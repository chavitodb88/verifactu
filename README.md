# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con **VERI\*FACTU (AEAT)**.\
Incluye **tipado estricto**, **idempotencia**, **hash**, **firma WSSE**,\
**cola de envío**, **trazabilidad integral**, **XML oficial**, **QR AEAT** y **PDF oficial**.

Compatible con PHP **7.4 → 8.3**.

Actualmente soporta:

- **Altas de registros de facturación** (RegistroAlta, TipoFactura F1).

- **Anulaciones técnicas de registros de facturación** (RegistroAnulacion), encadenadas sobre la misma serie/número.

---

## 1\. Objetivos del proyecto

- Recibir datos de facturación desde sistemas externos mediante **API REST multiempresa**.

- Generar TODOS los artefactos técnicos exigidos por VERI\*FACTU:

  - Cadena canónica (alta y anulación)

  - Hash (SHA-256)

  - Encadenamiento

  - CSV técnico (cadena canónica)

  - CSV AEAT (Código Seguro de Verificación)

  - XML de previsualización

  - XML oficial `RegFactuSistemaFacturacion` (alta y anulación)

  - QR oficial AEAT

  - PDF oficial con QR + datos de factura

- Enviar facturas a la AEAT mediante **SOAP WSSE**, usando un **único certificado**\
  como **colaborador social**, permitiendo múltiples emisores NIF.

- Garantizar:

  - Idempotencia por petición

  - Cadena inalterable y trazable

  - Copia exacta de todos los XML request/response

  - Backoff, reintentos, cola y trazabilidad histórica

  - Diferenciación clara entre **altas** y **anulaciones** de registros de facturación

---

## 2\. Requisitos técnicos

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

- `dompdf/dompdf` --- generación de PDF oficial

---

## 3\. Instalación

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

## 4\. Migraciones y Seeders

Tablas principales:

| Tabla                | Finalidad                                      |
| -------------------- | ---------------------------------------------- |
| `companies`          | Multiempresa + flags VERI\*FACTU               |
| `authorized_issuers` | Emisores NIF autorizados para esa empresa      |
| `api_keys`           | Autenticación                                  |
| `billing_hashes`     | Estado local, cadena, hash, QR, XML, PDF...    |
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
    VerifactuPayload.php
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

## 8\. Cadena canónica, hash y encadenamiento

### 8.1. Altas (RegistroAlta)

La cadena canónica de **alta** sigue este formato:

`IDEmisorFactura={NIF}
NumSerieFactura={SERIE+NUMERO}
FechaExpedicionFactura=dd-mm-YYYY
TipoFactura={F1/F2/F3/R1/...}
CuotaTotal={cuota_iva}
ImporteTotal={importe_total}
Huella={prev_hash o vacío}
FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS+01:00`

### 8.2. Anulaciones (RegistroAnulacion)

La cadena canónica de **anulación** sigue el formato AEAT:

`IDEmisorFacturaAnulada={NIF}
NumSerieFacturaAnulada={SERIE+NUMERO_ORIGINAL}
FechaExpedicionFacturaAnulada=dd-mm-YYYY
Huella={prev_hash o vacío}
FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS+01:00`

En ambos casos se generan y almacenan:

- `csv_text` → cadena completa concatenada

- `hash` → SHA-256 (hex, mayúsculas)

- `prev_hash` → hash anterior de ese emisor/serie

- `chain_index` → posición en la cadena para ese emisor/serie

- `datetime_offset` → timestamp exacto usado en la cadena (`FechaHoraHusoGenRegistro`)

Estos campos deben coincidir **exactamente** con lo que AEAT recalcula.

> ⚠ **Nota importante sobre `FechaHoraHusoGenRegistro` y la ventana de 240 s**
>
> La AEAT exige que la fecha, hora y huso horario reflejen el momento en que el\
> sistema informático **genera el registro de facturación**, y existe una\
> tolerancia temporal limitada (≈ 240 segundos).
>
> Actualmente, la API:
>
> - Genera `datetime_offset` y la cadena canónica en el `preview` (altas) o en la creación de anulación.
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

Representa **el estado actual y definitivo** del registro técnico de la factura\
(tanto de **altas** como de **anulaciones**).

Campos principales:

- Datos originales:

  - `issuer_nif`, `series`, `number`, `issue_date`

  - `lines_json` (líneas de factura `{desc, qty, price, vat, discount?}`)

  - `details_json` (agrupación por IVA usada en `DetalleDesglose`)

  - `vat_total`, `gross_total`

- Tipo de registro:

  - `kind` --- tipo de registro VERI\*FACTU:

    - `alta` → RegistroAlta (factura original)

    - `anulacion` → RegistroAnulacion (anula un registro de alta previo)

  - `original_billing_hash_id` --- referencia (FK lógica) al `billing_hash` de alta que se anula (solo para `kind = 'anulacion'`).

  - `cancel_reason` --- texto opcional con el motivo de la anulación (informativo, no se envía a AEAT).

- Cadena y huella:

  - `csv_text`

  - `hash`

  - `prev_hash`

  - `chain_index`

  - `datetime_offset`

- Artefactos:

  - `qr_path`, `qr_url`

  - `xml_path` (XML de previsualización / último XML oficial)

  - `pdf_path` (PDF oficial generado)

  - `raw_payload_json` (payload original recibido en `/preview`, solo para `alta`)

- Estado AEAT:

  - `aeat_csv` --- CSV devuelto por AEAT

  - `aeat_send_status` --- Correcto / ParcialmenteCorrecto / Incorrecto

  - `aeat_register_status` --- Correcto / AceptadoConErrores / Incorrecto

  - `aeat_error_code` --- código numérico AEAT

  - `aeat_error_message` --- descripción textual

- Cola:

  - `status` --- estado interno (`draft`, `ready`, `sent`, `accepted`, ...)

  - `next_attempt_at` --- cuándo reintentar

  - `processing_at` --- lock temporal

  - `idempotency_key` --- para repetir peticiones sin duplicar

---

## 10\. Estados de procesamiento

| Estado                 | Significado                                            |
| ---------------------- | ------------------------------------------------------ |
| `draft`                | Creado por `/preview` (alta) o por anulación, sin cola |
| `ready`                | Listo para entrar en la cola                           |
| `sent`                 | XML enviado, petición registrada                       |
| `accepted`             | AEAT ha aceptado                                       |
| `accepted_with_errors` | AEAT aceptó con errores                                |
| `rejected`             | Rechazo definitivo AEAT                                |
| `error`                | Fallo temporal, pendiente de reintento                 |

---

## 11\. Worker / cola

Ejecuta los envíos pendientes **tanto de altas como de anulaciones**:

`php spark verifactu:process`

Cron recomendado:

`* * * * * php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1`

El worker:

1.  Obtiene registros con `status IN ('ready','error')` y `next_attempt_at <= NOW()`.

2.  Carga la fila en `billing_hashes`:

    - Si `kind = 'alta'` → construye `RegistroAlta`.

    - Si `kind = 'anulacion'` → construye `RegistroAnulacion`.

3.  Construye el XML oficial (`VerifactuAeatPayloadBuilder` / `VerifactuPayload`).

4.  Firma WSSE y envía a AEAT (`VerifactuSoapClient` → `RegFactuSistemaFacturacion`).

5.  Guarda request y response en `WRITEPATH/verifactu/requests|responses`.

6.  Inserta registro en `submissions` con `type = 'register'` (alta) o `type = 'cancel'` (anulación).

7.  Actualiza `billing_hashes` con:

    - CSV, estado de envío/registro

    - códigos de error si los hay

    - nuevo `status` (`accepted`, `rejected`, `error`, etc.).

8.  Programa reintentos (`next_attempt_at`) en caso de fallo temporal.

---

## 12\. Respuesta AEAT interpretada

A partir del XML de respuesta se extrae:

- `CSV`

- `EstadoEnvio` → `aeat_send_status`

- `EstadoRegistro` → `aeat_register_status`

- `CodigoErrorRegistro` → `aeat_error_code`

- `DescripcionErrorRegistro` → `aeat_error_message`

Estos datos se guardan en:

- `billing_hashes` → estado actual del registro de facturación

- `submissions` → histórico de attempts y reintentos (incluyendo `type = register/cancel`)

---

## 13\. Endpoint `/invoices/{id}/verifactu`

**GET** `/api/v1/invoices/{id}/verifactu`

Devuelve un JSON con:

- Datos base del registro (`issuer_nif`, serie/número, fechas, totales)

- Tipo de registro (`kind = alta` / `anulacion`)

- Cadena canónica (`csv_text`), hash y encadenamiento

- Artefactos:

  - QR (`qr_url`)

  - XML asociado (`xml_path`)

  - PDF (`pdf_path`, si existe)

- Estado AEAT actual:

  - `aeat_csv`, `aeat_send_status`, `aeat_register_status`, errores...

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

  - `details_json` (para desglose por IVA si se necesita)

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

## 16\. Endpoint `/invoices/{id}/cancel`

**POST** `/api/v1/invoices/{id}/cancel`

Crea un **registro técnico de anulación** (VERI\*FACTU `RegistroAnulacion`) encadenado a la factura original.

### 16.1. Request

`POST /api/v1/invoices/123/cancel
X-API-Key: ...
Content-Type: application/json`

Body JSON:

`{
  "reason": "Factura emitida por error",
  "mode": "aeat_registered"
}`

- `reason` (opcional): motivo interno de anulación (guardado en `cancel_reason`).

- `mode` (opcional): modo de anulación (enum interna `CancellationMode`):

  - `aeat_registered` → caso normal (la factura tiene registro de alta en AEAT).

  - `no_aeat_record` → anulación de factura sin registro previo en AEAT (previsto para futuros flujos).

  - `previous_cancellation_rejected` → reintento tras anulación rechazada (previsto).

Si no se informa `mode`, se usa `aeat_registered`.

### 16.2. Comportamiento

- Busca el `billing_hash` original (`kind = 'alta'`) para ese `id` y `company_id`.

- Crea una nueva fila en `billing_hashes`:

  - `kind = 'anulacion'`

  - `original_billing_hash_id = id original`

  - `series` y `number` = **los mismos** que la factura original (la anulación referencia esa factura).

  - `vat_total = 0`, `gross_total = 0` (a efectos técnicos).

  - Nueva cadena canónica de anulación + `hash`, `prev_hash`, `chain_index`.

  - `status = 'ready'` y `next_attempt_at = NOW()` → entra en la cola automáticamente.

### 16.3. Response

`{
  "data": {
    "document_id": 456,
    "kind": "anulacion",
    "status": "ready",
    "hash": "ABCDEF1234...",
    "prev_hash": "XYZ987..."
  },
  "meta": {
    "request_id": "...",
    "ts": 1731840000
  }
}`

> **Nota:** La anulación es siempre un **nuevo registro VERI\*FACTU** encadenado,\
> nunca se borra ni se modifica el alta original. La lógica contable (asientos,\
> rectificativas, etc.) queda fuera de este middleware.

---

## 17\. Pendiente / roadmap

- Mejorar el **diseño del PDF oficial**:

  - Branding por empresa

  - Soporte multi-idioma

  - Textos legales configurables (LOPD, RGPD, etc.)

- Añadir validación XSD completa contra esquemas AEAT.

- Script de retry inteligente: reintentar solo facturas "retryable".

- Soporte completo para destinatarios internacionales (bloque `IDOtro`).

- Panel web opcional para:
- - ✅ Exploración básica de facturas (listado + filtros + detalle)
  - ✅ Visualización de artefactos (XML, PDF, QR) y `submissions`

  - Descarga masiva de XML/PDF.

- Ajustar la generación de `FechaHoraHusoGenRegistro` para:

  - reflejar siempre el momento real de envío del registro, y

  - cumplir estrictamente la ventana temporal exigida por AEAT.

---

## 18\. Tipos de facturas VERI\*FACTU: completas, rectificativas y anulaciones

AEAT exige soportar **todos** los tipos de operación y **todas** las clases de factura permitidas en VERI\*FACTU.

### 18.1. Facturas normales (TipoFactura = F1)

Estado actual: **YA IMPLEMENTADO**

Incluye:

- Emisor, destinatario, líneas, desglose por IVA, totales

- Cadena canónica, encadenamiento, huella

- XML oficial, envío SOAP, respuesta AEAT

- PDF con QR

### 18.2. Facturas rectificativas (TipoFactura = R1, R2, R3, R4)

Estado actual: **PENDIENTE DE IMPLEMENTAR**

Se soportarán:

- Referencias a factura original (`IDFacturaRectificada`)

- Totales rectificados / diferencias

- Encadenamiento independiente

- Endpoint específico para registrar rectificaciones

### 18.3. Anulaciones (RegistroAnulacion)

Estado actual: **PARCIALMENTE IMPLEMENTADO (núcleo técnico operativo)**

Ya implementado:

- Modelo de datos (`kind = 'anulacion'`, `original_billing_hash_id`, `cancel_reason`).

- Cadena canónica de anulación + huella.

- Encadenamiento en `billing_hashes` (nuevo eslabón).

- Endpoint `/invoices/{id}/cancel` que crea el registro de anulación.

- Envío por cola (`verifactu:process`) y envío SOAP como `RegistroAnulacion`.

Pendiente de pulir:

- Uso avanzado de flags `SinRegistroPrevio` / `RechazoPrevio` según `CancellationMode`.

- Escenarios de anulación sin registro previo en AEAT / tras rechazo previo, según doc oficial.

### 18.4. Facturas sin destinatario (TipoFactura = F3)

**Pendiente**

- Sin bloque `<Destinatarios>`

- Uso típico: tickets, ventas anónimas

### 18.5. Facturas simplificadas (TipoFactura = F2)

**Pendiente**

- Totales con IVA incluido en línea

- Desglose automático por tipo impositivo

### 18.6. IDOtro (identificadores internacionales)

**Pendiente**

- Soporte para:

  - `CodigoPais`

  - `IDType`

  - `IDNumero`

### 18.7. Trazabilidad en `billing_hashes` y `submissions` para todas las operaciones

Se añadirá/ampliará:

- `kind` → `alta` / `anulacion` / `rectify` / ...

- `type` en `submissions` → `register` / `cancel` / ...

- `rectified_json` → referencia/estructura de la factura original (rectificativas)

### 18.8. Estados especiales AEAT a documentar

| EstadoEnvio          | EstadoRegistro     | Significado                          |
| -------------------- | ------------------ | ------------------------------------ |
| Correcto             | Correcto           | OK                                   |
| Correcto             | AceptadoConErrores | Se ha procesado pero con incidencias |
| ParcialmenteCorrecto | AceptadoConErrores | Alguna parte está mal                |
| Incorrecto           | Incorrecto         | Rechazo total                        |
| Incorrecto           | _(vacío)_          | Error grave / estructura inválida    |

---

## 19\. Tests automatizados

El proyecto incluye tests unitarios para asegurar la estabilidad de la lógica crítica de VERI\*FACTU.

### 19.1. Ejecutar todos los tests

`php vendor/bin/phpunit`

### 19.2. Ejecutar un test concreto (builder AEAT)

`php vendor/bin/phpunit --filter VerifactuAeatPayloadBuilderTest`

Este test valida, entre otras cosas:

- Construcción de `RegistroAlta`

- Formato de fechas (`dd-mm-YYYY`)

- Cálculo de desglose (`DetalleDesglose`)

- Totales (`CuotaTotal`, `ImporteTotal`) consistentes con las líneas

### 19.3. Ejecutar tests de la cadena canónica

`php vendor/bin/phpunit --filter VerifactuCanonicalServiceTest`

Este test comprueba:

- Formato exacto de la cadena canónica (`csv_text`)

- Inclusión correcta de `FechaHoraHusoGenRegistro`

- Generación de la huella SHA-256 en mayúsculas

- Coherencia entre la cadena y los campos almacenados en `billing_hashes`

### 19.4. Caminos críticos cubiertos por tests

| Camino crítico                                                | Servicio / Componente                | Cobertura actual                                           | Pendiente / Futuro                                                                  |
| ------------------------------------------------------------- | ------------------------------------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Construcción de la **cadena canónica** + huella               | `VerifactuCanonicalService`          | ✅ `VerifactuCanonicalServiceTest`                         | Añadir casos límite (importes con muchos decimales, prev_hash nulo/no nulo, etc.)   |
| Cálculo de **desglose y totales** desde `lines`               | `VerifactuAeatPayloadBuilder`        | ✅ `VerifactuAeatPayloadBuilderTest`                       | Casos con varios tipos de IVA, descuentos, líneas a 0, etc.                         |
| Construcción de `RegistroAlta` (payload ALTA AEAT)            | `VerifactuAeatPayloadBuilder`        | ✅ Validación de campos básicos (fechas, totales, detalle) | Añadir soportes para tipos F2/F3/R1-R4, anulaciones y destinatarios internacionales |
| Construcción de `RegistroAnulacion`                           | `VerifactuAeatPayloadBuilder`        | ⏳ Pendiente de test específico                            | Validar referencia a factura anulada y encadenamiento                               |
| Generación de **QR AEAT**                                     | `VerifactuQrService`                 | ⏳ Pendiente de test unitario específico                   | Testear generación determinista de URL QR y ruta de fichero en disco                |
| Generación de **PDF oficial**                                 | `VerifactuPdfService` + vista `pdfs` | ⏳ Pendiente (actualmente validado manualmente)            | Testear que el HTML base se renderiza y el fichero PDF se genera sin errores        |
| Flujo de **worker / cola** (`ready` → envío → AEAT)           | `VerifactuService` + comando spark   | ⏳ Pendiente de tests de integración                       | Tests funcionales con respuestas SOAP simuladas (Correcto / Incorrecto / errores)   |
| Actualización de **estados AEAT** en BD                       | `VerifactuService` + `Submissions`   | ⏳ Pendiente de test unitario / integración                | Verificación de mapping correcto a `aeat_*` y `status` internos                     |
| Endpoints REST (`preview`, `cancel`, `verifactu`, `pdf`, ...) | `InvoicesController`                 | ⏳ Pendiente de tests tipo HTTP/feature                    | Tests de contrato (status codes, esquemas JSON, headers, etc.)                      |

---

## 20\. DIAGRAMA COMPLETO TPU (Trazabilidad)

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
│ (Alta / Anulación)
┌───────────┴──────────┐
│ EMPRESA A │
│ (Tu SaaS + SIF + │
│ Colaborador Social) │
└───────────▲──────────┘
│ XML firmado + Hash + Encadenamiento
│
┌───────────┴──────────┐
│ AEAT │
│ (VERI\*FACTU) │
└──────────────────────┘

---

## 21. Panel web de auditoría (Dashboard VERI\*FACTU)

Además de la API, el proyecto incluye un **panel web interno** para auditar y explorar los registros VERI\*FACTU.

Ruta típica (ejemplo):

- `/admin/verifactu`

### 21.1. Listado principal

La vista principal muestra una tabla paginada de `billing_hashes` con:

- Emisor (`issuer_nif`)
- Serie y número (`series`, `number`)
- Fecha de expedición (`issue_date`)
- Totales (`vat_total`, `gross_total`)
- Tipo de registro (`kind = alta / anulacion`)
- Estado interno (`status`: draft, ready, sent, accepted, accepted_with_errors, rejected, error)
- Estado AEAT (`aeat_send_status`, `aeat_register_status`)
- CSV AEAT (`aeat_csv`, si existe)
- Acciones rápidas:
  - Ver detalle
  - Descargar PDF
  - Ver/descargar XML (preview / request / response)
  - Ver QR

La lista se alimenta de `BillingHashModel` aplicando los filtros activos y ordenando por `id DESC`.

### 21.2. Filtros disponibles

Los filtros actuales (GET params) son:

- `company_id`
- `issuer_nif`
- `series`
- `status` (estado interno)
- `aeat_send_status`
- `aeat_register_status`
- `date_from` (filtra `issue_date >= date_from`)
- `date_to` (filtra `issue_date <= date_to`)

Ejemplo de URL:

`/admin/verifactu?company_id=1&issuer_nif=B12345678&status=ready&date_from=2025-01-01&date_to=2025-12-31`

### 21.3. Contadores por estado

En la parte superior del panel se muestran contadores calculados sobre la consulta actual:

- `totalRegistros` → total de filas tras aplicar filtros
- `readyCount` → número de registros en `status = ready`
- `sentCount` → número de registros en `status = sent`
- `errorCount` → número de registros en `status = error`

Internamente se calculan a partir de un `SELECT status, COUNT(*)` sobre el mismo conjunto filtrado.

> En una fase posterior se pueden añadir más contadores:
>
> - `accepted`, `accepted_with_errors`, `rejected`
> - separadores por emisor (`issuer_nif`) o por empresa (`company_id`)

### 21.4. Paths de artefactos por registro (`filesById`)

Para cada fila mostrada, el panel resuelve qué artefactos existen en disco utilizando un helper tipo `buildPaths($id, $row)` que devuelve algo como:

- `preview_xml_path`
- `request_xml_path`
- `response_xml_path`
- `pdf_path`
- `qr_path`

Esto permite saber en la propia tabla si:

- Ya existe PDF oficial
- Hay XML de request/response
- Falta algún artefacto (p. ej. todavía no se ha enviado a AEAT)

Los ficheros se almacenan normalmente bajo:

```text
writable/verifactu/
  previews/{id}-preview.xml
  requests/{id}-request.xml
  responses/{id}-response.xml
  pdfs/{id}.pdf
  qrs/{id}.png
```

### 21.5. Vista de detalle

Para cada `billing_hash` se ofrece una página de detalle donde se ve:

- Todos los campos de `billing_hashes` (datos de factura, tipo, hash, encadenamiento...)

- Artefactos generados (links a XML, PDF, QR)

- Estado AEAT actual

- Histórico de envíos (`submissions`), con:

  - fecha/hora

  - tipo (`register` / `cancel`)

  - CSV AEAT (si lo hay)

  - códigos y descripciones de error

  - paths de request/response asociados

Esta vista es la principal herramienta de **auditoría interna** para saber qué se ha enviado exactamente a AEAT y qué ha contestado en cada intento

---

**Autor:** Javier Delgado Berzal --- PTG (2025)
