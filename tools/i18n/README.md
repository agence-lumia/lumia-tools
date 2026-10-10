# Language-file tooling

Development only: `tools/` is never shipped (anchored `/tools` exclude in `tools/build/zip-excludes.txt`). The release zip carries the `.mo` and a `.l10n.php` compiled from the `.po` by `tools/build/build-zip.sh`, never the `.po` / `.pot`.

Source strings in the code are English; the French catalogue is
`languages/lumia-tools-fr_FR.po` (compiled to `.mo`). **The `.po` is the only source
of the French text**: to add or change a translation, edit it there (workflow in
`CLAUDE.md` and `docs/core.md`).

History: the `.po` was generated once from the `.pot` plus one pair file per scope,
each mapping an English source to the **original** French text. `languages/pairs/`
was deleted afterwards (find it in the history of the `chore/15-rename-lumia`
branch if needed), and `build-po.php build` was that one-time pairs-to-`.po`
generator. It is kept only for its tests; no Composer script runs it.

## Pair files (historical)

Format of `<pairs-dir>/<scope>.json`, as used by `build`:

```json
{
  "Settings saved.": "Réglages enregistrés.",
  "button\u0004Save": "Enregistrer",
  "%d file uploaded.|%d files uploaded.": ["%d fichier téléversé.", "%d fichiers téléversés."]
}
```

- Key: `msgid`, or `<msgctxt>\u0004<msgid>` for `_x()` / `_ex()` strings.
- Plurals: key `<msgid>|<msgid_plural>`, value `["<singular>", "<plural>"]`.
- A value is never empty. The same key may appear in several files only with the
  **same** French text; two different texts are a collision and `build` fails. Fix it
  with an `_x()` context in the code, never by changing the French.

## Tool

```
php tools/i18n/build-po.php build <pot> <pairs-dir> <po>
php tools/i18n/build-po.php check <pot> <po> <mo> [--no-msgunfmt]
```

| Command | Exit 1 when |
|---|---|
| `build` | a `msgid` has no pair, a pair is empty or malformed, or one key has two different French texts (nothing is written) |
| `check` | a `.pot` `msgid` is absent, empty or fuzzy in the `.po`, or the `.mo` is not exactly the translations of the `.po` |

Exit 2: bad usage or unreadable input. `build` keeps the `#.` and `#:` comments of
the `.pot`, writes `Language: fr_FR` and `Plural-Forms: nplurals=2; plural=(n > 1);`,
and warns (without failing) about pairs that match no `msgid`.

`check` reads the `.mo` with `msgunfmt` when installed, otherwise with a built-in
reader of the binary GNU format (`--no-msgunfmt` forces it). It compares contents,
not file dates.

## Running it

`i18n:pot` and `i18n:mo` need WP-CLI. The `composer:2` image has php but no WP-CLI, so
`tools/i18n/wp.sh` uses `wp` if present, and otherwise downloads the pinned WP-CLI
phar once into `tools/i18n/.cache/` (git-ignored), checked against its published
SHA-512. `composer check` is unaffected.

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:pot     # languages/lumia-tools.pot (first run downloads the phar)
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:mo      # languages/*.po -> .mo
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:check   # .po covers the .pot, .mo matches the .po
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:test    # tools/i18n/test.sh
```

Equivalent without Composer, with the WP-CLI image:

```bash
docker run --rm -v "$PWD:/app" -w /app wordpress:cli-php8.3 \
  wp i18n make-pot . languages/lumia-tools.pot --slug=lumia-tools --domain=lumia-tools --exclude=vendor,tools,docs,dist
docker run --rm -v "$PWD:/app" -w /app wordpress:cli-php8.3 wp i18n make-mo languages/
```

## Tests

`sh tools/i18n/test.sh` uses the local `php`, or re-runs itself inside
`wordpress:cli-php8.3` when there is none (macOS). It covers the collision, the
missing pair, exact `msgstr` texts (U+00A0 and U+202F byte for byte), kept
comments, and `check` on an empty `msgstr`, a stale `.mo`, a new `msgid` and a corrupt
`.mo`.

`fixtures/expected-fr_FR.mo` is the compiled `expected-fr_FR.po`. After changing the
output format, regenerate both:

```bash
mkdir /tmp/ok && cp tools/i18n/fixtures/pairs/[ab].json /tmp/ok/
php tools/i18n/build-po.php build tools/i18n/fixtures/sample.pot /tmp/ok tools/i18n/fixtures/expected-fr_FR.po
sh tools/i18n/wp.sh i18n make-mo tools/i18n/fixtures/expected-fr_FR.po tools/i18n/fixtures/
```

## CI

Job `i18n` in `.github/workflows/lint.yml`: runs the tests, regenerates the `.pot`
from the source (`wordpress:cli-php8.3`), then `check`. A string added in the code
without a matching `.po` entry, or a `.mo` that no longer matches the `.po`, fails it.
