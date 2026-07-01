<?php

namespace App\Http\Requests\Identity;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicyScope;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Rules\UniquePolicyPriorityPerTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 운영자가 IDV 정책을 수정할 때의 검증.
 *
 * source_type != 'admin' 정책은 키(key)/시점(scope)/위치(target) 만 readonly 이며 (확장이 발행하는
 * 훅/라우트 지점 식별자라 변경 시 정책이 지점과 어긋남), 그 외 필드는 운영자가 자유로이 편집할 수
 * 있습니다 — Controller 의 LIMITED_EDITABLE_FIELDS 화이트리스트가 key/scope/target 만 필터링합니다.
 */
class AdminIdentityPolicyUpdateRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true (권한 판정은 미들웨어 책임)
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
        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'provider_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fail_mode' => ['sometimes', Rule::enum(IdentityPolicyFailMode::class)],

            // 아래 필드는 source_type=admin 일 때만 Controller 에서 적용
            'key' => ['sometimes', 'string', 'max:120'],
            'scope' => ['sometimes', Rule::enum(IdentityPolicyScope::class)],
            'target' => ['sometimes', 'string', 'max:255'],
            'purpose' => ['sometimes', 'string', 'max:64'],
            // priority 동률 차단 — 같은 scope+target 에 동일 priority 활성 정책이 이미 있으면 거부 (자기 자신 제외).
            // scope/target/enabled 는 요청에 없으면 기존 정책 값으로 폴백 (선언형 정책은 scope/target 변경 불가).
            'priority' => [
                'sometimes',
                'integer',
                'min:0',
                'max:65535',
                new UniquePolicyPriorityPerTarget(
                    scope: $this->effectiveScope(),
                    target: $this->effectiveTarget(),
                    enabled: $this->effectiveEnabled(),
                    ignoreId: $this->currentPolicy()?->id,
                ),
            ],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'applies_to' => ['sometimes', Rule::enum(IdentityPolicyAppliesTo::class)],
        ];

        return HookManager::applyFilters('core.identity_policy.update_validation_rules', $rules, $this);
    }

    /**
     * 동일 요청 내 1회 조회 캐시 (false = 미조회). static 금지 — 인스턴스별로 격리해야
     * 한 프로세스에서 여러 요청이 처리되는 환경(테스트 러너 등)에서 이전 요청의 정책이
     * 재사용되는 오염을 방지한다.
     */
    private IdentityPolicy|null|false $cachedPolicy = false;

    /**
     * 수정 대상 정책을 라우트 {id} 로 1회 조회해 캐싱합니다 (priority 동률 검사용 폴백).
     *
     * @return IdentityPolicy|null 대상 정책 또는 null
     */
    protected function currentPolicy(): ?IdentityPolicy
    {
        if ($this->cachedPolicy === false) {
            $id = $this->route('id');
            $this->cachedPolicy = is_numeric($id)
                ? app(IdentityPolicyRepositoryInterface::class)->findById((int) $id)
                : null;
        }

        return $this->cachedPolicy;
    }

    /**
     * 동률 검사에 사용할 scope — 요청에 있으면 그 값, 없으면 기존 정책 값.
     */
    protected function effectiveScope(): string
    {
        $scope = $this->input('scope');
        if (is_string($scope) && $scope !== '') {
            return $scope;
        }

        return (string) ($this->currentPolicy()?->scope?->value ?? '');
    }

    /**
     * 동률 검사에 사용할 target — 요청에 있으면 그 값, 없으면 기존 정책 값.
     */
    protected function effectiveTarget(): string
    {
        $target = $this->input('target');
        if (is_string($target) && $target !== '') {
            return $target;
        }

        return (string) ($this->currentPolicy()?->target ?? '');
    }

    /**
     * 동률 검사에 사용할 enabled — 요청에 있으면 그 값, 없으면 기존 정책 값.
     */
    protected function effectiveEnabled(): bool
    {
        if ($this->has('enabled')) {
            return $this->boolean('enabled');
        }

        return (bool) ($this->currentPolicy()?->enabled ?? false);
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
