<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Rules\ClaimReasonCodeFormat;

/**
 * 클레임 사유 수정 요청
 */
class UpdateClaimReasonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'type' => ['required', 'string', Rule::in(ClaimReasonTypeEnum::values())],
            'code' => [
                'required',
                'string',
                'max:50',
                // 형식은 ClaimReasonCodeFormat 단일 출처. **대상 사유에 이미 저장된 코드**는
                // 면제한다 — 형식이 좁혀지기 전에 만들어진 코드를 그대로 둔 채 이름·귀책만
                // 고치는 저장이 막히면, 사용 중인 사유는 코드 변경도 잠겨 있어(codeLockedInUse)
                // 그 행을 손댈 방법이 아예 없어진다.
                new ClaimReasonCodeFormat($this->exemptCodes()),
                Rule::unique(ClaimReason::class, 'code')->where('type', $this->input('type', 'refund'))->ignore($id),
            ],
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'fault_type' => ['required', 'string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'is_user_selectable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * 코드 형식 검사를 면제할 값을 반환합니다.
     *
     * 대상 사유에 **이미 저장된 코드** 하나뿐이다. 다른 행의 코드까지 면제하면 이 요청으로
     * 남의 코드 형식을 물려받을 수 있으므로 넓히지 않는다.
     *
     * @return array<int, string> 면제 대상 코드
     */
    protected function exemptCodes(): array
    {
        $id = (int) $this->route('id');

        if ($id <= 0) {
            return [];
        }

        $current = app(ClaimReasonRepositoryInterface::class)->findById($id);

        return $current === null ? [] : [$current->code];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        return [
            'type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_type'),
            'code' => __('sirsoft-ecommerce::validation.attributes.claim_reason_code'),
            'name' => __('sirsoft-ecommerce::validation.attributes.claim_reason_name'),
            'fault_type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_fault_type'),
            'is_user_selectable' => __('sirsoft-ecommerce::validation.attributes.claim_reason_is_user_selectable'),
            'is_active' => __('sirsoft-ecommerce::validation.attributes.claim_reason_is_active'),
            'sort_order' => __('sirsoft-ecommerce::validation.attributes.claim_reason_sort_order'),
        ];
    }
}
