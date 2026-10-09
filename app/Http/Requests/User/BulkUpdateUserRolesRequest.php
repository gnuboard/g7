<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 회원 역할 일괄 추가·제거 요청 검증
 */
class BulkUpdateUserRolesRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string> 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['required', 'uuid', Rule::exists(User::class, 'uuid')],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists(Role::class, 'id')],
            'mode' => ['required', 'string', Rule::in(['add', 'remove'])],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.bulk_update_roles_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 정의합니다.
     *
     * @return array<string, string> 규칙별 오류 메시지
     */
    public function messages(): array
    {
        return [
            'uuids.required' => __('validation.required', ['attribute' => __('validation.attributes.uuids')]),
            'uuids.array' => __('validation.array', ['attribute' => __('validation.attributes.uuids')]),
            'uuids.min' => trans_choice('validation.min.array', 1, ['attribute' => __('validation.attributes.uuids'), 'min' => 1]),
            'uuids.max' => trans_choice('validation.max.array', 500, ['attribute' => __('validation.attributes.uuids'), 'max' => 500]),
            'uuids.*.required' => __('validation.required', ['attribute' => __('validation.attributes.user_id')]),
            'uuids.*.uuid' => __('validation.uuid', ['attribute' => __('validation.attributes.user_id')]),
            'uuids.*.exists' => __('validation.exists', ['attribute' => __('validation.attributes.user_id')]),
            'role_ids.required' => __('validation.required', ['attribute' => __('validation.attributes.role_ids')]),
            'role_ids.array' => __('validation.array', ['attribute' => __('validation.attributes.role_ids')]),
            'role_ids.min' => trans_choice('validation.min.array', 1, ['attribute' => __('validation.attributes.role_ids'), 'min' => 1]),
            'role_ids.*.integer' => __('validation.menu.roles.integer'),
            'role_ids.*.exists' => __('validation.menu.roles.exists'),
            'mode.required' => __('validation.required', ['attribute' => __('validation.attributes.mode')]),
            'mode.in' => __('validation.in', ['attribute' => __('validation.attributes.mode')]),
        ];
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * @return array<string, string> 필드별 표시명
     */
    public function attributes(): array
    {
        return [
            'uuids' => __('validation.attributes.uuids'),
            'uuids.*' => __('validation.attributes.user_id'),
            'role_ids' => __('validation.attributes.role_ids'),
            'role_ids.*' => __('validation.attributes.role_ids'),
            'mode' => __('validation.attributes.mode'),
        ];
    }
}
