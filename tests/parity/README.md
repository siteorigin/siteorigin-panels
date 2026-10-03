# Parity tests

These tests check that stored data and rendered output of the working tree are the same as on the last release.

Every run boots one WordPress Playground with one Page Builder tree, the parity mu-plugin and the Widgets Bundle. The release (the base) and the working tree (the candidate) run one after the other on the same port, with the same WordPress and the same Widgets Bundle. So every URL and asset string is the same, and only plugin behaviour can make a difference.

Each version runs twice: `base-1`, `cand-1`, `base-2`, `cand-2`. The two runs of one version show which bytes change on their own. Those bytes are replaced by named tokens before any comparison (see [Named tokens](#named-tokens)).

All output goes to `tests/cache/parity/` (ignored by git). Reports are in `tests/cache/parity/compare/`.

Requirements: Node 20 or later, `npm ci`, and outbound HTTPS (WordPress, the Widgets Bundle and themes are downloaded at run time). The builder build (`js/siteorigin-panels.js`) is not needed.

## Commands

| Command | What it checks | Run time (measured on an Apple M-series laptop) |
|---|---|---|
| `npm run parity -- --smoke` | 79 cases: every save path and role with two embeds, every older layout shape once per storage family, the ability round trip, the widget area and the home page. | About 6 min |
| `npm run parity` | The full set: 164 cases. | About 13 min |
| `npm run parity:generate -- --count=300` | 300 generated layouts, saved and rendered. | About 8.5 min |
| `npm run parity:generate` | 1,000 generated layouts (seed `20261002`). | About 28 min (estimated from the 300-case run) |
| `npm run parity:corpus -- --corpus=/abs/dir` | A private corpus of real layouts. | About 30 s for the boots, plus the layouts |

A failed download or a server that stops part-way is an incomplete run, not a parity failure. Each run is checked for completeness and tried up to three times (`--tries`); a failed try is moved to `tests/cache/parity/discarded/`.

### `npm run parity`

Cases, per version and run:

- Saves with embeds (iframe, shortcode, inline SVG, script, style) as administrator and author, through the classic editor builder (`wp-admin/post.php` with a login cookie and nonces), the Layout Block (REST), and a Layout Builder widget inside each of them.
- Older layout shapes (`info` in place of `panels_info`, lists as numeric-keyed objects, numeric-string references, string row IDs with ASCII and non-ASCII letters) through the same save paths.
- The same shapes written straight to the database, as data stored by an earlier version.
- The ability round trip (`layout-get`, change one widget, `layout-update`) as administrator and author, when the WordPress version has the Abilities API.
- A Layout Builder widget in a widget area (seeded, and saved through the widgets screen's own requests) and the custom home page (seeded, and saved through the Home Page screen).

Compared per case: the save response, the post ID, `post_content`, every post meta row, the stored widget or home page options, the page HTML, the printed layout CSS and `generate_css()` for the modern and the legacy renderer.

Pass rule (exit 0 only if all hold):

- all four runs are complete;
- `base-1` vs `base-2`, `cand-1` vs `cand-2`, `base-1` vs `cand-1`, `base-2` vs `cand-2`: no field differs after the named tokens, and no case is missing;
- the comparison is a real one: every case answers HTTP 200 on both renderers, and the base run shows a live iframe, script, style, SVG and shortcode, a non-ASCII row selector, the widget area text and the home page text (each count applies when the run holds such a case).

Options: `--smoke`, `--filter=<regex>` (case keys), `--base=<tag|git ref|absolute path>`, `--candidate=<git ref>`, `--port=1139`, `--wp=latest`, `--php=8.3`, `--wb=latest|<version>|<path>|none`, `--tokens=port,version`, `--tries=3`, `--prefix=<label prefix>`.

### Another base

The base is the highest `X.Y.Z` tag. Pick another one with `--base`:

```
npm run parity -- --smoke --base=2.36.0
npm run parity -- --smoke --base=develop
npm run parity -- --smoke --base=/abs/path/to/a/plugin/tree
```

A git ref is extracted with `git archive` into `tests/cache/parity/trees/<ref>` and reused. The ref's own `tests/` folder is left out (PHPUnit scans `tests/` recursively). Git submodules (`inc/installer`) are not in the archive; the plugin runs without them.

`--base=develop` on an unchanged branch compares the code with itself: it must show 0 differences.

### `npm run parity:generate`

The generator (`tests/parity/php/generator.php`, plain PHP) makes layouts from one seed: valid layouts, `info` in place of `panels_info`, numeric-keyed lists, numeric-string references, missing parts, string row IDs (ASCII and non-ASCII), references out of range, values of other types, nesting 2 to 6 levels deep, and combinations. Every string used as a row, cell or widget reference contains only letters, digits, underscores and hyphens (letters and digits of any script).

Per run, every case is saved through the plugin's own handlers as administrator and as author, on two scratch posts:

- classic: the builder field on a post save (the plugin's `save_post` hook, in an admin request);
- block: a Layout Block in the post content (`wp_update_post`).

Then the raw case and every stored value are rendered on both renderers.

Pass rule:

- self-check: two generations with one seed are equal, and every reference string follows the rule above;
- the noise checks (`base-1` vs `base-2`, `cand-1` vs `cand-2`) show 0 differences;
- base vs candidate: for every case, role and path, the same answer (stored or refused) and the same exception; where stored, the same stored bytes, HTML and CSS on both renderers; the same render of the raw case;
- the base stores at least 60% of the classic saves and of the block saves. Otherwise the generator is not testing the save path. The report lists the refused cases per kind.

Options: `--seed=<n>`, `--count=<n>`, `--chunk=50`, and the base, port and environment options of `npm run parity`.

## Named tokens

A token is a value that changes between two runs of the same code. It is replaced before comparing, and every report lists how often each token was needed.

| Token | What makes it change |
|---|---|
| `uniqid:layout-block-render-id` | A Layout Block with no stored builder ID gets `gb<post>-<13 hex>` from `uniqid()` on each render. |
| `uniqid:layout-builder-widget-id` | A Layout Builder widget gets a new `builder_id` from `uniqid()` on each save, printed as `w<13 hex>`. |
| `wb:_sow_form_timestamp` | Widgets Bundle widgets store the save time (13 digits). |
| `wp-core:post-modified-time(theme)` | A theme that prints the post's modified time (`<time class="updated">`, for example Vantage). A saved post is modified at the time of the run. |
| `wp-core:enclosure-meta(cron)` | WordPress writes `enclosure` post meta from WP-Cron for a media URL. Whether cron has run yet is timing, so the row is left out. |
| `harness:port` (opt-in, `--tokens=port`) | Two runs on different ports. |
| `package:version-string` (opt-in, `--tokens=version`) | The plugin version of each run, read from its `info.json` (asset URLs, generator comment). |
| `premium:animation-id` (opt-in, `--tokens=premium-animations`) | SiteOrigin Premium Animations: `animate-<22 hex>` per element and render. |
| `wb:google-map-id` (opt-in, `--tokens=wb-google-map`) | Widgets Bundle Google Maps: a random `"id":"<6 hex>"` per render. |

## Private corpus

Real layouts are the best test data, and they belong to their sites. Private corpora and Premium stay outside the repository and outside CI.

1. Export on any site that holds the layouts (read only):

   ```
   wp eval-file tests/parity/tools/export-corpus.php /abs/dir/outside/any/repo
   ```

   It writes one JSON file per layout (post meta, Layout Block, Layout Builder widget). It refuses a directory inside a git work tree.

2. Run:

   ```
   npm run parity:corpus -- --corpus=/abs/dir/outside/any/repo
   npm run parity:corpus -- --corpus=/abs/dir --tokens=premium-animations,wb-google-map
   ```

   Every stored layout is rendered on both renderers, and every post meta layout is saved again through the classic path as administrator. The pass rule is 0 differences after the named tokens. The command fails when the directory is inside the repository.

## Environment matrix

`npm run parity:matrix` runs the smoke comparison in other environments, one cell after the other. Base and candidate share each cell, so a difference is still a plugin difference. Local only; not in CI.

| Cell | Environment |
|---|---|
| `multisite-subsite` | Multisite (WP-CLI conversion), every request on the sub-site `/parity/` except the login (network root). The run fails unless the requests reach blog 2. |
| `legacy-theme` | The test theme `themes/panels-parity-legacy` (classic theme, `legacy-layout` set to `always` by theme support). No renderer header is sent; the run fails unless the site runs `SiteOrigin_Panels_Renderer_Legacy`. |
| `siteorigin-theme` | The Vantage theme from wordpress.org. |
| `no-wb` | No Widgets Bundle. |
| `premium`, `premium-no-wb` | SiteOrigin Premium from a local path, with and without the Widgets Bundle. Only with `--premium=<absolute path>`. |

```
npm run parity:matrix
npm run parity:matrix -- --cell=legacy-theme
npm run parity:matrix -- --premium=/abs/path/to/siteorigin-premium
```

Each cell prints one line: PASS, FAIL, or NOT RUN with the reason (a cell that cannot boot, or whose environment check fails). NOT RUN makes the exit code 1. A cell takes about 6 minutes.

Premium rule: Premium is never in the repository or in CI. `--premium` is refused when the `CI` environment variable is set.

The same environment options work on `npm run parity` itself: `--multisite`, `--multisite=subsite`, `--theme=<wordpress.org slug>`, `--theme=legacy-fixture`, `--wb=none`, `--premium=<absolute path>`.

## Files

- `parity.mjs` — the four runs, the comparisons and the verdict.
- `run.mjs` — one run: boot, cases, capture.
- `compare.mjs` — the comparison of two runs, the named tokens and the coverage counts.
- `integrity.mjs` — is a run complete?
- `generate.mjs` — the generator test and the private corpus mode.
- `matrix.mjs` — the environment matrix.
- `themes/panels-parity-legacy/` — the classic test theme of the `legacy-theme` cell.
- `lib.mjs` — Playground boot, requests, layout builders, save paths.
- `mu-plugins/panels-parity.php` — the test mu-plugin (header users, renderer pin, seed and read endpoints). It does nothing unless the blueprint defines `PANELS_PARITY_HARNESS`.
- `php/generator.php`, `php/batch.php` — the generator and its batch save and render endpoints.
- `tools/export-corpus.php` — the corpus export.
