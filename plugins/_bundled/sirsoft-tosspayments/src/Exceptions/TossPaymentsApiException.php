<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Exceptions;

use RuntimeException;

/**
 * 토스페이먼츠 PG API 호출 실패 예외
 *
 * 결제 승인/취소, 현금영수증 발급/취소 등 토스페이먼츠 API 호출 단계에서 발생하는
 * 모든 실패 (HTTP 오류, 응답 파싱 실패 등) 를 단일 도메인 예외로 통합한다.
 *
 * 베이스 \Exception 직접 throw 대신 본 클래스를 사용해 외부 소비자가 토스페이먼츠
 * 도메인 오류만 선택적으로 catch 할 수 있도록 한다. RuntimeException 을 상속하므로
 * 기존의 catch (\Exception) 소비자는 그대로 동작한다.
 */
class TossPaymentsApiException extends RuntimeException
{
    /**
     * @param  string  $message  사람이 읽는 실패 사유 (토스 응답의 `message`)
     * @param  int  $code  HTTP 상태 코드
     * @param  string|null  $pgCode  토스가 준 오류 코드 (응답의 `code` — 예: `REJECT_CARD_COMPANY`)
     * @param  array|null  $pgBody  토스 오류 응답 원본 (대사 근거)
     */
    public function __construct(
        string $message,
        int $code = 0,
        private readonly ?string $pgCode = null,
        private readonly ?array $pgBody = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * 토스가 준 오류 코드를 반환합니다.
     *
     * 메시지와 달리 이 값은 **거절 사유를 구분하는 식별자**다 — 소비자(클레임 승인 리스너 등)가
     * 이것을 결제행 `pg_error_code` 와 화면 「실패 코드」로 올린다. 예외가 이 값을 버리면 한도
     * 초과·분실 카드·세션 만료가 전부 같은 실패로 보인다.
     *
     * 응답 본문을 파싱하지 못한 실패(네트워크 단절 등)에는 없으므로 null 이다.
     *
     * @return string|null 토스 오류 코드
     */
    public function getPgCode(): ?string
    {
        return $this->pgCode;
    }

    /**
     * 토스 오류 응답 원본을 반환합니다.
     *
     * @return array|null 오류 응답 본문
     */
    public function getPgBody(): ?array
    {
        return $this->pgBody;
    }
}
