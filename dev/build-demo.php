<?php
/**
 * Genera la demo estática en /demo a partir del plugin y de los datos de muestra.
 * Uso:  php dev/build-demo.php
 *
 * La demo no usa WordPress: el marcado se genera aquí una sola vez y las búsquedas
 * se resuelven en el navegador (demo/demo-api.js) sobre demo/datos.json.
 */

$RAIZ = dirname( __DIR__ );
$DEMO = "$RAIZ/demo";

$_SERVER['REQUEST_URI'] = '/';
$_GET                   = array();

ob_start();
require __DIR__ . '/harness/index.php'; // Define $html (shortcode) y $LOCALIZE, además de los datos.
ob_end_clean();

if ( ! is_dir( $DEMO ) ) {
	mkdir( $DEMO, 0775, true );
}

copy( "$RAIZ/me-buscador/assets/me-buscador.css", "$DEMO/me-buscador.css" );
copy( "$RAIZ/me-buscador/assets/me-buscador.js", "$DEMO/me-buscador.js" );

/* ---------- Datos para el navegador ---------- */

$config = meb_config();
$datos  = array(
	'tipos'      => $config['tipos'],
	'porPagina'  => $config['por_pagina'],
	'tax'        => array(
		'tema'     => $config['tax_tema'],
		'eje'      => $config['tax_eje'],
		'formato'  => $config['tax_formato'],
		'programa' => $config['tax_programa'],
		'evento'   => $config['tax_evento'],
	),
	'etiquetas'  => array(),
	'terminos'   => array(),
	'contenidos' => array(),
);
foreach ( $TYPES as $slug => $info ) {
	$datos['etiquetas'][ $slug ] = $info[1];
}
foreach ( $TERMS as $tax => $terms ) {
	$datos['terminos'][ $tax ] = array_values( array_map( function ( $t ) { return array( 'nombre' => $t->name, 'slug' => $t->slug, 'n' => $t->count ); }, $terms ) );
}
foreach ( $POSTS as $p ) {
	$datos['contenidos'][] = array(
		'tipo'    => $p->post_type,
		'titulo'  => $p->post_title,
		'fecha'   => substr( $p->post_date, 0, 10 ),
		'orden'   => $p->post_date,
		'fechaTx' => get_the_date( '', $p ),
		'texto'   => wp_trim_words( $p->post_excerpt, 24, '…' ),
		'img'     => $p->img,
		'evento'  => $p->evento,
		'url'     => get_permalink( $p ),
		'tax'     => (object) $p->tax,
	);
}
file_put_contents( "$DEMO/datos.json", json_encode( $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

/* ---------- Página ---------- */

$LOCALIZE['rest'] = 'api/me-buscador/v1/';
$html             = str_replace( 'action="/"', 'action="./"', $html );
$html             = preg_replace( '/href="\?meb_pag=2"/', 'href="./"', $html );
$meb              = json_encode( $LOCALIZE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$version          = substr( md5_file( "$DEMO/me-buscador.js" ) . md5_file( "$DEMO/me-buscador.css" ), 0, 8 );

$pagina = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Demo del buscador</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400..700&family=DM+Serif+Display&family=Inter:wght@400;600&display=swap">
<link rel="stylesheet" href="me-buscador.css?v=$version">
<style>
	:root { --primary-color: #f05025; --primary-font: 'DM Sans', sans-serif; --dark-blue-color: #101725; }
	* { box-sizing: border-box; }
	body { margin: 0; font-family: var(--primary-font); color: #111827; background: #fff; }
	.demo-aviso { padding: 8px 16px; background: #101725; color: rgba(255, 255, 255, 0.86); font-size: 13px; text-align: center; }
	.demo-aviso strong { color: #fff; }
	.demo-intro { max-width: 760px; margin: 0 auto; padding: 56px 24px 32px; text-align: center; }
	.demo-intro h1 { margin: 0 0 12px; font-family: 'DM Serif Display', Georgia, serif; font-size: clamp(30px, 5vw, 46px); font-weight: 400; line-height: 1.1; }
	.demo-intro p { margin: 0; font-size: 17px; line-height: 1.5; color: #667085; }
	.demo-wrap { max-width: 1240px; margin: 0 auto; padding: 0 24px 96px; }
	.demo-pie { padding: 24px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #667085; text-align: center; }
	.meb { --meb-sticky-top: 12px; }
	@media (max-width: 743px) {
		.demo-intro { padding: 36px 16px 24px; }
		.demo-wrap { padding: 0 16px 64px; }
	}
</style>
</head>
<body>
<div class="demo-aviso"><strong>Demostración.</strong> Funciona con una muestra de 122 contenidos; en el sitio usará el catálogo completo.</div>
<header class="demo-intro">
	<h1>Encuentra investigaciones, prensa y multimedia</h1>
	<p>Busca por palabra clave, acota por fecha y elige uno o varios temas.</p>
</header>
<main class="demo-wrap">
$html
</main>
<footer class="demo-pie">Prototipo del buscador. Las tarjetas enlazan al sitio de pruebas.</footer>
<script>window.MEB = $meb;</script>
<script src="demo-api.js?v=$version"></script>
<script src="me-buscador.js?v=$version"></script>
<script>document.addEventListener("DOMContentLoaded", function () { window.demoListo && window.demoListo(); });</script>
</body>
</html>

HTML;

file_put_contents( "$DEMO/index.html", $pagina );

echo 'Demo generada en demo/ (' . count( $datos['contenidos'] ) . " contenidos)\n";
