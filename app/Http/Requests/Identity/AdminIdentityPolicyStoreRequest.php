<?php

namespace App\Http\Requests\Identity;

use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicyScope;
use App\Models\IdentityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 운영자가 신규 IDV 정책을 생성할 때의 검증.
 *
 * 권한은 라우트의 permission:admin,core.admin.identity.policies.manage 미들웨어가 담당.
 */
class AdminIdentityPolicyStoreRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:120', Rule::unique(IdentityPolicy::class, 'key')],
            'scope' => ['required', Rule::enum(IdentityPolicyScope::class)],
            'target' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:64'],
            'provider_id' => ['nullable', 'string', 'max:64'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:43200'],
            'enabled' => ['boolean'],
            'priority' => ['integer', 'min:0', 'max:65535'],
            'conditions' => ['nullable', 'array'],
            'applies_to' => ['required', Rule::enum(IdentityPolicyAppliesTo::class)],
            'fail_mode' => ['required', Rule::enum(IdentityPolicyFailMode::class)],
            // source_identifier — 운영자 자유 정책의 컨텍스트 귀속.
            // 'admin' (기본, 어느 확장에도 귀속 안 됨) | 'core' | 모듈/플러그인 raw identifier (예: 'sirsoft-ecommerce').
            // 모듈/플러그인 sync 경로 및 목록 필터가 모두 raw identifier 컨벤션을 사용하므로 동일하게 통일.
            'source_identifier' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\-]*$/'],
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
            'key.required' => __('validation.identity_policy.key_required'),
            'key.max' => __('validation.identity_policy.key_max'),
            'key.unique' => __('validation.identity_policy.key_unique'),
            'scope.required' => __('validation.identity_policy.scope_required'),
            'scope.enum' => __('validation.identity_policy.scope_invalid'),
            'target.required' => __('validation.identity_policy.target_required'),
            'target.max' => __('validation.identity_policy.target_max'),
            'purpose.required' => __('validation.identity_policy.purpose_required'),
            'purpose.max' => __('validation.identity_policy.purpose_max'),
            'provider_id.max' => __('validation.identity_policy.provider_id_max'),
            'grace_minutes.required' => __('validation.identity_policy.grace_minutes_required'),
            'grace_minutes.integer' => __('validation.identity_policy.grace_minutes_integer'),
            'grace_minutes.min' => __('validation.identity_policy.grace_minutes_min'),
            'grace_minutes.max' => __('validation.identity_policy.grace_minutes_max'),
            'enabled.boolean' => __('validation.identity_policy.enabled_boolean'),
            'priority.integer' => __('validation.identity_policy.priority_integer'),
            'priority.min' => __('validation.identity_policy.priority_min'),
            'priority.max' => __('validation.identity_policy.priority_max'),
            'conditions.array' => __('validation.identity_policy.conditions_array'),
            'applies_to.required' => __('validation.identity_policy.applies_to_required'),
            'applies_to.enum' => __('validation.identity_policy.applies_to_invalid'),
            'fail_mode.required' => __('validation.identity_policy.fail_mode_required'),
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
