<?php

namespace Tests\Feature\UserOverrides;

use App\Models\Menu;
use App\Models\Role;
use App\Services\MenuService;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 큐 워커가 도는 운영 환경에서도 메뉴·역할의 운영자 수정이 user_overrides 에 기록된다 (#138 후속 전수 점검 2번)
 *
 * 수정 직전 훅(before_update)의 리스너는 "지금 값"과 "바꿀 값"을 비교해 운영자가 고친 필드를 적는다.
 * 이 리스너가 큐로 가면 워커는 모델을 DB 에서 다시 읽는데, 그때는 이미 수정이 끝나 비교 차이가 0 이다.
 * 기록이 빠지면 다음 확장 업데이트가 운영자가 고친 이름을 확장 기본값으로 덮는다.
 *
 * 테스트 기본 큐는 sync 라 이 결함이 보이지 않는다 — 여기서는 database 큐로 발화하고 워커를 따로 돌린다.
 */
class UserOverridesQueuedWorkerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 큐를 database 로 바꿔 훅 리스너가 워커에서 돌게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 메뉴 이름을 고치면 워커가 돈 뒤에도 name 이 운영자 수정으로 기록된다.
     *
     * @scenario entity=menu
     *
     * @effects operator_edit_recorded_under_queue_worker
     *
     * @return void 반환값 없음
     */
    public function test_menu_name_edit_is_recorded_when_listeners_run_on_worker(): void
    {
        $menu = Menu::factory()->create(['name' => ['ko' => '원래 이름', 'en' => 'Original']]);

        app(MenuService::class)->updateMenu($menu, ['name' => ['ko' => '운영자 이름', 'en' => 'Original']]);
        $this->assertGreaterThan(0, DB::table('jobs')->count(), '훅 리스너가 큐에 쌓이지 않았다 — 워커 경로를 재지 못한다');
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스(수백 MB)가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $this->assertContains('name.ko', $menu->fresh()->user_overrides ?? [], '운영자가 고친 메뉴 이름이 user_overrides 에 없다');
    }

    /**
     * 역할 설명을 고치면 워커가 돈 뒤에도 description 이 운영자 수정으로 기록된다.
     *
     * @scenario entity=role
     *
     * @effects operator_edit_recorded_under_queue_worker
     *
     * @return void 반환값 없음
     */
    public function test_role_description_edit_is_recorded_when_listeners_run_on_worker(): void
    {
        $role = Role::factory()->create(['description' => ['ko' => '원래 설명', 'en' => 'Original']]);

        app(RoleService::class)->updateRole($role, ['description' => ['ko' => '운영자 설명', 'en' => 'Original']]);
        $this->assertGreaterThan(0, DB::table('jobs')->count(), '훅 리스너가 큐에 쌓이지 않았다 — 워커 경로를 재지 못한다');
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스(수백 MB)가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $this->assertContains('description.ko', $role->fresh()->user_overrides ?? [], '운영자가 고친 역할 설명이 user_overrides 에 없다');
    }
}
