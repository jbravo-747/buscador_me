<?php
/**
 * Solo para pruebas locales: replica los tipos de contenido y taxonomías del sitio
 * e importa una muestra de contenido desde seed.json. NO se instala en el sitio real.
 */

add_action(
	'init',
	function () {
		$tipos = array(
			'publicaciones' => array( 'Nuestras investigaciones', 'Investigación' ),
			'prensa'        => array( 'Prensa', 'Prensa' ),
			'multimedia'    => array( 'Multimedia', 'Multimedia' ),
			'eventos'       => array( 'Eventos', 'Evento' ),
			'equipo'        => array( 'Equipo', 'Equipo' ),
		);
		foreach ( $tipos as $slug => $labels ) {
			register_post_type(
				$slug,
				array(
					'labels'       => array(
						'name'          => $labels[0],
						'singular_name' => $labels[1],
					),
					'public'       => true,
					'show_in_rest' => true,
					'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				)
			);
		}

		$taxs = array(
			'ejes_tematicos'     => array( 'publicaciones', 'prensa', 'eventos', 'multimedia' ),
			'tema_investigacion' => array( 'publicaciones', 'prensa', 'multimedia' ),
			'publication_type'   => array( 'publicaciones' ),
			'programa'           => array( 'publicaciones', 'prensa', 'eventos', 'multimedia' ),
			'tipo_prensa'        => array( 'prensa' ),
			'multimedia_type'    => array( 'multimedia' ),
			'event_type'         => array( 'eventos' ),
		);
		foreach ( $taxs as $slug => $objetos ) {
			register_taxonomy(
				$slug,
				$objetos,
				array(
					'label'        => $slug,
					'public'       => true,
					'show_in_rest' => true,
				)
			);
		}
	},
	5
);

add_filter(
	'meb_card_image',
	function ( $img, $post ) {
		$remota = get_post_meta( $post->ID, '_meb_img', true );
		return $remota ? $remota : $img;
	},
	10,
	2
);

add_action(
	'init',
	function () {
		if ( get_option( 'meb_seeded' ) ) {
			return;
		}
		$data = json_decode( file_get_contents( __DIR__ . '/seed.json' ), true );
		if ( ! $data ) {
			return;
		}

		$mapa = array();
		foreach ( $data['terms'] as $tax => $terms ) {
			foreach ( $terms as $t ) {
				$r = wp_insert_term( $t['name'], $tax, array( 'slug' => $t['slug'] ) );
				if ( ! is_wp_error( $r ) ) {
					$mapa[ $tax ][ $t['id'] ] = (int) $r['term_id'];
				}
			}
		}

		foreach ( $data['posts'] as $p ) {
			$id = wp_insert_post(
				array(
					'post_type'    => $p['type'],
					'post_status'  => 'publish',
					'post_title'   => html_entity_decode( $p['title'], ENT_QUOTES, 'UTF-8' ),
					'post_name'    => $p['slug'],
					'post_date'    => str_replace( 'T', ' ', $p['date'] ),
					'post_excerpt' => wp_strip_all_tags( $p['excerpt'] ),
					'post_content' => $p['excerpt'],
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			if ( $p['img'] ) {
				update_post_meta( $id, '_meb_img', $p['img'] );
			}
			if ( ! empty( $p['evento'] ) ) {
				update_post_meta( $id, 'event_date', $p['evento']['fecha'] );
				update_post_meta( $id, 'event_time', $p['evento']['hora'] );
				update_post_meta( $id, 'event_place', $p['evento']['lugar'] );
			}
			foreach ( $p['tax'] as $tax => $ids ) {
				$nuevos = array();
				foreach ( $ids as $viejo ) {
					if ( isset( $mapa[ $tax ][ $viejo ] ) ) {
						$nuevos[] = $mapa[ $tax ][ $viejo ];
					}
				}
				if ( $nuevos ) {
					wp_set_object_terms( $id, $nuevos, $tax );
				}
			}
		}

		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Buscador',
				'post_name'    => 'buscador',
				'post_content' => '<!-- wp:shortcode -->[me_buscador]<!-- /wp:shortcode -->',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		update_option( 'meb_seeded', 1 );
	},
	20
);
