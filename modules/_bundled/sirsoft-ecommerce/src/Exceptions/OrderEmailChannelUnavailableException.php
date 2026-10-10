<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 메일 채널 사용 불가 예외
 *
 * 주문 상세 「고객에게 메일 보내기」가 알림 파이프라인에서 건너뛰어졌을 때(이커머스 메일 채널 꺼짐·
 * 메일 설정 미완료·템플릿 비활성 등) 던진다. 요청은 올바르나 사이트 설정 때문에 보낼 수 없는
 * 상태라 422 로 안내한다 — 발송 중 예외(500)와 구분된다.
 */
class OrderEmailChannelUnavailableException extends Exception
{
    /**
     * 생성자
     *
     * @param  string|null  $reason  건너뛴 사유 (발송 로그에 남는 문장 — 응답에는 싣지 않는다)
     */
    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct(__('sirsoft-ecommerce::messages.orders.email_channel_disabled'));
    }
}
