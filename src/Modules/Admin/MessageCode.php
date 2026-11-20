<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

/**
 * Every message the screen can show after an action. Only the code travels in the URL;
 * the text is fixed here, so a crafted URL cannot put words on the screen.
 */
enum MessageCode: string
{
    case SettingsSaved = 'settings_saved';
    case TokenSaved = 'token_saved';
    case TokenDeleted = 'token_deleted';
    case WebhookSecretSaved = 'webhook_secret_saved';
    case WebhookSecretDeleted = 'webhook_secret_deleted';
    case Checked = 'checked';
    case CheckSkipped = 'check_skipped';
    case CheckFailed = 'check_failed';
    case CheckPartial = 'check_partial';
    case MappingAdded = 'mapping_added';
    case MappingAddedNoTags = 'mapping_added_no_tags';
    case MappingAddedNoChannelTag = 'mapping_added_no_channel_tag';
    case MappingRemoved = 'mapping_removed';
    case TagSeeded = 'tag_seeded';
    case Unfrozen = 'unfrozen';
    case HostConfirmed = 'host_confirmed';
    case InvalidChannel = 'invalid_channel';
    case InvalidBranch = 'invalid_branch';
    case InvalidToken = 'invalid_token';
    case InvalidWebhookSecret = 'invalid_webhook_secret';
    case SecretInConstant = 'secret_in_constant';
    case WeakSalts = 'weak_salts';
    case InvalidRepo = 'invalid_repo';
    case ThemeNotInstalled = 'theme_not_installed';
    case ThemeAlreadyMapped = 'theme_already_mapped';
    case RepoAlreadyMapped = 'repo_already_mapped';
    case RepoPublic = 'repo_public';
    case TagNotInChannel = 'tag_not_in_channel';
    case TagAlreadyRecorded = 'tag_already_recorded';
    case MappingGone = 'mapping_gone';
    case ThemeUnavailable = 'theme_unavailable';
    case RollbackTargetGone = 'rollback_target_gone';
    case RollbackTargetChanged = 'rollback_target_changed';
    case SwitchTargetChanged = 'switch_target_changed';
    case SwitchNotNeeded = 'switch_not_needed';
    case ThemeFrozen = 'theme_frozen';
    case InstallRunning = 'install_running';
    case FileModsDisallowed = 'file_mods_disallowed';
    case HostChanged = 'host_changed';
    case WebhookCreated = 'webhook_created';
    case WebhookExists = 'webhook_exists';
    case WebhookDuplicate = 'webhook_duplicate';
    case WebhookRejected = 'webhook_rejected';
    case WebhookForbidden = 'webhook_forbidden';
    case WebhookTokenRejected = 'webhook_token_rejected';
    case WebhookRateLimited = 'webhook_rate_limited';
    case WebhookFailed = 'webhook_failed';
    case InvalidWebhookToken = 'invalid_webhook_token';
    case WebhookNoSecret = 'webhook_no_secret';
    case WebhookNotHttps = 'webhook_not_https';
    case Failed = 'failed';

    public static function fromReason(string $reason): self
    {
        return self::tryFrom($reason) ?? self::Failed;
    }

    public function isError(): bool
    {
        return ! in_array($this, [
            self::SettingsSaved, self::TokenSaved, self::TokenDeleted, self::WebhookSecretSaved,
            self::WebhookSecretDeleted, self::Checked, self::MappingAdded, self::MappingAddedNoTags, self::MappingAddedNoChannelTag, self::MappingRemoved,
            self::TagSeeded, self::Unfrozen, self::HostConfirmed, self::WebhookCreated, self::WebhookExists,
        ], true);
    }

    /** Notice level: success, warning (some repositories failed) or error. */
    public function level(): string
    {
        if (self::CheckPartial === $this) {
            return 'warning';
        }
        if (self::WebhookExists === $this) {
            return 'info';
        }

        return $this->isError() ? 'error' : 'success';
    }

    public function message(): string
    {
        return match ($this) {
            self::SettingsSaved => __('Settings saved.', 'pc-theme-release'),
            self::TokenSaved => __('GitHub token saved (encrypted).', 'pc-theme-release'),
            self::TokenDeleted => __('GitHub token deleted.', 'pc-theme-release'),
            self::WebhookSecretSaved => __('Webhook secret saved (encrypted).', 'pc-theme-release'),
            self::WebhookSecretDeleted => __('Webhook secret deleted. Webhook deliveries are refused until a new one is set.', 'pc-theme-release'),
            self::Checked => __('GitHub checked. Update notices are refreshed.', 'pc-theme-release'),
            self::CheckFailed => __('Error: GitHub could not be checked. Update notices may be out of date:', 'pc-theme-release'),
            self::CheckPartial => __('Warning: GitHub was checked, but not for every theme. Their update notices may be out of date:', 'pc-theme-release'),
            self::CheckSkipped => __('Nothing was checked: choose the channel and set the GitHub token first.', 'pc-theme-release'),
            self::MappingAdded => __('Theme added. Now pick the tag that is installed now.', 'pc-theme-release'),
            self::MappingAddedNoTags => __('Theme added. No tag list yet: the installed tag can be picked here after the channel and GitHub token are set and a check succeeded.', 'pc-theme-release'),
            self::MappingAddedNoChannelTag => __('Theme added. The repository has no tag for this channel yet.', 'pc-theme-release'),
            self::MappingRemoved => __('Theme removed from Theme Release. Its files were not touched.', 'pc-theme-release'),
            self::TagSeeded => __('Installed tag recorded.', 'pc-theme-release'),
            self::Unfrozen => __('Theme unfrozen. Newer tags are offered again.', 'pc-theme-release'),
            self::HostConfirmed => __('Site address confirmed.', 'pc-theme-release'),
            self::InvalidChannel => __('Error: choose Staging or Production.', 'pc-theme-release'),
            self::InvalidBranch => __('Error: a branch name may contain letters, digits, ".", "_", "-" and "/" (up to 255 characters).', 'pc-theme-release'),
            self::InvalidToken => __('Error: the token must be a fine-grained personal access token: it starts with github_pat_ and has 20–255 letters, digits and "_". Nothing was saved.', 'pc-theme-release'),
            self::InvalidWebhookSecret => __('Error: the webhook secret must be at least 16 characters without control characters. Nothing was saved.', 'pc-theme-release'),
            self::SecretInConstant => __('Error: this secret is defined in wp-config.php; change it there.', 'pc-theme-release'),
            self::WeakSalts => __('Error: AUTH_KEY / SECURE_AUTH_KEY in wp-config.php are missing or weak, so secrets cannot be stored safely. Define PC_THEME_RELEASE_GITHUB_TOKEN / PC_THEME_RELEASE_WEBHOOK_SECRET in wp-config.php instead.', 'pc-theme-release'),
            self::InvalidRepo => __('Error: enter the repository as owner/repo.', 'pc-theme-release'),
            self::ThemeNotInstalled => __('Error: choose an installed theme.', 'pc-theme-release'),
            self::ThemeAlreadyMapped => __('Error: this theme is already managed.', 'pc-theme-release'),
            self::RepoAlreadyMapped => __('Error: this repository is already mapped to another theme.', 'pc-theme-release'),
            self::RepoPublic => __('Error: the repository is public; only private repositories are accepted. Nothing was saved.', 'pc-theme-release'),
            self::TagNotInChannel => __('Error: that tag is not a tag of this site\'s channel. Run Check now and try again.', 'pc-theme-release'),
            self::TagAlreadyRecorded => __('Error: an installed tag is already recorded for this theme. Nothing was changed.', 'pc-theme-release'),
            self::MappingGone => __('Error: this theme is no longer managed.', 'pc-theme-release'),
            self::ThemeUnavailable => __('Error: the theme is missing on disk or managed by Git Updater.', 'pc-theme-release'),
            self::RollbackTargetGone => __('Error: that tag is no longer available for this channel. Nothing was installed.', 'pc-theme-release'),
            self::RollbackTargetChanged => __('Error: the tag now points to a different commit than the one you confirmed. Review it again. Nothing was installed.', 'pc-theme-release'),
            self::SwitchTargetChanged => __('Error: the newest tag of this channel changed since you reviewed it. Review it again. Nothing was installed.', 'pc-theme-release'),
            self::SwitchNotNeeded => __('Error: the installed tag already belongs to this channel, or this channel has no tag to install. Nothing was installed.', 'pc-theme-release'),
            self::ThemeFrozen => __('Error: the theme is frozen. Unfreeze it first. Nothing was installed.', 'pc-theme-release'),
            self::InstallRunning => __('Error: another update or rollback is running. Try again in a minute.', 'pc-theme-release'),
            self::FileModsDisallowed => __('Error: file modifications are disabled on this site (DISALLOW_FILE_MODS).', 'pc-theme-release'),
            self::HostChanged => __('Error: the site address changed. Confirm this site first (top of the screen).', 'pc-theme-release'),
            self::WebhookCreated => __('Webhook created on GitHub. GitHub sends a ping now; if it does not arrive, Health & log and the delivery alert say why. Revoke the one-time token on GitHub now.', 'pc-theme-release'),
            self::WebhookExists => __('A webhook on GitHub already points at this site. Nothing was created. Revoke the one-time token on GitHub now.', 'pc-theme-release'),
            self::WebhookDuplicate => __('Error: GitHub says a webhook with this URL already exists in this repository. Nothing was created.', 'pc-theme-release'),
            self::WebhookRejected => __('Error: GitHub refused the webhook. Nothing was created. GitHub says:', 'pc-theme-release'),
            self::WebhookForbidden => __('Error: the one-time token cannot manage this repository\'s webhooks. It needs Webhooks: Read and write, and the token owner must be a repository admin; see the README. Or add the webhook by hand.', 'pc-theme-release'),
            self::WebhookTokenRejected => __('Error: GitHub did not accept the one-time token (wrong, expired or revoked). Nothing was created.', 'pc-theme-release'),
            self::WebhookRateLimited => __('Error: GitHub\'s rate limit for this token is reached. Try again later. Nothing was created.', 'pc-theme-release'),
            self::WebhookFailed => __('Error: GitHub could not be reached or answered unexpectedly. Try again: an existing webhook is found first, so none is created twice.', 'pc-theme-release'),
            self::InvalidWebhookToken => __('Error: enter a fine-grained personal access token (github_pat_…, 20–255 letters, digits and "_") with Webhooks: Read and write. Nothing was sent to GitHub.', 'pc-theme-release'),
            self::WebhookNoSecret => __('Error: set the webhook secret first (GitHub tab). Nothing was sent to GitHub.', 'pc-theme-release'),
            self::WebhookNotHttps => __('Error: this site\'s webhook URL does not start with https://, so the webhook is not created. Serve the site over HTTPS, or add the webhook by hand. Nothing was sent to GitHub.', 'pc-theme-release'),
            default => __('Error: the action failed.', 'pc-theme-release'),
        };
    }
}
