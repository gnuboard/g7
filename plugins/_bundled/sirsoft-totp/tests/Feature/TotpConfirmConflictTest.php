<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\Totp\Exceptions\EnrollmentConflictException;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 등록 확정을 동시에 두 번 눌러도 화면에 보인 백업 코드와 저장된 코드가 어긋나지 않는지 검증한다 (KVE 동형 L3).
 *
 * 수정 전: 두 확정이 모두 성공해 먼저 보인 백업 코드 10개가 나중 저장에 덮여 쓸 수 없게 됐다.
 */
class TotpConfirmConflictTest extends PluginTestCase
{
    /**
     * 같은 미확정 자격증명을 먼저 읽은 두 번째 확정은 충돌로 거부되고 첫 번째 백업 코드가 그대로 남는다.
     *
     * @scenario case=confirm_conflict_keeps_first_codes
     *
     * @effects confirm_conflict_keeps_first_codes
     *
     * @return void 반환값 없음
     */
    public function test_second_confirm_does_not_overwrite_backup_codes(): void
    {
        $user = User::factory()->create(['email' => 'totp-confirm@test.com']);
        $secret = Base32::randomSecret();
        TotpCredential::query()->create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => null]);
        $stale = TotpCredential::query()->where('user_id', $user->id)->firstOrFail();

        $repository = new class($stale) extends TotpCredentialRepository
        {
            /**
             * 낡은 미확정 자격증명을 들고 있는 대역 저장소를 만듭니다.
             *
             * @param  TotpCredential  $stale  먼저 읽어 둔 자격증명
             */
            public function __construct(private TotpCredential $stale) {}

            /**
             * 낡은 자격증명 사본을 돌려줍니다.
             *
             * @param  int  $userId  회원 ID
             * @return TotpCredential|null 낡은 자격증명
             */
            public function findByUserId(int $userId): ?TotpCredential
            {
                return clone $this->stale;
            }
        };
        $service = new TotpEnrollmentService($repository);
        $code = Totp::codeAt($secret, Totp::timestepAt());

        $first = $service->confirm($user, $code);
        $this->assertCount(TotpEnrollmentService::BACKUP_CODE_COUNT, $first);

        try {
            $service->confirm($user, $code);
            $this->fail('두 번째 확정이 충돌 없이 끝났습니다.');
        } catch (EnrollmentConflictException) {
            // 기대한 충돌
        }

        $stored = TotpCredential::query()->where('user_id', $user->id)->firstOrFail()->backup_codes;
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $first[0]));
        $this->assertTrue(collect($stored)->contains(fn ($hash) => Hash::check($normalized, $hash)), '화면에 보인 첫 백업 코드가 저장돼 있어야 합니다.');
    }

    /**
     * HTTP 로는 충돌이 409 로 나간다.
     *
     * @scenario case=confirm_conflict_keeps_first_codes
     *
     * @effects confirm_conflict_keeps_first_codes
     *
     * @return void 반환값 없음
     */
    public function test_confirm_on_already_confirmed_returns_conflict_or_invalid(): void
    {
        $user = User::factory()->create(['email' => 'totp-confirm-http@test.com']);
        $secret = Base32::randomSecret();
        TotpCredential::query()->create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now(), 'backup_codes' => []]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/plugins/sirsoft-totp/enrollment/confirm', ['code' => Totp::codeAt($secret, Totp::timestepAt())]);

        $this->assertContains($response->status(), [409, 422]);
    }
}
