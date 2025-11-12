# VERI\*FACTU Middleware API (CodeIgniter 4)

Middleware multiempresa para integrar sistemas externos con VERI\*FACTU (AEAT). Proyecto diseñado para iterar por fases, reutilizable entre empresas y compatible con PHP **7.4 → 8.3** (desarrollando con 7.4, pero funcionando en 8.x evitando sintaxis exclusivas de 8.x).

---

## 1) Objetivos

- Exponer una API REST **multiempresa** que reciba datos de facturación ya numerados por el sistema origen.
- Generar **hash, encadenamiento, QR, CSV, XML de previsualización** (Fase 1) y posteriormente **enviar a AEAT** (Fase 2).
- **Idempotencia** por **petición** para evitar duplicados.
- **Trazabilidad** total (logs y auditoría).
- **Certificado único** (colaborador social) con autorización de emisores (NIF) por empresa.

---

## 2) Requisitos

- PHP 7.4+ (funciona también con PHP 8.2/8.3 si evitamos sintaxis nuevas de 8.x en el código del proyecto).
- Composer
- MySQL 5.7+/8.x
- CodeIgniter 4 (appstarter 4.3.x)
- `zircote/swagger-php` para generar OpenAPI desde PHPDoc

---

## 3) Instalación

```bash
composer install
```

### `.env` mínimo

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

> **Compatibilidad 7.4 → 8.3**: usa `declare(strict_types=1);`, tipos escalares y de retorno, y documenta los tipos complejos con PHPDoc (evita union types/attributes/constructor promotion propias de 8.x).

---

## 4) Migraciones y Seeders

**Migraciones creadas:**

- `companies` — empresas y flags (verifactu_enabled, send_to_aeat, etc.)
- `authorized_issuers` — emisores autorizados por empresa
- `api_keys` — claves de acceso por empresa
- `billing_hashes` — documento local por factura (hash, prev_hash, xml_path, etc.)
- `submissions` — intentos de envío a AEAT

**Seeders:**

- `CompaniesSeeder` (empresa demo `acme`)
- `ApiKeysSeeder` (API key demo)

**Ejecución:**

```bash
php spark migrate
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder
```

---

## 5) Autenticación (API Key)

Filtro `ApiKeyAuthFilter`:

- Lee `X-API-Key` o `Authorization: Bearer <token>`.
- Resuelve `company_id` y lo inyecta en la request.

**Rutas protegidas** bajo `api/v1` con filtro `apikey`.

---

## 6) Documentación OpenAPI (swagger-php)

**Script en `composer.json`:**

```json
{
  "scripts": {
    "openapi:build": "php ./vendor/bin/openapi --bootstrap vendor/autoload.php --format json --output public/openapi.json app/Controllers app/DTO"
  }
}
```

- **Importante:** limita el escaneo a directorios con anotaciones (p. ej. `app/Controllers`, `app/DTO`) y añade `--bootstrap vendor/autoload.php` para que swagger-php conozca el autoload de Composer.
- Asegúrate de **importar** `use OpenApi\Annotations as OA;` en cada archivo con anotaciones.

**Generar el JSON:**

```bash
composer openapi:build
```

Luego sirve `public/openapi.json` y, si quieres, añade Swagger UI en `public/swagger/` apuntando a ese JSON.

---

## 7) Arranque del servidor

```bash
php spark serve
```

Probar health:

```bash
curl -H "X-API-Key: dev_acme_key_000..." http://localhost:8080/api/v1/health
```

---

## 8) Estructura del proyecto (guía)

```
app/
  Controllers/
    Api/
      V1/
        HealthController.php
        // (próximo) InvoicesController.php
  DTO/
    // InvoiceDTO.php (próximo)
  Filters/
    ApiKeyAuthFilter.php
  Services/
    // VerifactuService, SoapClient (posteriores)
  Database/
    Migrations/
    Seeds/
public/
  openapi.json
  swagger/ (opcional)
```

---

## 9) Estándares de código

- `declare(strict_types=1);` en todos los archivos.
- Tipos de parámetros y retorno siempre que sea posible (compatibles 7.4+).
- PHPDoc para arrays/objetos complejos.
- Nombres consistentes en inglés para entidades/campos.

## 10) Comandos útiles

```bash
# Servidor local
php spark serve

# Migraciones / Seeders
php spark migrate
php spark migrate:refresh
php spark db:seed CompaniesSeeder
php spark db:seed ApiKeysSeeder

# OpenAPI
composer openapi:build

# Lint rápido (opcional si instalas tools)
./vendor/bin/phpcs --standard=PSR12 app
```
