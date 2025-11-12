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

## 6) Documentación OpenAPI (Swagger UI)

La documentación de la API se genera **dinámicamente** en tiempo de ejecución mediante [`swagger-php`](https://github.com/zircote/swagger-php).

### ▶️ Acceso rápido

- **Swagger UI:** `/api/v1/docs/ui`\
  Muestra la documentación interactiva en el navegador.

- **JSON OpenAPI:** [`/api/v1/docs/generate`](http://localhost:8080/api/v1/docs/generate)\
  Devuelve el esquema **OpenAPI 3.0** generado al vuelo.

> 💡 Ambas rutas están disponibles solo en entorno `development`.\
> En producción pueden desactivarse o protegerse con autenticación.

---

### 📂 Estructura de la documentación

| Archivo / Carpeta                         | Función                                                                                           |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `app/Swagger/Root.php`                    | Define los metadatos globales (`Info`, `Server`, `SecuritySchemes`, etc.) mediante **atributos**. |
| `app/Controllers/...`                     | Controladores de la API con atributos `#[OA\Get]`, `#[OA\Post]`, etc.                             |
| `app/Controllers/SwaggerDocGenerator.php` | Controlador que genera el JSON y sirve la vista de Swagger UI.                                    |
| `app/Views/swagger_docs/index.php`        | Vista HTML de Swagger UI (usa CDN, sin dependencias locales).                                     |

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

# Lint rápido (opcional si instalas tools)
./vendor/bin/phpcs --standard=PSR12 app
```

## 11. Procesamiento asíncrono y cola

---

### Estados del documento (`billing_hashes.status`)

- `draft`: creado por `/invoices/preview`, sin envío.

- `ready`: listo para procesar por el worker (en cola interna).

- `sent`: enviado a AEAT; pendiente o con respuesta registrada.

- `accepted`: aceptado por AEAT.

- `rejected`: rechazado por AEAT (error de validación/negocio AEAT).

- `error`: fallo temporal (conexión, SOAP, firma, etc.). Se reintenta según `next_attempt_at`.

### Campos de cola

- `next_attempt_at` (DATETIME): cuándo puede volver a intentarse el envío.

- `processing_at` (DATETIME): lock optimista para evitar doble proceso por múltiples workers.

### Idempotencia

- Cabecera `Idempotency-Key`: reutiliza el mismo `document_id` y respuesta si el cliente reintenta la misma operación de `preview`.

### Flujo recomendado

1.  `/invoices/preview` crea `draft`.

2.  Si la empresa tiene `verifactu_enabled=1` y `send_to_aeat=1` (o `?queue=1`/`X-Queue: 1`), se actualiza a `ready` y se programa `next_attempt_at = NOW()`.

3.  El **worker** recoge `ready`/`error` con `next_attempt_at <= NOW()` y los procesa.

4.  En caso de fallo temporal: `status = error` y `next_attempt_at = NOW() + backoff`.

---

## 12. Worker / Cron

---

### Comando manual (local o servidor)

php spark verifactu:process # procesa hasta 50 elementos por defecto

php spark verifactu:process 100 # procesa hasta 100

**Qué hace:**

- Selecciona `billing_hashes` con `status IN ('ready','error')`, `processing_at IS NULL` y `next_attempt_at <= NOW()` (o NULL).

- Marca `processing_at` para evitar duplicidades.

- Llama al servicio `VerifactuService::sendToAeat($id)`.

- Registra el intento en `submissions` y actualiza el `status` del documento.

- En errores temporales aplica **backoff** (por defecto +15 min).

### Programación en producción (crontab)

Ejecuta el worker **cada minuto** para baja latencia:

- - - - - /usr/bin/php /var/www/verifactu-api/spark verifactu:process >> /var/log/verifactu.log 2>&1

> Ajusta la ruta a PHP y al proyecto. Asegúrate de que el usuario del cron tenga permisos de lectura/escritura en el proyecto y logs.

### Logs y observabilidad

- Salida estándar se vuelca a `/var/log/verifactu.log` (según crontab).

- Recomendada rotación (logrotate) y/o envío a syslog.

- Métricas sugeridas: nº de procesados/aceptados/rechazados por minuto, latencia media, reintentos, códigos AEAT más frecuentes.

### Consideraciones multi-worker

- `processing_at` actúa como **lock optimista**. Con varios workers en paralelo evita la doble ejecución.

- Si necesitas robustez adicional, añade `lock_token` y condición en el `UPDATE`.

---

## 13. Operación y troubleshooting

---

**Problema:** no se procesan elementos.

- Verifica que existan filas en `billing_hashes` con `status='ready'` o `status='error'` y `next_attempt_at <= NOW()`.

- Comprueba que `processing_at` esté `NULL` (si quedó bloqueado por caída del worker, puede limpiarse manualmente o fijar un TTL de lock en el comando).

**Problema:** demasiados reintentos.

- Ajusta backoff por tipo de error. Recomendación inicial: 5--15 min para errores de red; 1--24 h para caídas mantenidas del servicio AEAT.

**Cambio de estrategia:**

- Para envío inmediato, puedes invocar el servicio desde la propia API tras `preview`. Para mayor resiliencia, preferimos **en cola** con worker.
