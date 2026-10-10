<?php

namespace App\Http\Middleware;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityPolicy;
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
     * 본인인증 정책 강제 미들웨어를 생성합니다.
     *
     * @param  IdentityPolicyService  $policyService  정책 유스케이스 Service
     * @param  IdentityPolicyRepositoryInterface  $policyRepository  정책 Repository
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 Repository (하위 호환 — 판정은 Service 로 옮겼다)
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
     * @throws IdentityVerificationRequiredException 정책 위반 시
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
     * @param  Request  $request  HTTP 요청 (자동 매핑 모드에서 라우트 이름을 읽는다)
     * @param  string|null  $policyKey  미들웨어 인자로 지정된 정책 키 (없으면 자동 매핑 모드)
     * @return iterable<IdentityPolicy> 강제할 정책 목록 (없으면 빈 배열)
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
     * 판정은 `IdentityPolicyService::enforcePolicyForRequest()` 한 곳이 소유한다 — 라우트 밖에서 같은
     * 정책을 거는 자리(정보수정 API 의 비밀번호 변경)와 판정이 갈리지 않게 한다.
     *
     * @param  IdentityPolicy  $policy  강제할 정책
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     *
     * @throws IdentityVerificationRequiredException 정책 위반 시
     */
    protected function enforcePolicy(IdentityPolicy $policy, Request $request): void
    {
        $this->policyService->enforcePolicyForRequest($policy, $request);
    }
}
