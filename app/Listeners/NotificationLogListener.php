<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
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
     * @param NotificationLogService $logService
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
     * @param mixed ...$args
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 채널 발송 성공 시 로그 기록.
     *
     * @param string $channel 채널 ID (mail, database, fcm 등)
     * @param array $context NotificationDispatcher에서 전달하는 컨텍스트
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
                'subject' => $context['subject'] ?? null,
                'body' => $context['body'] ?? null,
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
     * @param string $channel 채널 ID
     * @param array $context NotificationDispatcher에서 전달하는 컨텍스트
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
                'subject' => $context['subject'] ?? null,
                'body' => $context['body'] ?? null,
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
     * @param array $data DbTemplateMail::send()에서 전달하는 데이터
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
     * 확장 타입을 문자열로 변환합니다.
     *
     * @param mixed $type
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
