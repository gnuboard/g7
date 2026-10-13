<?php

namespace Tests\Unit\Listeners;

use App\Listeners\CoreActivityLogListener;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 코어 활동 로그 일괄 핸들러 원자화 테스트 (#139)
 *
 * 큐로 도는 일괄 핸들러가 항목별 기록 루프를 한 트랜잭션으로 감싸는지 — 활동 로그 행이 생성되는 순간의
 * 트랜잭션 깊이가 모두 「기준 + 1」인지 — 로 확인한다. 화면 건수로는 원자성을 판별할 수 없다(수정 전후 같은 건수).
 */
class CoreActivityLogListenerBulkAtomicTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, int> 활동 로그 행 생성 시점의 트랜잭션 깊이 */
    private array $levels = [];

    /**
     * 활동 로그 생성 시점의 트랜잭션 깊이를 모읍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->levels = [];
        Event::listen('eloquent.created: '.ActivityLog::class, function () {
            $this->levels[] = DB::transactionLevel();
        });
    }

    /**
     * 회원 일괄 상태 변경 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=user_bulk_update
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_bulk_update_rows_are_written_in_one_transaction(): void
    {
        $users = User::factory()->count(3)->create();
        $base = DB::transactionLevel();

        $this->listener()->handleUserAfterBulkUpdate($users->pluck('uuid')->all(), 'active', 3);

        $this->assertAtomic($base, 3);
    }

    /**
     * 회원 일괄 역할 변경 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=user_bulk_roles
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_bulk_roles_rows_are_written_in_one_transaction(): void
    {
        $users = User::factory()->count(2)->create();
        $role = Role::factory()->create();
        $base = DB::transactionLevel();

        $this->listener()->handleUserAfterBulkUpdateRoles(
            $users->pluck('id')->all(),
            [$role->id],
            'add',
            $users->map(fn (User $u) => ['user_id' => $u->id, 'added' => [$role->id], 'removed' => []])->all(),
        );

        $this->assertAtomic($base, 2);
    }

    /**
     * 스케줄 일괄 활성 변경 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=schedule_bulk_update
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_schedule_bulk_update_rows_are_written_in_one_transaction(): void
    {
        $ids = collect(range(1, 3))->map(fn (int $i) => Schedule::create([
            'name' => "qa139 일괄 {$i}",
            'description' => 'qa139',
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => true,
        ])->id)->all();
        $base = DB::transactionLevel();

        $this->listener()->handleScheduleAfterBulkUpdate($ids, false, 3);

        $this->assertAtomic($base, 3);
    }

    /**
     * 스케줄 일괄 삭제 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=schedule_bulk_delete
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_schedule_bulk_delete_rows_are_written_in_one_transaction(): void
    {
        $base = DB::transactionLevel();

        $this->listener()->handleScheduleAfterBulkDelete([901391, 901392], 2, [901391 => ['name' => 'a'], 901392 => ['name' => 'b']]);

        $this->assertAtomic($base, 2);
    }

    /**
     * 첨부 일괄 삭제 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=attachment_bulk_delete
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_attachment_bulk_delete_rows_are_written_in_one_transaction(): void
    {
        $base = DB::transactionLevel();

        $this->listener()->handleAttachmentAfterBulkDelete('core', 3, [911391, 911392, 911393]);

        $this->assertAtomic($base, 3);
    }

    /**
     * 기대 행 수만큼 활동 로그가 생겼고 모두 「기준 + 1」 깊이에서 기록됐는지 확인합니다.
     *
     * @param  int  $base  호출 전 트랜잭션 깊이
     * @param  int  $expected  기대 행 수
     * @return void 반환값 없음
     */
    private function assertAtomic(int $base, int $expected): void
    {
        $this->assertCount($expected, $this->levels, '활동 로그 행 수가 다르다');
        $this->assertSame(array_fill(0, $expected, $base + 1), $this->levels, '일괄 기록이 한 트랜잭션 안에서 일어나지 않았다');
    }

    /**
     * 리스너를 만듭니다.
     *
     * @return CoreActivityLogListener 리스너
     */
    private function listener(): CoreActivityLogListener
    {
        return app(CoreActivityLogListener::class);
    }
}
