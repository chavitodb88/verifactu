# Guía de integración VERI\*FACTU -- Middleware API

Esta guía está dirigida a **equipos técnicos de ERPs, CRMs, TPVs, plataformas SaaS** o cualquier sistema que necesite **emitir facturas VERI\*FACTU** a través de nuestra **VERI\*FACTU Middleware API**.

La idea es simple:

> Tú generas los datos de la factura →\
> los envías por API →\
> nosotros nos encargamos de **hash, encadenamiento, XML, SOAP, AEAT, QR y PDF**.

---

## 1\. Qué es este middleware y qué hace por ti

Nuestro middleware actúa como un **Sistema Informático de Facturación (SIF) VERI\*FACTU** y como **colaborador social** ante AEAT.

Tú solo necesitas:

- Llamar a nuestra API REST con los datos de facturación.

- Identificarte con tu **API key**.

- Guardar el `document_id` que devolvemos.

Nosotros nos encargamos de:

- Generar el **registro técnico** VERI\*FACTU:

  - Cadena canónica.

  - Huella SHA-256.

  - Encadenamiento de facturas (hash encadenado).

  - XML oficial `RegFactuSistemaFacturacion` (alta y anulación).

- Enviar las facturas a AEAT por **SOAP WSSE** con el certificado del SIF.

- Gestionar:

  - **Reintentos técnicos**, cola y backoff.

  - Copia de todos los **XML request/response**.

  - Generación de **QR AEAT** y **PDF oficial** de la factura.

- Exponer endpoints para:

  - Consultar el estado de la factura.

  - Descargar PDF/QR.

  - Anular facturas (RegistroAnulacion).

  - Ver detalle completo VERI\*FACTU.

---

## 2\. Conceptos clave

- **Empresa**: tu cliente final / obligado a emitir las facturas. En nuestra API se representa como `company` y se resuelve a partir de tu **API key**.

- **Emisor (`issuer`)**: datos que AEAT verá como emisor de la factura (NIF, nombre, dirección...).

- **Registro de facturación**:

  - **Alta** → factura emitida (F1, F2, F3, R1--R5).

  - **Anulación** → registro técnico que anula un alta previo.

- **VERI\*FACTU**:

  - Se apoya en **registros técnicos encadenados** (hash de cada factura enlazado con el anterior).

  - Cada alta / anulación genera un nuevo eslabón en la cadena.

---

## 3\. Tipos de factura soportados

Actualmente soportamos **todos los tipos principales que exige VERI\*FACTU**:

- **F1** → Facturas completas / ordinarias (con destinatario).

- **F2** → Facturas simplificadas (tickets).

- **F3** → Facturas completas especiales (tipo F3).

- **R1** → Rectificativa por error fundado en derecho.

- **R2** → Rectificativa por concurso de acreedores.

- **R3** → Rectificativa por créditos incobrables.

- **R4** → Resto de rectificativas.

- **R5** → Rectificativas de facturas simplificadas (tickets).

Y además:

- **Anulaciones técnicas** (RegistroAnulacion) sobre registros de facturación ya creados.

---

## 4\. Requisitos para integrarse

### 4.1. A nivel técnico

- Integrarte vía **HTTP/JSON** con nuestra API REST.

- Soportar:

  - Autenticación por **API key**.

  - Códigos HTTP estándar (`201`, `200`, `4xx`, `5xx`).

  - Envío de JSON según los esquemas que se describen más abajo.

### 4.2. A nivel de negocio

Necesitamos acordar contigo:

- Qué **empresa(s)** emitirá(n) facturas a través de nuestra API.

- Qué **NIF emisor** corresponde a cada empresa.

- Qué **entorno** usarás:

  - **Pruebas / PRE-AEAT** (recomendado al principio).

  - **Producción** (cuando todo esté validado).

Te entregaremos:

- Una **API key** por empresa o entorno (según el modelo que definamos).

- La **URL base** de la API (test / prod).

---

## 5\. Autenticación y multiempresa

La API se protege mediante:

- `X-API-Key: {apiKey}`\
  (opcionalmente podemos habilitar también `Authorization: Bearer {token}`).

Cada API key está asociada a:

- Una **empresa** en nuestro sistema (`company`).

- Un **NIF emisor**.

En cada petición:

1.  Validamos la API key.

2.  Recuperamos la empresa asociada.

3.  Forzamos que el `issuer.nif` del payload coincida con el `issuer_nif` configurado para esa empresa.\
    Si no coincide → devolvemos **422** y la factura **no entra** en el flujo VERI\*FACTU.

---

## 6\. Flujo básico de integración

El flujo típico consta de 3 pasos:

1.  **Alta del registro de facturación**\
    `POST /api/v1/invoices/preview`

2.  (Opcional para ti) **Envío a AEAT por cola**\
    `php spark verifactu:process` → lo gestionamos nosotros; tú no llamas a esto.

3.  **Consultas y artefactos**:

    - `GET /api/v1/invoices/{id}`

    - `GET /api/v1/invoices/{id}/verifactu`

    - `GET /api/v1/invoices/{id}/pdf`

    - `GET /api/v1/invoices/{id}/qr`

    - `POST /api/v1/invoices/{id}/cancel` (anulaciones)

---

## 7\. Alta de factura: `POST /api/v1/invoices/preview`

Este endpoint crea un **registro técnico de alta** y lo deja listo para enviar a AEAT.

### 7.1. URL y método

`POST /api/v1/invoices/preview
X-API-Key: {apiKey}
Idempotency-Key: {uuid-opcional}
Content-Type: application/json`

### 7.2. Body de ejemplo (F1 -- factura completa)

`{
"invoiceType": "F1",
"externalId": "ERP-2025-000123",

"issuer": {
"nif": "B61206934",
"name": "ACME S.L.",
"address": "Calle Mayor 1",
"postalCode": "29001",
"city": "Málaga",
"province": "Málaga",
"country": "ES"
},

"recipient": {
"name": "Cliente Demo S.L.",
"nif": "B12345678",
"country": "ES",
"address": "Avenida Principal 5",
"postalCode": "28001",
"city": "Madrid",
"province": "Madrid"
},

"issueDate": "2025-11-20",
"series": "F2025",
"number": 73,
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
}`

### 7.3. Respuesta de ejemplo

`{
  "data": {
    "document_id": 123,
    "kind": "alta",
    "status": "ready",
    "hash": "D86BEFBDACF9E8FC...",
    "prev_hash": null
  },
  "meta": {
    "request_id": "149f5d1f-...",
    "idempotent": false,
    "ts": 1731840000
  }
}`

Guarda **`document_id`** en tu ERP, ya que lo usarás para:

- Consultar la factura.

- Descargar PDF/QR.

- Anularla si es necesario.

---

## 8\. Idempotencia (evitar altas duplicadas)

Puedes enviar un encabezado opcional:

`Idempotency-Key: 2b5d2a20-30b9-4a1e-9d5c-...`

Si repites la misma petición con el mismo `Idempotency-Key`:

- No se creará un nuevo registro.

- Devolveremos **409 Conflict** con el mismo `document_id` y `meta.idempotent = true`.

Esto te permite:

- Repetir llamadas (por timeout, errores de red, etc.) sin miedo a duplicar facturas.

---

## 9\. Consultar la factura: `GET /api/v1/invoices/{id}`

Endpoint "simple" para recuperar el estado y datos básicos de una factura.

`GET /api/v1/invoices/123
X-API-Key: {apiKey}`

Respuesta típica:

`{
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
}`

---

## 10\. Vista VERI\*FACTU completa: `GET /invoices/{id}/verifactu`

Si necesitas una **vista completa técnica** (para auditoría o soporte), usa:

`GET /api/v1/invoices/{id}/verifactu
X-API-Key: {apiKey}`

Devuelve:

- Cadena canónica y hash.

- Estado interno (`status`).

- Estados AEAT (`aeat_send_status`, `aeat_register_status`, errores...).

- Histórico de envíos (`submissions`), incluyendo:

  - Paths de los XML enviados y recibidos.

  - CSV AEAT.

  - Motivos de error, si los hay.

---

## 11\. Descargar el PDF oficial: `GET /invoices/{id}/pdf`

`GET /api/v1/invoices/{id}/pdf
X-API-Key: {apiKey}`

- Genera (o regenera) el PDF oficial de la factura.

- Devuelve un `application/pdf` descargable.

- También persiste la ruta del PDF para reutilizarlo.

Uso típico en tu ERP:

- Botón "Descargar factura PDF" que llama a este endpoint y lo muestra/descarga al usuario.

---

## 12\. Descargar o mostrar el QR AEAT: `GET /invoices/{id}/qr`

`GET /api/v1/invoices/{id}/qr
X-API-Key: {apiKey}`

- Devuelve una imagen `image/png` con el QR tributario.

- La URL del QR también se expone en el show/verifactu.

Uso habitual:

- Mostrar el QR en tu interfaz, en tus propios PDFs o en tickets.

---

## 13\. Anulación técnica: `POST /invoices/{id}/cancel`

Si necesitas **anular una factura** (registro técnico), usa:

`POST /api/v1/invoices/{id}/cancel
X-API-Key: {apiKey}
Content-Type: application/json

{
"reason": "Factura emitida por error"
}`

Respuesta:

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

Características:

- **No se modifica ni borra** la factura original.

- Se crea un **nuevo registro de anulación** encadenado.

- El middleware decide automáticamente el modo de anulación AEAT:

  - Sin registro previo.

  - Con alta previa aceptada.

  - Con anulación previa rechazada.

- Totales técnicos de la anulación:

  - `vat_total = 0.0`

  - `gross_total = 0.0`

---

## 14\. Tipos de destinatario: NIF vs IDOtro (internacionales)

Reglas principales:

- Si el destinatario es **español (`country = ES`)**:

  - Debes usar siempre `recipient.nif`.

  - No se permite IDOtro.

- Si el destinatario es **internacional (`country != ES`)**:

  - Puedes usar el bloque `IDOtro`:

    `"recipient": {
  "name": "John Smith",
  "country": "GB",
  "idType": "02",
  "idNumber": "AB1234567"
}`

  - `idType` ∈ `{ "02", "03", "04", "05", "06", "07" }` (catálogo AEAT).

  - No se puede mezclar `nif` + `IDOtro` en el mismo destinatario.

- Para **F2 y R5**:

  - No se admite destinatario → no envíes el bloque `recipient`.

Si estas reglas no se cumplen, la API devolverá **422 Unprocessable Entity** y **no** creará el registro VERI\*FACTU.

---

## 15\. Rectificativas (R1--R5): resumen para integradores

Cuando envías una rectificativa (`invoiceType` ∈ `R1--R5`):

- Debes incluir un bloque `rectify` con la factura original:

`"rectify": {
  "mode": "substitution", // o "difference"
  "original": {
    "series": "F2025",
    "number": 62,
    "issueDate": "2025-11-10"
  }
}`

- `mode = "substitution"`:

  - Se genera una rectificativa por sustitución.

  - AEAT exige informar `ImporteRectificacion` → el middleware lo genera.

- `mode = "difference"`:

  - Se genera una rectificativa por diferencias.

  - AEAT prohíbe `ImporteRectificacion` → el middleware **no lo envía**.

Si hay varias facturas originales, podemos recibir un array `rectified_invoices[]`, y el middleware:

- Envía todas en `FacturasRectificadas`.

- Usa la primera como referencia principal.

---

## 16\. Errores y códigos de estado

### 16.1. Errores de validación (antes de crear el registro)

- **422 Unprocessable Entity**

  - Formato de payload incorrecto.

  - Faltan campos obligatorios.

  - Líneas con cantidades o precios inválidos.

  - Reglas VERI\*FACTU incumplidas (ej. destinatario no permitido en F2).

En estos casos **no se crea** la factura en el sistema.

### 16.2. Errores de negocio AEAT (alta duplicada, etc.)

- El registro se crea.

- Se intenta enviar a AEAT.

- AEAT devuelve error de negocio (alta ya registrada, estructura inválida, etc.).

- Nosotros:

  - Marcamos el registro como `error` o `accepted_with_errors`.

  - Guardamos `aeat_error_code` y `aeat_error_message`.

  - NO reintentamos automáticamente (al ser un error de negocio).

Puedes consultar el detalle con:

- `GET /invoices/{id}/verifactu`

- Panel web (si te lo habilitamos).

### 16.3. Errores técnicos (red, SOAP, timeouts)

- Creamos o mantenemos la factura.

- Si el envío falla por causa técnica:

  - Guardamos un `submission` con el error técnico.

  - Marcamos el registro como `error`.

  - Programamos un **reintento a +15 minutos**.

Tú no necesitas reintentar nada desde tu ERP; lo gestiona la cola interna.

---

## 17\. Buenas prácticas de integración

1.  **Usa `externalId`**\
    Envía siempre un identificador propio (`externalId`) para mapear nuestra respuesta con tu sistema.

2.  **Guarda `document_id`**\
    Es tu referencia principal para futuras consultas / anulaciones.

3.  **Idempotencia en el cliente**\
    Usa `Idempotency-Key` cuando repitas peticiones por timeout o dudas.

4.  **No intentes hablar con AEAT directamente desde tu ERP**\
    Toda la complejidad (WSSE, certificados, XSD, hash, encadenamiento) ya está encapsulada en este middleware.

5.  **Empieza siempre en entorno de pruebas**\
    Antes de pasar a producción, validamos juntos varios escenarios:

    - Alta F1/F2/F3.

    - Rectificativa R2 o R3.

    - Anulación.

    - Algún caso de error controlado.

---

## 18\. Healthcheck: `GET /api/v1/health`

Para comprobar que:

- La API responde.

- La API key es válida.

- La empresa asociada está activa.

`GET /api/v1/health
X-API-Key: {apiKey}`

Respuesta:

`{
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
}`

---

## 19\. Resumen para el integrador

Si eres un integrador, tu "checklist mental" es:

- Tengo la **URL base** (test/prod) y la **API key**.

- Uso `POST /api/v1/invoices/preview` para crear altas (F1--F3, R1--R5).

- Guardo `document_id` y opcionalmente `externalId`.

- Uso:

  - `GET /api/v1/invoices/{id}` para estado básico.

  - `GET /api/v1/invoices/{id}/pdf` para obtener el PDF.

  - `GET /api/v1/invoices/{id}/qr` para el QR.

  - `POST /api/v1/invoices/{id}/cancel` si necesito anular.

- No me preocupo por:

  - SOAP, WSSE, certificados, XSD, hash, encadenamiento.

  - Reintentos, colas, logs técnicos → todo eso lo gestiona el middleware.
