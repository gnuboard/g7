<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 알림 발송 후 실시간 브로드캐스트 리스너
 *
 * core.notification.after_channel_send 훅(channel='database')을 수신하여
 * database 채널 발송 직후 WebSocket으로 브로드캐스트합니다.
 *
 * NotificationDispatcher가 모든 발송 경로(직접 notify(), 훅 기반 dispatch 등)에서
 * 동일하게 발화시키므로 발송 경로와 무관하게 작동합니다.
 */
class BroadcastNotificationListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array<string, mixed>> 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        // sync: 채널 판정만 그 자리에서 한다 — 큐로 가면 database 가 아닌 발송(본인인증 메일·문자 등)까지
        // 마스킹 전 본문을 담은 발송 문맥이 큐 인자로 직렬화돼 jobs 테이블에 남는다(#724). 실제 전송은
        // GenericBroadcastEvent(ShouldBroadcast)가 큐에서 하므로 발송 요청을 늦추지 않는다.
        return [
            'core.notification.after_channel_send' => [
                'method' => 'broadcastNotification',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * database 채널 발송 직후 실시간 브로드캐스트를 실행합니다.
     *
     * @param  string  $channel  발송 채널명
     * @param  array  $context  NotificationDispatcher::buildContext() 결과
     * @return void 반환값 없음
     */
    public function broadcastNotification(string $channel, array $context): void
    {
        if ($channel !== 'database') {
            return;
        }

        // UUID 기반 채널 사용 (User ID 노출 방지)
        $uuid = $context['notifiable_uuid'] ?? null;
        if (! $uuid) {
            return;
        }

        try {
            HookManager::broadcast(
                "core.user.notifications.{$uuid}",
                'notification.received',
                [
                    'subject' => $context['subject'] ?? null,
                    'body' => $context['body'] ?? null,
                    'type' => $context['notification_type'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('알림 브로드캐스트 실패', [
                'user_uuid' => $uuid,
                'type' => $context['notification_type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
