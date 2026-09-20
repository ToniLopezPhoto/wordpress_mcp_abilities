# Test baseline — WordPress 6.9

> **Desactualizado.** Esta ejecución se hizo sobre `feat/issue-8-navigation-site-editor`,
> una rama que nunca llegó a `origin` (no existe en `git branch -a`) y cuyo código no
> coincide con el commit `c6cdbfa` finalmente mergeado — que contenía un fatal error en
> runtime (cuatro métodos llamados y nunca definidos en `class-site-editor.php`) que este
> baseline no pudo haber visto verde. No usar estas cifras como referencia; regenerar este
> documento la primera vez que la CI (`.github/workflows/ci.yml`, job `unit`) corra la suite
> real sobre el código estabilizado. `tests/test-documentation.php` verifica en CI que el
> recuento de tests declarado en ambos README coincide con `ReflectionClass`, para que este
> tipo de deriva no vuelva a pasar inadvertida.

## Estado cerrado

Ejecución realizada sobre la rama `feat/issue-8-navigation-site-editor`, con el
código de las Issues #7 y #8 y las correcciones de compatibilidad y fixtures
incluidas.

| Componente | Estado verificado |
|---|---|
| PHP | 8.3.6 |
| WordPress | 6.9.7 |
| PHPUnit | 9.6.36 |
| WordPress PHPUnit suite | rama 6.9 |
| MariaDB | 10.11.14 |
| Resultado | **154 tests, 788 aserciones — OK** |

La prueba de lectura de global styles se omite cuando el theme de pruebas no
incluye `theme.json`; no se contabiliza como fallo.

Comando ejecutado:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib \
WP_TESTS_PHPUNIT_POLYFILLS_PATH=/tmp/phpunit-polyfills-extract/PHPUnit-Polyfills-1.x \
php /tmp/phpunit.phar --configuration phpunit.xml.dist
```

## Correcciones aplicadas

### API de Abilities de WordPress 6.9

- Los tests ya consultan `WP_Ability` mediante `get_meta_item()` y
  `get_category()` en lugar de tratar el objeto como array.
- Las categorías y abilities se inicializan mediante el ciclo oficial de
  WordPress (`wp_abilities_api_categories_init` y `wp_abilities_api_init`), sin
  registrar directamente fuera de los hooks requeridos.
- La metadata MCP se comprueba en la estructura real `meta.mcp.public`.

### Reemplazo de media

- `wp_handle_sideload()` recibe ahora una variable, como exige su parámetro por
  referencia.
- Un `false` de `update_attached_file()` o
  `wp_update_attachment_metadata()` solo se considera fallo si el valor
  persistido tampoco coincide; WordPress puede devolver `false` cuando el dato
  ya era idéntico.
- La validación de permisos sobre el post o página objetivo reutiliza el
  validador central de meta-capabilities y conserva la distinción entre falta
  de permiso y contenido ajeno.
- Los fallos parciales al actualizar el archivo adjunto o sus metadatos
  restauran archivo, metadata y campos del post antes de retirar el reemplazo.
  Tres regresiones cubren ambas direcciones del fallo parcial y un rollback que
  continúa fallando; en ese último caso los archivos nuevos se conservan para
  no dejar referencias persistidas apuntando a ficheros eliminados.

### Capabilities y fixtures

- Los fixtures de la taxonomía custom conceden explícitamente sus capabilities
  custom al usuario administrador de prueba.
- Las pruebas funcionales de páginas usan un administrador con las
  capabilities nativas de páginas; el rol `wp_mcp_agent` no se amplía.
- `create-page` exige ahora `edit_pages` tanto en el callback como en el
  `permission_callback` y en la matriz declarativa. Se añadió una regresión que
  confirma que `wp_mcp_agent` no puede crear páginas sin esa capability.
- Las pruebas de validación de uploads usan un administrador para alcanzar la
  validación SSRF/path; el test separado de mínimo privilegio sigue verificando
  que el agente no dispone de `upload_files`.

### Scheduling, revisiones y comentarios

- Scheduling persiste `post_date`, `post_date_gmt` y `edit_date=true`, evitando
  que `wp_update_post()` restaure la fecha del borrador y convierta `future` en
  `publish`.
- La restauración de revisiones usa un ID de revisión original creado de forma
  explícita, en vez de asumir el orden o contenido implícito del fixture.
- El test de respuesta a comentarios desactiva temporalmente el flood check,
  sin retirar esa protección de la implementación.
- `unspam` espera la semántica de core: WordPress restaura el estado previo del
  comentario, que en el fixture era `approve`.

## Estado de la Issue #7

El bloque de usuarios, roles y Application Passwords continúa verde y queda
incluido en la suite global. El baseline heredado de **5 errores y 23 fallos**
queda cerrado: la suite completa de WordPress 6.9 está verde en el entorno
registrado.

## Estado de la Issue #8

Las pruebas de navegación clásica, ubicaciones, elementos, `wp_navigation`,
templates, patrones sincronizados y permisos Site Editor quedan incluidas en
la suite global. La suite no verifica un despliegue MCP autenticado ni modifica
la instalación productiva.

Esto verifica el código y los tests locales; no demuestra despliegue en
producción ni redescubrimiento del catálogo MCP autenticado.
