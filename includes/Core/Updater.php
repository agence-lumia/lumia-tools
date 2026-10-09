<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub updater for the plugin.
 * Checks for updates from a GitHub repository.
 */
class Updater {

	/**
	 * GitHub user.
	 */
	private string $github_user = 'agence-lumia';

	/**
	 * GitHub repository name.
	 */
	private string $github_repo = 'lumia-tools';

	/**
	 * Transient for the update cache.
	 */
	private string $transient_key = 'lumia_github_update';

	/**
	 * Update channel.
	 */
	private string $channel = 'stable';

	/**
	 * Cache duration (12 hours).
	 */
	private int $cache_duration = 43200;

	/**
	 * Cache duration on failure (15 minutes).
	 *
	 * Without a negative cache, a network outage or an exceeded GitHub quota
	 * (60 requests/hour when anonymous) triggers an HTTP call with a 10 s
	 * timeout on EVERY update check — so, in practice, on every admin screen
	 * load.
	 */
	private int $failure_cache_duration = 900;

	/** Sentinel stored in the transient to remember a failure. */
	private const FAILURE_MARKER = 'lumia_update_check_failed';

	/** Maximum number of release notes kept (dev channel). */
	private const MAX_NOTES = 10;

	/**
	 * Initializes the updater.
	 */
	public function init(): void {
		$settings = get_option( 'lumia_settings', [] );

		if ( ! empty( $settings['global']['update_channel'] ) ) {
			$this->channel = sanitize_key( $settings['global']['update_channel'] );
		}

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// After a plugin update, purge the remote version cache (12 h) and the WP
		// updates transient, otherwise the "update available" badge persists until
		// a manual check.
		add_action( 'upgrader_process_complete', [ $this, 'purge_cache_after_update' ], 10, 2 );
	}

	/**
	 * Clears the update caches after a new version is installed.
	 *
	 * @param object $upgrader Upgrader instance (unused).
	 * @param array<string, mixed>  $options  Operation context.
	 */
	public function purge_cache_after_update( $upgrader, array $options ): void {
		if ( ( $options['action'] ?? '' ) !== 'update' || ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}

		$our_plugin = plugin_basename( LUMIA_PLUGIN_FILE );
		$updated    = (array) ( $options['plugins'] ?? [] );

		if ( ! in_array( $our_plugin, $updated, true ) ) {
			return;
		}

		delete_transient( $this->transient_key . '_stable' );
		delete_transient( $this->transient_key . '_dev' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Checks for available updates.
	 *
	 * @param \stdClass $transient Transient data.
	 *
	 * @return \stdClass
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $transient;
		}

		$plugin_file = plugin_basename( LUMIA_PLUGIN_FILE );

		// Compare the versions
		$has_update = $this->compare_versions( LUMIA_VERSION, $remote['version'] );

		$item = (object) [
			'slug'         => dirname( $plugin_file ),
			'plugin'       => $plugin_file,
			'new_version'  => $has_update ? $remote['version'] : LUMIA_VERSION,
			'url'          => $remote['url'],
			'package'      => $remote['download_url'],
			'icons'        => [],
			'banners'      => [],
			// No `tested` field: filling it with the site's current version
			// declared the plugin as tested on any version.
			'requires'     => '6.9',
			'requires_php' => '8.0',
		];

		if ( $has_update ) {
			$transient->response[ $plugin_file ] = $item;
		} else {
			// No update, but declare the known source: WordPress then shows the
			// "Automatic updates" column and can handle this plugin's native
			// auto-update.
			$transient->no_update[ $plugin_file ] = $item;
		}

		return $transient;
	}

	/**
	 * Tells whether the remote version is newer than the installed one.
	 *
	 * Pre-releases target the next patch (1.1.0 → 1.1.1-dev.N), so the SemVer
	 * order of version_compare() is enough: 1.1.0 < 1.1.1-dev.2 <
	 * 1.1.1-dev.10 < 1.1.1.
	 *
	 * @param string $installed_version Installed version.
	 * @param string $remote_version    Remote version.
	 * @return bool True if an update is available.
	 */
	private function compare_versions( string $installed_version, string $remote_version ): bool {
		return version_compare( $installed_version, $remote_version, '<' );
	}

	/**
	 * Provides the plugin information for the details screen.
	 *
	 * @param false|object|array<string, mixed> $result Default value.
	 * @param string             $action Requested action.
	 * @param object             $args   Arguments.
	 * @return false|object|array<string, mixed>
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$plugin_file = plugin_basename( LUMIA_PLUGIN_FILE );

		if ( dirname( $plugin_file ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $result;
		}

		return (object) [
			'name'           => 'Lümia Tools',
			'slug'           => dirname( $plugin_file ),
			'author'         => '<a href="https://agence-lumia.com">Agence Lümia</a>',
			'author_profile' => 'https://agence-lumia.com',
			'homepage'       => $remote['url'],
			'download_link'  => $remote['download_url'],
			'version'        => $remote['version'],
			'requires'       => '6.9',
			'requires_php'   => '8.0',
			'last_updated'   => $remote['published_at'],
			'sections'       => [
				'description' => __( 'A modular toolkit to optimize and improve your WordPress site.', 'lumia-tools' ),
				'changelog'   => $this->render_changelog( $remote['notes'] ?? [] ),
			],
		];
	}

	/**
	 * Builds the "Changelog" tab of the details modal.
	 *
	 * On the dev channel, an update can skip several pre-releases: show all the
	 * notes newer than the installed version. If the site is up to date, fall
	 * back to the notes of the latest version.
	 *
	 * @param mixed $notes Normalized notes (version, date, html).
	 */
	private function render_changelog( $notes ): string {
		if ( ! is_array( $notes ) || [] === $notes ) {
			return '<p>' . esc_html__( 'No release notes available.', 'lumia-tools' ) . '</p>';
		}

		$newer = array_filter(
			$notes,
			function ( $note ) {
				return version_compare( LUMIA_VERSION, $note['version'], '<' );
			}
		);

		if ( [] === $newer ) {
			$newer = [ reset( $notes ) ];
		}

		$html = '';

		foreach ( $newer as $note ) {
			$date  = '' !== $note['date'] ? (string) mysql2date( (string) get_option( 'date_format' ), $note['date'] ) : '';
			$html .= '<h4>' . esc_html( 'v' . $note['version'] ) . ( '' !== $date ? ' — ' . esc_html( $date ) : '' ) . '</h4>';
			// HTML rendered by GitHub: filtered here, then again by WordPress when
			// the modal is displayed.
			$html .= wp_kses_post( $note['html'] );
		}

		return $html;
	}

	/**
	 * Known update state, without any network call.
	 *
	 * Only reads the cache: the dashboard must never trigger a 10 s HTTP call.
	 * Empty cache or recent failure → `remote` is null.
	 *
	 * @return array{channel: string, remote: ?string, has_update: bool}
	 */
	public function get_status(): array {
		$cached = get_transient( $this->transient_key . '_' . $this->channel );
		$remote = is_array( $cached ) && ! empty( $cached['version'] ) ? (string) $cached['version'] : null;

		return [
			'channel'    => $this->channel,
			'remote'     => $remote,
			'has_update' => null !== $remote && $this->compare_versions( LUMIA_VERSION, $remote ),
		];
	}

	/**
	 * Fetches the latest version from GitHub.
	 *
	 * @return array<string, mixed>|false Release data, or false on error.
	 */
	private function get_remote_version() {
		$cache_key = $this->transient_key . '_' . $this->channel;
		$cached    = get_transient( $cache_key );

		// A recent failure is remembered as such: GitHub is not queried again
		// before the negative cache expires.
		if ( self::FAILURE_MARKER === $cached ) {
			return false;
		}

		if ( false !== $cached ) {
			return $cached;
		}

		$api_url = $this->get_api_url();

		$response = wp_remote_get(
			$api_url,
			[
				'timeout' => 10,
				'headers' => [
					// "html" variant: GitHub returns the notes already rendered
					// (`body_html`), which avoids bundling a Markdown parser.
					'Accept'     => 'application/vnd.github.html+json',
					'User-Agent' => 'LumiaTools',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->remember_failure( $cache_key );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_release_data( $body );

		if ( false === $data ) {
			return $this->remember_failure( $cache_key );
		}

		set_transient( $cache_key, $data, $this->cache_duration );

		return $data;
	}

	/**
	 * Remembers a lookup failure and returns false.
	 *
	 * @param string $cache_key Transient key of the current channel.
	 * @return false
	 */
	private function remember_failure( string $cache_key ) {
		set_transient( $cache_key, self::FAILURE_MARKER, $this->failure_cache_duration );

		return false;
	}

	/**
	 * Returns the API URL for the channel.
	 */
	private function get_api_url(): string {
		if ( 'dev' === $this->channel ) {
			return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases";
		}

		return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
	}

	/**
	 * Normalizes the release data according to the channel.
	 *
	 * @param mixed $body Decoded JSON body of the GitHub API.
	 * @return array<string, mixed>|false
	 */
	private function normalize_release_data( $body ) {
		if ( 'dev' === $this->channel ) {
			if ( ! is_array( $body ) ) {
				return false;
			}

			// Sort by descending version to guarantee the latest one (regardless of the API order)
			usort(
				$body,
				function ( $a, $b ) {
					return version_compare(
						ltrim( $b['tag_name'] ?? '', 'v' ),
						ltrim( $a['tag_name'] ?? '', 'v' )
					);
				}
			);

			// Pre-releases AND stable ones: the dev channel follows the highest of
			// both, otherwise a site on 1.0.13-dev.19 never sees the stable 1.1.0.
			$releases = array_values(
				array_filter(
					$body,
					function ( $release ) {
						return is_array( $release ) && empty( $release['draft'] ) && ! empty( $release['tag_name'] );
					}
				)
			);

			if ( [] === $releases ) {
				return false;
			}

			$payload          = $this->format_release_payload( $releases[0] );
			$payload['notes'] = array_map( [ $this, 'format_release_note' ], array_slice( $releases, 0, self::MAX_NOTES ) );

			return $payload;
		}

		if ( empty( $body['tag_name'] ) ) {
			return false;
		}

		return $this->format_release_payload( $body );
	}

	/**
	 * Converts a GitHub release into an updater payload.
	 *
	 * @param array<string, mixed> $release
	 * @return array<string, mixed>
	 */
	private function format_release_payload( array $release ): array {
		$download_url = $this->find_asset_download_url( $release );

		return [
			'version'      => ltrim( $release['tag_name'] ?? '', 'v' ),
			'url'          => $release['html_url'] ?? '',
			'download_url' => '' !== $download_url ? $download_url : ( $release['zipball_url'] ?? '' ),
			'published_at' => $release['published_at'] ?? '',
			'notes'        => [ $this->format_release_note( $release ) ],
		];
	}

	/**
	 * Extracts a release's note (version, date, HTML rendered by GitHub).
	 *
	 * @param array<string, mixed> $release
	 * @return array{version: string, date: string, html: string}
	 */
	private function format_release_note( array $release ): array {
		return [
			'version' => ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
			'html'    => (string) ( $release['body_html'] ?? '' ),
		];
	}

	/**
	 * Gets the zip asset if available.
	 *
	 * @param array<string, mixed> $release
	 */
	private function find_asset_download_url( array $release ): string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( empty( $asset['name'] ) ) {
				continue;
			}

			// Look for a zip file named lumia-tools-*.zip
			if ( preg_match( '/^lumia-tools-.*\.zip$/', $asset['name'] ) ) {
				return $asset['browser_download_url'] ?? '';
			}
		}

		return '';
	}
}
