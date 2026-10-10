<?php

namespace App\Notifications;

use App\Contracts\Notifications\GuestRecipientInterface;
use App\Extension\HookManager;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Notifications\NotificationSender;
use Illuminate\Support\Facades\Log;

/**
 * 알림 발송 공통 디스패처
 *
 * 모든 채널의 모든 알림이 이 sendToNotifiable()을 통과합니다.
 *
 * 책임:
 * 1. 채널별 독립 발송 보장 (한 채널 실패가 다른 채널에 영향 없음)
 * 2. 발송 전후 G7 훅 실행 (core.notification.before_channel_send / after_channel_send / channel_send_failed)
 * 3. 플러그인/모듈이 훅으로 발송 로깅, 커스텀 처리 가능
 */
class NotificationDispatcher extends NotificationSender
{
    /**
     * 개별 채널로 알림을 발송합니다.
     *
     * @param  mixed  $notifiable
     * @param  string  $id
     * @param  mixed  $notification
     * @param  string  $channel
     * @return mixed
     */
    protected function sendToNotifiable($notifiable, $id, $notification, $channel)
    {
        $context = $this->buildContext($notifiable, $notification, $channel);

        // Before 훅: 발송 전 처리 (로깅 준비, 필터링 등)
        HookManager::doAction('core.notification.before_channel_send', $channel, $context);

        try {
            $result = parent::sendToNotifiable($notifiable, $id, $notification, $channel);

            // 결과 보고서(붙어 있을 때만) — 건너뜀이 먼저 기록된 채널은 markSent 가 덮지 않는다.
            if ($notification instanceof GenericNotification) {
                $notification->getDeliveryReport()?->markSent($channel);
            }

            // After 훅: 발송 성공 (로깅, 통계 등)
            HookManager::doAction('core.notification.after_channel_send', $channel, $context);

            return $result;
        } catch (\Exception $e) {
            if ($notification instanceof GenericNotification) {
                $notification->getDeliveryReport()?->markFailed($channel, $e->getMessage());
            }

            // Failed 훅: 발송 실패 (에러 로깅 등)
            $context['error'] = $e->getMessage();
            HookManager::doAction('core.notification.channel_send_failed', $channel, $context);

            Log::warning("알림 채널 '{$channel}' 발송 실패", [
                'channel' => $channel,
                'notification' => get_class($notification),
                'notifiable_id' => $notifiable->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 훅에 전달할 공통 컨텍스트를 구성합니다.
     *
     * @param  mixed  $notifiable  알림 수신자
     * @param  mixed  $notification  발송 중인 알림
     * @param  string  $channel  채널 식별자
     * @return array<string, mixed> 훅 컨텍스트 (GenericNotification 이면 type·분류·렌더 결과 포함)
     */
    private function buildContext($notifiable, $notification, string $channel): array
    {
        // 비회원(게스트)은 users FK 가 없으므로 recipient_user_id 는 null, 식별은 email 로 한다.
        // (게스트의 getKey() 는 "guest:{hash}" 문자열이라 정수 FK 컬럼에 넣으면 로그 기록이 실패한다)
        $isGuest = $notifiable instanceof GuestRecipientInterface && $notifiable->isGuest();

        $context = [
            'notifiable' => $notifiable,
            'notifiable_id' => $notifiable->getKey(),
            'notifiable_type' => get_class($notifiable),
            'notifiable_uuid' => $notifiable->uuid ?? null,
            'notification_class' => get_class($notification),
            'recipient_identifier' => $notifiable->email ?? (string) ($notifiable->getKey() ?? ''),
            'recipient_name' => $notifiable->name ?? null,
            'recipient_user_id' => $isGuest ? null : ($notifiable->getKey() ?? null),
        ];

        // GenericNotification인 경우 추가 메타데이터
        if ($notification instanceof GenericNotification) {
            $context['notification_type'] = $notification->getType();
            $context['extension_type'] = $notification->getExtensionType();
            $context['extension_identifier'] = $notification->getExtensionIdentifier();
            $context['category'] = $this->resolveCategory($notification);
            $context['data'] = $notification->getData();

            // 렌더링된 subject/body를 context에 포함 (로깅용)
            $rendered = $this->resolveRenderedContent($notification, $notifiable, $channel);
            if ($rendered) {
                $context['subject'] = $rendered['subject'] ?? null;
                $context['body'] = $rendered['body'] ?? null;
            }
        }

        return $context;
    }

    /**
     * 알림 정의의 분류(category)를 조회합니다.
     *
     * 발송 로그 리스너가 본인인증 알림(category=identity)의 인증번호·검증 링크를 가리는 데 쓴다.
     * 정의 조회는 NotificationDefinitionService 캐시를 탄다.
     *
     * @param  GenericNotification  $notification  알림
     * @return string|null 분류 (일반 알림이거나 정의가 없으면 null)
     */
    private function resolveCategory(GenericNotification $notification): ?string
    {
        try {
            return app(NotificationDefinitionService::class)->resolve($notification->getType())?->category;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 채널별 렌더링된 제목/본문을 조회합니다.
     *
     * @param  GenericNotification  $notification  발송 중인 알림
     * @param  mixed  $notifiable  알림 수신자 (로케일 해석용)
     * @param  string  $channel  채널 식별자
     * @return array{subject: string|null, body: string|null}|null 렌더 결과 (템플릿이 없거나 비활성이면 null)
     */
    private function resolveRenderedContent(GenericNotification $notification, $notifiable, string $channel): ?array
    {
        try {
            $templateService = app(NotificationTemplateService::class);
            $template = $templateService->resolve($notification->getType(), $channel);

            if (! $template || ! $template->is_active) {
                return null;
            }

            $locale = BaseNotification::resolveNotifiableLocale($notifiable);

            return $template->replaceVariables($notification->getData(), $locale);
        } catch (\Throwable $e) {
            Log::debug('NotificationDispatcher: 렌더링된 콘텐츠 조회 실패', [
                'type' => $notification->getType(),
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
