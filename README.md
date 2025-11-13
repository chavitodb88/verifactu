# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con VERI\*FACTU (AEAT). Proyecto diseñado para ser reutilizable entre empresas, con tipado estricto y compatible con PHP **7.4 → 8.3**.

---

## 1) Objetivos

- Proveer una **API REST multiempresa** que reciba datos de facturación numerados desde sistemas externos.
- Generar y almacenar los artefactos técnicos exigidos por VERI\*FACTU: **hash, encadenamiento, QR, CSV, XML de previsualización y XML oficial**.
- Permitir el **envío a la AEAT** mediante WS-SOAP firmado por un **colaborador social autorizado**.
- Asegurar **idempotencia** por petición para evitar duplicados.
- Garantizar **trazabilidad completa** (logs, auditoría y almacenamiento seguro de XML/PDF).
- Gestionar un **certificado único** con autorización de múltiples emisores (NIF) por empresa.

---

## 2) Requisitos técnicos

- PHP **7.4+** (compatible hasta **8.3**)
- Composer
- MySQL **5.7+ / 8.x**
- CodeIgniter **4.3.x**
- Librería `zircote/swagger-php` para documentación OpenAPI.
- Opcional: `endroid/qr-code` (para QR real), `ext-soap` (para envío AEAT).

> Código con **tipado estricto**, PHPDoc detallado y compatibilidad ascendente (sin sintaxis exclusiva de PHP 8).

---

## 3) Instalación y configuración

Instalar dependencias:

```bash
composer install
```

Configurar `.env`:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = 127.0.0.1
database.default.database = verifactu
database.default.username = root
database.default.password = secret
database.default.DBDriver  = MySQLi
database.default.charset   = utf8mb4
```

---

## 4) Migraciones y Seeders

**Tablas principales:**

- `companies` — gestión multiempresa y flags (`verifactu_enabled`, `send_to_aeat`, etc.).
- `authorized_issuers` — emisores NIF autorizados por empresa.
- `api_keys` — autenticación por API key.
- `billing_hashes` — registros locales de facturas (hash, encadenamiento, QR, XML, etc.).
- `submissions` — trazabilidad de envíos o anulaciones hacia AEAT.

**Seeders iniciales:**

```bash
php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder
```

---

## 5) Autenticación (API Key)

Filtro `ApiKeyAuthFilter`:

- Cabecera `X-API-Key` o `Authorization: Bearer <token>`.
- Asocia `company_id` e inyecta datos de empresa en la request.
- Protege todas las rutas bajo `api/v1`.

---

## 6) Documentación OpenAPI

Generada con `swagger-php`. Script en `composer.json`:

```json
"openapi:build": "php ./vendor/bin/openapi --bootstrap vendor/autoload.php --format json --output public/openapi.json app/Controllers app/DTO"
```

Visualización:

- `public/openapi.json` (JSON)
- `public/swagger/` (Swagger UI)

---

## 7) Servidor local

```bash
php spark serve
```

Healthcheck:

```bash
curl -H "X-API-Key: dev_acme_key_000..." http://localhost:8080/api/v1/health
```

---

## 8) Estructura del proyecto

```
app/
  Controllers/
    Api/V1/HealthController.php
    Api/V1/InvoicesController.php
  DTO/InvoiceDTO.php
  Filters/ApiKeyAuthFilter.php
  Services/
    VerifactuCanonicalService.php
    VerifactuXmlBuilder.php
    VerifactuService.php
  Database/Migrations/
  Database/Seeds/
```

---

## 9) Estándares de código

- `declare(strict_types=1);` en todos los archivos.
- Tipos estrictos en parámetros y retornos.
- PSR-12 y PHPDoc con tipos detallados.
- Naming en inglés consistente.

---

## 10) Comandos útiles

```bash
php spark serve                   # servidor local
php spark migrate                 # migraciones
php spark db:seed CompaniesSeeder # seed empresa demo
php spark verifactu:process       # ejecuta el worker
composer openapi:build            # genera documentación OpenAPI
```

---

## 11) Procesamiento asíncrono y cola

### Estados del documento

- `draft` → creado por `/invoices/preview` (sin envío).
- `ready` → preparado para envío.
- `sent` → enviado a AEAT.
- `accepted` → aceptado por AEAT.
- `accepted_with_errors` → aceptado con errores por AEAT.
- `rejected` → rechazado por AEAT.
- `error` → fallo temporal, reintento según `next_attempt_at`.

### Campos de control

- `next_attempt_at` — fecha/hora del siguiente intento.
- `processing_at` — bloqueo temporal durante ejecución.

### Flujo

1. `/invoices/preview` crea un `draft` con hash, encadenamiento y XML local.
2. Si la empresa tiene `verifactu_enabled=1` y `send_to_aeat=1`, se pasa a `ready`.
3. El **worker** ejecuta `VerifactuService::sendToAeat()`.
4. En fallo temporal → `error`, se reintenta tras `backoff`.

---

## 12) Worker / Cron

```bash
php spark verifactu:process     # procesa 50 por defecto
php spark verifactu:process 100 # procesa 100 elementos
```

### En producción

Programar en crontab:

```cron
* * * * * /usr/bin/php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1
```

Logs: `/var/log/verifactu.log`

Recomendaciones:

- Usar `logrotate`.
- Controlar métricas de accepted/rejected/error.
- Soporta múltiples workers gracias a `processing_at` (lock optimista).

---

## 13) Envío a AEAT (stub actual)

Actualmente el envío se **simula** con `VerifactuService::sendToAeat()`:

- Genera XML “oficial” de ejemplo (sin WSSE).
- Guarda `requests/{id}-request.xml` y `responses/{id}-response.json`.
- Inserta registro en `submissions`.
- Actualiza estado (`sent`).

> En fases posteriores se sustituirá por el XML oficial VERI\*FACTU, firmado digitalmente y enviado mediante SOAP (`RegFactuSistemaFacturacion`).

---

## 14) Troubleshooting

**No se procesan facturas:**

- Comprueba `billing_hashes.status IN ('ready','error')` y `next_attempt_at <= NOW()`.

**Reintentos infinitos:**

- Ajusta backoff según tipo de error (5–15 min red / 1–24 h AEAT).

**Bloqueos persistentes:**

- Limpia `processing_at` manualmente o establece TTL de lock.

---

## 15) Próximos pasos

- ✅ Implementar QR real (`endroid/qr-code`).
- ✅ Integrar XML oficial y firma WSSE.
- ✅ Añadir validaciones AEAT (XSD y respuesta SOAP).
- 🔜 Endpoint `/invoices/{id}/pdf` con QR y CSV embebido.
- 🔜 Monitoreo de `submissions` (panel / API interna).

---

**Autor:** Javier Delgado — PTG 2025.
