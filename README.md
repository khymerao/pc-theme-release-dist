# ProCoders Theme Release

Releases themes from private GitHub repositories to staging and production WordPress sites through
the **standard WordPress theme update flow**: a new tag shows the usual update notice under
*Appearance → Themes* and *Dashboard → Updates*; an administrator clicks **Update**. No SSH, no
environment variables, no runtime libraries.

- One site = one channel: **staging** or **production**.
- Staging takes tags **with** a suffix (`v1.19.1-rc.1`) whose commit is in the staging branch
  (default `develop`). Production takes tags **without** a suffix (`v1.19.1`) whose commit is in the
  production branch (default `main`). Every other tag is ignored.
- Updates are manual. Nothing is ever installed automatically; WordPress auto-updates are switched
  off for managed themes.
- Rollback to any of the last 20 channel tags; the theme is then **frozen** (no update notices)
  until you unfreeze it.
- The plugin never flushes any cache. It writes only its own options/transients and WordPress's
  `update_themes` transient.

Requirements: WordPress 6.4+, PHP 8.3+, outbound HTTPS to `api.github.com` and `codeload.github.com`.
Single-site installs only. On a multisite network it works on the **main site** only: on every
other site it offers nothing, installs nothing and shows a notice saying so.

## Install

Upload `pc-theme-release.zip` (built with `composer build`, see *Development*) under
*Plugins → Add New → Upload*, activate, then open **Appearance → Theme Release**. Only
users with both `manage_options` and `update_themes` see the screen or change any of its settings,
because what is configured here feeds WordPress's theme updates. While `DISALLOW_FILE_MODS` is set
WordPress grants `update_themes` to nobody and nothing can be installed, so there `manage_options`
alone is enough for the settings; updates and rollbacks stay disabled.
The screen has four tabs: *Themes* (daily work: status, Check now, install / roll back), *Channel*,
*GitHub* (token and webhook) and *Health & log*. The setup checklist links each step to its tab.

## Set up (once per site)

1. **Channel** (tab *Channel*). Choose *Staging* or *Production*. Nothing is checked until a channel is chosen.
   Optionally change the staging/production branch names; each theme can override them.
2. **GitHub token** (tab *GitHub*) — one fine-grained personal access token **per site**, so one site can be
   revoked without touching the others:
   1. GitHub → *Settings → Developer settings → Personal access tokens → Fine-grained tokens →
      Generate new token*.
   2. *Resource owner*: the organisation. *Expiration*: as your policy allows (the plugin warns
      14 days before expiry).
   3. *Repository access*: **Only select repositories** → the theme repositories of this site.
   4. *Repository permissions*: **Contents: Read-only** (compare, zipball) and
      **Metadata: Read-only** (tags; GitHub selects it automatically). Nothing else.
   5. Paste the token into the plugin (it is encrypted and never shown again — only its last four
      characters), or better put it in `wp-config.php` (see *Secrets*).
3. **Webhook secret** (tab *GitHub*). Click *Generate a new webhook secret* and copy it — it is shown once.
4. **Themes** (tab *Themes*, the default). Add each installed theme with its repository (`owner/repo`). Then pick the tag that
   is installed now (pre-selected when `style.css` `Version` matches). Until you do, the plugin
   compares against `style.css` `Version`. The tag can be picked once; after that the plugin records
   every install itself. Only themes directly under `wp-content/themes/` can be managed (not
   themes in a sub-folder such as `group/theme`).
5. **GitHub webhook** in each theme repository: *Settings → Webhooks → Add webhook*
   - Payload URL: shown on the plugin screen's *GitHub* tab (`https://SITE/wp-json/pc-theme-release/v1/webhook`)
   - Content type: **application/json** (form-encoded deliveries are refused with 415)
   - Secret: the webhook secret
   - Events: *Let me select individual events* → **Branch or tag creation** and **Pushes**
     (GitHub sends no `create` event when more than three tags are pushed at once; `push` covers it).

A webhook only makes the site re-check GitHub at once; the plugin trusts nothing in the payload
except "repository X changed". Without a webhook the plugin checks every 12 hours (WP-Cron) and on
**Check now**.

## Secrets

Preferred — constants in `wp-config.php` (the database then holds nothing):

```php
define( 'PC_THEME_RELEASE_GITHUB_TOKEN', 'github_pat_…' );
define( 'PC_THEME_RELEASE_WEBHOOK_SECRET', '…' ); // generate: openssl rand -hex 32 (min. 16 characters)
define( 'PC_THEME_RELEASE_CHANNEL', 'production' ); // optional: 'staging' or 'production', read-only in the UI
```

Constants follow the same rules as values entered in the screen (token: 20–255 letters, digits and
`_`; webhook secret: at least 16 characters, no control characters). An invalid constant is not
used at all — the plugin stays inert (token) or refuses every webhook delivery (secret) and shows an
admin notice until it is fixed.

Without constants, secrets are encrypted with libsodium (`secretbox`, random nonce) using a key
derived from `AUTH_KEY` and `SECURE_AUTH_KEY` and stored in a non-autoloaded option. If those
salts are missing, shorter than 32 characters or still the placeholder, the plugin refuses to store
secrets and asks for the constants. If the salts change, the token can no longer be decrypted and
the plugin asks you to enter it again.

**Cloned databases.** The configuration is bound to the site host (scheme and `www.` ignored). If a
production database is copied to staging, the plugin goes inert there and asks to confirm the
channel; confirming deletes the copied secrets so that site needs its own token. With
`PC_THEME_RELEASE_CHANNEL` the channel stays in force and you only confirm the new host.

## Tagging convention (CI)

| Build | Tag | Must be reachable from |
|---|---|---|
| work in progress / staging | `v1.19.1-rc.1`, `v1.19.1-rc.2`, … | `develop` (staging branch) |
| ready for production | `v1.19.1` | `main` (production branch) |

A `v` prefix is optional; tags that are not SemVer are ignored. A suffix tag can never reach
production, even after `develop` is merged into `main`.

The package is GitHub's zip of the tag's **commit** (the repository root must be the theme). Keep
development files out of it with `.gitattributes` in the theme repository:

```gitattributes
/.github        export-ignore
/node_modules   export-ignore
/src            export-ignore
/tests          export-ignore
/.gitattributes export-ignore
/.gitignore     export-ignore
/package.json   export-ignore
/package-lock.json export-ignore
/composer.json  export-ignore
/composer.lock  export-ignore
```

Before installing, the plugin checks that the zip has exactly one top-level folder with a
`style.css` whose *Theme Name* or *Text Domain* matches the installed theme, and renames the folder
to the theme's directory name. A `style.css` *Version* that differs from the tag is only a note.

## Updating, Rollback and freeze

- **Update:** the normal WordPress update notice. Only the highest channel tag is offered.
- **Rollback:** in the theme's row choose a tag → *Review rollback…* → *Roll back and freeze*. It
  runs WordPress's own theme upgrader (with its backup/restore on failure). The confirmation shows
  exactly which commit will be installed; if the tag moved meanwhile, nothing is installed and the
  screen shows the new state. The theme is then frozen; **Unfreeze** brings notices back.
- **Installed tag from the other channel** (e.g. `v1.1.0-rc.2` still installed after the site was
  switched to production, or a tag whose commit is not in the channel branch): the row says so
  ("Installed v1.1.0-rc.2 is from the staging channel. Install v1.0.1 to move this theme to
  production.") and never "Up to date."; Site Health lists it as recommended. Move the theme with
  either the normal WordPress update (it shows "Update to 1.0.1" even though that number is lower)
  or the row's **Install v1.0.1** button → *Switch to production*. Neither freezes the theme.
  A frozen theme gets no offer: unfreeze it first.
  Because that offer is a normal WordPress update, **Update all** (Dashboard → Updates) installs it
  too, even though its version number is lower. To keep such a theme where it is, update the other
  themes one by one, or **freeze** it (a rollback freezes the theme) until you want to move it.
- With `DISALLOW_FILE_MODS` updates and rollbacks are disabled; settings still work.
- Warnings: *tag moved* (installed tag now points to another commit), *no longer on GitHub*,
  *branch not found*, token errors, rate limits.

## Health and WP-Cron

The plugin screen (problems above the tabs; every check, good ones too, and the last 20 events on
the *Health & log* tab) and *Tools → Site Health* show: channel, token (missing, undecryptable, expiring),
webhook secret, salts, GitHub rate limit, and WP-Cron (`DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`,
missing schedule, last run older than 24 hours, overdue events). Loopback problems appear in core's
own Site Health test. A third Site Health test, *ProCoders Theme Release repositories*, lists every
theme whose last GitHub check failed and why (the same text as the Status column on the plugin screen).

The 12-hour check schedules itself on the next page load whenever it is missing (for example when the
activation hook did not run on a symlinked install), so deactivating and activating is not needed.

**Check now** shows a green notice only when every managed theme was checked without an error. When
some or all checks failed it shows a warning or error notice listing each theme and the reason:
"cannot see repository …" (give the token access to that repository), "expired or was revoked"
(create a new token), "rate-limited until …" (wait), "branch … not found" (fix the branch setting).

If WP-Cron does not run, add a server cron (every 15 minutes) and set
`define( 'DISABLE_WP_CRON', true );`:

```sh
# Replace https://example.com with your site's address (the plugin screen shows the exact line).
*/15 * * * * wget -q -O - "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
# or, with WP-CLI:
*/15 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

Webhooks and **Check now** keep working without cron.

## Staging behind Basic Auth or an IP allow-list

GitHub must reach the webhook path. Exempt only that path.

Apache (`.htaccess`, Apache 2.4):

```apache
SetEnvIf Request_URI "^/wp-json/pc-theme-release/v1/webhook$" pctr_webhook
<RequireAny>
    Require env pctr_webhook
    Require valid-user
</RequireAny>
```

nginx:

```nginx
location = /wp-json/pc-theme-release/v1/webhook {
    auth_basic off;
    try_files $uri $uri/ /index.php?$args;
}
```

For an IP allow-list, allow GitHub's webhook ranges from `https://api.github.com/meta` (the `hooks`
list; it changes, so refresh it periodically). The webhook itself is protected by its HMAC
signature, a 1 MB body limit, replay protection (`X-GitHub-Delivery`, 24 hours) and a limit of 30
signed deliveries per minute.

## Git Updater

If Git Updater is active and a theme has a `GitHub Theme URI` header, this plugin does not manage
that theme and says so. Deactivate Git Updater (or remove the header) to switch.

## Uninstall

Deleting the plugin removes its options, transients and cron event, and its entries in WordPress's
list of available theme updates (deactivating removes those entries too). Theme files are never touched.
Revoke the site's token in GitHub and delete the repository webhooks.

## Development

```sh
composer install
composer test          # PHPStan (level 8) + PHPCS (PSR-12 + WPCS security sniffs) + PHPUnit
npm ci
npx playwright test    # real WordPress 6.4 / PHP 8.3 in WordPress Playground, GitHub faked
composer build         # build/pc-theme-release.zip with vendor/autoload.php, no dev files
```

Code: PSR-4 under `src/` (`ProCoders\ThemeRelease\`), PSR-12, one class per file, constructor
injection, interfaces at the WordPress/GitHub boundaries.

## Releasing to the distribution repository

The install-ready plugin lives in a separate repository (`pc-theme-release-dist`, cloned next to
this one) with one squash commit and one tag per release, and no development files.

Once, mark that repository as the distribution repository. The script refuses any folder without
the marker, so a wrong `--dist` (or this repository) is never wiped:

```sh
cd ../pc-theme-release-dist
touch .pc-theme-release-dist
git add .pc-theme-release-dist
git commit -m "Mark this repository as the distribution repository"
```

1. Bump `Version:` in `pc-theme-release.php` and commit.
2. Run:

   ```sh
   composer publish-dist -- 1.1.0 --message="Settings tabs" --push
   ```

The script checks that the version is SemVer and matches the header, that the distribution
repository is not this repository and has the marker, that both repositories have no uncommitted
changes and that the tag is new. It then builds with `bin/build-zip.php` (which writes only into
`build/` or the system temp folder; `COMPOSER_BIN` may name another composer program), replaces
the distribution files (everything but `.git` and the marker; `composer.json` is left out), commits
`v1.1.0 Settings tabs` with this repository's commit in the body, tags `v1.1.0` and, with
`--push`, pushes both. Another location: `--dist=PATH` or `PCTR_DIST_DIR`.
