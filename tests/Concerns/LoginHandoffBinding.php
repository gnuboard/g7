<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Testing\TestResponse;

/**
 * 브라우저에 묶인 로그인 인계 코드의 발급·교환 시험 도우미 (#798 K2)
 *
 * `LoginHandoffService::issue()` 는 묶음 쿠키(`g7_login_bind`)를 쿠키 대기열에 넣는다. 시험은 그 값을 꺼내 교환 요청에 싣는다 —
 * 쿠키는 암호화 제외 대상이라 `withUnencryptedCookie()` 로, JSON 요청은 `withCredentials()` 가 있어야 쿠키를 싣는다.
 */
trait LoginHandoffBinding
{
    /**
     * 묶인 인계 코드를 발급하고 대기열의 묶음 쿠키 값을 꺼냅니다 (다음 요청으로 새지 않게 대기열에서 뺀다).
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  로그인 문맥
     * @param  string  $guard  로그인 경로
     * @return array{code: string, bind: string|null} 코드와 묶음 쿠키 값
     */
    protected function issueBoundHandoff(User $user, array $context = ['login_method' => 'social:test'], string $guard = 'user'): array
    {
        $code = app(LoginHandoffService::class)->issue($user, $context, $guard);
        $cookie = Cookie::queued(LoginHandoffService::BIND_COOKIE);
        Cookie::unqueue(LoginHandoffService::BIND_COOKIE);

        return ['code' => $code, 'bind' => $cookie?->getValue()];
    }

    /**
     * 인계 코드를 교환합니다.
     *
     * @param  string  $code  인계 코드
     * @param  string|null  $bind  묶음 쿠키 값 (null 이면 싣지 않음)
     * @param  string  $guard  교환 경로 (user | admin)
     * @param  array<string, string>  $headers  요청 헤더
     * @return TestResponse 응답
     */
    protected function exchangeHandoff(string $code, ?string $bind, string $guard = 'user', array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = [];

        $request = $this->withHeaders($headers)->withCredentials();

        if ($bind !== null) {
            $request = $request->withUnencryptedCookie(LoginHandoffService::BIND_COOKIE, $bind);
        }

        $uri = $guard === 'admin' ? '/api/auth/admin/login/handoff' : '/api/auth/login/handoff';

        return $request->postJson($uri, ['code' => $code]);
    }
}
