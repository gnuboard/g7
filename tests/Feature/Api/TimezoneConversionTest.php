<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 시딩 실행
        $this->seed();
    }

    /**
     * 사용자 timezone 기본값이 Asia/Seoul인지 테스트합니다.
     */
    public function test_user_timezone_defaults_to_asia_seoul(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->assertEquals('Asia/Seoul', $user->getTimezone());
    }

    /**
     * 사용자가 설정한 timezone이 반환되는지 테스트합니다.
     */
    public function test_user_timezone_returns_set_value(): void
    {
        $user = User::factory()->create(['timezone' => 'America/New_York']);

        $this->assertEquals('America/New_York', $user->getTimezone());
    }

    /**
     * API 응답에서 datetime이 사용자 timezone으로 변환되는지 테스트합니다.
     *
     * Note: 이 테스트는 BaseApiResourceTimezoneTest에서 단위 테스트로 검증합니다.
     * User API가 themes 테이블 의존성으로 인해 통합 테스트가 어렵습니다.
     */
    public function test_api_response_datetime_converted_to_user_timezone(): void
    {
        // UTC 기준 시간 설정
        Carbon::setTestNow(Carbon::parse('2025-01-15 00:00:00', 'UTC'));

        // Asia/Seoul 사용자 생성 (UTC+9)
        $user = User::factory()->create([
            'timezone' => 'Asia/Seoul',
            'email_verified_at' => Carbon::now('UTC'),
        ]);

        // 사용자 역할 부여
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        $this->assertNotNull($adminRole, 'Admin role should exist');

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => $user->id,
        ]);

        // User 목록 API로 테스트 (themes 테이블 의존성 없음)
        $response = $this->actingAs($user)->getJson('/api/admin/users');
        $response->assertStatus(200);

        // 페이지네이션 응답 구조: data.data 또는 data
        $responseData = $response->json('data');
        $users = $responseData['data'] ?? $responseData;
        $userData = collect($users)->firstWhere('uuid', $user->uuid);
        $this->assertNotNull($userData, 'User not found in response');

        // Asia/Seoul은 UTC+9이므로 09:00:00 이어야 함
        // Y-m-d H:i:s 형식으로 반환됨 (timezone offset 없음)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $userData['email_verified_at']);
        $emailVerifiedAt = Carbon::parse($userData['email_verified_at']);
        $this->assertEquals(9, $emailVerifiedAt->hour);

        Carbon::setTestNow();
    }

    /**
     * 다른 timezone 사용자의 API 응답이 올바르게 변환되는지 테스트합니다.
     */
    public function test_api_response_datetime_converted_to_different_timezone(): void
    {
        // UTC 기준 시간 설정
        Carbon::setTestNow(Carbon::parse('2025-01-15 12:00:00', 'UTC'));

        // America/New_York 사용자 생성 (UTC-5)
        $user = User::factory()->create([
            'timezone' => 'America/New_York',
            'email_verified_at' => Carbon::now('UTC'),
        ]);

        // 사용자 역할 부여
        $adminRole = \App\Models\Role::where('identifier', 'admin')->first();
        $this->assertNotNull($adminRole, 'Admin role should exist');

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => $user->id,
        ]);

        // User 목록 API로 테스트
        $response = $this->actingAs($user)->getJson('/api/admin/users');
        $response->assertStatus(200);

        // 페이지네이션 응답 구조: data.data 또는 data
        $responseData = $response->json('data');
        $users = $responseData['data'] ?? $responseData;
        $userData = collect($users)->firstWhere('uuid', $user->uuid);
        $this->assertNotNull($userData, 'User not found in response');

        // America/New_York는 UTC-5이므로 07:00:00 이어야 함
        // Y-m-d H:i:s 형식으로 반환됨 (timezone offset 없음)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $userData['email_verified_at']);
        $emailVerifiedAt = Carbon::parse($userData['email_verified_at']);
        $this->assertEquals(7, $emailVerifiedAt->hour);

        Carbon::setTestNow();
    }

    /**
     * config에서 지원하는 timezone 목록이 올바른지 테스트합니다.
     */
    public function test_supported_timezones_config(): void
    {
        $supportedTimezones = config('app.supported_timezones');

        $this->assertIsArray($supportedTimezones);
        $this->assertContains('Asia/Seoul', $supportedTimezones);
        $this->assertContains('Asia/Tokyo', $supportedTimezones);
        $this->assertContains('America/New_York', $supportedTimezones);
        $this->assertContains('UTC', $supportedTimezones);
    }

    /**
     * 기본 사용자 timezone config가 올바른지 테스트합니다.
     */
    public function test_default_user_timezone_config(): void
    {
        $this->assertEquals('Asia/Seoul', config('app.default_user_timezone'));
    }

    /**
     * 앱 timezone이 UTC인지 테스트합니다.
     */
    public function test_app_timezone_is_utc(): void
    {
        $this->assertEquals('UTC', config('app.timezone'));
    }
}
