<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Plugins\Sirsoft\Totp\Services\TotpOwnershipThrottle;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 해제·백업 코드 재발급의 코드 확인이 회원별로 시도를 제한하는지 검증한다 (KVE 동형 A1).
 *
 * 수정 전: 열린 세션으로 해제 코드를 무제한 맞혀 볼 수 있어 2단계 인증을 끌 수 있었다.
 */
class TotpOwnershipThrottleTest extends PluginTestCase
{
    private User $user;

    private string $secret;

    /**
     * 확정 자격증명을 가진 회원으로 로그인하고 시도 기준을 3회로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'g7_settings.core.security.max_login_attempts' => 3,
            'g7_settings.core.security.login_lockout_time' => 5,
        ]);

        $this->user = User::factory()->create(['email' => 'totp-own@test.com']);
        RateLimiter::clear('sirsoft-totp:ownership:'.$this->user->id);
        $this->secret = Base32::randomSecret();
        TotpCredential::query()->create([
            'user_id' => $this->user->id,
            'secret' => $this->secret,
            'confirmed_at' => now(),
            'backup_codes' => [],
        ]);
        Sanctum::actingAs($this->user);
    }

    /**
     * 해제 코드를 기준 횟수만큼 틀리면 다음 시도는 맞는 코드라도 429 다 (수정 전: 422 무한).
     *
     * @scenario case=ownership_attempts_throttled
     *
     * @effects ownership_attempts_throttled
     *
     * @return void 반환값 없음
     */
    public function test_disable_attempts_are_throttled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => sprintf('%06d', 100 + $i)])->assertStatus(422);
        }

        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => Totp::codeAt($this->secret, Totp::timestepAt())])
            ->assertStatus(429);
        $this->assertNotNull(TotpCredential::query()->where('user_id', $this->user->id)->first());
    }

    /**
     * 재발급도 같은 제한을 받는다.
     *
     * @scenario case=ownership_attempts_throttled
     *
     * @effects ownership_attempts_throttled
     *
     * @return void 반환값 없음
     */
    public function test_regenerate_attempts_share_the_limit(): void
    {
        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => '000101'])->assertStatus(422);
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000102'])->assertStatus(422);
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000103'])->assertStatus(422);

        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000104'])->assertStatus(429);
    }

    /**
     * 맞히면 실패 기록이 지워진다.
     *
     * @scenario case=ownership_success_clears
     *
     * @effects ownership_success_clears
     *
     * @return void 반환값 없음
     */
    public function test_success_clears_failures(): void
    {
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000201'])->assertStatus(422);
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000202'])->assertStatus(422);

        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => Totp::codeAt($this->secret, Totp::timestepAt())])
            ->assertOk();

        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000203'])->assertStatus(422);
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => '000204'])->assertStatus(422);
    }

    /**
     * 코드를 대조하는 순간 이번 시도가 이미 세어져 있다 — 동시에 보낸 요청들이 같은 남은 횟수를 함께 통과해 기준보다
     * 많이 맞혀 보지 못하게 한다(검증 중 발견, 수정 전: 대조 뒤 실패일 때만 증가).
     *
     * @scenario case=ownership_attempt_counted_before_check
     *
     * @effects ownership_attempt_counted_before_check
     *
     * @return void 반환값 없음
     */
    public function test_attempt_is_counted_before_the_code_check(): void
    {
        $key = TotpOwnershipThrottle::KEY_PREFIX.$this->user->id;
        $seen = (object) ['attempts' => null];

        $this->app->bind(TotpCredentialRepositoryInterface::class, fn () => new class($key, $seen) extends TotpCredentialRepository
        {
            /**
             * 대조 시점의 시도 횟수를 기록하는 대역 생성자.
             *
             * @param  string  $key  제한 키
             * @param  object  $seen  관측값 상자
             */
            public function __construct(private string $key, private object $seen) {}

            /**
             * 대조 시점의 시도 횟수를 남기고 원래 동작을 따릅니다.
             *
             * @param  int  $credentialId  자격증명 ID
             * @param  int  $step  확인한 시간 단계 번호
             * @return bool 원래 결과
             */
            public function advanceTimestep(int $credentialId, int $step): bool
            {
                $this->seen->attempts = RateLimiter::attempts($this->key);

                return parent::advanceTimestep($credentialId, $step);
            }
        });

        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => Totp::codeAt($this->secret, Totp::timestepAt())])
            ->assertOk();

        $this->assertSame(1, $seen->attempts, '대조 시점에 이번 시도가 세어져 있지 않으면 동시 요청이 기준을 넘을 수 있다');
        $this->assertSame(0, RateLimiter::attempts($key), '성공하면 기록을 지워야 한다');
    }
}
