<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * CleanInicisRecordOnUserDelete Listener 통합 테스트.
 *
 * 실제 hook 체인 (HookManager::doAction → Listener::handle) + 실제 DB 검증.
 * 도메인 매트릭스 §Hook/Event 규정: mock 금지, 관찰 가능한 상태 변화 검증.
 *
 * 시나리오 매니페스트 → tests/scenarios/lifecycle.yaml
 *
 * before_delete 훅은 hard delete 직전에 호출되므로 삭제될 User 모델을 받음.
 * inicis_identity_records.user_id 는 users(id) FK(CASCADE 미설정)를 가지므로
 * users 행 삭제 전에 본 record 를 먼저 파기해야 FK 제약 위반(1451) 없이 삭제 완료.
 *
 * 검증:
 *  - 실제 UserService::deleteUser() 호출 시 FK 위반 없이 삭제 완료 (버그 재현)
 *  - $user->id 의 record 만 삭제
 *  - 다른 사용자 영향 없음
 *  - 인자가 User 모델이 아니면 noop
 *  - identity_verification_logs 에서 삭제 user 의 inicis 발행 로그 user_id NULL 익명화 (PIPC)
 *  - 다른 사용자 또는 다른 provider 발행 로그는 영향 없음
 */
class CleanInicisRecordOnUserDeleteTest extends PluginTestCase
{
    private InicisIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordRepository = app(InicisIdentityRecordRepositoryInterface::class);
    }

    public function test_full_user_delete_succeeds_without_fk_violation_when_record_exists(): void
    {
        // 버그 재현: record 가 있는 사용자를 코어 UserService 로 실제 삭제 →
        // before_delete listener 가 먼저 record 를 파기해야 FK 위반(1451) 없이 users 행 삭제.
        $user = User::factory()->create();
        $this->seedRecordForUser($user);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);

        $result = app(UserService::class)->deleteUser($user);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_deletes_record_when_admin_deletes_user_via_before_delete_hook(): void
    {
        $user = User::factory()->create();
        $this->seedRecordForUser($user);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);

        // 코어 UserService::deleteUser() 가 삭제 직전 발화하는 형식 — User 모델 전달
        HookManager::doAction('core.user.before_delete', $user);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_does_not_affect_other_users_records(): void
    {
        $deleting = User::factory()->create();
        $other = User::factory()->create();
        $this->seedRecordForUser($deleting);
        $this->seedRecordForUser($other);

        HookManager::doAction('core.user.before_delete', $deleting);

        $this->assertDatabaseMissing('inicis_identity_records', ['user_id' => $deleting->id]);
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $other->id]);
    }

    public function test_listener_noops_when_argument_is_not_user_model(): void
    {
        // before_delete 훅 계약은 User 모델 전달을 강제하나, 비정상 인자(배열 등)
        // 가 들어와도 listener 가 안전하게 noop 하는지 가드 검증.
        $user = User::factory()->create();
        $this->seedRecordForUser($user);

        HookManager::doAction('core.user.before_delete', ['id' => $user->id]);

        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
    }

    public function test_listener_anonymizes_logs_user_id_on_admin_delete(): void
    {
        $user = User::factory()->create();
        $log = $this->seedLogForUser($user, InicisIdentityProvider::PROVIDER_ID);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => $user->id,
        ]);

        HookManager::doAction('core.user.before_delete', $user);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $log->id,
            'user_id' => null,
        ]);
    }

    public function test_listener_purges_pii_hashes_from_log_metadata_on_admin_delete(): void
    {
        $user = User::factory()->create();
        $log = $this->seedLogForUser($user, InicisIdentityProvider::PROVIDER_ID, [
            'di_hash' => hash('sha256', 'DI-VAL-'.$user->id),
            'ci_hash' => hash('sha256', 'CI-VAL-'.$user->id),
            'matched_field' => 'di_hash',
            'duplicate_field_used' => 'di',
        ]);

        HookManager::doAction('core.user.before_delete', $user);

        $log->refresh();
        $metadata = (array) $log->metadata;

        // PII성 해시는 파기
        $this->assertArrayNotHasKey('di_hash', $metadata);
        $this->assertArrayNotHasKey('ci_hash', $metadata);
        // 감사 추적 필드는 보존
        $this->assertSame('di_hash', $metadata['matched_field'] ?? null);
        $this->assertSame('di', $metadata['duplicate_field_used'] ?? null);
    }

    public function test_listener_does_not_anonymize_logs_of_other_users_or_providers(): void
    {
        $deleting = User::factory()->create();
        $other = User::factory()->create();

        $deletingInicisLog = $this->seedLogForUser($deleting, InicisIdentityProvider::PROVIDER_ID);
        $otherUserInicisLog = $this->seedLogForUser($other, InicisIdentityProvider::PROVIDER_ID);
        $deletingMailLog = $this->seedLogForUser($deleting, 'g7:core.mail');

        HookManager::doAction('core.user.before_delete', $deleting);

        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $deletingInicisLog->id,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $otherUserInicisLog->id,
            'user_id' => $other->id,
        ]);
        $this->assertDatabaseHas('identity_verification_logs', [
            'id' => $deletingMailLog->id,
            'user_id' => $deleting->id,
        ]);
    }

    /**
     * identity_verification_logs 에 verified 상태 로그 1건을 생성한다.
     *
     * @param  User  $user  로그 소유자
     * @param  string  $providerId  로그 발행 provider (예: 'inicis' / 'g7:core.mail')
     * @param  array<string, mixed>  $metadata  로그 metadata (di_hash/ci_hash 등)
     * @return IdentityVerificationLog 생성된 로그
     */
    private function seedLogForUser(User $user, string $providerId, array $metadata = []): IdentityVerificationLog
    {
        $log = new IdentityVerificationLog;
        $log->id = (string) Str::uuid();
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
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => Crypt::encryptString('DI-VAL-'.$user->id),
            'di_hash' => hash('sha256', 'DI-VAL-'.$user->id),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'provider_dev_cd' => 'SKT',
            'verified_at' => now(),
        ]);
    }
}
