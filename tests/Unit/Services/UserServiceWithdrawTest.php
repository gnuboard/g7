<?php

namespace Tests\Unit\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * User::withdraw() 익명화 규칙 단위 테스트 (공개이슈 #112)
 *
 * 서비스 계층의 원자성은 `tests/Feature/Api/Auth/WithdrawAtomicityTest.php` 가 다루고,
 * 여기서는 모델이 만드는 **익명화 값 자체**의 규칙을 고정한다 —
 * 접미사 형식·유일성·멱등·컬럼 길이 클램프.
 *
 * @scenario target_account=normal, target_account=already_withdrawn, field_length=exceeds_limit
 *
 * @effects suffix_includes_user_id_for_structural_uniqueness, long_values_truncated_within_column_limits, withdraw_is_idempotent
 */
class UserServiceWithdrawTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 이메일 접미사는 날짜 + 사용자 ID 로 구성된다.
     */
    public function test_email_suffix_contains_date_and_user_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 09:00:00'));

        $user = User::factory()->create(['email' => 'suffix@example.com']);

        $this->assertTrue($user->withdraw());

        $this->assertSame('suffix@example.com_deleted_20260813_'.$user->id, $user->email);
    }

    /**
     * 같은 날 같은 원본 이메일이어도 사용자마다 결과가 다르다 (구조적 유일).
     */
    public function test_same_original_email_yields_distinct_anonymized_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 09:00:00'));

        $first = User::factory()->create(['email' => 'dup@example.com']);
        $first->withdraw();

        $second = User::factory()->create(['email' => 'dup@example.com']);
        $second->withdraw();

        $this->assertNotSame($first->email, $second->email);
    }

    /**
     * 이름·닉네임 접미사 규칙.
     */
    public function test_name_and_nickname_suffixes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 09:00:00'));

        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);

        $user->withdraw();

        $this->assertSame('홍길동_탈퇴_20260813', $user->name);
        $this->assertSame('길동이_탈퇴', $user->nickname);
    }

    /**
     * 닉네임이 없으면 접미사를 붙이지 않는다.
     */
    public function test_null_nickname_is_left_untouched(): void
    {
        $user = User::factory()->create(['nickname' => null]);

        $user->withdraw();

        $this->assertNull($user->nickname);
    }

    /**
     * 이미 탈퇴한 계정은 값이 변하지 않는다 (멱등).
     */
    public function test_withdraw_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'idem@example.com', 'nickname' => '닉']);

        $user->withdraw();
        $snapshot = [$user->email, $user->name, $user->nickname, (string) $user->withdrawn_at];

        $this->assertTrue($user->withdraw());

        $this->assertSame($snapshot, [$user->email, $user->name, $user->nickname, (string) $user->withdrawn_at]);
    }

    /**
     * 컬럼 상한을 넘기지 않도록 원값 앞부분을 잘라 붙인다.
     */
    public function test_values_are_clamped_to_column_limits(): void
    {
        $limit = SchemaBuilder::$defaultStringLength ?? 255;

        $user = User::factory()->create([
            'email' => str_repeat('a', $limit - 12).'@example.com',
            'name' => str_repeat('나', $limit),
            'nickname' => str_repeat('다', User::NICKNAME_MAX_LENGTH),
        ]);

        $user->withdraw();

        $this->assertSame($limit, mb_strlen($user->email));
        $this->assertSame($limit, mb_strlen($user->name));
        $this->assertSame(User::NICKNAME_MAX_LENGTH, mb_strlen($user->nickname));

        // 잘렸어도 접미사는 온전히 남는다 (식별 규칙이 깨지면 안 된다)
        $this->assertStringEndsWith('_deleted_'.now()->format('Ymd').'_'.$user->id, $user->email);
        $this->assertStringEndsWith('_탈퇴', $user->nickname);
    }

    /**
     * 상태·탈퇴 일시가 함께 기록된다.
     */
    public function test_status_and_withdrawn_at_are_set(): void
    {
        $user = User::factory()->create();

        $user->withdraw();

        $this->assertSame(UserStatus::Withdrawn->value, $user->status);
        $this->assertNotNull($user->withdrawn_at);
        $this->assertTrue($user->isWithdrawn());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
