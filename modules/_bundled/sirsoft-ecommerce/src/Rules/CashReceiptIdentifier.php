<?php

namespace Modules\Sirsoft\Ecommerce\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;

/**
 * 현금영수증 식별번호 검증 Custom Rule
 *
 * 용도(소득공제/지출증빙)와 식별번호 종류(휴대폰/현금영수증카드/사업자등록번호)의 조합을 먼저 검증한 뒤,
 * 해당 종류의 형식(정규식 또는 사업자등록번호 체크섬)을 검증한다.
 *
 * 형식 규칙과 조합 허용표는 Enum 이 SSoT 다 — CashReceiptType::allowedIdentifierTypes() 와
 * CashReceiptIdentifierType::matches() 를 그대로 위임 호출하며 본 Rule 은 어떤 규칙도 복제하지 않는다.
 *
 * 자진발급 지정번호(0100001234)는 소득공제용 전용이므로 지출증빙 용도에서는 거부한다.
 */
class CashReceiptIdentifier implements ValidationRule
{
    /**
     * @param  CashReceiptType|null  $type  발급 용도 (미해석 시 조합 검증 생략)
     * @param  CashReceiptIdentifierType|null  $identifierType  식별번호 종류 (미해석 시 형식 검증 생략)
     */
    public function __construct(
        private ?CashReceiptType $type,
        private ?CashReceiptIdentifierType $identifierType,
    ) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값 (식별번호)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('sirsoft-ecommerce::cash_receipt.validation.identifier_invalid'));

            return;
        }

        // 용도·종류가 각각의 in: 규칙에서 이미 걸러졌다면 여기서 중복 오류를 내지 않는다.
        if ($this->type === null || $this->identifierType === null) {
            return;
        }

        $normalized = self::normalize($value);

        // 자진발급 지정번호는 소득공제용 전용 (지출증빙 자진발급은 제도상 불가).
        if (CashReceiptIdentifierType::isSelfIssueNumber($normalized)) {
            if ($this->type !== CashReceiptType::INCOME) {
                $fail(__('sirsoft-ecommerce::cash_receipt.validation.self_issue_income_only'));
            }

            return;
        }

        // 용도 × 식별번호 종류 조합 (지출증빙 + 휴대폰번호는 허용된다).
        if (! in_array($this->identifierType, $this->type->allowedIdentifierTypes(), true)) {
            $fail(__('sirsoft-ecommerce::cash_receipt.validation.identifier_type_not_allowed', [
                'type' => $this->type->label(),
                'identifier_type' => $this->identifierType->label(),
            ]));

            return;
        }

        if (! $this->identifierType->matches($normalized)) {
            $fail(__('sirsoft-ecommerce::cash_receipt.validation.identifier_format.'.$this->identifierType->value));
        }
    }

    /**
     * 하이픈·공백을 제거해 식별번호를 정규화합니다.
     *
     * @param  string  $identifier  원본 식별번호
     * @return string 정규화된 식별번호
     */
    public static function normalize(string $identifier): string
    {
        return preg_replace('/[\s-]/', '', $identifier) ?? '';
    }
}
