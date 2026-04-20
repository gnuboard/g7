<?php

namespace App\Notifications;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\ChannelManager;

/**
 * G7 알림 ChannelManager
 *
 * NotificationDispatcher를 사용하여 모든 채널 발송에
 * G7 훅 파이프라인(before/after/failed)과 채널 독립 발송을 적용합니다.
 */
class NotificationChannelManager extends ChannelManager
{
    /**
     * 알림을 발송합니다 (큐 지원).
     *
     * @param mixed $notifiables
     * @param mixed $notification
     * @return void
     */
    public function send($notifiables, $notification)
    {
        return (new NotificationDispatcher(
            $this,
            $this->container->make(Bus::class),
            $this->container->make(Dispatcher::class),
            $this->locale
        ))->send($notifiables, $notification);
    }

    /**
     * 알림을 즉시 발송합니다 (큐 미사용).
     *
     * @param mixed $notifiables
     * @param mixed $notification
     * @param array|null $channels
     * @return void
     */
    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        return (new NotificationDispatcher(
            $this,
            $this->container->make(Bus::class),
            $this->container->make(Dispatcher::class),
            $this->locale
        ))->sendNow($notifiables, $notification, $channels);
    }
}
