<?php
/**
 * Settings page of the Media module.
 *
 * The module has no settings of its own: the virtual folders interface is
 * built directly into the WordPress media library (upload.php) via JS.
 *
 * @var string $module_id
 * @var array  $module
 * @var object $instance
 * @var array  $module_settings
 * @var string $tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="lumia-section">
	<div class="lumia-section__header">
		<h2 class="lumia-section__title"><?php esc_html_e( 'Media folders', 'lumia-tools' ); ?></h2>
		<p class="lumia-section__desc">
			<?php esc_html_e( 'Virtual folders are available directly from the WordPress media library.', 'lumia-tools' ); ?>
		</p>
	</div>
	<div class="lumia-section__content">
		<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="lumia-btn lumia-btn--primary">
			<?php esc_html_e( 'Open media library', 'lumia-tools' ); ?>
		</a>
	</div>
</div>
