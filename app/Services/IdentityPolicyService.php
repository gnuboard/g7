<?php

namespace App\Services;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicySourceType;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityPolicy;
use App\Models\User;

/**
 * 본인인증 정책 해석/강제 Service.
 *
 * 호출 흐름:
 *   resolve(scope, target, context)  → 매칭 정책 반환 (null 가능)
 *   enforce(policy, user, context)   → grace 내 verified 있으면 pass / 없으면 예외
 *
 * @since 7.0.0-beta.4
 */
class IdentityPolicyService
{
    public function __construct(
        protected IdentityPolicyRepositoryInterface $policyRepository,
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected IdentityVerificationManager $manager,
    ) {}

    /**
     * scope + target 으로 매칭 가능한 활성 정책 중 가장 높은 priority 정책을 반환합니다.
     * 매칭 정책이 없으면 null 반환 (no-op pass-through).
     *
     * 플러그인 필터 훅 `core.identity.resolve_policy` 가 IdentityPolicy 인스턴스를 반환하면 덮어씁니다.
     * 보안: 훅이 null/잘못된 타입 반환 시 원본 정책 유지 (우회 차단).
     *
     * @param  string  $scope  정책 scope ('route' | 'hook')
     * @param  string  $target  scope 별 식별자 (route name 또는 hook name)
     * @param  array<string, mixed>  $context  매칭 컨텍스트 (http_method, signup_stage, user_roles 등)
     * @return IdentityPolicy|null 매칭 정책 또는 null
     */
    public function resolve(string $scope, string $target, array $context = []): ?IdentityPolicy
    {
        $policies = $this->policyRepository->resolveByScopeTarget($scope, $target);
        $policy = $this->selectMatchingPolicy($policies, $context);

        $filtered = HookManager::applyFilters(
            'core.identity.resolve_policy',
            $policy,
            $scope,
            $target,
            $context,
        );

        // 보안: filter 훅이 IdentityPolicy 외 타입(null 등)을 반환해도 원본 정책 유지.
        // 의도적 정책 변경은 IdentityPolicy 인스턴스로 반환할 것.
        if (! $filtered instanceof IdentityPolicy) {
            return $policy;
        }

        return $filtered;
    }

    /**
     * 정책을 강제합니다. grace_minutes 내 verified 가 있으면 통과, 없으면 예외.
     *
     * 분기 순서:
     *   1. policy.enabled=false → no-op
     *   2. applies_to 와 사용자(admin/일반) 매칭 안 되면 no-op
     *   3. grace_minutes 내 verified 로그 있으면 통과
     *   4. fail_mode=log_only 이면 감사 로그 남기고 통과, 아니면 예외 throw
     *
     * @param  IdentityPolicy  $policy  강제 대상 정책
     * @param  User|null  $user  현재 사용자 (게스트 가입 흐름에서는 null)
     * @param  array<string, mixed>  $context  요청 컨텍스트 (target_email, user_roles, return_request 등)
     * @return void
     *
     * @throws IdentityVerificationRequiredException grace 내 verified 없고 fail_mode != log_only 일 때
     */
    public function enforce(IdentityPolicy $policy, ?User $user, array $context = []): void
    {
        if (! $policy->enabled) {
            return;
        }

        // policy.conditions 와 요청 context 매칭 — 안전망. 호출자(미들웨어/리스너/직접호출)가
        // selectMatchingPolicy 를 거치지 않고 enforce 만 호출해도 conditions 가 평가되도록 보장한다.
        // 예: contact_change 정책(changed_fields=['email','phone','mobile']) 이 비밀번호 변경 같은
        // 무관 user update 에서 발화하던 회귀 차단.
        if (! $this->policyMatchesContext($policy, $context)) {
            return;
        }

        // applies_to: self → admin 제외, admin → admin 만, both → 모두 enforce
        $appliesTo = $policy->applies_to ?? IdentityPolicyAppliesTo::Both;
        if ($appliesTo !== IdentityPolicyAppliesTo::Both) {
            $isAdmin = $this->isAdminContext($user, $context);
            if ($appliesTo === IdentityPolicyAppliesTo::Self_ && $isAdmin) {
                return;
            }
            if ($appliesTo === IdentityPolicyAppliesTo::Admin && ! $isAdmin) {
                return;
            }
        }

        $targetHash = $this->resolveTargetHash($user, $context);
        $userId = $user?->id;

        // verification_token 우회 — IdentityGuardInterceptor 가 verify 직후 토큰을 부착해
        // 원 요청을 재실행할 때 grace_minutes 윈도우와 무관하게 통과시킨다.
        // 모든 enforce 진입점(미들웨어/리스너/직접 호출) 에 동일 우회가 적용되도록 Service 단계에서 처리.
        // 회귀 차단: hook scope 정책 (예: core.admin.user_delete, grace_minutes=0) 에서 미들웨어만
        // 토큰을 알고 listener 는 모르던 결함으로 인해 verify 후 retry 시 재차 428 이 발생하던 무한 루프.
        $token = (string) ($context['verification_token'] ?? '');
        if ($token !== '') {
            $verifiedLog = $this->logRepository->findVerifiedForToken($token, $policy->purpose);
            if ($verifiedLog !== null
                && ($targetHash === null || $verifiedLog->target_hash === $targetHash)) {
                return;
            }
        }

        $recent = $this->logRepository->findRecentVerified(
            purpose: $policy->purpose,
            userId: $userId,
            targetHash: $targetHash,
            withinMinutes: max(0, $policy->grace_minutes),
        );

        if ($recent !== null) {
            return;
        }

        // fail_mode=log_only → 감사 로그만 남기고 요청 통과
        if ($policy->fail_mode === IdentityPolicyFailMode::LogOnly) {
            $this->logPolicyViolation($policy, $user, $context);

            return;
        }

        throw new IdentityVerificationRequiredException(
            policyKey: $policy->key,
            purpose: $policy->purpose,
            providerId: $policy->provider_id,
            renderHint: $this->resolveRenderHint($policy),
            returnRequest: $context['return_request'] ?? null,
        );
    }

    /**
     * 플러그인이 런타임에 정책을 추가로 등록할 때 사용 (DB 저장 없이).
     * 현재 구현은 로그에만 남기고, 필터 훅으로 소비되도록 설계.
     *
     * @param  IdentityPolicy  $policy  임시 정책 인스턴스
     * @return void
     */
    public function registerRuntime(IdentityPolicy $policy): void
    {
        HookManager::doAction('core.identity.runtime_policy_registered', $policy);
    }

    /**
     * 정책 목록을 페이지네이션과 함께 조회합니다 (관리자 S1d DataGrid).
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지 크기
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function search(array $filters, int $perPage = 20)
    {
        return $this->policyRepository->search($filters, $perPage);
    }

    /**
     * 정책 id 로 조회합니다.
     *
     * @param  int  $id  정책 ID
     * @return IdentityPolicy|null
     */
    public function findById(int $id): ?IdentityPolicy
    {
        return $this->policyRepository->findById($id);
    }

    /**
     * 신규 정책을 생성합니다 (source_type='admin' 고정).
     *
     * @param  array<string, mixed>  $data  정책 데이터
     * @return IdentityPolicy
     */
    public function createAdminPolicy(array $data): IdentityPolicy
    {
        $data['source_type'] = 'admin';
        // source_identifier 는 FormRequest 가 형식 검증(admin|module:{id}|plugin:{id}) 후 전달.
        // 미지정 시 'admin' (운영자 자유 정책, 어느 확장에도 귀속 안 됨).
        $data['source_identifier'] = $data['source_identifier'] ?? 'admin';

        return $this->policyRepository->upsertByKey($data);
    }

    /**
     * 정책을 업데이트합니다. source_type != 'admin' 일 경우 제한 필드만 허용.
     *
     * @param  IdentityPolicy  $policy  수정 대상 정책
     * @param  array<string, mixed>  $attributes  수정할 필드
     * @return bool 수정 성공 여부
     */
    public function updatePolicy(IdentityPolicy $policy, array $attributes): bool
    {
        $overrides = $policy->source_type !== IdentityPolicySourceType::Admin ? array_keys($attributes) : [];

        return $this->policyRepository->updateByKey($policy->key, $attributes, $overrides);
    }

    /**
     * 관리자 생성 정책을 삭제합니다. 선언형 정책은 false 반환.
     *
     * @param  IdentityPolicy  $policy  삭제 대상
     * @return bool 삭제 성공 여부
     */
    public function deleteAdminPolicy(IdentityPolicy $policy): bool
    {
        if ($policy->source_type !== IdentityPolicySourceType::Admin) {
            return false;
        }

        return $this->policyRepository->deleteByKey($policy->key);
    }

    /**
     * 단일 필드의 user_overrides 를 해제하고 선언 기본값으로 즉시 복원합니다.
     *
     * 동작:
     *   1. user_overrides 배열에서 해당 필드명 제거
     *   2. 선언 기본값(core: config/core.php, module/plugin: 해당 확장의 getIdentityPolicies()) 을
     *      현재 정책 레코드에 즉시 반영 (다음 Seeder 실행 기다림 없이)
     *   3. source_type='admin' 정책은 선언 기본값이 없으므로 false 반환
     *
     * S1d 관리자 UI 의 "↺ 기본값으로 되돌리기" 버튼이 이 메서드를 호출합니다.
     *
     * @param  IdentityPolicy  $policy  대상 정책
     * @param  string  $field  복원할 필드명 (enabled|grace_minutes|provider_id|fail_mode|conditions 중 하나)
     * @return bool 성공 여부 (field 미지원 또는 선언 기본값 부재 시 false)
     */
    public function resetFieldOverride(IdentityPolicy $policy, string $field): bool
    {
        $allowed = ['enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions'];
        if (! in_array($field, $allowed, true)) {
            return false;
        }

        $declared = $this->findDeclaredDefault($policy);
        if ($declared === null || ! array_key_exists($field, $declared)) {
            return false;
        }

        $overrides = array_values(array_filter(
            $policy->user_overrides ?? [],
            fn (string $name): bool => $name !== $field,
        ));

        $policy->{$field} = $declared[$field];
        $policy->user_overrides = $overrides;

        // HasUserOverrides trait 의 auto-record 우회 — 이 저장은 운영자 수정이 아니라
        // 기본값 복원이므로 trackable 필드가 변경되어도 user_overrides 에 재추가되면 안 됨.
        return $this->withUserOverridesBypass(fn () => $this->policyRepository->save($policy));
    }

    /**
     * `user_overrides.seeding` 플래그를 켠 상태로 callback 을 실행합니다.
     * HasUserOverrides trait 의 updating 이벤트가 이 플래그를 보면 auto-record 를 스킵합니다.
     *
     * @param  callable  $callback  내부에서 실행할 저장 콜백 (true/false 반환)
     * @return bool 콜백 결과를 bool 캐스팅한 값
     */
    protected function withUserOverridesBypass(callable $callback): bool
    {
        $app = app();
        $previouslyBound = $app->bound('user_overrides.seeding');
        $previousValue = $previouslyBound ? $app->make('user_overrides.seeding') : null;

        $app->instance('user_overrides.seeding', true);
        try {
            return (bool) $callback();
        } finally {
            if ($previouslyBound) {
                $app->instance('user_overrides.seeding', $previousValue);
            } else {
                // Laravel container 에는 unbind 공식 API 가 없으므로 false 로 덮어씀.
                $app->instance('user_overrides.seeding', false);
            }
        }
    }

    /**
     * 정책의 source 에 해당하는 선언 기본값을 반환합니다.
     *
     * - core: config('core.identity_policies.{key}')
     * - module/plugin: 해당 확장의 getIdentityPolicies() 결과 중 key 일치 항목
     * - admin: null (선언 기본값 없음)
     *
     * @param  IdentityPolicy  $policy  대상 정책
     * @return array<string, mixed>|null 선언 기본값 배열 또는 null
     */
    protected function findDeclaredDefault(IdentityPolicy $policy): ?array
    {
        if ($policy->source_type === IdentityPolicySourceType::Core) {
            // 주의: policy key 에 dot 가 포함되므로 config() 의 dot-notation 을 쓰면 안 됨.
            // 전체 블록을 가져와 배열 키로 조회.
            $block = (array) config('core.identity_policies', []);
            $declared = $block[$policy->key] ?? null;

            return is_array($declared) ? $declared : null;
        }

        if ($policy->source_type === IdentityPolicySourceType::Module) {
            try {
                $manager = app(\App\Extension\ModuleManager::class);
                $module = $manager->getModuleByIdentifier($policy->source_identifier)
                    ?? $manager->getModule($policy->source_identifier);
                if ($module && method_exists($module, 'getIdentityPolicies')) {
                    foreach ($module->getIdentityPolicies() as $data) {
                        if (($data['key'] ?? null) === $policy->key) {
                            return $data;
                        }
                    }
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }

        if ($policy->source_type === IdentityPolicySourceType::Plugin) {
            try {
                $manager = app(\App\Extension\PluginManager::class);
                $plugin = $manager->getPlugin($policy->source_identifier);
                if ($plugin && method_exists($plugin, 'getIdentityPolicies')) {
                    foreach ($plugin->getIdentityPolicies() as $data) {
                        if (($data['key'] ?? null) === $policy->key) {
                            return $data;
                        }
                    }
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }

        // source_type=admin 은 선언 기본값 없음
        return null;
    }

    /**
     * 우선순위 정렬된 정책 목록 중 context 와 매칭되는 첫 정책을 반환합니다.
     *
     * @param  iterable<IdentityPolicy>  $policies  Repository 가 priority 내림차순으로 반환한 정책 목록
     * @param  array<string, mixed>  $context  매칭 컨텍스트
     * @return IdentityPolicy|null 매칭 정책 또는 null
     */
    protected function selectMatchingPolicy($policies, array $context): ?IdentityPolicy
    {
        foreach ($policies as $policy) {
            if ($this->policyMatchesContext($policy, $context)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * policy.conditions 와 요청 context 를 매칭합니다.
     * 지원 키: http_method / changed_fields / user_role / signup_stage.
     * 모든 명시 조건이 통과해야 true.
     *
     * @param  IdentityPolicy  $policy  검사 대상 정책
     * @param  array<string, mixed>  $context  요청 컨텍스트
     * @return bool 매칭 여부
     */
    protected function policyMatchesContext(IdentityPolicy $policy, array $context): bool
    {
        $conditions = $policy->conditions ?? [];

        if (! empty($conditions['http_method']) && isset($context['http_method'])) {
            $methods = (array) $conditions['http_method'];
            if (! in_array(strtoupper((string) $context['http_method']), array_map('strtoupper', $methods), true)) {
                return false;
            }
        }

        if (! empty($conditions['changed_fields']) && isset($context['changed_fields'])) {
            $required = (array) $conditions['changed_fields'];
            $changed = (array) $context['changed_fields'];
            if (empty(array_intersect($required, $changed))) {
                return false;
            }
        }

        if (! empty($conditions['user_role']) && isset($context['user_roles'])) {
            $required = (array) $conditions['user_role'];
            $userRoles = (array) $context['user_roles'];
            if (empty(array_intersect($required, $userRoles))) {
                return false;
            }
        }

        if (! empty($conditions['signup_stage']) && isset($context['signup_stage'])) {
            $allowed = (array) $conditions['signup_stage'];
            if (! in_array((string) $context['signup_stage'], $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * permission 기반 admin 여부 판정 (role identifier 'admin' 직접 가정 금지).
     *
     * 판정 우선순위:
     *   1. context['user_is_admin'] 가 명시되어 있으면 그 값 (미들웨어 fast path — User::isAdmin() 결과 캐시)
     *   2. User 모델의 `isAdmin()` — type='admin' 권한을 보유한 역할이 1개라도 있으면 true
     *
     * `context['user_roles']` 는 정책 conditions.user_role 매칭 전용이며, admin 판정 입력으로는
     * 사용하지 않습니다 (role identifier 와 권한 보유는 별개의 개념이므로 의미 혼재 방지).
     *
     * @param  User|null  $user  현재 사용자 (게스트 흐름에서는 null)
     * @param  array<string, mixed>  $context  요청 컨텍스트
     * @return bool admin 여부
     */
    protected function isAdminContext(?User $user, array $context): bool
    {
        if (array_key_exists('user_is_admin', $context)) {
            return (bool) $context['user_is_admin'];
        }

        if ($user && method_exists($user, 'isAdmin')) {
            try {
                return (bool) $user->isAdmin();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * 사용자 이메일 또는 context.target_email 을 sha256 해시로 변환합니다.
     * 인증 로그 조회 시 PII 보호용 키로 사용됩니다.
     *
     * @param  User|null  $user  사용자 (있으면 email 우선 사용)
     * @param  array<string, mixed>  $context  요청 컨텍스트 (`target_email` 키 폴백)
     * @return string|null sha256(소문자 email) 또는 null
     */
    protected function resolveTargetHash(?User $user, array $context): ?string
    {
        if ($user && $user->email) {
            return hash('sha256', mb_strtolower($user->email));
        }

        $email = (string) ($context['target_email'] ?? '');
        if ($email !== '') {
            return hash('sha256', mb_strtolower($email));
        }

        return null;
    }

    /**
     * 정책의 provider 가 제공하는 렌더 힌트를 반환합니다.
     * provider_id 가 명시되어 있으면 우선 사용, 미명시 시 purpose 기반 fallback.
     *
     * fallback 분기에서도 정책의 provider_id 를 Manager 의 0번 우선순위로 전달한다.
     * 정책에 지정된 provider 가 비활성/언인스톨된 순간 Manager 가 같은 우선순위 체인으로
     * 일관 처리하도록 정합화 — IdentityVerificationService::start 와 동일 패턴.
     *
     * @param  IdentityPolicy  $policy  대상 정책
     * @return string|null Provider 의 렌더 힌트 (UI 분기용) 또는 null
     */
    protected function resolveRenderHint(IdentityPolicy $policy): ?string
    {
        try {
            $providerId = $policy->provider_id;
            if ($providerId && $this->manager->has($providerId)) {
                return $this->manager->get($providerId)->getRenderHint();
            }

            return $this->manager->resolveForPurpose($policy->purpose, $providerId)->getRenderHint();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * fail_mode=log_only 정책 위반을 감사 로그로 기록합니다 (요청은 통과).
     *
     * @param  IdentityPolicy  $policy  위반된 정책
     * @param  User|null  $user  현재 사용자
     * @param  array<string, mixed>  $context  요청 컨텍스트 (origin_type, origin_identifier 등)
     * @return void
     */
    protected function logPolicyViolation(IdentityPolicy $policy, ?User $user, array $context): void
    {
        $this->logRepository->create([
            'provider_id' => $policy->provider_id ?? 'g7:core.mail',
            'purpose' => $policy->purpose,
            'channel' => 'policy',
            'user_id' => $user?->id,
            'target_hash' => $this->resolveTargetHash($user, $context) ?? str_repeat('0', 64),
            'status' => \App\Enums\IdentityVerificationStatus::PolicyViolationLogged->value,
            'origin_type' => $context['origin_type'] ?? IdentityOriginType::Policy->value,
            'origin_identifier' => $context['origin_identifier'] ?? null,
            'origin_policy_key' => $policy->key,
            'metadata' => ['policy_id' => $policy->id],
        ]);
    }
}
