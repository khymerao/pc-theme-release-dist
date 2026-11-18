<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\SettingsRepository;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Support\Clock;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\InstallRecord;
use ProCoders\ThemeRelease\Theme\MappingRepository;
use ProCoders\ThemeRelease\Update\UpdateInjector;
use ProCoders\ThemeRelease\Update\UpdateOffer;

/**
 * admin-post.php handlers. Each one: POST only, manage_options and update_themes, its own nonce, then redirect with a code
 * to the tab its form lives on (success and error alike).
 */
final class Actions
{
    public const string SAVE_SETTINGS = 'pctr_save_settings';
    public const string SAVE_TOKEN = 'pctr_save_token';
    public const string DELETE_TOKEN = 'pctr_delete_token';
    public const string SAVE_WEBHOOK_SECRET = 'pctr_save_webhook_secret';
    public const string DELETE_WEBHOOK_SECRET = 'pctr_delete_webhook_secret';
    public const string CHECK_NOW = 'pctr_check_now';
    public const string ADD_MAPPING = 'pctr_add_mapping';
    public const string REMOVE_MAPPING = 'pctr_remove_mapping';
    public const string SEED_TAG = 'pctr_seed_tag';
    public const string UNFREEZE = 'pctr_unfreeze';
    public const string CONFIRM_HOST = 'pctr_confirm_host';

    /** @var array<string, string> action → handler method */
    private const array HANDLERS = [
        self::SAVE_SETTINGS => 'saveSettings',
        self::SAVE_TOKEN => 'saveToken',
        self::DELETE_TOKEN => 'deleteToken',
        self::SAVE_WEBHOOK_SECRET => 'saveWebhookSecret',
        self::DELETE_WEBHOOK_SECRET => 'deleteWebhookSecret',
        self::CHECK_NOW => 'checkNow',
        self::ADD_MAPPING => 'addMapping',
        self::REMOVE_MAPPING => 'removeMapping',
        self::SEED_TAG => 'seedTag',
        self::UNFREEZE => 'unfreeze',
        self::CONFIRM_HOST => 'confirmHost',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly MappingRepository $mappings,
        private readonly StateStore $state,
        private readonly UpdateOffer $offers,
        private readonly Checker $checker,
        private readonly UpdateInjector $injector,
        private readonly StatusLog $log,
        private readonly Clock $clock,
        private readonly Responder $responder,
    ) {
    }

    /** Registers one admin_post_{action} hook per action, each pointing at dispatch(). */
    public function register(): void
    {
        foreach (array_keys(self::HANDLERS) as $action) {
            add_action('admin_post_' . $action, [$this, 'dispatch']);
        }
    }

    /** admin_post_{action} entry point. */
    public function dispatch(): void
    {
        $current = current_action();
        $action = is_string($current) ? (string) preg_replace('/^admin_post_/', '', $current) : '';
        $this->handle($action, Input::fromGlobals());
    }

    public function handle(string $action, Input $input): never
    {
        $method = self::HANDLERS[$action] ?? null;
        if (null === $method) {
            $this->responder->deny(400);
        }
        self::guard($this->responder, $action, $input);
        $tab = self::tabFor($action);
        try {
            /** @var MessageCode $code */
            $code = $this->$method($input);
        } catch (ValidationError $error) {
            $keep = self::ADD_MAPPING === $action
                ? ['repo' => $input->text('repo'), 'theme' => $input->text('theme'), 'branch' => $input->text('branch')]
                : [];
            $this->responder->redirect(MessageCode::fromReason($error->reason), $keep, $tab);
        }
        $this->responder->redirect($code, [], $tab);
    }

    /** The tab whose form submits the action; the redirect goes back there. */
    public static function tabFor(string $action): Tab
    {
        return match ($action) {
            self::SAVE_SETTINGS, self::CONFIRM_HOST => Tab::Channel,
            self::SAVE_TOKEN, self::DELETE_TOKEN, self::SAVE_WEBHOOK_SECRET, self::DELETE_WEBHOOK_SECRET => Tab::GitHub,
            default => Tab::Themes,
        };
    }

    /** @return list<string> every admin-post action this class handles */
    public static function names(): array
    {
        return array_keys(self::HANDLERS);
    }

    /** Shared by admin-post handlers and the screen's own POST forms. */
    public static function guard(Responder $responder, string $action, Input $input): void
    {
        if ('POST' !== $input->method) {
            $responder->deny(405);
        }
        if (! Capability::canManage()) {
            $responder->deny(403);
        }
        if (false === wp_verify_nonce($input->nonce(), $action)) {
            $responder->deny(403);
        }
    }

    private function saveSettings(Input $input): MessageCode
    {
        $channel = null;
        if ($this->site->hostChanged() && ! $this->site->settings()->channelFromConstant) {
            // A cloned site must go through the confirmation, which also drops the copied secrets.
            throw new ValidationError('host_changed');
        }
        if (! $this->site->settings()->channelFromConstant) {
            $channel = Channel::fromValue($input->text('channel'));
            if (null === $channel) {
                throw new ValidationError('invalid_channel', 'channel');
            }
        }
        $this->settings->save($channel, $input->text('staging_branch'), $input->text('production_branch'));
        $this->injector->refresh();
        return MessageCode::SettingsSaved;
    }

    private function saveToken(Input $input): MessageCode
    {
        $this->secrets->saveToken($input->secret('token'));
        return MessageCode::TokenSaved;
    }

    private function deleteToken(Input $input): MessageCode
    {
        $this->secrets->delete(SecretKind::Token);
        return MessageCode::TokenDeleted;
    }

    private function saveWebhookSecret(Input $input): MessageCode
    {
        $this->secrets->saveWebhookSecret($input->secret('webhook_secret'));
        return MessageCode::WebhookSecretSaved;
    }

    private function deleteWebhookSecret(Input $input): MessageCode
    {
        $this->secrets->delete(SecretKind::WebhookSecret);
        return MessageCode::WebhookSecretDeleted;
    }

    private function checkNow(Input $input): MessageCode
    {
        $checked = $this->checker->checkAll('check_now');
        if (0 === $checked && ! $this->site->isOperational()) {
            return MessageCode::CheckSkipped;
        }
        // Judged from what this round stored, so a missing branch (no exception) and an unconfirmed branch
        // (a failed compare, kept as a warning) count as failures too.
        $statuses = $this->offers->all();
        $failed = count(StatusText::failures($statuses));
        if (0 === $failed) {
            return MessageCode::Checked;
        }
        return $failed < count($statuses) ? MessageCode::CheckPartial : MessageCode::CheckFailed;
    }

    private function addMapping(Input $input): MessageCode
    {
        $mapping = $this->mappings->add($input->text('theme'), $input->text('repo'), $input->text('branch'));
        $this->log->add('mapping_added', $mapping->repo->full());
        if ($this->site->isOperational()) {
            $this->checker->checkRepo($mapping->repo, 'mapping_added');
        }
        // Only promise a pick when the row will have a tag list to pick from.
        $status = $this->offers->evaluate($mapping);
        if ([] !== $status->state->releases) {
            return MessageCode::MappingAdded;
        }
        // Checked fine (same rule as Check now) but nothing to pick: the repository has no tag of this channel yet.
        $checkedFine = $this->site->isOperational() && $status->state->checkedAt > 0 && null === StatusText::problem($status);
        return $checkedFine ? MessageCode::MappingAddedNoChannelTag : MessageCode::MappingAddedNoTags;
    }

    private function removeMapping(Input $input): MessageCode
    {
        $removed = $this->mappings->remove($input->text('theme'));
        if (null === $removed) {
            throw new ValidationError('mapping_gone');
        }
        $this->state->forgetRepo($removed->repo);
        $this->log->add('mapping_removed', $removed->repo->full());
        $this->injector->refresh();
        return MessageCode::MappingRemoved;
    }

    private function seedTag(Input $input): MessageCode
    {
        $mapping = $this->mappings->get($input->text('theme'));
        if (null === $mapping) {
            throw new ValidationError('mapping_gone');
        }
        // Seeding only records what is installed when nothing is recorded yet; it never rewrites a record (audit S8).
        if (null !== $mapping->record) {
            throw new ValidationError('tag_already_recorded');
        }
        $release = $this->offers->currentState($mapping)->release($input->text('tag'));
        if (null === $release) {
            throw new ValidationError('tag_not_in_channel', 'tag');
        }
        $this->mappings->recordInstall($mapping->stylesheet, new InstallRecord($release->tag, $release->sha, $this->clock->now()), $mapping->frozen);
        $this->injector->refresh();
        return MessageCode::TagSeeded;
    }

    private function unfreeze(Input $input): MessageCode
    {
        $mapping = $this->mappings->get($input->text('theme'));
        if (null === $mapping) {
            throw new ValidationError('mapping_gone');
        }
        $this->mappings->setFrozen($mapping->stylesheet, false);
        $this->log->add('unfrozen', $mapping->repo->full());
        $this->injector->refresh();
        return MessageCode::Unfrozen;
    }

    private function confirmHost(Input $input): MessageCode
    {
        if ($this->site->settings()->channelFromConstant) {
            $this->settings->rebind();
            return MessageCode::HostConfirmed;
        }
        $channel = Channel::fromValue($input->text('channel'));
        if (null === $channel) {
            throw new ValidationError('invalid_channel', 'channel');
        }
        // A cloned database must not keep using the source site's secrets (spec call 14).
        $this->secrets->deleteStored();
        $current = $this->site->settings();
        $this->settings->save($channel, $current->stagingBranch, $current->productionBranch);
        $this->injector->refresh();
        return MessageCode::HostConfirmed;
    }
}
