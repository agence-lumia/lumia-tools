<?php
/**
 * MCP Connect module screen: connections, settings, help.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (mcp_connect)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\McpConnect\Abilities\Registry;
use Lumia\Tools\Modules\McpConnect\Agent;
use Lumia\Tools\Modules\McpConnect\Discovery;
use Lumia\Tools\Modules\McpConnect\Module as McpModule;
use Lumia\Tools\Modules\McpConnect\SiteHealth;

$mc_disabled  = (bool) Compat::constant( 'DISABLE_MCP_CONNECT' );
$mc_paused    = ! empty( $module_settings['paused'] );
$mc_url       = Discovery::resource_url();
$mc_presets   = Agent::presets();
$mc_preset    = Agent::current_preset();
$mc_forced    = null !== Agent::forced_preset();
$mc_approver  = (string) $module_settings['approver_capability'];
$mc_checks    = SiteHealth::checks();
$mc_families  = Registry::describe_families();
$mc_cli_name  = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$mc_cli       = 'claude mcp add --transport http ' . ( '' !== $mc_cli_name ? $mc_cli_name : 'site' ) . ' ' . $mc_url;
$mc_approvers = [
	'manage_options'    => __( 'Administrators (manage_options)', 'lumia-tools' ),
	'edit_others_pages' => __( 'Editors and administrators (edit_others_pages)', 'lumia-tools' ),
];
// Site Health state → badge of the design system (badges hold a word, the
// sentence goes next to it).
$mc_badges = [
	'good'        => [ 'lumia-badge--success', __( 'OK', 'lumia-tools' ) ],
	'critical'    => [ 'lumia-badge--danger', __( 'Required', 'lumia-tools' ) ],
	'recommended' => [ 'lumia-badge--warning', __( 'Recommended', 'lumia-tools' ) ],
	'info'        => [ 'lumia-badge--neutral', __( 'Info', 'lumia-tools' ) ],
];
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="mcp_connect" aria-label="<?php esc_attr_e( 'MCP Connect module sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="connections"><?php esc_html_e( 'Connections', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Settings', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="help"><?php esc_html_e( 'Help', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<?php if ( $mc_disabled ) : ?>
		<div class="lumia-notice lumia-notice--error">
			<?php esc_html_e( 'MCP Connect is turned off by the LUMIA_DISABLE_MCP_CONNECT constant in wp-config.php: Claude cannot discover, sign in to or use this site. Connections are kept and work again once the constant is removed.', 'lumia-tools' ); ?>
		</div>
	<?php endif; ?>

	<!-- ============================================================
		CONNECTIONS
		============================================================ -->
	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="mcp_connect" data-lumia-tab-panel="connections">

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Connections', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Each approval in Claude creates a connection. Revoking it cuts its access immediately; Claude then asks to connect again.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-mc" id="lumia-mc" data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>">

				<div class="lumia-mc__toolbar">
					<div class="lumia-mc__status">
						<?php if ( $mc_disabled ) : ?>
							<span class="lumia-badge lumia-badge--danger"><?php echo esc_html_x( 'Off', 'MCP Connect status', 'lumia-tools' ); ?></span>
						<?php elseif ( $mc_paused ) : ?>
							<span class="lumia-badge lumia-badge--warning"><?php esc_html_e( 'Paused', 'lumia-tools' ); ?></span>
							<span><?php esc_html_e( 'Every request from Claude is refused until connections are resumed in Settings.', 'lumia-tools' ); ?></span>
						<?php else : ?>
							<span class="lumia-badge lumia-badge--success"><?php esc_html_e( 'Active', 'lumia-tools' ); ?></span>
							<span id="lumia-mc-total"></span>
						<?php endif; ?>
					</div>
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-mc-revoke-all"><?php esc_html_e( 'Revoke all', 'lumia-tools' ); ?></button>
				</div>

				<div class="lumia-mc__table-wrap">
					<table class="lumia-mc__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Label', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Client', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Acts as', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Approved by', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Created', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last used', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last IP', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Refresh token expires', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'lumia-tools' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'lumia-tools' ); ?></span></th>
							</tr>
						</thead>
						<tbody id="lumia-mc-rows">
							<tr><td colspan="10" class="lumia-mc__state"><?php esc_html_e( 'Loading…', 'lumia-tools' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="lumia-empty lumia-mc__empty" id="lumia-mc-empty" hidden>
					<?php echo $this->render_icon( 'plug', 'xl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Lucide markup. ?>
					<p><?php esc_html_e( 'No connection yet. Add this site in Claude: see Help.', 'lumia-tools' ); ?></p>
				</div>
			</div>
		</div>
	</div>

	</div><!-- Connections panel -->

	<!-- ============================================================
		SETTINGS
		============================================================ -->
	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="mcp_connect" data-lumia-tab-panel="settings" hidden>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Access', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Who may connect Claude, and what Claude may do once connected.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_mc_paused" class="lumia-option__label">
						<?php esc_html_e( 'Pause all connections', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( "To cut everything without the admin (locked out, emergency), add define( 'LUMIA_DISABLE_MCP_CONNECT', true ); to wp-config.php.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Every request from Claude is refused and no new connection can be approved. Connections are kept and work again when you resume.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_mc_paused" name="lumia_module_settings[paused]" value="1" <?php checked( $mc_paused ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_mc_approver" class="lumia-form__label"><?php esc_html_e( 'Who can approve a connection', 'lumia-tools' ); ?></label>
					<select id="lumia_mc_approver" name="lumia_module_settings[approver_capability]" class="lumia-select lumia-select--sm">
						<?php foreach ( $mc_approvers as $mc_value => $mc_label ) : ?>
							<option value="<?php echo esc_attr( $mc_value ); ?>" <?php selected( $mc_approver, $mc_value ); ?>><?php echo esc_html( $mc_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="lumia-form__group">
					<label for="lumia_mc_preset" class="lumia-form__label"><?php esc_html_e( 'Permissions of the Claude account', 'lumia-tools' ); ?></label>
					<select id="lumia_mc_preset" name="lumia_module_settings[agent_preset]" class="lumia-select lumia-select--sm" <?php disabled( $mc_forced ); ?>>
						<?php foreach ( $mc_presets as $mc_value => $mc_data ) : ?>
							<option value="<?php echo esc_attr( (string) $mc_value ); ?>" <?php selected( $mc_preset, (string) $mc_value ); ?>><?php echo esc_html( (string) $mc_data['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<p class="lumia-form__help"><?php esc_html_e( 'Claude acts through a dedicated account that cannot sign in by itself. Administrator (default): every right of an administrator, plus JavaScript, unfiltered uploads (with ALLOW_UNFILTERED_UPLOADS) and Bricks code execution. Editor: what an administrator can do, including what plugins grant them (pages, Bricks, media, menus, shop, SEO, forms…), but never the WordPress settings, plugins, themes, users, roles or code.', 'lumia-tools' ); ?></p>

			<?php if ( $mc_forced ) : ?>
				<div class="lumia-notice lumia-notice--warning"><?php esc_html_e( 'Set by the LUMIA_MCP_AGENT_PRESET constant in wp-config.php: remove it to choose here.', 'lumia-tools' ); ?></div>
			<?php endif; ?>
			<?php if ( Agent::PRESET_ADMIN === $mc_preset ) : ?>
				<div class="lumia-notice lumia-notice--warning"><?php esc_html_e( 'Anyone holding a connection controls the whole site, code included: revoke the connections once the work is done.', 'lumia-tools' ); ?></div>
			<?php endif; ?>

			<?php foreach ( [ 'code', 'plugins', 'bricks_abilities', 'bricks' ] as $mc_key ) : ?>
				<?php if ( 'recommended' === ( $mc_checks[ $mc_key ]['state'] ?? '' ) ) : ?>
					<div class="lumia-notice lumia-notice--warning"><?php echo esc_html( $mc_checks[ $mc_key ]['value'] ); ?></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Tools for Claude', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'The lumia/* abilities Claude can use on this site, on top of the Bricks ones. Each one still checks the rights of the Claude account.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<?php if ( ! function_exists( 'wp_register_ability' ) ) : ?>
				<div class="lumia-notice lumia-notice--warning"><?php esc_html_e( 'The Abilities API needs WordPress 6.9 or later: these tools are not available on this site.', 'lumia-tools' ); ?></div>
			<?php endif; ?>
			<input type="hidden" name="lumia_module_settings[abilities][_sent]" value="1">
			<?php foreach ( $mc_families as $mc_family => $mc_info ) : ?>
				<div class="lumia-option">
					<div class="lumia-option__content">
						<label for="lumia_mc_family_<?php echo esc_attr( $mc_family ); ?>" class="lumia-option__label">
							<?php echo esc_html( $mc_info['label'] ); ?>
							<?php if ( ! $mc_info['available'] ) : ?>
								<span class="lumia-badge lumia-badge--neutral"><?php esc_html_e( 'Plugin not active', 'lumia-tools' ); ?></span>
							<?php endif; ?>
						</label>
						<p class="lumia-option__desc"><?php echo esc_html( $mc_info['description'] ); ?></p>
					</div>
					<div class="lumia-option__control">
						<label class="lumia-toggle">
							<input type="checkbox" id="lumia_mc_family_<?php echo esc_attr( $mc_family ); ?>" name="lumia_module_settings[abilities][<?php echo esc_attr( $mc_family ); ?>]" value="1" <?php checked( ! empty( $module_settings['abilities'][ $mc_family ] ) ); ?>>
							<span class="lumia-toggle__slider"></span>
						</label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Clients', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Which applications may ask to connect.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_mc_trusted_only" class="lumia-option__label"><?php esc_html_e( 'Trusted publishers only', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Only applications published by Anthropic (claude.ai) can request a connection: Claude, Claude Code and Claude Desktop.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_mc_trusted_only" name="lumia_module_settings[trusted_only]" value="1" <?php checked( ! empty( $module_settings['trusted_only'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_mc_dcr" class="lumia-option__label"><?php esc_html_e( 'Dynamic client registration', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Lets MCP clients that do not publish their identity register themselves. Off unless a client you use requires it.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_mc_dcr" name="lumia_module_settings[dcr_enabled]" value="1" <?php checked( ! empty( $module_settings['dcr_enabled'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-notice lumia-notice--warning">
				<?php esc_html_e( 'Self-registered clients are unverified: anyone can register one under any name. While "Trusted publishers only" is on, they are always refused.', 'lumia-tools' ); ?>
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_mc_dcr_purge" class="lumia-form__label"><?php esc_html_e( 'Delete unused registered clients after (days)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_mc_dcr_purge" name="lumia_module_settings[dcr_purge_days]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['dcr_purge_days'] ); ?>" min="<?php echo esc_attr( (string) McpModule::DCR_PURGE_MIN ); ?>" max="<?php echo esc_attr( (string) McpModule::DCR_PURGE_MAX ); ?>">
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Tokens', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Claude renews its access token on its own with the refresh token. A connection unused for longer than the refresh token lifetime must be approved again.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_mc_access_ttl" class="lumia-form__label"><?php esc_html_e( 'Access token lifetime (minutes)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_mc_access_ttl" name="lumia_module_settings[access_token_ttl]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['access_token_ttl'] ); ?>" min="<?php echo esc_attr( (string) McpModule::ACCESS_TTL_MIN ); ?>" max="<?php echo esc_attr( (string) McpModule::ACCESS_TTL_MAX ); ?>">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_mc_refresh_ttl" class="lumia-form__label"><?php esc_html_e( 'Refresh token lifetime (days)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_mc_refresh_ttl" name="lumia_module_settings[refresh_token_ttl]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['refresh_token_ttl'] ); ?>" min="<?php echo esc_attr( (string) McpModule::REFRESH_TTL_MIN ); ?>" max="<?php echo esc_attr( (string) McpModule::REFRESH_TTL_MAX ); ?>">
				</div>
			</div>
		</div>
	</div>

	</div><!-- Settings panel -->

	<!-- ============================================================
		HELP
		============================================================ -->
	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="mcp_connect" data-lumia-tab-panel="help" hidden>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Add this site to Claude', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'The URL to paste in Claude when adding a custom connector.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-mc__copy">
				<!-- No name attribute: read-only, never posted with the settings. -->
				<input type="text" class="lumia-input" id="lumia-mc-url" value="<?php echo esc_attr( $mc_url ); ?>" readonly
					aria-label="<?php esc_attr_e( 'MCP server URL', 'lumia-tools' ); ?>">
				<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm" data-mc-copy="#lumia-mc-url">
					<?php echo $this->render_icon( 'copy', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Lucide markup. ?>
					<?php esc_html_e( 'Copy', 'lumia-tools' ); ?>
				</button>
			</div>

			<ol class="lumia-mc__steps">
				<li><?php esc_html_e( 'In Claude, open Settings > Connectors and choose Add custom connector.', 'lumia-tools' ); ?></li>
				<li><?php esc_html_e( 'Paste the URL above. Leave Client ID and Client Secret empty.', 'lumia-tools' ); ?></li>
				<li><?php esc_html_e( 'Click Connect: Claude opens the sign-in page of this site.', 'lumia-tools' ); ?></li>
				<li><?php esc_html_e( 'Sign in to WordPress if asked, check the request, then approve it.', 'lumia-tools' ); ?></li>
			</ol>
			<p class="lumia-form__help">
				<?php
				$mc_who = $mc_approvers[ $mc_approver ] ?? $mc_approver;
				/* translators: %s: users allowed to approve, e.g. "Administrators (manage_options)". */
				echo esc_html( sprintf( __( 'Only these users can approve a connection: %s.', 'lumia-tools' ), $mc_who ) );
				?>
			</p>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Claude Code', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Run this command, then /mcp in Claude Code to sign in.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-mc__copy">
				<pre class="lumia-mc__code" id="lumia-mc-cli"><code><?php echo esc_html( $mc_cli ); ?></code></pre>
				<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm" data-mc-copy="#lumia-mc-cli">
					<?php echo $this->render_icon( 'copy', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Lucide markup. ?>
					<?php esc_html_e( 'Copy', 'lumia-tools' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Status', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php esc_html_e( 'What this site needs for Claude to connect.', 'lumia-tools' ); ?>
				<a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Site Health also checks the discovery documents and the Authorization header.', 'lumia-tools' ); ?></a>
			</p>
		</div>
		<div class="lumia-section__content">
			<table class="lumia-server-table">
				<tbody>
					<?php foreach ( $mc_checks as $mc_check ) : ?>
						<?php $mc_badge = $mc_badges[ $mc_check['state'] ] ?? $mc_badges['info']; ?>
						<tr>
							<td class="lumia-server-table__label"><?php echo esc_html( $mc_check['label'] ); ?></td>
							<td class="lumia-mc__check">
								<span class="lumia-badge <?php echo esc_attr( $mc_badge[0] ); ?>"><?php echo esc_html( $mc_badge[1] ); ?></span>
								<span><?php echo esc_html( $mc_check['value'] ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	</div><!-- Help panel -->

	</div><!-- .lumia-module-form__scroll -->

</form>
