<?php

namespace App\Notifications;

use App\Services\NotificationChannelService;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Notification;

/**
 * 알림 기본 추상 클래스
 *
 * 모든 그누보드7 알림의 공통 기반입니다.
 * via() 메서드는 코어 채널 후보 진입점(NotificationChannelService::resolveChannelCandidates)으로
 * 채널 목록을 결정합니다. 채널 제공 확장은 `core.notification.channels`, 도메인은
 * `{hookPrefix}.notification.channels` 필터 훅으로 후보를 더하거나 좁힙니다.
 *
 * toMail()은 정의하지 않습니다 — 각 서브클래스가 직접 구현합니다 (Laravel 규약).
 */
abstract class BaseNotification extends Notification
{
    /**
     * 훅 접두사를 반환합니다.
     *
     * 예: 'core.auth', 'sirsoft-board', 'sirsoft-ecommerce'
     *
     * @return string 훅 접두사
     */
    abstract protected function getHookPrefix(): string;

    /**
     * 알림 유형을 반환합니다.
     *
     * 예: 'welcome', 'reset_password', 'new_comment'
     *
     * @return string 알림 유형
     */
    abstract protected function getNotificationType(): string;

    /**
     * 알림 채널을 결정합니다.
     *
     * 채널 후보는 코어 단일 진입점(NotificationChannelService::resolveChannelCandidates)이
     * 정합니다 — 채널 제공 확장은 `core.notification.channels` 에, 도메인은
     * `{hookPrefix}.notification.channels` 에 답합니다.
     *
     * @param  object  $notifiable  수신자
     * @return array<string> 채널 목록
     */
    public function via(object $notifiable): array
    {
        return app(NotificationChannelService::class)->resolveChannelCandidates(
            $this->getHookPrefix(),
            ['mail'],
            $this->getNotificationType(),
            $notifiable
        );
    }

    /**
     * 알림 렌더에 사용할 수신자 로케일을 해석합니다.
     *
     * 사용자 언어 SSoT(users.language)를 따르는 HasLocalePreference 수신자는
     * preferredLocale() 값을 우선합니다. contract 미구현 수신자나 빈 선호값은
     * 현재 app locale 로 폴백합니다.
     *
     * @param  object  $notifiable  알림 수신자
     * @return string 해석된 로케일
     */
    public static function resolveNotifiableLocale(object $notifiable): string
    {
        $preferred = $notifiable instanceof HasLocalePreference
            ? $notifiable->preferredLocale()
            : null;

        return $preferred ?? app()->getLocale();
    }
}
