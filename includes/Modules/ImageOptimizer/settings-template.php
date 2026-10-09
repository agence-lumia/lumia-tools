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
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="svg"><?php esc_html_e( 'SVG', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="bulk"><?php esc_html_e( 'Bulk optimization', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="settings">

	<!-- Behavior -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Behavior', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Configure how images are processed on upload.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_optimize_on_upload" class="lumia-option__label"><?php echo esc_html__( 'Optimize on upload', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Automatically resizes, compresses and converts images.', 'lumia-tools' ); ?></p>
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
					<label for="lumia_format_mode" class="lumia-option__label"><?php echo esc_html__( 'Output format', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( '“Auto” picks AVIF if the server can encode it, otherwise WebP. A format chosen explicitly but not supported converts nothing at all: there is no fallback.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Automatic image conversion format.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<select id="lumia_format_mode"
							name="lumia_module_settings[format_mode]"
							class="lumia-select lumia-select--sm">
						<option value="auto" <?php selected( $module_settings['format_mode'], 'auto' ); ?>>
							<?php echo esc_html__( 'Auto', 'lumia-tools' ); ?>
						</option>
						<option value="avif" <?php selected( $module_settings['format_mode'], 'avif' ); ?>>
							<?php echo esc_html__( 'AVIF', 'lumia-tools' ); ?>
						</option>
						<option value="webp" <?php selected( $module_settings['format_mode'], 'webp' ); ?>>
							<?php echo esc_html__( 'WebP', 'lumia-tools' ); ?>
						</option>
					</select>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Quality and dimensions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Quality and dimensions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Adjust the compression quality and the maximum dimensions.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__group">
				<label for="lumia_quality" class="lumia-form__label"><?php echo esc_html__( 'Compression quality (1-100)', 'lumia-tools' ); ?></label>
				<input type="number"
						id="lumia_quality"
						name="lumia_module_settings[quality]"
						class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $module_settings['quality'] ); ?>"
						min="1"
						max="100">
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_max_width" class="lumia-form__label"><?php echo esc_html__( 'Max width (px)', 'lumia-tools' ); ?></label>
					<input type="number"
							id="lumia_max_width"
							name="lumia_module_settings[max_width]"
							class="lumia-input"
							value="<?php echo esc_attr( $module_settings['max_width'] ); ?>"
							min="100">
				</div>

				<div class="lumia-form__group">
					<label for="lumia_max_height" class="lumia-form__label"><?php echo esc_html__( 'Max height (px)', 'lumia-tools' ); ?></label>
					<input type="number"
							id="lumia_max_height"
							name="lumia_module_settings[max_height]"
							class="lumia-input"
							value="<?php echo esc_attr( $module_settings['max_height'] ); ?>"
							min="100">
				</div>
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
					<label for="lumia_strip_exif" class="lumia-option__label"><?php echo esc_html__( 'Remove EXIF metadata', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Irreversible, and the cleanup takes the whole block with it, copyright notice and color profile included. Leave it enabled unless the site publishes author-credited photos.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
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

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_keep_original" class="lumia-option__label"><?php echo esc_html__( 'Keep the original', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Doubles the disk space used by the media library. Keep it on until the conversion has been validated on the site, then turn it off.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Keeps an untouched copy of the source file, so the image can be restored or re-optimized without loss from its details screen.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_keep_original"
								name="lumia_module_settings[keep_original]"
								value="1"
								<?php checked( $module_settings['keep_original'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
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
