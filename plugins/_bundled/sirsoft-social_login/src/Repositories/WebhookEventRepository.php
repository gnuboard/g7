<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use Illuminate\Database\UniqueConstraintViolationException;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 제공자 웹훅 수신 기록 저장소 구현.
 *
 * @since 1.0.0
 */
class WebhookEventRepository implements WebhookEventRepositoryInterface
{
    /**
     * 처음 받은 알림이면 기록하고, 같은 event_key 가 이미 있으면 null 을 반환합니다.
     *
     * 먼저 조회하고 넣는 방식은 동시에 온 두 알림이 둘 다 통과한다. UNIQUE 제약에 맡기고 위반을 중복으로 읽는다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (event_key 필수)
     * @return SocialLoginWebhookEvent|null 새 행, 중복이면 null
     */
    public function recordIfNew(array $attributes): ?SocialLoginWebhookEvent
    {
        try {
            return SocialLoginWebhookEvent::query()->create($attributes + ['received_at' => now(), 'attempts' => 0]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
