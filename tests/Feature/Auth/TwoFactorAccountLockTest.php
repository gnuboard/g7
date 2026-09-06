<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 2단계 인증 완료 지점의 계정 잠금 재검사 테스트.
 *
 * 계정 잠금은 비밀번호 확인 단계(`login`)에서만 검사되고 있었습니다. 그런데 2단계 인증이
 * 켜져 있으면 `login` 은 토큰 대신 challenge 만 돌려주고, 실제 세션은 `login/two-factor`
 * 가 발급합니다. 그 두 번째 지점에 잠금 검사가 없으면 **잠기기 전에 발급받은 challenge 를
 * 잠긴 뒤에 완료**하는 것만으로 잠금이 통째로 우회됩니다.
 *
 * 게다가 로그인 완료 훅(`core.auth.after_login`)이 실패 횟수·잠금 시각을 초기화하므로,
 * 우회에 성공하면 잠금 자체가 해제되어 흔적도 남지 않습니다.
 *
 * 불변조건: **세션(토큰)을 발급하는 모든 지점은 잠금을 재검사한다.**
 */
class TwoFactorAccountLockTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'two-factor-lock@test.com';

    private const PASSWORD = 'Passw0rd!2fa';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // 인증번호 메일 구성에 본인인증 메시지 정의가 필요하다. 없으면 challenge 가 발송
        // 실패로 표시되어, 잠금 게이트가 아니라 시드 부재를 측정하게 된다.
        $this->seed(IdentityMessageDefinitionSeeder::class);

        $this->setSecuritySettings([
            'two_factor_auth' => true,
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 30,
        ]);

        Cache::flush();

        $this->user = User::factory()->create([
            'email' => self::EMAIL,
            'password' => Hash::make(self::PASSWORD),
            'status' => UserStatus::Active->value,
        ]);
    }

    /**
     * 잠금 전에 발급된 2단계 인증 challenge 로는 잠긴 계정에 로그인할 수 없다.
     */
    #[Test]
    public function a_challenge_issued_before_the_lock_cannot_complete_login_after_the_lock(): void
    {
        // 1. 정상 비밀번호로 challenge 를 확보한다 (아직 잠기지 않은 상태).
        $challengeId = $this->login()->json('data.challenge_id');
        $this->assertNotEmpty($challengeId, '2단계 인증 challenge 가 발급되지 않았습니다.');

        $code = $this->issuedCode($challengeId);

        // 2. 임계값까지 실패시켜 계정을 잠근다.
        $this->lockAccount();

        // 3. 통제군 — 정상 로그인 경로는 잠금을 인지해 423 을 돌려준다.
        $this->login()->assertStatus(423);

        // 4. 잠금 전 challenge 로 2단계 인증을 완료하려 하면 같은 판정을 받아야 한다.
        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);

        $response->assertStatus(423);
        $this->assertNull(
            $response->json('data.token'),
            '잠긴 계정에 토큰이 발급되면 계정 잠금이 통째로 우회됩니다.'
        );
    }

    /**
     * 우회 시도는 잠금 상태를 초기화하지 않는다.
     *
     * 로그인 완료 훅이 실패 횟수·잠금 시각을 리셋하므로, 우회가 성공하면 공격의 흔적까지
     * 사라진다. 차단이 훅 이전에 일어나는지를 사후 상태로 확인한다.
     */
    #[Test]
    public function a_blocked_two_factor_attempt_does_not_reset_the_lock_state(): void
    {
        $challengeId = $this->login()->json('data.challenge_id');
        $code = $this->issuedCode($challengeId);

        $this->lockAccount();

        $locked = $this->user->fresh();
        $attemptsBefore = $locked->failed_login_attempts;
        $lockedUntilBefore = $locked->locked_until;
        $permanentBefore = $locked->locked_permanently;

        $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertStatus(423);

        $after = $this->user->fresh();

        $this->assertSame($attemptsBefore, $after->failed_login_attempts, '실패 횟수가 초기화되었습니다.');
        $this->assertEquals($lockedUntilBefore, $after->locked_until, '잠금 해제 시각이 변경되었습니다.');
        $this->assertEquals($permanentBefore, $after->locked_permanently, '영구 잠금 플래그가 변경되었습니다.');
        $this->assertSame(0, $after->tokens()->count(), '차단된 요청이 토큰을 남겼습니다.');
    }

    /**
     * 423 응답은 정상 로그인 경로와 같은 계약(해제 시각·잔여 시간·영구 여부)을 따른다.
     *
     * 화면은 두 경로를 구분하지 않으므로, 한쪽만 다른 모양이면 안내가 깨진다.
     */
    #[Test]
    public function the_locked_response_matches_the_login_endpoint_contract(): void
    {
        $challengeId = $this->login()->json('data.challenge_id');
        $code = $this->issuedCode($challengeId);

        $this->lockAccount();

        $loginResponse = $this->login()->assertStatus(423);

        $twoFactorResponse = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertStatus(423);

        $this->assertSame(
            array_keys((array) $loginResponse->json('errors')),
            array_keys((array) $twoFactorResponse->json('errors')),
            '두 경로의 423 응답 형태가 다릅니다.'
        );
        $this->assertFalse((bool) $twoFactorResponse->json('errors.permanent'));
        $this->assertNotNull($twoFactorResponse->json('errors.locked_until'));
    }

    /**
     * 잠기지 않은 계정의 2단계 인증은 그대로 완료된다 (정상 흐름 회귀 방지).
     */
    #[Test]
    public function an_unlocked_account_still_completes_two_factor_normally(): void
    {
        $challengeId = $this->login()->json('data.challenge_id');

        $response = $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $this->issuedCode($challengeId),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'), '정상 계정의 2단계 인증이 차단되었습니다.');
    }

    /**
     * 잠금 검사 설정이 꺼져 있으면 2단계 인증도 잠금을 보지 않는다 (설정 계약 일치).
     */
    #[Test]
    public function the_lock_recheck_follows_the_login_attempt_setting(): void
    {
        $challengeId = $this->login()->json('data.challenge_id');
        $code = $this->issuedCode($challengeId);

        $this->lockAccount();

        // 운영자가 잠금 기능을 끄면 정상 로그인이 통과하듯 2단계 인증도 통과해야 한다.
        $this->setSecuritySettings(['login_attempt_enabled' => false]);

        $this->postJson('/api/auth/login/two-factor', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertStatus(200);
    }

    /**
     * 잠긴 계정은 기존 세션으로도 토큰을 재발급받지 못한다.
     *
     * 형제 결함 — `refreshToken` 은 유효한 세션이 전제라 신규 로그인 우회는 아니지만,
     * 관리자가 계정을 잠근 뒤에도 그 세션이 무기한 연장되면 잠금이 실효를 잃는다.
     */
    #[Test]
    public function a_locked_account_cannot_refresh_its_token(): void
    {
        $this->grantRefreshPermission();
        $token = $this->user->createToken('test-token')->plainTextToken;

        $this->lockAccount();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/auth/refresh')
            ->assertStatus(423);
    }

    /**
     * 잠기지 않은 계정의 토큰 재발급은 그대로 동작한다 (정상 흐름 회귀 방지).
     */
    #[Test]
    public function an_unlocked_account_can_still_refresh_its_token(): void
    {
        $this->grantRefreshPermission();
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/auth/refresh');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 테스트 사용자에게 토큰 재발급 권한을 부여합니다.
     *
     * 재발급 라우트는 `permission:user,core.auth.refresh` 미들웨어를 거치므로, 권한이 없으면
     * 잠금 게이트에 닿기 전에 403 이 되어 측정 대상이 바뀐다.
     */
    private function grantRefreshPermission(): void
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.auth.refresh'],
            [
                'name' => json_encode(['ko' => '토큰 재발급', 'en' => 'Refresh Token']),
                'description' => json_encode(['ko' => '토큰 재발급', 'en' => 'Refresh Token']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'user',
            ]
        );

        $role = Role::create([
            'identifier' => 'two_factor_lock_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);

        $this->user->roles()->attach($role->id, ['assigned_at' => now()]);
        $this->user = $this->user->fresh();
    }

    /**
     * 비밀번호 확인 단계(1단계)를 수행합니다.
     *
     * @return TestResponse 로그인 응답
     */
    private function login()
    {
        return $this->postJson('/api/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * 임계값까지 로그인을 실패시켜 계정을 잠급니다.
     */
    private function lockAccount(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => self::EMAIL,
                'password' => 'wrong-password',
            ]);
        }

        $this->assertTrue(
            $this->user->fresh()->locked_until !== null || (bool) $this->user->fresh()->locked_permanently,
            '전제 조건 실패 — 계정이 잠기지 않았습니다.'
        );
    }

    /**
     * challenge 에 알려진 인증 코드를 심고 그 값을 돌려줍니다.
     *
     * 발급된 코드는 해시로만 저장되어 되읽을 수 없으므로, 기존 2단계 인증 테스트와 같은
     * 방식으로 알려진 값의 해시를 심어 "코드가 맞을 때" 를 재현합니다.
     *
     * @param  string  $challengeId  challenge UUID
     * @return string 심어 둔 인증 코드
     */
    private function issuedCode(string $challengeId): string
    {
        $code = '135790';

        $log = IdentityVerificationLog::find($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make($code);

        $log->metadata = $metadata;
        $log->save();

        return $code;
    }

    /**
     * 코어 보안 설정을 덮어씁니다.
     *
     * @param  array<string, mixed>  $values  덮어쓸 설정 값
     */
    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }
}
