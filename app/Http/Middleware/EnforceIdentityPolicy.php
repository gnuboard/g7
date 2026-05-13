<?php

namespace App\Http\Middleware;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 라우트 단위 IDV 정책 강제 미들웨어.
 *
 * 두 가지 운영 모드:
 *
 *   1. 자동 매핑 모드 (인자 없음, 권장) — bootstrap/app.php 에서 API 그룹에 글로벌 등록.
 *      모든 API 요청에서 라우트 이름을 키로 `IdentityPolicyRepository::getRouteScopeIndex()` 를
 *      조회하여 매칭 정책 모두를 enforce. 정책 DB 토글만으로 즉시 효과 (라우트 코드 수정 불필요).
 *      hook scope 정책이 EnforceIdentityPolicyListener 의 동적 구독으로 동작하는 것과 동일 모델.
 *
 *   2. 명시 모드 (인자 있음, deprecated 권장 외) — 외부 모듈/플러그인 라우트가 자기 정책 키를
 *      직접 명시하고 싶을 때:
 *        Route::put('/me/password', ...)->middleware('identity.policy:core.profile.password_change');
 *
 * 어느 모드든 단일 정책 enforce 절차는 동일:
 *   - 요청의 verification_token 이 verified+미소비+purpose 일치+target_hash 일치 → 통과
 *     (IdentityGuardInterceptor 의 verify 직후 재시도 흐름)
 *   - 토큰 미동봉/무효 → IdentityPolicyService::enforce() 가 grace_minutes 윈도우 검사 후
 *     통과 또는 IdentityVerificationRequiredException throw
 *
 * @since 7.0.0-beta.4
 */
class EnforceIdentityPolicy
{
    /**
     * @param  IdentityPolicyService  $policyService  정책 유스케이스 Service
     * @param  IdentityPolicyRepositoryInterface  $policyRepository  정책 Repository
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
        protected IdentityPolicyRepositoryInterface $policyRepository,
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 미들웨어 진입점.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 파이프라인
     * @param  string|null  $policyKey  정책 키 (identity_policies.key)
     * @return Response
     *
     * @throws \App\Exceptions\IdentityVerificationRequiredException 정책 위반 시
     */
    public function handle(Request $request, Closure $next, ?string $policyKey = null): Response
    {
        $policies = $this->resolvePolicies($request, $policyKey);

        foreach ($policies as $policy) {
            $this->enforcePolicy($policy, $request);
        }

        return $next($request);
    }

    /**
     * 강제 대상 정책 목록을 결정합니다.
     *
     * - 명시 모드: $policyKey 로 단일 정책 조회 (enabled 만 통과)
     * - 자동 매핑 모드: 라우트 이름으로 캐시된 인덱스에서 매칭 정책 컬렉션 조회
     *
     * 라우트 이름이 없는 요청 (예: 404 / health check) 은 빈 배열 반환 → 미들웨어 즉시 통과.
     *
     * @return iterable<\App\Models\IdentityPolicy>
     */
    protected function resolvePolicies(Request $request, ?string $policyKey): iterable
    {
        if ($policyKey !== null && $policyKey !== '') {
            $policy = $this->policyRepository->findByKey($policyKey);

            return ($policy && $policy->enabled) ? [$policy] : [];
        }

        $routeName = $request->route()?->getName();
        if (! is_string($routeName) || $routeName === '') {
            return [];
        }

        $index = $this->policyRepository->getRouteScopeIndex();

        return $index[$routeName] ?? [];
    }

    /**
     * 단일 정책에 대해 enforce 절차를 수행합니다 (token bypass → grace 윈도우 → exception).
     *
     * @throws \App\Exceptions\IdentityVerificationRequiredException 정책 위반 시
     */
    protected function enforcePolicy(\App\Models\IdentityPolicy $policy, Request $request): void
    {
        // verification_token 우회 — IdentityGuardInterceptor 가 verify 직후 토큰을 query/body 에 부착해
        // 원 요청을 재실행할 때 grace_minutes 윈도우와 무관하게 통과시킨다.
        // 검사 순서: ① 토큰이 verified + 미소비 + purpose 일치 → ② target_hash 매칭 (요청 email vs 토큰 발급 시 email).
        $token = (string) $request->input('verification_token', '');
        if ($token !== '') {
            $verifiedLog = $this->logRepository->findVerifiedForToken($token, $policy->purpose);
            if ($verifiedLog !== null && $this->tokenTargetMatches($verifiedLog, $request)) {
                return;
            }
        }

        $context = [
            'http_method' => $request->getMethod(),
            'user_roles' => $this->collectUserRoles($request),
            'user_is_admin' => $this->resolveUserIsAdmin($request),
            'target_email' => $this->resolveTargetEmail($request),
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => $request->route()?->getName() ?: $request->path(),
            'origin_policy_key' => $policy->key,
            'return_request' => [
                'method' => $request->getMethod(),
                'url' => $request->fullUrl(),
            ],
        ];

        $this->policyService->enforce($policy, $request->user(), $context);
    }

    /**
     * 토큰이 가리키는 challenge 의 target_hash 가 현재 요청의 식별자(이메일)와 일치하는지 확인합니다.
     *
     * 인증 사용자: user.email 우선
     * 게스트: 요청 body 의 email
     *
     * 요청에 식별자가 전혀 없으면(예: 별도 정책에서 email 외 식별자 사용) 검사를 건너뛰고 통과시킵니다 —
     * 이 경우 다운스트림 listener 가 자기 도메인의 식별자로 매칭을 강제해야 합니다.
     *
     * @param  \App\Models\IdentityVerificationLog  $log  토큰이 가리키는 verified 로그
     * @param  Request  $request  HTTP 요청
     * @return bool target_hash 일치 여부 (식별자 미존재 시 true)
     */
    protected function tokenTargetMatches(\App\Models\IdentityVerificationLog $log, Request $request): bool
    {
        $user = $request->user();
        $email = $user instanceof User && $user->email
            ? $user->email
            : (string) $request->input('email', '');

        if ($email === '') {
            return true;
        }

        return $log->target_hash === hash('sha256', mb_strtolower($email));
    }

    /**
     * 현재 요청 사용자의 역할 식별자 목록을 수집합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<int, string> 역할 식별자 배열
     */
    protected function collectUserRoles(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [];
        }

        if (method_exists($user, 'roles')) {
            return $user->roles()->pluck('identifier')->all();
        }

        return [];
    }

    /**
     * 현재 요청 사용자의 admin 여부를 permission 기반으로 판정합니다 (User::isAdmin() 위임).
     * 게스트는 항상 false.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool admin 여부
     */
    protected function resolveUserIsAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        try {
            return (bool) $user->isAdmin();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 게스트 라우트(register/forgot/reset)에서 폼 입력 email 을 정책 컨텍스트에 노출합니다.
     * 인증 사용자의 경우는 IdentityPolicyService::resolveTargetHash() 가 user->email 을 우선 사용합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 요청 input.email 또는 null
     */
    protected function resolveTargetEmail(Request $request): ?string
    {
        $email = $request->input('email');

        if (is_string($email) && $email !== '') {
            return $email;
        }

        return null;
    }
}
