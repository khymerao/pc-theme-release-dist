# ProCoders Theme Release

Themes from private GitHub repos as normal WordPress updates. WordPress 6.4+, PHP 8.3+.

## Setup

*Appearance -> Theme Release*:

1. **Channel**: *Staging* or *Production*.
2. **GitHub** -> token. GitHub: *Settings -> Developer settings -> Personal access tokens -> Fine-grained tokens ->
   Generate new token*.
   Owner: the organisation. Repositories: only this site's themes. Permissions: **Contents: Read-only**,
   **Metadata: Read-only**, and **Webhooks: Read-only** for delivery alerts (optional; reading webhooks also needs
   the token owner to be a repository admin). One token per site. Only fine-grained tokens (`github_pat_…`) are accepted.
3. **GitHub** -> **Generate a new webhook secret**. Shown once.
4. **Themes** -> **Add theme** (`owner/repo` or the GitHub URL), then **Record installed tag**. Public repositories are refused.
5. Each theme repo: *Settings -> Webhooks -> Add webhook*. URL from the **GitHub** tab, content type
   **application/json**, the secret, events **Branch or tag creation** and **Pushes**.

Optional, in `wp-config.php`:

```php
define( 'PC_THEME_RELEASE_GITHUB_TOKEN', 'github_pat_…' );
define( 'PC_THEME_RELEASE_WEBHOOK_SECRET', '…' ); // openssl rand -hex 32
define( 'PC_THEME_RELEASE_CHANNEL', 'production' ); // or 'staging'
```

## Tags

- Staging: tag with a suffix (`v1.2.0-rc.1`) on `develop`.
- Production: tag without a suffix (`v1.2.0`) on `main`.
- Three or more numbers work: `v1.0.40.39`, `v1.0.40.39-rc.1`. `v1.0.40` < `v1.0.40.0` < `v1.0.40.1`.

The zip is the tagged commit, so the repo root must be the theme; drop dev files with `export-ignore` in the theme's `.gitattributes`.

## Use

- **Update**: the usual WordPress update notice.
- **Rollback**: **Change version** -> tag -> **Rollback…**. The theme is frozen until **Unfreeze**.
- Theme on a tag from the other channel: **Update all** installs this channel's newest tag, even a lower one;
  roll the theme back to freeze it instead.

## Webhook delivery alerts

With a webhook secret set, the 12-hour check and **Check now** also read GitHub's *Recent Deliveries* of the webhook
that points at this site (two API calls per repository).

- **Red notice** on admin screens and Site Health *critical*: the newest delivery failed (GitHub's reason, the last
  successful delivery), the webhook is disabled, or it sends no tag events / not `application/json`. The links open
  the webhook on GitHub and **Health & log**.
- It clears at the next check that sees a successful delivery, or at once when this site receives a valid signed
  delivery for that repository.
- Site Health *recommended*: no webhook points at this site (organisation webhooks are not visible), the token lacks
  **Webhooks: Read-only**, or the last reading is more than 48 hours old.
- Not wanted: delete the webhook secret (updates then come only from the 12-hour check and Check now).

## Notes

- Access: `manage_options` and `update_themes`. With `DISALLOW_FILE_MODS`: `manage_options` only, updates off,
  settings still work.
- Multisite: main site only.
- Cloned database: confirm the site again; the copied token and secret are deleted, enter new ones. With
  `PC_THEME_RELEASE_CHANNEL` they stay: replace them.
- Basic Auth staging: exempt `/wp-json/pc-theme-release/v1/webhook` in `.htaccess` or nginx; IP lists from
  `api.github.com/meta` (`hooks`).
- Uninstall removes plugin data, never theme files. Revoke the token and delete the webhooks in GitHub.
