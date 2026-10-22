<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 페이스북 데이터 삭제 요청의 진행 상태 조회 (회원이 확인 코드로 여는 공개 페이지).
 *
 * @since 1.0.0
 */
class WebhookStatusService
{
    /**
     * @param  WebhookEventRepositoryInterface  $events  웹훅 저장소
     */
    public function __construct(private readonly WebhookEventRepositoryInterface $events) {}

    /**
     * 확인 코드로 데이터 삭제 요청을 찾습니다.
     *
     * @param  string  $code  확인 코드
     * @return SocialLoginWebhookEvent|null 요청 (다른 종류의 이벤트면 null)
     */
    public function deletionRequest(string $code): ?SocialLoginWebhookEvent
    {
        $event = $this->events->findByConfirmationCode($code);

        return $event !== null && $event->event_type === WebhookEventType::DataDeletion->value ? $event : null;
    }
}
