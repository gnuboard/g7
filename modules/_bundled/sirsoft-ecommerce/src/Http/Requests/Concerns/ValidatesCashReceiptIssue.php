<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Rules\CashReceiptIdentifier;

/**
 * 현금영수증 발급 요청의 공통 검증 트레이트
 *
 * 관리자 발급과 유저 사후 발급은 검증 규칙이 동일하다(권한만 다르며 권한은 미들웨어/컨트롤러가 담당).
 * 발급 가능 조건(dbank·결제완료·미발급·발급액>0) 가드는 주문을 알아야 하므로 컨트롤러가 아닌
 * FormRequest 에서 처리할 수 없다 — 각 컨트롤러가 CashReceiptService 의 결과로 판정한다.
 */
trait ValidatesCashReceiptIssue
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한은 미들웨어/컨트롤러에서 처리)
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, array<int, mixed>> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'receipt_type' => ['required', 'string', 'in:'.implode(',', CashReceiptType::values())],
            'identifier_type' => ['required', 'string', 'in:'.implode(',', CashReceiptIdentifierType::values())],
            'identifier' => [
                'required',
                'string',
                'max:30',
                new CashReceiptIdentifier($this->resolveReceiptType(), $this->resolveIdentifierType()),
            ],
        ];
    }

    /**
     * 검증 필드의 사용자 표시명
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        return [
            'receipt_type' => __('sirsoft-ecommerce::cash_receipt.attributes.receipt_type'),
            'identifier_type' => __('sirsoft-ecommerce::cash_receipt.attributes.identifier_type'),
            'identifier' => __('sirsoft-ecommerce::cash_receipt.attributes.identifier'),
        ];
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     *
     * @throws ValidationException 검증 실패 시
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * 발급 용도를 반환합니다.
     *
     * @return CashReceiptType 발급 용도
     */
    public function getReceiptType(): CashReceiptType
    {
        return CashReceiptType::from($this->validated('receipt_type'));
    }

    /**
     * 식별번호 종류를 반환합니다.
     *
     * @return CashReceiptIdentifierType 식별번호 종류
     */
    public function getIdentifierType(): CashReceiptIdentifierType
    {
        return CashReceiptIdentifierType::from($this->validated('identifier_type'));
    }

    /**
     * 하이픈·공백이 제거된 식별번호 원본을 반환합니다.
     *
     * @return string 정규화된 식별번호
     */
    public function getIdentifier(): string
    {
        return CashReceiptIdentifier::normalize((string) $this->validated('identifier'));
    }

    /**
     * 검증 전 입력에서 발급 용도를 해석합니다. (Rule 생성자 주입용)
     *
     * @return CashReceiptType|null 해석된 용도 (미해석 시 null)
     */
    private function resolveReceiptType(): ?CashReceiptType
    {
        $value = $this->input('receipt_type');

        return is_string($value) ? CashReceiptType::tryFrom($value) : null;
    }

    /**
     * 검증 전 입력에서 식별번호 종류를 해석합니다. (Rule 생성자 주입용)
     *
     * @return CashReceiptIdentifierType|null 해석된 종류 (미해석 시 null)
     */
    private function resolveIdentifierType(): ?CashReceiptIdentifierType
    {
        $value = $this->input('identifier_type');

        return is_string($value) ? CashReceiptIdentifierType::tryFrom($value) : null;
    }
}
