<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

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
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 가입 트랜잭션 안 동일인 선점 테스트 (1.1.4, KVE-2026-3192 동형).
 *
 * 중복 가입 판정은 records 표를 보는데, records 는 가입이 커밋된 뒤에야 채워진다. 첫 가입의 records 가 아직 없는
 * 창(여기서는 PII 임시 보관을 비워 records 가 끝내 생기지 않게 재현)에서 같은 본인확인 결과로 두 번째 가입이
 * 들어오면 통과했다. 실제 `AuthService::register()` 를 태워 두 번째 가입이 거부되고, 조기 판정을 지난 뒤 다른 요청이
 * 먼저 선점한 경우에도 가입 트랜잭션 안 선점에서 막혀 사용자·동의 이력까지 롤백되는지 확인한다.
 *
 * @since 1.1.4
 */
class ClaimInicisIdentityOnRegisteringTest extends PluginTestCase
{
    /** plugin 식별자 — 설정 주입 키 */
    private const PLUGIN_IDENTIFIER = 'sirsoft-verification_kginicis';

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
     * 첫 가입의 records 가 없는 창에서도 같은 명의의 두 번째 가입이 거부되는지 확인합니다.
     *
     * @scenario stage=registering, race=records_missing
     *
     * @effects same_identity_second_signup_rejected
     *
     * @return void 반환값 없음
     */
    public function test_second_signup_with_same_identity_is_rejected(): void
    {
        $diHash = InicisIdentityHasher::hash('DI-RACE-0001');

        $first = $this->register('race-first@example.test', 'tok-race-1', $diHash);
        $this->assertSame(0, DB::table('inicis_identity_records')->count(), '경합 창 재현 — records 가 비어 있어야 한다');

        try {
            $this->register('race-second@example.test', 'tok-race-2', $diHash);
            $this->fail('같은 명의의 두 번째 가입은 거부되어야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity', $e->errors());
        }

        $this->assertNull(User::query()->where('email', 'race-second@example.test')->first(), '거부된 가입의 사용자가 남으면 안 된다');
        $this->assertSame($first->id, app(InicisIdentityClaimRepositoryInterface::class)->findHolderId('di', $diHash));
    }

    /**
     * 조기 판정을 지난 뒤 다른 요청이 먼저 선점하면 가입 트랜잭션 안 선점에서 막혀 흔적 없이 롤백되는지 확인합니다.
     *
     * before_register 의 조기 판정(priority 20) 직후에 경쟁 가입이 선점을 커밋하는 상황을 priority 30 훅으로 끼워 재현한다.
     *
     * @scenario stage=registering, race=claimed_after_early_check
     *
     * @effects rejected_signup_rolls_back_user_and_consents, rejected_signup_keeps_matched_field_audit
     *
     * @return void 반환값 없음
     */
    public function test_claim_taken_after_early_check_rolls_back_signup(): void
    {
        $diHash = InicisIdentityHasher::hash('DI-RACE-0002');
        $competitor = User::factory()->create();
        $claims = app(InicisIdentityClaimRepositoryInterface::class);

        $competitorClaims = function () use ($claims, $diHash, $competitor): void {
            $claims->claimSoft('di', $diHash, (int) $competitor->id);
        };
        HookManager::addAction('core.auth.before_register', $competitorClaims, 30);

        try {
            $this->register('race-late@example.test', 'tok-race-late', $diHash);
            $this->fail('조기 판정 뒤 다른 회원이 선점했으면 가입 트랜잭션 안에서 거부되어야 한다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity', $e->errors());
        } finally {
            HookManager::removeAction('core.auth.before_register', $competitorClaims);
        }

        $this->assertNull(User::query()->where('email', 'race-late@example.test')->first(), '거부된 가입의 사용자가 남으면 안 된다');
        $this->assertSame(
            0,
            DB::table('user_consents')->whereNotIn('user_id', User::query()->pluck('id'))->count(),
            '롤백된 가입의 동의 이력이 남으면 안 된다',
        );
        $this->assertSame((int) $competitor->id, $claims->findHolderId('di', $diHash), '먼저 선점한 회원이 그대로 쥐어야 한다');

        // 거부 사유(어느 식별값이 겹쳤는지) 감사 기록은 가입 롤백과 함께 사라지지 않는다
        $metadata = IdentityVerificationLog::query()->where('verification_token', 'tok-race-late')->value('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $this->assertSame('di_hash', $metadata['matched_field'] ?? null, '감사 기록이 가입 롤백과 함께 사라지면 안 된다');
        $this->assertSame((int) $competitor->id, (int) ($metadata['matched_user_id'] ?? 0));
    }

    /**
     * 판정 키가 아닌 식별값도 비어 있으면 함께 선점하는지 확인합니다.
     *
     * @scenario stage=registering, field=soft
     *
     * @effects other_identity_hash_soft_claimed
     *
     * @return void 반환값 없음
     */
    public function test_other_identity_hash_is_soft_claimed(): void
    {
        $ciHash = InicisIdentityHasher::hash('CI-SOFT-0001');
        $user = $this->register('soft@example.test', 'tok-soft', InicisIdentityHasher::hash('DI-SOFT-0001'), $ciHash);

        $this->assertSame($user->id, app(InicisIdentityClaimRepositoryInterface::class)->findHolderId('ci', $ciHash));
    }

    /**
     * 같은 회원이 다시 선점하면 성공(멱등)하고, 다른 회원은 실패하는지 확인합니다.
     *
     * @scenario repository=claim
     *
     * @effects claim_is_idempotent_for_same_user
     *
     * @return void 반환값 없음
     */
    public function test_claim_is_idempotent_for_the_same_user(): void
    {
        $claims = app(InicisIdentityClaimRepositoryInterface::class);
        $hash = InicisIdentityHasher::hash('DI-IDEMPOTENT');
        [$owner, $other] = User::factory()->count(2)->create()->all();

        $this->assertTrue($claims->claim('di', $hash, $owner->id));
        $this->assertTrue($claims->claim('di', $hash, $owner->id));
        $this->assertFalse($claims->claim('di', $hash, $other->id));
        $this->assertSame(1, DB::table('inicis_identity_claims')->count());
    }

    /**
     * 운영자가 중복 차단을 끄면 선점하지 않는지 확인합니다.
     *
     * @scenario stage=registering, setting=disabled
     *
     * @effects claim_skipped_when_duplicate_block_disabled
     *
     * @return void 반환값 없음
     */
    public function test_disabled_setting_skips_claims(): void
    {
        $this->applySettings(['duplicate_block_enabled' => false, 'duplicate_field' => 'di']);
        $diHash = InicisIdentityHasher::hash('DI-OFF-0001');

        $this->register('off-first@example.test', 'tok-off-1', $diHash);
        $this->register('off-second@example.test', 'tok-off-2', $diHash);

        $this->assertSame(0, DB::table('inicis_identity_claims')->count());
        $this->assertSame(2, User::query()->where('email', 'like', 'off-%')->count());
    }

    /**
     * 탈퇴하면 선점이 풀려 같은 명의로 다시 가입할 수 있는지 확인합니다 (현행 허용 유지).
     *
     * @scenario stage=withdraw, claim=release
     *
     * @effects withdraw_releases_claim_for_reregistration
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_releases_the_claim_so_the_person_can_register_again(): void
    {
        $diHash = InicisIdentityHasher::hash('DI-REJOIN-0001');
        $first = $this->register('rejoin-first@example.test', 'tok-rejoin-1', $diHash);

        HookManager::doAction('core.user.after_withdraw', $first);

        $again = $this->register('rejoin-second@example.test', 'tok-rejoin-2', $diHash);

        $this->assertSame($again->id, app(InicisIdentityClaimRepositoryInterface::class)->findHolderId('di', $diHash));
    }

    /**
     * 관리자가 회원을 삭제하면 그 회원의 선점이 모두 풀리는지 확인합니다.
     *
     * @scenario stage=delete, claim=release
     *
     * @effects delete_releases_claims
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_releases_claims(): void
    {
        $diHash = InicisIdentityHasher::hash('DI-DELETE-0001');
        $ciHash = InicisIdentityHasher::hash('CI-DELETE-0001');
        $user = $this->register('delete-me@example.test', 'tok-delete', $diHash, $ciHash);
        $this->assertSame(2, DB::table('inicis_identity_claims')->where('user_id', $user->id)->count());

        HookManager::doAction('core.user.before_delete', $user);

        $this->assertSame(0, DB::table('inicis_identity_claims')->where('user_id', $user->id)->count());
    }

    /**
     * 실제 가입 서비스를 태워 회원을 만듭니다 (PII 임시 보관 없음 → records 미생성).
     *
     * @param  string  $email  가입 이메일
     * @param  string  $token  본인확인 토큰
     * @param  string  $diHash  DI 해시
     * @param  string|null  $ciHash  CI 해시 (없으면 생략)
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
     * 검증 완료된 이니시스 가입 결과를 만듭니다.
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
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => 'signup',
            'channel' => 'ipin',
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
