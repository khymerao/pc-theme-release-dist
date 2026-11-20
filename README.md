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
   - The **token owner** must be an admin of the repository to read or create its webhooks.
3. **GitHub** -> **Generate a new webhook secret**. The plugin creates the secret and shows it once: copy it now if you
   set up webhooks by hand in step 5. Generating again replaces it, and every webhook then needs the new one.
4. **Themes** -> **Add theme** (`owner/repo` or the GitHub URL), then **Record installed tag**. Public repositories are refused.
5. The webhook of each theme repository, one of:
   - **Create webhook…** (link under the repository on **Themes**, shown while no webhook of this site is known): paste a
     **one-time token** and click **Create webhook**. The plugin reads the repository webhooks, and creates one only
     if none points at this site: URL from the **GitHub** tab, `application/json`, the secret from step 3, events
     **Branch or tag creation** and **Pushes**. The token is used for this click only and never saved. Needs an
     `https://` site and a confirmed site address. The one-time token: fine-grained, the same **resource owner** and
     **repository access** as above, **Repository permissions: Webhooks: Read and write** and nothing else (GitHub
     adds Metadata: Read-only; tick it if the form asks), **Account permissions: none**, **Expiration**: the shortest
     (1 day, or a custom date). Its owner must be a repository admin, and a pending organisation approval gives 403.
     **Revoke it on GitHub afterwards.** The saved token from step 2 stays read-only.
   - By hand: *Settings -> Webhooks -> Add webhook*. URL from the **GitHub** tab, content type **application/json**,
     **Secret**: the secret from step 3 (generated on the plugin page, not in GitHub), events **Branch or tag
     creation** and **Pushes**.

   Create webhook messages: *needs Webhooks: Read and write … repository admin* (403 / 404: wrong permission, not an
   admin, token pending approval or for another resource owner); *GitHub did not accept the one-time token* (wrong,
   expired or revoked); *already points at this site* / *already exists* (nothing created); *GitHub refused* (GitHub's
   own reason, e.g. a URL it cannot reach); *rate limit*; *could not be reached* (try again: an existing webhook is
   found first, so none is created twice).

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
- Tunnels that rewrite the host in saved options (Local Live Link / ngrok): handled; the plugin checks every option it
  saves and writes it again when a filter changed it. Opened under another host (the tunnel, then `client.local`), the
  site asks you to confirm its address again.
- Basic Auth staging: exempt `/wp-json/pc-theme-release/v1/webhook` in `.htaccess` or nginx; IP lists from
  `api.github.com/meta` (`hooks`).
- Create webhook sends the webhook secret to `api.github.com` in the request body and never logs it; the plugin log
  keeps the repository and HTTP status only. Third-party HTTP-logging plugins may record request bodies: turn them off
  while you click it.
- Uninstall removes plugin data, never theme files. Revoke the token and delete the webhooks in GitHub.
