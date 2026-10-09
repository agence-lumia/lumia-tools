<?php
/**
 * Nettoyage des données lors de la désinstallation.
 *
 * Chaque module déclare les clés à supprimer via ::get_uninstall_keys().
 * La liste des modules vit dans Activator::MODULE_CLASSES.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Chargement de l'autoloader pour accéder aux classes des modules.
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Autoloader.php';
\Lumia\Tools\Core\Autoloader::register();

$module_classes = \Lumia\Tools\Core\Activator::MODULE_CLASSES;

// Suppression de l'option globale.
delete_option( 'lumia_settings' );
delete_site_option( 'lumia_settings' );

// Métadonnées écrites par le cœur du plugin (centre de notifications).
// Aucun module ne les déclare : elles ne sont rattachées à aucun d'entre eux.
delete_metadata( 'user', 0, 'lumia_notices', '', true );

// Suppression des options et meta propres à chaque module.
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

	// Tables propres à un module, déclarées sans préfixe. Le nom ne vient que
	// du code du module, jamais d'une saisie : on le restreint quand même aux
	// caractères d'un identifiant avant de l'interpoler.
	foreach ( $keys['tables'] ?? [] as $table ) {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $table ) ) {
			continue;
		}
		$table = $GLOBALS['wpdb']->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table validé ci-dessus.
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	foreach ( $keys['cron'] ?? [] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Métadonnées d'utilisateur : delete_post_meta_by_key() ne les touche pas,
	// elles vivent dans une autre table.
	foreach ( $keys['user_meta'] ?? [] as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// Suppression des post types custom
	foreach ( $keys['post_type'] ?? [] as $post_type ) {
		// Récupérer tous les posts du type custom
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

	// Suppression des taxonomies custom (tous les termes).
	// Le plugin n'étant pas booté ici, la taxonomie n'est pas enregistrée : on
	// l'enregistre à la volée pour que get_terms()/wp_delete_term() fonctionnent.
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
