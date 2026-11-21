# ProCoders Theme Release

Themes from private GitHub repos as normal WordPress updates. WordPress 6.4+, PHP 8.3+.

## Setup

*Appearance -> Theme Release*:

1. **Channel**: *Staging* or *Production*.
2. **GitHub** -> token. GitHub: *Settings -> Developer settings -> Personal access tokens -> Fine-grained tokens ->
   Generate new token*. Only fine-grained tokens (`github_pat_…`) are accepted. One token per site.
   - **Resource owner**: the organisation (or user) that owns the theme repositories. An organisation must allow
     fine-grained tokens; if it requires approval, an organisation owner approves the token (*Organisation settings ->
     Personal access tokens -> Pending requests*). Until then GitHub answers 403 / 404 for the private repositories.
   - **Repository access**: *Only select repositories*, including every theme repository of this site.
   - **Repository permissions**: **Contents: Read-only**, **Metadata: Read-only**, and **Webhooks: Read-only** for
     delivery alerts (optional).
   - **Account permissions**: none.
   - The **token owner** must be an admin of the repository for **Webhooks: Read-only** to read its webhooks.
3. **Themes** -> **Add theme** (`owner/repo` or the GitHub URL), then **Record installed tag**.
   Public repositories are refused. Each theme gets its **own webhook secret**; the page after **Add theme** shows it
   once in the theme's **Webhook setup** panel.
4. The webhook of each theme repository: **Webhook setup…** (link under the repository on **Themes**, shown while no
   webhook of this site is known or the theme has no secret of its own). The panel lists:
   - **Payload URL** and the **Secret** (shown once, right after it was generated), each with **Copy**;
   - *Content type: application/json. Events: Branch or tag creation, Pushes.*;
   - **Open GitHub: Add webhook** (the repository's *Settings -> Webhooks -> Add webhook* page, in a new tab; only a
     repository owner or admin can add it). Paste the Payload URL and the Secret, choose **application/json**, *Let me
     select individual events* -> **Branch or tag creation** and **Pushes**, keep **Active**, click **Add webhook**.

   Secret lost before pasting it, or wanted new: **Webhook setup…** -> **Generate new secret…** -> confirm. Only this
   theme's secret changes; edit the webhook on GitHub and paste the new one (deliveries are refused until then). No
   GitHub token with write access is ever needed.

Optional, in `wp-config.php`:

```php
define( 'PC_THEME_RELEASE_GITHUB_TOKEN', 'github_pat_…' );
define( 'PC_THEME_RELEASE_WEBHOOK_SECRET', '…' ); // openssl rand -hex 32; one secret for every theme
define( 'PC_THEME_RELEASE_CHANNEL', 'production' ); // or 'staging'
```

With `PC_THEME_RELEASE_WEBHOOK_SECRET` that one secret verifies every theme (paste it into each webhook); the per-theme
secrets and their buttons are not used then.

**Sites set up with 1.5.x or earlier (one shared secret):** nothing breaks on update. A theme without its own secret is still
verified with the shared secret (its panel says *Shared secret*). **Generate own secret…** gives it its own; edit the
webhook on GitHub and paste it, after which the shared secret no longer works for that theme. The shared secret stays
on the **GitHub** tab (replace or generate a new one) and can be deleted once no theme uses it.

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

For each theme with a webhook secret (its own, the shared one or the wp-config one), the 12-hour check and **Check
now** also read GitHub's *Recent Deliveries* of the webhook that points at this site (two API calls per repository).

- **Red notice** on admin screens and Site Health *critical*: the newest delivery failed (GitHub's reason, the last
  successful delivery), the webhook is disabled, or it sends no tag events / not `application/json`. The links open
  the webhook on GitHub and **Health & log**.
- It clears at the next check that sees a successful delivery, or at once when this site receives a valid signed
  delivery for that repository.
- Site Health *recommended*: no webhook points at this site (organisation webhooks are not visible), the token lacks
  **Webhooks: Read-only**, or the last reading is more than 48 hours old.
- Not wanted: remove the webhook on GitHub (updates then come only from the 12-hour check and Check now).

## Notes

- Access: `manage_options` and `update_themes`. With `DISALLOW_FILE_MODS`: `manage_options` only, updates off,
  settings still work.
- Multisite: main site only.
- Cloned database: confirm the site again; the copied token and every webhook secret are deleted: enter a new token
  and use **Webhook setup…** -> **Generate secret** per theme. With `PC_THEME_RELEASE_CHANNEL` they stay: replace them.
- Removing a theme from Theme Release deletes its webhook secret; its webhook's deliveries are then refused (401).
- Tunnels that rewrite the host in saved options (Local Live Link / ngrok): handled; the plugin checks every option it
  saves and writes it again when a filter changed it. Opened under another host (the tunnel, then `client.local`), the
  site asks you to confirm its address again.
- Basic Auth staging: exempt `/wp-json/pc-theme-release/v1/webhook` in `.htaccess` or nginx; IP lists from
  `api.github.com/meta` (`hooks`).
- Webhook secrets are stored encrypted (bound to the theme and its repository), shown once only to the admin who
  generated them, never put in a URL or the log; the log keeps the repository only.
- Uninstall removes plugin data, never theme files. Revoke the token and delete the webhooks in GitHub.
