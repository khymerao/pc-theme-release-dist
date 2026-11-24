<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Core\Support;

/**
 * Every option, transient, hook and constant name the plugin owns, in one place.
 */
final class Keys
{
    public const string PREFIX = 'pc_theme_release_';
    public const string SETTINGS = 'pc_theme_release_settings';
    public const string SECRETS = 'pc_theme_release_secrets';
    /** Set once every stored secret is bound to its slot; kept when the secrets are deleted (audit S9). */
    public const string SECRETS_FORMAT = 'pc_theme_release_secrets_format';
    public const string THEMES = 'pc_theme_release_themes';
    public const string STATE = 'pc_theme_release_state';
    public const string LOG = 'pc_theme_release_log';
    public const string RATE_WINDOW = 'pc_theme_release_webhook_window';
    public const string DELIVERY_PREFIX = 'pc_theme_release_dlv_';
    public const string COMPARE_PREFIX = 'pc_theme_release_cmp_';
    /** A just-generated theme webhook secret (encrypted), per user and theme, for one display (removed with the PREFIX transients). */
    public const string SECRET_SHOWN_PREFIX = 'pc_theme_release_whsec_';
    /** The plugin's own update lookup (site option, network-wide; deleted on uninstall). */
    public const string SELF_UPDATE = 'pc_theme_release_self_update';
    public const string CRON_HOOK = 'pc_theme_release_check';
    public const string LOCK = 'pc_theme_release';
    public const string PAGE_SLUG = 'pc-theme-release';
    public const string REST_NAMESPACE = 'pc-theme-release/v1';

    /** Scalar entries of the STATE option, next to its "repos" map. */
    public const string RATE_LIMITED_UNTIL = 'rate_limited_until';
    public const string TOKEN_EXPIRES_AT = 'token_expires_at';
    public const string LAST_CRON_AT = 'last_cron_at';
    public const string LAST_WEBHOOK_AT = 'last_webhook_at';
    public const string LAST_CHECK_AT = 'last_check_at';

    public const string CONST_CHANNEL = 'PC_THEME_RELEASE_CHANNEL';
    public const string CONST_TOKEN = 'PC_THEME_RELEASE_GITHUB_TOKEN';
    public const string CONST_WEBHOOK_SECRET = 'PC_THEME_RELEASE_WEBHOOK_SECRET';

    /** @return list<string> Options removed on uninstall. */
    public static function options(): array
    {
        return [self::SETTINGS, self::SECRETS, self::SECRETS_FORMAT, self::THEMES, self::STATE, self::LOG];
    }
}
