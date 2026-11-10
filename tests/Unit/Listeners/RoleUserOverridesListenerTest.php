<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Listeners\RoleUserOverridesListener;
use App\Models\Role;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\PureTestCase;

/**
 * RoleUserOverridesListener 테스트
 *
 * 역할 업데이트/권한 동기화 시 user_overrides 필드 기록을 검증합니다.
 */
class RoleUserOverridesListenerTest extends PureTestCase
{
    use MockeryPHPUnitIntegration;

    private RoleUserOverridesListener $listener;

    private MockInterface $roleRepository;

    /**
     * 역할 저장소 목을 만들고 그것을 주입한 RoleUserOverridesListener 를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->roleRepository = Mockery::mock(RoleRepositoryInterface::class);
        $this->listener = new RoleUserOverridesListener($this->roleRepository);
    }

    /**
     * getSubscribedHooks()가 before_update·after_sync_permissions 훅을 우선순위 10 의 각 핸들러에 연결한다.
     *
     * @scenario case=hook_subscription
     *
     * @effects role_hooks_subscribed_with_priority
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_correct_mapping(): void
    {
        $hooks = RoleUserOverridesListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.role.before_update', $hooks);
        $this->assertArrayHasKey('core.role.after_sync_permissions', $hooks);
        $this->assertEquals('handleBeforeUpdate', $hooks['core.role.before_update']['method']);
        $this->assertEquals('handleAfterSyncPermissions', $hooks['core.role.after_sync_permissions']['method']);
        $this->assertEquals(10, $hooks['core.role.before_update']['priority']);
        $this->assertEquals(10, $hooks['core.role.after_sync_permissions']['priority']);
    }

    /**
     * name 을 바꾸면 user_overrides 에 name.ko·name.en 하위 키만 기록하고 description 키는 넣지 않는다.
     *
     * @scenario case=field_update
     *
     * @effects changed_translatable_subkeys_recorded
     *
     * @return void 반환값 없음
     */
    public function test_name_change_records_user_override(): void
    {
        $role = new Role;
        $role->name = ['ko' => '관리자', 'en' => 'Admin'];
        $role->description = ['ko' => '설명', 'en' => 'Description'];
        $role->forceFill(['user_overrides' => []]);

        $data = ['name' => ['ko' => '최고관리자', 'en' => 'Super Admin']];

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($role, Mockery::on(function ($arg) {
                // 다국어 JSON name/description 은 sub-key dot-path 단위 (7.0.0-beta.4+)
                return in_array('name.ko', $arg['user_overrides'], true)
                    && in_array('name.en', $arg['user_overrides'], true)
                    && ! in_array('description.ko', $arg['user_overrides'], true)
                    && ! in_array('description.en', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($role, $data);
    }

    /**
     * description 을 바꾸면 user_overrides 에 description.ko·description.en 하위 키만 기록한다.
     *
     * @scenario case=field_update
     *
     * @effects changed_translatable_subkeys_recorded
     *
     * @return void 반환값 없음
     */
    public function test_description_change_records_user_override(): void
    {
        $role = new Role;
        $role->name = ['ko' => '관리자', 'en' => 'Admin'];
        $role->description = ['ko' => '설명', 'en' => 'Description'];
        $role->forceFill(['user_overrides' => []]);

        $data = ['description' => ['ko' => '새 설명', 'en' => 'New Description']];

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($role, Mockery::on(function ($arg) {
                return in_array('description.ko', $arg['user_overrides'], true)
                    && in_array('description.en', $arg['user_overrides'], true)
                    && ! in_array('name.ko', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleBeforeUpdate($role, $data);
    }

    /**
     * 기존과 같은 값으로 갱신하면 변경으로 보지 않아 저장소 update 를 호출하지 않는다.
     *
     * @scenario case=field_update
     *
     * @effects unchanged_values_not_recorded
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_field_not_recorded(): void
    {
        $role = new Role;
        $role->name = ['ko' => '관리자', 'en' => 'Admin'];
        $role->description = ['ko' => '설명', 'en' => 'Description'];
        $role->forceFill(['user_overrides' => []]);

        // 동일한 값으로 업데이트 → 변경 아님
        $data = ['name' => ['ko' => '관리자', 'en' => 'Admin']];

        $this->roleRepository->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($role, $data);
    }

    /**
     * 권한 동기화로 제거된 권한 식별자만 user_overrides 에 기록하고 유지된 권한은 넣지 않는다.
     *
     * @scenario case=permission_sync
     *
     * @effects changed_permission_identifiers_recorded
     *
     * @return void 반환값 없음
     */
    public function test_sync_permissions_records_changed_identifiers(): void
    {
        $role = new Role;
        $role->forceFill(['user_overrides' => []]);

        $previous = ['boards.read', 'boards.create', 'boards.delete'];
        $current = ['boards.read', 'boards.create'];  // boards.delete 제거

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($role, Mockery::on(function ($arg) {
                return in_array('boards.delete', $arg['user_overrides'], true)
                    && ! in_array('boards.read', $arg['user_overrides'], true)
                    && ! in_array('boards.create', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleAfterSyncPermissions($role, $previous, $current);
    }

    /**
     * 동기화 전후 권한 목록이 같으면 저장소 update 를 호출하지 않는다.
     *
     * @scenario case=permission_sync
     *
     * @effects unchanged_values_not_recorded
     *
     * @return void 반환값 없음
     */
    public function test_sync_permissions_no_change_no_record(): void
    {
        $role = new Role;
        $role->forceFill(['user_overrides' => []]);

        $permissions = ['boards.read', 'boards.create'];

        // 이전/이후 동일 → update 호출 안 됨
        $this->roleRepository->shouldNotReceive('update');

        $this->listener->handleAfterSyncPermissions($role, $permissions, $permissions);
    }

    /**
     * 바뀐 하위 키가 이미 user_overrides 에 모두 있으면 중복 추가 없이 update 를 생략한다.
     *
     * @scenario case=field_update
     *
     * @effects existing_override_not_duplicated
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_override_not_added(): void
    {
        // name.ko/name.en 가 이미 user_overrides 에 있는 경우 (sub-key dot-path 형식)
        $role = new Role;
        $role->name = ['ko' => '관리자', 'en' => 'Admin'];
        $role->description = ['ko' => '설명', 'en' => 'Description'];
        $role->forceFill(['user_overrides' => ['name.ko', 'name.en']]);

        $data = ['name' => ['ko' => '최고관리자', 'en' => 'Super Admin']];

        // 모든 변경 sub-key 가 이미 있으므로 update 호출 안 됨
        $this->roleRepository->shouldNotReceive('update');

        $this->listener->handleBeforeUpdate($role, $data);
    }

    /**
     * 제거된 권한 식별자가 이미 user_overrides 에 있으면 중복 추가 없이 update 를 생략한다.
     *
     * @scenario case=permission_sync
     *
     * @effects existing_override_not_duplicated
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_permission_identifier_not_added(): void
    {
        $role = new Role;
        $role->forceFill(['user_overrides' => ['boards.delete']]);

        $previous = ['boards.read', 'boards.create', 'boards.delete'];
        $current = ['boards.read', 'boards.create'];  // boards.delete 제거

        // boards.delete가 이미 있으므로 update 호출 안 됨
        $this->roleRepository->shouldNotReceive('update');

        $this->listener->handleAfterSyncPermissions($role, $previous, $current);
    }

    /**
     * 권한 동기화로 추가된 권한 식별자도 개별로 user_overrides 에 기록한다.
     *
     * @scenario case=permission_sync
     *
     * @effects changed_permission_identifiers_recorded
     *
     * @return void 반환값 없음
     */
    public function test_sync_permissions_records_added_identifiers(): void
    {
        $role = new Role;
        $role->forceFill(['user_overrides' => []]);

        $previous = ['boards.read'];
        $current = ['boards.read', 'boards.create', 'boards.delete'];  // 2개 추가

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($role, Mockery::on(function ($arg) {
                return in_array('boards.create', $arg['user_overrides'], true)
                    && in_array('boards.delete', $arg['user_overrides'], true)
                    && ! in_array('boards.read', $arg['user_overrides'], true);
            }))
            ->andReturn(true);

        $this->listener->handleAfterSyncPermissions($role, $previous, $current);
    }
}
