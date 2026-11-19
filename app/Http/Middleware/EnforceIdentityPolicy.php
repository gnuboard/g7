<?php

namespace App\Http\Middleware;

use App\Contracts\Http\DefersIdentityPolicyUntilValidated;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Exceptions\IdentityPolicyProviderUnavailableException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use App\Services\IdentityTokenAcceptanceService;
use App\Support\Auth\SystemOperation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
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
 * 컨트롤러가 `DefersIdentityPolicyUntilValidated` 표식을 단 폼 요청을 받으면(가입) 정책을 여기서 강제하지 않고
 * 그 폼 요청이 입력 검증을 통과한 뒤 강제한다 — 입력 오류(422)를 본인 확인 요구(428)보다 먼저 알린다(7.2.0).
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
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  본인인증 결과 인정 서비스 (실패한 작업의 소비 되돌림 — 7.1.6)
     */
    public function __construct(
        protected IdentityPolicyService $policyService,
        protected IdentityPolicyRepositoryInterface $policyRepository,
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected IdentityTokenAcceptanceService $tokenAcceptance,
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
        // 행위자 없는 시스템 작업 문맥(하위 요청 등)에서는 사람용 관문을 받지 않는다 — 리스너와 같은 규칙.
        if (SystemOperation::active()) {
            SystemOperation::logBypass('route:'.($request->route()?->getName() ?? $request->path()));

            return $next($request);
        }

        $policies = $this->resolvePolicies($request, $policyKey);
        $policies = is_array($policies) ? array_values($policies) : iterator_to_array($policies, false);

        try {
            if ($this->defersUntilValidated($request, $policies)) {
                // 입력 검증을 먼저 하는 폼 요청(7.2.0) — 정책은 그 요청이 검증을 통과한 뒤 같은 판정으로 강제한다.
                // 입력 오류(422)를 본인 확인 요구(428)보다 먼저 알리기 위해서다. 소비 되돌림은 아래 응답 판정이 그대로 맡는다.
                $request->attributes->set(IdentityPolicyService::DEFERRED_ROUTE_POLICIES_ATTRIBUTE, $policies);
            } else {
                foreach ($policies as $policy) {
                    $this->enforcePolicy($policy, $request);
                }
            }

            $response = $next($request);
        } catch (\Throwable $e) {
            // 정책·작업이 예외로 끝나면 이 요청이 소비한 본인인증 결과를 돌려준다 — 고쳐서 다시 보낼 수 있게(7.1.6, D2).
            // 뒤 정책이 지정 수단 사용 불가(503)로 끝난 경우도 같다 — 수단이 복구된 뒤 처음부터 다시 인증하지 않게.
            if ($e instanceof IdentityVerificationRequiredException
                || $e instanceof IdentityPolicyProviderUnavailableException
                || $e instanceof ValidationException) {
                $this->tokenAcceptance->releaseAccepted($request);
            }

            throw $e;
        }

        // 작업이 입력 오류(422)나 다른 본인인증 요구(428)로 끝나면 결과를 돌려준다 — 작업 1건에 1번만 인정하되
        // 실패한 작업은 작업으로 세지 않는다. 컨트롤러 안 훅 정책이 지정 수단 사용 불가(503)로 끝난 경우도
        // 같다 — 라우터 파이프라인이 `$next` 안 예외를 응답으로 바꿔 위 catch 에 닿지 않으므로 응답에 붙은 예외로 판정한다(V5).
        $responseException = $response->exception ?? null;
        if (in_array($response->getStatusCode(), [422, 428], true)
            || $responseException instanceof IdentityPolicyProviderUnavailableException) {
            $this->tokenAcceptance->releaseAccepted($request);
        }

        return $response;
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
     * 이 라우트의 정책 강제를 폼 요청의 입력 검증 뒤로 미룰지 판정합니다.
     *
     * 컨트롤러가 `DefersIdentityPolicyUntilValidated` 표식을 단 폼 요청을 받을 때만 미룬다 — 폼 요청은 컨트롤러에 닿기 전에
     * 반드시 검증되므로, 검증을 통과한 요청은 빠짐없이 정책 판정을 거친다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<int, IdentityPolicy>  $policies  강제할 정책 목록
     * @return bool 미룰지 여부 (강제할 정책이 없으면 false)
     */
    protected function defersUntilValidated(Request $request, array $policies): bool
    {
        if ($policies === []) {
            return false;
        }

        $route = $request->route();
        if (! $route instanceof Route) {
            return false;
        }

        try {
            return $route->signatureParameters(['subClass' => DefersIdentityPolicyUntilValidated::class]) !== [];
        } catch (\ReflectionException) {
            return false;
        }
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
