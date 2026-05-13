<?php

namespace App\Http\Requests\Identity;

use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 운영자가 IDV 정책을 수정할 때의 검증.
 *
 * source_type != 'admin' 정책은 enabled/grace_minutes/provider_id/fail_mode 4개 필드만 허용됩니다.
 * (key/scope/target/conditions 등은 readonly — 선언형 Seeder 의 SSoT 유지를 위해 Controller 에서 필터링)
 */
class AdminIdentityPolicyUpdateRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'provider_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fail_mode' => ['sometimes', Rule::enum(IdentityPolicyFailMode::class)],

            // 아래 필드는 source_type=admin 일 때만 Controller 에서 적용
            'key' => ['sometimes', 'string', 'max:120'],
            'scope' => ['sometimes', Rule::enum(IdentityPolicyScope::class)],
            'target' => ['sometimes', 'string', 'max:255'],
            'purpose' => ['sometimes', 'string', 'max:64'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'applies_to' => ['sometimes', Rule::enum(IdentityPolicyAppliesTo::class)],
        ];
    }

    /**
     * 사용자 정의 검증 메시지.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.max' => __('validation.identity_policy.key_max'),
            'scope.enum' => __('validation.identity_policy.scope_invalid'),
            'target.max' => __('validation.identity_policy.target_max'),
            'purpose.max' => __('validation.identity_policy.purpose_max'),
            'provider_id.max' => __('validation.identity_policy.provider_id_max'),
            'grace_minutes.integer' => __('validation.identity_policy.grace_minutes_integer'),
            'grace_minutes.min' => __('validation.identity_policy.grace_minutes_min'),
            'grace_minutes.max' => __('validation.identity_policy.grace_minutes_max'),
            'enabled.boolean' => __('validation.identity_policy.enabled_boolean'),
            'priority.integer' => __('validation.identity_policy.priority_integer'),
            'priority.min' => __('validation.identity_policy.priority_min'),
            'priority.max' => __('validation.identity_policy.priority_max'),
            'conditions.array' => __('validation.identity_policy.conditions_array'),
            'applies_to.enum' => __('validation.identity_policy.applies_to_invalid'),
            'fail_mode.enum' => __('validation.identity_policy.fail_mode_invalid'),
        ];
    }

    /**
     * 검증 속성명 (validation.attributes).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'key' => __('validation.attributes.identity_policy_key'),
            'scope' => __('validation.attributes.identity_policy_scope'),
            'target' => __('validation.attributes.identity_policy_target'),
            'purpose' => __('validation.attributes.identity_policy_purpose'),
            'provider_id' => __('validation.attributes.identity_policy_provider_id'),
            'grace_minutes' => __('validation.attributes.identity_policy_grace_minutes'),
            'enabled' => __('validation.attributes.identity_policy_enabled'),
            'applies_to' => __('validation.attributes.identity_policy_applies_to'),
            'fail_mode' => __('validation.attributes.identity_policy_fail_mode'),
        ];
    }
}
