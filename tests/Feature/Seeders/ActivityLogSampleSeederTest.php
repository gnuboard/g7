<?php

namespace Tests\Feature\Seeders;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Sample\ActivityLogSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 활동 로그 샘플 시더 테스트.
 *
 * 이 시더는 코어 로그만 다룬다. 종전에는 확장 로그를 지우지 않으려고 특정 확장 식별자 세 개를
 * 적어 두었고, 확장 설치·설정 로그의 대상도 식별자 다섯 개(존재하지 않는 플러그인 포함)를 적어
 * 두었다. 이제 확장 로그는 설명 키의 `{확장}::` 네임스페이스로 가려 보존하고, 확장 로그의 대상은
 * 실제로 설치된 확장에서 고른다(2026-09-28 결정).
 */
class ActivityLogSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시더가 요구하는 관리자 계정을 만듭니다.
     *
     * @return User 관리자 계정
     */
    private function makeAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        return $admin;
    }

    /**
     * 확장 로그는 네임스페이스로 가려 지우지 않고, 코어 로그만 새로 채운다.
     *
     * @scenario log_origin=extension
     *
     * @effects extension_namespaced_logs_survive_core_reseed
     *
     * @return void 반환값 없음
     */
    public function test_extension_namespaced_logs_are_preserved(): void
    {
        $admin = $this->makeAdmin();

        $extensionLog = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'probe.created',
            'description_key' => 'probe-extension::activity_log.description.probe_created',
            'description_params' => [],
            'user_id' => $admin->id,
        ]);
        $staleCoreLog = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'description_key' => 'activity_log.description.user_update',
            'description_params' => [],
            'user_id' => $admin->id,
        ]);

        $this->seed(ActivityLogSampleSeeder::class);

        $this->assertTrue(ActivityLog::whereKey($extensionLog->id)->exists(), '확장 네임스페이스 로그가 지워졌습니다.');
        $this->assertFalse(ActivityLog::whereKey($staleCoreLog->id)->exists(), '기존 코어 로그는 다시 채우기 전에 지워져야 합니다.');
    }

    /**
     * 확장 설치·설정 로그의 대상은 실제로 설치된 확장뿐이다.
     *
     * @scenario log_origin=core
     *
     * @effects extension_lifecycle_logs_target_installed_extensions_only
     *
     * @return void 반환값 없음
     */
    public function test_extension_lifecycle_logs_target_installed_extensions_only(): void
    {
        $this->makeAdmin();

        Module::query()->delete();
        Module::factory()->create(['identifier' => 'probe-module', 'name' => ['ko' => '탐침', 'en' => 'Probe']]);

        $this->seed(ActivityLogSampleSeeder::class);

        $targets = ActivityLog::query()
            ->where(fn ($q) => $q->where('action', 'like', 'module.%')->orWhere('action', 'like', 'module_settings.%'))
            ->get()
            ->map(fn (ActivityLog $log) => $log->properties['identifier'] ?? null)
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['probe-module'], $targets, '모듈 로그 대상은 설치된 모듈이어야 합니다.');
    }
}
