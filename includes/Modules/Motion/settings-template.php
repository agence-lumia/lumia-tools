<?php
/**
 * Settings template of the Motion module.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (motion)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 * @var string          $tab             Active tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Modules\Motion\ClassManager;
use Lumia\Tools\Modules\Motion\Effects;
use Lumia\Tools\Modules\Motion\Legacy;
use Lumia\Tools\Modules\Motion\Module as MotionModule;

if ( ! MotionModule::is_bricks_active() ) :
	?>
	<div class="lumia-module-form__scroll">
		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php esc_html_e( 'Bricks is not the active theme', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php esc_html_e( 'Motion adds its animations to the elements of Bricks. Nothing is loaded on this site until the Bricks theme is active, and there is nothing to configure.', 'lumia-tools' ); ?></p>
			</div>
		</div>
	</div>
	<?php
	return;
endif;

$effects         = is_array( $module_settings['effects'] ?? null ) ? $module_settings['effects'] : [];
$lenis           = is_array( $module_settings['lenis'] ?? null ) ? $module_settings['lenis'] : [];
$tempo           = (string) ( $module_settings['tempo'] ?? 'normal' );
$ease            = (string) ( $module_settings['ease'] ?? 'smooth' );
$ease_custom     = (string) ( $module_settings['ease_custom'] ?? '' );
$builder_preview = (string) ( $module_settings['builder_preview'] ?? 'replay' );

// What still uses a former class: kept five minutes, and dropped by the migration, so the numbers follow it.
try {
	$legacy_usage = ClassManager::legacy_usage_cached();
} catch ( \Throwable $lumia_motion_error ) {
	$legacy_usage = null;
}

$tempo_labels = [
	'very-fast' => __( 'Very fast', 'lumia-tools' ),
	'fast'      => __( 'Fast', 'lumia-tools' ),
	'normal'    => __( 'Normal', 'lumia-tools' ),
	'slow'      => __( 'Slow', 'lumia-tools' ),
	'very-slow' => __( 'Very slow', 'lumia-tools' ),
];

$ease_labels = [
	'smooth' => __( 'Smooth', 'lumia-tools' ),
	'snappy' => __( 'Snappy', 'lumia-tools' ),
	'bouncy' => __( 'Bouncy', 'lumia-tools' ),
	'linear' => __( 'Linear', 'lumia-tools' ),
	'custom' => __( 'Custom', 'lumia-tools' ),
];

$edge_labels = [
	'top'    => __( 'Top', 'lumia-tools' ),
	'center' => __( 'Center', 'lumia-tools' ),
	'bottom' => __( 'Bottom', 'lumia-tools' ),
];

$effect_labels = [
	'fade'      => __( 'Fade', 'lumia-tools' ),
	'slide-up'  => __( 'Slide up', 'lumia-tools' ),
	'blur'      => __( 'Blur', 'lumia-tools' ),
	'blur-up'   => __( 'Blur up', 'lumia-tools' ),
	'zoom'      => __( 'Zoom', 'lumia-tools' ),
	'zoom-blur' => __( 'Zoom blur', 'lumia-tools' ),
	'lines'     => __( 'Lines', 'lumia-tools' ),
	'words'     => __( 'Words on scroll', 'lumia-tools' ),
	'letters'   => __( 'Letters on scroll', 'lumia-tools' ),
	'counter'   => __( 'Counter', 'lumia-tools' ),
];

$field_labels = [
	'start_edge'   => __( 'Start edge', 'lumia-tools' ),
	'start_at'     => __( 'Start position', 'lumia-tools' ),
	'end_edge'     => __( 'End edge', 'lumia-tools' ),
	'end_at'       => __( 'End position', 'lumia-tools' ),
	'delay'        => __( 'Delay (s)', 'lumia-tools' ),
	'duration'     => __( 'Duration (s)', 'lumia-tools' ),
	'stagger'      => __( 'Stagger (s)', 'lumia-tools' ),
	'distance'     => __( 'Distance (px)', 'lumia-tools' ),
	'blur'         => __( 'Blur (px)', 'lumia-tools' ),
	'scale'        => __( 'Scale', 'lumia-tools' ),
	'from_opacity' => __( 'Starting opacity', 'lumia-tools' ),
	'start_value'  => __( 'Starting value', 'lumia-tools' ),
];

// Text lines slide by a share of their own height, not by pixels.
$distance_percent_label = __( 'Distance (%)', 'lumia-tools' );

// Number inputs: [ min, max, step ].
$number_ranges = [
	'delay'        => [ 0, 10, 0.05 ],
	'duration'     => [ 0, 10, 0.05 ],
	'stagger'      => [ 0, 2, 0.01 ],
	'distance'     => [ -500, 500, 1 ],
	'blur'         => [ 0, 100, 1 ],
	'scale'        => [ 0, 3, 0.01 ],
	'from_opacity' => [ 0, 1, 0.01 ],
	'start_value'  => [ -1000000000, 1000000000, 1 ],
];
?>

<form id="lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="motion" aria-label="<?php esc_attr_e( 'Motion module sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="animations"><?php esc_html_e( 'Animations', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="scrolling"><?php esc_html_e( 'Scrolling', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="builder"><?php esc_html_e( 'Builder', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="compatibility"><?php esc_html_e( 'Compatibility', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="motion" data-lumia-tab-panel="animations">

	<!-- ============================================================
		DEFAULT TEMPO AND EASING
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Pace and curve', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'How animations move by default. Each element can override them in the Bricks builder.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_tempo" class="lumia-option__label"><?php esc_html_e( 'Tempo', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Durations and staggers are multiplied by 0.5 (very fast), 0.75 (fast), 1 (normal), 1.5 (slow) or 2 (very slow). Delays are never scaled.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<select id="lumia_motion_tempo" name="lumia_module_settings[tempo]" class="lumia-select">
						<?php foreach ( array_keys( Effects::TEMPOS ) as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tempo, $key ); ?>><?php echo esc_html( $tempo_labels[ $key ] ?? $key ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_ease" class="lumia-option__label"><?php esc_html_e( 'Easing curve', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Smooth, snappy and bouncy are springs: smooth settles without overshoot, snappy overshoots slightly, bouncy overshoots more and rebounds. Linear keeps a constant speed.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<select id="lumia_motion_ease" name="lumia_module_settings[ease]" class="lumia-select">
						<?php foreach ( Effects::EASES as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ease, $key ); ?>><?php echo esc_html( $ease_labels[ $key ] ?? $key ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_ease_custom" class="lumia-option__label"><?php esc_html_e( 'Custom curve', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Used when the easing curve is set to Custom. Four numbers, for example cubic-bezier(0.2, 0.8, 0.2, 1). The first and third must be between 0 and 1; an invalid curve falls back to the smooth curve.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<input
						type="text"
						id="lumia_motion_ease_custom"
						name="lumia_module_settings[ease_custom]"
						value="<?php echo esc_attr( $ease_custom ); ?>"
						placeholder="cubic-bezier(0.2, 0.8, 0.2, 1)"
						maxlength="80"
						autocomplete="off"
						spellcheck="false"
						class="lumia-input lumia-mo__curve"
					>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		REFERENCE VALUES, ONE ROW PER EFFECT
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Reference values', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Used by every element of an effect that does not set its own value. The start edge is the part of the element that triggers the animation; the start position is how far down the screen that edge must be (98% is almost at the bottom). Effects that follow the scroll also have an end.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-mo__effects">
				<?php
				foreach ( Effects::catalog() as $slug => $definition ) :
					$values = is_array( $effects[ $slug ] ?? null ) ? $effects[ $slug ] : [];
					if ( [] === $values ) {
						continue;
					}
					?>
					<fieldset class="lumia-mo__effect">
						<legend class="lumia-mo__effect-name">
							<?php echo esc_html( $effect_labels[ $slug ] ?? $slug ); ?>
							<code class="lumia-mo__effect-class"><?php echo esc_html( $definition['class'] ); ?></code>
						</legend>
						<div class="lumia-mo__fields">
							<?php
							foreach ( $values as $key => $value ) :
								$key      = (string) $key;
								$field_id = 'lumia_motion_' . str_replace( '-', '_', $slug ) . '_' . $key;
								$name     = 'lumia_module_settings[effects][' . $slug . '][' . $key . ']';
								$label    = 'distance' === $key && 'lines' === $slug ? $distance_percent_label : ( $field_labels[ $key ] ?? $key );
								?>
								<div class="lumia-mo__field">
									<label for="<?php echo esc_attr( $field_id ); ?>" class="lumia-mo__label"><?php echo esc_html( $label ); ?></label>
									<?php if ( 'start_edge' === $key || 'end_edge' === $key ) : ?>
										<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="lumia-select lumia-select--sm">
											<?php foreach ( Effects::EDGES as $edge ) : ?>
												<option value="<?php echo esc_attr( $edge ); ?>" <?php selected( (string) $value, $edge ); ?>><?php echo esc_html( $edge_labels[ $edge ] ?? $edge ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php elseif ( 'start_at' === $key || 'end_at' === $key ) : ?>
										<input
											type="text"
											id="<?php echo esc_attr( $field_id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											value="<?php echo esc_attr( (string) $value ); ?>"
											maxlength="8"
											autocomplete="off"
											spellcheck="false"
											class="lumia-input lumia-input--xs"
										>
									<?php else : ?>
										<?php $range = $number_ranges[ $key ] ?? [ -1000000000, 1000000000, 1 ]; ?>
										<input
											type="number"
											id="<?php echo esc_attr( $field_id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											value="<?php echo esc_attr( (string) $value ); ?>"
											min="<?php echo esc_attr( (string) $range[0] ); ?>"
											max="<?php echo esc_attr( (string) $range[1] ); ?>"
											step="any"
											class="lumia-input lumia-input--xs"
										>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endforeach; ?>
			</div>
			<p class="lumia-form__help"><?php esc_html_e( 'The underline effect has no reference value: it is styled with the CSS variables --lm-underline-thickness, --lm-underline-gap, --lm-underline-color and --lm-underline-duration.', 'lumia-tools' ); ?></p>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="motion" data-lumia-tab-panel="scrolling" hidden>

	<!-- ============================================================
		SMOOTH SCROLLING
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Smooth scrolling', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Eases the mouse wheel on desktop. Touch screens keep their native scrolling.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_lenis_enabled" class="lumia-option__label"><?php esc_html_e( 'Smooth scrolling', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Loaded on every public page, with or without animation, so the scroll feels the same everywhere.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_motion_lenis_enabled"
							name="lumia_module_settings[lenis][enabled]"
							value="1"
							<?php checked( ! empty( $lenis['enabled'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_lenis_lerp" class="lumia-option__label"><?php esc_html_e( 'Intensity', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Between 0.01 and 1. A low value glides for longer, 1 follows the wheel with no easing.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<input
						type="number"
						id="lumia_motion_lenis_lerp"
						name="lumia_module_settings[lenis][lerp]"
						value="<?php echo esc_attr( (string) ( $lenis['lerp'] ?? 0.1 ) ); ?>"
						min="0.01"
						max="1"
						step="any"
						class="lumia-input lumia-input--xs"
					>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="motion" data-lumia-tab-panel="builder" hidden>

	<!-- ============================================================
		BUILDER PREVIEW
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Builder preview', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Elements always appear in their final state in the builder, so the page can be built without hidden content.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_builder_preview" class="lumia-option__label"><?php esc_html_e( 'Animation preview', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Replaying plays an element once when one of its Motion settings changes.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<select id="lumia_motion_builder_preview" name="lumia_module_settings[builder_preview]" class="lumia-select">
						<option value="replay" <?php selected( $builder_preview, 'replay' ); ?>><?php esc_html_e( 'Replay on change', 'lumia-tools' ); ?></option>
						<option value="off" <?php selected( $builder_preview, 'off' ); ?>><?php esc_html_e( 'Never animate', 'lumia-tools' ); ?></option>
					</select>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="motion" data-lumia-tab-panel="compatibility" hidden>

	<!-- ============================================================
		COMPATIBILITY
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Compatibility', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'For sites that used the former animation snippet of the agency.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_motion_legacy_classes" class="lumia-option__label"><?php esc_html_e( 'Former class names', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Keeps the classes of the former snippet working until the content is moved to the lm- classes. Turn it off once the migration is done.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_motion_legacy_classes"
							name="lumia_module_settings[legacy_classes]"
							value="1"
							<?php checked( ! empty( $module_settings['legacy_classes'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		MIGRATION OF THE FORMER CLASSES
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Migration', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Moves the content from the former classes to the lm- classes. It runs once per site, from the command line, and keeps every element and its animation.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<?php if ( null === $legacy_usage ) : ?>
				<div class="lumia-notice lumia-notice--warning">
					<?php esc_html_e( 'The content could not be read to count the former classes.', 'lumia-tools' ); ?>
				</div>
			<?php elseif ( [] === $legacy_usage['classes'] && 0 === $legacy_usage['elements'] && [] === $legacy_usage['css_hits'] ) : ?>
				<p class="lumia-form__help"><?php esc_html_e( 'No former class is left on this site. The compatibility setting above can be turned off.', 'lumia-tools' ); ?></p>
			<?php else : ?>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php esc_html_e( 'Former classes', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php esc_html_e( 'Global classes that still carry a former name.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control">
						<span class="lumia-badge lumia-badge--info"><?php echo esc_html( number_format_i18n( count( $legacy_usage['classes'] ) ) ); ?></span>
					</div>
				</div>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php esc_html_e( 'Elements concerned', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of pages, headers, footers and templates. */
									__( 'Spread over %s pages, headers, footers or templates.', 'lumia-tools' ),
									number_format_i18n( $legacy_usage['posts'] )
								)
							);
							?>
						</p>
					</div>
					<div class="lumia-option__control">
						<span class="lumia-badge lumia-badge--info"><?php echo esc_html( number_format_i18n( $legacy_usage['elements'] ) ); ?></span>
					</div>
				</div>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php esc_html_e( 'Selectors that will stop matching', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php esc_html_e( 'Custom CSS that targets a class that is renamed. The content then carries the new name and the compatibility setting does not bring the old one back, so fix these before or right after the migration. They are listed, never rewritten.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control">
						<span class="lumia-badge <?php echo [] === $legacy_usage['selectors'] ? 'lumia-badge--success' : 'lumia-badge--danger'; ?>"><?php echo esc_html( number_format_i18n( count( $legacy_usage['selectors'] ) ) ); ?></span>
					</div>
				</div>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php esc_html_e( 'Kept working by compatibility', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php esc_html_e( 'Former names typed in a CSS classes field, former underline variables, and selectors of classes that keep their name. They stop working when the compatibility setting is turned off.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control">
						<span class="lumia-badge <?php echo [] === $legacy_usage['compat'] ? 'lumia-badge--success' : 'lumia-badge--warning'; ?>"><?php echo esc_html( number_format_i18n( count( $legacy_usage['compat'] ) ) ); ?></span>
					</div>
				</div>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php esc_html_e( 'Published snippets', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php esc_html_e( 'FluentSnippets files that are published and still name the former classes. Deactivate or delete them.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control">
						<span class="lumia-badge <?php echo [] === $legacy_usage['snippets'] ? 'lumia-badge--success' : 'lumia-badge--warning'; ?>"><?php echo esc_html( number_format_i18n( count( $legacy_usage['snippets'] ) ) ); ?></span>
					</div>
				</div>
			<?php endif; ?>

			<p class="lumia-form__help">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: a WP-CLI command that previews the migration, 2: the same command that applies it. */
						__( 'Preview it with %1$s, which writes nothing and lists everything it found. Apply it with %2$s.', 'lumia-tools' ),
						'<code>wp lumia motion migrate</code>',
						'<code>wp lumia motion migrate --apply --user=&lt;admin&gt;</code>'
					),
					[ 'code' => [] ]
				);
				?>
			</p>
			<p class="lumia-form__help">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: name of the former animation snippet. */
						__( 'Then deactivate (or delete) the "%s" snippet in FluentSnippets, purge the page cache, check the site, and turn off the compatibility setting above once nothing is kept working by it.', 'lumia-tools' ),
						Legacy::SNIPPET_NAME
					)
				);
				?>
			</p>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
