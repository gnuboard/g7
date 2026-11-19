<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Listeners;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 가입 트랜잭션 안 동일인 선점 테스트 (1.1.4, KVE-2026-3192).
 *
 * 중복 가입 판정은 records 표를 보는데, records 는 가입이 커밋된 뒤에야 채워진다. 첫 가입의 records 가 아직 없는
 * 창(여기서는 PII 임시 보관을 비워 records 가 끝내 생기지 않게 재현)에서 같은 본인확인 결과로 두 번째 가입이
 * 들어오면 통과했다. 실제 `AuthService::register()` 를 태워 두 번째 가입이 거부되고 사용자·동의 이력까지 롤백되는지
 * 확인한다.
 *
 * @since 1.1.4
 */
class ClaimKcpIdentityOnRegisteringTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->applySettings(['duplicate_block_enabled' => true, 'duplicate_field' => 'di']);
    }

    /**
     * 첫 가입의 records 가 없는 창에서도 같은 명의의 두 번째 가입이 거부되고 흔적 없이 롤백되는지 확인합니다.
     *
     * 케이스(마커 아님 — 평문): stage registering · race records_missing — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects same_identity_second_signup_rejected, rejected_signup_rolls_back_user_and_consents
     *
     * @return void 반환값 없음
     */
    public function test_second_signup_with_same_identity_is_rejected_and_rolled_back(): void
    {
        $diHash = KcpIdentityHasher::hash('DI-RACE-0001');

        $first = $this->register('race-first@example.test', 'tok-race-1', $diHash);
        $this->assertSame(0, DB::table('nhnkcp_identity_records')->count(), '경합 창 재현 — records 가 비어 있어야 한다');

        try {
            $this->register('race-second@example.test', 'tok-race-2', $diHash);
            $this->fail('같은 명의의 두 번째 가입은 거부되어야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity', $e->errors());
        }

        $this->assertNull(User::query()->where('email', 'race-second@example.test')->first(), '거부된 가입의 사용자가 남으면 안 된다');
        $this->assertSame(1, User::query()->where('email', 'like', 'race-%')->count());
        $this->assertSame(
            0,
            DB::table('user_consents')->whereNotIn('user_id', User::query()->pluck('id'))->count(),
            '롤백된 가입의 동의 이력이 남으면 안 된다',
        );
        $this->assertSame($first->id, app(KcpIdentityClaimRepositoryInterface::class)->findHolderId('di', $diHash));
    }

    /**
     * 가입 트랜잭션 안에서 거부돼도 「어느 식별값이 겹쳤는지」 감사 기록은 롤백되지 않고 남는지 확인합니다.
     *
     * 사전 검사(가입 트랜잭션 밖)를 통과한 뒤 경쟁 가입이 먼저 선점한 경합 창을 흉내 낸다 — 선점 리스너보다 먼저
     * 도는 구독자가 다른 회원 이름으로 선점한다.
     *
     * 케이스(마커 아님 — 평문): stage registering · race records_missing — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects rejected_signup_keeps_matched_field_audit
     *
     * @return void 반환값 없음
     */
    public function test_rejection_inside_signup_transaction_keeps_the_audit_record(): void
    {
        $diHash = KcpIdentityHasher::hash('DI-RACE-AUDIT');
        $competitor = User::factory()->create();
        $claims = app(KcpIdentityClaimRepositoryInterface::class);
        $race = function () use ($claims, $diHash, $competitor): void {
            $claims->claim('di', $diHash, (int) $competitor->id);
        };
        HookManager::addAction('core.auth.registering', $race, 10);

        try {
            $this->register('race-audit@example.test', 'tok-race-audit', $diHash);
            $this->fail('경쟁 가입이 선점한 명의의 가입은 거부되어야 한다');
        } catch (ValidationException) {
            // 기대한 거부
        } finally {
            HookManager::removeAction('core.auth.registering', $race);
        }

        $this->assertNull(User::query()->where('email', 'race-audit@example.test')->first(), '거부된 가입은 롤백되어야 한다');
        // 가입 트랜잭션 안에서 이미 기록된 약관·개인정보 동의도 함께 롤백된다 — 위 첫 시험의 두 번째 가입은 사전 검사에서
        // 막혀 트랜잭션에 들어가지 않으므로, 동의 롤백은 이 시험이 잰다(계획 §8 「롤백 시 동의 이력도 없음」).
        $this->assertSame(
            0,
            DB::table('user_consents')->whereNotIn('user_id', User::query()->pluck('id'))->count(),
            '롤백된 가입의 동의 이력이 남으면 안 된다',
        );
        $metadata = IdentityVerificationLog::query()->where('verification_token', 'tok-race-audit')->value('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $this->assertSame('di_hash', $metadata['matched_field'] ?? null, '감사 기록이 가입 롤백과 함께 사라지면 안 된다');
        $this->assertSame((int) $competitor->id, (int) ($metadata['matched_user_id'] ?? 0));
    }

    /**
     * 판정 키가 아닌 식별값도 비어 있으면 함께 선점하는지 확인합니다.
     *
     * 케이스(마커 아님 — 평문): stage registering · field soft — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects other_identity_hash_soft_claimed
     *
     * @return void 반환값 없음
     */
    public function test_other_identity_hash_is_soft_claimed(): void
    {
        $ciHash = KcpIdentityHasher::hash('CI-SOFT-0001');
        $user = $this->register('soft@example.test', 'tok-soft', KcpIdentityHasher::hash('DI-SOFT-0001'), $ciHash);

        $this->assertSame($user->id, app(KcpIdentityClaimRepositoryInterface::class)->findHolderId('ci', $ciHash));
    }

    /**
     * 같은 회원이 다시 선점하면 성공(멱등)하고, 다른 회원은 실패하는지 확인합니다.
     *
     * 케이스(마커 아님 — 평문): repository claim — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects claim_is_idempotent_for_same_user
     *
     * @return void 반환값 없음
     */
    public function test_claim_is_idempotent_for_the_same_user(): void
    {
        $claims = app(KcpIdentityClaimRepositoryInterface::class);
        $hash = KcpIdentityHasher::hash('DI-IDEMPOTENT');
        [$owner, $other] = User::factory()->count(2)->create()->all();

        $this->assertTrue($claims->claim('di', $hash, $owner->id));
        $this->assertTrue($claims->claim('di', $hash, $owner->id));
        $this->assertFalse($claims->claim('di', $hash, $other->id));
        $this->assertSame(1, DB::table('nhnkcp_identity_claims')->count());
    }

    /**
     * 운영자가 중복 차단을 끄면 선점하지 않는지 확인합니다.
     *
     * 케이스(마커 아님 — 평문): stage registering · setting disabled — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects claim_skipped_when_duplicate_block_disabled
     *
     * @return void 반환값 없음
     */
    public function test_disabled_setting_skips_claims(): void
    {
        $this->applySettings(['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);
        $diHash = KcpIdentityHasher::hash('DI-OFF-0001');

        $this->register('off-first@example.test', 'tok-off-1', $diHash);
        $this->register('off-second@example.test', 'tok-off-2', $diHash);

        $this->assertSame(0, DB::table('nhnkcp_identity_claims')->count());
        $this->assertSame(2, User::query()->where('email', 'like', 'off-%')->count());
    }

    /**
     * 탈퇴하면 선점이 풀려 같은 명의로 다시 가입할 수 있는지 확인합니다 (현행 허용 유지).
     *
     * 케이스(마커 아님 — 평문): stage withdraw · claim release — 이 파일 안의 구분자라 매니페스트 축이 아니다. 매핑은 아래 @effects.
     *
     * @effects withdraw_releases_claim_for_reregistration
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_releases_the_claim_so_the_person_can_register_again(): void
    {
        $diHash = KcpIdentityHasher::hash('DI-REJOIN-0001');
        $first = $this->register('rejoin-first@example.test', 'tok-rejoin-1', $diHash);

        HookManager::doAction('core.user.after_withdraw', $first);

        $again = $this->register('rejoin-second@example.test', 'tok-rejoin-2', $diHash);

        $this->assertSame($again->id, app(KcpIdentityClaimRepositoryInterface::class)->findHolderId('di', $diHash));
    }

    /**
     * 실제 가입 서비스를 태워 회원을 만듭니다 (PII 임시 보관 없음 → records 미생성).
     *
     * @param  string  $email  가입 이메일
     * @param  string  $token  본인확인 토큰
     * @param  string  $diHash  DI 해시
     * @param  string|null  $ciHash  CI 해시
     * @return User 만든 회원
     */
    private function register(string $email, string $token, string $diHash, ?string $ciHash = null): User
    {
        $this->createVerifiedSignupLog($token, $email, array_filter(['di_hash' => $diHash, 'ci_hash' => $ciHash]));

        return app(AuthService::class)->register([
            'name' => '선점 시험',
            'email' => $email,
            'password' => 'Claim-test-pass-1!',
            'verification_token' => $token,
        ])['user'];
    }

    /**
     * 플러그인 설정을 주입합니다.
     *
     * @param  array<string, mixed>  $settings  설정
     * @return void 반환값 없음
     */
    private function applySettings(array $settings): void
    {
        config(['g7_settings.plugins.'.self::PLUGIN_IDENTIFIER => $settings]);
    }

    /**
     * 검증 완료된 KCP 가입 결과를 만듭니다.
     *
     * @param  string  $token  확인 토큰
     * @param  string  $email  가입 이메일 (대상 해시)
     * @param  array<string, mixed>  $metadata  식별값 해시
     * @return IdentityVerificationLog 결과
     */
    private function createVerifiedSignupLog(string $token, string $email, array $metadata): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => 'signup',
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => null,
            'target_hash' => hash('sha256', mb_strtolower($email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.auth.register',
            'verification_token' => $token,
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'metadata' => $metadata,
        ]);
    }
}
