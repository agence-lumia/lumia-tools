<?php
/**
 * Settings template of the Image Optimizer module.
 *
 * Available variables: $instance, $tab, $module_id, $module
 */

defined( 'ABSPATH' ) || exit;

$module_settings = $instance->get_settings();
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="image_optimizer" aria-label="<?php esc_attr_e( 'Image Optimizer sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Settings', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="delivery"><?php esc_html_e( 'Delivery', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="svg"><?php esc_html_e( 'SVG', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="bulk"><?php esc_html_e( 'Bulk optimization', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="settings">

	<!-- Behavior -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Behavior', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'The JPEG or PNG made by WordPress stays the image file, at the same URL. An AVIF version is generated next to it in the background and served to the browsers that accept it.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_optimize_on_upload" class="lumia-option__label"><?php echo esc_html__( 'Optimize on upload', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Queues every new JPEG or PNG for its AVIF version, right after the upload.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_optimize_on_upload"
								name="lumia_module_settings[optimize_on_upload]"
								value="1"
								<?php checked( $module_settings['optimize_on_upload'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_convert_modern_uploads" class="lumia-option__label"><?php echo esc_html__( 'Convert AVIF and WebP uploads', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Some email clients cannot display AVIF or WebP. Animated images are always kept as uploaded.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Turns an uploaded AVIF or WebP into a PNG (transparency, flat colors) or a JPEG that every client can read; its AVIF version then follows as for any other image.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_convert_modern_uploads"
								name="lumia_module_settings[convert_modern_uploads]"
								value="1"
								<?php checked( $module_settings['convert_modern_uploads'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Quality and dimensions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Quality and dimensions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Adjust the AVIF encoding and the maximum dimension of the uploads.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_quality" class="lumia-form__label"><?php echo esc_html__( 'AVIF quality (1-100)', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( '70 recommended: sharper than the JPEG made by WordPress, for about 30% fewer bytes.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="number"
							id="lumia_quality"
							name="lumia_module_settings[quality]"
							class="lumia-input lumia-input--sm"
							value="<?php echo esc_attr( $module_settings['quality'] ); ?>"
							min="1"
							max="100">
				</div>

				<div class="lumia-form__group">
					<label for="lumia_speed" class="lumia-form__label"><?php echo esc_html__( 'Encoding speed', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Fast takes less CPU time per image, for heavier files: about 5% for photos, a lot more for PNG images. Balanced is recommended.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<select id="lumia_speed"
							name="lumia_module_settings[speed]"
							class="lumia-select lumia-select--sm">
						<option value="balanced" <?php selected( $module_settings['speed'], 'balanced' ); ?>>
							<?php echo esc_html__( 'Balanced', 'lumia-tools' ); ?>
						</option>
						<option value="fast" <?php selected( $module_settings['speed'], 'fast' ); ?>>
							<?php echo esc_html__( 'Fast', 'lumia-tools' ); ?>
						</option>
					</select>
				</div>
			</div>

			<div class="lumia-form__group">
				<label for="lumia_max_dimension" class="lumia-form__label"><?php echo esc_html__( 'Maximum dimension (px)', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'WordPress scales down larger uploads to this width or height (the original is kept aside). 0 turns the limit off.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="number"
						id="lumia_max_dimension"
						name="lumia_module_settings[max_dimension]"
						class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $module_settings['max_dimension'] ); ?>"
						min="0">
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Exclusions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Exclusions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Images that keep their original format only.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__group">
				<label for="lumia_exclude_suffixes" class="lumia-form__label"><?php echo esc_html__( 'Excluded name suffixes', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Case-insensitive, before the extension: with -noopt, logo-noopt.png and logo-noopt-2.png get no AVIF version. Separate suffixes with commas.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="text"
						id="lumia_exclude_suffixes"
						name="lumia_module_settings[exclude_suffixes]"
						class="lumia-input"
						value="<?php echo esc_attr( implode( ', ', (array) $module_settings['exclude_suffixes'] ) ); ?>">
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Advanced options -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Advanced options', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Additional options for image processing.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_strip_exif" class="lumia-option__label"><?php echo esc_html__( 'Remove EXIF metadata', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Irreversible: the copyright notice goes with the rest. The color profile is kept. Leave it enabled unless the site publishes author-credited photos.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Removes GPS data, camera details, etc.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_strip_exif"
								name="lumia_module_settings[strip_exif]"
								value="1"
								<?php checked( $module_settings['strip_exif'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_generate_alt" class="lumia-option__label"><?php echo esc_html__( 'Generate alt text', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Only applies to new uploads and never overwrites alt text that has already been entered. A file name is only worth so much: review it for images that carry meaning.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Automatically creates the alt text from the file name.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_generate_alt"
								name="lumia_module_settings[generate_alt]"
								value="1"
								<?php checked( $module_settings['generate_alt'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="delivery" hidden>

	<!-- Delivery self-test -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Delivery', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'The AVIF version is only generated once a test proves that the web server serves it to the browsers that accept it, and the JPEG or PNG to everyone else (email clients, social networks, feeds). The test runs on activation, on every save, every day, and from your browser when this tab opens.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div id="lumia-delivery-status">
				<?php echo \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::render_status(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delivery-status.php. ?>
			</div>
			<div class="lumia-form__actions">
				<button type="button" id="lumia-delivery-retest" class="lumia-btn lumia-btn--secondary">
					<svg class="lumia-icon lumia-icon--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
					<?php echo esc_html__( 'Retest', 'lumia-tools' ); ?>
				</button>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="svg" hidden>

	<!-- SVG uploads -->
	<?php $svg_enabled = ! empty( $module_settings['svg_upload'] ); ?>
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'SVG uploads', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'SVG files are XML and may contain malicious code. Once enabled, every uploaded SVG is automatically sanitized (JavaScript, event handlers and external references are removed).', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_svg_upload" class="lumia-option__label"><?php echo esc_html__( 'Allow SVG uploads', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Enables uploading sanitized .svg files to the media library.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_svg_upload"
								name="lumia_module_settings[svg_upload]"
								value="1"
								data-svg-master
								<?php checked( $svg_enabled, true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<?php $svg_roles = (array) ( $module_settings['svg_roles'] ?? [] ); ?>
			<div class="lumia-svg-roles<?php echo $svg_enabled ? '' : ' is-disabled'; ?>" id="lumia-svg-roles">
				<div class="lumia-svg-roles__head">
					<span class="lumia-svg-roles__title"><?php echo esc_html__( 'Allowed roles', 'lumia-tools' ); ?></span>
					<span class="lumia-svg-roles__hint"><?php echo esc_html__( 'Only these roles will be able to upload SVG files.', 'lumia-tools' ); ?></span>
				</div>
				<div class="lumia-svg-roles__grid">
					<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
						<label class="lumia-svg-role">
							<span class="lumia-toggle">
								<input type="checkbox"
										name="lumia_module_settings[svg_roles][]"
										value="<?php echo esc_attr( $role_slug ); ?>"
										<?php checked( in_array( $role_slug, $svg_roles, true ), true ); ?>>
								<span class="lumia-toggle__slider"></span>
							</span>
							<span class="lumia-svg-role__name"><?php echo esc_html( translate_user_role( $role_name ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="bulk" hidden>

	<!-- Bulk optimization -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Bulk optimization', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Optimizes the images already in the media library.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-bulk" id="lumia-bulk">

				<!-- Initial state: run a scan before showing figures -->
				<div class="lumia-bulk__scan" id="lumia-bulk-scan-intro">
					<p class="lumia-bulk__scan-hint">
						<?php echo esc_html__( 'Scan the media library to find out how many images are left to optimize.', 'lumia-tools' ); ?>
					</p>
					<button type="button" id="lumia-bulk-scan" class="lumia-btn lumia-btn--secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<?php echo esc_html__( 'Scan the media library', 'lumia-tools' ); ?>
					</button>
				</div>

				<!-- Scan result (revealed by the JS) -->
				<div class="lumia-bulk__result" id="lumia-bulk-result" style="display: none;">
					<div class="lumia-bulk__stats">
						<div class="lumia-bulk__stat">
							<span class="lumia-bulk__stat-value" id="lumia-bulk-remaining">0</span>
							<span class="lumia-bulk__stat-label"><?php echo esc_html__( 'images to optimize', 'lumia-tools' ); ?></span>
						</div>
						<div class="lumia-bulk__stat" id="lumia-bulk-potential-tile" style="display: none;">
							<span class="lumia-bulk__stat-value" id="lumia-bulk-potential">—</span>
							<span class="lumia-bulk__stat-label"><?php echo esc_html__( 'estimated potential savings', 'lumia-tools' ); ?></span>
						</div>
					</div>
					<div class="lumia-bulk__action">
						<button type="button" id="lumia-bulk-start" class="lumia-btn lumia-btn--primary" disabled>
							<?php echo esc_html__( 'Start optimization', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<!-- Progress -->
				<div class="lumia-bulk__progress lumia-bulk-status__progress" style="display: none;">
					<div class="lumia-progress">
						<div class="lumia-progress__bar" style="width: 0%"></div>
					</div>
					<span class="lumia-bulk-status__message"></span>
				</div>
			</div>
		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->
</form>
