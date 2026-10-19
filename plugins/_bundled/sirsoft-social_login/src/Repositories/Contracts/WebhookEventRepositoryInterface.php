<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 제공자 웹훅 수신 기록 저장소.
 *
 * @since 1.0.0
 */
interface WebhookEventRepositoryInterface
{
    /**
     * 처음 받은 알림이면 기록하고, 같은 event_key 가 이미 있으면 null 을 반환합니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (event_key 필수)
     * @return SocialLoginWebhookEvent|null 새 행, 중복이면 null
     */
    public function recordIfNew(array $attributes): ?SocialLoginWebhookEvent;
}
