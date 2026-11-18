<?php
/**
 * Banco de pruebas SIN WordPress: simula las funciones de WP que usa el plugin
 * con los datos de ../mu-plugins/seed.json. Sirve para revisar marcado, estilos y
 * comportamiento en el navegador. Uso:  php -S 127.0.0.1:9400 dev/harness/index.php
 */

define( 'ABSPATH', __DIR__ );
$PLUGIN = dirname( __DIR__, 2 ) . '/me-buscador';
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( preg_match( '#^/assets/([a-z-]+\.(css|js))$#', $path, $m ) ) {
	header( 'Content-Type: ' . ( 'css' === $m[2] ? 'text/css' : 'application/javascript' ) );
	header( 'Cache-Control: no-store' );
	readfile( "$PLUGIN/assets/{$m[1]}" );
	return;
}

/* ---------- Datos ---------- */

if ( '/escenarios.js' === $path ) {
	header( 'Content-Type: application/javascript' );
	readfile( __DIR__ . '/escenarios.js' );
	return;
}

$SEED  = json_decode( file_get_contents( dirname( __DIR__ ) . '/mu-plugins/seed.json' ), true );
$TERMS = array(); // tax => slug => objeto
$BYID  = array(); // tax => id => slug
foreach ( $SEED['terms'] as $tax => $terms ) {
	foreach ( $terms as $t ) {
		$TERMS[ $tax ][ $t['slug'] ] = (object) array( 'name' => $t['name'], 'slug' => $t['slug'], 'count' => 0 );
		$BYID[ $tax ][ $t['id'] ]    = $t['slug'];
	}
}
$POSTS = array();
foreach ( $SEED['posts'] as $i => $p ) {
	$tax = array();
	foreach ( $p['tax'] as $name => $ids ) {
		foreach ( $ids as $id ) {
			if ( isset( $BYID[ $name ][ $id ] ) ) {
				$tax[ $name ][] = $BYID[ $name ][ $id ];
				$TERMS[ $name ][ $BYID[ $name ][ $id ] ]->count++;
			}
		}
	}
	$POSTS[] = (object) array(
		'ID'           => $i + 1,
		'post_type'    => $p['type'],
		'post_title'   => html_entity_decode( $p['title'], ENT_QUOTES, 'UTF-8' ),
		'post_date'    => str_replace( 'T', ' ', $p['date'] ),
		'post_excerpt' => trim( html_entity_decode( strip_tags( $p['excerpt'] ), ENT_QUOTES, 'UTF-8' ) ),
		'post_content' => '',
		'img'          => $p['img'],
		'tax'          => $tax,
		'slug'         => $p['slug'],
		'evento'       => isset( $p['evento'] ) ? $p['evento'] : null,
	);
}
$TYPES = array(
	'publicaciones' => array( 'Nuestras investigaciones', 'Investigación', array( 'ejes_tematicos', 'tema_investigacion', 'publication_type', 'programa' ) ),
	'prensa'        => array( 'Prensa', 'Prensa', array( 'ejes_tematicos', 'tema_investigacion', 'programa' ) ),
	'multimedia'    => array( 'Multimedia', 'Multimedia', array( 'ejes_tematicos', 'tema_investigacion', 'programa' ) ),
	'eventos'       => array( 'Eventos', 'Evento', array( 'ejes_tematicos', 'programa', 'event_type' ) ),
	'equipo'        => array( 'Equipo', 'Equipo', array() ),
);

/* ---------- Simulación de WordPress ---------- */

function plano_h( $s ) { return strtolower( iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ) ); }

class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 0;
	public function __construct( $q ) {
		global $POSTS;
		$out = array();
		foreach ( $POSTS as $p ) {
			if ( ! in_array( $p->post_type, (array) $q['post_type'], true ) ) { continue; }
			if ( ! empty( $q['s'] ) ) {
				$hay = plano_h( $p->post_title . ( isset( $q['search_columns'] ) ? '' : ' ' . $p->post_excerpt ) );
				foreach ( preg_split( '/\s+/', plano_h( $q['s'] ) ) as $w ) {
					if ( '' !== $w && false === strpos( $hay, $w ) ) { continue 2; }
				}
			}
			foreach ( isset( $q['tax_query'] ) ? $q['tax_query'] : array() as $k => $tq ) {
				if ( 'relation' === $k ) { continue; }
				$tiene = isset( $p->tax[ $tq['taxonomy'] ] ) ? $p->tax[ $tq['taxonomy'] ] : array();
				if ( ! array_intersect( $tiene, $tq['terms'] ) ) { continue 2; }
			}
			if ( ! empty( $q['date_query'][0] ) ) {
				$d = substr( $p->post_date, 0, 10 );
				$r = $q['date_query'][0];
				if ( ( isset( $r['after'] ) && $d < $r['after'] ) || ( isset( $r['before'] ) && $d > $r['before'] ) ) { continue; }
			}
			$out[] = $p;
		}
		$asc = isset( $q['order'] ) && 'ASC' === $q['order'];
		usort( $out, function ( $a, $b ) use ( $asc ) { return $asc ? strcmp( $a->post_date, $b->post_date ) : strcmp( $b->post_date, $a->post_date ); } );
		$pp                  = $q['posts_per_page'];
		$this->found_posts   = count( $out );
		$this->max_num_pages = (int) ceil( count( $out ) / $pp );
		$this->posts         = array_slice( $out, ( max( 1, isset( $q['paged'] ) ? $q['paged'] : 1 ) - 1 ) * $pp, $pp );
	}
}

class Fake_Request {
	public function get_params() { return $_GET; }
	public function get_param( $k ) { return isset( $_GET[ $k ] ) ? $_GET[ $k ] : null; }
}

class Fake_Locale {
	private $m = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
	private $d = array( 'D', 'L', 'M', 'M', 'J', 'V', 'S' );
	public function get_month( $n ) { return $this->m[ $n - 1 ]; }
	public function get_month_abbrev( $name ) { return substr( $name, 0, 3 ); }
	public function get_weekday( $n ) { return $n; }
	public function get_weekday_initial( $n ) { return $this->d[ $n ]; }
}
$wp_locale = new Fake_Locale();
$LOCALIZE  = array();

function add_action() {}
function add_shortcode() {}
function do_action() {}
function apply_filters( $tag, $value ) {
	if ( 'meb_card_image' === $tag ) { $post = func_get_arg( 2 ); return $post->img; }
	if ( 'meb_card_evento' === $tag ) { $post = func_get_arg( 2 ); return $post->evento ? $post->evento : $value; }
	return $value;
}
function post_type_exists( $t ) { global $TYPES; return isset( $TYPES[ $t ] ); }
function taxonomy_exists( $t ) { global $TERMS; return isset( $TERMS[ $t ] ); }
function get_post_type_object( $t ) {
	global $TYPES;
	return isset( $TYPES[ $t ] ) ? (object) array( 'labels' => (object) array( 'name' => $TYPES[ $t ][0], 'singular_name' => $TYPES[ $t ][1] ) ) : null;
}
function is_object_in_taxonomy( $type, $tax ) { global $TYPES; return in_array( $tax, $TYPES[ $type ][2], true ); }
function get_the_terms( $post, $tax ) {
	global $TERMS;
	if ( empty( $post->tax[ $tax ] ) ) { return false; }
	return array_map( function ( $s ) use ( $TERMS, $tax ) { return $TERMS[ $tax ][ $s ]; }, $post->tax[ $tax ] );
}
function get_terms( $a ) {
	global $TERMS;
	$out = array();
	foreach ( (array) $a['taxonomy'] as $tax ) {
		foreach ( isset( $TERMS[ $tax ] ) ? $TERMS[ $tax ] : array() as $t ) {
			if ( ! $t->count ) { continue; }
			if ( ! empty( $a['name__like'] ) && false === strpos( plano_h( $t->name ), plano_h( $a['name__like'] ) ) ) { continue; }
			$out[] = $t;
		}
	}
	usort( $out, function ( $x, $y ) { return $y->count - $x->count; } );
	return empty( $a['number'] ) ? $out : array_slice( $out, 0, $a['number'] );
}
function is_wp_error() { return false; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_title( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function esc_url_raw( $v ) { return $v; }
function __( $v ) { return $v; }
function esc_html__( $v ) { return esc_html( $v ); }
function esc_html_e( $v ) { echo esc_html( $v ); }
function esc_attr_e( $v ) { echo esc_html( $v ); }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function number_format_i18n( $n ) { return number_format( $n ); }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function shortcode_atts( $defaults, $atts ) { return array_merge( $defaults, (array) $atts ); }
function get_permalink( $post = null ) { return $post ? 'https://mexicoevalua-dev.virk.io/' . $post->post_type . '/' . $post->slug . '/' : '/'; }
function get_the_title( $post ) { return $post->post_title; }
function get_the_date( $f, $post ) {
	global $wp_locale;
	$t = strtotime( $post->post_date );
	return (int) date( 'j', $t ) . ' de ' . $wp_locale->get_month( (int) date( 'n', $t ) ) . ' de ' . date( 'Y', $t );
}
function get_the_post_thumbnail_url() { return ''; }
function get_post_meta() { return ''; }
function strip_shortcodes( $v ) { return $v; }
function wp_strip_all_tags( $v ) { return trim( strip_tags( $v ) ); }
function wp_trim_words( $text, $n, $more ) {
	$w = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	return count( $w ) > $n ? implode( ' ', array_slice( $w, 0, $n ) ) . $more : implode( ' ', $w );
}
function current_time( $f ) { return date( $f ); }
function add_query_arg( $k, $v ) { return '?' . http_build_query( array_merge( $_GET, array( $k => $v ) ) ); }
function rest_url( $p ) { return '/wp-json/' . $p; }
function rest_ensure_response( $d ) { return $d; }
function plugin_dir_url() { return '/'; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script( $h, $name, $data ) { global $LOCALIZE; $LOCALIZE = $data; }

require "$PLUGIN/me-buscador.php";

/* ---------- Rutas ---------- */

if ( preg_match( '#^/wp-json/me-buscador/v1/(buscar|sugerir)/?$#', $path, $m ) ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$fn = 'meb_rest_' . $m[1];
	echo json_encode( $fn( new Fake_Request() ), JSON_UNESCAPED_UNICODE );
	return;
}

$atts = array();
foreach ( array( 'resultados', 'url', 'compacto', 'tema' ) as $k ) {
	if ( isset( $_GET[ "h_$k" ] ) ) { $atts[ $k ] = $_GET[ "h_$k" ]; }
}
$html   = meb_shortcode( $atts );
$oscuro = isset( $atts['tema'] ) && 'oscuro' === $atts['tema'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ME Buscador — banco de pruebas</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400..700&family=DM+Serif+Display&family=Inter:wght@400;600&display=swap">
<link rel="stylesheet" href="/assets/me-buscador.css">
<style>
	:root { --primary-color: #f05025; --primary-font: 'DM Sans', sans-serif; --dark-blue-color: #101725; }
	body { margin: 0; font-family: var(--primary-font); background: #fff; }
	.hero { padding: 56px 24px 64px; background: <?php echo $oscuro ? '#101725' : '#f9fafb'; ?>; border-bottom: 1px solid #e5e7eb; }
	.hero h1 { margin: 0 0 28px; text-align: center; font-size: 34px; color: <?php echo $oscuro ? '#fff' : '#111827'; ?>; }
	.wrap { max-width: 1240px; margin: 0 auto; padding: 0 24px 80px; }
	.wrap { padding-top: 40px; }
</style>
</head>
<body>
<div class="wrap"><?php echo $html; ?></div>
<script>window.MEB = <?php echo json_encode( $LOCALIZE, JSON_UNESCAPED_UNICODE ); ?>;</script>
<script src="/assets/me-buscador.js"></script>
<?php if ( isset( $_GET['h_esc'] ) ) : ?><script src="/escenarios.js"></script><?php endif; ?>
</body>
</html>
