<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ThrottleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 사용자 생성
        $this->testUser = User::factory()->create();

        // 테스트 라우트 등록 (throttle:5,1 = 1분당 5회 제한)
        Route::middleware(['api', 'throttle:5,1'])->get('/api/test-throttle', function () {
            return response()->json(['message' => 'Request successful']);
        });
    }

    /**
     * 속도 제한 내 요청은 정상적으로 처리되어야 합니다.
     */
    public function test_requests_within_rate_limit_are_successful(): void
    {
        // 5회 요청 (제한 내)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson('/api/test-throttle');
            $response->assertStatus(200)
                ->assertJson(['message' => 'Request successful']);
        }
    }

    /**
     * 속도 제한을 초과한 요청은 429 응답을 받아야 합니다.
     */
    public function test_requests_exceeding_rate_limit_receive_429(): void
    {
        // 5회 요청 (제한 내)
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/test-throttle');
        }

        // 6번째 요청 (제한 초과)
        $response = $this->getJson('/api/test-throttle');
        $response->assertStatus(429);
    }

    /**
     * Rate Limit 관련 헤더가 응답에 포함되어야 합니다.
     */
    public function test_rate_limit_headers_are_present(): void
    {
        $response = $this->getJson('/api/test-throttle');

        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');
    }

    /**
     * Rate Limit 헤더 값이 올바르게 감소해야 합니다.
     */
    public function test_rate_limit_headers_decrement_correctly(): void
    {
        // 첫 번째 요청
        $response = $this->getJson('/api/test-throttle');
        $response->assertStatus(200);
        $remaining1 = $response->headers->get('X-RateLimit-Remaining');

        // 두 번째 요청
        $response = $this->getJson('/api/test-throttle');
        $response->assertStatus(200);
        $remaining2 = $response->headers->get('X-RateLimit-Remaining');

        // Remaining이 감소해야 함
        $this->assertLessThan((int) $remaining1, (int) $remaining2);
    }

    /**
     * 인증된 사용자의 속도 제한도 정상 동작해야 합니다.
     */
    public function test_authenticated_user_throttle_works(): void
    {
        // 5회 요청 (제한 내)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->testUser)
                ->getJson('/api/test-throttle');
            $response->assertStatus(200);
        }

        // 6번째 요청 (제한 초과)
        $response = $this->actingAs($this->testUser)
            ->getJson('/api/test-throttle');
        $response->assertStatus(429);
    }

    /**
     * 사용자/관리자 라우트에 throttle 이 적용되어 있는지 미들웨어 목록으로 확인합니다.
     *
     * 참고: Route::middleware() 는 alias 를 FQCN 으로 resolve 한 결과를 반환하므로
     * 'throttle:600,1' 문자열 일치 대신 부분 매칭(ThrottleRequests/throttle 키워드)을 사용한다.
     * 로그인 라우트(api.auth.login)에는 throttle 이 적용되지 않는다 — 현재 정책.
     */
    public function test_throttle_is_applied_to_authenticated_api_routes(): void
    {
        $userRoute = Route::getRoutes()->getByName('api.user.auth.user');
        $adminRoute = Route::getRoutes()->getByName('api.admin.auth.user');
        $this->assertNotNull($userRoute);
        $this->assertNotNull($adminRoute);

        $this->assertThrottleMiddlewareApplied($userRoute->middleware(), 'api.user.auth.user');
        $this->assertThrottleMiddlewareApplied($adminRoute->middleware(), 'api.admin.auth.user');
    }

    /**
     * 미들웨어 배열에서 throttle(ThrottleRequests) 적용 여부를 확인합니다.
     *
     * @param  array<string>  $middleware
     */
    private function assertThrottleMiddlewareApplied(array $middleware, string $routeName): void
    {
        $hasThrottle = false;
        foreach ($middleware as $m) {
            if (str_contains($m, 'ThrottleRequests') || str_starts_with($m, 'throttle:')) {
                $hasThrottle = true;
                break;
            }
        }
        $this->assertTrue($hasThrottle, "{$routeName} 라우트에 throttle 미들웨어가 적용되어야 합니다");
    }
}
