<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\NotificationLogService;
use Illuminate\Support\Facades\Log;

/**
 * 알림 발송 이력 자동 기록 리스너
 *
 * NotificationDispatcher가 실행하는 G7 훅을 구독하여
 * notification_logs 테이블에 모든 채널의 발송 이력을 자동 기록합니다.
 *
 * 훅:
 * - core.notification.after_channel_send — 발송 성공
 * - core.notification.channel_send_failed — 발송 실패
 *
 * 플러그인/모듈이 별도 조치 없이도 모든 채널 발송이 자동 기록됩니다.
 * 커스텀 로깅이 필요하면 동일 훅을 별도 리스너에서 구독하면 됩니다.
 */
class NotificationLogListener implements HookListenerInterface
{
    /**
     * @param  NotificationLogService  $logService
     */
    public function __construct(
        private readonly NotificationLogService $logService,
    ) {}

    /**
     * 구독할 훅 목록.
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 공통 채널 발송 훅 (NotificationDispatcher에서 실행)
            'core.notification.after_channel_send' => ['method' => 'handleChannelSent', 'priority' => 15],
            'core.notification.channel_send_failed' => ['method' => 'handleChannelFailed', 'priority' => 15],

            // 메일 전용 훅 (DbTemplateMail에서 실행) — skipped만 유지
            'core.mail.send_skipped' => ['method' => 'handleMailSkipped', 'priority' => 15],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 채널 발송 성공 시 로그 기록.
     *
     * @param  string  $channel  채널 ID (mail, database, fcm 등)
     * @param  array  $context  NotificationDispatcher에서 전달하는 컨텍스트
     * @return void
     */
    public function handleChannelSent(string $channel, array $context): void
    {
        try {
            $this->logService->logSent([
                'channel' => $channel,
                'notification_type' => $context['notification_type'] ?? '',
                'extension_type' => (string) ($context['extension_type'] ?? 'core'),
                'extension_identifier' => $context['extension_identifier'] ?? 'core',
                'recipient_identifier' => $context['recipient_identifier'] ?? '',
                'recipient_name' => $context['recipient_name'] ?? null,
                'recipient_user_id' => $context['recipient_user_id'] ?? null,
                'subject' => $this->maskSensitive($context['subject'] ?? null, $context),
                'body' => $this->maskSensitive($context['body'] ?? null, $context),
                'source' => 'notification',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationLogListener: 발송 성공 로그 기록 실패', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 채널 발송 실패 시 로그 기록.
     *
     * @param  string  $channel  채널 ID
     * @param  array  $context  NotificationDispatcher에서 전달하는 컨텍스트
     * @return void
     */
    public function handleChannelFailed(string $channel, array $context): void
    {
        try {
            $this->logService->logFailed([
                'channel' => $channel,
                'notification_type' => $context['notification_type'] ?? '',
                'extension_type' => (string) ($context['extension_type'] ?? 'core'),
                'extension_identifier' => $context['extension_identifier'] ?? 'core',
                'recipient_identifier' => $context['recipient_identifier'] ?? '',
                'recipient_name' => $context['recipient_name'] ?? null,
                'recipient_user_id' => $context['recipient_user_id'] ?? null,
                'subject' => $this->maskSensitive($context['subject'] ?? null, $context),
                'body' => $this->maskSensitive($context['body'] ?? null, $context),
                'error_message' => $context['error'] ?? null,
                'source' => 'notification',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationLogListener: 발송 실패 로그 기록 실패', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 메일 발송 건너뜀 시 로그 기록.
     *
     * DbTemplateMail에서 템플릿 비활성 시 실행됩니다.
     *
     * @param  array  $data  DbTemplateMail::send()에서 전달하는 데이터
     * @return void
     */
    public function handleMailSkipped(array $data): void
    {
        try {
            $this->logService->logSkipped([
                'channel' => 'mail',
                'notification_type' => $data['templateType'] ?? '',
                'extension_type' => $this->resolveExtensionType($data['extensionType'] ?? 'core'),
                'extension_identifier' => $data['extensionIdentifier'] ?? 'core',
                'recipient_identifier' => $data['recipientEmail'] ?? '',
                'recipient_name' => $data['recipientName'] ?? null,
                'source' => $data['source'] ?? 'notification',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationLogListener: 발송 스킵 로그 기록 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 본인인증 알림의 인증번호·검증 링크를 발송 로그에서 가립니다.
     *
     * 본인인증 알림(category=identity)은 인증번호와 검증 링크(토큰 포함) 자체가 인증 수단이다 —
     * 관리자 발송 로그 화면에 평문으로 남으면 로그를 볼 수 있는 사람이 남의 인증을 대신 완료할 수 있다.
     * 렌더된 제목·본문에서 민감 변수의 실제 값(빈 값 제외)을 `******` 로 바꿔 기록한다.
     * 훅 컨텍스트 원본은 건드리지 않는다 — 같은 훅을 구독해 직접 기록하는 확장은 스스로 가려야 한다.
     *
     * @param  string|null  $text  렌더된 제목 또는 본문
     * @param  array  $context  NotificationDispatcher 컨텍스트 (category, data 포함)
     * @return string|null 가린 문자열 (본인인증 알림이 아니면 원문 그대로)
     */
    private function maskSensitive(?string $text, array $context): ?string
    {
        if ($text === null || $text === '' || ($context['category'] ?? null) !== IdentityNotificationType::CATEGORY) {
            return $text;
        }

        $data = is_array($context['data'] ?? null) ? $context['data'] : [];
        $secrets = [];

        foreach (IdentityNotificationType::sensitiveVariables() as $key) {
            $value = $data[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $secrets[] = (string) $value;
                // 본문은 HTML 이라 링크가 엔티티로 이스케이프된 형태(&amp;)로도 들어간다.
                $secrets[] = htmlspecialchars((string) $value, ENT_QUOTES);
            }
        }

        if ($secrets === []) {
            return $text;
        }

        // 긴 값부터 바꿔 짧은 값(인증번호)이 링크 일부를 먼저 망가뜨리지 않게 한다.
        $secrets = array_values(array_unique($secrets));
        usort($secrets, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($secrets, '******', $text);
    }

    /**
     * 확장 타입을 문자열로 변환합니다.
     *
     * @param  mixed  $type
     * @return string
     */
    private function resolveExtensionType($type): string
    {
        if ($type instanceof \BackedEnum) {
            return $type->value;
        }

        return (string) ($type ?? 'core');
    }
}
