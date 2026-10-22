<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use App\Support\UndeliverableEmail;
use Plugins\Sirsoft\SocialLogin\Enums\UnlinkReason;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookStatus;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookUnlinkPolicy;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 받은 웹훅을 처리합니다 (큐 잡 본체).
 *
 * 제공자 쪽에서 회원이 연결을 끊었거나 계정을 지웠다는 알림이다. 사이트 정책(`webhook_unlink_policy`)이
 *  - `unlink_only`(기본): 이 사이트의 연결만 지운다. 제공자는 이미 끊었으므로 권한 회수를 다시 부르지 않는다. 비밀번호도 다른
 *    연결도 없어 로그인할 수단이 사라진 회원에게는(메일을 받을 수 있으면) 안내 훅을 쏜다 — 알림 정의가 「비밀번호 찾기」 메일을 붙인다.
 *  - `withdraw_user`: 연결을 지우고 코어 탈퇴를 부른다. 관리자 계정은 코어가 거부하므로 `failed` 로 남기고 관리자에게 알린다.
 * 카카오 「가입 미완료(INCOMPLETE_SIGN_UP)」는 연결만 정리하고 안내하지 않는다(가입을 끝내지 않은 사람이다).
 *
 * @since 1.0.0
 */
class WebhookProcessor
{
    /** 마지막 로그인 수단 해제 안내 훅 (인자: User $user, string $providerKey) */
    public const ACTION_UNLINKED_LAST_METHOD = 'sirsoft-social_login.account.unlinked_last_method';

    /** 웹훅 처리 실패 훅 (인자: array{event_id, provider_key, event_type, error}) */
    public const ACTION_FAILED = 'sirsoft-social_login.webhook.failed';

    /**
     * @param  WebhookEventRepositoryInterface  $events  웹훅 저장소
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AccountLinkService  $links  연결 서비스
     * @param  UserService  $userService  코어 회원 서비스 (탈퇴)
     */
    public function __construct(
        private readonly WebhookEventRepositoryInterface $events,
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly UserRepositoryInterface $users,
        private readonly AccountLinkService $links,
        private readonly UserService $userService,
    ) {}

    /**
     * 이벤트 1건을 처리합니다.
     *
     * 처리 중 예외는 그대로 올린다 — 큐가 다시 시도하고, 마지막 시도가 실패하면 잡이 {@see self::markFailed()} 를 부른다.
     *
     * @param  int  $eventId  이벤트 ID
     * @return void 반환값 없음
     */
    public function process(int $eventId): void
    {
        $event = $this->events->find($eventId);
        if ($event === null || in_array($event->status, [WebhookStatus::Processed->value, WebhookStatus::Ignored->value], true)) {
            return;
        }

        $this->events->update($event, ['attempts' => (int) $event->attempts + 1]);

        $account = $event->provider_user_id !== null
            ? $this->accounts->findByProviderUid($event->provider_key, $event->provider_user_id)
            : null;
        $user = $account !== null ? $this->users->findById((int) $account->user_id) : null;

        if ($account === null || ! $user instanceof User) {
            $this->finish($event, WebhookStatus::Ignored, 'not_linked');

            return;
        }

        $this->links->remove($account, $user, UnlinkReason::Webhook, false);

        $policy = WebhookUnlinkPolicy::tryFrom((string) plugin_setting('sirsoft-social_login', 'webhook_unlink_policy', 'unlink_only'))
            ?? WebhookUnlinkPolicy::UnlinkOnly;
        $incomplete = $event->event_type === WebhookEventType::IncompleteSignup->value;

        if ($policy === WebhookUnlinkPolicy::WithdrawUser && ! $incomplete) {
            try {
                $this->userService->withdrawUser($user);
            } catch (\Throwable $e) {
                // 관리자 탈퇴 거부 같은 정책 거절 — 다시 시도해도 같다. 연결 해제는 이미 끝났다.
                $this->finish($event, WebhookStatus::Failed, 'withdraw_refused: '.class_basename($e));
                $this->notifyFailed($event, 'withdraw_refused');

                return;
            }

            $this->finish($event, WebhookStatus::Processed, null);

            return;
        }

        if (! $incomplete && ! $user->hasPassword() && $this->accounts->countForUser((int) $user->getKey()) === 0
            && ! UndeliverableEmail::is($user->email)) {
            HookManager::doAction(self::ACTION_UNLINKED_LAST_METHOD, $user, $event->provider_key);
        }

        $this->finish($event, WebhookStatus::Processed, null);
    }

    /**
     * 다시 시도를 다 쓴 이벤트를 실패로 남기고 관리자에게 알립니다.
     *
     * @param  int  $eventId  이벤트 ID
     * @param  string  $error  오류 요약 (비밀 없음)
     * @return void 반환값 없음
     */
    public function markFailed(int $eventId, string $error): void
    {
        $event = $this->events->find($eventId);
        if ($event === null || $event->status === WebhookStatus::Processed->value) {
            return;
        }

        $this->finish($event, WebhookStatus::Failed, mb_substr($error, 0, 500));
        $this->notifyFailed($event, $error);
    }

    /**
     * 상태를 기록합니다.
     *
     * @param  SocialLoginWebhookEvent  $event  이벤트
     * @param  WebhookStatus  $status  상태
     * @param  string|null  $error  오류 요약
     * @return void 반환값 없음
     */
    private function finish(SocialLoginWebhookEvent $event, WebhookStatus $status, ?string $error): void
    {
        $this->events->update($event, ['status' => $status->value, 'error' => $error, 'processed_at' => now()]);
    }

    /**
     * 처리 실패 훅을 쏩니다 (관리자 알림).
     *
     * @param  SocialLoginWebhookEvent  $event  이벤트
     * @param  string  $error  오류 요약
     * @return void 반환값 없음
     */
    private function notifyFailed(SocialLoginWebhookEvent $event, string $error): void
    {
        HookManager::doAction(self::ACTION_FAILED, [
            'event_id' => (int) $event->getKey(),
            'provider_key' => $event->provider_key,
            'event_type' => $event->event_type,
            'error' => mb_substr($error, 0, 200),
        ]);
    }
}
