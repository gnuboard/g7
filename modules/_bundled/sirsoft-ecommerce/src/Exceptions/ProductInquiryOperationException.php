<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 상품 문의 조작 실패 예외
 *
 * 문의 게시판이 설정되지 않았거나, 대상 문의·답변을 찾을 수 없거나,
 * 답변 등록·수정·삭제가 도메인 규칙에 어긋날 때 사용합니다.
 *
 * 컨트롤러는 이 예외를 도메인 실패로 구분해 기존 422 응답으로 처리하고,
 * 그 외 예외는 서버 결함으로 보아 500 을 반환합니다. 종전에는 컨트롤러가
 * 예외 메시지 원문을 응답 메시지 키 자리에 넘겨, 번역되지 않은 문자열이
 * 그대로 노출될 수 있었습니다.
 */
class ProductInquiryOperationException extends RuntimeException
{
    /**
     * ProductInquiryOperationException 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::messages.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    public function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 컨트롤러가 예외 메시지 원문 대신 이 키로 응답을 구성하기 위한 접근자입니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }
}
