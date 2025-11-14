# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con **VERI\*FACTU (AEAT)**.  
Incluye **tipado estricto**, **idempotencia**, **cadena-huella**, **firma WSSE**,  
**cola de envío**, **trazabilidad integral**, **XML oficial**, **QR AEAT** y **PDF oficial**.

Compatible con PHP **7.4 → 8.3**.

---

## 1. Objetivos del proyecto

- Recibir datos de facturación desde sistemas externos mediante **API REST multiempresa**.

- Generar TODOS los artefactos técnicos exigidos por VERI\*FACTU:

  - Cadena canónica
  - Huella (SHA-256)
  - Encadenamiento
  - CSV técnico (cadena canónica)
  - CSV AEAT (Código Seguro de Verificación)
  - XML de previsualización
  - XML oficial `RegFactuSistemaFacturacion`
  - QR oficial AEAT
  - PDF oficial con QR + datos de factura

- Enviar facturas a la AEAT mediante **SOAP WSSE**, usando un **único certificado**  
  como **colaborador social**, permitiendo múltiples emisores NIF.

- Garantizar:

  - Idempotencia por petición
  - Cadena inalterable y trazable
  - Copia exacta de todos los XML request/response
  - Backoff, reintentos, cola y trazabilidad histórica

---

## 2. Requisitos técnicos

Mínimos:

- PHP **7.4+**
- CodeIgniter **4.3.x**
- MySQL **5.7+ / 8.x**

Extensiones necesarias:

- `ext-soap` — envío AEAT
- `ext-openssl` — firma WSSE
- `ext-json`

Dependencias recomendadas:

- `zircote/swagger-php` — OpenAPI
- `endroid/qr-code` — QR oficial AEAT
- `dompdf/dompdf` — generación de PDF oficial

---

## 3. Instalación

```bash
composer install
```

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

## 4\. Migraciones y Seeders

Tablas principales:

| Tabla                | Finalidad                                      |
| -------------------- | ---------------------------------------------- |
| `companies`          | Multiempresa + flags VERI\*FACTU               |
| `authorized_issuers` | Emisores NIF autorizados para esa empresa      |
| `api_keys`           | Autenticación                                  |
| `billing_hashes`     | Estado local, cadena, huella, QR, XML, PDF...  |
| `submissions`        | Historial de envíos, reintentos y errores AEAT |

Instalación:

`php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder`

---

## 5\. Autenticación

El middleware soporta:

- `X-API-Key: {key}`

- `Authorization: Bearer {token}`

El filtro:

- Valida la API key

- Carga la empresa (`company_id`)

- Inyecta el contexto vía `RequestContextService`

Todas las rutas bajo `/api/v1` están protegidas.

### 5.1. Validación de NIF/NIE/CIF

En el endpoint de entrada (`/invoices/preview`), el DTO `InvoiceDTO` aplica una validación estricta sobre:

- `issuerNif` (obligado a emitir / emisor)

- `recipient.nif` (si se informa en el payload)

Se utiliza un validador interno `SpanishIdValidator` que comprueba:

- **DNI** (8 dígitos + letra con control)

- **NIE** (X/Y/Z + 7 dígitos + letra, convertido internamente a DNI)

- **CIF** (letra inicial, 7 dígitos, dígito o letra de control calculados)

Si el NIF/NIE/CIF no es válido (por ejemplo, `B12345678`), el `preview` devuelve:

- `422 Unprocessable Entity` con mensaje tipo\
  `issuerNif is not a valid Spanish NIF/NIE/CIF`\
  o `recipient.nif is not a valid Spanish NIF/NIE/CIF`.

Estas facturas **no entran en la cola** y por tanto **nunca se envían a AEAT**.

---

## 6\. Documentación OpenAPI

Generar:

`composer openapi:build`

Ubicación:

- `/public/openapi.json`

- `/public/swagger/`

Controladores y DTOs documentados con `#[OA\Get]`, `#[OA\Post]`, etc.\
Esquemas centralizados en `App\Swagger\Root`.

---

## 7\. Estructura del proyecto

`app/
  Controllers/
    Api/V1/InvoicesController.php
    Api/V1/HealthController.php
  DTO/
    InvoiceDTO.php
  Services/
    VerifactuCanonicalService.php
    VerifactuXmlBuilder.php
    VerifactuAeatPayloadBuilder.php
    VerifactuService.php
    VerifactuPdfService.php
    VerifactuQrService.php
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
    Root.php
  Views/
    pdfs/verifactu_invoice.php`

---

## 8\. Cadena canónica, huella y encadenamiento

La cadena canónica sigue este formato:

`IDEmisorFactura=
NumSerieFactura=
FechaExpedicionFactura=dd-mm-YYYY
TipoFactura=
CuotaTotal=
ImporteTotal=
Huella=(prev_hash o vacío)
FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS+01:00`

Se generan y almacenan:

- `csv_text` → cadena completa concatenada

- `hash` → SHA-256 (hex, mayúsculas)

- `prev_hash` → hash anterior de ese emisor/serie

- `chain_index` → posición en la cadena para ese emisor/serie

- `fecha_huso` → timestamp exacto usado en la cadena

Estos campos deben coincidir **exactamente** con lo que AEAT recalcula.

> ⚠ **Nota importante sobre `FechaHoraHusoGenRegistro` y la ventana de 240 s**
>
> La AEAT exige que la fecha, hora y huso horario reflejen el momento en que el\
> sistema informático **genera el registro de facturación**, y existe una\
> tolerancia temporal limitada (≈ 240 segundos).
>
> Actualmente, la API:
>
> - Genera `fecha_huso` y la cadena canónica en el `preview`.
>
> - Guarda ambos valores en `billing_hashes`.
>
> - Reutiliza esa información al enviar por la cola.
>
> Esto funciona correctamente si el envío a AEAT es relativamente inmediato.\
> Para escenarios en los que el envío pueda producirse bastante más tarde, está\
> previsto introducir una mejora (roadmap) para:
>
> - Regenerar `FechaHoraHusoGenRegistro` en el momento de envío, y
>
> - Recalcular la cadena canónica y la huella asociada,\
>   manteniendo la consistencia con los requisitos de AEAT y su ventana temporal.

---

## 9\. Estructura de `billing_hashes`

Representa **el estado actual y definitivo** del registro técnico de la factura.

Campos principales:

- Datos originales:

  - `issuer_nif`, `series`, `number`, `issue_date`

  - `lines_json` (líneas de factura `{desc, qty, price, vat, discount?}`)

  - `detalle_json` (agrupación por IVA usada en `DetalleDesglose`)

  - `vat_total`, `gross_total`

- Cadena y huella:

  - `csv_text`

  - `hash`

  - `prev_hash`

  - `chain_index`

  - `fecha_huso`

- Artefactos:

  - `qr_path`, `qr_url`

  - `xml_path` (XML de previsualización / último XML oficial)

  - `pdf_path` (PDF oficial generado)

  - `raw_payload_json` (payload original recibido en `/preview`)

- Estado AEAT:

  - `aeat_csv` --- CSV devuelto por AEAT

  - `aeat_estado_envio` --- Correcto / ParcialmenteCorrecto / Incorrecto

  - `aeat_estado_registro` --- Correcto / AceptadoConErrores / Incorrecto

  - `aeat_codigo_error` --- código numérico AEAT

  - `aeat_descripcion_error` --- descripción textual

- Cola:

  - `status` --- estado interno (`draft`, `ready`, `sent`, `accepted`, ...)

  - `next_attempt_at` --- cuándo reintentar

  - `processing_at` --- lock temporal

  - `idempotency_key` --- para repetir peticiones sin duplicar

---

## 10\. Estados de procesamiento

| Estado                 | Significado                            |
| ---------------------- | -------------------------------------- |
| `draft`                | Creado por `/preview`, sin enviar      |
| `ready`                | Listo para entrar en la cola           |
| `sent`                 | XML enviado, petición registrada       |
| `accepted`             | AEAT ha aceptado                       |
| `accepted_with_errors` | AEAT aceptó con errores                |
| `rejected`             | Rechazo definitivo AEAT                |
| `error`                | Fallo temporal, pendiente de reintento |

---

## 11\. Worker / cola

Ejecuta los envíos pendientes:

`php spark verifactu:process`

Cron recomendado:

`* * * * * php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1`

El worker:

1.  Obtiene facturas con `status IN ('ready','error')` y `next_attempt_at <= NOW()`.

2.  Construye el XML oficial (`VerifactuAeatPayloadBuilder`).

3.  Firma WSSE y envía a AEAT (`VerifactuSoapClient` → `RegFactuSistemaFacturacion`).

4.  Guarda request y response en `WRITEPATH/verifactu/requests|responses`.

5.  Inserta registro en `submissions`.

6.  Actualiza `billing_hashes` con:

    - CSV, estado de envío/registro

    - códigos de error si los hay

    - nuevo `status` (`accepted`, `rejected`, `error`, etc.)

7.  Programa reintentos (`next_attempt_at`) en caso de fallo temporal.

---

## 12\. Respuesta AEAT interpretada

A partir del XML de respuesta se extrae:

- `CSV`

- `EstadoEnvio` → `aeat_estado_envio`

- `EstadoRegistro` → `aeat_estado_registro`

- `CodigoErrorRegistro` → `aeat_codigo_error`

- `DescripcionErrorRegistro` → `aeat_descripcion_error`

Estos datos se guardan en:

- `billing_hashes` → estado actual de la factura

- `submissions` → histórico de attempts y reintentos

---

## 13\. Endpoint `/invoices/{id}/verifactu`

**GET** `/api/v1/invoices/{id}/verifactu`

Devuelve un JSON con:

- Datos base de la factura (`issuer_nif`, serie/número, fechas, totales)

- Cadena canónica (`csv_text`), hash y encadenamiento

- Artefactos:

  - QR (`qr_url`)

  - XML asociado (`xml_path`)

  - PDF (`pdf_path`, si existe)

- Estado AEAT actual:

  - `aeat_csv`, `aeat_estado_envio`, `aeat_estado_registro`, errores...

- Histórico de envíos (`submissions`), incluyendo paths de request/response.

Uso típico:

- UI interna de auditoría

- Depuración de integraciones

- Ver "qué le hemos mandado a AEAT" y "qué nos ha respondido"

---

## 14\. Endpoint `/invoices/{id}/pdf`

**GET** `/api/v1/invoices/{id}/pdf`

Genera (o regenera) el **PDF oficial** de la factura y lo devuelve como descarga.

Características:

- Implementado vía `VerifactuPdfService` + `Dompdf`.

- Usa como fuente:

  - `billing_hashes` (serie, número, fecha, totales, líneas)

  - `lines_json` (líneas `{desc, qty, price, vat, ...}`)

  - `detalle_json` (para desglose por IVA si se necesita)

  - `qr_path` / `qr_url` (QR tributario)

- Renderiza la vista `app/Views/pdfs/verifactu_invoice.php`.

- Guarda el fichero en: `WRITEPATH/verifactu/pdfs/{id}.pdf`.

- Persiste la ruta en `billing_hashes.pdf_path`.

- El controlador responde con:

  - `Content-Type: application/pdf`

  - `Content-Disposition: attachment; filename="Factura-{series}{number}.pdf"`

> **Nota:** el layout actual es genérico. El branding y el diseño definitivo\
> se pueden adaptar por empresa en una fase posterior.

---

## 15\. Endpoint `/invoices/{id}/qr`

**GET** `/api/v1/invoices/{id}/qr`

- Genera un QR AEAT a partir de `issuer_nif`, serie/número, fecha e importe total.

- Usa `endroid/qr-code` para generar imagen PNG.

- Guarda el archivo en `WRITEPATH/verifactu/qrs/{id}.png`.

- Actualiza `billing_hashes.qr_path` y `billing_hashes.qr_url`.

- Responde con la imagen como `image/png`.

Este QR se reutiliza luego tanto en el PDF como en cualquier UI externa.

---

## 16\. Pendiente / roadmap

- Mejorar el **diseño del PDF oficial**:

  - Branding por empresa

  - Soporte multi-idioma

  - Textos legales configurables (LOPD, RGPD, etc.)

- Añadir validación XSD completa contra esquemas AEAT.

- Script de retry inteligente: reintentar solo facturas "retryable".

- Soporte completo para destinatarios internacionales (bloque `IDOtro`).

- Panel web opcional para:

  - Exploración de facturas

  - Reintentos manuales

  - Descarga masiva de XML/PDF.

- Ajustar la generación de `FechaHoraHusoGenRegistro` para:

  - reflejar siempre el momento real de envío del registro, y

  - cumplir estrictamente la ventana temporal exigida por AEAT.

---

## 17\. Tipos de facturas VERI\*FACTU: completas, rectificativas y anulaciones (pendiente de implementación)

AEAT exige soportar **todos** los tipos de operación y **todas** las clases de factura permitidas en VERI\*FACTU.

Aquí se describe lo que quedará implementado en esta API (pendiente de desarrollo técnico).

### 17.1. Facturas normales (TipoFactura = F1)

Estado actual: **YA IMPLEMENTADO**

Incluye:

- Emisor, destinatario, líneas, desglose por IVA, totales

- Cadena canónica, encadenamiento, huella

- XML oficial, envío SOAP, respuesta AEAT

- PDF con QR

### 17.2. Facturas rectificativas (TipoFactura = R1, R2, R3, R4)

**Pendiente de implementar**

Se soportarán:

- Referencias a factura original (`IDFacturaRectificada`)

- Totales rectificados / diferencias

- Encadenamiento independiente

- Endpoint específico para registrar rectificaciones

### 17.3. Anulaciones (TipoOperacion = "Anulación")

**Pendiente de implementar**

- TipoOperacion = `Anulacion`

- Totales a 0

- Referencia a factura original

- Nuevo registro en cadena (no se borra nada)

### 17.4. Facturas sin destinatario (TipoFactura = F3)

**Pendiente**

- Sin bloque `<Destinatarios>`

- Uso típico: tickets, ventas anónimas

### 17.5. Facturas simplificadas (TipoFactura = F2)

**Pendiente**

- Totales con IVA incluido en línea

- Desglose automático por tipo impositivo

### 17.6. IDOtro (identificadores internacionales)

**Pendiente**

- Soporte para:

  - `CodigoPais`

  - `IDType`

  - `IDNumero`

### 17.7. Trazabilidad en `billing_hashes` y `submissions` para todas las operaciones

Se añadirá:

- `kind` → normal / rectify / cancel / ...

- `type` → F1/F2/F3/R1/R2/R3/R4

- `rectified_json` → referencia/estructura de la factura original

### 17.8. Estados especiales AEAT a documentar

| EstadoEnvio          | EstadoRegistro     | Significado                          |
| -------------------- | ------------------ | ------------------------------------ |
| Correcto             | Correcto           | OK                                   |
| Correcto             | AceptadoConErrores | Se ha procesado pero con incidencias |
| ParcialmenteCorrecto | AceptadoConErrores | Alguna parte está mal                |
| Incorrecto           | Incorrecto         | Rechazo total                        |
| Incorrecto           | _(vacío)_          | Error grave / estructura inválida    |

---

## 18\. Tests automatizados

El proyecto incluye tests unitarios para asegurar la estabilidad de la lógica crítica de VERI\*FACTU.

### 18.1. Ejecutar todos los tests

`php vendor/bin/phpunit`

### 18.2. Ejecutar un test concreto (builder AEAT)

`php vendor/bin/phpunit --filter VerifactuAeatPayloadBuilderTest`

Este test valida, entre otras cosas:

- Construcción de `RegistroAlta`

- Formato de fechas (`dd-mm-YYYY`)

- Cálculo de desglose (`DetalleDesglose`)

- Totales (`CuotaTotal`, `ImporteTotal`) consistentes con las líneas

### 18.3. Ejecutar tests de la cadena canónica

`php vendor/bin/phpunit --filter VerifactuCanonicalServiceTest`

Este test comprueba:

- Formato exacto de la cadena canónica (`csv_text`)

- Inclusión correcta de `FechaHoraHusoGenRegistro`

- Generación de la huella SHA-256 en mayúsculas

- Coherencia entre la cadena y los campos almacenados en `billing_hashes`

### 18.4. Caminos críticos cubiertos por tests

| Camino crítico                                      | Servicio / Componente                | Cobertura actual                                           | Pendiente / Futuro                                                                  |
| --------------------------------------------------- | ------------------------------------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Construcción de la **cadena canónica** + huella     | `VerifactuCanonicalService`          | ✅ `VerifactuCanonicalServiceTest`                         | Añadir casos límite (importes con muchos decimales, prev_hash nulo/no nulo, etc.)   |
| Cálculo de **desglose y totales** desde `lines`     | `VerifactuAeatPayloadBuilder`        | ✅ `VerifactuAeatPayloadBuilderTest`                       | Casos con varios tipos de IVA, descuentos, líneas a 0, etc.                         |
| Construcción de `RegistroAlta` (payload ALTA AEAT)  | `VerifactuAeatPayloadBuilder`        | ✅ Validación de campos básicos (fechas, totales, detalle) | Añadir soportes para tipos F2/F3/R1-R4, anulaciones y destinatarios internacionales |
| Generación de **QR AEAT**                           | `VerifactuQrService`                 | ⏳ Pendiente de test unitario específico                   | Testear generación determinista de URL QR y ruta de fichero en disco                |
| Generación de **PDF oficial**                       | `VerifactuPdfService` + vista `pdfs` | ⏳ Pendiente (actualmente validado manualmente)            | Testear que el HTML base se renderiza y el fichero PDF se genera sin errores        |
| Flujo de **worker / cola** (`ready` → envío → AEAT) | `VerifactuService` + comando spark   | ⏳ Pendiente de tests de integración                       | Tests funcionales con respuestas SOAP simuladas (Correcto / Incorrecto / errores)   |
| Actualización de **estados AEAT** en BD             | `VerifactuService` + `Submissions`   | ⏳ Pendiente de test unitario / integración                | Verificación de mapping correcto a `aeat_*` y `status` internos                     |
| Endpoints REST (`preview`, `verifactu`, `pdf`, ...) | `InvoicesController`                 | ⏳ Pendiente de tests tipo HTTP/feature                    | Tests de contrato (status codes, esquemas JSON, headers, etc.)                      |

---

# DIAGRAMA COMPLETO TPU (Trazabilidad)

┌──────────────────────┐
│ EMPRESA C │
│ (Cliente final) │
└───────────▲──────────┘
│
│ Factura
│
┌───────────┴──────────┐
│ EMPRESA B │
│ (Obligado a emitir) │
└───────────▲──────────┘
│ Registro de facturación
│
┌───────────┴──────────┐
│ EMPRESA A │
│ (Tu SaaS + SIF + │
│ Colaborador Social)│
└───────────▲──────────┘
│ XML firmado + Hash + Encadenamiento
│
┌───────────┴──────────┐
│ AEAT │
│ (VERI\*FACTU) │
└──────────────────────┘

**Autor:** Javier Delgado Berzal --- PTG (2025)
