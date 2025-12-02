# Docker Local --- Entorno PHP 8.2 + Apache para desarrollo

---

Este proyecto incluye un entorno Docker para ejecutar el middleware en local con **PHP 8.2 + Apache**, independiente del PHP del sistema (por ejemplo si tu Mac usa PHP 7.4 via Homebrew).

Pensado para:

- Desarrollo local

- Tests y comandos `php spark`

- Evitar conflictos de versiones entre proyectos

- Ejecutar varias instancias del mismo proyecto en puertos distintos

---

## Estructura de ficheros Docker

---

```json
project-root/
├─ Dockerfile
├─ docker-compose.yml
└─ docker/
   └─ vhost.conf
```

### `Dockerfile`

- Imagen base: `php:8.2-apache`

- Activa `mod_rewrite` (necesario para CI4)

- Instala extensiones: `intl`, `pdo_mysql`, `mysqli`, `zip`...

- Copia `docker/vhost.conf` a Apache

- DocumentRoot = `/public`

### `docker-compose.yml`

Define el servicio:

- nombre del contenedor

- puerto expuesto (por defecto 8082)

- volumen `.:/var/www/html`

- arranque/paro automático

### `docker/vhost.conf`

Sitúa el DocumentRoot en `public/` y permite `.htaccess`.

---

## **Arrancar el entorno por primera vez**

---

Desde la raíz del proyecto:

`docker compose up -d --build`

Acceder:

`http://localhost:8082`

---

## **Arranques posteriores**

---

Sin rebuild:

`docker compose up -d`

Apagar:

`docker compose stop`

Apagar + eliminar contenedor:

`docker compose down`

---

## Ejecutar comandos dentro del contenedor

---

`docker compose exec app bash`

Dentro:

```php
php spark migrate
php spark db:seed TestSeeder
php -v
```

Salir:

`exit`

---

## Logs

`docker compose logs -f`

---

## **Varias instancias del mismo proyecto en distintos puertos**

---

Puedes tener múltiples carpetas/instancias del mismo proyecto y levantar cada una en un puerto diferente.\
Simplemente cambia:

```yaml
ports:
  - "8082:80" # instancia A`
```

Por ejemplos:

```makefile
8083:80  → instancia B
8084:80  → instancia C`
```

Ejemplo:

```
http://localhost:8082   → instancia 1
http://localhost:8083   → instancia 2
http://localhost:8084   → instancia 3
```

Cada instancia es totalmente independiente: `.env`, BD, cola, logs, etc.
