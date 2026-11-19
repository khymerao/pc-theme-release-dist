<?php

declare(strict_types=1);

namespace ProCoders\ThemeRelease\Admin;

use ProCoders\ThemeRelease\Config\Channel;
use ProCoders\ThemeRelease\Config\SiteContext;
use ProCoders\ThemeRelease\Release\Checker;
use ProCoders\ThemeRelease\Release\StateStore;
use ProCoders\ThemeRelease\Release\StatusLog;
use ProCoders\ThemeRelease\Release\UpdateTransient;
use ProCoders\ThemeRelease\Security\SecretKind;
use ProCoders\ThemeRelease\Security\Secrets;
use ProCoders\ThemeRelease\Status\Offers;
use ProCoders\ThemeRelease\Status\ThemeStatus;
use ProCoders\ThemeRelease\Support\Environment;
use ProCoders\ThemeRelease\Support\ValidationError;
use ProCoders\ThemeRelease\Theme\InstallRecord;
use ProCoders\ThemeRelease\Theme\MappingRepository;

/**
 * The admin-post.php handlers of the plugin screen: each one is guarded, changes one thing and
 * redirects back to its tab with a message code.
 */
final class Actions
{
    public function __construct(
        private readonly SiteContext $site,
        private readonly Secrets $secrets,
        private readonly MappingRepository $mappings,
        private readonly StateStore $state,
        private readonly Offers $offers,
        private readonly Checker $checker,
        private readonly StatusLog $log,
        private readonly Environment $env,
    ) {
    }

    /** admin_post_{action} entry point. */
    public function dispatch(): void
    {
        $current = current_action();
        $action = is_string($current) ? (string) preg_replace('/^admin_post_/', '', $current) : '';
        $this->handle($action, Input::fromGlobals());
    }

    public function handle(string $name, Input $input): never
    {
        $action = Action::tryFrom($name);
        if (null === $action || $action->postsToScreen()) {
            Module::deny(400);
        }
        Module::guard($action, $input);
        try {
            $code = $this->run($action, $input);
        } catch (ValidationError $error) {
            $keep = [];
            foreach ($action->keptFields() as $field) {
                $keep[$field] = $input->text($field);
            }
            Module::redirect(MessageCode::fromReason($error->reason), $action->tab(), $keep, $action->errorView());
        }
        Module::redirect($code, $action->tab());
    }

    /** @throws ValidationError */
    private function run(Action $action, Input $input): MessageCode
    {
        return match ($action) {
            Action::SaveSettings => $this->saveSettings($input),
            Action::SaveToken => $this->saveSecret(SecretKind::Token, $input->secret('token'), MessageCode::TokenSaved),
            Action::DeleteToken => $this->deleteSecret(SecretKind::Token, MessageCode::TokenDeleted),
            Action::SaveWebhookSecret => $this->saveSecret(SecretKind::WebhookSecret, $input->secret('webhook_secret'), MessageCode::WebhookSecretSaved),
            Action::DeleteWebhookSecret => $this->deleteSecret(SecretKind::WebhookSecret, MessageCode::WebhookSecretDeleted),
            Action::CheckNow => $this->checkNow(),
            Action::AddMapping => $this->addMapping($input),
            Action::RemoveMapping => $this->removeMapping($input->text('theme')),
            Action::SeedTag => $this->seedTag($input->text('theme'), $input->text('tag')),
            Action::Unfreeze => $this->unfreeze($input->text('theme')),
            Action::ConfirmHost => $this->confirmHost($input),
            default => throw new \LogicException('Not an admin-post action: ' . $action->value),
        };
    }

    /** @throws ValidationError */
    private function saveSettings(Input $input): MessageCode
    {
        $fromConstant = $this->site->settings()->channelFromConstant;
        if ($this->site->hostChanged() && ! $fromConstant) {
            // A cloned site must go through the confirmation, which also drops the copied secrets.
            throw new ValidationError('host_changed');
        }
        $channel = $fromConstant ? null : self::requireChannel($input);
        $this->site->save($channel, $input->text('staging_branch'), $input->text('production_branch'));
        UpdateTransient::refresh();

        return MessageCode::SettingsSaved;
    }

    /** @throws ValidationError */
    private function saveSecret(SecretKind $kind, string $value, MessageCode $saved): MessageCode
    {
        $this->secrets->save($kind, $value);

        return $saved;
    }

    private function deleteSecret(SecretKind $kind, MessageCode $deleted): MessageCode
    {
        $this->secrets->delete($kind);

        return $deleted;
    }

    private function checkNow(): MessageCode
    {
        $checked = $this->checker->checkAll('check_now');
        if (0 === $checked && ! $this->site->isOperational()) {
            return MessageCode::CheckSkipped;
        }
        // Judged from what this round stored, so a missing branch (no exception) and an unconfirmed branch
        // (a failed compare, kept as a warning) count as failures too.
        $statuses = $this->offers->all();
        $failed = count(ThemeStatus::failures($statuses));
        if (0 === $failed) {
            return MessageCode::Checked;
        }

        return $failed < count($statuses) ? MessageCode::CheckPartial : MessageCode::CheckFailed;
    }

    /** @throws ValidationError */
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
        $checkedFine = $this->site->isOperational() && $status->state->checkedAt > 0 && null === $status->problem();

        return $checkedFine ? MessageCode::MappingAddedNoChannelTag : MessageCode::MappingAddedNoTags;
    }

    /** @throws ValidationError */
    private function removeMapping(string $stylesheet): MessageCode
    {
        $removed = $this->mappings->remove($stylesheet) ?? throw new ValidationError('mapping_gone');
        $this->state->forgetRepo($removed->repo);
        $this->log->add('mapping_removed', $removed->repo->full());
        UpdateTransient::refresh();

        return MessageCode::MappingRemoved;
    }

    /**
     * Records the tag that is installed now. Only when nothing is recorded yet; it never rewrites a
     * record (audit S8).
     *
     * @throws ValidationError
     */
    private function seedTag(string $stylesheet, string $tag): MessageCode
    {
        $mapping = $this->mappings->require($stylesheet);
        if (null !== $mapping->record) {
            throw new ValidationError('tag_already_recorded');
        }
        $release = $this->offers->currentState($mapping)->release($tag) ?? throw new ValidationError('tag_not_in_channel');
        $this->mappings->recordInstall($mapping->stylesheet, new InstallRecord($release->tag, $release->sha, $this->env->now()), $mapping->frozen);
        UpdateTransient::refresh();

        return MessageCode::TagSeeded;
    }

    /** @throws ValidationError */
    private function unfreeze(string $stylesheet): MessageCode
    {
        $mapping = $this->mappings->require($stylesheet);
        $this->mappings->setFrozen($mapping->stylesheet, false);
        $this->log->add('unfrozen', $mapping->repo->full());
        UpdateTransient::refresh();

        return MessageCode::Unfrozen;
    }

    /** @throws ValidationError */
    private function confirmHost(Input $input): MessageCode
    {
        $current = $this->site->settings();
        if ($current->channelFromConstant) {
            $this->site->rebind();

            return MessageCode::HostConfirmed;
        }
        $channel = self::requireChannel($input);
        // A cloned database must not keep using the source site's secrets (spec call 14).
        $this->secrets->deleteStored();
        $this->site->save($channel, $current->stagingBranch, $current->productionBranch);
        UpdateTransient::refresh();

        return MessageCode::HostConfirmed;
    }

    /** @throws ValidationError */
    private static function requireChannel(Input $input): Channel
    {
        return Channel::fromValue($input->text('channel')) ?? throw new ValidationError('invalid_channel');
    }
}
