<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioNotificationBinding;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioNotificationBindingRepositoryInterface;

/**
 * 비즈뿌리오 이벤트↔알림톡 템플릿 연결 Repository 구현체.
 */
class BizppurioNotificationBindingRepository implements BizppurioNotificationBindingRepositoryInterface
{
    /**
     * 알림 유형+채널로 활성 연동을 조회합니다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널
     * @return BizppurioNotificationBinding|null 매칭 연동 또는 null
     */
    public function findActive(string $notificationType, string $channel = 'alimtalk'): ?BizppurioNotificationBinding
    {
        return BizppurioNotificationBinding::query()
            ->active()
            ->byNotificationType($notificationType)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * 채널의 모든 연동을 조회합니다.
     *
     * @param  string  $channel  채널
     * @return Collection<int, BizppurioNotificationBinding>
     */
    public function allByChannel(string $channel = 'alimtalk'): Collection
    {
        return BizppurioNotificationBinding::query()
            ->where('channel', $channel)
            ->get();
    }

    /**
     * 알림 유형+채널 연동을 생성하거나 갱신합니다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return BizppurioNotificationBinding 저장된 연동
     */
    public function upsert(string $notificationType, string $channel, array $data): BizppurioNotificationBinding
    {
        $binding = BizppurioNotificationBinding::firstOrNew([
            'notification_type' => $notificationType,
            'channel' => $channel,
        ]);

        $binding->fill($data)->save();

        return $binding;
    }

    /**
     * 알림 유형+채널 연동을 삭제합니다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널
     * @return void
     */
    public function delete(string $notificationType, string $channel = 'alimtalk'): void
    {
        BizppurioNotificationBinding::query()
            ->byNotificationType($notificationType)
            ->where('channel', $channel)
            ->delete();
    }
}
