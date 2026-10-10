<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\User;
use App\Notifications\DeliveryReport;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Notifications\Identity\IdentityNotificationType;
use Illuminate\Support\Facades\Log;

/**
 * 본인인증(IDV) 메시지 디스패처 — 알림 시스템 파사드.
 *
 * 7.1.0 부터 본인인증 메시지는 알림 정의(`notification_definitions`, category=identity)로 저장되고
 * 알림 디스패처(`GenericNotification` → `NotificationDispatcher`)로 발송된다. 그래서 「알림 채널 관리」의
 * 메일 토글·채널 준비 상태·게스트 게이트·발송 로그(`notification_logs`)·`core.notification.*` 훅이
 * 본인인증 메일에도 똑같이 적용된다.
 *
 * 해석 순서(가장 구체적인 것 먼저 — 옛 IDV 표의 3단 폴백과 같은 의미):
 *   1. `identity.policy.{정책 키}` (challenge 를 일으킨 정책이 있을 때)
 *   2. `identity.purpose.{목적}`
 *   3. `identity.default`
 * 정의가 없거나 비활성이거나, 그 채널 템플릿이 없거나 비활성이면 다음 후보로 넘어간다.
 *
 * 발송 성공 여부(bool)는 `DeliveryReport` 로 판정한다 — 알림 파이프라인은 채널을 조용히 빼거나
 * 예외를 삼키므로, 보고서 없이는 「메일 채널이 꺼져 보내지 못함」을 알 수 없다. 이 bool 이
 * 로그인 2단계 503·챌린지 요청 502 판정의 근거다.
 *
 * 옛 발송 훅 `core.identity.message.{resolve_failed|before_send|after_send|send_failed}` 은
 * 호환을 위해 같은 시점에 계속 발화한다(@deprecated 7.1.0 — 8.0 제거, `core.notification.*` 로 대체).
 */
class IdentityMessageDispatcher
{
    /**
     * @param  NotificationDefinitionService  $definitionService  알림 정의 조회 (캐시)
     * @param  NotificationTemplateService  $templateService  알림 템플릿 조회 (캐시)
     * @param  UserRepositoryInterface  $userRepository  챌린지 사용자 조회
     */
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
        private readonly NotificationTemplateService $templateService,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 본인인증 메시지를 발송합니다.
     *
     * @param  string  $providerId  IDV 프로바이더 ID (예: g7:core.mail) — 훅 컨텍스트용
     * @param  string  $purpose  IDV 목적 (signup, password_reset 등)
     * @param  string|null  $policyKey  정책 키 (정책 컨텍스트가 없으면 null)
     * @param  string  $renderHint  렌더 힌트 (text_code | link)
     * @param  string  $channel  발송 채널 (현재 'mail')
     * @param  string  $target  수신자 (mail 채널은 이메일 주소)
     * @param  array  $data  변수 치환용 데이터 (code, action_url, expire_minutes 등)
     * @param  array  $context  추가 컨텍스트 (challenge_id, user_id 등 — 훅에 전달)
     * @return bool 실제 발송 여부 (정의 미해석·채널 꺼짐·미준비·예외는 false)
     */
    public function dispatch(
        string $providerId,
        string $purpose,
        ?string $policyKey,
        string $renderHint,
        string $channel,
        string $target,
        array $data,
        array $context = [],
    ): bool {
        $hookContext = array_merge($context, [
            'provider_id' => $providerId,
            'purpose' => $purpose,
            'policy_key' => $policyKey,
            'render_hint' => $renderHint,
            'channel' => $channel,
            'target' => $target,
            'data' => $data,
        ]);

        $definition = $this->resolveDefinition($purpose, $policyKey, $channel);

        if ($definition === null) {
            // @deprecated 7.1.0 — 8.0 제거. 대체: 알림 정의 조회 실패는 발송 로그가 남지 않으므로 이 훅만 남는다.
            HookManager::doAction('core.identity.message.resolve_failed', $hookContext);
            Log::warning('[IDV] 본인인증 알림 정의/템플릿 미해석으로 발송 건너뜀', [
                'provider_id' => $providerId,
                'purpose' => $purpose,
                'policy_key' => $policyKey,
                'render_hint' => $renderHint,
                'channel' => $channel,
                'candidates' => IdentityNotificationType::candidates($purpose, $policyKey),
            ]);

            return false;
        }

        $hookContext['definition_id'] = $definition->id;
        $hookContext['notification_type'] = $definition->type;

        $report = new DeliveryReport;
        $notification = (new GenericNotification(
            type: $definition->type,
            hookPrefix: (string) $definition->hook_prefix,
            data: $data,
            extensionType: (string) $definition->extension_type,
            extensionIdentifier: (string) $definition->extension_identifier,
            channel: $channel,
        ))->withDeliveryReport($report);

        // @deprecated 7.1.0 — 8.0 제거. 대체: core.notification.before_channel_send
        HookManager::doAction('core.identity.message.before_send', $hookContext);

        try {
            $this->resolveRecipient($target, $context)->notifyNow($notification);
        } catch (\Throwable $e) {
            $report->markFailed($channel, $e->getMessage());
        }

        if ($report->wasSent($channel)) {
            // @deprecated 7.1.0 — 8.0 제거. 대체: core.notification.after_channel_send
            HookManager::doAction('core.identity.message.after_send', $hookContext);

            return true;
        }

        $reason = $report->reasonFor($channel)
            ?? __('notification.channel_skipped_no_template', ['channel' => $channel, 'type' => $definition->type]);

        // @deprecated 7.1.0 — 8.0 제거. 대체: core.notification.channel_send_failed (건너뜀은 발송 로그 skipped)
        HookManager::doAction('core.identity.message.send_failed', array_merge($hookContext, [
            'error_message' => $reason,
            'delivery_status' => $report->statusFor($channel),
        ]));

        Log::warning('[IDV] 본인인증 메시지 발송 실패', [
            'provider_id' => $providerId,
            'purpose' => $purpose,
            'policy_key' => $policyKey,
            'notification_type' => $definition->type,
            'channel' => $channel,
            'status' => $report->statusFor($channel),
            'reason' => $reason,
        ]);

        return false;
    }

    /**
     * type 후보를 가장 구체적인 것부터 시도해 발송할 정의를 고릅니다.
     *
     * 정의가 활성·본인인증 분류이고, 그 채널의 활성 템플릿이 있어야 채택한다.
     *
     * @param  string  $purpose  인증 목적
     * @param  string|null  $policyKey  정책 키
     * @param  string  $channel  발송 채널
     * @return NotificationDefinition|null 채택된 정의 (없으면 null)
     */
    private function resolveDefinition(string $purpose, ?string $policyKey, string $channel): ?NotificationDefinition
    {
        foreach (IdentityNotificationType::candidates($purpose, $policyKey) as $type) {
            $definition = $this->definitionService->resolve($type);

            if (! $definition instanceof NotificationDefinition
                || $definition->category !== IdentityNotificationType::CATEGORY) {
                continue;
            }

            if ($this->templateService->resolve($type, $channel) === null) {
                continue;
            }

            return $definition;
        }

        return null;
    }

    /**
     * 수신자를 결정합니다.
     *
     * 챌린지 사용자가 있고 그 이메일이 수신 주소와 같으면 회원(User)으로 보낸다 — 회원 언어로 렌더되고
     * 발송 로그에 회원이 연결된다. 그 밖(비회원 가입·비밀번호 찾기·새 이메일로 바꾸는 정보 변경)은
     * 수신 주소만 가진 게스트로 보낸다. `Notification::route()` 의 익명 수신자는 메일 템플릿이 읽는
     * `$notifiable->email` 이 없어 빈 수신자가 되므로 쓰지 않는다.
     *
     * @param  string  $target  수신 이메일
     * @param  array  $context  발송 컨텍스트 (user_id 포함 가능)
     * @return User|GuestNotifiable 수신자
     */
    private function resolveRecipient(string $target, array $context): User|GuestNotifiable
    {
        $userId = $context['user_id'] ?? null;

        if (is_numeric($userId)) {
            $user = $this->userRepository->findById((int) $userId);

            if ($user instanceof User && mb_strtolower((string) $user->email) === mb_strtolower($target)) {
                return $user;
            }
        }

        return new GuestNotifiable($target, null, app()->getLocale());
    }
}
