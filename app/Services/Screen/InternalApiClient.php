<?php

namespace App\Services\Screen;

use App\Http\Middleware\SetTimezone;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

/**
 * 내부 API 도구 — 개발자 화면(custom 예약 자리, #792 D10)이 **로그인 회원 자격으로** 코어·확장 API 를 같은
 * 프로세스에서 불러 HTML 을 완성하게 한다.
 *
 * ```php
 * $res = $api->get('/api/modules/sirsoft-board/boards/free/posts', ['page' => 2]);
 * if ($res->status() === 403) abort(403);
 * $posts = $res->json('data.data');
 * ```
 *
 * 동작:
 * - `/api/` 로 시작하는 경로만, 하위 요청 안에서 다시 부르는 것과 세션을 여는 인증 라우트(로그인·로그아웃·2FA·
 *   갱신·가입 — `start.api.session`)는 거부한다. 로그아웃은 {@see ScreenAuth::logout()} 을 쓴다.
 * - 바깥 요청의 scheme·host·IP·User-Agent 를 그대로 쓰고 `Accept: application/json`, `Accept-Language` = 현재 로케일.
 *   쿠키는 넘기지 않는다(점검 모드 우회 쿠키만 예외).
 * - 인증: 웹 가드를 잊고 `sanctum.guard` 를 비운 뒤(같은 프로세스 하위 요청이 바깥 웹 세션 회원을 TransientToken 으로
 *   잡는 함정 차단) {@see ScreenAuth} 회원이면 짝 토큰으로 sanctum 가드에 세운다. 비회원이면 손님으로 부른다.
 * - 끝나면 가드·`sanctum.guard`·기본 가드·`request()`·로케일·시간대를 원래대로 돌린다.
 * - 401·403·428 등 상태는 그대로 돌려준다.
 *
 * @since 7.2.0
 */
class InternalApiClient
{
    /** 진행 중인 하위 요청 깊이 (재호출 차단) */
    private static int $depth = 0;

    /**
     * GET 요청.
     *
     * @param  string  $path  `/api/...` 경로
     * @param  array<string, mixed>  $query  쿼리
     * @return InternalApiResponse 응답
     */
    public function get(string $path, array $query = []): InternalApiResponse
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * POST 요청.
     *
     * @param  string  $path  `/api/...` 경로
     * @param  array<string, mixed>  $data  본문
     * @return InternalApiResponse 응답
     */
    public function post(string $path, array $data = []): InternalApiResponse
    {
        return $this->send('POST', $path, $data);
    }

    /**
     * PUT 요청.
     *
     * @param  string  $path  `/api/...` 경로
     * @param  array<string, mixed>  $data  본문
     * @return InternalApiResponse 응답
     */
    public function put(string $path, array $data = []): InternalApiResponse
    {
        return $this->send('PUT', $path, $data);
    }

    /**
     * DELETE 요청.
     *
     * @param  string  $path  `/api/...` 경로
     * @param  array<string, mixed>  $data  본문
     * @return InternalApiResponse 응답
     */
    public function delete(string $path, array $data = []): InternalApiResponse
    {
        return $this->send('DELETE', $path, $data);
    }

    /**
     * 하위 요청을 보냅니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  `/api/...` 경로
     * @param  array<string, mixed>  $parameters  쿼리(GET) 또는 본문
     * @return InternalApiResponse 응답
     *
     * @throws \InvalidArgumentException 거부 대상 경로면
     * @throws \LogicException 하위 요청 안에서 다시 부르면
     */
    public function send(string $method, string $path, array $parameters = []): InternalApiResponse
    {
        if (! str_starts_with($path, '/api/')) {
            throw new \InvalidArgumentException(__('settings.custom_reserved.errors.internal_api_path', ['path' => $path]));
        }
        if (self::$depth > 0) {
            throw new \LogicException(__('settings.custom_reserved.errors.internal_api_nested'));
        }

        $app = app();
        $outer = $app->bound('request') ? $app->make('request') : Request::capture();
        $sub = $this->buildRequest($outer, strtoupper($method), $path, $parameters);
        $this->assertAllowedRoute($sub);

        $user = ScreenAuth::user();
        $token = ScreenAuth::token();

        $previousSanctumGuard = config('sanctum.guard');
        $previousDefaultGuard = Auth::getDefaultDriver();
        $previousLocale = $app->getLocale();
        $previousTimezone = $app->bound(SetTimezone::TIMEZONE_KEY) ? $app->make(SetTimezone::TIMEZONE_KEY) : null;
        $router = $app->make('router');
        $previousRoute = $router->current();
        $previousRouterRequest = $router->getCurrentRequest();

        self::$depth++;
        try {
            Auth::forgetGuards();
            config(['sanctum.guard' => []]);
            Auth::shouldUse('sanctum');
            if ($user !== null && $token !== null) {
                Auth::guard('sanctum')->setUser($user->withAccessToken($token));
            }

            $response = $app->make(HttpKernel::class)->handle($sub);
            // 스트리밍 응답도 본문을 여기서 확정한다
            $response->getContent();

            return new InternalApiResponse($response);
        } finally {
            self::$depth--;

            config(['sanctum.guard' => $previousSanctumGuard]);
            Auth::forgetGuards();
            Auth::shouldUse($previousDefaultGuard);
            $app->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            // 하위 요청 디스패치가 바꾼 라우터의 「현재 라우트」를 바깥 요청 것으로 되돌린다
            (function () use ($previousRoute, $previousRouterRequest): void {
                $this->current = $previousRoute;
                $this->currentRequest = $previousRouterRequest;
            })->call($router);
            $app->setLocale($previousLocale);
            if ($previousTimezone !== null) {
                $app->instance(SetTimezone::TIMEZONE_KEY, $previousTimezone);
            }
        }
    }

    /**
     * 바깥 요청을 바탕으로 하위 요청을 만듭니다.
     *
     * @param  Request  $outer  바깥 요청
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  경로
     * @param  array<string, mixed>  $parameters  쿼리 또는 본문
     * @return Request 하위 요청
     */
    private function buildRequest(Request $outer, string $method, string $path, array $parameters): Request
    {
        $cookies = [];
        $bypass = $outer->cookies->get('laravel_maintenance');
        if (is_string($bypass) && $bypass !== '') {
            $cookies['laravel_maintenance'] = $bypass;
        }

        $server = [
            'REMOTE_ADDR' => $outer->server('REMOTE_ADDR', '127.0.0.1'),
            'HTTP_USER_AGENT' => (string) $outer->userAgent(),
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => app()->getLocale(),
        ];

        $uri = $outer->getSchemeAndHttpHost().$path;
        $sub = Request::create($uri, $method, $parameters, $cookies, [], $server);
        // 프록시 뒤 실제 접속 IP 를 그대로 — 바깥 요청이 이미 신뢰 프록시로 해석한 값을 넘긴다
        $sub->server->set('REMOTE_ADDR', $outer->ip() ?? $server['REMOTE_ADDR']);

        return $sub;
    }

    /**
     * 세션을 여는 인증 라우트는 거부합니다.
     *
     * @param  Request  $sub  하위 요청
     * @return void 반환값 없음
     *
     * @throws \InvalidArgumentException 거부 대상이면
     */
    private function assertAllowedRoute(Request $sub): void
    {
        try {
            $route = app('router')->getRoutes()->match($sub);
        } catch (\Throwable) {
            return; // 없는 경로는 코어가 404 로 응답하게 둔다
        }

        if (in_array('start.api.session', (array) $route->gatherMiddleware(), true)) {
            throw new \InvalidArgumentException(__('settings.custom_reserved.errors.internal_api_session_route', ['path' => $sub->getPathInfo()]));
        }
    }
}
