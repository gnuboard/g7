<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 401 응답 시 세션 쿠키 정리 테스트
 *
 * 토큰 만료로 AuthenticationException 발생 시,
 * 잔존 세션 쿠키를 만료시켜 보안 공백을 방지하는 동작을 검증합니다.
 */
class SessionCleanupOnUnauthorizedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 세션 쿠키가 있는 상태에서 401 응답 시 세션 쿠키 만료 헤더가 포함되는지 확인
     */
    public function test_401_response_includes_forget_session_cookie_when_session_cookie_exists(): void
    {
        $sessionName = config('session.cookie');

        // 세션 쿠키를 가진 상태에서 인증 없이 API 요청
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->withCookie($sessionName, 'fake-session-value')
            ->getJson('/api/auth/user');

        $response->assertStatus(401);

        // 응답에 세션 쿠키 만료 Set-Cookie 헤더가 포함되어야 함
        $cookies = $response->headers->getCookies();
        $sessionCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $sessionCookie = $cookie;
                break;
            }
        }

        $this->assertNotNull($sessionCookie, '401 응답에 세션 쿠키 만료 헤더가 포함되어야 합니다.');
        $this->assertTrue(
            $sessionCookie->getExpiresTime() < time(),
            '세션 쿠키의 만료 시간이 과거여야 합니다 (쿠키 삭제).'
        );
    }

    /**
     * 세션 쿠키가 없는 상태에서도 401 응답 시 세션 쿠키 만료 헤더가 포함되는지 확인
     *
     * 무조건적 forget cookie 방식: 클라이언트에 쿠키가 없어도 Set-Cookie 헤더는 무해함
     */
    public function test_401_response_includes_forget_cookie_even_without_session_cookie(): void
    {
        $sessionName = config('session.cookie');

        // 세션 쿠키 없이 인증 없이 API 요청
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/auth/user');

        $response->assertStatus(401);

        // 무조건적 forget이므로 세션 쿠키 만료 헤더가 항상 포함됨
        $cookies = $response->headers->getCookies();
        $sessionCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $sessionCookie = $cookie;
                break;
            }
        }

        $this->assertNotNull($sessionCookie, '401 응답에는 항상 세션 쿠키 만료 헤더가 포함되어야 합니다.');
        $this->assertTrue(
            $sessionCookie->getExpiresTime() < time(),
            '세션 쿠키의 만료 시간이 과거여야 합니다 (쿠키 삭제).'
        );
    }

    /**
     * 만료된 토큰으로 요청 시 401 + 세션 쿠키 정리 확인
     */
    public function test_expired_token_returns_401_with_session_cleanup(): void
    {
        $user = User::factory()->create();
        $sessionName = config('session.cookie');

        // 이미 만료된 토큰 생성
        $token = $user->createToken('test-token', ['*'], now()->subMinute())->plainTextToken;

        // 세션 쿠키 + 만료된 토큰으로 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->withCookie($sessionName, 'fake-session-value')
            ->getJson('/api/auth/user');

        $response->assertStatus(401);

        // 세션 쿠키 만료 헤더 확인
        $cookies = $response->headers->getCookies();
        $sessionCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $sessionCookie = $cookie;
                break;
            }
        }

        $this->assertNotNull($sessionCookie, '만료된 토큰 + 세션 쿠키 → 세션 쿠키 만료 헤더 포함.');
    }

    /**
     * 유효한 토큰으로 요청 시 세션 쿠키 만료 헤더가 포함되지 않는지 확인
     */
    public function test_valid_token_does_not_trigger_session_cleanup(): void
    {
        $user = User::factory()->create();
        $sessionName = config('session.cookie');

        // 유효한 토큰 생성
        $token = $user->createToken('test-token', ['*'], now()->addHour())->plainTextToken;

        // 세션 쿠키 + 유효한 토큰으로 요청
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->withCookie($sessionName, 'fake-session-value')
            ->getJson('/api/auth/user');

        $response->assertStatus(200);

        // 세션 쿠키 만료 헤더가 없어야 함
        $cookies = $response->headers->getCookies();
        $sessionCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $sessionCookie = $cookie;
                break;
            }
        }

        $this->assertNull($sessionCookie, '유효한 토큰 요청에서는 세션 쿠키를 건드리지 않아야 합니다.');
    }

    /**
     * 401 응답의 메시지 형식 확인
     */
    public function test_401_response_returns_proper_json_format(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/auth/user');

        $response->assertStatus(401)
            ->assertJsonStructure(['message']);
    }
}
