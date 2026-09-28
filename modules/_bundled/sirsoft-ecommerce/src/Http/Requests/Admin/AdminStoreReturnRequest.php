<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesAdminClaimRequest;

/**
 * 관리자 반품 직권 접수 요청 (§3-11 · D5~D8)
 *
 * 고객 반품 신청과 같은 형태에 운영자 축(판매자 귀책 사유 · 배송비 직접 입력 · 「무료」 · 「접수와 함께
 * 승인」)을 더한다. 신청 기간·신청 가능 상태 설정은 따르지 않는다 — 물리 게이트는 서비스가 판정한다.
 */
class AdminStoreReturnRequest extends FormRequest
{
    use ValidatesAdminClaimRequest;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return $this->adminClaimRules(ClaimTypeEnum::RETURN);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return $this->adminClaimMessages();
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return $this->adminClaimAttributes();
    }

    /**
     * 추가 검증 로직 — 접수 상품의 주문 소속 · 환불 계좌 완결성
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateAdminClaimItems($validator));
    }

    /**
     * 서비스에 넘길 반품 직권 접수 입력을 반환합니다.
     *
     * @return array<string, mixed> 접수 입력
     */
    public function toServiceInput(): array
    {
        return $this->toAdminInput();
    }
}
