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
        parent::__construct(trans_count($messageKey, $replace));
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
     * 이미 사용 중이라 삭제할 수 없을 때
     *
     * 사용량은 그 사유의 유형에 맞는 표 전수에서 센다(`ClaimReasonService::countUsage()`) — 교환·반품은
     * 각 신청서, 환불은 환불신청서 + 즉시취소 합산이다. 취소 이력만 세던 시절의 서술을 남기면 운영자가
     * 안내받은 건수를 취소 이력에서 찾지 못한다.
     *
     * @param  int  $usageCount  해당 사유를 사용 중인 건수
     * @return self 예외 인스턴스
     */
    public static function inUse(int $usageCount): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_in_use', [
            'count' => $usageCount,
        ]);
    }

    /**
     * 이미 사용 중이라 코드를 바꿀 수 없을 때
     *
     * 접수된 신청서는 사유를 `reason_code` 문자열로만 기억한다(`ecommerce_order_{exchanges,returns}`
     * 에 `reason_id` FK 가 없다). 그래서 코드를 바꾸면 그 사유로 접수된 전건이 카탈로그와의 짝을
     * 잃고, 라벨 해석이 원시 코드로 폴백해 내부 문자열이 화면에 드러난다 — 삭제를 막아 방지하려던
     * 피해와 결과가 같으므로 같은 강도로 막는다. 이름·귀책 등 나머지 항목은 계속 수정할 수 있다.
     *
     * @param  int  $usageCount  해당 사유를 사용 중인 건수
     * @return self 예외 인스턴스
     */
    public static function codeLockedInUse(int $usageCount): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_code_locked_in_use', [
            'count' => $usageCount,
        ]);
    }

    /**
     * 이미 사용 중이라 유형을 바꿀 수 없을 때
     *
     * 라벨 해석은 코드 단독이 아니라 `(code, type)` **쌍**이다(`ClaimReasonRepository::findByCode`).
     * 그래서 코드를 그대로 두고 유형만 옮겨도 그 사유로 접수된 전건이 카탈로그와의 짝을 잃어
     * 원시 코드가 화면에 드러난다 — `codeLockedInUse` 가 막는 피해와 결과가 같다. 게다가
     * `countUsage()` 는 유형으로 표를 고르므로, 유형이 바뀐 뒤에는 사용량을 엉뚱한 표에서 세어
     * 삭제 가드까지 통과한다(연쇄 우회). 그래서 같은 강도로 막는다.
     *
     * @param  int  $usageCount  해당 사유를 사용 중인 건수
     * @return self 예외 인스턴스
     */
    public static function typeLockedInUse(int $usageCount): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_type_locked_in_use', [
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
