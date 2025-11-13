# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con **VERI\*FACTU (AEAT)**.\
Incluye **tipado estricto**, **idempotencia**, **cadena-huella**, **firma WSSE**,\
**cola de envío**, **trazabilidad integral**, **XML oficial** y **respuesta AEAT completa**.

Compatible con PHP **7.4 → 8.3**.

---

1. # Objetivos del proyecto

- Recibir datos de facturación desde sistemas externos mediante **API REST multiempresa**.

- Generar TODOS los artefactos exigidos por VERI\*FACTU:

  - Cadena canónica

  - Huella (SHA-256)

  - Encadenamiento

  - CSV técnico (cadena canónica)

  - CSV AEAT (Código Seguro de Verificación)

  - XML de previsualización

  - XML oficial `RegFactuSistemaFacturacion`

  - QR oficial AEAT

- Enviar facturas a la AEAT mediante **SOAP WSSE**, usando un **único certificado**\
  como **colaborador social**, permitiendo múltiples emisores NIF.

- Garantizar:

  - Idempotencia por petición

  - Cadena inalterable y trazable

  - Copia exacta de todos los XML request/response

  - Backoff, reintentos, cola y trazabilidad histórica

---

2. # Requisitos técnicos

Mínimos:

- PHP **7.4+**

- CodeIgniter **4.3.x**

- MySQL **5.7+ / 8.x**

Extensiones necesarias:

- `ext-soap` --- envío AEAT

- `ext-openssl` --- firma WSSE

- `ext-json`

Dependencias recomendadas:

- `zircote/swagger-php` --- OpenAPI

- `endroid/qr-code` --- QR oficial AEAT

---

3. # Instalación

`composer install`

Crear `.env`:

`CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = 127.0.0.1
database.default.database = verifactu
database.default.username = root
database.default.password = secret
database.default.DBDriver = MySQLi
database.default.charset = utf8mb4

# Envío real (1) o simulado (0)

VERIFACTU_SEND_REAL = 0

# Conexión a entorno de PRE-AEAT

verifactu.isTest = true`

---

4. # Migraciones y Seeders

Tablas principales:

| Tabla                | Finalidad                                      |
| -------------------- | ---------------------------------------------- |
| `companies`          | Multiempresa + flags VERI\*FACTU               |
| `authorized_issuers` | Emisores NIF autorizados para esa empresa      |
| `api_keys`           | Autenticación                                  |
| `billing_hashes`     | Estado local, cadena, huella, QR, XML, AEAT... |
| `submissions`        | Historial de envíos, reintentos y errores AEAT |

Instalación:

`php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder`

---

5. # Autenticación

El middleware soporta:

- `X-API-Key: {key}`

- `Authorization: Bearer {token}`

El filtro:

- valida la API key,

- carga la empresa (`company_id`),

- inyecta el contexto vía `RequestContextService`.

Todas las rutas bajo `/api/v1` están protegidas.

---

6. # Documentación OpenAPI

Generar:

`composer openapi:build`

Ubicación:

- `/public/openapi.json`

- `/public/swagger/`

Controladores y DTOs documentados con `#[OA\Get]`, `#[OA\Post]`, esquemas en `App\Swagger\Root`.

---

7. # Estructura del proyecto

`app/
  Controllers/
 Api/V1/InvoicesController.php
  DTO/
    InvoiceDTO.php
  Services/
    VerifactuCanonicalService.php
    VerifactuXmlBuilder.php
    VerifactuAeatPayloadBuilder.php
    VerifactuService.php
  Libraries/
    MySoap.php
    VerifactuSoapClient.php
  Filters/
    ApiKeyAuthFilter.php
  Models/
    BillingHashModel.php
    SubmissionsModel.php
    CompaniesModel.php
  Database/
    Migrations/
    Seeds/
  Swagger/
    Root.php `

---

8. # Cadena canónica, huella y encadenamiento

La cadena canónica usa exactamente estos campos:

`IDEmisorFactura=
NumSerieFactura=
FechaExpedicionFactura=dd-mm-YYYY
TipoFactura=
CuotaTotal=
ImporteTotal=
Huella=(prev_hash o vacío)
FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS+01:00`

Se generan:

- **csv_text** = cadena concatenada

- **hash** = SHA-256 (mayúsculas)

- **prev_hash** = hash anterior de ese emisor/serie

- **chain_index** = nº en la cadena

- **fecha_huso** = timestamp exacto usado en la cadena

Estos campos deben coincidir literalmente para que AEAT valide la huella.

---

9. # Estructura de `billing_hashes`

Representa **el estado actual y definitivo** del registro técnico de la factura.

Campos principales:

- Datos originales:

  - `issuer_nif`, `series`, `number`, `issue_date`

  - `lines_json`

  - `detalle_json`

  - `cuota_total`, `importe_total`

- Cadena y huella:

  - `csv_text`

  - `hash`

  - `prev_hash`

  - `chain_index`

  - `fecha_huso`

- Artefactos:

  - `qr_path`, `qr_url`

  - `xml_path`

  - `raw_payload_json`

- Estado AEAT:

  - `aeat_csv`

  - `aeat_estado_envio`

  - `aeat_estado_registro`

  - `aeat_codigo_error`

  - `aeat_descripcion_error`

- Cola:

  - `status`

  - `next_attempt_at`

  - `processing_at`

  - `idempotency_key`

---

10. # Estados de procesamiento

| Estado                 | Significado                                     |
| ---------------------- | ----------------------------------------------- |
| `draft`                | Creado por `/preview`, sin enviar               |
| `ready`                | Listo para la cola                              |
| `sent`                 | Enviado a AEAT (XML request/response guardados) |
| `accepted`             | AEAT ha aceptado                                |
| `accepted_with_errors` | AEAT aceptó parcialmente                        |
| `rejected`             | Rechazo definitivo AEAT                         |
| `error`                | Fallo temporal, pendiente de reintento          |

---

11. # Worker / cola

Ejecuta los envíos pendientes:

`php spark verifactu:process`

Cron recomendado:

`* * * * * php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1`

El worker:

- Obtiene facturas con `ready` o `error`

- Construye el XML oficial

- Firma WSSE

- Envia a AEAT (`RegFactuSistemaFacturacion`)

- Guarda request y response

- Inserta registro en `submissions`

- Actualiza estado en `billing_hashes`

- Reintenta con backoff si es necesario

---

12. # Respuesta AEAT interpretada

El sistema analiza:

- `CSV`

- `EstadoEnvio` (Correcto / ParcialmenteCorrecto / Incorrecto)

- `EstadoRegistro` (Correcto / AceptadoConErrores / Incorrecto)

- `CodigoErrorRegistro`

- `DescripcionErrorRegistro`

Todos estos datos se guardan en:

- `billing_hashes` (estado actual)

- `submissions` (histórico de attempts)

---

13. # Endpoint `/invoices/{id}/verifactu`

Devuelve:

- Datos originales de la factura

- Cadena + huella + encadenamiento

- Artefactos generados (QR, XML)

- Estado AEAT actual

- CSV AEAT

- Histórico de envíos (`submissions`)

- Request y response oficiales (paths)

Este endpoint es ideal para:

- UI interna

- depuración

- comparativa conceputal con AEAT

---

14. # Pendiente / roadmap

- **PDF oficial** con:

  - QR AEAT

  - CSV AEAT

  - Datos de factura

- Endpoint `/invoices/{id}/pdf`

- Validación XSD completa con AEAT

- Script de retry inteligente (`retryable only`)

- Soporte para destinatarios internacionales (IDOtro)

- Panel web (opcional)

- Revisar el tema de Terceros Colaboradores Sociales (Como se hace? Revisar doc AEAT)

---

**Autor:** Javier Delgado Berzal --- PTG (2025)
