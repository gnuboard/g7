<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 취소·환불 처리 예외
 *
 * 취소 불가 상태, 취소 옵션 부재/중복, 취소 수량 오류, 환불 금액 오류,
 * PG 환불 실패 등 주문 취소·환불 라이프사이클에서 발생하는 비즈니스 예외입니다.
 *
 * 다국어 키를 함께 보관할 수 있다 — 클레임 도메인 예외(`OrderClaimException`)를 이 예외로
 * 바꿔 던지는 지점이 있어서, 키를 버리면 회원·비회원 면이 사유를 잃고 뭉뚱그린 안내만 남는다.
 * 키는 선택 사항이며(기본 null) 기존 호출부는 그대로 동작한다.
 */
class OrderCancellationException extends Exception
{
    /**
     * PG 원문을 치환 파라미터로 품는 메시지 키 — 공개 면에 내보내지 않는다
     *
     * @var array<int, string>
     */
    public const PG_DETAIL_MESSAGE_KEYS = [
        'sirsoft-ecommerce::exceptions.pg_refund_failed',
    ];

    /**
     * 생성자
     *
     * @param  string  $message  다국어 처리된 예외 메시지
     * @param  string|null  $messageKey  다국어 키 (없으면 호출부가 자기 기본 키를 쓴다)
     * @param  array<string, mixed>  $messageParams  메시지 치환 파라미터
     */
    public function __construct(
        string $message,
        protected ?string $messageKey = null,
        protected array $messageParams = [],
    ) {
        parent::__construct($message);
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string|null 메시지 키 (보관하지 않았으면 null)
     */
    public function getMessageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * 구매자 화면(회원·비회원)에 그대로 안내해도 되는 메시지 키를 반환합니다.
     *
     * PG 가 돌려준 원문을 치환 파라미터로 품는 키(`pg_refund_failed` 의 `:error`)는 공개 면에 내보내지
     * 않는다 — 가맹점 설정·내부 코드가 섞인 문장이라 고객 화면에 나가면 안 된다. 그 경우 null 을 돌려
     * 호출부가 일반 실패 문구로 떨어지게 한다. 관리자 면은 이 메서드가 아니라 원문을 조치 근거로 싣는다.
     *
     * @return string|null 공개 면에 쓸 수 있는 메시지 키 (없거나 PG 원문을 품으면 null)
     */
    public function publicMessageKey(): ?string
    {
        if ($this->messageKey === null || in_array($this->messageKey, self::PG_DETAIL_MESSAGE_KEYS, true)) {
            return null;
        }

        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->messageParams;
    }
}
