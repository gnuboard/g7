<?php

namespace Tests\Feature\Database;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Sample\ActivityLogSampleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 코어 활동 로그 샘플 시더는 자기가 만든 샘플 로그만 다시 만들고 실제 활동 로그는 지우지 않는다 (총괄 결정 — 게시판·이커머스 E-15·B-5 와 같은 방식).
 *
 * 종전에는 모듈 로그가 아닌 코어 활동 로그 전체를 지웠다 — 설치가 끝난 사이트에서 `db:seed --sample` 을 돌리면 보관 대상 활동 로그가
 * 사라졌다. 이제 시더가 만든 행에 `properties.seeded_by='core:sample'` 표식을 달고, 다시 실행할 때는 그 표식 행만 지운다.
 */
class ActivityLogSampleSeederPreservesRealLogsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 설치 상태(실제 로그 있음)에서 두 번 돌려도 실제 로그는 남고, 샘플은 표식을 달고 쌓이지 않고 다시 만들어진다.
     *
     * @scenario existing_logs=real_core_logs, runs=twice
     *
     * @effects sample_rerun_keeps_real_activity_logs_and_replaces_only_marked_samples
     *
     * @return void 반환값 없음
     */
    public function test_rerun_keeps_real_logs_and_replaces_only_samples(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('identifier', 'admin')->firstOrFail()->id);

        $real = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'description_key' => 'activity_log.description.user_update',
            'description_params' => ['name' => 'real'],
            'user_id' => $admin->id,
            'properties' => ['name' => 'real operator action'],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->seed(ActivityLogSampleSeeder::class);
        $afterFirst = ActivityLog::where('properties->seeded_by', 'core:sample')->count();
        $this->seed(ActivityLogSampleSeeder::class);
        $afterSecond = ActivityLog::where('properties->seeded_by', 'core:sample')->count();

        $this->assertNotNull(ActivityLog::find($real->id), '샘플 시더가 실제 활동 로그를 지우면 안 된다');
        $this->assertGreaterThan(0, $afterFirst, '샘플 로그에 표식이 달려야 한다');
        $this->assertSame(
            ActivityLog::where('description_key', 'not like', '%::%')->where('id', '!=', $real->id)->count(),
            $afterSecond,
            '시더가 만든 코어 로그는 모두 표식을 단다'
        );
        $this->assertEqualsWithDelta($afterFirst, $afterSecond, $afterFirst * 0.5 + 5, '재실행은 샘플을 쌓지 않고 다시 만든다');
    }
}
