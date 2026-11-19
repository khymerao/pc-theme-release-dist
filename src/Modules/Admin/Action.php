<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Modules\Admin;

/**
 * Every state-changing request of the plugin screen. Most go to admin-post.php; three are posted to the
 * screen itself, because their answer is a page (the one-time secret, the install result).
 */
enum Action: string
{
    case SaveSettings = 'pctr_save_settings';
    case SaveToken = 'pctr_save_token';
    case DeleteToken = 'pctr_delete_token';
    case SaveWebhookSecret = 'pctr_save_webhook_secret';
    case DeleteWebhookSecret = 'pctr_delete_webhook_secret';
    case CheckNow = 'pctr_check_now';
    case AddMapping = 'pctr_add_mapping';
    case RemoveMapping = 'pctr_remove_mapping';
    case SeedTag = 'pctr_seed_tag';
    case Unfreeze = 'pctr_unfreeze';
    case ConfirmHost = 'pctr_confirm_host';
    case GenerateWebhookSecret = 'pctr_generate_webhook_secret';
    case Rollback = 'pctr_rollback';
    case SwitchChannel = 'pctr_switch_channel';

    /** @return list<self> The actions handled by admin-post.php, in registration order. */
    public static function adminPost(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $action): bool => ! $action->postsToScreen()));
    }

    /** Posted to the plugin screen itself (guarded in its load- hook), not to admin-post.php. */
    public function postsToScreen(): bool
    {
        return in_array($this, [self::GenerateWebhookSecret, self::Rollback, self::SwitchChannel], true);
    }

    /** Installs a theme: also needs update_themes. */
    public function installs(): bool
    {
        return self::Rollback === $this || self::SwitchChannel === $this;
    }

    /** The tab whose form submits the action; the redirect goes back there. */
    public function tab(): Tab
    {
        return match ($this) {
            self::SaveSettings, self::ConfirmHost => Tab::Channel,
            self::SaveToken, self::DeleteToken, self::SaveWebhookSecret, self::DeleteWebhookSecret, self::GenerateWebhookSecret => Tab::GitHub,
            default => Tab::Themes,
        };
    }

    /** The view of its tab a refusal goes back to ('' for the tab itself): Add theme has its own sub-view. */
    public function errorView(): string
    {
        return self::AddMapping === $this ? 'add' : '';
    }

    /** @return list<string> Non-secret fields shown again after a refusal. */
    public function keptFields(): array
    {
        return self::AddMapping === $this ? ['repo', 'theme', 'branch'] : [];
    }
}
