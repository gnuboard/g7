<?php

namespace App\Notifications;

use App\Extension\HookManager;
use Illuminate\Notifications\Notification;

/**
 * 알림 기본 추상 클래스
 *
 * 모든 그누보드7 알림의 공통 기반입니다.
 * via() 메서드에서 HookManager를 통해 채널 목록을 동적으로 결정합니다.
 * 플러그인은 Filter 훅으로 채널을 추가/제거할 수 있습니다.
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
     * Filter 훅으로 플러그인이 채널을 추가/제거할 수 있습니다.
     * 훅명: {hookPrefix}.notification.channels
     *
     * @param  object  $notifiable  수신자
     * @return array<string> 채널 목록
     */
    public function via(object $notifiable): array
    {
        return HookManager::applyFilters(
            "{$this->getHookPrefix()}.notification.channels",
            ['mail'],
            $this->getNotificationType(),
            $notifiable
        );
    }
}
