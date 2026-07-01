<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * CleanInicisRecordOnUserWithdraw Listener 통합 테스트.
 *
 * 실제 hook 체인 (HookManager::doAction → Listener::handle) + 실제 DB 검증.
 * 도메인 매트릭스 §Hook/Event 규정: mock 금지, 관찰 가능한 상태 변화 검증.
 *
 * 시나리오 매니페스트 → tests/scenarios/lifecycle.yaml
 *
 * 검증 후속 효과 (관찰 가능한 상태 변화):
 *  - inicis_identity_records 테이블에서 탈퇴한 user 의 row 삭제
 *  - 다른 사용자의 record 는 영향 없음
 *  - record 가 없는 사용자 탈퇴 시 noop (에러 없이 통과)
 *  - identity_verification_logs 에서 탈퇴 user 의 inicis provider 발행 로그 user_id NULL 익명화 (PIPC)
 *  - 다른 사용자의 로그 또는 다른 provider 발행 로그는 영향 없음
 */
class CleanInicisRecordOnUserWithdrawTest extends PluginTestCase
{
    private InicisIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);
    }

    public function test_listener_deletes_record_when_user_withdraws_via_after_withdraw_hook(): void
    {
        $user = User::factory()->create();
        $this->seedRecordForUser($user);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_does_not_affect_other_users_records(): void
    {
        $withdrawing = User::factory()->create();
        $other = User::factory()->create();
        $this->seedRecordForUser($withdrawing);
        $this->seedRecordForUser($other);

        HookManager::doAction('core.user.after_withdraw', $withdrawing);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $withdrawing->id]);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $other->id]);
    }

    public function test_listener_noops_when_user_has_no_record(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_anonymizes_logs_user_id_on_withdraw(): void
    {
        $user = User::factory()->create();
        $log = $this->seedLogForUser($user, InicisIdentityProvider::PROVIDER_ID);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
        ]);

        HookManager::doAction('core.user.after_withdraw', $user);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => null,
        ]);
    }

    public function test_listener_purges_pii_hashes_from_log_metadata_on_withdraw(): void
    {
        $user = User::factory()->create();
        $log = $this->seedLogForUser($user, InicisIdentityProvider::PROVIDER_ID, [
            'di_hash' => hash('sha256', 'DI-VAL-'.$user->id),
            'ci_hash' => hash('sha256', 'CI-VAL-'.$user->id),
            'matched_field' => 'di_hash',
            'duplicate_field_used' => 'di',
        ]);

        HookManager::doAction('core.user.after_withdraw', $user);

        $log->refresh();
        $metadata = (array) $log->metadata;

        // PII성 해시는 파기 (탈퇴자에게도 결함 ① 위험이 영속되지 않도록)
        $this->assertArrayNotHasKey('di_hash', $metadata);
        $this->assertArrayNotHasKey('ci_hash', $metadata);
        // 감사 추적 필드는 보존
        $this->assertSame('di_hash', $metadata['matched_field'] ?? null);
        $this->assertSame('di', $metadata['duplicate_field_used'] ?? null);
    }

    public function test_listener_does_not_anonymize_logs_of_other_users_or_providers(): void
    {
        $withdrawing = User::factory()->create();
        $other = User::factory()->create();

        $withdrawingInicisLog = $this->seedLogForUser($withdrawing, InicisIdentityProvider::PROVIDER_ID);
        $otherUserInicisLog = $this->seedLogForUser($other, InicisIdentityProvider::PROVIDER_ID);
        $withdrawingMailLog = $this->seedLogForUser($withdrawing, 'g7:core.mail');

        HookManager::doAction('core.user.after_withdraw', $withdrawing);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $withdrawingInicisLog->id,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $otherUserInicisLog->id,
            'user_id' => $other->id,
        ]);
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $withdrawingMailLog->id,
            'user_id' => $withdrawing->id,
        ]);
    }

    /**
     * identity_verification_logs 에 verified 상태 로그 1건을 생성한다.
     *
     * @param  User  $user  로그 소유자
     * @param  string  $providerId  로그 발행 provider (예: 'inicis' / 'g7:core.mail')
     * @param  array<string, mixed>  $metadata  로그 metadata (di_hash/ci_hash 등)
     * @return IdentityVerificationLog  생성된 로그
     */
    private function seedLogForUser(User $user, string $providerId, array $metadata = []): IdentityVerificationLog
    {
        $log = new IdentityVerificationLog();
        $log->id = (string) \Illuminate\Support\Str::uuid();
        $log->user_id = $user->id;
        $log->provider_id = $providerId;
        $log->purpose = 'signup';
        $log->channel = $providerId === InicisIdentityProvider::PROVIDER_ID ? 'ipin' : 'email';
        $log->status = IdentityVerificationStatus::Verified->value;
        $log->target_hash = hash('sha256', 'target-'.$user->id.'-'.$providerId);
        $log->attempts = 1;
        $log->max_attempts = 1;
        $log->origin_type = 'route';
        $log->origin_identifier = 'test';
        $log->expires_at = now()->addMinutes(10);
        $log->verified_at = now();
        $log->metadata = $metadata !== [] ? $metadata : null;
        $log->save();

        return $log;
    }

    /**
     * 본인확인 record 를 직접 1건 생성한다.
     */
    private function seedRecordForUser(User $user): void
    {
        $this->recordRepository->upsertForUser((int) $user->id, [
            'name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('홍길동'),
            'phone_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('01012345678'),
            'birthday_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('19900101'),
            'di_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('DI-VAL-'.$user->id),
            'di_hash' => hash('sha256', 'DI-VAL-'.$user->id),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'provider_dev_cd' => 'SKT',
            'verified_at' => now(),
        ]);
    }
}
