<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Rules\ExcludeCurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateUserStatusRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * 권한 검사는 라우트의 `permission:admin,core.users.update` 미들웨어가 담당합니다.
     *
     * @return bool 항상 true (미들웨어에서 권한 제어)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1', new ExcludeCurrentUser],
            'ids.*' => ['required', 'uuid', Rule::exists(User::class, 'uuid')],
            'status' => ['required', 'string', Rule::in(UserStatus::values())],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.bulk_update_status_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 정의합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('validation.required', ['attribute' => __('validation.attributes.user_ids')]),
            'ids.array' => __('validation.array', ['attribute' => __('validation.attributes.user_ids')]),
            'ids.min' => __('validation.min.array', ['attribute' => __('validation.attributes.user_ids'), 'min' => 1]),
            'ids.*.required' => __('validation.required', ['attribute' => __('validation.attributes.user_id')]),
            'ids.*.uuid' => __('validation.uuid', ['attribute' => __('validation.attributes.user_id')]),
            'ids.*.exists' => __('validation.exists', ['attribute' => __('validation.attributes.user_id')]),
            'status.required' => __('validation.required', ['attribute' => __('validation.attributes.status')]),
            'status.in' => __('validation.in', ['attribute' => __('validation.attributes.status')]),
        ];
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * 범용 필드명 ids 는 전역 라벨이 범용 문구이므로,
     * 사용자 대상 라벨을 이 요청에서만 명시 매핑합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ids' => __('validation.attributes.user_ids'),
            'ids.*' => __('validation.attributes.user_id'),
        ];
    }
}
