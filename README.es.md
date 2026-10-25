<!--
 * MD.Viewer — Documentación
 * Versión: 2.11.3
-->

# MD.Viewer

<!-- md-i18n:switcher -->
**En otros idiomas:** [English](README.md) · [Deutsch](README.de.md) · [中文](README.zh.md) · [हिन्दी](README.hi.md) · Español · [Русский](README.ru.md)

> Un visor y navegador de Markdown en PHP, seguro y autoalojado, que convierte archivos `.md` simples en páginas de documentación pulidas, con navegador de archivos con búsqueda, tabla de contenidos generada automáticamente, numeración de encabezados, diagramas Mermaid, tooltips de glosario, controles de lectura adaptables, modo oscuro, un actualizador propio incorporado, carga de archivos, vista previa del portapapeles y configuración del lado del servidor. Sin paso de compilación. Sin base de datos. Sin framework.

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.18.0-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.14.0-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## Descripción general

MD.Viewer se articula en torno a **`md.php`**, un único punto de entrada en PHP que renderiza el archivo Markdown correspondiente o, cuando no existe un archivo coincidente, pasa a un navegador recursivo de archivos Markdown. El proyecto está pensado para un despliegue sencillo: copie los archivos a un host con PHP, coloque sus documentos `.md` cerca y abra `md.php` en un navegador.

El visor se centra en la documentación autoalojada sin cadena de herramientas. Admite tipografía legible, numeración de encabezados, generación automática de la tabla de contenidos, renderizado de Mermaid con autorreparación y diagnósticos de consola en inglés, controles compartidos de copia para código y citas, tooltips de glosario, modo oscuro, controles adaptados al móvil y un panel de Ajustes que guarda localmente las preferencias del visor.

**`updater.php`** es un motor complementario de autoactualización y mantenimiento. Comprueba archivos crudos de GitHub mediante ETag y SHA-256, crea copias de seguridad versionadas antes de reemplazar, restaura versiones anteriores desde el panel de Ajustes o directamente por URL, gestiona un enlace duro opcional `index.php`, maneja la carga de archivos `.md` y permite actualizaciones y reversiones directas desde el navegador.

El comportamiento del lado del servidor se controla mediante un archivo de configuración **`.md.ini`** colocado junto a `md.php`. Este archivo bloquea de forma rígida los indicadores de funcionalidad, que no pueden sobrescribirse desde el navegador ni desde el panel de Ajustes.

---

## Inicio rápido

```bash
# Clone into your web root or any subdirectory
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs

# Create a Markdown file that matches the script name
echo "# Hello, World\n\nThis is my documentation." > /var/www/html/docs/md.md

# Open in a browser
# https://your-domain.com/docs/md.php
```

Configuración mínima:

- Coloque **`md.php`** (con las carpetas `js/md/` y `css/md/`) junto a **`md.md`**.
- Abra **`md.php`** en un navegador.
- El script busca automáticamente un archivo Markdown con el mismo nombre base.

Opcional:

- Añada **`updater.php`** para habilitar la comprobación de actualizaciones dentro de la aplicación, la aplicación de actualizaciones, las copias de seguridad, la restauración, la carga de archivos y la gestión del enlace `index.php`.
- Defina **`ALLOW_UPDATE = true`** en `.md.ini` para habilitar el sistema de actualización (deshabilitado por defecto).

### Instalación de arranque con un solo archivo

La forma más rápida de instalar es subir **solo `updater.php`** al servidor y abrirlo en un navegador con `?update=true`. Hará lo siguiente:

1. Crear `.md.ini` con valores predeterminados seguros de forma automática.
2. Descargar todos los archivos rastreados (`md.php`, `js/md/*`, `css/md/*`, `README.md`, `LICENSE`) desde GitHub.
3. Crear todos los subdirectorios necesarios (`js/md/`, `css/md/`) con permisos `0755` si no existen.
4. Colocarse a sí mismo en la lista final y mostrar una página de resultado completa.

**Requisito:** para el modo navegador, `ALLOW_UPDATE = true` debe estar definido en `.md.ini` antes de ejecutar (o añádalo manualmente al `.md.ini` creado automáticamente y recargue). Para la API de máquina, la credencial es en cambio `API_KEY` de `.md.ini` — véase [API de máquina](#4-api-de-máquina-json-para-clientes-externos).

```bash
# Upload only updater.php, then:
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
# Edit .md.ini (auto-created on first hit) and set ALLOW_UPDATE = true
# Then open: https://your-domain.com/updater.php?update=true
#
# Or drive it over the machine API (no ALLOW_UPDATE needed, key from .md.ini):
curl "https://your-domain.com/updater.php?api_key=<key>&action=conflicts&format=json"
curl -X POST "https://your-domain.com/updater.php?api_key=<key>&action=install&format=json&dry_run=1"
```

---

## Requisitos

- PHP **8.3+**.
- Extensiones de PHP: `mbstring` y `curl` (necesarias para el actualizador y el portapapeles).
- Un servidor web como Apache, Nginx, Caddy o el servidor incorporado de PHP.
- Acceso a internet desde el navegador para los recursos de CDN (Mermaid, resaltado de sintaxis, Tailwind), salvo que se sustituyan por copias locales.

---

## Instalación

### Clonación con Git

```bash
git clone https://github.com/paulmann/MD.Viewer.git
cd MD.Viewer
```

### Instalación manual

1. Descargue el repositorio o el archivo de la versión.
2. Copie `md.php`, `updater.php`, `js/`, `css/` y, opcionalmente, `README.md` en su directorio de destino.
3. Añada sus archivos Markdown en el mismo árbol de directorios.
4. Abra `md.php` en un navegador. `.md.ini` se crea automáticamente en la primera carga.

### Instalación de un solo archivo mediante el actualizador

1. Suba solo `updater.php` al directorio de su servidor.
2. Abra `updater.php` una vez en un navegador — crea automáticamente `.md.ini` con `ALLOW_UPDATE = false`.
3. Edite `.md.ini` en el servidor: defina `ALLOW_UPDATE = true`.
4. Abra `https://your-domain.com/updater.php?update=true`.
5. El actualizador descarga todos los archivos, crea los directorios `js/md/` y `css/md/` según sea necesario y muestra una página de resultado.
6. Abra `md.php` — su MD.Viewer está listo.
7. Opcional: instale sin tocar `ALLOW_UPDATE` llamando a la API de máquina con 
   `API_KEY` de `.md.ini` (véase [API de máquina](#4-api-de-máquina-json-para-clientes-externos)).

### Servidor incorporado de PHP

```bash
cd /path/to/MD.Viewer
php -S localhost:8080
# then open http://localhost:8080/md.php
```

### Ejemplo de Nginx

```nginx
server {
    listen 80;
    server_name docs.example.com;
    root /var/www/html/docs;
    index index.php md.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## Estructura de archivos

```text
MD.Viewer/
├── md.php                     # Main viewer / browser script       v2.18.0
├── updater.php                # Self-updater, backup, upload        v3.14.0
├── .md.ini                    # Server-side config (auto-created)
├── md.md                      # Default Markdown file for md.php
├── uploads.md/                # Created automatically for uploaded/saved files
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.8.2/
│   ├── 2.8.2-pre-restore/     # Auto-created before each rollback
│   └── .state/                # ETag + SHA-256 per-file cache
├── css/
│   └── md/
│       ├── md.css             # v2.2.2
│       ├── settings.css       # v2.9.0
│       └── tooltips.css       # v2.4.5
├── js/
│   └── md/
│       ├── md.js              # v2.7.0
│       ├── settings.js        # v2.11.0
│       ├── tooltips.js        # v2.4.5
│       └── upload.js          # v2.9.1
├── LICENSE                    # v1.0.0
└── README.md                  # v2.11.3
```

Regla clave de nombres: `md.php` busca `md.md`, `docs.php` busca `docs.md`. Varias instancias independientes del visor pueden compartir un mismo árbol de directorios sin enrutamiento adicional.

---

## Configuración del lado del servidor — `.md.ini`

En la primera petición, `md.php` (o `updater.php`) crea `.md.ini` en el mismo directorio. Este archivo bloquea de forma rígida los indicadores de funcionalidad, de modo que **no** puedan cambiarse desde el navegador ni desde el panel de Ajustes. Las claves ausentes se añaden automáticamente con valores predeterminados seguros en cada carga.

```ini
; MD.Viewer server-side configuration

; Disable the "Upload .md" button in the file browser (recommended: true)
DISABLE_UPLOAD    = true

; Disable the "Preview Clipboard" button in the file browser
DISABLE_CLIPBOARD = false

; Disable the "Save to File" button in clipboard preview
DISABLE_SAVE_CLIPBOARD_TO_FILE = true

; Allow updating files via updater.php?update=true or the Settings panel
ALLOW_UPDATE = false

; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]
ALLOW_RESTORE = false

; Allow creating/removing the index.php hard link from the Settings panel
ALLOW_CREATE_INDEX_PHP_LINK = true

; Machine API key used by external clients (a control panel, an installer)
; Generated automatically on first run. Send it as ?api_key=<value>.
API_KEY = mdv_your_generated_key_here
```

| Clave | Predeterminado | Efecto |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Oculta y desactiva el botón Upload |
| `DISABLE_CLIPBOARD` | `false` | Oculta y desactiva el botón de vista previa del portapapeles |
| `API_KEY` | generada automáticamente | Clave secreta para la API de máquina (`?api_key=<value>`); necesaria para instalaciones JSON |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Oculta Guardar en archivo en la vista previa del portapapeles |
| `ALLOW_UPDATE` | `false` | Habilita el sistema de actualización (panel de Ajustes + `?update=true`) |
| `ALLOW_RESTORE` | `false` | Habilita la sección Copia de seguridad y restauración y el modo URL `?restore=` |
| `ALLOW_CREATE_INDEX_PHP_LINK` | `true` | Habilita la sección Archivo de índice en el panel de Ajustes |

**Todas las claves se añaden automáticamente** (con sus valores predeterminados) a los archivos `.md.ini` existentes en la siguiente carga — actualizar nunca exige editar `.md.ini` a mano.

### `.md.ini` por directorio (v2.12.0 / v3.12.0)

El `.md.ini` situado junto a `md.php` conserva su papel de archivo de nivel de instalación
(`BROWSE_DIR`, `API_KEY`, `ALLOW_*` y los valores predeterminados de los indicadores `DISABLE_*`).
Por encima de él, **cualquier directorio dentro de la raíz de navegación puede llevar su propio
`.md.ini`**. Los ajustes que se aplican a un documento son los del archivo base con todos los
`.md.ini` encontrados en el camino desde la raíz de navegación hasta el directorio del documento
aplicados sobre él — un archivo más profundo siempre gana al menos profundo:

```text
ext/mdviewer/.md.ini     base file: installation-level keys, API key
share/.md.ini            applies to everything below share/
share/sub/.md.ini        applies to share/sub/ and below, and wins
```

Solo se respetan estas tres claves en un archivo de directorio:

| Clave | Predeterminado | Efecto en ese directorio |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Si se permite **Upload .md** y dónde aterriza la carga |
| `DISABLE_CLIPBOARD` | `false` | Si el botón de vista previa del portapapeles está activo |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Si se permite **Guardar en archivo** y dónde aterriza el archivo |

Reglas:

- Las claves `BROWSE_DIR`, `API_KEY`, `ALLOW_UPDATE`, `ALLOW_RESTORE` y
  `ALLOW_CREATE_INDEX_PHP_LINK` son de nivel de instalación. Se ignoran en un
  archivo de directorio y se escribe una nota en el registro de errores de PHP.
- Los valores se interpretan con el mismo rigor que en el archivo base (recortados, comillas
  emparejadas opcionales eliminadas, y descartado un valor que contenga un byte nulo, un salto
  de línea o más de 255 bytes). Un archivo ilegible o un valor inutilizable se omite con una
  entrada en el registro de errores — nunca rompe la página ni recurre a un valor
  predeterminado privilegiado.
- Un `.md.ini` de directorio solo se lee cuando su ruta real está dentro de la raíz de
  navegación, de modo que se rechaza una ruta que intente salir con `..`.
- En modo visor (`?file=sub/doc.md`) manda el directorio del documento; en modo navegador de
  archivos manda la raíz de navegación.
- **Upload .md** y **Guardar en archivo** escriben en el directorio que se está viendo. La
  petición lo lleva en el parámetro `dir` (relativo a la raíz de navegación); un valor `dir`
  con segmentos de recorrido, una ruta absoluta o un byte nulo se responde con `400`, y un
  directorio tampoco puede abandonarse de ninguna otra forma.
- Los archivos `.md.ini` de directorio son opcionales. Sin ellos el comportamiento es
  exactamente el mismo que antes.

---

## Modos de uso

### Modo visor

Cuando existe un archivo Markdown coincidente, MD.Viewer lo renderiza como una página de documento con estilo. Deriva el título de la página del primer encabezado de nivel superior, construye una descripción a partir del primer párrafo, genera una tabla de contenidos y aplica las preferencias locales del visor.

### Modo navegador de archivos

Cuando no existe un archivo Markdown coincidente, MD.Viewer pasa a un navegador recursivo de Markdown. El navegador recorre el árbol de directorios actual, lista los archivos Markdown en una tabla con búsqueda y abre el archivo seleccionado con `?file=relative/path.md`.

### Modo vista previa del portapapeles

El navegador de archivos incluye un botón **Clipboard Preview**. Pegue cualquier texto Markdown en el área de texto y pulse Preview — el contenido se renderiza en modo visor. Si `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, aparece una barra **Save to File** con un campo de nombre de archivo y un botón Save que escribe en `BROWSE_DIR` (recurriendo a `uploads.md/` junto a `md.php` cuando esa clave no está definida).

### Parámetros de URL

| Parámetro | Significado | Ejemplo |
|---|---|---|
| `?file=path/to/doc.md` | Renderiza un archivo Markdown concreto | `md.php?file=docs/api.md` |

---

## Funcionalidades

### Renderizado de Markdown

- Encabezados, párrafos, énfasis, texto en negrita, código en línea, citas en bloque, reglas horizontales y bloques de código delimitados.
- La autorreparación de Mermaid convierte notas de clase estilo PlantUML, `\n` literales, `<br>` sin cerrar, `return` de PlantUML y paréntesis sin comillas; los diagnósticos son solo en inglés.
- Listas ordenadas y no ordenadas, incluidas las anidadas.
- Tablas con salida con estilo.
- Listas de tareas, notas al pie y enlaces de estilo referencia.
- Imágenes y enlaces estándar.
- Compatibilidad con códigos cortos de emoji.
- Patrones en línea universales para un formato de documento pulido.

### Navegación y estructura

- Tabla de contenidos generada automáticamente.
- Numeración automática de encabezados opcional.
- División del título por dos puntos para títulos de portada más limpios.
- Modo navegador de archivos con búsqueda.
- Copia y descarga del `.md` de origen del documento que se está leyendo y de cada archivo del
  navegador (ambos conmutadores vienen activados por defecto).

### Código, diagramas y contenido enriquecido

- Bloques de código delimitados con resaltado de sintaxis y botón de copia.
- Diagramas Mermaid.
- Compatibilidad con superíndices y subíndices.

### Tooltips de glosario

Tooltips de glosario en línea mediante `js/md/tooltips.js` y `css/md/tooltips.css`. Los términos muestran un tooltip al pasar el cursor; el motor resuelve grafías variantes a la misma definición. Incluye soporte táctil, un margen de gracia de 2 segundos y posicionamiento inteligente según el área visible.

### Panel de ajustes — tooltips de funciones

Cada conmutador de la sección **Funciones** del panel de Ajustes muestra un tooltip detallado al pasar el cursor, que explica qué hace la función y cómo afecta al renderizado. Los tooltips usan la misma burbuja `.g-tooltip` y el mismo motor de posicionamiento que los tooltips de glosario — mismo estilo visual, mismo soporte de modo oscuro, mismo comportamiento táctil y de teclado. No se requiere CSS adicional.

### Apariencia y controles de lectura

- Modo oscuro.
- Ancho de página, tamaño de fuente y altura de línea ajustables.
- Ajustes de visibilidad de la barra de herramientas superior (escritorio y móvil de forma independiente).
- Preferencias persistentes guardadas en cookies del navegador.

### Carga de archivos

Si `DISABLE_UPLOAD = false` en `.md.ini`, el navegador de archivos muestra un botón **Upload .md**. Los archivos subidos se guardan en `BROWSE_DIR` o, cuando esa clave no está definida, en `uploads.md/` junto a `md.php`. Con ajustes por directorio, el archivo aterriza en cambio en el directorio que se está viendo, y el `.md.ini` propio de ese directorio decide si la acción está permitida. La protección de la carga tiene tres capas:

1. Botón de interfaz oculto o desactivado cuando `DISABLE_UPLOAD = true`.
2. El manejador JS de clic devuelve pronto si `MDV_CONFIG.disableUpload` es true.
3. PHP `updater.php`: lee `.md.ini` de forma independiente y devuelve `403` si está desactivado.

### Copia y descarga del `.md` de origen

Tres controles opcionales entregan al lector el archivo en sí en lugar de la página renderizada.
Los tres vienen activados por defecto, los tres son conmutadores de funcionalidad del lado de PHP
(véase el panel de Ajustes y la tabla de claves de `.md.ini`), y ambos son **puertas de interfaz
antes que control de acceso**: una petición del mismo archivo sigue siendo atendible en ambos
casos, porque el visor no tiene autenticación y nunca la tuvo.

| Conmutador | Dónde aparece | Predeterminado |
|---|---|---|
| `FEATURE_DOWNLOAD_ICON` | Un icono de descarga en cada fila del navegador de archivos | activado |
| `FEATURE_DOWNLOAD_BUTTON` | Un botón de descarga en la barra de herramientas del documento, a la izquierda de los controles de ancho y fuente | activado |
| `FEATURE_COPY_BUTTON` | Un botón de copia en la barra de herramientas del documento, a la izquierda del botón de descarga | activado |

Los tres llevan a la misma petición — `md.php?file=<relative path>&download=1` — y esa
petición se valida con exactamente la misma función que la página del visor, así que una ruta
rechazada para mostrarse también se rechaza aquí. La respuesta entrega los bytes del archivo como
`application/octet-stream` con `X-Content-Type-Options: nosniff` y
`Content-Disposition: attachment`: un `.md` es texto, y anunciarlo como texto bajo un nombre de
archivo elegido por el propio autor del documento es cómo se convertiría en un script a ojos del
navegador. El botón de copia obtiene esa petición al hacer clic en lugar de incrustar el
documento en la página una segunda vez, y luego pone el resultado en el portapapeles. La vista
previa del portapapeles no tiene archivo detrás, así que no recibe ninguno de los dos controles
de la barra de herramientas.

### Vista previa del portapapeles y Guardar en archivo
Pegue texto Markdown y renderícelo al instante sin guardar un archivo. Si `DISABLE_SAVE_CLIPBOARD_TO_FILE = false`, aparece una barra Save to File con campo de nombre de archivo, botón Save y confirmación de estado en línea. El texto de origen se guarda en `sessionStorage` cuando se abre la vista previa. El archivo se escribe en el directorio que se está viendo, y un `.md.ini` por directorio puede desactivar o reactivar el guardado allí.

### Panel de ajustes

El panel de Ajustes (que se abre con el icono de engranaje de la cabecera) incluye:

- Conmutadores de preferencias del visor (tema, ancho, fuente, altura de línea, tabla de contenidos, numeración).
- Controles de visibilidad de la barra de herramientas superior por tipo de dispositivo.
- Conmutadores de funcionalidad del lado de PHP con tooltips descriptivos al pasar el cursor (se aplican en la siguiente carga de página mediante cookies).
- Controles de comprobación y aplicación de actualizaciones (visibles solo cuando `ALLOW_UPDATE = true`).
- Lista de copias de seguridad y controles de restauración (visibles solo cuando `ALLOW_RESTORE = true`).
- Gestión del enlace duro `index.php` (visible solo cuando `ALLOW_CREATE_INDEX_PHP_LINK = true`).

---

## Actualizador incorporado

`updater.php` ofrece cuatro formas de actualizar y gestionar archivos.

### Página de inicio del actualizador

Abrir `updater.php` sin parámetros muestra una **página informativa** con:

- Estado presente / ausente de cada archivo rastreado, con números de versión.
- Cada nombre de archivo enlaza al archivo crudo en GitHub (se abre en una pestaña nueva).
- Estado de `.md.ini`: muestra una advertencia si `ALLOW_UPDATE = false`, con instrucciones de edición.
- Botones de acción (**Check & Apply Updates**, **↺ Force Reinstall All**, **⟲ Restore Latest Backup**) — aparecen solo cuando el indicador correspondiente está habilitado en `.md.ini`.

### 1. Panel de ajustes (AJAX)

- Comprobar actualizaciones en todos los archivos rastreados, con visualización de la versión por archivo.
- Aplicar actualizaciones — hace copia de seguridad de los archivos actuales y descarga las versiones nuevas.
- Forzar la reinstalación de todos los archivos sin importar la versión (omite la caché de ETag/SHA-256).
- Ver y restaurar versiones de copia de seguridad (visible solo cuando `ALLOW_RESTORE = true`).

### 2. Actualización directa (`?update=true`)

Requiere `ALLOW_UPDATE = true` en `.md.ini`.

```
https://your-domain.com/updater.php?update=true
https://your-domain.com/updater.php?update=true&force=true   ← force reinstall
```

**Arranque en dos fases:**

- **Fase 1** — actualiza `updater.php` sí mismo primero. Si el archivo cambió, redirige a `?_phase=2` (conservando `&force=true` si estaba definido) para que el **nuevo** actualizador se encargue del resto.
- **Fase 2** — actualiza el resto de archivos rastreados con visualización del delta de versión.

**Indicador `&force=true`:**

- Omite por completo la caché de ETag y SHA-256 — descarga siempre cada archivo desde GitHub.
- Útil tras una actualización parcial fallida o cuando los archivos locales están dañados.
- Se propaga automáticamente de la fase 1 a la fase 2 mediante la URL de redirección.
- En el pie de la página de resultado aparece un botón ámbar **↺ Force reinstall all**.

**Creación automática de directorios:**

El actualizador crea los subdirectorios que falten (`js/md/`, `css/md/`) con permisos `0755` antes de escribir los archivos. Esto hace que `?update=true` y la API de máquina funcionen desde una instalación de un solo archivo.

**Página de resultado:**

- Insignias de estado por archivo: `updated` / `force-updated` / `created` / `current` / `error`
- Delta de versión: `2.7.0 → 2.8.2`
- Cada nombre de archivo es un enlace en el que se puede hacer clic al archivo crudo en GitHub (se abre en una pestaña nueva)
- Pie: botón **↺ Force reinstall all** + botón **← Back**

### 3. Reversión directa (`?restore=`)

Requiere `ALLOW_RESTORE = true` en `.md.ini`.

```
https://your-domain.com/updater.php?restore=latest
https://your-domain.com/updater.php?restore=2.7.0
```

| URL | Comportamiento |
|---|---|
| `?restore=latest` | Recorre `md.backup/` y elige automáticamente la versión semver más alta |
| `?restore=X.Y.Z` | Restaura la versión de copia de seguridad nombrada exactamente |

**Proceso de reversión:**

1. Valida `ALLOW_RESTORE = true` — muestra una página de error si es false.
2. `?restore=latest` recorre `md.backup/`, ordena por semver y elige la más reciente.
3. Valida la cadena de versión (solo alfanuméricos, puntos y guiones).
4. Hace copia de seguridad de los archivos actuales en `md.backup/[version]-pre-restore/` antes de sobrescribir — la reversión de una reversión siempre es posible.
5. Invalida la caché ETag por archivo (`.state/`) para que la siguiente comprobación de actualizaciones haga una descarga completa.
6. Emite una página HTML de resultado con insignias `restored` / `skipped` / `error`, delta de versión y enlaces de archivo en los que se puede hacer clic.

### 4. API de máquina (JSON, para clientes externos)

Una API JSON protegida por clave permite que otro programa (un panel de control, un instalador) dirija la instalación y 
renderice su progreso paso a paso. Nunca devuelve HTML, y los avisos o advertencias de PHP nunca 
se filtran al cuerpo de la respuesta.

`API_KEY` se genera en la primera petición y se guarda en `.md.ini`. Defina ahí su propio valor 
antes de desplegar si quiere automatizar el arranque sin leer el archivo más tarde.

```
GET  updater.php?api_key=<key>&action=status&format=json
GET  updater.php?api_key=<key>&action=conflicts&format=json
POST updater.php?api_key=<key>&action=install&format=json[&dry_run=1][&force=1]
```

| Acción | Método | Propósito |
|---|---|---|
| `status` | GET | Estado de la instalación local por archivo (presente / ausente / versión). Sin E/S de red. |
| `conflicts` | GET | Informe de conflictos en seco. Nunca escribe nada. |
| `install` | POST | Obtiene y escribe archivos a través de la protección de conflictos previa a la escritura. |

Envoltorio de la respuesta:

```json
{
  "ok": true,
  "action": "install",
  "version": "2.16.0",
  "steps":     [{"step": "install:css/md/md.css", "status": "ok", "ms": 143, "detail": "2.2.2 → 2.2.2"}],
  "files":     [{"path": "css/md/md.css", "status": "current", "version": "2.2.2"}],
  "conflicts": [{"path": "css/md/settings.css", "status": "same_name_elsewhere",
                 "existing_path": "legacy/components/settings.css", "size_bytes": 1932}],
  "errors":    []
}
```

- `conflicts[].status`: `ok` | `exists` (la ruta exacta ya existe) | `same_name_elsewhere`
- `files[].status`: `present` | `missing` | `updated` | `created` | `force-updated` | `current` | `skipped` | `would_write` | `error`
- `steps[].status`: `ok` | `dry-run` | `skip` | `warn` | `error`
- Códigos HTTP: `200` completado (los fallos por archivo mantienen `"ok": false` en el cuerpo), `400` acción desconocida, `403` `unauthorized` o `api_key_unavailable`, `405` `install` sin POST

`dry_run=1` se detiene después del informe de conflictos: no se obtiene nada y no se escribe nada. 
`force=1` también reemplaza un archivo en una ruta rastreada que no parece un archivo de MD.Viewer 
(en caso contrario se omite y se informa en `conflicts`) y omite la caché de ETag/SHA-256. 
Los modos de navegador anteriores siguen respetando `ALLOW_UPDATE` / `ALLOW_RESTORE`; la clave de API es su 
propia credencial para las acciones JSON.
---

## Archivos rastreados

Todos los archivos gestionados por el actualizador (comprobados, descargados, respaldados):

```
md.php                    v2.18.0
updater.php               v3.14.0
js/md/md.js               v2.7.0
js/md/settings.js         v2.11.0
js/md/tooltips.js         v2.4.5
js/md/upload.js           v2.9.1
css/md/md.css             v2.2.2
css/md/settings.css       v2.9.0
css/md/tooltips.css       v2.4.5
README.md                 v2.11.3
LICENSE                   v1.0.0
```

---

## Copias de seguridad y restauración

Antes de cada reemplazo de archivo (actualización o reinstalación forzada), el archivo local actual se copia a `md.backup/[version]/`. El panel de Ajustes lista las versiones de copia de seguridad, las fechas y los recuentos de archivos (visible solo cuando `ALLOW_RESTORE = true`). Al restaurar, primero se hace copia de seguridad de la versión actual (como `[version]-pre-restore`) y luego se reescriben los archivos seleccionados. `README.md` y `LICENSE` se descargan y se actualizan, pero **no** se respaldan (son documentación, no código).

---

## Seguridad

Todas las acciones sensibles a la seguridad están protegidas en **tres niveles independientes**: el indicador `.md.ini` del servidor, la guarda de la API de PHP y la supresión desde la interfaz del cliente.

| Función | Indicador `.md.ini` | Guarda de PHP | Supresión en la interfaz |
|---|---|---|---|
| Actualización (aplicar/comprobar) | `ALLOW_UPDATE` | `requireAllowUpdate()` | Oculta cuando es false |
| Restaurar copia de seguridad | `ALLOW_RESTORE` | `requireAllowRestore()` | Oculta cuando es false |
| Listar copias de seguridad | `ALLOW_RESTORE` | `requireAllowRestore()` | No se llama cuando es false |
| Crear enlace de índice | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Oculta cuando es false |
| Eliminar enlace de índice | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Oculta cuando es false |
| Estado del índice | `ALLOW_CREATE_INDEX_PHP_LINK` | Devuelve `{disabled:true}` | No se llama cuando es false |
| Carga de archivos | `DISABLE_UPLOAD` | 403 si es true | Botón oculto |
| Guardar portapapeles | `DISABLE_SAVE_CLIPBOARD_TO_FILE` | 403 si es true | Botón oculto |
| API de máquina (`status`, `conflicts`, `install`) | `API_KEY` | 403 `unauthorized` | No invocable sin la clave |

Protecciones adicionales:

- **`.md.ini`** nunca se sirve al navegador — se lee solo del lado del servidor.
- Los archivos **`.md.ini` por directorio** se leen solo de directorios dentro de la raíz de navegación, y de ellos se toman únicamente las claves `DISABLE_*`; las claves de nivel de instalación se ignoran y se registran.
- El **recorrido de rutas** se evita en todas las operaciones de servicio de archivos, carga y guardado.
- **Upload / Save to File** validan los nombres de archivo del lado del servidor (solo extensión `.md`, sin barras, sin bytes nulos).
- La **guarda CORS** de `updater.php` rechaza peticiones de origen cruzado.
- **Todas las acciones que modifican** requieren `POST` (salvo los modos URL directos, que requieren sus respectivos indicadores `ALLOW_*`).
- Las **cadenas de versión** en `?restore=` se validan solo contra `[a-zA-Z0-9.\-]`.
- La **API de máquina** responde solo en JSON (`format=json`), requiere `API_KEY` e informa cada 
  archivo ajeno omitido en lugar de sobrescribirlo.

---

## Licencia

Publicado bajo la PolyForm Noncommercial License 1.0.0. Véase [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE).
