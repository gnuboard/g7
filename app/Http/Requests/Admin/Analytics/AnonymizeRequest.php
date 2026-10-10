<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use App\Services\Analytics\PrivacyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 소급 익명화 요청
 *
 * 되돌릴 수 없는 변경이므로 무엇을 어떻게 가릴지 명시해야 합니다.
 */
class AnonymizeRequest extends FormRequest
{
    /**
     * 인가는 라우트 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'mask_octets' => ['nullable', 'integer', 'min:0', 'max:3'],
            'hash' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'confirm' => ['required', 'accepted'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.anonymize_validation_rules', $rules, $this);
    }

    /**
     * 가릴 대상이 하나라도 지정됐는지 확인합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // 소급 제안(PrivacyService::tightensPrivacy)과 같은 판정을 쓴다 — 띄운 창의 요청이 거부되지 않도록.
            if (! PrivacyService::hasRetroTarget((int) $this->input('mask_octets', 0), $this->boolean('hash'))) {
                $validator->errors()->add('mask_octets', __('validation.analytics.anonymize_target_required'));
            }
        });
    }

    /**
     * 검증 실패 문구를 반환합니다.
     *
     * @return array<string, string> 문구 목록
     */
    public function messages(): array
    {
        return [
            'confirm.required' => __('validation.analytics.confirm_required'),
            'confirm.accepted' => __('validation.analytics.confirm_required'),
        ];
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'mask_octets' => __('analytics.attributes.mask_octets'),
            'hash' => __('analytics.attributes.hash'),
            'from' => __('analytics.attributes.from'),
            'to' => __('analytics.attributes.to'),
            'confirm' => __('analytics.attributes.confirm'),
        ];
    }
}
