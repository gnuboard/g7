<?php

namespace App\Http\Middleware;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 추가 정보 입력 요구 관문 — api 그룹 전체에 붙어 공통 회원 상태 관문({@see EnsureActiveUser}) 뒤에서 돈다.
 *
 * 비어 있는 필수 항목이 있는 회원의 쓰기 요청을 거부한다. 화면 유도만으로는 주소를 직접 부르는 요청을 막지 못하므로
 * 서버가 막는다. 코어, 모듈, 플러그인 API 가 모두 같은 판정을 받는다.
 *
 * | 순서 | 조건 | 결과 |
 * | --- | --- | --- |
 * | 1 | 인증된 회원이 없음 (인증 미들웨어가 없는 라우트 포함) | 통과 |
 * | 2 | 메서드가 GET, HEAD, OPTIONS | 통과 |
 * | 3 | 관리자 API (URI 에 `admin` 구간이 있거나 관리자 미들웨어가 붙은 라우트) | 통과 |
 * | 4 | 라우트에 표식 `allow.pending_required_fields` 가 있거나, 필터 `core.required_fields.filter_exempt_routes` 가 돌려준 라우트 이름 | 통과 |
 * | 5 | 비어 있는 필수 항목이 있음 | 403 `required_fields_pending` |
 *
 * @since 7.2.0
 */
class EnsureRequiredFieldsCompleted
{
    /** 통과 표식 별칭 */
    public const ALLOW_PENDING_ALIAS = 'allow.pending_required_fields';

    /** 거부 응답의 오류 코드 */
    public const ERROR_CODE = 'required_fields_pending';

    /** 판정하지 않는 메서드 */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param  RequiredFieldsChecker  $checker  비어 있는 필수 항목 판정
     */
    public function __construct(
        private readonly RequiredFieldsChecker $checker,
    ) {}

    /**
     * 요청을 처리합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::READ_METHODS, true)) {
            return $next($request);
        }

        $route = $request->route();
        if (! $route instanceof Route) {
            return $next($request);
        }

        // 인증 미들웨어가 없는 라우트는 회원을 풀지 않는다(공통 회원 상태 관문과 같은 기준).
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        if (! $this->hasMiddleware($middleware, ['auth', 'optional.sanctum', OptionalSanctumMiddleware::class])) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->isAdminRoute($route, $middleware)
            || $this->hasMiddleware($middleware, [self::ALLOW_PENDING_ALIAS, AllowPendingRequiredFields::class])) {
            return $next($request);
        }

        // 대부분의 회원은 미입력이 없다 — 캐시된 판정으로 먼저 걸러 확장 필터를 부르지 않는다.
        if ($this->checker->pendingKeys($user) === [] || $this->isExemptByFilter($route, $request)) {
            return $next($request);
        }

        // 거부하기 직전에는 캐시를 버리고 다시 판정한다 — 낡은 판정으로 회원을 막지 않는다.
        $pending = $this->checker->pendingKeys($user, fresh: true);
        if ($pending === []) {
            return $next($request);
        }

        $response = ResponseHelper::error('user.required_fields_pending', 403, null, [], 'core', [
            'pending_required_fields' => $pending,
            'fields' => $this->checker->describe($pending),
        ]);
        $response->setData([...$response->getData(true), 'error_code' => self::ERROR_CODE]);

        return $response;
    }

    /**
     * 관리자 API 라우트인지 판정합니다 (관리자 화면은 막지 않는다).
     *
     * @param  Route  $route  현재 라우트
     * @param  array<int, string>  $middleware  라우트 미들웨어 목록
     * @return bool URI 에 `admin` 구간이 있거나 관리자 미들웨어가 붙어 있으면 true
     */
    private function isAdminRoute(Route $route, array $middleware): bool
    {
        if (in_array('admin', explode('/', trim($route->uri(), '/')), true)) {
            return true;
        }

        return $this->hasMiddleware($middleware, ['admin', AdminMiddleware::class]);
    }

    /**
     * 확장이 필터로 보탠 허용 라우트인지 판정합니다.
     *
     * 필터 `core.required_fields.filter_exempt_routes(array $routeNames, Request $request)` 가 라우트 이름 목록을 돌려준다.
     * 이름 끝에 `*` 를 쓰면 접두가 같은 라우트를 한꺼번에 허용한다.
     *
     * @param  Route  $route  현재 라우트
     * @param  Request  $request  HTTP 요청
     * @return bool 허용 목록에 있으면 true
     */
    private function isExemptByFilter(Route $route, Request $request): bool
    {
        $name = $route->getName();
        if (! is_string($name) || $name === '') {
            return false;
        }

        $exempt = HookManager::applyFilters('core.required_fields.filter_exempt_routes', [], $request);
        if (! is_array($exempt)) {
            return false;
        }

        foreach ($exempt as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 목록에 이름(파라미터 무시)이 있는지 판정합니다.
     *
     * `auth` 는 파라미터가 없거나 `sanctum` 일 때만 일치로 본다(다른 가드는 이 관문 대상이 아니다).
     *
     * @param  array<int, string>  $middleware  미들웨어 목록
     * @param  array<int, string>  $names  찾을 이름
     * @return bool 있으면 true
     */
    private function hasMiddleware(array $middleware, array $names): bool
    {
        foreach ($middleware as $entry) {
            if (in_array($entry, $names, true)) {
                return true;
            }

            [$name, $parameter] = array_pad(explode(':', $entry, 2), 2, null);

            if ($name === 'auth') {
                if (in_array('auth', $names, true) && ($parameter === null || $parameter === 'sanctum')) {
                    return true;
                }

                continue;
            }

            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }
}
