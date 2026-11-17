# ME Buscador

Plugin de WordPress con un buscador segmentado (palabra clave, fecha y tema) para sitios con tipos de contenido propios: autocompletado, filtros con conteo en vivo y resultados sin recargar la página.

## Contenido

- `me-buscador/` — el plugin.
- `me-buscador.zip` — el plugin empaquetado para subirlo desde wp-admin.
- `dev/harness/` — banco de pruebas que simula WordPress con PHP, sin instalación.
- `dev/mu-plugins/` — datos de muestra (`seed.json`) y un mu-plugin que replica los tipos de contenido en un WordPress local.

## Instalación

1. En wp-admin: Plugins → Añadir nuevo → Subir plugin, y sube `me-buscador.zip`.
2. Activa el plugin.
3. Inserta el shortcode `[me_buscador]` en una página.

## Shortcode

| Atributo | Valores | Uso |
|---|---|---|
| `resultados` | `si` (por defecto), `no` | Con `no` muestra solo la barra. |
| `url` | dirección | Página de resultados cuando `resultados="no"`. |
| `compacto` | `si` (por defecto), `no` | Barra compacta fija al hacer scroll. |
| `tema` | `claro` (por defecto), `oscuro` | Colores de las pestañas sobre fondos oscuros. |

Ejemplo de barra sola que envía a la página del buscador:

```
[me_buscador resultados="no" url="/buscador/"]
```

## Configuración

El plugin detecta los tipos `publicaciones`, `prensa`, `multimedia` y `eventos`, y las taxonomías `tema_investigacion`, `ejes_tematicos`, `publication_type` y `programa`. Si no existen, usa entradas y categorías. Todo se puede cambiar con el filtro `meb_config`.

Otros filtros: `meb_query_args` (consulta) y `meb_card_image` (imagen de la tarjeta).

## Banco de pruebas

```
php -S 127.0.0.1:9400 dev/harness/index.php
```

Abre `http://127.0.0.1:9400`. Con `?h_esc=flujo` ejecuta una prueba del flujo completo; otros escenarios están en `dev/harness/escenarios.js`.

## Demo estática

La carpeta `demo/` es una versión sin WordPress para enseñar el buscador: el marcado se genera una vez y las búsquedas corren en el navegador sobre `demo/datos.json`. Se regenera con:

```
php dev/build-demo.php
```

`vercel.json` publica esa carpeta tal cual, sin paso de compilación. `demo/demo-api.js` replica la lógica de búsqueda del plugin; si cambia una, hay que cambiar la otra.

## Estado

Versión 0.1.0. Probado en el banco de pruebas; falta validarlo dentro de un WordPress real y con WPML.
