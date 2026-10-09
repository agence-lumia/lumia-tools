<?php
/**
 * Data cleanup on uninstall.
 *
 * Each module declares the keys to delete through ::get_uninstall_keys().
 * The module list lives in Activator::MODULE_CLASSES.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the autoloader to reach the module classes.
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Autoloader.php';
\Lumia\Tools\Core\Autoloader::register();

$module_classes = \Lumia\Tools\Core\Activator::MODULE_CLASSES;

// Delete the global option.
delete_option( 'lumia_settings' );
delete_site_option( 'lumia_settings' );

// State of the migration from Studio Kyne Mini Tools.
delete_option( \Lumia\Tools\Core\Migration\FromSkmt::MARKER );
delete_option( \Lumia\Tools\Core\Migration\FromSkmt::ERROR_OPTION );
delete_option( \Lumia\Tools\Core\Migration\FromSkmt::ERROR_DETAIL_OPTION );
delete_option( \Lumia\Tools\Core\Migration\FromSkmt::NOTICE_OPTION );

// Metadata written by the plugin core (notification center).
// No module declares them: they are not attached to any of them.
delete_metadata( 'user', 0, 'lumia_notices', '', true );

// Delete the options and meta that belong to each module.
foreach ( $module_classes as $id => $class ) {
	if ( ! class_exists( $class ) ) {
		continue;
	}

	$keys = $class::get_uninstall_keys();

	foreach ( $keys['options'] ?? [] as $option_key ) {
		delete_option( $option_key );
		delete_site_option( $option_key );
	}

	foreach ( $keys['meta'] ?? [] as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	// Tables owned by a module, declared without a prefix. The name only comes
	// from the module code, never from user input: it is still restricted to
	// identifier characters before being interpolated.
	foreach ( $keys['tables'] ?? [] as $table ) {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $table ) ) {
			continue;
		}
		$table = $GLOBALS['wpdb']->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	foreach ( $keys['cron'] ?? [] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// User metadata: delete_post_meta_by_key() does not touch it, it lives in
	// another table.
	foreach ( $keys['user_meta'] ?? [] as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Delete the custom post types
	foreach ( $keys['post_type'] ?? [] as $post_type ) {
		// Fetch every post of the custom type
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'numberposts'    => -1,
				'posts_per_page' => -1,
			]
		);

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true ); // true = hard delete
		}
	}

	// Delete the custom taxonomies (every term).
	// The plugin is not booted here, so the taxonomy is not registered: register
	// it on the fly so that get_terms()/wp_delete_term() work.
	foreach ( $keys['taxonomy'] ?? [] as $tax_name ) {
		if ( '' === $tax_name ) {
			continue;
		}
		if ( ! taxonomy_exists( $tax_name ) ) {
			register_taxonomy( $tax_name, 'attachment', [ 'public' => false ] );
		}

		$terms = get_terms(
			[
				'taxonomy'   => $tax_name,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				wp_delete_term( $term_id, $tax_name );
			}
		}
	}
}
