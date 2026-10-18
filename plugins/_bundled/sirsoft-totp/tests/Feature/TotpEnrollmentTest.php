<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 인증 앱 등록 API 테스트.
 *
 * 이 흐름의 불변조건은 둘입니다.
 * - **첫 코드를 확인하기 전에는 등록이 확정되지 않는다** — 확정해 버리면 앱에 시크릿을
 *   넣지 않은 사용자에게 2단계 인증을 요구하게 되고, 그 계정은 그대로 잠깁니다.
 * - **평문(시크릿·백업 코드)은 발급 응답에 한 번만 실린다** — 조회로 다시 내려가면
 *   세션을 훔친 쪽이 인증 수단 자체를 복제할 수 있습니다.
 *
 * @since 1.0.0
 */
class TotpEnrollmentTest extends PluginTestCase
{
    private User $user;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'totp-enroll@test.com']);
        Sanctum::actingAs($this->user);
    }

    /**
     * 시크릿을 발급받습니다.
     *
     * @return string 평문 Base32 시크릿
     */
    private function startEnrollment(): string
    {
        $response = $this->postJson('/api/plugins/sirsoft-totp/enrollment');
        $response->assertStatus(200);

        return $response->json('data.secret');
    }

    /**
     * 등록을 확정합니다.
     *
     * @param  string  $secret  평문 시크릿
     * @return \Illuminate\Testing\TestResponse 확정 응답
     */
    private function confirmEnrollment(string $secret)
    {
        return $this->postJson('/api/plugins/sirsoft-totp/enrollment/confirm', [
            'code' => Totp::codeAt($secret, Totp::timestepAt()),
        ]);
    }

    /**
     * 등록을 시작하면 시크릿과 otpauth URI 를 받고 아직 확정되지 않은 상태로 남는지 확인합니다.
     *
     * @scenario enrollment=start
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function starting_enrollment_returns_a_secret_and_an_otpauth_uri(): void
    {
        $response = $this->postJson('/api/plugins/sirsoft-totp/enrollment');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertStringStartsWith('otpauth://totp/', $response->json('data.otpauth_uri'));

        $credential = TotpCredential::where('user_id', $this->user->id)->first();
        $this->assertNotNull($credential);
        $this->assertNull(
            $credential->confirmed_at,
            '첫 코드를 확인하기 전에 등록이 확정되면 앱에 시크릿을 넣지 않은 사용자의 계정이 잠깁니다.'
        );
    }

    /**
     * 시크릿이 DB 에 평문으로 저장되지 않는지 확인합니다.
     *
     * @scenario enrollment=start, storage=encrypted
     *
     * @effects secret_is_not_stored_in_plaintext
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_secret_is_never_stored_in_plaintext(): void
    {
        $secret = $this->startEnrollment();

        $raw = \Illuminate\Support\Facades\DB::table('totp_credentials')
            ->where('user_id', $this->user->id)
            ->value('secret');

        $this->assertNotSame($secret, $raw, 'DB 에 평문 시크릿이 그대로 저장됐습니다.');
        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    /**
     * 첫 코드 확인으로 등록이 확정되고 백업 코드 10개가 해시로 저장되는지 확인합니다.
     *
     * @scenario enrollment=confirm, code=valid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function confirming_with_a_valid_code_issues_ten_backup_codes(): void
    {
        $secret = $this->startEnrollment();

        $response = $this->confirmEnrollment($secret);

        $response->assertStatus(200);
        $codes = $response->json('data.backup_codes');

        $this->assertCount(TotpEnrollmentService::BACKUP_CODE_COUNT, $codes);
        $this->assertCount(count($codes), array_unique($codes), '중복된 백업 코드가 발급됐습니다.');

        $credential = TotpCredential::where('user_id', $this->user->id)->first();
        $this->assertNotNull($credential->confirmed_at);

        // 저장된 것은 해시여야 한다 — 평문이 남으면 DB 하나로 로그인이 가능해진다.
        foreach ($credential->backup_codes as $stored) {
            $this->assertNotContains($stored, $codes, '백업 코드가 평문으로 저장됐습니다.');
        }
    }

    /**
     * 틀린 코드로는 등록이 확정되지 않고 백업 코드도 발급되지 않는지 확인합니다.
     *
     * @scenario enrollment=confirm, code=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function confirming_with_a_wrong_code_leaves_the_enrollment_pending(): void
    {
        $this->startEnrollment();

        $response = $this->postJson('/api/plugins/sirsoft-totp/enrollment/confirm', ['code' => '000000']);

        $response->assertStatus(422);

        $credential = TotpCredential::where('user_id', $this->user->id)->first();
        $this->assertNull($credential->confirmed_at);
        $this->assertEmpty($credential->backup_codes ?? []);
    }

    /**
     * 이미 확정된 자격증명을 덮어쓰지 않고 해제를 먼저 요구하는지 확인합니다.
     *
     * @scenario enrollment=start, state=already_confirmed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function starting_again_after_confirmation_is_refused(): void
    {
        $secret = $this->startEnrollment();
        $this->confirmEnrollment($secret)->assertStatus(200);

        // 덮어쓰면 기존 앱이 조용히 무효가 되고 사용자는 다음 로그인에서야 알게 된다.
        $this->postJson('/api/plugins/sirsoft-totp/enrollment')->assertStatus(409);

        $credential = TotpCredential::where('user_id', $this->user->id)->first();
        $this->assertNotNull($credential->confirmed_at, '거절된 재발급이 확정 상태를 지웠습니다.');
    }

    /**
     * 확정 전 상태에서는 새 시크릿을 다시 발급해 QR 을 다시 띄울 수 있는지 확인합니다.
     *
     * @scenario enrollment=start, state=pending
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function starting_again_while_pending_issues_a_fresh_secret(): void
    {
        $first = $this->startEnrollment();

        // 등록을 하다 만 상태는 보호할 것이 없다 — 막으면 QR 을 다시 띄울 방법이 없어진다.
        $second = $this->startEnrollment();

        $this->assertNotSame($first, $second);
    }

    /**
     * 상태 조회가 평문 시크릿·백업 코드를 다시 내려보내지 않는지 확인합니다.
     *
     * @scenario enrollment=show
     *
     * @effects status_never_returns_the_secret
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_status_endpoint_never_returns_the_secret_or_backup_codes(): void
    {
        $secret = $this->startEnrollment();
        $this->confirmEnrollment($secret)->assertStatus(200);

        $response = $this->getJson('/api/plugins/sirsoft-totp/enrollment');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.enrolled'));
        $this->assertSame(TotpEnrollmentService::BACKUP_CODE_COUNT, $response->json('data.backup_codes_remaining'));

        $body = $response->getContent();
        $this->assertStringNotContainsString($secret, $body, '상태 조회가 시크릿을 다시 내려보냈습니다.');
        $this->assertStringNotContainsString('backup_codes"', str_replace('backup_codes_remaining"', '', $body));
    }

    /**
     * 해제에 현재 코드를 요구해 열린 세션만으로 2단계 인증을 끌 수 없게 하는지 확인합니다.
     *
     * @scenario enrollment=disable, code=valid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function disabling_requires_a_current_code(): void
    {
        $secret = $this->startEnrollment();
        $this->confirmEnrollment($secret)->assertStatus(200);

        // 잠깐 자리를 비운 사이 열린 세션으로 2단계 인증을 꺼 버리는 경로를 막는다.
        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => '000000'])->assertStatus(422);
        $this->assertDatabaseHas('totp_credentials', ['user_id' => $this->user->id]);

        // 확정에 쓴 코드는 리플레이 방지로 막히므로 다음 timestep 의 코드를 쓴다.
        $next = Totp::codeAt($secret, Totp::timestepAt() + 1);
        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => $next])->assertStatus(200);

        $this->assertDatabaseMissing('totp_credentials', ['user_id' => $this->user->id]);
    }

    /**
     * 백업 코드로도 해제할 수 있는지 확인합니다.
     *
     * @scenario enrollment=disable, code=backup
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_backup_code_also_unlocks_disabling(): void
    {
        $secret = $this->startEnrollment();
        $backupCodes = $this->confirmEnrollment($secret)->json('data.backup_codes');

        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => $backupCodes[0]])
            ->assertStatus(200);

        $this->assertDatabaseMissing('totp_credentials', ['user_id' => $this->user->id]);
    }

    /**
     * 백업 코드를 재발급하면 이전 코드가 모두 무효가 되는지 확인합니다.
     *
     * @scenario backup_codes=regenerate
     *
     * @effects previous_backup_codes_stop_working
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function regenerating_backup_codes_invalidates_the_previous_set(): void
    {
        $secret = $this->startEnrollment();
        $original = $this->confirmEnrollment($secret)->json('data.backup_codes');

        $response = $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', [
            'code' => $original[0],
        ]);

        $response->assertStatus(200);
        $fresh = $response->json('data.backup_codes');

        $this->assertCount(TotpEnrollmentService::BACKUP_CODE_COUNT, $fresh);
        $this->assertEmpty(array_intersect($original, $fresh), '재발급 후에도 이전 코드가 남아 있습니다.');

        // 남아 있던 이전 코드로는 더 이상 아무것도 열 수 없어야 한다.
        $this->deleteJson('/api/plugins/sirsoft-totp/enrollment', ['code' => $original[1]])
            ->assertStatus(422);
    }

    /**
     * 백업 코드가 1회용으로 소진되는지 확인합니다.
     *
     * @scenario backup_codes=single_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_backup_code_cannot_be_used_twice(): void
    {
        $secret = $this->startEnrollment();
        $codes = $this->confirmEnrollment($secret)->json('data.backup_codes');

        // 첫 사용: 재발급을 여는 데 쓴다 (성공) — 그 과정에서 소진돼야 한다.
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => $codes[0]])
            ->assertStatus(200);

        // 같은 코드를 다시 쓰면 거절 (이미 소진 + 새 세트로 교체됨).
        $this->postJson('/api/plugins/sirsoft-totp/backup-codes/regenerate', ['code' => $codes[0]])
            ->assertStatus(422);
    }

    /**
     * 등록 관련 엔드포인트 전부가 인증을 요구하는지 확인합니다.
     *
     * @scenario enrollment=unauthenticated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_enrollment_endpoint_requires_authentication(): void
    {
        // setUp 이 걸어 둔 Sanctum 행세를 걷어내고 완전한 비인증 상태에서 호출한다 —
        // 남의 자격증명을 건드릴 수 있는 표면이 생기면 안 된다.
        $this->app['auth']->forgetGuards();

        foreach ([
            ['getJson', '/api/plugins/sirsoft-totp/enrollment'],
            ['postJson', '/api/plugins/sirsoft-totp/enrollment'],
            ['postJson', '/api/plugins/sirsoft-totp/enrollment/confirm'],
            ['deleteJson', '/api/plugins/sirsoft-totp/enrollment'],
            ['postJson', '/api/plugins/sirsoft-totp/backup-codes/regenerate'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertStatus(401);
        }
    }
}
