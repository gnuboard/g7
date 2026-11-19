<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 토큰 만료 슬라이딩 갱신 테스트
 *
 * RefreshTokenExpiration 미들웨어의 동작을 테스트합니다.
 * - 토큰 만료까지 남은 시간이 절반 미만이면 만료 시간 갱신
 * - 절반 이상 남았으면 갱신하지 않음
 */
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트용 토큰 유지시간 (분)
     */
    private const TOKEN_LIFETIME = 60;

    /**
     * 테스트 공통 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 설정 모킹 - g7_core_settings 헬퍼가 사용하는 설정
        Config::set('g7_settings.core.security.auth_token_lifetime', self::TOKEN_LIFETIME);
    }

    /**
     * JSON 요청 헬퍼 메서드
     *
     * @return static 헤더를 붙인 테스트 요청
     */
    private function jsonRequest(): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 특정 만료 시간을 가진 토큰으로 인증된 요청 생성
     *
     * @param  User  $user  사용자
     * @param  \DateTimeInterface|null  $expiresAt  토큰 만료 시간
     * @return array{token: string, tokenModel: PersonalAccessToken} 평문 토큰과 토큰 모델
     */
    private function createTokenWithExpiration(User $user, ?\DateTimeInterface $expiresAt): array
    {
        $token = $user->createToken('test-token', ['*'], $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'tokenModel' => $token->accessToken,
        ];
    }

    /**
     * 토큰으로 인증된 요청 생성
     *
     * @param  string  $token  Bearer 토큰
     * @return static 헤더를 붙인 테스트 요청
     */
    private function authRequest(string $token): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 슬라이딩 만료 갱신 테스트
    // ========================================================================

    /**
     * 토큰 만료까지 절반 미만 남았을 때 만료 시간이 갱신됨
     *
     * @effects sliding_expiration_extends_below_half
     *
     * @return void 반환값 없음
     */
    public function test_token_expiration_is_extended_when_less_than_half_remaining(): void
    {
        $user = User::factory()->create();

        // 토큰 유지시간의 절반 미만(25분) 남은 토큰 생성
        $expiresAt = now()->addMinutes(25);
        $tokenData = $this->createTokenWithExpiration($user, $expiresAt);

        $originalExpiresAt = $tokenData['tokenModel']->expires_at;

        // API 요청 (인증 필요한 아무 엔드포인트)
        $response = $this->authRequest($tokenData['token'])->getJson('/api/auth/user');
        $response->assertStatus(200);

        // 토큰 만료 시간이 갱신되었는지 확인
        $tokenData['tokenModel']->refresh();
        $newExpiresAt = $tokenData['tokenModel']->expires_at;

        // 새 만료 시간은 원래 만료 시간보다 나중이어야 함
        $this->assertTrue(
            $newExpiresAt->greaterThan($originalExpiresAt),
            '토큰 만료 시간이 갱신되어야 합니다.'
        );

        // 새 만료 시간은 현재 시간 + 설정값(60분)과 거의 같아야 함 (1분 오차 허용)
        $expectedExpiresAt = now()->addMinutes(self::TOKEN_LIFETIME);
        $this->assertTrue(
            abs($newExpiresAt->diffInMinutes($expectedExpiresAt)) <= 1,
            '새 만료 시간은 현재 시간 + 설정값이어야 합니다.'
        );
    }

    /**
     * 토큰 만료까지 절반 이상 남았을 때 만료 시간이 유지됨
     *
     * @effects sliding_expiration_keeps_above_half
     *
     * @return void 반환값 없음
     */
    public function test_token_expiration_is_not_extended_when_more_than_half_remaining(): void
    {
        $user = User::factory()->create();

        // 토큰 유지시간의 절반 이상(35분) 남은 토큰 생성
        $expiresAt = now()->addMinutes(35);
        $tokenData = $this->createTokenWithExpiration($user, $expiresAt);

        $originalExpiresAt = $tokenData['tokenModel']->expires_at->toDateTimeString();

        // API 요청
        $response = $this->authRequest($tokenData['token'])->getJson('/api/auth/user');
        $response->assertStatus(200);

        // 토큰 만료 시간이 변경되지 않았는지 확인
        $tokenData['tokenModel']->refresh();
        $newExpiresAt = $tokenData['tokenModel']->expires_at->toDateTimeString();

        $this->assertEquals(
            $originalExpiresAt,
            $newExpiresAt,
            '토큰 만료 시간이 변경되지 않아야 합니다.'
        );
    }

    /**
     * 토큰 만료까지 절반보다 약간 더 남았을 때 만료 시간이 유지됨 (경계값 테스트)
     *
     * @effects sliding_expiration_keeps_above_half
     *
     * @return void 반환값 없음
     */
    public function test_token_expiration_is_not_extended_at_slightly_above_half(): void
    {
        $user = User::factory()->create();

        // 절반보다 1분 더 남은(31분) 토큰 생성
        $expiresAt = now()->addMinutes(31);
        $tokenData = $this->createTokenWithExpiration($user, $expiresAt);

        $originalExpiresAt = $tokenData['tokenModel']->expires_at->toDateTimeString();

        // API 요청
        $response = $this->authRequest($tokenData['token'])->getJson('/api/auth/user');
        $response->assertStatus(200);

        // 토큰 만료 시간이 변경되지 않았는지 확인
        $tokenData['tokenModel']->refresh();
        $newExpiresAt = $tokenData['tokenModel']->expires_at->toDateTimeString();

        $this->assertEquals(
            $originalExpiresAt,
            $newExpiresAt,
            '절반보다 약간 더 남았을 때는 갱신되지 않아야 합니다.'
        );
    }

    /**
     * 만료 시간이 없는 토큰(무한대)은 갱신하지 않음
     *
     * @effects token_without_expiry_untouched
     *
     * @return void 반환값 없음
     */
    public function test_token_without_expiration_is_not_modified(): void
    {
        $user = User::factory()->create();

        // 만료 시간 없는 토큰 생성
        $tokenData = $this->createTokenWithExpiration($user, null);

        // API 요청
        $response = $this->authRequest($tokenData['token'])->getJson('/api/auth/user');
        $response->assertStatus(200);

        // 토큰 만료 시간이 여전히 null인지 확인
        $tokenData['tokenModel']->refresh();
        $this->assertNull(
            $tokenData['tokenModel']->expires_at,
            '무한대 토큰의 만료 시간은 null로 유지되어야 합니다.'
        );
    }

    /**
     * 비인증 요청은 정상 처리됨
     *
     * @effects token_without_expiry_untouched
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_request_is_handled_normally(): void
    {
        // 인증 없이 공개 API 요청
        $response = $this->jsonRequest()->getJson('/api/templates/sirsoft-admin_basic/routes.json');

        // 요청이 정상 처리되어야 함 (200 또는 404 등 - 에러가 아닌 정상 응답)
        $this->assertNotEquals(500, $response->status(), '서버 에러가 발생하지 않아야 합니다.');
    }

    /**
     * 이미 만료된 토큰은 401 반환
     *
     * @effects expired_token_rejected
     *
     * @return void 반환값 없음
     */
    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();

        // 이미 만료된 토큰 생성 (1분 전에 만료)
        $expiresAt = now()->subMinute();
        $tokenData = $this->createTokenWithExpiration($user, $expiresAt);

        // API 요청
        $response = $this->authRequest($tokenData['token'])->getJson('/api/auth/user');

        // 401 반환 확인
        $response->assertStatus(401);
    }

    /**
     * 갱신 후 다시 절반 미만이 되면 다시 갱신됨
     *
     * @effects sliding_expiration_extends_below_half, sliding_expiration_keeps_above_half
     *
     * @return void 반환값 없음
     */
    public function test_token_is_extended_again_when_below_threshold(): void
    {
        $user = User::factory()->create();

        // 25분 남은 토큰 생성 (갱신 대상)
        $expiresAt = now()->addMinutes(25);
        $tokenData = $this->createTokenWithExpiration($user, $expiresAt);

        $originalExpiresAt = $tokenData['tokenModel']->expires_at;

        // 첫 번째 요청 - 갱신됨
        $this->authRequest($tokenData['token'])->getJson('/api/auth/user');

        $tokenData['tokenModel']->refresh();
        $firstExtendedExpiresAt = $tokenData['tokenModel']->expires_at;

        // 첫 번째 갱신이 이루어졌는지 확인
        $this->assertTrue(
            $firstExtendedExpiresAt->greaterThan($originalExpiresAt),
            '첫 번째 요청에서 만료 시간이 갱신되어야 합니다.'
        );

        // 갱신 후에는 60분이 남았으므로 (절반 이상) 두 번째 요청에서는 갱신 안 됨
        $this->authRequest($tokenData['token'])->getJson('/api/auth/user');

        $tokenData['tokenModel']->refresh();
        $afterSecondRequest = $tokenData['tokenModel']->expires_at;

        // 두 번째 요청 후에도 만료 시간이 같아야 함 (갱신 안 됨)
        $this->assertEquals(
            $firstExtendedExpiresAt->toDateTimeString(),
            $afterSecondRequest->toDateTimeString(),
            '절반 이상 남았을 때는 갱신되지 않아야 합니다.'
        );
    }

    // ========================================================================
    // 폐기한 토큰이 슬라이딩 갱신으로 되살아나지 않는다 (KVE-2026-3187)
    // ========================================================================

    /**
     * 수명이 절반 미만 남은 토큰으로 로그아웃해도 그 토큰이 다시 통하지 않는다.
     *
     * 수정 전: 미들웨어가 컨트롤러 뒤에 같은 토큰 객체를 save() 해 삭제된 행이 INSERT 로 되살아났다.
     *
     * @effects revoked_token_not_resurrected
     *
     * @return void 반환값 없음
     */
    public function test_logout_below_threshold_does_not_resurrect_token(): void
    {
        $user = User::factory()->create();
        $tokenData = $this->createTokenWithExpiration($user, now()->addMinutes(5));
        $tokenId = $tokenData['tokenModel']->id;

        $this->authRequest($tokenData['token'])->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $this->app['auth']->forgetGuards();
        $this->authRequest($tokenData['token'])->getJson('/api/auth/user')->assertUnauthorized();
    }

    /**
     * 수명이 절반 미만 남은 사용자 토큰을 갱신하면 옛 토큰은 지워진 채로 남는다.
     *
     * @effects revoked_token_not_resurrected, refreshed_new_token_works
     *
     * @return void 반환값 없음
     */
    public function test_refresh_below_threshold_does_not_resurrect_old_token(): void
    {
        $user = User::factory()->create();
        $tokenData = $this->createTokenWithExpiration($user, now()->addMinutes(5));
        $tokenId = $tokenData['tokenModel']->id;

        $response = $this->authRequest($tokenData['token'])->postJson('/api/user/auth/refresh');
        $response->assertOk();
        $newToken = $response->json('data.token');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $this->app['auth']->forgetGuards();
        $this->authRequest($tokenData['token'])->getJson('/api/auth/user')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->authRequest($newToken)->getJson('/api/auth/user')->assertOk();
    }

    /**
     * 관리자 경로 갱신도 옛 토큰을 되살리지 않는다.
     *
     * @effects revoked_token_not_resurrected
     *
     * @return void 반환값 없음
     */
    public function test_admin_refresh_below_threshold_does_not_resurrect_old_token(): void
    {
        $admin = $this->createAdminUser();
        $tokenData = $this->createTokenWithExpiration($admin, now()->addMinutes(5));
        $tokenId = $tokenData['tokenModel']->id;

        $this->authRequest($tokenData['token'])->postJson('/api/admin/auth/refresh')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $this->app['auth']->forgetGuards();
        $this->authRequest($tokenData['token'])->getJson('/api/admin/auth/user')->assertUnauthorized();
    }

    /**
     * 관리자 경로 로그아웃도 옛 토큰을 되살리지 않는다.
     *
     * @effects revoked_token_not_resurrected
     *
     * @return void 반환값 없음
     */
    public function test_admin_logout_below_threshold_does_not_resurrect_token(): void
    {
        $admin = $this->createAdminUser();
        $tokenData = $this->createTokenWithExpiration($admin, now()->addMinutes(5));
        $tokenId = $tokenData['tokenModel']->id;

        $this->authRequest($tokenData['token'])->postJson('/api/admin/auth/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $this->app['auth']->forgetGuards();
        $this->authRequest($tokenData['token'])->getJson('/api/admin/auth/user')->assertUnauthorized();
    }

    /**
     * 관리자 역할을 가진 회원을 만듭니다 (AdminAuthTest 와 같은 최소 구성).
     *
     * @return User 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator'], 'description' => ['ko' => '-', 'en' => '-']]
        );
        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.test_default'],
            ['name' => ['ko' => 'admin default', 'en' => 'admin default'], 'description' => ['ko' => '-', 'en' => '-'], 'type' => 'admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
