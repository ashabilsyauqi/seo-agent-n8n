<?php
/**
 * Plugin Name:       SEO Bridge for AI Agent
 * Description:       Jembatan aman antara SEO AI Agent dan WordPress. Hanya aksi SEO yang di-whitelist (title/meta, internal link, alt gambar, FAQ schema, redirect, draft) — setiap perubahan dicatat dan bisa di-rollback. Tidak ada eksekusi PHP/SQL bebas.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Difitech
 * License:           GPL-2.0-or-later
 * Text Domain:       seo-bridge
 *
 * Autentikasi: WordPress Application Password (Users → Profile → Application Passwords)
 * milik user khusus agent dengan role Editor. Izin dicek per post dengan current_user_can().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEO_BRIDGE_VERSION', '1.0.0' );
define( 'SEO_BRIDGE_NS', 'seo-bridge/v1' );
define( 'SEO_BRIDGE_MAX_LOG', 300 );

/* ======================================================================
 * Pengaturan
 * ==================================================================== */

function seo_bridge_actions() {
	return array(
		'seo_meta'      => 'Ubah SEO title & meta description',
		'internal_link' => 'Sisipkan internal link',
		'image_alt'     => 'Ubah alt text gambar',
		'faq'           => 'Pasang / ubah FAQ schema (JSON-LD)',
		'redirect'      => 'Buat redirect 301/302',
		'draft'         => 'Buat artikel baru sebagai DRAFT',
	);
}

function seo_bridge_settings() {
	$defaults = array(
		'read_only' => 0,
		'enabled'   => array_keys( seo_bridge_actions() ),
	);
	$saved = get_option( 'seo_bridge_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function seo_bridge_action_enabled( $action ) {
	$s = seo_bridge_settings();
	return ! $s['read_only'] && in_array( $action, (array) $s['enabled'], true );
}

/* ======================================================================
 * Deteksi plugin SEO & builder
 * ==================================================================== */

function seo_bridge_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'seopress';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'aioseo';
	}
	return 'none';
}

function seo_bridge_meta_keys( $plugin ) {
	switch ( $plugin ) {
		case 'yoast':
			return array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' );
		case 'rankmath':
			return array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' );
		case 'seopress':
			return array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw' );
		default:
			// Tanpa plugin SEO: plugin ini sendiri yang mencetak title & meta description.
			return array( '_seo_bridge_title', '_seo_bridge_description', '_seo_bridge_focus_kw' );
	}
}

function seo_bridge_is_elementor( $post_id ) {
	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

function seo_bridge_aioseo_table() {
	global $wpdb;
	return $wpdb->prefix . 'aioseo_posts';
}

function seo_bridge_get_seo( $post_id ) {
	$plugin = seo_bridge_seo_plugin();
	if ( 'aioseo' === $plugin ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT title, description FROM ' . seo_bridge_aioseo_table() . ' WHERE post_id = %d', $post_id ), ARRAY_A ); // phpcs:ignore
		return array(
			'title'         => $row ? (string) $row['title'] : '',
			'description'   => $row ? (string) $row['description'] : '',
			'focus_keyword' => '',
		);
	}
	list( $tk, $dk, $kk ) = seo_bridge_meta_keys( $plugin );
	return array(
		'title'         => (string) get_post_meta( $post_id, $tk, true ),
		'description'   => (string) get_post_meta( $post_id, $dk, true ),
		'focus_keyword' => (string) get_post_meta( $post_id, $kk, true ),
	);
}

function seo_bridge_set_seo( $post_id, $title, $description ) {
	$plugin = seo_bridge_seo_plugin();
	if ( 'aioseo' === $plugin ) {
		global $wpdb;
		$table  = seo_bridge_aioseo_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore
		$data   = array();
		if ( null !== $title ) {
			$data['title'] = $title;
		}
		if ( null !== $description ) {
			$data['description'] = $description;
		}
		if ( ! $data ) {
			return;
		}
		$data['updated'] = current_time( 'mysql', true );
		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'post_id' => $post_id ) );
		} else {
			$data['post_id'] = $post_id;
			$data['created'] = $data['updated'];
			$wpdb->insert( $table, $data );
		}
		return;
	}
	list( $tk, $dk ) = seo_bridge_meta_keys( $plugin );
	if ( null !== $title ) {
		update_post_meta( $post_id, $tk, $title );
	}
	if ( null !== $description ) {
		update_post_meta( $post_id, $dk, $description );
	}
	clean_post_cache( $post_id );
	if ( 'yoast' === $plugin ) {
		seo_bridge_refresh_yoast( $post_id );
	}
}

/** Yoast merender dari tabel indexable; bangun ulang supaya title/meta baru langsung tampil. */
function seo_bridge_refresh_yoast( $post_id ) {
	try {
		if ( function_exists( 'YoastSEO' ) && class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' ) ) {
			$builder = YoastSEO()->classes->get( '\Yoast\WP\SEO\Builders\Indexable_Builder' );
			$repo    = YoastSEO()->classes->get( '\Yoast\WP\SEO\Repositories\Indexable_Repository' );
			$current = $repo->find_by_id_and_type( $post_id, 'post', false );
			$builder->build_for_id_and_type( $post_id, 'post', $current ? $current : false );
		}
	} catch ( \Throwable $e ) {
		// Fallback: indexable akan diperbarui Yoast saat post disimpan berikutnya.
	}
}

/* ======================================================================
 * Change log + backup (untuk audit & rollback)
 * ==================================================================== */

function seo_bridge_log_add( $entry ) {
	$log   = get_option( 'seo_bridge_changes', array() );
	$log   = is_array( $log ) ? $log : array();
	$entry = array_merge(
		array(
			'id'     => 'chg_' . wp_generate_password( 12, false, false ),
			'time'   => gmdate( 'c' ),
			'user'   => wp_get_current_user()->user_login,
			'status' => 'applied',
		),
		$entry
	);
	array_unshift( $log, $entry );
	$dropped = array_slice( $log, SEO_BRIDGE_MAX_LOG );
	foreach ( $dropped as $old ) {
		if ( ! empty( $old['backup_key'] ) && ! empty( $old['post_id'] ) ) {
			delete_post_meta( (int) $old['post_id'], $old['backup_key'] );
		}
	}
	update_option( 'seo_bridge_changes', array_slice( $log, 0, SEO_BRIDGE_MAX_LOG ), false );
	return $entry;
}

function seo_bridge_log_get( $id ) {
	foreach ( (array) get_option( 'seo_bridge_changes', array() ) as $entry ) {
		if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
			return $entry;
		}
	}
	return null;
}

function seo_bridge_log_update( $id, $fields ) {
	$log = (array) get_option( 'seo_bridge_changes', array() );
	foreach ( $log as $i => $entry ) {
		if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
			$log[ $i ] = array_merge( $entry, $fields );
		}
	}
	update_option( 'seo_bridge_changes', $log, false );
}

/* ======================================================================
 * Helper konten
 * ==================================================================== */

function seo_bridge_resolve_post( $request ) {
	$id = (int) $request->get_param( 'post_id' );
	if ( ! $id && $request->get_param( 'url' ) ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		$id  = url_to_postid( $url );
		if ( ! $id && untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			$id = (int) get_option( 'page_on_front' );
		}
	}
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'seo_bridge_not_found', 'Post/halaman tidak ditemukan untuk URL/ID tersebut.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'seo_bridge_forbidden', 'User agent tidak punya izin mengedit post ini.', array( 'status' => 403 ) );
	}
	return $post;
}

/**
 * Sisipkan <a href> pada kemunculan pertama $anchor di teks HTML yang belum ber-link
 * dan bukan di dalam heading/tag. Return HTML baru, atau null bila anchor tidak ditemukan.
 */
function seo_bridge_link_html( $html, $anchor, $url ) {
	$parts     = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$blocked   = 0; // di dalam <a>, <h1-6>, <script>, <style>, <code>, <pre>
	$done      = false;
	$block_tag = '/^<\s*(\/?)\s*(a|h[1-6]|script|style|code|pre|button)\b/i';
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( preg_match( $block_tag, $part, $m ) ) {
				$blocked += ( '/' === $m[1] ) ? -1 : 1;
				$blocked  = max( 0, $blocked );
			}
			continue;
		}
		if ( $done || $blocked > 0 ) {
			continue;
		}
		$pattern = '/(?<![\p{L}\p{N}])(' . preg_quote( $anchor, '/' ) . ')(?![\p{L}\p{N}])/iu';
		if ( preg_match( $pattern, $part ) ) {
			$parts[ $i ] = preg_replace( $pattern, '<a href="' . esc_url( $url ) . '">$1</a>', $part, 1 );
			$done        = true;
		}
	}
	return $done ? implode( '', $parts ) : null;
}

function seo_bridge_has_link( $snapshot, $url ) {
	$haystack = $snapshot['value'];
	if ( 'elementor' === $snapshot['type'] ) {
		$decoded  = json_decode( $haystack, true );
		$haystack = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : $haystack;
		$haystack = str_replace( '\\"', '"', $haystack );
	}
	$pattern = '/href=["\']' . preg_quote( untrailingslashit( $url ), '/' ) . '\/?["\'#?]/i';
	return (bool) preg_match( $pattern, $haystack );
}

function seo_bridge_link_elementor( &$elements, $anchor, $url ) {
	foreach ( $elements as &$el ) {
		if ( isset( $el['widgetType'] ) && 'text-editor' === $el['widgetType'] && isset( $el['settings']['editor'] ) ) {
			$new = seo_bridge_link_html( $el['settings']['editor'], $anchor, $url );
			if ( null !== $new ) {
				$el['settings']['editor'] = $new;
				return true;
			}
		}
		if ( ! empty( $el['elements'] ) && seo_bridge_link_elementor( $el['elements'], $anchor, $url ) ) {
			return true;
		}
	}
	return false;
}

function seo_bridge_clear_elementor_cache( $post_id ) {
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	delete_post_meta( $post_id, '_elementor_element_cache' );
	clean_post_cache( $post_id );
}

function seo_bridge_get_content_snapshot( $post ) {
	if ( seo_bridge_is_elementor( $post->ID ) ) {
		return array( 'type' => 'elementor', 'value' => (string) get_post_meta( $post->ID, '_elementor_data', true ) );
	}
	return array( 'type' => 'post_content', 'value' => (string) $post->post_content );
}

function seo_bridge_restore_content( $post_id, $snapshot ) {
	if ( 'elementor' === $snapshot['type'] ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( $snapshot['value'] ) );
		seo_bridge_clear_elementor_cache( $post_id );
	} else {
		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $snapshot['value'] ) ) );
	}
}

/* ======================================================================
 * REST API
 * ==================================================================== */

function seo_bridge_can_use() {
	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

function seo_bridge_guard( $action ) {
	if ( ! seo_bridge_action_enabled( $action ) ) {
		return new WP_Error( 'seo_bridge_disabled', "Aksi '{$action}' dinonaktifkan di Settings → SEO Bridge.", array( 'status' => 403 ) );
	}
	return true;
}

add_action( 'rest_api_init', 'seo_bridge_register_routes' );

function seo_bridge_register_routes() {
	$read  = array( 'methods' => 'GET', 'permission_callback' => 'seo_bridge_can_use' );
	$write = array( 'methods' => 'POST', 'permission_callback' => 'seo_bridge_can_use' );

	register_rest_route( SEO_BRIDGE_NS, '/status', $read + array( 'callback' => 'seo_bridge_rest_status' ) );
	register_rest_route( SEO_BRIDGE_NS, '/post', $read + array( 'callback' => 'seo_bridge_rest_post' ) );
	register_rest_route( SEO_BRIDGE_NS, '/changes', $read + array( 'callback' => 'seo_bridge_rest_changes' ) );
	register_rest_route( SEO_BRIDGE_NS, '/seo-meta', $write + array( 'callback' => 'seo_bridge_rest_seo_meta' ) );
	register_rest_route( SEO_BRIDGE_NS, '/internal-link', $write + array( 'callback' => 'seo_bridge_rest_internal_link' ) );
	register_rest_route( SEO_BRIDGE_NS, '/image-alt', $write + array( 'callback' => 'seo_bridge_rest_image_alt' ) );
	register_rest_route( SEO_BRIDGE_NS, '/faq', $write + array( 'callback' => 'seo_bridge_rest_faq' ) );
	register_rest_route( SEO_BRIDGE_NS, '/redirect', $write + array( 'callback' => 'seo_bridge_rest_redirect' ) );
	register_rest_route( SEO_BRIDGE_NS, '/draft', $write + array( 'callback' => 'seo_bridge_rest_draft' ) );
	register_rest_route( SEO_BRIDGE_NS, '/rollback', $write + array( 'callback' => 'seo_bridge_rest_rollback' ) );
}

function seo_bridge_rest_status() {
	$user     = wp_get_current_user();
	$settings = seo_bridge_settings();
	return array(
		'plugin_version' => SEO_BRIDGE_VERSION,
		'wp_version'     => get_bloginfo( 'version' ),
		'site_url'       => home_url( '/' ),
		'seo_plugin'     => seo_bridge_seo_plugin(),
		'elementor'      => defined( 'ELEMENTOR_VERSION' ),
		'user'           => $user->user_login,
		'roles'          => array_values( (array) $user->roles ),
		'is_admin'       => current_user_can( 'manage_options' ),
		'read_only'      => (bool) $settings['read_only'],
		'enabled'        => array_values( $settings['read_only'] ? array() : (array) $settings['enabled'] ),
	);
}

function seo_bridge_rest_post( WP_REST_Request $request ) {
	$post = seo_bridge_resolve_post( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$images = array();
	foreach ( get_attached_media( 'image', $post->ID ) as $img ) {
		$images[] = array(
			'id'  => $img->ID,
			'url' => wp_get_attachment_url( $img->ID ),
			'alt' => (string) get_post_meta( $img->ID, '_wp_attachment_image_alt', true ),
		);
	}
	return array(
		'id'         => $post->ID,
		'type'       => $post->post_type,
		'status'     => $post->post_status,
		'url'        => get_permalink( $post ),
		'post_title' => $post->post_title,
		'modified'   => $post->post_modified_gmt,
		'seo_plugin' => seo_bridge_seo_plugin(),
		'seo'        => seo_bridge_get_seo( $post->ID ),
		'elementor'  => seo_bridge_is_elementor( $post->ID ),
		'faq'        => get_post_meta( $post->ID, '_seo_bridge_faq', true ) ?: array(),
		'images'     => array_slice( $images, 0, 30 ),
	);
}

function seo_bridge_rest_changes( WP_REST_Request $request ) {
	$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
	$out   = array();
	foreach ( array_slice( (array) get_option( 'seo_bridge_changes', array() ), 0, $limit ) as $entry ) {
		unset( $entry['backup_key'] );
		$out[] = $entry;
	}
	return $out;
}

function seo_bridge_rest_seo_meta( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'seo_meta' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post = seo_bridge_resolve_post( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$title = $request->get_param( 'title' );
	$desc  = $request->get_param( 'description' );
	$title = ( null === $title || '' === $title ) ? null : sanitize_text_field( $title );
	$desc  = ( null === $desc || '' === $desc ) ? null : sanitize_textarea_field( $desc );
	if ( null === $title && null === $desc ) {
		return new WP_Error( 'seo_bridge_empty', 'Isi title dan/atau description.', array( 'status' => 400 ) );
	}
	$before = seo_bridge_get_seo( $post->ID );
	seo_bridge_set_seo( $post->ID, $title, $desc );
	$after = seo_bridge_get_seo( $post->ID );
	return seo_bridge_log_add(
		array(
			'action'  => 'seo_meta',
			'post_id' => $post->ID,
			'url'     => get_permalink( $post ),
			'summary' => 'SEO title/meta diubah (' . seo_bridge_seo_plugin() . ')',
			'before'  => $before,
			'after'   => $after,
		)
	);
}

function seo_bridge_rest_internal_link( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'internal_link' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post = seo_bridge_resolve_post( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$anchor = trim( wp_strip_all_tags( (string) $request->get_param( 'anchor' ) ) );
	$url    = esc_url_raw( (string) $request->get_param( 'link_url' ) );
	if ( '' === $anchor || '' === $url ) {
		return new WP_Error( 'seo_bridge_empty', 'anchor dan link_url wajib diisi.', array( 'status' => 400 ) );
	}
	$snapshot = seo_bridge_get_content_snapshot( $post );
	if ( seo_bridge_has_link( $snapshot, $url ) ) {
		return new WP_Error( 'seo_bridge_exists', 'Halaman ini sudah memiliki link ke URL tersebut.', array( 'status' => 409 ) );
	}
	if ( 'elementor' === $snapshot['type'] ) {
		$data = json_decode( $snapshot['value'], true );
		if ( ! is_array( $data ) || ! seo_bridge_link_elementor( $data, $anchor, $url ) ) {
			return new WP_Error( 'seo_bridge_anchor_missing', "Anchor '{$anchor}' tidak ditemukan di widget Text Editor Elementor (yang belum ber-link).", array( 'status' => 422 ) );
		}
		update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		seo_bridge_clear_elementor_cache( $post->ID );
	} else {
		$new = seo_bridge_link_html( $snapshot['value'], $anchor, $url );
		if ( null === $new ) {
			return new WP_Error( 'seo_bridge_anchor_missing', "Anchor '{$anchor}' tidak ditemukan di konten (yang belum ber-link).", array( 'status' => 422 ) );
		}
		wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ) );
	}
	$entry = seo_bridge_log_add(
		array(
			'action'  => 'internal_link',
			'post_id' => $post->ID,
			'url'     => get_permalink( $post ),
			'summary' => "Link '{$anchor}' → {$url}",
			'before'  => array( 'content_type' => $snapshot['type'] ),
			'after'   => array( 'anchor' => $anchor, 'link_url' => $url ),
		)
	);
	$key = '_seo_bridge_backup_' . $entry['id'];
	add_post_meta( $post->ID, $key, wp_slash( wp_json_encode( $snapshot ) ) );
	$after = seo_bridge_get_content_snapshot( get_post( $post->ID ) );
	seo_bridge_log_update( $entry['id'], array( 'backup_key' => $key, 'after_hash' => md5( $after['value'] ) ) );
	return $entry;
}

function seo_bridge_rest_image_alt( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'image_alt' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$id = (int) $request->get_param( 'attachment_id' );
	if ( ! $id && $request->get_param( 'image_url' ) ) {
		$id = attachment_url_to_postid( esc_url_raw( $request->get_param( 'image_url' ) ) );
	}
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'seo_bridge_not_found', 'Gambar tidak ditemukan di Media Library.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'seo_bridge_forbidden', 'Tidak punya izin mengedit gambar ini.', array( 'status' => 403 ) );
	}
	$alt    = sanitize_text_field( (string) $request->get_param( 'alt' ) );
	$before = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return seo_bridge_log_add(
		array(
			'action'  => 'image_alt',
			'post_id' => $id,
			'url'     => wp_get_attachment_url( $id ),
			'summary' => 'Alt gambar diubah',
			'before'  => array( 'alt' => $before ),
			'after'   => array( 'alt' => $alt ),
		)
	);
}

function seo_bridge_rest_faq( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'faq' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$post = seo_bridge_resolve_post( $request );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$items = array();
	foreach ( (array) $request->get_param( 'items' ) as $item ) {
		$q = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
		$a = isset( $item['answer'] ) ? wp_kses_post( $item['answer'] ) : '';
		if ( $q && $a ) {
			$items[] = array( 'question' => $q, 'answer' => $a );
		}
	}
	$before = get_post_meta( $post->ID, '_seo_bridge_faq', true ) ?: array();
	if ( $items ) {
		update_post_meta( $post->ID, '_seo_bridge_faq', $items );
	} else {
		delete_post_meta( $post->ID, '_seo_bridge_faq' );
	}
	return seo_bridge_log_add(
		array(
			'action'  => 'faq',
			'post_id' => $post->ID,
			'url'     => get_permalink( $post ),
			'summary' => count( $items ) . ' FAQ schema',
			'before'  => array( 'items' => $before ),
			'after'   => array( 'items' => $items ),
		)
	);
}

function seo_bridge_normalize_path( $from ) {
	$path = wp_parse_url( (string) $from, PHP_URL_PATH );
	$path = '/' . ltrim( (string) $path, '/' );
	return strtolower( untrailingslashit( $path ) ?: '/' );
}

function seo_bridge_rest_redirect( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'redirect' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return new WP_Error( 'seo_bridge_forbidden', 'Redirect butuh role Editor atau lebih tinggi.', array( 'status' => 403 ) );
	}
	$from = seo_bridge_normalize_path( $request->get_param( 'from' ) );
	$to   = esc_url_raw( (string) $request->get_param( 'to' ) );
	$code = (int) $request->get_param( 'status_code' ) === 302 ? 302 : 301;
	if ( '/' === $from || '' === $to ) {
		return new WP_Error( 'seo_bridge_invalid', 'Redirect butuh path asal (bukan homepage) dan URL tujuan.', array( 'status' => 400 ) );
	}
	if ( seo_bridge_normalize_path( $to ) === $from && wp_parse_url( $to, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return new WP_Error( 'seo_bridge_loop', 'URL asal dan tujuan sama (redirect loop).', array( 'status' => 400 ) );
	}
	$map           = (array) get_option( 'seo_bridge_redirects', array() );
	$before        = isset( $map[ $from ] ) ? $map[ $from ] : null;
	$map[ $from ]  = array( 'to' => $to, 'code' => $code );
	update_option( 'seo_bridge_redirects', $map, true );
	return seo_bridge_log_add(
		array(
			'action'  => 'redirect',
			'post_id' => 0,
			'url'     => home_url( $from ),
			'summary' => "{$code} {$from} → {$to}",
			'before'  => array( 'from' => $from, 'rule' => $before ),
			'after'   => array( 'from' => $from, 'rule' => $map[ $from ] ),
		)
	);
}

function seo_bridge_rest_draft( WP_REST_Request $request ) {
	$ok = seo_bridge_guard( 'draft' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
	if ( '' === $title ) {
		return new WP_Error( 'seo_bridge_empty', 'Judul draft wajib diisi.', array( 'status' => 400 ) );
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_title'   => $title,
				'post_content' => wp_kses_post( (string) $request->get_param( 'content' ) ),
				'post_name'    => sanitize_title( (string) $request->get_param( 'slug' ) ),
				'post_status'  => 'draft', // tidak pernah langsung publish
				'post_type'    => 'post',
				'post_author'  => get_current_user_id(),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$seo_title = $request->get_param( 'seo_title' );
	$seo_desc  = $request->get_param( 'seo_description' );
	if ( $seo_title || $seo_desc ) {
		seo_bridge_set_seo( $id, $seo_title ? sanitize_text_field( $seo_title ) : null, $seo_desc ? sanitize_textarea_field( $seo_desc ) : null );
	}
	return seo_bridge_log_add(
		array(
			'action'   => 'draft',
			'post_id'  => $id,
			'url'      => get_edit_post_link( $id, 'raw' ),
			'summary'  => "Draft baru: {$title}",
			'before'   => array(),
			'after'    => array( 'post_id' => $id, 'title' => $title ),
			'edit_url' => get_edit_post_link( $id, 'raw' ),
		)
	);
}

function seo_bridge_rest_rollback( WP_REST_Request $request ) {
	$id    = (string) $request->get_param( 'change_id' );
	$entry = seo_bridge_log_get( $id );
	if ( ! $entry ) {
		return new WP_Error( 'seo_bridge_not_found', 'Change ID tidak ditemukan di log.', array( 'status' => 404 ) );
	}
	if ( 'applied' !== $entry['status'] ) {
		return new WP_Error( 'seo_bridge_state', 'Perubahan ini sudah di-rollback.', array( 'status' => 409 ) );
	}
	$post_id = (int) $entry['post_id'];
	if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'seo_bridge_forbidden', 'Tidak punya izin.', array( 'status' => 403 ) );
	}
	switch ( $entry['action'] ) {
		case 'seo_meta':
			seo_bridge_set_seo( $post_id, (string) $entry['before']['title'], (string) $entry['before']['description'] );
			break;
		case 'internal_link':
			$raw      = get_post_meta( $post_id, $entry['backup_key'], true );
			$snapshot = $raw ? json_decode( $raw, true ) : null;
			if ( ! $snapshot ) {
				return new WP_Error( 'seo_bridge_no_backup', 'Backup konten tidak ditemukan.', array( 'status' => 410 ) );
			}
			$current = seo_bridge_get_content_snapshot( get_post( $post_id ) );
			if ( ! empty( $entry['after_hash'] ) && md5( $current['value'] ) !== $entry['after_hash'] && ! $request->get_param( 'force' ) ) {
				return new WP_Error( 'seo_bridge_conflict', 'Konten sudah diedit lagi setelah link disisipkan. Rollback akan menimpa edit tersebut; kirim force=true bila tetap ingin.', array( 'status' => 409 ) );
			}
			seo_bridge_restore_content( $post_id, $snapshot );
			delete_post_meta( $post_id, $entry['backup_key'] );
			break;
		case 'image_alt':
			update_post_meta( $post_id, '_wp_attachment_image_alt', (string) $entry['before']['alt'] );
			break;
		case 'faq':
			if ( ! empty( $entry['before']['items'] ) ) {
				update_post_meta( $post_id, '_seo_bridge_faq', $entry['before']['items'] );
			} else {
				delete_post_meta( $post_id, '_seo_bridge_faq' );
			}
			break;
		case 'redirect':
			$map  = (array) get_option( 'seo_bridge_redirects', array() );
			$from = $entry['before']['from'];
			if ( $entry['before']['rule'] ) {
				$map[ $from ] = $entry['before']['rule'];
			} else {
				unset( $map[ $from ] );
			}
			update_option( 'seo_bridge_redirects', $map, true );
			break;
		case 'draft':
			if ( 'draft' !== get_post_status( $post_id ) ) {
				return new WP_Error( 'seo_bridge_state', 'Draft sudah dipublish/diubah statusnya; rollback dibatalkan.', array( 'status' => 409 ) );
			}
			wp_trash_post( $post_id );
			break;
	}
	seo_bridge_log_update( $id, array( 'status' => 'rolled_back', 'rolled_back_at' => gmdate( 'c' ), 'rolled_back_by' => wp_get_current_user()->user_login ) );
	return seo_bridge_log_get( $id );
}

/* ======================================================================
 * Frontend output: FAQ schema, title/meta (tanpa plugin SEO), redirect
 * ==================================================================== */

add_action( 'wp_head', 'seo_bridge_head', 1 );
function seo_bridge_head() {
	if ( ! is_singular() ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( 'none' === seo_bridge_seo_plugin() ) {
		$desc = get_post_meta( $post_id, '_seo_bridge_description', true );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	}
	$faq = get_post_meta( $post_id, '_seo_bridge_faq', true );
	if ( is_array( $faq ) && $faq ) {
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				function ( $item ) {
					return array(
						'@type'          => 'Question',
						'name'           => $item['question'],
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $item['answer'] ),
					);
				},
				$faq
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}

add_filter( 'pre_get_document_title', 'seo_bridge_document_title', 20 );
function seo_bridge_document_title( $title ) {
	if ( 'none' !== seo_bridge_seo_plugin() || ! is_singular() ) {
		return $title;
	}
	$custom = get_post_meta( get_queried_object_id(), '_seo_bridge_title', true );
	return $custom ? $custom : $title;
}

add_action( 'template_redirect', 'seo_bridge_do_redirect', 1 );
function seo_bridge_do_redirect() {
	$map = get_option( 'seo_bridge_redirects', array() );
	if ( ! $map || ! is_array( $map ) || is_admin() ) {
		return;
	}
	$path = seo_bridge_normalize_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore
	if ( isset( $map[ $path ] ) ) {
		wp_redirect( $map[ $path ]['to'], (int) $map[ $path ]['code'], 'SEO Bridge' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
}

/* ======================================================================
 * WordPress Abilities API (WP 6.9+) — aksi yang sama bisa dipanggil klien MCP
 * (Claude Code, Cursor, dll. lewat MCP Adapter) dengan izin yang sama persis.
 * ==================================================================== */

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( 'seo-bridge', array( 'label' => 'SEO Bridge', 'description' => 'Aksi SEO yang di-whitelist.' ) );
		}
	}
);

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$wrap = function ( $callback ) {
			return function ( $input ) use ( $callback ) {
				$request = new WP_REST_Request( 'POST' );
				foreach ( (array) $input as $k => $v ) {
					$request->set_param( $k, $v );
				}
				$result = call_user_func( $callback, $request );
				return $result instanceof WP_REST_Response ? $result->get_data() : $result;
			};
		};
		$abilities = array(
			'get-post'      => array( 'Baca info SEO halaman', 'seo_bridge_rest_post', true, array( 'url' => 'string' ) ),
			'seo-meta'      => array( 'Ubah SEO title & meta description', 'seo_bridge_rest_seo_meta', false, array( 'url' => 'string', 'title' => 'string', 'description' => 'string' ) ),
			'internal-link' => array( 'Sisipkan internal link', 'seo_bridge_rest_internal_link', false, array( 'url' => 'string', 'anchor' => 'string', 'link_url' => 'string' ) ),
			'rollback'      => array( 'Rollback perubahan SEO Bridge', 'seo_bridge_rest_rollback', false, array( 'change_id' => 'string' ) ),
		);
		foreach ( $abilities as $slug => $def ) {
			$props = array();
			foreach ( $def[3] as $name => $type ) {
				$props[ $name ] = array( 'type' => $type );
			}
			wp_register_ability(
				'seo-bridge/' . $slug,
				array(
					'label'               => $def[0],
					'description'         => $def[0] . ' (lewat SEO Bridge, tercatat di change log).',
					'category'            => 'seo-bridge',
					'input_schema'        => array( 'type' => 'object', 'properties' => $props ),
					'execute_callback'    => $wrap( $def[1] ),
					'permission_callback' => 'seo_bridge_can_use',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'readonly' => $def[2], 'destructive' => false ),
					),
				)
			);
		}
	}
);

/* ======================================================================
 * Halaman admin: Settings → SEO Bridge
 * ==================================================================== */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'SEO Bridge', 'SEO Bridge', 'manage_options', 'seo-bridge', 'seo_bridge_admin_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'seo_bridge',
			'seo_bridge_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $input ) {
					$valid = array_keys( seo_bridge_actions() );
					return array(
						'read_only' => empty( $input['read_only'] ) ? 0 : 1,
						'enabled'   => array_values( array_intersect( $valid, (array) ( $input['enabled'] ?? array() ) ) ),
					);
				},
			)
		);
	}
);

function seo_bridge_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = seo_bridge_settings();
	$changes = array_slice( (array) get_option( 'seo_bridge_changes', array() ), 0, 50 );
	?>
	<div class="wrap">
		<h1>SEO Bridge <small style="font-weight:400;color:#777">v<?php echo esc_html( SEO_BRIDGE_VERSION ); ?></small></h1>
		<p>Plugin SEO terdeteksi: <strong><?php echo esc_html( seo_bridge_seo_plugin() ); ?></strong>
			· Elementor: <strong><?php echo defined( 'ELEMENTOR_VERSION' ) ? 'ya' : 'tidak'; ?></strong></p>
		<p>Hubungkan agent: buat user khusus (role <strong>Editor</strong>) → Users → Profile → <em>Application Passwords</em> → isi username + password itu di tab Website aplikasi SEO Agent.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'seo_bridge' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Mode baca saja</th><td><label><input type="checkbox" name="seo_bridge_settings[read_only]" value="1" <?php checked( $s['read_only'] ); ?>> Tolak semua perubahan (agent hanya bisa membaca)</label></td></tr>
				<tr><th scope="row">Aksi yang diizinkan</th><td>
					<?php foreach ( seo_bridge_actions() as $key => $label ) : ?>
						<label style="display:block;margin-bottom:4px"><input type="checkbox" name="seo_bridge_settings[enabled][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['enabled'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2>Riwayat perubahan (50 terakhir)</h2>
		<table class="widefat striped">
			<thead><tr><th>Waktu (UTC)</th><th>User</th><th>Aksi</th><th>Ringkasan</th><th>URL</th><th>Status</th></tr></thead>
			<tbody>
			<?php if ( ! $changes ) : ?>
				<tr><td colspan="6">Belum ada perubahan.</td></tr>
			<?php endif; ?>
			<?php foreach ( $changes as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['time'] ); ?></td>
					<td><?php echo esc_html( $c['user'] ); ?></td>
					<td><code><?php echo esc_html( $c['action'] ); ?></code></td>
					<td><?php echo esc_html( $c['summary'] ); ?></td>
					<td><a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $c['url'], PHP_URL_PATH ) ); ?></a></td>
					<td><?php echo esc_html( $c['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
