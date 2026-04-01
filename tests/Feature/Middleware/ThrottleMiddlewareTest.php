<?php

namespace Tests\Feature\Middleware;

use App\Enums\ExtensionOwnerType;
use App\Models\Role;
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
     * 실제 API 라우트에 throttle이 적용되었는지 확인합니다.
     */
    public function test_actual_api_routes_have_throttle_applied(): void
    {
        // 인증 라우트에 throttle 적용 확인
        $route = Route::getRoutes()->getByName('api.auth.login');
        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->middleware());
    }

    /**
     * 관리자 라우트에 throttle이 적용되었는지 확인합니다.
     */
    public function test_admin_routes_have_throttle_applied(): void
    {
        // 관리자 역할 생성
        $adminRole = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '시스템 관리자', 'en' => 'System administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $adminUser = User::factory()->create();
        $adminUser->roles()->attach($adminRole->id);

        // 관리자 라우트에 throttle 적용 확인
        $route = Route::getRoutes()->getByName('api.admin.auth.user');
        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->middleware());

        // 실제 요청으로 확인
        $response = $this->actingAs($adminUser)
            ->getJson('/api/admin/auth/user');

        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit');
    }

    /**
     * 사용자 라우트에 throttle이 적용되었는지 확인합니다.
     */
    public function test_user_routes_have_throttle_applied(): void
    {
        // 사용자 라우트에 throttle 적용 확인
        $route = Route::getRoutes()->getByName('api.user.auth.user');
        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->middleware());

        // 실제 요청으로 확인
        $response = $this->actingAs($this->testUser)
            ->getJson('/api/user/auth/user');

        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit');
    }
}
