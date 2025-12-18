# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con **VERI\*FACTU (AEAT)**.\
Incluye **tipado estricto**, **idempotencia**, **hash**, **firma WSSE**,\
**cola de envío**, **trazabilidad integral**, **XML oficial**, **QR AEAT** y **PDF oficial**.

Compatible con PHP **7.4 → 8.3**.

Actualmente soporta:

- **Altas de registros de facturación** (RegistroAlta) para:

  - **F1** → Facturas completas / ordinarias (con destinatario).
  - **F2** → Facturas simplificadas. En esta versión del middleware el payload de entrada
    se modela igual que F1 (`price` = base imponible, `vat` = tipo impositivo) y se marca
    `invoiceType = "F2"`. El soporte de “precio con IVA incluido en línea” se deja como
    mejora futura.
  - **F3** → Facturas completas con tipología especial F3. En este middleware funcionan
    igual que F1 (requieren destinatario); la diferencia es el valor de `TipoFactura = "F3"`
    en el XML.
  - **R1** → Factura rectificativa por error fundado en derecho (art. 80 Uno, Dos y Seis LIVA).
  - **R2** → Factura rectificativa por concurso de acreedores (art. 80 Tres LIVA).
  - **R3** → Factura rectificativa por créditos incobrables (art. 80 Cuatro LIVA).
  - **R4** → Factura rectificativa (resto de supuestos).
  - **R5** → Factura rectificativa de facturas simplificadas (tickets).

- **Anulaciones técnicas de registros de facturación** (RegistroAnulacion),
  encadenadas sobre el mismo obligado a emitir. (Encadenamiento en esta versión
  es por emisor, no por serie).

---

## 1. Objetivos del proyecto

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

## 2. Requisitos técnicos

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
- `spatie/pdf-to-text` --- **solo para tests**, permite extraer texto de los PDF y hacer asserts sobre el contenido

### 2.1. Dependencias del sistema para tests de PDF

Para poder ejecutar los tests que validan el contenido de los PDF, es necesario
tener instalado el binario `pdftotext` (suite **Poppler**) en el sistema:

- macOS (Homebrew):

  ```bash
  brew install poppler
  ```

- Debian/Ubuntu:

  ```bash
  sudo apt-get install poppler-utils
  ```

El binario se localiza típicamente en:

- macOS (Apple Silicon): `/opt/homebrew/bin/pdftotext`

- macOS (Intel): `/usr/local/bin/pdftotext`

- Linux: `/usr/bin/pdftotext` o `/usr/local/bin/pdftotext`

---

## 3. Instalación

`composer install`

Crear `.env`:

```env
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = 127.0.0.1
database.default.database = verifactu
database.default.username = root
database.default.password = secret
database.default.DBDriver = MySQLi
database.default.charset = utf8mb4

# Envío real (1) o simulado (0)
verifactu.sendReal = 0

# Conexión a entorno de PRE-AEAT
verifactu.isTest = true
```

---

### 3.1. Configuración del Sistema Informático de Facturación (SIF)

El middleware se instala por proyecto/cliente (una instalación por servidor o entorno).\
Los datos del **Sistema Informático de Facturación** (SIF) se configuran vía variables de entorno:

```env
verifactu.systemNameReason="Nombre o razón social del titular del SIF"
verifactu.systemNif="NIF del titular del SIF"
verifactu.systemName="Nombre comercial del sistema de facturación"
verifactu.systemId="Identificador interno del sistema (código libre)"
verifactu.systemVersion="Versión del sistema (SemVer recomendada)"
verifactu.installNumber="Identificador de la instalación del SIF" # si se deja vacío, se usa '0001'

# Flags de uso:
verifactu.onlyVerifactu="S"   # 'S' si solo se usa como SIF VERI*FACTU
verifactu.multiOt="S"         # 'S' si el SIF gestiona varios obligados tributarios
verifactu.multiplesOt="S"     # 'S' si gestiona múltiples OTs de forma simultánea

verifactu.middlewareVersion="{versión del middleware, p.ej. 1.0.0}" # Solo para tu código, despliegues, changelog, health, etc.
```

### 3.**2** Entorno local con Docker (PHP 8.2)

Este proyecto puede ejecutarse en local usando Docker (PHP 8.2 + Apache) sin depender
de la versión de PHP instalada en el sistema.

👉 Guía completa aquí:  
**[`DOCKER-LOCAL.md`](./DOCKER-LOCAL.md)**

Incluye:

- Imagen PHP 8.2 + Apache con extensiones necesarias para CodeIgniter 4
- Configuración de Apache (`vhost.conf`) apuntando a `/public`
- Levantar el entorno en `http://localhost:8082`
- Ejecutar comandos `php spark` dentro del contenedor
- Logs y troubleshooting
- Cómo levantar varias instancias en distintos puertos (multi-entorno)

---

---

## 4\. Migraciones y Seeders

Tablas principales:

| Tabla            | Finalidad                                   |
| ---------------- | ------------------------------------------- |
| `companies`      | Multiempresa + flags VERI\*FACTU            |
| `api_keys`       | Autenticación                               |
| `billing_hashes` | Estado local, cadena, hash, QR, XML, PDF... |
| `submissions`    | Historial de envíos, reintentos y errores   |

Instalación:

`php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder`

---

## 5\. Autenticación

---

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

- `issuer.nif` (obligado a emitir / emisor)

- `recipient.nif` (si se informa en el payload)

Se utiliza un validador interno `SpanishIdValidator` que comprueba:

- **DNI** (8 dígitos + letra con control)

- **NIE** (X/Y/Z + 7 dígitos + letra, convertido internamente a DNI)

- **CIF** (letra inicial, 7 dígitos, dígito o letra de control calculados)

Si el NIF/NIE/CIF no es válido, el `preview` devuelve:

- `422 Unprocessable Entity` con mensaje tipo\
  `issuerNif is not a valid Spanish NIF/NIE/CIF`\
  o `recipient.nif is not a valid Spanish NIF/NIE/CIF`.

Estas facturas **no entran en la cola** y por tanto **nunca se envían a AEAT**.

---

### 5.2. Emisor de la factura (`issuer`)

El middleware **no decide** quién es el emisor (obligado a emitir la factura).\
Ese dato siempre viene en el cuerpo del request dentro del bloque `issuer`.

El sistema origen (ERP, SaaS, plataforma de reservas, etc.) es responsable de:

- Resolver quién es el emisor real de la factura (empresa, franquicia, local, etc.).

- Validar que existe en su modelo de datos.

- Enviar al middleware el bloque:

```json
"issuer": {
  "nif": "B12345678",                  // OBLIGATORIO
  "name": "Transporte Costa Sol S.L.", // OBLIGATORIO para F1/F2/F3/R*
  "address": "Calle Mayor 1",          // opcional pero recomendado
  "postalCode": "28001",
  "city": "Málaga",
  "province": "Málaga",
  "country": "ES"                      // ISO 3166-1 alpha-2
}
```

El middleware:

- Valida sintácticamente `issuer.nif` con `SpanishIdValidator`.

- Utiliza `issuer.nif` como `IDEmisorFactura` en el XML VERI\*FACTU.

- Copia estos datos a `billing_hashes`:

  - `issuer_nif` ← `issuer.nif`

  - `issuer_name` ← `issuer.name`

  - `issuer_address` ← `issuer.address`

  - `issuer_postal_code` ← `issuer.postalCode`

  - `issuer_city` ← `issuer.city`

  - `issuer_province` ← `issuer.province`

  - `issuer_country_code` ← `issuer.country`

Ejemplo de payload completo (F1) usando `issuer`:

```json
{
  "invoiceType": "F1",
  "externalId": "ERP-2025-000123",

  "issuer": {
    "nif": "B12345678",
    "name": "Transporte Costa Sol S.L.",
    "address": "Calle Mayor 1",
    "postalCode": "29001",
    "city": "Málaga",
    "province": "Málaga",
    "country": "ES"
  },

  "recipient": {
    "name": "Cliente Demo S.L.",
    "nif": "A87654321",
    "country": "ES",
    "address": "Avenida Principal 5",
    "postalCode": "28001",
    "city": "Madrid",
    "province": "Madrid"
  },

  "issueDate": "2025-11-19",
  "series": "F2025",
  "number": 1234,
  "description": "Servicios de transporte",

  "taxRegimeCode": "01",
  "operationQualification": "S1",

  "lines": [
    {
      "desc": "Traslado aeropuerto-hotel",
      "qty": 1,
      "price": 50.0,
      "vat": 21
    }
  ]
}
```

Para **F2** el bloque `issuer` es exactamente el mismo; lo que cambia es:

- `invoiceType = "F2"`

- Las reglas sobre `recipient` (prohibido/permitido según F2/R5, ya gestionado por `InvoiceDTO`).

La relación con la `company` del middleware sigue siendo:

- La API key / JWT te da una `company` (tabla `companies`).

- Esa `company` tiene un `issuer_nif` esperado.

- El endpoint compara el `issuer.nif` del payload con el `issuer_nif` de la empresa del contexto (cuando decidas activar ese cortafuegos con JWT).

### 5.3 Campos adicionales de cliente y régimen fiscal

Además de los campos ya descritos, el endpoint `/api/v1/invoices/preview` admite los
siguientes campos **opcionales**, que el middleware guarda de forma atómica en
`billing_hashes` (además de en `raw_payload_json`):

#### 5.3.1. Datos de cliente (`recipient`)

```jsonc
{
  "recipient": {
    "name": "Cliente S.L.",
    "nif": "B12345678", // O alternativamente el bloque IDOtro
    "country": "ES", // ISO 3166-1 alpha-2

    // Campos opcionales para PDF / filtros
    "address": "Calle Mayor 1",
    "postalCode": "28001",
    "city": "Madrid",
    "province": "Madrid"
  }
}
```

Estos campos se copian a `billing_hashes` como:

- `client_name` ← `recipient.name`

- `client_document` ← `recipient.nif` **o**, si no existe, `recipient.idNumber`

- `client_country_code` ← `recipient.country`

- `client_address` ← `recipient.address`

- `client_postal_code` ← `recipient.postalCode`

- `client_city` ← `recipient.city`

- `client_province` ← `recipient.province`

> Nota: el middleware **sigue almacenando el payload completo** en `raw_payload_json`\
> para trazabilidad y auditoría, pero utiliza estos campos atómicos para PDF,\
> panel y filtros de forma eficiente.

#### 5.3.2. Régimen y calificación de la operación

```json
{
  "taxRegimeCode": "01",
  "operationQualification": "S1"
}
```

Por diseño, estos campos controlan la pareja:

- `ClaveRegimen`

- `CalificacionOperacion`

que se informa en el XML de AEAT dentro del desglose de IVA.

En **esta versión** del middleware:

- `taxRegimeCode` solo admite el valor `01` (régimen general).

- `operationQualification` solo admite el valor `S1` (operación sujeta y no exenta, interior).

Cualquier otro valor producirá un error de validación (`422 Unprocessable Entity`).

Si el integrador **no informa** estos campos:

- `taxRegimeCode` se asume `01`.

- `operationQualification` se asume `S1`.

Internamente, los valores se guardan en `billing_hashes` como:

- `tax_regime_code`

- `operation_qualification`

### 5.4. Relación entre API key, `company` e `issuerNif`

Cada API key se asocia a una fila de la tabla `companies`:

- `companies.id` → `company_id` que se guarda en `billing_hashes`.

- `companies.issuer_nif` → NIF del emisor de las facturas (obligado tributario).

En cada petición:

1.  El filtro `ApiKeyAuthFilter`:

    - Valida `X-API-Key`.

    - Carga la empresa asociada (`companies`).

    - Inyecta en el contexto (`RequestContext`) un array con:

      - `id`, `slug`, `issuer_nif`.

2.  El endpoint `/invoices/preview`:

    - Valida `issuerNif` en el payload.

    - Comprueba que `issuerNif` coincide con `companies.issuer_nif` de la empresa\
      asociada a la API key.

    - Si no coincide, devuelve `422 Unprocessable Entity` y la factura **no** entra\
      en el flujo de hash/cola/AEAT.

De esta forma:

- Cada API key solo puede emitir facturas para el emisor (NIF) que tenga asignado.

- No es necesario mantener una tabla adicional de emisores autorizados.

La activación del cortafuegos se hace por instalación, vía `.env`.

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

```text
app/
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
    pdfs/verifactu_invoice.php
```

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

- `chain_index` → posición en la cadena para ese emisor (por empresa + NIF)

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

Representa **el estado actual y definitivo** del registro técnico de la factura
(tanto de **altas** como de **anulaciones**).

El middleware sigue una estrategia **híbrida**:

- Guarda el **payload original** en `raw_payload_json` (snapshot íntegro para auditoría).

- Además, normaliza y guarda ciertos campos en columnas atómicas para:

  - generar PDF/QR/XML sin depender de JSON,

  - permitir filtros y paneles eficientes,

  - mejorar rendimiento en consultas.

### 9.1. Datos originales de factura

Identificación básica de la factura:

- `company_id` --- empresa propietaria del registro.

- `issuer_nif` --- NIF del emisor.

- `issuer_name` --- nombre/razón social del emisor (cuando se informa).

- `series` --- serie de la factura.

- `number` --- número de la factura (dentro de la serie).

- `issue_date` --- fecha de expedición de la factura.

- `invoice_type` --- tipo de factura (F1, F2, F3, R1--R5).

- `external_id` --- identificador opcional en el sistema origen.

Líneas y totales:

- `lines_json` --- array de líneas `{desc, qty, price, vat, discount?}`.

- `details_json` --- desglose por tipo impositivo usado en `DetalleDesglose`.

- `vat_total` --- suma de cuotas de IVA.

- `gross_total` --- total bruto (base + IVA).

Payload íntegro:

- `raw_payload_json` --- JSON original recibido por el middleware\
  en `/api/v1/invoices/preview`.

### 9.2. Datos de cliente (para PDF / filtros / panel)

Se rellenan a partir del bloque `recipient` del payload, pero se guardan como\
columnas independientes para evitar búsquedas sobre JSON:

- `client_name` --- nombre o razón social del destinatario.

- `client_document` --- NIF o identificador alternativo (IDOtro).

- `client_country_code` --- código de país (ISO 3166-1 alpha-2).

- `client_address` --- dirección postal.

- `client_postal_code` --- código postal.

- `client_city` --- ciudad.

- `client_province` --- provincia.

Estos campos se usan principalmente en:

- listado del panel de auditoría,

- generación de PDF,

- filtros por cliente.

### 9.3. Régimen y calificación de la operación

Controlan la pareja `ClaveRegimen` / `CalificacionOperacion` informada en el XML\
de AEAT:

- `tax_regime_code`

- `operation_qualification`

En la versión actual del middleware:

- Solo se admite `tax_regime_code = '01'` (régimen general).

- Solo se admite `operation_qualification = 'S1'`\
  (sujeta y no exenta, operación interior).

Otros valores se rechazan en la capa DTO / validación de entrada.\
En futuras versiones se podrán habilitar otros regímenes, manteniendo estos\
campos como punto único de verdad.

### 9.4. Tipo de registro (alta / anulación / rectificativa)

- `kind` --- tipo de registro VERI\*FACTU:

  - `alta` → `RegistroAlta` (factura original).

  - `anulacion` → `RegistroAnulacion` (anula un registro de alta previo).

- `original_billing_hash_id` --- referencia lógica al `billing_hash` de alta que\
  se anula (solo para `kind = 'anulacion'`).

- Campos para rectificativas:

  - `rectified_billing_hash_id` --- referencia al `billing_hash` de la factura original rectificada (si se localiza).

  - `rectified_meta_json` --- JSON con la información de rectificación\
    (`mode`, `original {series, number, issueDate}`, etc.).

- Motivo de anulación (informativo, no se envía a AEAT):

  - `cancel_reason` --- texto opcional con el motivo.

  - `cancellation_mode` --- modo de anulación (según reglas internas del middleware).

### 9.5. Cadena y huella (encadenamiento)

Campos relacionados con la cadena canónica y el encadenamiento:

- `csv_text` --- cadena canónica completa (texto plano).

- `hash` --- huella SHA-256 en mayúsculas (`Huella`).

- `prev_hash` --- hash inmediatamente anterior para ese emisor (`issuer_nif`).

- `chain_index` --- posición en la cadena para ese emisor\
  (por empresa + `issuer_nif`).

- `datetime_offset` --- fecha/hora/huso usados en la cadena\
  (`FechaHoraHusoGenRegistro`).

### 9.6. Artefactos y cola de procesamiento

Artefactos generados:

- `xml_path` --- ruta del XML de previsualización / último XML oficial.

- `pdf_path` --- ruta del PDF oficial generado.

- `qr_url` --- URL al QR AEAT (el fichero físico se genera en `writable/`).

Cola interna:

- `status` --- estado interno del registro:

  - `draft`, `ready`, `sent`, `accepted`,

  - `accepted_with_errors`, `rejected`, `error`.

- `next_attempt_at` --- fecha/hora a partir de la cual se puede reintentar el envío.

- `processing_at` --- marca de bloqueo temporal mientras lo procesa el worker.

- `idempotency_key` --- token para repetir peticiones de `/preview`\
  sin duplicar registros.

### 9.7. Estado AEAT

Campos relacionados con la respuesta de AEAT para ese registro:

- `aeat_csv` --- CSV devuelto por AEAT.

- `aeat_send_status` --- estado de envío:

  - `Correcto`, `ParcialmenteCorrecto`, `Incorrecto`.

- `aeat_register_status` --- estado del registro de facturación:

  - `Correcto`, `AceptadoConErrores`, `Incorrecto`.

- `aeat_error_code` --- código numérico AEAT (cuando aplica).

- `aeat_error_message` --- descripción textual devuelta por AEAT.

Además, se guardan:

- `created_at` / `updated_at` --- trazabilidad interna del middleware.

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

### 11.1. Gestión de reintentos (`scheduleRetry()`)

Cuando se produce un error temporal en el envío a AEAT (timeout, error SOAP, problema de red, etc.), el middleware no pierde el intento ni deja el registro en un estado ambiguo. En su lugar, utiliza una función interna `scheduleRetry()` que:

- Inserta una fila en `submissions` con:

  - `billing_hash_id` → ID del registro afectado,
  - `type` → `register` o `cancel` según el caso,
  - `status = "error"`,
  - `error_message` con el motivo técnico del fallo.

- Actualiza el propio `billing_hashes`:
  - `status = "error"` (para distinguirlo de `ready` o `sent`),
  - `next_attempt_at` con una fecha/hora futura (por defecto, **+15 minutos** desde el momento del fallo).

De esta forma, el comando `php spark verifactu:process` puede reintentar más tarde solo aquellos registros marcados como `error` y cuya `next_attempt_at <= NOW()`, manteniendo trazabilidad completa de cada intento y su motivo de fallo.

---

## 11.2. Dispatcher inmediato (kick) al crear `/preview`

Además del **cron / worker** principal, el middleware soporta un mecanismo opcional de\
**dispatcher inmediato ("kick")**.\
Cuando una factura se crea en `/api/v1/invoices/preview` y queda en `status = ready`,\
el sistema **puede intentar disparar automáticamente** el procesador de cola para que\
el envío a AEAT ocurra en segundos, sin esperar al siguiente tick del cron.

### ¿Qué hace exactamente el "kick"?

- **NO** envía la factura de forma síncrona dentro del request HTTP.

- **NO** bloquea ni retrasa la respuesta del endpoint `/preview`.

- Ejecuta **best-effort** el comando:

  `php spark verifactu:process {N}`

  en **background**, si el entorno lo permite.

- Si el disparo falla (por permisos, `exec` deshabilitado, etc.),\
  **la petición NO falla** y la factura queda igualmente en cola para el cron/worker.

El sistema utiliza un **anti-rebote** (`dispatchTtl`) para evitar disparos masivos\
cuando se crean muchas facturas en poco tiempo.

---

### Modos de funcionamiento

El comportamiento se controla mediante la variable `verifactu.dispatchMode`:

- `noop`\
  No se lanza ningún proceso automáticamente.\
  Usar este modo cuando:

  - Existe un **cron** ejecutando `php spark verifactu:process`.

  - Se utiliza un **worker dedicado** (por ejemplo, en Docker o Kubernetes).

- `spark`\
  El middleware lanza el comando `php spark verifactu:process` en **background**\
  usando `nohup`, sin bloquear la request.

### Recomendación de uso (importante)

Aunque el dispatcher inmediato **puede convivir técnicamente** con un cron o worker\
(si ambos se ejecutan a la vez no se producen duplicados gracias al locking interno),\
**NO se recomienda activar ambos mecanismos simultáneamente en producción**.

La recomendación general es:

- **Producción / carga media-alta**\
   Usar **solo cron o worker dedicado**

  `verifactu.dispatchMode = noop`

- **Entornos sin cron**, Docker simple o setups de baja carga\
  Usar dispatcher inmediato

  `verifactu.dispatchMode = spark`

El cron o worker sigue siendo el **mecanismo principal y más predecible** para el\
procesamiento de la cola VERI\*FACTU.

### Configuración (`.env`)

```env
# Modo del dispatcher:

# - noop -> no dispara nada (cron o worker externo)

# - spark -> lanza "php spark verifactu:process" en background

verifactu.dispatchMode = spark

# Ruta del binario PHP

# En Docker suele ser /usr/local/bin/php

verifactu.phpBin = /usr/local/bin/php

# Anti-rebote en segundos

# Evita lanzar múltiples procesos si entran varias previews seguidas

verifactu.dispatchTtl = 3
```

### Detalles técnicos

- El dispatcher usa internamente `nohup` y ejecución en background (`&`).

- La salida estándar y de error se descarta por defecto (`/dev/null`).

- Cualquier error en el _kick_ **no rompe** la request de `/preview`.

- El envío real y la gestión de reintentos siguen estando controlados\
  exclusivamente por el comando `verifactu:process`.

### Recomendación de uso

- **Producción con carga o alta criticidad**\
  → `verifactu.dispatchMode = noop` + cron o worker dedicado.

- **Entornos pequeños o sin cron**\
  → `verifactu.dispatchMode = spark` para reducir latencia sin complejidad extra.

Este diseño permite usar **el mismo código** en todos los entornos, cambiando\
únicamente la configuración del `.env`.

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

- Datos base del registro (issuer_nif, serie/número, fechas, totales)

- Tipo de registro (`kind = alta` / `anulacion`)

- Cadena canónica (`csv_text`), hash y encadenamiento

- Artefactos:

  - QR (`qr_url`)

  - XML asociado (`xml_path`)

  - PDF (`pdf_path`, si existe)

- Estado AEAT actual:

  - `aeat_csv`, `aeat_send_status`, `aeat_register_status`, errores...

  - Último envío a AEAT (`last_submission`), con referencias a request/response.

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

- Guarda el archivo en `WRITEPATH/verifactu/qr/{id}.png`.

- Actualiza `billing_hashes.qr_path` y `billing_hashes.qr_url`.

Este QR se reutiliza luego tanto en el PDF como en cualquier UI externa.

### 15.1. Respuesta (por defecto: PNG)

Si no se indica ningún parámetro, responde con la imagen como `image/png`.

Ejemplo:

`GET /api/v1/invoices/123/qr`

### 15.2. Respuesta alternativa (base64)

Para integraciones que necesiten embeber el QR (por ejemplo en HTML/PDF/app móvil),
se puede solicitar como JSON en base64:

`GET /api/v1/invoices/123/qr?format=base64`

Respuesta:

```json
{
  "data": {
    "document_id": 123,
    "mime": "image/png",
    "base64": "iVBORw0KGgoAAAANSUhEUgAA...",
    "data_uri": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
  },
  "meta": {
    "request_id": "...",
    "ts": 1731840000
  }
}
```

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
  "reason": "Factura emitida por error"
}`

- `reason` (opcional): motivo interno de anulación (guardado en `cancel_reason`).
  Response:
  201 Created

  ```json
  {
    "data": {
      "document_id": 456,
      "kind": "anulacion",
      "status": "ready",
      "hash": "ABCDEF1234...",
      "prev_hash": "XYZ987...",
      "aeat_status": null
    },
    "meta": {
      "request_id": "...",
      "ts": 1731840000
    }
  }
  ```

401 Unauthorized → API key/token inválido.

403 Forbidden → sin empresa en contexto.

404 Not Found → no existe el documento o pertenece a otra empresa.

422 Unprocessable Entity → no se puede anular (por reglas internas).

500 Internal Server Error → error inesperado.

🔹 El **modo de anulación AEAT** (`SinRegistroPrevio`, `RechazoPrevio`, caso normal...)\
se determina automáticamente por el propio middleware, en función del histórico\
de envíos de esa factura en la tabla `submissions`.\
El cliente **no tiene que indicar nada especial**.

### 16.2. Comportamiento

- Busca el `billing_hash` original (`kind = 'alta'`) para ese `id` y `company_id`.
- Comprueba que la factura original es anulable (por ejemplo, `kind = 'alta'` y que pertenece a la empresa del contexto).
- Analiza el histórico de `submissions` para ese `billing_hash` y determina internamente el **modo de anulación** (`cancellation_mode`) siguiendo este orden de prioridad:

  1. Si existe una **anulación previa rechazada**  
     → `cancellation_mode = PREVIOUS_CANCELLATION_REJECTED`
  2. En otro caso, si existe un **registro previo aceptado o aceptado con errores**  
     → `cancellation_mode = AEAT_REGISTERED`
  3. Si no existe ningún registro previo en AEAT (ni alta ni anulación aceptada)  
     → `cancellation_mode = NO_AEAT_RECORD`

- Crea una nueva fila en `billing_hashes` para la anulación, donde:

  - `kind = 'anulacion'`
  - `original_billing_hash_id = id` de la factura original
  - `series` y `number` = **los mismos** que la factura original
  - `company_id`, `issuer_nif` y el resto de datos de contexto se copian del registro original
  - `external_id` se copia desde la factura original (para mantener trazabilidad con el sistema origen)
  - `cancel_reason` se rellena con el motivo recibido en el body (si se informa)
  - `cancellation_mode` se guarda con el valor calculado según el histórico en `submissions`
  - `vat_total = 0.0` y `gross_total = 0.0` (totales técnicos para anulaciones VERI\*FACTU)

- Genera **en el momento de creación**:

  - la **cadena canónica de anulación** (`csv_text`),
  - la **huella SHA-256** (`hash`, en mayúsculas),
  - el **encadenamiento**:
    - `prev_hash` = `hash` de la factura original,
    - `chain_index` > `chain_index` original (nuevo eslabón en la cadena).

- Inicializa los campos de cola:

  - `status = "ready"` (lista para envío),
  - `next_attempt_at = NOW()`.

La anulación se comporta como **un nuevo registro VERI\*FACTU encadenado**, nunca se modifica ni se borra el registro original de alta.

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

## 17. Pendiente / roadmap

- Mejorar el **diseño del PDF oficial**:

  - Branding por empresa.
  - Soporte multi-idioma.
  - Textos legales configurables (LOPD, RGPD, etc.).

- Añadir validación XSD completa contra esquemas AEAT:

  - Validar `RegFactuSistemaFacturacion` (alta/anulación) contra los XSD oficiales antes de enviar.
  - Exponer los errores XSD de forma legible en el panel / API.

- Política de **retry**:

  - ✅ Implementado: reintento automático para errores técnicos (SOAP, timeouts, problemas de red) mediante `VerifactuService::scheduleRetry()`, que:
    - Inserta un `submission` con `status = "error"` y detalle de `error_message`.
    - Actualiza el `billing_hash` a `status = "error"` y programa `next_attempt_at` a **+15 minutos**.
  - Pendiente: refinar la política de reintentos:
    - Backoff más fino (exponencial o configurable).
    - Clasificar errores por código AEAT para marcar explícitamente qué casos son **no retryable** (p.ej. duplicados, estructura inválida, etc.).

- Ampliar validaciones y tests para destinatarios internacionales (bloque **IDOtro**):

  - ✅ Implementado: soporte básico de IDOtro en `InvoiceDTO` + builder (tipos `02–07`, mezcla NIF/IDOtro prohibida).
  - Pendiente: más casuística y casos límite (combinaciones país/tipo, escenarios reales adicionales).

- Panel web opcional (Dashboard VERI\*FACTU):

  - ✅ Exploración básica de facturas (listado + filtros + detalle) sobre `billing_hashes`.
  - ✅ Visualización de artefactos (XML, PDF, QR) y del histórico de `submissions`.
  - ✅ Descarga **individual** de artefactos por registro (`/admin/verifactu/{id}/download/{preview|request|response|pdf}` y `qr` embebible).
  - Pendiente: **descarga masiva** de XML/PDF/QR (por rango de fechas, filtros, emisor, etc.), por ejemplo generando un ZIP descargable desde el propio panel.

- Ajustar la generación de `FechaHoraHusoGenRegistro` para:
  - reflejar siempre el momento real de **envío** del registro a AEAT, y
  - cumplir estrictamente la ventana temporal exigida (≈ 240 s) incluso cuando el envío se difiere en cola.
  - Estado actual: la API genera `datetime_offset` en el `preview` / creación de anulación y lo reutiliza en el envío; funcional en envíos inmediatos, pero pendiente de ajustar para escenarios de envío tardío.

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

### 18.2. Facturas rectificativas (TipoFactura = R1, R2, R3, R4, R5)

Estado actual: **IMPLEMENTADO A NIVEL TÉCNICO (ALTA + ENVÍO AEAT)**

Se soportan facturas rectificativas:

- **R1 / R2 / R3 / R4** → mismas reglas técnicas, cambia solo la causa legal.

- **R5** → rectificativas de facturas simplificadas (tickets). Técnicamente se tratan como cualquier R\*, pero:

  - No se permiten destinatarios (igual que F2).

  - Siempre requieren bloque `rectify` con referencia a la factura simplificada original.

El payload de entrada amplía el `InvoiceInput` con un bloque `rectify`:

```json
{
  "invoiceType": "R1",

  "issuer": {
    "nif": "B61206934",
    "name": "Mi Empresa S.L.",
    "address": "Calle Mayor 1",
    "postalCode": "28001",
    "city": "Madrid",
    "province": "Madrid",
    "country": "ES"
  },

  "series": "R",
  "number": 2,
  "issueDate": "2025-11-19",

  "lines": [
    {
      "desc": "Rectificación servicio aeropuerto-hotel",
      "qty": 1,
      "price": 80,
      "vat": 21
    }
  ],

  "recipient": {
    "name": "Cliente Demo S.L.",
    "nif": "D41054115"
  },

  "rectify": {
    "mode": "difference", // o "substitution"
    "original": {
      "series": "F",
      "number": 62,
      "issueDate": "2025-11-19"
    }
  }
}
```

- `mode = "substitution"` → el middleware envía `TipoRectificativa = "S"` **e informa el bloque `ImporteRectificacion`**.

- `mode = "difference"` → el middleware envía `TipoRectificativa = "I"` **y NO informa el bloque `ImporteRectificacion`**, tal y como exige AEAT.

El middleware:

1.  Localiza la factura original en `billing_hashes` (por empresa, emisor, serie, número, fecha y `kind = 'alta'`).

2.  Guarda:

    - `rectified_billing_hash_id` → ID de la original.

    - `rectified_meta_json` → JSON con `mode` + `original`.

3.  En el envío a AEAT (`verifactu:process`):

    - Construye el bloque `FacturasRectificadas` con los datos de la factura original.

    - Informa `TipoRectificativa` según `rectify.mode`:

      - `"substitution"` → `TipoRectificativa = "S"` + bloque `ImporteRectificacion`.

      - `"difference"` → `TipoRectificativa = "I"` **sin** bloque `ImporteRectificacion`.

```md
⚠️ **Nota sobre `ImporteRectificacion` (regla AEAT)**

- En rectificativas **por sustitución** (`TipoRectificativa = "S"`), AEAT exige

informar el bloque `ImporteRectificacion` con los importes que sustituyen a la

factura original.

- En rectificativas **por diferencias** (`TipoRectificativa = "I"`), AEAT

**prohíbe** informar `ImporteRectificacion`. La diferencia se deduce a partir

de la propia factura rectificativa (líneas, bases, cuotas y totales).

El middleware implementa esta regla:

- `mode = "substitution"` → se genera `ImporteRectificacion`.

- `mode = "difference"`   → no se genera `ImporteRectificacion`.
```

#### 18.2.x. Múltiples facturas rectificadas (`rectified_invoices[]`)

El payload de entrada puede incluir **varias facturas rectificadas** en el array\
`rectified_invoices[]`. Esto ocurre cuando una misma rectificativa sustituye o\
ajusta **más de una factura original**.

En esta versión del middleware, el comportamiento es:

- El builder genera **todas** las facturas originales en:

  `FacturasRectificadas.IDFacturaRectificada[]`

- El bloque "plano" obligatorio:

  `FacturaRectificada`

  se rellena **solo con la PRIMERA** factura del array (`rectified_invoices[0]`).\
  Esto se hace porque AEAT exige este bloque, pero no permite múltiples nodos\
  repetidos al mismo nivel.

- El cálculo de `ImporteRectificacion` (cuando `mode = "substitution"`) se hace\
  **siempre** desde la rectificativa actual (sus líneas/base/cuota), **NO**\
  sumando las facturas originales.

Este comportamiento está fijado mediante tests en `VerifactuAeatPayloadBuilderTest`\
(`test_it_uses_first_rectified_invoice_when_multiple_are_provided()`).

### 18.3. Anulaciones (RegistroAnulacion)

Estado actual: **IMPLEMENTADO (núcleo técnico operativo, decisión automática)**

Ya implementado:

- Modelo de datos (`kind = 'anulacion'`, `original_billing_hash_id`, `cancel_reason`, `cancellation_mode`).

- Cadena canónica de anulación + huella.

- Encadenamiento en `billing_hashes` (nuevo eslabón).

- Endpoint `/invoices/{id}/cancel` que crea el registro de anulación.

- Envío por cola (`verifactu:process`) y envío SOAP como `RegistroAnulacion`.

- Decisión automática del modo de anulación en el middleware:

  - Alta previa aceptada → anulación normal (sin flags AEAT especiales).

  - Sin alta previa aceptada → flag `SinRegistroPrevio`.

  - Anulación previa rechazada → flag `RechazoPrevio`.

- En todas las anulaciones:

- Se copian `series`, `number`, `issuer_nif` y `external_id` desde la factura original.
- Los totales técnicos se fijan siempre a `vat_total = 0.0` y `gross_total = 0.0`.
- Se genera en el mismo momento la cadena canónica de anulación (`csv_text`), la huella (`hash`) y el encadenamiento (`prev_hash`, `chain_index`), dejando el registro en `status = "ready"` para su envío por la cola.

Pendiente de pulir:

- Tests específicos para `buildCancellation()` y verificación de que los flags `SinRegistroPrevio` / `RechazoPrevio` se aplican correctamente para cada escenario.

- Documentar más ejemplos de flujos reales (ej. anulación antes de enviar, cadena de varios intentos, etc.).

### 18.4. Facturas F3 (TipoFactura = F3)

Estado actual: **YA IMPLEMENTADO (misma estructura que F1)**

En esta versión del middleware:

- `invoiceType = "F3"` genera en el XML `TipoFactura = "F3"`.

- El payload de entrada es **el mismo que para F1**:

  - Requiere destinatario (`recipient`), ya sea:

    - `recipient.name` + `recipient.nif`, o

    - bloque completo `IDOtro` (country, idType, idNumber).

  - Las líneas (`lines[]`) se interpretan como:

    - `price` = base imponible,

    - `vat` = tipo impositivo (%),

    - opcionalmente `discount`.

- El desglose (`DetalleDesglose`) y los totales (`CuotaTotal`, `ImporteTotal`) se\
  calculan exactamente igual que en F1.

En otras palabras: a nivel técnico, el middleware trata F3 como "otra clase de factura\
completa" con el mismo modelo de datos que F1, pero marcando la tipología `F3` en el XML.

### 18.5. Facturas simplificadas (TipoFactura = F2)

Estado actual: **IMPLEMENTADO A NIVEL TÉCNICO (misma interpretación que F1)**

En esta versión del middleware:

- El cliente envía `invoiceType = "F2"` en el payload.

- El XML resultante informa `TipoFactura = "F2"` en `RegistroAlta`.

- Las líneas se interpretan igual que en F1/F3:

  - `price` = base imponible,

  - `vat` = tipo impositivo (%),

  - opcionalmente `discount`.

- El `VerifactuAeatPayloadBuilder` calcula:

  - `DetalleDesglose` a partir de esas líneas,

  - `CuotaTotal` e `ImporteTotal` a partir de bases y cuotas.

⚠ **Nota sobre precios con IVA incluido**

El esquema AEAT permite que, en facturas simplificadas, el precio pueda venir con IVA\
incluido en línea. En este middleware, por simplicidad, **no se ha activado aún** ese modo:

- No se aceptan de momento precios "IVA incluido".

- Se asume siempre `price` = base sin IVA.

En el roadmap está previsto añadir un modo opcional de configuración para:

- admitir precios con IVA incluido en línea, y

- convertirlos internamente a base + cuota antes de construir el XML VERI\*FACTU.

---

### 18.6. Identificadores internacionales (IDOtro)

Estado actual: **YA IMPLEMENTADO**

El middleware soporta destinatarios sin NIF español mediante el bloque `IDOtro`.

Ejemplo de entrada:

```json
"recipient": {
  "name": "John Smith",
  "country": "GB",
  "idType": "02",
  "idNumber": "AB1234567"
}
```

Reglas:

- Debes enviar `name`, `country` (ISO-3166 alpha2), `idType` y `idNumber`.

- `idType` debe estar en: **02, 03, 04, 05, 06, 07** (catálogo AEAT).

  - 02 NIF-IVA

  - 03 Pasaporte

  - 04 Documento oficial de identificación expedido por el país o territorio de residencia

  - 05 Certificado de residencia

  - 06 Otro documento probatorio

  - 07 No censado

- Si se usa `IDOtro`, **no** se puede enviar `recipient.nif`.

- El XML generado será:

```xml
<Destinatarios>
  <IDDestinatario>
    <NombreRazon>John Smith</NombreRazon>
    <IDOtro>
      <CodigoPais>GB</CodigoPais>
      <IDType>02</IDType>
      <ID>AB1234567</ID>
    </IDOtro>
  </IDDestinatario>
</Destinatarios>
```

---

### 18.7. Trazabilidad en `billing_hashes` y `submissions` para todas las operaciones

Se añadirá/ampliará:

- `kind` → `alta` / `anulacion` / `rectify` / ...

- `type` en `submissions` → `register` / `cancel` / ...

- `rectified_json` → referencia/estructura de la factura original (rectificativas)

---

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

El proyecto incluye tests unitarios y de feature para asegurar la estabilidad de la lógica crítica de VERI\*FACTU y de los endpoints HTTP.

### 19.1. Ejecutar todos los tests

`php vendor/bin/phpunit`

Además de tests unitarios, el proyecto incluye **tests de feature HTTP** para los endpoints\
principales de facturación:

- `POST /api/v1/invoices/preview`

- `GET /api/v1/invoices/{id}/verifactu`

- `GET /api/v1/invoices/{id}` (show)

- `GET /api/v1/invoices/{id}/pdf`

- `GET /api/v1/invoices/{id}/qr`

- `POST /api/v1/invoices/{id}/cancel`

- `GET /api/v1/health`

Estos tests validan:

- Códigos de estado.

- Estructura básica del JSON devuelto.

- Reglas de multiempresa (aislamiento por `company_id` via `RequestContext`).

- Persistencia de artefactos (PDF/QR) en disco y limpieza posterior.

- Mensajes de error y códigos internos (`VF404`, validaciones, etc.).

### 19.2. Tests del builder AEAT (`VerifactuAeatPayloadBuilderTest`)

Los tests de `VerifactuAeatPayloadBuilderTest` validan la construcción del payload técnico que se envía a la AEAT (`RegistroAlta` y `RegistroAnulacion`), incluyendo:

- **Altas normales (F1)**

  - Cabecera `ObligadoEmision`.

  - `IDFactura` (`IDEmisorFactura`, `NumSerieFactura`, `FechaExpedicionFactura` en formato `dd-mm-YYYY`).

  - Cálculo de desglose (`DetalleDesglose`) y totales (`CuotaTotal`, `ImporteTotal`).

  - Encadenamiento cuando `prev_hash` es `null` → `PrimerRegistro = "S"`.

  - Huella (`TipoHuella = "01"`, `Huella`) y `FechaHoraHusoGenRegistro`.

  - Bloque `SistemaInformatico` con todas las claves obligatorias.

- **Facturas simplificadas (F2) sin destinatario**

  - `TipoFactura = "F2"`.

  - Desglose y totales calculados desde `lines`.

  - Verificación explícita de que **no existe** bloque `Destinatarios` para F2 sin destinatario.

- **Facturas F3 con destinatario**

  - `TipoFactura = "F3"`.

  - Presencia de `Destinatarios/IDDestinatario` con `NombreRazon` y `NIF`.

  - Desglose y totales coherentes con las líneas.

- **Destinatario internacional (`IDOtro`)**

  - Construcción del bloque:

  ```xml
  <Destinatarios>
    <IDDestinatario>
      <NombreRazon>...</NombreRazon>
      <IDOtro>
        <CodigoPais>...</CodigoPais>
        <IDType>...</IDType>
        <ID>...</ID>
      </IDOtro>
    </IDDestinatario>
  </Destinatarios>
  ```

- Verificación de que **no** se envía `NIF` cuando se usa `IDOtro`.

- **Rectificativas R2 (sustitutiva)**

  - `TipoFactura = "R2"`.

  - `TipoRectificativa = "S"`.

  - Construcción de `FacturasRectificadas/IDFacturaRectificada`.

  - Cálculo y presencia de `ImporteRectificacion` (`BaseRectificada`, `CuotaRectificada`, `ImporteRectificacion`) cuando la rectificación es por **sustitución**.

- **Rectificativas R3 (por diferencias)**

  - `TipoFactura = "R3"`.

  - `TipoRectificativa = "I"`.

  - Bloque `FacturasRectificadas` informado.

  - Verificación explícita de que **no se genera** `ImporteRectificacion` en modo diferencias (`I`), siguiendo la regla AEAT.

- **Rectificativas R5 sobre simplificadas (F2)**

  - `TipoFactura = "R5"`.

  - Confirmación de que **no** se envía bloque `Destinatarios` (igual que en F2).

  - Bloque `FacturaRectificada` con emisor, serie/número y fecha de la factura simplificada original.

  - En modo sustitución (`rectify_mode = 'S'`) se genera `ImporteRectificacion` usando `detail`, `vat_total` y `gross_total`.

  - En modo diferencias (`rectify_mode = 'I'`) **no** se envía `ImporteRectificacion`.

- **Rectificativas con múltiples facturas originales**

  Cuando el payload incluye más de una entrada en `rectified_invoices[]`, el\
  builder:

  - Genera `FacturasRectificadas.IDFacturaRectificada[]` con **todas** las\
    facturas originales recibidas.

  - Usa únicamente la **primera** factura del array para el bloque\
    `FacturaRectificada` (bloque plano requerido por AEAT).

  - `ImporteRectificacion` (modo sustitución) se calcula desde las líneas de la\
    rectificativa actual, no como suma de las originales.

  Este comportamiento queda fijado en:

  `test_it_uses_first_rectified_invoice_when_multiple_are_provided()`

- **Anulaciones técnicas (`RegistroAnulacion`)**

  - Construcción del bloque `RegistroAnulacion` completo:

    - `IDFactura` anulada (`IDEmisorFacturaAnulada`, `NumSerieFacturaAnulada`, `FechaExpedicionFacturaAnulada`).

    - Encadenamiento:

      - Primer registro → `Encadenamiento/PrimerRegistro = "S"` cuando `prev_hash` es `null`.

      - Enlace encadenado → `Encadenamiento/RegistroAnterior` con `IDEmisorFactura`, `NumSerieFactura`, `FechaExpedicionFactura` y `Huella` cuando existe `prev_hash`.

    - `TipoHuella = "01"`, `Huella`, `FechaHoraHusoGenRegistro`.

    - Presencia del bloque `SistemaInformatico` con todas las claves obligatorias.

- **Caso avanzado de desglose con redondeos, descuentos y líneas no computables**

  El test:

  `test_build_breakdown_handles_discounts_rounding_and_zero_quantity_lines()`

  cubre:

  - múltiples tipos de IVA simultáneos,

  - descuentos porcentuales complejos,

  - precios con decimales no exactos (casos típicos de 33.333),

  - acumulación correcta por tramo,

  - redondeo a 2 decimales AEAT,

  - líneas con `qty = 0` que se ignoran en totales.

  Este test fija la estabilidad del algoritmo de totales del middleware.

### 19.3. Tests de DTO y validaciones de destinatario (`InvoiceDTO::fromArray()`)

El DTO de entrada `InvoiceDTO` es el núcleo de validación de payloads de alta.
Los tests en `tests/DTO/InvoiceDTOTest.php` cubren:

- **Mapeo básico y valores por defecto**

  - Normalización de `issuer.nif` → `issuerNif` siempre en mayúsculas.
  - Mapeo de campos principales: `series`, `number`, `issueDate`, `description`.
  - Defaults cuando no se informan:
    - `invoiceType` → `F1`
    - `taxRegimeCode` → `01`
    - `operationQualification` → `S1`
  - Las líneas (`lines`) se copian al DTO y conservan `desc`, `qty`, `price`, `vat`, etc.

- **Líneas (`lines`) obligatorias y válidas**

  - `lines` es obligatorio:
    - Falta el campo → `InvalidArgumentException` con mensaje `Missing field: lines`.
    - `lines` vacío → `InvalidArgumentException` con mensaje `lines[] is required and must be non-empty`.
  - Validación numérica por línea:
    - `qty > 0`
    - `price >= 0`
    - `vat >= 0`
  - Cualquier violación lanza `InvalidArgumentException` con mensaje:
    `Invalid line values: qty must be > 0, price must be >= 0, vat must be >= 0`.

- **Tipos de factura permitidos (`invoiceType`)**

  - Se aceptan únicamente: `F1`, `F2`, `F3`, `R1`, `R2`, `R3`, `R4`, `R5`.
  - Tipo desconocido (por ejemplo `ZZ`) → `InvalidArgumentException` con mensaje:
    `invoiceType must be one of: F1, F2, F3, R1, R2, R3, R4, R5`.

- **Reglas de destinatario por tipo de factura**

  - Para `F1`, `F3`, `R1`, `R4`:
    - El destinatario (`recipient`) es **obligatorio**.
    - Debe venir como:
      - `recipient.name` + `recipient.nif`, **o**
      - bloque completo `IDOtro` (`country`, `idType`, `idNumber`).
    - Si falta → `InvalidArgumentException` con mensaje del estilo:
      `For invoiceType F1 you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).`
  - Para `F2` y `R5`:
    - **No se permite destinatario** (igual que en el XML VERI\*FACTU).
    - Si se incluye `recipient` → `InvalidArgumentException` con mensaje:
      `For invoiceType F2/R5 the recipient block must be empty (AEAT: no Destinatarios).`

- **NIF vs IDOtro (destinatarios nacionales/internacionales)**

  - Si el destinatario es español (`country = 'ES'`):
    - Debe usarse siempre `recipient.nif`.
    - No se permite IDOtro → `InvalidArgumentException` con mensaje:
      `For Spanish recipients you must use recipient.nif (not IDOtro)`.
  - Si el destinatario es internacional (`country != 'ES'`):
    - Se puede usar `IDOtro`:
      - Campos requeridos: `country`, `idType`, `idNumber`.
      - `idType` debe estar en el catálogo AEAT: `02, 03, 04, 05, 06, 07`.
      - Un `idType` fuera de catálogo (`99`, etc.) lanza `InvalidArgumentException` con mensaje:
        `recipient.idType must be one of: 02, 03, 04, 05, 06, 07`.
  - Nunca se permite mezclar ambos modelos:
    - Si se envía `recipient.nif` **y además** `idType` + `idNumber` →
      `InvalidArgumentException` con mensaje:
      `recipient cannot have both nif and IDOtro at the same time.`

- **Bloque de rectificación (`rectify`) para R1–R5**

  - Para `invoiceType` en `R1`, `R2`, `R3`, `R4`, `R5`:

    - Es obligatorio informar el bloque `rectify` con los datos de la factura original:
      - `rectify.mode` ∈ `{substitution, difference}` → mapeado a `RectifyMode::SUBSTITUTION`/`DIFFERENCE`.
      - `rectify.original.series`
      - `rectify.original.number`
      - `rectify.original.issueDate`
    - Si falta el bloque `rectify` → `InvalidArgumentException` con mensaje:
      `Rectificative invoices (R1–R5) require a "rectify" block with original invoice data.`
    - Si `rectify.mode` es distinto de `substitution` o `difference` →
      `InvalidArgumentException` con mensaje:
      `rectify.mode must be "substitution" or "difference"`.

  - El DTO expone esta información como:
    - `invoiceType` (`R1`–`R5`)
    - `rectify` (objeto tipado con `mode`, `originalSeries`, `originalNumber`, `originalIssueDate`)
    - `isRectification()` → `true` para todos los `R*`.

- **Casos de error genérico de payload**
  - Si `fromArray()` se llama con algo que no sea `array`
    (por ejemplo `null`) → `TypeError` directamente de la firma de tipo.

Con estos tests se garantiza que **ninguna factura incorrecta** (por tipo, líneas o destinatario)
llega a la parte de hash/encadenamiento ni a la cola de envío a AEAT.

### 19.4. Tests de la cadena canónica

`php vendor/bin/phpunit --filter VerifactuCanonicalServiceTest`

Los tests de `VerifactuCanonicalService` comprueban:

- Formato exacto de la cadena canónica (`csv_text`) tanto para altas como para anulaciones.

- Inclusión correcta de `FechaHoraHusoGenRegistro` en la cadena.

- Generación de la huella SHA-256 en mayúsculas.

- Coherencia entre la cadena generada y los campos almacenados en `billing_hashes`\
  (`hash`, `prev_hash`, `datetime_offset`, etc.).

### Casos extremos cubiertos por `VerifactuCanonicalServiceTest`

Los tests del servicio `VerifactuCanonicalService` aseguran la correcta generación de:

- la **cadena canónica** AEAT (registro de alta y anulación),

- la **huella SHA-256** en mayúsculas,

- y el **encadenamiento** secuencial (`prev_hash` → `hash`).

Incluyen casos avanzados:

#### Cadena canónica exacta

Validación carácter a carácter de una cadena oficial completa para un alta F1, incluyendo:

- `NumSerieFactura`

- Fecha AEAT `dd-mm-YYYY`

- `CuotaTotal` y `ImporteTotal`

- `Huella` previa

- `FechaHoraHusoGenRegistro` fija

#### Huella SHA-256

La huella generada debe coincidir exactamente con:

`hash('sha256', $cadena_canónica) en mayúsculas`

Se comprueba que siempre es uppercase.

#### Importes grandes y decimales

Se validan totales con muchos decimales (simulando varios tipos de IVA), verificando que:

- `fmt2()` redondea correctamente,

- la cadena canónica usa esos valores exactos.

#### Encadenamiento (`prev_hash`)

- Primer registro → `Huella=` vacía.

- Siguientes → contiene **exactamente** el hash del eslabón anterior.

#### Cadenas largas (stress test)

Se genera un encadenamiento de **50 eslabones**, comprobando:

- unicidad de todos los hashes,

- secuencia perfecta del `prev_hash`,

- `NumSerieFactura` correcto en cada salto,

- estabilidad del timestamp cuando se fija.

---

## 19.5. Tests de VerifactuQrService (QR AEAT)

Los tests del servicio `VerifactuQrService` validan la generación del **QR oficial de la AEAT** utilizado tanto para el PDF como para validación externa.

### ✔ Comportamiento comprobado

- **Determinismo de la URL del QR**
  Para un registro (`billing_hash`) con los mismos valores de:
  `issuer_nif`, `series`, `number`, `issue_date`, `gross_total`,
  la **URL del QR generada siempre es exactamente la misma**.

- **Generación del archivo PNG**
  El servicio genera el archivo PNG en:

  ```
  writable/verifactu/qr/{id}.png
  ```

  y el fichero existe tras el endpoint `/api/v1/invoices/{id}/qr`.

- **Actualización de columnas en BD**
  Tras la generación:

  - `billing_hashes.qr_path`
  - `billing_hashes.qr_url`
    quedan actualizadas con la ruta absoluta y la URL al QR público.

- **Limpieza durante los tests**
  Los tests eliminan el fichero generado para dejar el entorno limpio.

### ✔ Ejemplo de test incluido (resumen)

```php
$result = $this
    ->withRoutes($this->apiRoutes)
    ->get("/api/v1/invoices/{$id}/qr");

$result->assertStatus(200);

$path = WRITEPATH . 'verifactu/qr/' . $id . '.png';
$this->assertFileExists($path);

// Limpieza del fichero generado durante el test.
unlink($path);
$this->assertFileDoesNotExist($path);
```

### 19.5. Tests de VerifactuPdfService (PDF oficial)

El PDF oficial de la factura se genera mediante `VerifactuPdfService` utilizando
`dompdf/dompdf` y la vista `pdfs/verifactu_invoice.php`. Para asegurar que el
pipeline funciona y que el contenido básico es correcto, se incluye un test de
feature:

- `Tests\Feature\InvoicesPdfTest::test_pdf_generates_file_and_updates_billing_hash`

Este test comprueba:

- Que el endpoint `GET /api/v1/invoices/{id}/pdf` devuelve **200 OK**.
- Que se genera un fichero PDF físico en `writable/verifactu/pdfs/{id}.pdf`.
- Que la ruta se persiste en `billing_hashes.pdf_path`.
- Que el PDF contiene texto coherente con la factura:

  - Nombre del emisor (`ACME S.L.`).
  - Nombre del destinatario (`Cliente Demo S.L.` en el escenario de test).
  - Descripción de la línea (`Servicio`).
  - Totales (`100,00 €`, `121,00 €` según el caso de prueba).

Para validar el contenido, el test usa la librería `spatie/pdf-to-text`, que
a su vez requiere el binario `pdftotext` instalado en el sistema (ver sección
**2.1. Dependencias del sistema para tests de PDF**).

Ejemplo simplificado del uso en el test:

```php
use Spatie\PdfToText\Pdf;

// ...

$pdfPath = $row['pdf_path'];
$text    = Pdf::getText($pdfPath, '/opt/homebrew/bin/pdftotext'); // ruta configurable

$this->assertStringContainsString('ACME S.L.', $text);
$this->assertStringContainsString('Cliente Demo S.L.', $text);
$this->assertStringContainsString('Servicio', $text);
$this->assertStringContainsString('121,00 €', $text);
```

> Estos tests están pensados como **smoke tests de contenido**: no validan el\
> diseño pixel-perfect ni el layout gráfico, solo que el PDF se genera sin >errores\
> y contiene los datos clave (emisor, cliente, líneas y totales).

### 19.7. Caminos críticos cubiertos por tests

| Camino crítico                                                | Servicio / Componente                | Cobertura actual                                                                                                                                           | Pendiente / Futuro                                                                                                 |
| ------------------------------------------------------------- | ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Construcción de la **cadena canónica** + huella               | `VerifactuCanonicalService`          | ✅ `VerifactuCanonicalServiceTest`                                                                                                                         | Casos límite (importes con muchos decimales, cadenas largas, escenarios con muchos eslabones, etc.)                |
| Cálculo de **desglose y totales** desde `lines`               | `VerifactuAeatPayloadBuilder`        | ✅ `testBuildAltaHappyPath`, `testBuildAltaF2WithoutRecipient`, `testBuildAltaF3WithRecipient`                                                             | Añadir casos con varios tipos de IVA a la vez, descuentos por línea, bases a 0, etc.                               |
| Construcción de `RegistroAlta` (F1/F2/F3/R2/R3/R5)            | `VerifactuAeatPayloadBuilder`        | ✅ Altas F1/F2/F3, rectificativas R2/R3/R5 (sustitución y diferencias)                                                                                     | Ampliar con más escenarios reales (varias facturas rectificadas, múltiples tramos de IVA, etc.).                   |
| Construcción de `RegistroAnulacion`                           | `VerifactuAeatPayloadBuilder`        | ✅ `testBuildCancellationAsFirstInChain`, `testBuildCancellationChained`                                                                                   | Tests de integración sobre el comando `verifactu:process` para cubrir también la decisión de modo AEAT.            |
| Destinatarios nacionales e internacionales (NIF / IDOtro)     | `VerifactuAeatPayloadBuilder` + DTO  | ✅ F3 con destinatario (NIF), F1 con `IDOtro`, validación DTO `NIF` vs `IDOtro`                                                                            | Añadir más casos de `IDType` (02–07) y combinaciones país/tipo para documentación y regresiones.                   |
| Generación de **QR AEAT**                                     | `VerifactuQrService`                 | ⏳ Pendiente de test unitario específico                                                                                                                   | Testear generación determinista de la URL QR y la ruta de fichero en disco.                                        |
| Generación de **PDF oficial**                                 | `VerifactuPdfService` + vista `pdfs` | ⏳ Pendiente (validado manualmente)                                                                                                                        | Testear que el HTML base se renderiza y el fichero PDF se genera sin errores.                                      |
| Flujo de **worker / cola** (`ready` → envío → AEAT`)          | `VerifactuService` + comando spark   | ⏳ Pendiente de tests de integración                                                                                                                       | Tests funcionales con respuestas SOAP simuladas (Correcto / Incorrecto / errores) y reintentos.                    |
| Actualización de **estados AEAT** en BD                       | `VerifactuService` + `Submissions`   | ⏳ Pendiente de test unitario / integración                                                                                                                | Verificar el mapping correcto a `aeat_*` y `status` internos en diferentes escenarios AEAT.                        |
| Endpoints REST (`preview`, `cancel`, `verifactu`, `pdf`, ...) | `InvoicesController`                 | ✅ Tests feature para `POST /api/v1/invoices/preview` e `GET /api/v1/invoices/{id}/verifactu` (status, esquema básico, idempotencia y contexto de empresa) | Añadir tests feature para `cancel`, `pdf`, `qr` y flujos de error más complejos (timeouts AEAT, reintentos, etc.). |
| Validaciones de destino: NIF, IDOtro, reglas F2/F5, R\*       | `InvoiceDTO`                         | ✅ Tests del DTO: líneas, tipos, reglas de destinatario español/no español, rectificativas, F2 y R5 sin destinatario                                       | Añadir más combinaciones y casos límite de validación.                                                             |
| Lógica de creación de anulaciones y modo de anulación         | `VerifactuService`                   | ✅ Tests unitarios en `VerifactuServiceTest` (`createCancellation`, `determineCancellationMode`, `scheduleRetry`)                                          | Ampliar con tests de integración completos sobre el comando `verifactu:process` (envío real y reintentos).         |

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
  qr/{id}.png
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

## 22\. Versionado del middleware

El middleware VERI\*FACTU se versiona siguiendo el esquema **SemVer**:

`MAJOR.MINOR.PATCH` → `1.0.3`, `1.1.0`, `2.0.0`, etc.

- **MAJOR** (`2.0.0`): cambios incompatibles en la API pública
  (se rompen contratos de endpoints o payloads, campos obligatorios que cambian, etc.).

- **MINOR** (`1.1.0`): nuevas funcionalidades **compatibles hacia atrás**
  (nuevos endpoints, nuevos campos opcionales en las respuestas, mejoras internas).

- **PATCH** (`1.0.4`): correcciones de bugs o ajustes internos
  sin cambios en el contrato público de la API.

### 22\.1. Dónde se declara la versión

La versión actual del middleware se declara en la configuración:

```php
// Config/Verifactu.php
final class Verifactu extends BaseConfig
{
    /**
     * Versión del middleware VERI*FACTU (SemVer).
     */
    public string $middlewareVersion = '1.0.0';
}
```

De esta forma cada despliegue puede saber con claridad qué versión del middleware\
está ejecutando, independientemente de la versión del **Sistema Informático de Facturación**\
(`verifactu.systemVersion`), que puede ser distinta.

### 22.2. Tags y despliegues

Se recomienda:

- Crear un **tag Git** por versión estable del middleware, con el formato `vX.Y.Z`.

- Desplegar en producción siempre a partir de una versión etiquetada:

  - Ejemplo: `git checkout v1.0.3` + `composer install` + `php spark migrate`.

- Registrar los cambios en un `CHANGELOG.md` (resumen por versión):

  - Nuevos endpoints / campos.

  - Cambios en el comportamiento de la cola.

  - Ajustes en la lógica de hash / encadenamiento / anulación.

### 22.3. Exponer la versión (opcional)

Opcionalmente, la versión del middleware puede exponerse a integradores o a herramientas de monitorización:

- Añadiendo un campo `middlewareVersion` en la respuesta de `GET /api/v1/health`.

- O añadiendo un comando CLI específico (ejemplo):

  `php spark verifactu:version`

  que imprima el valor de `config('Verifactu')->middlewareVersion`.

Estas opciones son puramente informativas y no forman parte del contrato funcional de la API.

## 23\. Endpoint `/api/v1/invoices/{id}` (show)

**GET** `/api/v1/invoices/{id}`

Devuelve la representación interna de un registro de facturación (`billing_hashes`)\
para la empresa actual (resuelta vía API key / JWT).

### 23.1. Comportamiento

- Requiere autenticación (`X-API-Key` / `Bearer`).

- Usa el `RequestContext` para obtener la empresa actual (`company_id`, `issuer_nif`).

- Busca el registro en `billing_hashes` por:

  - `id = {id}`

  - `company_id = company.id` (empresa del contexto)

- Si no se encuentra:

  - Devuelve `404` con código interno `VF404` y `detail = "document not found"`.

- Si pertenece a otra empresa:

  - También devuelve `404 VF404` (aislamiento multiempresa).

### 23.2. Respuesta de ejemplo

```json
{
  "data": {
    "document_id": 123,
    "kind": "alta",
    "status": "accepted",
    "issuer_nif": "B61206934",
    "series": "F2025",
    "number": 73,
    "issue_date": "2025-11-20",
    "vat_total": 21.0,
    "gross_total": 121.0,
    "hash": "D86BEFBDACF9E8FC...",
    "prev_hash": null,
    "qr_url": "https://.../verifactu/qr/123.png",
    "xml_path": "verifactu/xml/123.xml",
    "pdf_path": "verifactu/pdfs/123.pdf",
    "aeat_send_status": "Correcto",
    "aeat_register_status": "Correcto"
  },
  "meta": {
    "request_id": "...",
    "ts": 1731840000
  }
}
```

> **Nota:** este endpoint está pensado para consumo interno o para integradores\
> que necesiten una vista "low-level" del registro (`billing_hashes`) sin cargar\
> todos los `submissions` ni artefactos de auditoría detallados.\
> Para auditoría completa, usar `/invoices/{id}/verifactu`.

---

## 24\. Endpoint `/api/v1/health`

**GET** `/api/v1/health`

Endpoint de healthcheck orientado a integradores/monitorización.\
Permite verificar:

- Que la API está viva.

- Que la API key / token resuelve correctamente a una empresa (`company`).

### 24.1. Comportamiento

- Requiere autenticación (igual que el resto de endpoints bajo `/api/v1`).

- Usa `RequestContext` para obtener la empresa asociada a la API key.

- Devuelve siempre `200 OK` si:

  - La API key es válida.

  - La empresa existe y está activa en el contexto.

- El body incluye:

  - `status` → `"ok"`

  - `company` → datos básicos de la empresa (`id`, `slug`, `name`, `issuer_nif`, flags...).

### 24.2. Respuesta de ejemplo

```json
{
  "data": {
    "status": "ok",
    "company": {
      "id": 1,
      "slug": "acme",
      "name": "ACME S.L.",
      "issuer_nif": "B61206934",
      "verifactu_enabled": 1,
      "send_to_aeat": 0
    }
  },
  "meta": {
    "ts": 1731840000
  }
}
```

Uso típico:

- Probes de Kubernetes / Docker / monitorización (liveness/readiness).

- Chequear rápidamente que:

  - la API responde,

  - el contexto de empresa es el esperado para una API key concreta.

## 25. Ejemplos de flujos reales

### 25.1. Alta → envío → anulación

Este es el flujo más habitual:

1. **Alta** (F1/F2/F3/R\*):

   - El integrador llama a:

     `POST /api/v1/invoices/preview`

     con un payload `InvoiceInput` válido (p.ej. F1):

     ```json
     {
       "invoiceType": "F1",
       "externalId": "ERP-2025-000123",
       "issuer": { "...": "..." },
       "recipient": { "...": "..." },
       "series": "F2025",
       "number": 73,
       "issueDate": "2025-11-20",
       "description": "Servicio de transporte",
       "lines": [
         {
           "desc": "Traslado aeropuerto-hotel",
           "qty": 1,
           "price": 50.0,
           "vat": 21
         }
       ]
     }
     ```

   - El middleware:
     - Valida el payload vía `InvoiceDTO::fromArray()`.
     - Normaliza y guarda los datos en `billing_hashes`:
       - `kind = "alta"`, `status = "ready"`.
       - `csv_text`, `hash`, `prev_hash`, `chain_index`, `datetime_offset`.
       - Totales (`vat_total`, `gross_total`) y payload original (`raw_payload_json`).
     - Devuelve `201 Created` con `document_id`, `status`, `hash`, etc.

2. **Envío a AEAT (cola)**:

   - Un cron ejecuta periódicamente:

     ```bash
     php spark verifactu:process
     ```

   - El comando:
     - Selecciona registros con `status IN ('ready','error')` y `next_attempt_at <= NOW()`.
     - Construye el XML oficial (`RegistroAlta`) con `VerifactuAeatPayloadBuilder`.
     - Firma y envía via SOAP (`VerifactuSoapClient::sendInvoice()`).
     - Interpreta la respuesta AEAT con `parseAeatResponse()`:
       - `EstadoEnvio` (`send_status`).
       - `EstadoRegistro` (`register_status`).
       - `CSV`, `CodigoErrorRegistro`, `DescripcionErrorRegistro`.
     - Actualiza:
       - `billing_hashes.status` → `accepted`, `accepted_with_errors` o `error`/`rejected`.
       - Campos `aeat_csv`, `aeat_send_status`, `aeat_register_status`, `aeat_error_code`, `aeat_error_message`.
     - Inserta una fila en `submissions` con el histórico del envío (`type = "register"`).

3. **Anulación técnica**:

   - Si es necesario anular la factura, el integrador llama a:

     `POST /api/v1/invoices/{id}/cancel`

     por ejemplo:

     ```http
     POST /api/v1/invoices/123/cancel
     X-API-Key: ...
     Content-Type: application/json

     { "reason": "Factura emitida por error" }
     ```

   - El middleware:

     - Busca el `billing_hash` original (`kind = "alta"`) para esa empresa.
     - Determina automáticamente el `cancellation_mode` (`NO_AEAT_RECORD`, `AEAT_REGISTERED`, `PREVIOUS_CANCELLATION_REJECTED`) en función de las filas de `submissions`.
     - Crea un nuevo `billing_hash`:
       - `kind = "anulacion"`.
       - `original_billing_hash_id = {id original}`.
       - Misma `series` y `number` que la factura original.
       - `vat_total = 0.0`, `gross_total = 0.0`.
       - Cadena canónica de anulación (`csv_text`), huella (`hash`) y encadenamiento (`prev_hash` y `chain_index`) ya calculados.
       - `status = "ready"`, `next_attempt_at = NOW()`.

   - De nuevo, `php spark verifactu:process` enviará el `RegistroAnulacion` a AEAT y actualizará los campos AEAT + `submissions` igual que en el alta.

---

### 25.2. Alta duplicada

En la práctica hay **dos niveles**:

#### 1) Idempotencia a nivel de API

Si el cliente repite un `POST /preview` con la **misma `Idempotency-Key`**:

```http
POST /api/v1/invoices/preview
X-API-Key: ...
Idempotency-Key: 2b5d2a20-...

{ ... mismo body JSON ... }
```

- El middleware busca en `billing_hashes` por:

  - `company_id` (derivado de la API key),

  - `idempotency_key`.

- Si encuentra un registro existente:

  - Responde con **409 Conflict**.

  - Devuelve el mismo `document_id`, `status`, `hash`, `prev_hash`, `qr_url`, `xml_path`...

  - Marca `meta.idempotent = true`.

De esta forma, el cliente puede repetir llamadas (por timeout, etc.) sin crear registros duplicados, y **sin siquiera llegar a AEAT**.

#### 2) Error AEAT por alta ya registrada

Si, pese a todo, AEAT responde que la factura ya está registrada (o devuelve otro error de negocio):

- El parser `parseAeatResponse()` extrae:

  - `EstadoEnvio` (`send_status`),

  - `EstadoRegistro` (`register_status`),

  - `CodigoErrorRegistro` (`aeat_error_code`),

  - `DescripcionErrorRegistro` (`aeat_error_message`),

  - `CSV` (si lo hay).

- El middleware:

  - Marca el `billing_hash` con:

    - `status = "error"` o `status = "accepted_with_errors"` según la combinación `send_status` / `register_status`.

    - Rellena los campos `aeat_*`.

  - Inserta una fila en `submissions` con el detalle del intento (y el error devuelto por AEAT).

- El integrador puede ver el detalle en:

  - `GET /api/v1/invoices/{id}/verifactu`

  - Panel `/admin/verifactu` (detalle de registro + histórico de `submissions`).

**Importante**: los errores de negocio AEAT (incluido "alta duplicada") **no** activan `scheduleRetry()`; se consideran **no retryable** y quedan reflejados en BD para revisión.

---

### 25.3. Rectificativas R2 / R3 / R5

Las rectificativas se modelan como cualquier alta, pero:

- `invoiceType` ∈ `{ "R1", "R2", "R3", "R4", "R5" }`.

- Se añade bloque obligatorio `rectify`:

`{
  "invoiceType": "R2",
  "issuer": { "...": "..." },
  "series": "R2025",
  "number": 5,
  "issueDate": "2025-11-19",
  "lines": [
    { "desc": "Rectificación servicio", "qty": 1, "price": 80.0, "vat": 21 }
  ],
  "recipient": {
    "name": "Cliente Demo S.L.",
    "nif": "B12345678",
    "country": "ES"
  },
  "rectify": {
    "mode": "substitution",      // o "difference"
    "original": {
      "series": "F2025",
      "number": 62,
      "issueDate": "2025-11-10"
    }
  }
}`

#### Códigos de tipo de factura (R\*)

- `R1` → rectificativa por error fundado en derecho (art. 80 Uno, Dos y Seis LIVA).

- `R2` → rectificativa por concurso de acreedores (art. 80 Tres LIVA).

- `R3` → rectificativa por créditos incobrables (art. 80 Cuatro LIVA).

- `R4` → resto de rectificativas.

- `R5` → rectificativa de facturas simplificadas (tickets).

#### Códigos de tipo de rectificativa

El campo `rectify.mode` se mapea internamente a:

- `"substitution"` → `TipoRectificativa = "S"` (sustitución):

  - El builder incluye el bloque `ImporteRectificacion` en el XML.

- `"difference"` → `TipoRectificativa = "I"` (por diferencias):

  - **No** se incluye `ImporteRectificacion` (regla AEAT).

Esto está cubierto por tests en `VerifactuAeatPayloadBuilderTest`:

- Rectificativas R2 (sustitutiva) → se comprueba la presencia de `ImporteRectificacion`.

- Rectificativas R3 (diferencias) → se comprueba explícitamente que **no** se genera `ImporteRectificacion`.

- Rectificativas R5 sobre simplificadas (F2) → se valida que no hay bloque `Destinatarios` y que el comportamiento con `TipoRectificativa` sigue estas mismas reglas.

El flujo completo R\*/R5 es:

1.  `POST /api/v1/invoices/preview` con `invoiceType = "R2" | "R3" | "R5"` + `rectify`.

2.  El registro se guarda como `kind = "alta"` con información de rectificación:

    - `rectified_billing_hash_id`,

    - `rectified_meta_json`.

3.  `php spark verifactu:process` construye `RegistroAlta` con:

    - `TipoFactura = "R2"/"R3"/"R5"`,

    - bloque `FacturasRectificadas`,

    - `TipoRectificativa = "S"` o `"I"` según `rectify.mode`.

4.  Se interpreta la respuesta AEAT y se actualizan `billing_hashes` + `submissions` como en cualquier alta.

**Autor:** Javier Delgado Berzal --- PTG (2025)
