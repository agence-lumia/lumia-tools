<?php
/**
 * Language-file tooling for Lümia Tools (development only, never shipped).
 *
 * Usage:
 *   php tools/i18n/build-po.php build <pot> <pairs-dir> <po>
 *   php tools/i18n/build-po.php check <pot> <po> <mo> [--no-msgunfmt]
 *
 * build  Merges the .pot (from `wp i18n make-pot`) with the pair files
 *        (languages/pairs/<scope>.json) into a fr_FR .po. Exits 1 and lists the
 *        problems, writing nothing, when a msgid has no pair, when a pair is
 *        unusable, or when one key maps to two different French texts.
 * check  Exits 1 when a .pot msgid is missing, empty or fuzzy in the .po, or when
 *        the .mo does not carry exactly the translations of the .po.
 *
 * Pair key: "<msgctxt>\u0004<msgid>" or "<msgid>"; value: the French text.
 * Plurals: key "<msgid>|<msgid_plural>", value ["<singular>", "<plural>"].
 *
 * Exit codes: 0 success, 1 problems found, 2 usage or unreadable input.
 *
 * Pure PHP CLI, no WordPress and no extension beyond core: it runs in the
 * WP-CLI image, in the composer image and on a CI runner alike.
 *
 * @package Lumia\Tools
 */

// This is a standalone CLI script, not plugin code: direct file and output functions are the point.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions

declare( strict_types=1 );

/**
 * Decodes the inside of a PO string literal.
 *
 * @param string $raw Text between the quotes.
 */
function lumia_i18n_unescape( string $raw ): string {
	return (string) preg_replace_callback(
		'/\\\\(.)/s',
		static function ( array $m ): string {
			return match ( $m[1] ) {
				'n'     => "\n",
				't'     => "\t",
				'r'     => "\r",
				'"'     => '"',
				'\\'    => '\\',
				default => $m[0],
			};
		},
		$raw
	);
}

/**
 * Encodes text for the inside of a PO string literal.
 *
 * @param string $text Raw text.
 */
function lumia_i18n_escape( string $text ): string {
	return strtr(
		$text,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\t" => '\\t',
			"\r" => '\\r',
		)
	);
}

/**
 * Formats a keyword and its value; multi-line values use the gettext layout.
 *
 * @param string $keyword Keyword, e.g. msgid or msgstr[1].
 * @param string $value   Unescaped value.
 */
function lumia_i18n_format_field( string $keyword, string $value ): string {
	if ( ! str_contains( $value, "\n" ) ) {
		return $keyword . ' "' . lumia_i18n_escape( $value ) . '"';
	}
	$out = $keyword . ' ""';
	foreach ( (array) preg_split( '/(?<=\n)/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $piece ) {
		$out .= "\n\"" . lumia_i18n_escape( (string) $piece ) . '"';
	}
	return $out;
}

/**
 * Parses PO text (also valid for a .pot).
 *
 * Each entry: ctx (?string), id, plural (?string), str (list<string>),
 * comments (list<string>, raw `#` lines), fuzzy (bool). The header is the
 * entry whose id is '' without context. Obsolete (#~) entries are skipped.
 *
 * @param string $text PO file content.
 * @return list<array{ctx: ?string, id: string, plural: ?string, str: list<string>, comments: list<string>, fuzzy: bool}>
 * @throws RuntimeException On a malformed line.
 */
function lumia_i18n_parse_po( string $text ): array {
	$entries = array();
	$cur     = null;
	$field   = null;
	$started = false;

	$flush = static function () use ( &$entries, &$cur, &$field, &$started ): void {
		if ( null !== $cur && null !== $cur['id'] ) {
			$cur['str'] = array_values( $cur['str'] );
			$entries[]  = $cur;
		}
		$cur     = null;
		$field   = null;
		$started = false;
	};
	$fresh = static function (): array {
		return array(
			'ctx'      => null,
			'id'       => null,
			'plural'   => null,
			'str'      => array(),
			'comments' => array(),
			'fuzzy'    => false,
		);
	};

	foreach ( (array) preg_split( '/\r\n|\n/', $text ) as $n => $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( str_starts_with( $line, '#~' ) ) {
			continue;
		}
		if ( '#' === $line[0] ) {
			if ( $started ) {
				$flush();
			}
			$cur ??= $fresh();
			if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
				$cur['fuzzy'] = true;
			}
			$cur['comments'][] = $line;
			continue;
		}
		if ( '"' === $line[0] ) {
			if ( null === $cur || null === $field || ! preg_match( '/^"(.*)"$/s', $line, $m ) ) {
				throw new RuntimeException( sprintf( 'Malformed PO line %d: %s', $n + 1, $line ) );
			}
			$value = lumia_i18n_unescape( $m[1] );
			if ( 'str' === $field[0] ) {
				$cur['str'][ $field[1] ] .= $value;
			} else {
				$cur[ $field[0] ] .= $value;
			}
			continue;
		}
		if ( ! preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/s', $line, $m ) ) {
			throw new RuntimeException( sprintf( 'Malformed PO line %d: %s', $n + 1, $line ) );
		}
		$keyword = $m[1];
		$value   = lumia_i18n_unescape( $m[3] );
		if ( in_array( $keyword, array( 'msgctxt', 'msgid' ), true ) && null !== $cur && ( $started || null !== $cur['id'] ) ) {
			$flush();
		}
		$cur ??= $fresh();
		if ( 'msgstr' === $keyword ) {
			$started = true;
			$index   = '' === $m[2] ? 0 : (int) $m[2];

			$cur['str'][ $index ] = $value;
			$field                = array( 'str', $index );
		} else {
			$key         = 'msgctxt' === $keyword ? 'ctx' : ( 'msgid' === $keyword ? 'id' : 'plural' );
			$cur[ $key ] = $value;
			$field       = array( $key );
		}
	}
	$flush();

	return $entries;
}

/**
 * Whether an entry is the PO header.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_is_header( array $entry ): bool {
	return '' === $entry['id'] && null === $entry['ctx'];
}

/**
 * Key as stored in a .mo file: "ctx\x04id" and "\0plural" when present.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_mo_key( array $entry ): string {
	$key = $entry['id'];
	if ( null !== $entry['plural'] ) {
		$key .= "\0" . $entry['plural'];
	}
	if ( null !== $entry['ctx'] ) {
		$key = $entry['ctx'] . "\x04" . $key;
	}
	return $key;
}

/**
 * Key used in pair files: "ctx\x04id" and "|plural" when present.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_pair_key( array $entry ): string {
	$key = $entry['id'];
	if ( null !== $entry['plural'] ) {
		$key .= '|' . $entry['plural'];
	}
	if ( null !== $entry['ctx'] ) {
		$key = $entry['ctx'] . "\x04" . $key;
	}
	return $key;
}

/**
 * Human-readable form of a pair key.
 *
 * @param string $key Pair key.
 */
function lumia_i18n_show_key( string $key ): string {
	return '"' . str_replace( "\x04", '" (context) / "', lumia_i18n_escape( $key ) ) . '"';
}

/**
 * Translation as stored in a .mo file: plural forms joined by NUL.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_mo_value( array $entry ): string {
	return implode( "\0", $entry['str'] );
}

/**
 * Whether every msgstr of an entry holds text. Plural entries need both forms.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_is_translated( array $entry ): bool {
	$needed = null === $entry['plural'] ? 1 : 2;
	if ( count( $entry['str'] ) < $needed ) {
		return false;
	}
	foreach ( $entry['str'] as $str ) {
		if ( '' === trim( $str ) ) {
			return false;
		}
	}
	return true;
}

/**
 * First `#:` reference of an entry, to locate it in the source.
 *
 * @param array<string, mixed> $entry Parsed entry.
 */
function lumia_i18n_first_ref( array $entry ): string {
	foreach ( $entry['comments'] as $comment ) {
		if ( str_starts_with( $comment, '#:' ) ) {
			return ' [' . trim( substr( $comment, 2 ) ) . ']';
		}
	}
	return '';
}

/**
 * Reads a file or fails.
 *
 * @param string $path File path.
 * @throws RuntimeException When unreadable.
 */
function lumia_i18n_read_file( string $path ): string {
	$data = is_file( $path ) ? file_get_contents( $path ) : false;
	if ( false === $data ) {
		throw new RuntimeException( 'Cannot read ' . $path );
	}
	return $data;
}

/**
 * Loads the pair files of a directory.
 *
 * @param string $dir Directory holding *.json files.
 * @return array{pairs: array<string, array<string, list<string>>>, values: array<string, array<string, string|list<string>>>, errors: list<string>}
 *         pairs: key => encoded value => files; values: key => encoded value => decoded value.
 */
function lumia_i18n_load_pairs( string $dir ): array {
	$files = glob( rtrim( $dir, '/' ) . '/*.json' );
	if ( false === $files || array() === $files ) {
		throw new RuntimeException( 'No *.json pair file in ' . $dir );
	}
	sort( $files );

	$pairs  = array();
	$values = array();
	$errors = array();
	foreach ( $files as $file ) {
		$name = basename( $file );
		try {
			$data = json_decode( lumia_i18n_read_file( $file ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$errors[] = $name . ': invalid JSON (' . $e->getMessage() . ')';
			continue;
		}
		if ( ! is_array( $data ) ) {
			$errors[] = $name . ': the top level must be an object';
			continue;
		}
		foreach ( $data as $key => $value ) {
			// PHP turns numeric-looking JSON keys into integers.
			$key = (string) $key;
			$ok  = is_string( $value ) && '' !== trim( $value );
			if ( is_array( $value ) ) {
				$ok = 2 === count( $value ) && array_is_list( $value );
				foreach ( $value as $form ) {
					$ok = $ok && is_string( $form ) && '' !== trim( $form );
				}
			}
			if ( ! $ok ) {
				$errors[] = $name . ': empty or invalid value for ' . lumia_i18n_show_key( $key );
				continue;
			}
			$encoded                     = (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$pairs[ $key ][ $encoded ][] = $name;
			$values[ $key ][ $encoded ]  = $value;
		}
	}
	return array(
		'pairs'  => $pairs,
		'values' => $values,
		'errors' => $errors,
	);
}

/**
 * Builds the .po header from the .pot header.
 *
 * @param array<string, mixed> $header The .pot header entry.
 * @return string Header block, comments included.
 */
function lumia_i18n_po_header( array $header ): string {
	$lines  = array();
	$seen   = array(
		'Language'     => false,
		'Plural-Forms' => false,
	);
	$fields = array(
		'Language'     => 'fr_FR',
		'Plural-Forms' => 'nplurals=2; plural=(n > 1);',
	);
	foreach ( explode( "\n", rtrim( $header['str'][0] ?? '', "\n" ) ) as $line ) {
		if ( '' === $line ) {
			continue;
		}
		$name = trim( (string) strstr( $line, ':', true ) );
		if ( isset( $fields[ $name ] ) ) {
			$line          = $name . ': ' . $fields[ $name ];
			$seen[ $name ] = true;
		}
		$lines[] = $line;
	}
	foreach ( $fields as $name => $value ) {
		if ( ! $seen[ $name ] ) {
			$lines[] = $name . ': ' . $value;
		}
	}

	$out = '';
	foreach ( $header['comments'] as $comment ) {
		$out .= $comment . "\n";
	}
	return $out . "msgid \"\"\n" . lumia_i18n_format_field( 'msgstr', implode( "\n", $lines ) . "\n" ) . "\n\n";
}

/**
 * The `build` command.
 *
 * @param string $pot_path   .pot file.
 * @param string $pairs_dir  Directory of pair files.
 * @param string $po_path    .po file to write.
 * @return int Exit code.
 */
function lumia_i18n_build( string $pot_path, string $pairs_dir, string $po_path ): int {
	$pot    = lumia_i18n_parse_po( lumia_i18n_read_file( $pot_path ) );
	$loaded = lumia_i18n_load_pairs( $pairs_dir );

	$problems = $loaded['errors'];
	$header   = null;
	$body     = '';
	$used     = array();
	$missing  = array();

	foreach ( $pot as $entry ) {
		if ( lumia_i18n_is_header( $entry ) ) {
			$header = $entry;
			continue;
		}
		$key = lumia_i18n_pair_key( $entry );
		if ( ! isset( $loaded['values'][ $key ] ) ) {
			$missing[] = lumia_i18n_show_key( $key ) . lumia_i18n_first_ref( $entry );
			continue;
		}
		$used[ $key ] = true;
		if ( count( $loaded['values'][ $key ] ) > 1 ) {
			continue; // Reported below as a collision.
		}
		$value = reset( $loaded['values'][ $key ] );

		foreach ( $entry['comments'] as $comment ) {
			$body .= $comment . "\n";
		}
		if ( null !== $entry['ctx'] ) {
			$body .= lumia_i18n_format_field( 'msgctxt', $entry['ctx'] ) . "\n";
		}
		$body .= lumia_i18n_format_field( 'msgid', $entry['id'] ) . "\n";
		if ( null === $entry['plural'] ) {
			$body .= lumia_i18n_format_field( 'msgstr', (string) $value ) . "\n\n";
			continue;
		}
		$body .= lumia_i18n_format_field( 'msgid_plural', $entry['plural'] ) . "\n";
		foreach ( (array) $value as $i => $form ) {
			$body .= lumia_i18n_format_field( 'msgstr[' . $i . ']', (string) $form ) . "\n";
		}
		$body .= "\n";
	}

	$collisions = array();
	foreach ( $loaded['pairs'] as $key => $variants ) {
		if ( count( $variants ) < 2 ) {
			continue;
		}
		$lines = array();
		foreach ( $variants as $encoded => $files ) {
			$lines[] = '    ' . $encoded . '  (' . implode( ', ', array_unique( $files ) ) . ')';
		}
		$collisions[] = lumia_i18n_show_key( $key ) . ' has ' . count( $variants ) . " different translations:\n" . implode( "\n", $lines );
	}

	if ( array() !== $collisions ) {
		$problems[] = "Collision: the same English source maps to different French texts. Fix with _x() context, never by changing the French:\n  " . implode( "\n  ", $collisions );
	}
	if ( array() !== $missing ) {
		$problems[] = count( $missing ) . " msgid without a pair:\n  " . implode( "\n  ", $missing );
	}
	if ( null === $header ) {
		$problems[] = 'The .pot has no header entry.';
	}
	if ( array() !== $problems ) {
		fwrite( STDERR, "build failed, nothing written.\n" . implode( "\n", $problems ) . "\n" );
		return 1;
	}

	$unused = array_diff( array_keys( $loaded['values'] ), array_keys( $used ) );
	if ( array() !== $unused ) {
		fwrite( STDERR, 'Notice: ' . count( $unused ) . " pair(s) match no .pot msgid (stale or renamed):\n  " . implode( "\n  ", array_map( 'lumia_i18n_show_key', $unused ) ) . "\n" );
	}

	if ( false === file_put_contents( $po_path, lumia_i18n_po_header( (array) $header ) . $body ) ) {
		throw new RuntimeException( 'Cannot write ' . $po_path );
	}
	fwrite( STDOUT, sprintf( "Wrote %s (%d entries).\n", $po_path, count( $used ) ) );
	return 0;
}

/**
 * Parses a binary GNU .mo file into a key => translation map (header excluded).
 *
 * @param string $bin File content.
 * @return array<string, string>
 * @throws RuntimeException When the file is not a valid .mo.
 */
function lumia_i18n_read_mo( string $bin ): array {
	if ( strlen( $bin ) < 28 ) {
		throw new RuntimeException( 'The .mo file is too short.' );
	}
	$magic = unpack( 'V', $bin )[1];
	if ( 0x950412de === $magic ) {
		$fmt = 'V';
	} elseif ( 0xde120495 === $magic ) {
		$fmt = 'N';
	} else {
		throw new RuntimeException( 'Bad .mo magic number.' );
	}
	$head = unpack( "{$fmt}rev/{$fmt}count/{$fmt}orig/{$fmt}trans", $bin, 4 );
	if ( false === $head || 0 !== ( $head['rev'] >> 16 ) ) {
		throw new RuntimeException( 'Unsupported .mo revision.' );
	}
	$read = static function ( int $table, int $i ) use ( $bin, $fmt ): string {
		$pair = unpack( "{$fmt}len/{$fmt}off", $bin, $table + 8 * $i );
		if ( false === $pair || $pair['off'] + $pair['len'] > strlen( $bin ) ) {
			throw new RuntimeException( 'Corrupt .mo string table.' );
		}
		return substr( $bin, $pair['off'], $pair['len'] );
	};

	$map = array();
	for ( $i = 0; $i < $head['count']; $i++ ) {
		$orig = $read( $head['orig'], $i );
		if ( '' !== $orig ) {
			$map[ $orig ] = $read( $head['trans'], $i );
		}
	}
	return $map;
}

/**
 * Reads a .mo through msgunfmt, or null when msgunfmt is not installed.
 *
 * @param string $mo_path .mo file.
 * @return array<string, string>|null
 * @throws RuntimeException When msgunfmt rejects the file.
 */
function lumia_i18n_read_mo_msgunfmt( string $mo_path ): ?array {
	$output = array();
	$code   = 0;
	exec( 'msgunfmt ' . escapeshellarg( $mo_path ) . ' 2>&1', $output, $code );
	if ( 127 === $code ) {
		return null;
	}
	if ( 0 !== $code ) {
		throw new RuntimeException( 'msgunfmt failed: ' . implode( ' ', $output ) );
	}
	$map = array();
	foreach ( lumia_i18n_parse_po( implode( "\n", $output ) ) as $entry ) {
		if ( ! lumia_i18n_is_header( $entry ) ) {
			$map[ lumia_i18n_mo_key( $entry ) ] = lumia_i18n_mo_value( $entry );
		}
	}
	return $map;
}

/**
 * The `check` command.
 *
 * @param string $pot_path     .pot file (freshly generated).
 * @param string $po_path      .po file.
 * @param string $mo_path      .mo file.
 * @param bool   $use_msgunfmt Whether to prefer msgunfmt over the built-in reader.
 * @return int Exit code.
 */
function lumia_i18n_check( string $pot_path, string $po_path, string $mo_path, bool $use_msgunfmt ): int {
	$pot = lumia_i18n_parse_po( lumia_i18n_read_file( $pot_path ) );
	$po  = lumia_i18n_parse_po( lumia_i18n_read_file( $po_path ) );

	$by_key = array();
	foreach ( $po as $entry ) {
		if ( ! lumia_i18n_is_header( $entry ) ) {
			$by_key[ lumia_i18n_mo_key( $entry ) ] = $entry;
		}
	}

	$problems = array();
	$seen     = array();
	foreach ( $pot as $entry ) {
		if ( lumia_i18n_is_header( $entry ) ) {
			continue;
		}
		$key          = lumia_i18n_mo_key( $entry );
		$seen[ $key ] = true;
		$label        = lumia_i18n_show_key( lumia_i18n_pair_key( $entry ) ) . lumia_i18n_first_ref( $entry );
		if ( ! isset( $by_key[ $key ] ) ) {
			$problems[] = 'missing from the .po: ' . $label;
		} elseif ( $by_key[ $key ]['fuzzy'] ) {
			$problems[] = 'fuzzy in the .po: ' . $label;
		} elseif ( ! lumia_i18n_is_translated( $by_key[ $key ] ) ) {
			$problems[] = 'empty msgstr in the .po: ' . $label;
		}
	}
	$stale = array_diff_key( $by_key, $seen );

	// What the .mo must hold: every translated, non-fuzzy .po entry.
	$expected = array();
	foreach ( $by_key as $key => $entry ) {
		if ( ! $entry['fuzzy'] && lumia_i18n_is_translated( $entry ) ) {
			$expected[ $key ] = lumia_i18n_mo_value( $entry );
		}
	}
	$mo   = $use_msgunfmt ? lumia_i18n_read_mo_msgunfmt( $mo_path ) : null;
	$mo ??= lumia_i18n_read_mo( lumia_i18n_read_file( $mo_path ) );

	$show = static function ( string $key ): string {
		return lumia_i18n_show_key( str_replace( "\0", '|', $key ) );
	};
	foreach ( $expected as $key => $value ) {
		if ( ! isset( $mo[ $key ] ) ) {
			$problems[] = '.mo out of date, missing: ' . $show( $key );
		} elseif ( $mo[ $key ] !== $value ) {
			$problems[] = '.mo out of date, different translation: ' . $show( $key );
		}
	}
	foreach ( array_keys( array_diff_key( $mo, $expected ) ) as $key ) {
		$problems[] = '.mo out of date, entry not in the .po: ' . $show( $key );
	}

	if ( array() !== $stale ) {
		fwrite( STDERR, 'Notice: ' . count( $stale ) . " .po entr(ies) no longer in the .pot (kept, harmless).\n" );
	}
	if ( array() !== $problems ) {
		fwrite( STDERR, 'check failed (' . count( $problems ) . "):\n  " . implode( "\n  ", $problems ) . "\n" );
		return 1;
	}
	fwrite( STDOUT, sprintf( "OK: %d entries translated, .mo in sync.\n", count( $expected ) ) );
	return 0;
}

/**
 * Entry point.
 *
 * @param list<string> $argv Command-line arguments.
 * @return int Exit code.
 */
function lumia_i18n_main( array $argv ): int {
	$args         = array_values( array_diff( array_slice( $argv, 1 ), array( '--no-msgunfmt' ) ) );
	$use_msgunfmt = ! in_array( '--no-msgunfmt', $argv, true );
	$usage        = "Usage:\n  php build-po.php build <pot> <pairs-dir> <po>\n  php build-po.php check <pot> <po> <mo> [--no-msgunfmt]\n";

	if ( 4 !== count( $args ) || ! in_array( $args[0], array( 'build', 'check' ), true ) ) {
		fwrite( STDERR, $usage );
		return 2;
	}
	try {
		if ( 'build' === $args[0] ) {
			return lumia_i18n_build( $args[1], $args[2], $args[3] );
		}
		return lumia_i18n_check( $args[1], $args[2], $args[3], $use_msgunfmt );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
		return 2;
	}
}

exit( lumia_i18n_main( $argv ) );
