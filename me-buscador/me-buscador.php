<?php
/**
 * Plugin Name: ME Buscador
 * Description: Buscador segmentado (palabra clave, fecha y tema) con autocompletado, filtros y resultados en vivo. Shortcode: [me_buscador].
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: me-buscador
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEB_VERSION', '0.2.0' );

/* -------------------------------------------------------------------------
 * Configuración
 * ---------------------------------------------------------------------- */

/**
 * Tipos de contenido y taxonomías que usa el buscador.
 * Se detectan los del sitio de México Evalúa; se puede ajustar con el filtro `meb_config`.
 */
function meb_config() {
	static $config = null;
	if ( null !== $config ) {
		return $config;
	}

	$tipos = array_values( array_filter( array( 'publicaciones', 'prensa', 'multimedia', 'eventos' ), 'post_type_exists' ) );
	if ( ! $tipos ) {
		$tipos = array( 'post' );
	}

	$config = apply_filters(
		'meb_config',
		array(
			'tipos'         => $tipos,
			'tipos_persona' => array_values( array_filter( array( 'equipo' ), 'post_type_exists' ) ),
			'tax_tema'      => taxonomy_exists( 'tema_investigacion' ) ? 'tema_investigacion' : 'category',
			'tax_eje'       => taxonomy_exists( 'ejes_tematicos' ) ? 'ejes_tematicos' : '',
			'tax_formato'   => taxonomy_exists( 'publication_type' ) ? 'publication_type' : '',
			'tax_programa'  => taxonomy_exists( 'programa' ) ? 'programa' : '',
			'por_pagina'    => 12,
		)
	);

	return $config;
}

function meb_lang() {
	$lang = apply_filters( 'wpml_current_language', null );
	return $lang ? $lang : '';
}

function meb_terms( $taxonomy, $args = array() ) {
	if ( ! $taxonomy ) {
		return array();
	}
	$terms = get_terms(
		array_merge(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			),
			$args
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/* -------------------------------------------------------------------------
 * Parámetros de búsqueda
 * ---------------------------------------------------------------------- */

/**
 * Normaliza los parámetros (vienen de $_GET o de la petición REST).
 */
function meb_args( $src ) {
	$config = meb_config();

	$lista = function ( $key ) use ( $src ) {
		if ( ! isset( $src[ $key ] ) ) {
			return array();
		}
		$values = array();
		foreach ( (array) $src[ $key ] as $value ) {
			$values = array_merge( $values, explode( ',', (string) wp_unslash( $value ) ) );
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_title', $values ) ) ) );
	};

	$fecha = function ( $key ) use ( $src ) {
		$value = isset( $src[ $key ] ) ? sanitize_text_field( wp_unslash( $src[ $key ] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	};

	$tipo = isset( $src['meb_tipo'] ) ? sanitize_key( $src['meb_tipo'] ) : 'todo';
	if ( ! in_array( $tipo, $config['tipos'], true ) ) {
		$tipo = 'todo';
	}

	$orden = isset( $src['meb_orden'] ) ? sanitize_key( $src['meb_orden'] ) : '';
	if ( ! in_array( $orden, array( 'reciente', 'antiguo' ), true ) ) {
		$orden = '';
	}

	return array(
		'q'        => isset( $src['meb_q'] ) ? trim( sanitize_text_field( wp_unslash( $src['meb_q'] ) ) ) : '',
		'tipo'     => $tipo,
		'tema'     => $lista( 'meb_tema' ),
		'eje'      => $lista( 'meb_eje' ),
		'formato'  => $lista( 'meb_formato' ),
		'programa' => $lista( 'meb_programa' ),
		'desde'    => $fecha( 'meb_desde' ),
		'hasta'    => $fecha( 'meb_hasta' ),
		'orden'    => $orden,
		'pag'      => isset( $src['meb_pag'] ) ? max( 1, (int) $src['meb_pag'] ) : 1,
	);
}

function meb_query( $args, $solo_total = false ) {
	$config = meb_config();

	$query = array(
		'post_type'           => 'todo' === $args['tipo'] ? $config['tipos'] : array( $args['tipo'] ),
		'post_status'         => 'publish',
		'posts_per_page'      => $solo_total ? 1 : $config['por_pagina'],
		'paged'               => $solo_total ? 1 : $args['pag'],
		'ignore_sticky_posts' => true,
	);

	if ( $solo_total ) {
		$query['fields'] = 'ids';
	}

	if ( '' !== $args['q'] ) {
		$query['s'] = $args['q'];
	}

	$tax_query = array();
	$mapa      = array(
		'tema'     => $config['tax_tema'],
		'eje'      => $config['tax_eje'],
		'formato'  => $config['tax_formato'],
		'programa' => $config['tax_programa'],
	);
	foreach ( $mapa as $key => $taxonomy ) {
		if ( $taxonomy && $args[ $key ] ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $args[ $key ],
			);
		}
	}
	if ( $tax_query ) {
		$tax_query['relation'] = 'AND';
		$query['tax_query']    = $tax_query;
	}

	if ( $args['desde'] || $args['hasta'] ) {
		$rango = array( 'inclusive' => true );
		if ( $args['desde'] ) {
			$rango['after'] = $args['desde'];
		}
		if ( $args['hasta'] ) {
			$rango['before'] = $args['hasta'];
		}
		$query['date_query'] = array( $rango );
	}

	if ( 'antiguo' === $args['orden'] ) {
		$query['orderby'] = 'date';
		$query['order']   = 'ASC';
	} elseif ( 'reciente' === $args['orden'] || '' === $args['q'] ) {
		$query['orderby'] = 'date';
		$query['order']   = 'DESC';
	} else {
		$query['orderby'] = 'relevance';
	}

	return new WP_Query( apply_filters( 'meb_query_args', $query, $args ) );
}

/* -------------------------------------------------------------------------
 * Render de resultados
 * ---------------------------------------------------------------------- */

function meb_titulo_total( $total ) {
	if ( 0 === $total ) {
		return __( 'Sin resultados', 'me-buscador' );
	}
	/* translators: %s: número de resultados */
	return sprintf( _n( '%s resultado', '%s resultados', $total, 'me-buscador' ), number_format_i18n( $total ) );
}

function meb_card( $post ) {
	$config = meb_config();
	$type   = get_post_type_object( $post->post_type );
	$badge  = $type ? $type->labels->singular_name : '';

	if ( $config['tax_formato'] && is_object_in_taxonomy( $post->post_type, $config['tax_formato'] ) ) {
		$formatos = get_the_terms( $post, $config['tax_formato'] );
		if ( $formatos && ! is_wp_error( $formatos ) ) {
			$badge = $formatos[0]->name;
		}
	}

	$tema = '';
	if ( is_object_in_taxonomy( $post->post_type, $config['tax_tema'] ) ) {
		$temas = get_the_terms( $post, $config['tax_tema'] );
		if ( $temas && ! is_wp_error( $temas ) ) {
			$tema = $temas[0]->name;
		}
	}

	$img     = apply_filters( 'meb_card_image', get_the_post_thumbnail_url( $post, 'medium_large' ), $post );
	$texto   = $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content );
	$extract = wp_trim_words( wp_strip_all_tags( $texto ), 24, '…' );
	$meta    = array_filter( array( get_the_date( '', $post ), $tema ) );

	ob_start();
	?>
	<a class="meb-card" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
		<span class="meb-card-img<?php echo $img ? '' : ' is-empty'; ?>">
			<?php if ( $img ) : ?>
				<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" decoding="async">
			<?php endif; ?>
			<?php if ( $badge ) : ?>
				<span class="meb-badge"><?php echo esc_html( $badge ); ?></span>
			<?php endif; ?>
		</span>
		<span class="meb-card-title"><?php echo esc_html( get_the_title( $post ) ); ?></span>
		<span class="meb-card-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
		<?php if ( $extract ) : ?>
			<span class="meb-card-text"><?php echo esc_html( $extract ); ?></span>
		<?php endif; ?>
	</a>
	<?php
	return ob_get_clean();
}

/**
 * Ejecuta la búsqueda y devuelve total, tarjetas y si hay más páginas.
 */
function meb_resultados( $args ) {
	$query = meb_query( $args );
	$cards = '';
	foreach ( $query->posts as $post ) {
		$cards .= meb_card( $post );
	}

	if ( '' === $cards && 1 === $args['pag'] ) {
		$cards = '<p class="meb-empty">' . esc_html__( 'No encontramos contenido con esa combinación. Prueba con otra palabra o quita algún filtro.', 'me-buscador' ) . '</p>';
	}

	$total = (int) $query->found_posts;

	return array(
		'total'   => $total,
		'titulo'  => meb_titulo_total( $total ),
		'cards'   => $cards,
		'hay_mas' => $args['pag'] < (int) $query->max_num_pages,
		'pag'     => $args['pag'],
	);
}

/* -------------------------------------------------------------------------
 * REST
 * ---------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'me-buscador/v1',
			'/buscar',
			array(
				'methods'             => 'GET',
				'callback'            => 'meb_rest_buscar',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'me-buscador/v1',
			'/sugerir',
			array(
				'methods'             => 'GET',
				'callback'            => 'meb_rest_sugerir',
				'permission_callback' => '__return_true',
			)
		);
	}
);

function meb_rest_lang( $request ) {
	$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
	if ( $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}
}

function meb_rest_buscar( $request ) {
	meb_rest_lang( $request );
	$args = meb_args( $request->get_params() );

	if ( $request->get_param( 'solo_total' ) ) {
		$total = (int) meb_query( $args, true )->found_posts;
		return rest_ensure_response(
			array(
				'total'  => $total,
				'titulo' => meb_titulo_total( $total ),
			)
		);
	}

	return rest_ensure_response( meb_resultados( $args ) );
}

function meb_rest_sugerir( $request ) {
	meb_rest_lang( $request );
	$config = meb_config();
	$q      = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
	$items  = array();

	$term_item = function ( $term, $campo ) {
		return array(
			'clase'  => 'tema',
			'campo'  => $campo,
			'slug'   => $term->slug,
			'titulo' => $term->name,
			/* translators: %s: número de contenidos */
			'sub'    => sprintf( _n( 'Tema · %s contenido', 'Tema · %s contenidos', $term->count, 'me-buscador' ), number_format_i18n( $term->count ) ),
		);
	};

	if ( '' === $q ) {
		foreach ( meb_terms( $config['tax_tema'], array( 'number' => 7 ) ) as $term ) {
			$items[] = $term_item( $term, 'meb_tema' );
		}
		return rest_ensure_response(
			array(
				'titulo' => __( 'Temas más consultados', 'me-buscador' ),
				'items'  => $items,
			)
		);
	}

	foreach ( meb_terms( $config['tax_tema'], array( 'name__like' => $q, 'number' => 3 ) ) as $term ) {
		$items[] = $term_item( $term, 'meb_tema' );
	}
	foreach ( meb_terms( $config['tax_eje'], array( 'name__like' => $q, 'number' => 2 ) ) as $term ) {
		$items[] = $term_item( $term, 'meb_eje' );
	}

	$buscar_titulos = function ( $tipos, $cuantos ) use ( $q ) {
		if ( ! $tipos ) {
			return array();
		}
		$query = new WP_Query(
			array(
				'post_type'           => $tipos,
				'post_status'         => 'publish',
				's'                   => $q,
				'search_columns'      => array( 'post_title' ),
				'posts_per_page'      => $cuantos,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
			)
		);
		return $query->posts;
	};

	foreach ( $buscar_titulos( $config['tipos_persona'], 2 ) as $post ) {
		$items[] = array(
			'clase'  => 'persona',
			'titulo' => get_the_title( $post ),
			'sub'    => __( 'Equipo', 'me-buscador' ),
			'url'    => get_permalink( $post ),
		);
	}

	foreach ( $buscar_titulos( $config['tipos'], 5 ) as $post ) {
		$type    = get_post_type_object( $post->post_type );
		$items[] = array(
			'clase'  => 'contenido',
			'titulo' => get_the_title( $post ),
			'sub'    => ( $type ? $type->labels->singular_name . ' · ' : '' ) . get_the_date( '', $post ),
			'url'    => get_permalink( $post ),
		);
	}

	return rest_ensure_response(
		array(
			'titulo' => '',
			'items'  => $items,
		)
	);
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	function () {
		$base = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_register_style( 'me-buscador', $base . 'me-buscador.css', array(), MEB_VERSION );
		wp_register_script( 'me-buscador', $base . 'me-buscador.js', array(), MEB_VERSION, true );
	}
);

function meb_enqueue() {
	global $wp_locale;

	wp_enqueue_style( 'me-buscador' );
	wp_enqueue_script( 'me-buscador' );

	$meses        = array();
	$meses_cortos = array();
	for ( $m = 1; $m <= 12; $m++ ) {
		$mes            = $wp_locale->get_month( $m );
		$meses[]        = $mes;
		$meses_cortos[] = $wp_locale->get_month_abbrev( $mes );
	}
	$dias = array();
	for ( $d = 0; $d <= 6; $d++ ) {
		$dias[] = $wp_locale->get_weekday_initial( $wp_locale->get_weekday( $d ) );
	}

	wp_localize_script(
		'me-buscador',
		'MEB',
		array(
			'rest'        => esc_url_raw( rest_url( 'me-buscador/v1/' ) ),
			'lang'        => meb_lang(),
			'meses'       => $meses,
			'mesesCortos' => $meses_cortos,
			'dias'        => $dias,
			'i18n'        => array(
				'cualquierFecha' => __( 'Cualquier fecha', 'me-buscador' ),
				'cualquierTema'  => __( 'Cualquier tema', 'me-buscador' ),
				'cualquierCosa'  => __( 'Buscar contenido', 'me-buscador' ),
				'temas'          => __( '%d temas', 'me-buscador' ),
				'desde'          => __( 'Desde %s', 'me-buscador' ),
				'mostrar'        => __( 'Mostrar %s', 'me-buscador' ),
				'sinSugerencias' => __( 'Sin sugerencias. Presiona Enter para buscar «%s».', 'me-buscador' ),
				'error'          => __( 'No se pudo completar la búsqueda. Intenta de nuevo.', 'me-buscador' ),
				'quitar'         => __( 'Quitar filtro: %s', 'me-buscador' ),
				'quitarTodo'     => __( 'Quitar todo', 'me-buscador' ),
				'mesAnterior'    => __( 'Mes anterior', 'me-buscador' ),
				'mesSiguiente'   => __( 'Mes siguiente', 'me-buscador' ),
			),
		)
	);
}

/* -------------------------------------------------------------------------
 * Shortcode [me_buscador]
 * ---------------------------------------------------------------------- */

function meb_icon( $name ) {
	$paths = array(
		'buscar'        => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
		'filtros'       => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
		'cerrar'        => '<path d="M6 6l12 12M18 6 6 18"/>',
		'todo'          => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
		'publicaciones' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
		'prensa'        => '<path d="M4 5h13v14H6a2 2 0 0 1-2-2z"/><path d="M17 9h3v8a2 2 0 0 1-2 2M8 9h5M8 13h5"/>',
		'multimedia'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>',
		'eventos'       => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
		'post'          => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
	);
	$path  = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['publicaciones'];
	return '<svg class="meb-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function meb_chips( $taxonomy, $name, $seleccion ) {
	$html = '';
	foreach ( meb_terms( $taxonomy ) as $term ) {
		$html .= sprintf(
			'<label class="meb-chip"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s><span>%4$s</span></label>',
			esc_attr( $name ),
			esc_attr( $term->slug ),
			checked( in_array( $term->slug, $seleccion, true ), true, false ),
			esc_html( $term->name )
		);
	}
	return $html;
}

add_shortcode( 'me_buscador', 'meb_shortcode' );

function meb_shortcode( $atts ) {
	static $instancia = 0;
	$instancia++;

	$atts = shortcode_atts(
		array(
			'resultados' => 'si',   // "si" muestra resultados debajo; "no" solo la barra.
			'url'        => '',     // Página de resultados cuando resultados="no".
			'compacto'   => 'si',   // Barra compacta fija al hacer scroll.
			'tema'       => 'claro', // "oscuro" para fondos oscuros.
		),
		$atts,
		'me_buscador'
	);

	$config   = meb_config();
	$args     = meb_args( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$con_res  = 'no' !== $atts['resultados'];
	$action   = $atts['url'] ? $atts['url'] : get_permalink();
	$id       = 'meb-' . $instancia;

	meb_enqueue();

	$tabs = array( 'todo' => __( 'Todo', 'me-buscador' ) );
	foreach ( $config['tipos'] as $tipo ) {
		$type          = get_post_type_object( $tipo );
		$tabs[ $tipo ] = $type ? $type->labels->name : $tipo;
	}

	$ordenes = array(
		''         => __( 'Recomendado', 'me-buscador' ),
		'reciente' => __( 'Más recientes', 'me-buscador' ),
		'antiguo'  => __( 'Más antiguos', 'me-buscador' ),
	);

	$clases = array( 'meb' );
	if ( 'oscuro' === $atts['tema'] ) {
		$clases[] = 'meb--oscuro';
	}

	ob_start();
	?>
	<div class="<?php echo esc_attr( implode( ' ', $clases ) ); ?>" data-meb data-ajax="<?php echo $con_res ? '1' : '0'; ?>" data-compacto="<?php echo 'no' === $atts['compacto'] ? '0' : '1'; ?>">
		<form class="meb-form" role="search" method="get" action="<?php echo esc_url( $action ); ?>">

			<div class="meb-tabs">
				<?php foreach ( $tabs as $valor => $label ) : ?>
					<label class="meb-tab">
						<input type="radio" name="meb_tipo" value="<?php echo esc_attr( $valor ); ?>" <?php checked( $args['tipo'], $valor ); ?>>
						<span><?php echo meb_icon( $valor ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="meb-barwrap">
				<div class="meb-bar">
					<div class="meb-seg meb-seg--q" data-seg="q">
						<label class="meb-label" for="<?php echo esc_attr( $id ); ?>-q"><?php esc_html_e( 'Buscar', 'me-buscador' ); ?></label>
						<input class="meb-input" id="<?php echo esc_attr( $id ); ?>-q" type="text" name="meb_q" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Publicaciones, temas, expertos', 'me-buscador' ); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $id ); ?>-sug">
						<button type="button" class="meb-clear" aria-label="<?php esc_attr_e( 'Limpiar búsqueda', 'me-buscador' ); ?>" hidden><?php echo meb_icon( 'cerrar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
					<span class="meb-div" aria-hidden="true"></span>
					<button type="button" class="meb-seg" data-seg="fecha" aria-expanded="false">
						<span class="meb-label"><?php esc_html_e( 'Fecha', 'me-buscador' ); ?></span>
						<span class="meb-value" data-valor="fecha"></span>
					</button>
					<span class="meb-div" aria-hidden="true"></span>
					<button type="button" class="meb-seg meb-seg--ultimo" data-seg="tema" aria-expanded="false">
						<span class="meb-label"><?php esc_html_e( 'Tema', 'me-buscador' ); ?></span>
						<span class="meb-value" data-valor="tema"></span>
					</button>
					<button type="submit" class="meb-submit" aria-label="<?php esc_attr_e( 'Buscar', 'me-buscador' ); ?>">
						<?php echo meb_icon( 'buscar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="meb-submit-text"><?php esc_html_e( 'Buscar', 'me-buscador' ); ?></span>
					</button>
				</div>

				<div class="meb-panel meb-panel--q" data-panel="q" hidden>
					<p class="meb-panel-title" data-sug-titulo></p>
					<ul class="meb-sug" id="<?php echo esc_attr( $id ); ?>-sug" role="listbox"></ul>
				</div>

				<div class="meb-panel meb-panel--fecha" data-panel="fecha" hidden>
					<div class="meb-switch" role="tablist">
						<button type="button" class="is-on" data-modo="calendario"><?php esc_html_e( 'Calendario', 'me-buscador' ); ?></button>
						<button type="button" data-modo="periodo"><?php esc_html_e( 'Periodo', 'me-buscador' ); ?></button>
					</div>
					<div class="meb-cal" data-vista="calendario"></div>
					<div class="meb-periodos" data-vista="periodo" hidden>
						<button type="button" class="meb-pill" data-periodo="mes:1"><?php esc_html_e( 'Último mes', 'me-buscador' ); ?></button>
						<button type="button" class="meb-pill" data-periodo="mes:3"><?php esc_html_e( 'Últimos 3 meses', 'me-buscador' ); ?></button>
						<button type="button" class="meb-pill" data-periodo="mes:6"><?php esc_html_e( 'Últimos 6 meses', 'me-buscador' ); ?></button>
						<button type="button" class="meb-pill" data-periodo="anio:actual"><?php esc_html_e( 'Este año', 'me-buscador' ); ?></button>
						<?php for ( $anio = (int) current_time( 'Y' ) - 1; $anio >= (int) current_time( 'Y' ) - 6; $anio-- ) : ?>
							<button type="button" class="meb-pill" data-periodo="anio:<?php echo esc_attr( $anio ); ?>"><?php echo esc_html( $anio ); ?></button>
						<?php endfor; ?>
					</div>
					<div class="meb-panel-foot">
						<button type="button" class="meb-link" data-accion="limpiar-fecha"><?php esc_html_e( 'Borrar fechas', 'me-buscador' ); ?></button>
					</div>
				</div>

				<div class="meb-panel meb-panel--tema" data-panel="tema" hidden>
					<?php if ( $config['tax_eje'] ) : ?>
						<p class="meb-panel-title"><?php esc_html_e( 'Ejes temáticos', 'me-buscador' ); ?></p>
						<div class="meb-rows">
							<?php foreach ( meb_terms( $config['tax_eje'] ) as $term ) : ?>
								<label class="meb-row">
									<span class="meb-row-text">
										<span class="meb-row-title"><?php echo esc_html( $term->name ); ?></span>
										<span class="meb-row-sub"><?php echo esc_html( sprintf( _n( '%s contenido', '%s contenidos', $term->count, 'me-buscador' ), number_format_i18n( $term->count ) ) ); ?></span>
									</span>
									<input type="checkbox" name="meb_eje[]" value="<?php echo esc_attr( $term->slug ); ?>" data-nombre="<?php echo esc_attr( $term->name ); ?>" <?php checked( in_array( $term->slug, $args['eje'], true ) ); ?>>
									<span class="meb-check" aria-hidden="true"></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<p class="meb-panel-title"><?php esc_html_e( 'Temas de investigación', 'me-buscador' ); ?></p>
					<div class="meb-chips" data-grupo="tema">
						<?php echo meb_chips( $config['tax_tema'], 'meb_tema', $args['tema'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			</div>

			<input type="hidden" name="meb_desde" value="<?php echo esc_attr( $args['desde'] ); ?>">
			<input type="hidden" name="meb_hasta" value="<?php echo esc_attr( $args['hasta'] ); ?>">

			<div class="meb-modal" data-modal hidden>
				<div class="meb-modal-box" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $id ); ?>-filtros">
					<div class="meb-modal-head">
						<h2 id="<?php echo esc_attr( $id ); ?>-filtros"><?php esc_html_e( 'Filtros', 'me-buscador' ); ?></h2>
						<button type="button" class="meb-modal-close" data-accion="cerrar-modal" aria-label="<?php esc_attr_e( 'Cerrar', 'me-buscador' ); ?>"><?php echo meb_icon( 'cerrar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
					<div class="meb-modal-body">
						<?php if ( $config['tax_formato'] ) : ?>
							<h3><?php esc_html_e( 'Tipo de publicación', 'me-buscador' ); ?></h3>
							<div class="meb-chips"><?php echo meb_chips( $config['tax_formato'], 'meb_formato', $args['formato'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
						<?php if ( $config['tax_programa'] ) : ?>
							<h3><?php esc_html_e( 'Programa', 'me-buscador' ); ?></h3>
							<div class="meb-chips"><?php echo meb_chips( $config['tax_programa'], 'meb_programa', $args['programa'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
						<h3><?php esc_html_e( 'Ordenar por', 'me-buscador' ); ?></h3>
						<div class="meb-switch meb-switch--radio">
							<?php foreach ( $ordenes as $valor => $label ) : ?>
								<label><input type="radio" name="meb_orden" value="<?php echo esc_attr( $valor ); ?>" <?php checked( $args['orden'], $valor ); ?>><span><?php echo esc_html( $label ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="meb-modal-foot">
						<button type="button" class="meb-link" data-accion="quitar-filtros"><?php esc_html_e( 'Quitar filtros', 'me-buscador' ); ?></button>
						<button type="submit" class="meb-btn-dark" data-mostrar><?php esc_html_e( 'Mostrar resultados', 'me-buscador' ); ?></button>
					</div>
				</div>
			</div>
		</form>

		<?php if ( $con_res ) : ?>
			<?php $res = meb_resultados( $args ); ?>
			<div class="meb-results" aria-live="polite">
				<div class="meb-results-head">
					<h2 class="meb-count" data-count><?php echo esc_html( $res['titulo'] ); ?></h2>
					<button type="button" class="meb-filtros-btn" data-accion="abrir-modal">
						<?php echo meb_icon( 'filtros' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Filtros', 'me-buscador' ); ?>
						<span class="meb-filtros-n" data-filtros-n hidden></span>
					</button>
				</div>
				<div class="meb-activos" data-activos hidden></div>
				<div class="meb-grid" data-grid><?php echo $res['cards']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="meb-more-wrap">
					<a class="meb-more" data-more href="<?php echo esc_url( add_query_arg( 'meb_pag', $args['pag'] + 1 ) ); ?>" <?php echo $res['hay_mas'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Mostrar más', 'me-buscador' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<button type="button" class="meb-compact" data-compact hidden>
			<span data-compact-q></span>
			<span class="meb-div" aria-hidden="true"></span>
			<span data-compact-fecha></span>
			<span class="meb-div" aria-hidden="true"></span>
			<span data-compact-tema></span>
			<span class="meb-compact-ico"><?php echo meb_icon( 'buscar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</button>
	</div>
	<?php
	return ob_get_clean();
}
