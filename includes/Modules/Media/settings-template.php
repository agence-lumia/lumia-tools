<?php
/**
 * Page de réglages du module Médias.
 *
 * Le module n'a pas de réglages propres : l'interface des dossiers virtuels est
 * intégrée directement dans la médiathèque WordPress (upload.php) via JS.
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
		<h2 class="lumia-section__title"><?php esc_html_e( 'Dossiers médias', 'lumia-tools' ); ?></h2>
		<p class="lumia-section__desc">
			<?php esc_html_e( 'Les dossiers virtuels sont accessibles directement depuis la médiathèque WordPress.', 'lumia-tools' ); ?>
		</p>
	</div>
	<div class="lumia-section__content">
		<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="lumia-btn lumia-btn--primary">
			<?php esc_html_e( 'Ouvrir la médiathèque', 'lumia-tools' ); ?>
		</a>
	</div>
</div>
