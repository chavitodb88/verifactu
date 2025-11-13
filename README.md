# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con VERI\*FACTU (AEAT).\
Arquitectura avanzada con **tipado estricto**, **idempotencia**, **cola de procesamiento**, **firma WSSE** y **trazabilidad completa**.

Compatible con PHP **7.4 → 8.3**.

---

1. # Objetivos del proyecto

- Exponer una **API REST multiempresa** para recibir datos de facturación desde sistemas externos.

- Generar automáticamente todos los artefactos exigidos por VERI\*FACTU:

  - **Cadena canónica**

  - **Huella (SHA-256)**

  - **Encadenamiento**

  - **CSV (Código Seguro de Verificación)**

  - **QR**

  - **XML de previsualización**

  - **XML oficial (RegFactuSistemaFacturacion)**

- Enviar facturas a la AEAT mediante **SOAP firmado WSSE**, usando un **único certificado** (colaborador social).

- Gestionar múltiples empresas y múltiples emisores (NIF) en el mismo sistema.

- Mantener **idempotencia por petición**.

- Ofrecer **trazabilidad total**, guardando todos los XML, hashes y respuestas de AEAT.

---

2. # Requisitos técnicos

- PHP **7.4+** (compatible hasta **8.3**)

- CodeIgniter **4.3.x**

- MySQL **5.7+ / 8.x**

- Extensiones:

  - `ext-soap` (SOAP AEAT)

  - `ext-openssl` (firma WSSE)

  - `ext-json`

- Librerías recomendadas:

  - `zircote/swagger-php` (OpenAPI)

  - `endroid/qr-code` (QR real)

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

VERIFACTU_SEND_REAL=0

# Test AEAT (1) o producción (0)

verifactu.isTest=true`

---

4. # Migraciones y Seeders

Tablas:

| Tabla                | Finalidad                        |
| -------------------- | -------------------------------- |
| `companies`          | Multiempresa + flags VERI\*FACTU |
| `authorized_issuers` | Emisores autorizados             |
| `api_keys`           | Autenticación                    |
| `billing_hashes`     | Estado local de cada factura     |
| `submissions`        | Historial de intentos de envío   |

Seeders:

`php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder`

---

5. # Autenticación

Filtro:

- `X-API-Key`

- o `Authorization: Bearer <token>`

El filtro:

- valida la API key,

- recupera la empresa,

- inyecta `company_id` y contexto en la request mediante `RequestContextService`.

---

6. # OpenAPI

Generación:

`composer openapi:build`

Rutas:

- `public/openapi.json`

- `public/swagger/`

Controladores y DTOs documentados con atributos `#[OA\Get]`, `#[OA\Post]`, etc.

---

7. # Estructura del proyecto

`app/
  Controllers/Api/V1/InvoicesController.php
  DTO/InvoiceDTO.php
  Services/
    VerifactuCanonicalService.php
    VerifactuXmlBuilder.php
    VerifactuAeatPayloadBuilder.php
    VerifactuService.php
  Filters/ApiKeyAuthFilter.php
  Libraries/MySoap.php
  Libraries/VerifactuSoapClient.php
  Models/...
  Database/Migrations/
  Database/Seeds/`

---

8. # Cadena canónica y huella

Se usa:

`IDEmisorFactura=
NumSerieFactura=
FechaExpedicionFactura=dd-mm-YYYY
TipoFactura=
CuotaTotal=
ImporteTotal=
Huella=(prev_hash || vacío)
FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS+01:00`

Se genera:

- cadena completa (almacenada en `csv_text`)

- huella SHA-256 (`hash`)

- fecha/hora huso (`fecha_huso`)

---

9. # Campos importantes en `billing_hashes`

Esta tabla representa **el estado actual** de la factura.

Contiene:

- `issuer_nif`, `series`, `number`

- `lines_json`

- `detalle_json`

- `cuota_total`, `importe_total`

- `hash`, `prev_hash`, `chain_index`

- `qr_url`, `xml_path`

- `fecha_huso`

- **`aeat_csv`**

- **`aeat_estado_envio`**

- **`aeat_estado_registro`**

- **`aeat_codigo_error`**

- **`aeat_descripcion_error`**

- timestamps, idempotency, cola

---

10. # Estados de procesamiento

- `draft` → generado localmente, sin enviar

- `ready` → listo para worker

- `sent` → enviado (respuesta AEAT almacenada)

- `accepted`

- `accepted_with_errors`

- `rejected`

- `error` → reintento programado

---

11. # Worker

Procesa en cola:

`php spark verifactu:process`

Recomendado en cron:

`* * * * * php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1`

El worker:

- Obtiene facturas `ready` o `error`

- Llama a `VerifactuService::sendToAeat()`

- Procesa la respuesta

- Guarda XML request/response

- Actualiza estado AEAT

- Inserta registro en `submissions`

- Reintenta si es necesario (backoff)

---

12. # Respuesta AEAT soportada

El sistema parsea:

- `CSV`

- `EstadoEnvio` (Correcto, ParcialmenteCorrecto, Incorrecto)

- `EstadoRegistro`

- `CodigoErrorRegistro`

- `DescripcionErrorRegistro`

Los valores se guardan **tanto en `billing_hashes` como en `submissions`**.

---

13. # Endpoint nuevo `/invoices/{id}/verifactu`

Devuelve:

- Datos de factura

- Cadena y huella

- Encadenamiento

- Estado AEAT

- CSV

- QR

- XML de previsualización

- XML de envío oficial (último attempt)

- Attempts históricos

---

1.  # Pendiente / Próximos pasos

- Implementar PDF oficial con QR y CSV.

- Endpoint `/invoices/{id}/pdf`.

- Mejorar QR (endroid/qr-code).

- Validación AEAT (esquemas XSD).

- Script para reintentar solo facturas `retryable`.

- Gestión de clientes internacionales (NIF, IDOtro).

- Panel de administración opcional.

---

**Autor**: Javier Delgado (PTG) --- 2025
