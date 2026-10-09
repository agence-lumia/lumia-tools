<?php
/**
 * Bench seed for Studio Kyne Mini Tools (SKMT), run by `run.sh seed-skmt`
 * through `wp --user=admin eval-file`. Bench only, never shipped.
 *
 * The seed is split in phases, each one a separate wp-cli process, because
 * SKMT reads its settings when it boots:
 *
 *   modules   turns the modules on through Modules::activate() (the same path as
 *             the admin UI, so on_activate() runs). With "minimal", only Security
 *             is on and every skmt_module_* option is deleted afterwards: the
 *             "active but never configured" site.
 *   settings  saves every module's settings through its own save_settings(), then
 *             the SMTP secrets. A fresh process afterwards, so the content phase
 *             runs with the saved settings (keep_original, SMTP transport...).
 *   content   images, media folders, avatar, menu profile, a running bulk state with its
 *             cron event, notice, one failed mail.
 *
 * Usage: wp --user=admin eval-file seed-skmt.php <phase> [minimal]
 */

use StudioKyne\MiniTools\Admin\Admin;
use StudioKyne\MiniTools\Core\Plugin;
use StudioKyne\MiniTools\Modules\ActivityLog\Events;
use StudioKyne\MiniTools\Modules\Media\Module as MediaModule;
use StudioKyne\MiniTools\Modules\Security\ClientIp;
use StudioKyne\MiniTools\Modules\Smtp\Crypto;
use StudioKyne\MiniTools\Modules\Smtp\Mailer;
use StudioKyne\MiniTools\Modules\WhiteLabel\MenuProfileManager;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$e2e_phase   = $args[0] ?? '';
$e2e_minimal = in_array( 'minimal', $args, true );
$e2e_admin   = get_user_by( 'login', 'admin' );

if ( ! $e2e_admin || get_current_user_id() !== $e2e_admin->ID ) {
	WP_CLI::error( 'Run with --user=admin.' );
}

/**
 * The module instance (active in this process), or an error.
 */
function e2e_module( string $id ) {
	$module = Plugin::instance()->modules->get_instance( $id );
	if ( ! $module ) {
		WP_CLI::error( "Module {$id} not found." );
	}
	return $module;
}

/**
 * Writes a gradient-and-noise JPEG big enough to be resized and recompressed.
 */
function e2e_make_jpeg( string $path, int $width, int $height, int $seed ): void {
	$img = imagecreatetruecolor( $width, $height );
	mt_srand( $seed );
	for ( $y = 0; $y < $height; $y += 8 ) {
		$color = imagecolorallocate( $img, ( $y * 255 / $height ), ( $seed * 40 ) % 255, 255 - ( $y * 255 / $height ) );
		imagefilledrectangle( $img, 0, $y, $width, $y + 8, $color );
	}
	for ( $i = 0; $i < 400; $i++ ) {
		$color = imagecolorallocate( $img, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
		imagefilledellipse( $img, mt_rand( 0, $width ), mt_rand( 0, $height ), mt_rand( 20, 200 ), mt_rand( 20, 200 ), $color );
	}
	imagejpeg( $img, $path, 92 );
	imagedestroy( $img );
}

/**
 * Imports a file with `wp media import` (in this process, so SKMT's upload
 * hooks run) and returns the attachment ID.
 */
function e2e_import( string $path, string $title ): int {
	$id = WP_CLI::runcommand(
		'media import ' . escapeshellarg( $path ) . ' --title=' . escapeshellarg( $title ) . ' --porcelain',
		[
			'return' => true,
			'launch' => false,
		]
	);
	return (int) trim( (string) $id );
}

/* ------------------------------------------------------------------ */

if ( 'modules' === $e2e_phase ) {
	$modules = Plugin::instance()->modules;
	// Registered modules are only the active ones outside wp-admin: register them all.
	$modules->register_default_modules( false );

	$wanted = $e2e_minimal ? [ 'security' ] : array_keys( $modules->get_all() );
	foreach ( $wanted as $id ) {
		$modules->activate( $id );
	}

	if ( $e2e_minimal ) {
		// The Activator creates skmt_module_* for modules that have defaults; a
		// never-configured site has none.
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'skmt\\_module\\_%'" );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	WP_CLI::success( 'modules: ' . implode( ',', $wanted ) );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'settings' === $e2e_phase ) {
	// Each value below differs from the module's default.

	e2e_module( 'image_optimizer' )->save_settings(
		[
			'optimize_on_upload' => 1,
			'format_mode'        => 'webp',
			'quality'            => 82,
			'max_width'          => 1920,
			'max_height'         => 1920,
			'strip_exif'         => 1,
			'generate_alt'       => 1,
			'keep_original'      => 1,
			'svg_roles'          => [ 'administrator', 'editor' ],
		]
	);

	// Security stores custom_login_url as "/connexion-e2e".
	e2e_module( 'security' )->save_settings(
		[
			'rate_limiting'           => 1,
			'rate_limit_attempts'     => 3,
			'rate_limit_window'       => 600,
			'rate_limit_lockout'      => 900,
			'rate_limit_whitelist'    => "192.0.2.10\n198.51.100.7",
			'enable_custom_login_url' => 1,
			'custom_login_url'        => 'connexion-e2e',
			'ip_source'               => ClientIp::SOURCE_REMOTE_ADDR,
			'disable_xmlrpc'          => 1,
			'prevent_user_enum'       => 1,
			'hide_wp_version'         => 1,
		]
	);

	$login = e2e_module( 'login' );
	$cur   = $login->get_settings();

	$cur['layout']['panel_bg_color'] = '#112233';
	$cur['branding']['logo_width']   = 200;
	$cur['form']['btn_bg_color']     = '#ff5500';
	$cur['form']['hide_lost_password'] = true;
	$login->save_settings( $cur );

	$white = e2e_module( 'white_label' );
	$cur   = $white->get_settings();

	$cur['admin_bar']['hide_wp_logo'] = false;
	$cur['footer']['left_text']       = 'Propulsé par le banc E2E';
	$cur['profile']['hide_language']  = true;
	$white->save_settings( $cur );

	e2e_module( 'activity_log' )->save_settings(
		[
			'retention_days' => 60,
			'max_rows'       => 5000,
			'anonymize_ip'   => 1,
			'tracked_groups' => array_keys( Events::groups() ),
			'tracked_roles'  => array_keys( wp_roles()->get_names() ),
		]
	);

	e2e_module( 'smtp' )->save_settings(
		[
			'smtp_enabled'       => 1,
			'transport'          => 'smtp',
			'provider'           => 'custom',
			// Nothing listens here: the mail below fails at once and lands in the log.
			'host'               => '127.0.0.1',
			'port'               => 2525,
			'encryption'         => 'none',
			'auth'               => 1,
			'username'           => 'e2e-user',
			'from_email'         => 'e2e@example.test',
			'from_name'          => 'Banc E2E',
			'force_from_name'    => 1,
			'set_return_path'    => 1,
			'log_enabled'        => 1,
			'log_retention_days' => 14,
			'log_max_rows'       => 2000,
		]
	);

	// Secrets as the form would store them (autoload off), encrypted with the
	// key in force right now: SKMT_ENCRYPTION_KEY when the bench defines it.
	update_option( Mailer::PASSWORD_OPTION, Crypto::encrypt( 'e2e-secret' ), false );
	update_option( Mailer::BREVO_KEY_OPTION, Crypto::encrypt( 'e2e-brevo' ), false );

	// Files, Database, Media and MenuCreator have no settings of their own.
	WP_CLI::success( 'settings saved' );
	return;
}

/* ------------------------------------------------------------------ */

if ( 'content' === $e2e_phase ) {
	$tmp = sys_get_temp_dir();

	// Two JPEGs through the Image Optimizer pipeline (_skmt_* meta, skmt-originals-*).
	$photo_a = "{$tmp}/e2e-photo-a.jpg";
	$photo_b = "{$tmp}/e2e-photo-b.jpg";
	$avatar  = "{$tmp}/e2e-avatar.jpg";
	e2e_make_jpeg( $photo_a, 2400, 1600, 3 );
	e2e_make_jpeg( $photo_b, 2000, 1400, 5 );
	e2e_make_jpeg( $avatar, 400, 400, 7 );

	$id_a = e2e_import( $photo_a, 'Photo E2E A' );
	$id_b = e2e_import( $photo_b, 'Photo E2E B' );
	$id_v = e2e_import( $avatar, 'Avatar E2E' );
	if ( ! $id_a || ! $id_b || ! $id_v ) {
		WP_CLI::error( 'Image import failed.' );
	}

	// Media folders: two terms, one with a colour, one image filed in it.
	$taxonomy = MediaModule::TAXONOMY;
	$folder_1 = wp_insert_term( 'Dossier E2E rouge', $taxonomy );
	$folder_2 = wp_insert_term( 'Dossier E2E sans couleur', $taxonomy );
	if ( is_wp_error( $folder_1 ) || is_wp_error( $folder_2 ) ) {
		WP_CLI::error( 'Could not create the media folders.' );
	}
	update_term_meta( $folder_1['term_id'], MediaModule::COLOR_META, '#ef4444' );
	wp_set_object_terms( $id_a, [ (int) $folder_1['term_id'] ], $taxonomy );

	// WhiteLabel: local avatar, and a menu profile that points at the SKMT admin page.
	update_user_meta( $e2e_admin->ID, 'skmt_local_avatar', $id_v );

	MenuProfileManager::save(
		[
			'id'            => 'e2e00000-0000-4000-8000-000000000001',
			'name'          => 'Profil E2E',
			'status'        => 'active',
			'apply_to_all'  => false,
			// Editors only: the admin keeps its full menu.
			'include_roles' => [ 'editor' ],
			'include_users' => [],
			'exclude_roles' => [],
			'exclude_users' => [],
			'items'         => [
				[
					'type'         => 'wp_item',
					'slug'         => 'studio-kyne-mini-tools',
					'label'        => 'Outils E2E',
					'icon'         => null,
					'visible'      => true,
					'block_access' => false,
					'target_blank' => false,
					'url'          => '',
					'roles'        => [],
					'children'     => [
						[
							'type'         => 'wp_item',
							'slug'         => 'studio-kyne-mini-tools&tab=module_smtp',
							'label'        => null,
							'icon'         => null,
							'visible'      => false,
							'block_access' => true,
							'target_blank' => false,
							'url'          => '',
							'roles'        => [],
							'children'     => [],
						],
						// The separator SKMT adds to its own submenu: the editor lists it as
						// a child of the plugin page, under its slug.
						[
							'type'         => 'wp_item',
							'slug'         => 'skmt-separator',
							'label'        => null,
							'icon'         => null,
							'visible'      => false,
							'block_access' => false,
							'target_blank' => false,
							'url'          => '',
							'roles'        => [],
							'children'     => [],
						],
					],
				],
				[
					'type'         => 'separator',
					'slug'         => '',
					'label'        => null,
					'icon'         => null,
					'visible'      => true,
					'block_access' => false,
					'target_blank' => false,
					'url'          => '',
					'roles'        => [],
					'children'     => [],
				],
				[
					'type'         => 'custom_link',
					'slug'         => '',
					'label'        => 'Documentation E2E',
					'icon'         => 'dashicons-book',
					'visible'      => true,
					'block_access' => false,
					'target_blank' => true,
					'url'          => 'https://example.test/docs',
					'roles'        => [ 'editor' ],
					'children'     => [],
				],
			],
			'updated_at'    => time(),
		]
	);

	// Optimizer left mid-bulk. The images above are already optimized, so the plugin's own
	// ajax_start() would find nothing to do: write the option shape BulkProcessor::set_state()
	// stores (autoload off) and schedule the event the way schedule_next() does, with
	// Module::BATCH_SIZE (5) as its only argument.
	// The event is a year away so neither WP-Cron nor run_cron_batch() consumes it before a
	// migration is checked (DISABLE_WP_CRON would also stop the bench's other cron events).
	update_option(
		'skmt_module_image_optimizer_bulk_state',
		[
			'running'    => true,
			'total'      => 12,
			'processed'  => 4,
			'remaining'  => 8,
			'updated_at' => time(),
			'user_id'    => $e2e_admin->ID,
		],
		false
	);
	wp_schedule_single_event( time() + YEAR_IN_SECONDS, 'skmt_image_optimizer_cron', [ 5 ] );

	Admin::add_persistent_notice( 'e2e_notice', 'Notice E2E : réglages enregistrés.', 'info', $e2e_admin->ID );

	// SMTP now points at a closed port: the failure is logged in skmt_mail_log.
	$sent = wp_mail( 'destinataire@example.test', 'Mail de test E2E', 'Corps du mail de test.' );

	WP_CLI::success(
		sprintf(
			'content: attachments %d,%d (avatar %d), folders %d,%d, mail sent=%s',
			$id_a,
			$id_b,
			$id_v,
			$folder_1['term_id'],
			$folder_2['term_id'],
			$sent ? 'yes' : 'no (expected)'
		)
	);
	return;
}

WP_CLI::error( "Unknown phase '{$e2e_phase}'." );
