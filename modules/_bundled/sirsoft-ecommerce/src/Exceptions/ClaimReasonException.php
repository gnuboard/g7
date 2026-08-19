<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 클레임 사유 처리 예외
 *
 * 클레임 사유의 조회·수정·삭제·일괄 동기화 중 발생하는 도메인 실패를 표현합니다.
 * 컨트롤러는 i18n 문자열 매칭 대신 이 타입으로 분기합니다.
 */
class ClaimReasonException extends Exception
{
    /**
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::exceptions.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    private function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 대상 클레임 사유를 찾을 수 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function notFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_not_found');
    }

    /**
     * 주문 취소 이력에서 사용 중이라 삭제할 수 없을 때
     *
     * @param  int  $usageCount  해당 사유를 사용한 취소 이력 건수
     * @return self 예외 인스턴스
     */
    public static function inUse(int $usageCount): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_in_use', [
            'count' => $usageCount,
        ]);
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
