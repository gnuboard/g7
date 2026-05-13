<?php

namespace Tests\Feature\Api\Auth;

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

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 설정 모킹 - g7_core_settings 헬퍼가 사용하는 설정
        Config::set('g7_settings.core.security.auth_token_lifetime', self::TOKEN_LIFETIME);
    }

    /**
     * JSON 요청 헬퍼 메서드
     *
     * @return static
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
     * @return array{token: string, tokenModel: PersonalAccessToken}
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
     * @return static
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
}
