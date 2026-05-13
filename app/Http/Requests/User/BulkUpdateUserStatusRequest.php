<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Rules\ExcludeCurrentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateUserStatusRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     */
    public function authorize(): bool
    {
        // 사용자 업데이트 권한 확인
        return $this->user()->can('core.users.update');
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            'ids.required' => __('validation.required', ['attribute' => __('validation.attributes.ids')]),
            'ids.array' => __('validation.array', ['attribute' => __('validation.attributes.ids')]),
            'ids.min' => __('validation.min.array', ['attribute' => __('validation.attributes.ids'), 'min' => 1]),
            'ids.*.required' => __('validation.required', ['attribute' => __('validation.attributes.user_id')]),
            'ids.*.uuid' => __('validation.uuid', ['attribute' => __('validation.attributes.user_id')]),
            'ids.*.exists' => __('validation.exists', ['attribute' => __('validation.attributes.user_id')]),
            'status.required' => __('validation.required', ['attribute' => __('validation.attributes.status')]),
            'status.in' => __('validation.in', ['attribute' => __('validation.attributes.status')]),
        ];
    }
}
