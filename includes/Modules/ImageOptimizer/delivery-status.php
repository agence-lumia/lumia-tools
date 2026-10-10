<?php
/**
 * Status block of the Image Optimizer's Delivery tab, rendered by DeliveryProbe::render_status()
 * (settings screen and AJAX answers).
 *
 * Available variables: $lumia_delivery (DeliveryProbe::result()), $lumia_probe_url
 */

defined( 'ABSPATH' ) || exit;

// Set by the caller; the fallbacks keep the partial usable (and analyzable) on its own.
$lumia_delivery  = $lumia_delivery ?? \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::result();
$lumia_probe_url = $lumia_probe_url ?? \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::probe_url();

$lumia_mode    = (string) $lumia_delivery['mode'];
$lumia_reason  = (string) $lumia_delivery['reason'];
$lumia_server  = (string) $lumia_delivery['server'];
$lumia_serving = in_array( $lumia_mode, [ 'nginx', 'htaccess' ], true );

$lumia_modes = [
	'nginx'    => [ 'lumia-badge--success', __( 'Served by the web server', 'lumia-tools' ) ],
	'htaccess' => [ 'lumia-badge--success', __( 'Served by the .htaccess rules', 'lumia-tools' ) ],
	'none'     => [ 'lumia-badge--warning', __( 'Not served', 'lumia-tools' ) ],
];
if ( 'pending' === $lumia_reason ) {
	$lumia_modes['none'] = [ 'lumia-badge--neutral', __( 'Not tested yet', 'lumia-tools' ) ];
}

$lumia_purged = __( 'No AVIF is generated, and the AVIF versions already generated were deleted: every client gets the JPEG or PNG.', 'lumia-tools' );
$lumia_purge  = __( 'Purge the CDN cache too: it may still hold AVIF versions.', 'lumia-tools' );
$lumia_paused = __( 'No AVIF is generated until the test passes: every client gets the JPEG or PNG.', 'lumia-tools' );

$lumia_reasons = [
	'pending'      => __( 'Delivery has not been tested yet. Run the test with the button below.', 'lumia-tools' ),
	'no_rule'      => __( 'The web server does not serve the AVIF version to the browsers that accept it.', 'lumia-tools' ) . ' ' . $lumia_paused,
	'no_vary'      => __( 'The web server serves the AVIF version without the "Vary: Accept" header: a cache could then hand it to a client that cannot display it.', 'lumia-tools' ) . ' ' . $lumia_paused,
	'leak'         => __( 'The web server sent the AVIF version to a client that did not ask for it.', 'lumia-tools' ) . ' ' . $lumia_purged,
	'server_error' => __( 'The web server answered with an error (HTTP 500) once the rules were in wp-content/uploads/.htaccess: they were removed at once. The host probably does not allow these directives in .htaccess.', 'lumia-tools' ) . ' ' . $lumia_paused,
	'unreachable'  => __( 'The site could not reach its own images (loopback requests blocked, password protection, or uploads served from another address): delivery cannot be verified.', 'lumia-tools' ) . ' ' . $lumia_paused,
	'cdn_unknown'  => __( 'A cache or CDN that Lümia Tools does not recognize sits in front of the site: it may hand the AVIF version to clients that cannot display it.', 'lumia-tools' ) . ' ' . $lumia_purged . ' ' . $lumia_purge,
	'cdn_vary'     => __( 'The CDN in front of the site does not keep the AVIF and JPEG/PNG versions apart (it ignores "Vary: Accept"). Turn off its cache for images, or make it vary images on the Accept header (on Cloudflare: "Vary for images"), then run the test again.', 'lumia-tools' ) . ' ' . $lumia_purged . ' ' . $lumia_purge,
	'cdn_unproven' => __( 'A CDN sits in front of the site, but the test could not prove that its cache keeps the AVIF and JPEG/PNG versions apart (the test requests were never answered from its cache). Make it vary images on the Accept header (on Cloudflare: "Vary for images"), or turn off its cache for images, then run the test again.', 'lumia-tools' ) . ' ' . $lumia_purged . ' ' . $lumia_purge,
	'browser'      => __( 'Your browser received the wrong version of the test image, probably from a CDN or a proxy that ignores "Vary: Accept".', 'lumia-tools' ) . ' ' . $lumia_purged . ' ' . $lumia_purge,
	'write_failed' => __( 'WordPress could not write wp-content/uploads/.htaccess (file permissions).', 'lumia-tools' ) . ' ' . $lumia_paused,
	'probe_files'  => __( 'The test images could not be created in wp-content/uploads/lumia-tools/ (file permissions).', 'lumia-tools' ) . ' ' . $lumia_paused,
];
if ( 'htaccess' === $lumia_mode ) {
	$lumia_reasons['ok'] = __( 'The rules Lümia Tools wrote in wp-content/uploads/.htaccess serve the AVIF version to the browsers that accept it, and the JPEG or PNG to every other client.', 'lumia-tools' );
} else {
	$lumia_reasons['ok'] = __( 'The web server serves the AVIF version to the browsers that accept it, and the JPEG or PNG to every other client.', 'lumia-tools' );
}

$lumia_servers = [
	'apache'    => 'Apache',
	'litespeed' => 'LiteSpeed',
	'nginx'     => 'nginx',
	'other'     => __( 'Other', 'lumia-tools' ),
];
$lumia_cdns    = [
	'cloudflare' => 'Cloudflare',
	'fastly'     => 'Fastly',
	'sucuri'     => 'Sucuri',
	'hostinger'  => 'Hostinger CDN',
	'unknown'    => __( 'Unknown cache or CDN', 'lumia-tools' ),
];

$lumia_browser = [
	'ok'     => __( 'Passed', 'lumia-tools' ),
	'failed' => __( 'Failed', 'lumia-tools' ),
	''       => __( 'Not run', 'lumia-tools' ),
];

$lumia_badge = $lumia_modes[ $lumia_mode ] ?? $lumia_modes['none'];

// The nginx rule to hand over to the host: an nginx (or unknown) server that does not serve
// the AVIF properly. Never for Apache / LiteSpeed (the plugin writes its own rules there).
$lumia_snippet = 'none' === $lumia_mode && ! in_array( $lumia_server, [ 'apache', 'litespeed' ], true )
	&& in_array( $lumia_reason, [ 'no_rule', 'no_vary', 'leak' ], true );
$lumia_uploads = (string) wp_parse_url( wp_upload_dir( null, false )['baseurl'], PHP_URL_PATH );
$lumia_uploads = '' === $lumia_uploads ? '/wp-content/uploads' : untrailingslashit( $lumia_uploads );
$lumia_rule    = implode(
	"\n",
	[
		'# http { } level',
		'map $args $lumia_force_original {',
		'    default 0;',
		'    "~(^|&)original(=|&|$)" 1;',
		'}',
		'map "$lumia_force_original:$http_accept" $lumia_avif_suffix {',
		'    default "";',
		'    "~^0:.*image/avif" ".avif";',
		'}',
		'',
		'# server { } level, before the location of the images',
		'location ~* ^' . preg_quote( $lumia_uploads, '#' ) . '/.+\.(?:jpe?g|png)$ {',
		'    add_header Vary Accept always;',
		'    add_header Cache-Control "public, max-age=31536000" always;',
		'    try_files $uri$lumia_avif_suffix $uri =404;',
		'}',
	]
);
?>
<div class="lumia-delivery" data-server-ok="<?php echo 'none' !== $lumia_delivery['server_mode'] ? '1' : '0'; ?>" data-probe-url="<?php echo esc_url( $lumia_probe_url ); ?>" data-mode="<?php echo esc_attr( $lumia_mode ); ?>">
	<p class="lumia-delivery__reason">
		<span class="lumia-badge <?php echo esc_attr( $lumia_badge[0] ); ?>"><?php echo esc_html( $lumia_badge[1] ); ?></span>
		<?php echo esc_html( $lumia_reasons[ $lumia_reason ] ?? $lumia_reasons['pending'] ); ?>
	</p>
	<?php if ( $lumia_serving && (int) $lumia_delivery['failures'] > 0 ) : ?>
		<p class="lumia-form__help">
			<?php
			/* translators: 1: failed attempts so far, 2: attempts tolerated before AVIF is turned off. */
			echo esc_html( sprintf( __( 'The last check could not reach the site (%1$d of %2$d attempts): the previous result is kept.', 'lumia-tools' ), (int) $lumia_delivery['failures'], 3 ) );
			?>
		</p>
	<?php endif; ?>

	<table class="lumia-server-table lumia-delivery__table">
		<tbody>
			<tr>
				<td class="lumia-server-table__label"><?php echo esc_html__( 'Web server', 'lumia-tools' ); ?></td>
				<td><?php echo esc_html( $lumia_servers[ $lumia_server ] ?? __( 'Unknown', 'lumia-tools' ) ); ?></td>
			</tr>
			<tr>
				<td class="lumia-server-table__label"><?php echo esc_html__( 'CDN', 'lumia-tools' ); ?></td>
				<td><?php echo esc_html( $lumia_cdns[ (string) $lumia_delivery['cdn'] ] ?? __( 'None detected', 'lumia-tools' ) ); ?></td>
			</tr>
			<tr>
				<td class="lumia-server-table__label"><?php echo esc_html__( 'Last check', 'lumia-tools' ); ?></td>
				<td>
					<?php
					echo esc_html(
						(int) $lumia_delivery['checked_at'] > 0
							? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $lumia_delivery['checked_at'] )
							: __( 'Never', 'lumia-tools' )
					);
					?>
				</td>
			</tr>
			<tr>
				<td class="lumia-server-table__label"><?php echo esc_html__( 'Check from your browser', 'lumia-tools' ); ?></td>
				<td data-lumia-delivery-browser><?php echo esc_html( $lumia_browser[ (string) $lumia_delivery['browser'] ] ?? $lumia_browser[''] ); ?></td>
			</tr>
			<?php if ( '' !== (string) $lumia_delivery['detail'] ) : ?>
				<tr>
					<td class="lumia-server-table__label"><?php echo esc_html__( 'Details', 'lumia-tools' ); ?></td>
					<td><code class="lumia-delivery__detail"><?php echo esc_html( (string) $lumia_delivery['detail'] ); ?></code></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $lumia_snippet ) : ?>
		<div class="lumia-delivery__snippet">
			<p class="lumia-form__help"><?php echo esc_html__( 'On nginx, hand this rule over to the host (or add it to the server configuration), then run the test again. The maps go at the http level, the location in the site\'s server block, before the location that serves the images.', 'lumia-tools' ); ?></p>
			<pre class="lumia-delivery__code" id="lumia-delivery-snippet"><code><?php echo esc_html( $lumia_rule ); ?></code></pre>
			<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm" data-lumia-copy="#lumia-delivery-snippet">
				<svg class="lumia-icon lumia-icon--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
				<?php echo esc_html__( 'Copy the rule', 'lumia-tools' ); ?>
			</button>
		</div>
	<?php endif; ?>
</div>
