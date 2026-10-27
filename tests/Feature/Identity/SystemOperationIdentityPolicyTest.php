<?php

namespace Tests\Feature\Identity;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\User;
use App\Support\Auth\SystemOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * 시스템 작업 문맥과 본인인증 훅 정책 (#798 K19)
 *
 * 운영자가 회원 수정 훅(`core.user.before_update`)에 본인인증 정책을 걸면, 행위자가 없는 시스템 경로(동기화·프로비저닝·예약 탈퇴)는
 * 대상 회원으로 판정돼 428 로 멈춘다. `SystemOperation::run()` 안에서는 정책을 건너뛰고, 문맥은 중첩과 예외 뒤에도 복원된다.
 */
class SystemOperationIdentityPolicyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 회원 수정 훅에 거는 본인인증 정책을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        IdentityPolicy::query()->create([
            'key' => 'test.system_operation.'.Str::lower(Str::random(8)),
            'scope' => 'hook',
            'target' => 'core.user.before_update',
            'purpose' => 'sensitive_action',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'conditions' => null,
            'source_type' => 'core',
            'source_identifier' => 'core',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);
    }

    /**
     * 회원 수정 훅을 발화합니다 (행위자 없음).
     *
     * @return void 반환값 없음
     */
    private function fireUpdateHook(): void
    {
        Auth::forgetGuards();
        $user = User::factory()->create();

        HookManager::doAction('core.user.before_update', $user, ['name' => 'changed-'.Str::random(4)]);
    }

    /**
     * 시스템 작업 문맥 밖에서는 정책이 대상 회원으로 판정돼 본인인증을 요구한다.
     *
     * @scenario context=outside
     *
     * @effects hook_policy_enforced_without_system_context
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 문맥_밖에서는_본인인증을_요구한다(): void
    {
        $this->expectException(IdentityVerificationRequiredException::class);

        $this->fireUpdateHook();
    }

    /**
     * 시스템 작업 문맥 안에서는 정책을 건너뛴다.
     *
     * @scenario context=inside
     *
     * @effects system_operation_skips_hook_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 문맥_안에서는_정책을_건너뛴다(): void
    {
        $result = SystemOperation::run('test:sync', function () {
            $this->fireUpdateHook();

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse(SystemOperation::active());
    }

    /**
     * 중첩 실행 뒤 바깥 문맥이 유지되고, 다 끝나면 문맥 밖으로 돌아온다.
     *
     * @scenario context=nested
     *
     * @effects system_operation_nesting_restores
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 중첩_뒤에도_문맥이_복원된다(): void
    {
        SystemOperation::run('outer', function () {
            SystemOperation::run('inner', fn () => $this->assertSame('inner', SystemOperation::reason()));

            $this->assertTrue(SystemOperation::active());
            $this->assertSame('outer', SystemOperation::reason());
            $this->fireUpdateHook();
        });

        $this->assertFalse(SystemOperation::active());
        $this->assertNull(SystemOperation::reason());
    }

    /**
     * 예외가 나도 문맥이 복원돼 그 뒤의 사람 경로는 다시 정책을 받는다.
     *
     * @scenario context=exception
     *
     * @effects system_operation_exception_restores
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 예외_뒤에도_문맥이_복원된다(): void
    {
        try {
            SystemOperation::run('boom', function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // 의도한 예외
        }

        $this->assertFalse(SystemOperation::active());

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->fireUpdateHook();
    }
}
