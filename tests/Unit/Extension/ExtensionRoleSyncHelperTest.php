<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mockery;
use Tests\TestCase;

/**
 * ExtensionRoleSyncHelper 단위 테스트
 *
 * 확장 역할/권한 동기화 헬퍼의 핵심 로직을 검증합니다.
 * user_overrides 기반 필드 보호 동작을 검증합니다.
 */
class ExtensionRoleSyncHelperTest extends TestCase
{
    private RoleRepositoryInterface $roleRepository;

    private PermissionRepositoryInterface $permissionRepository;

    private ExtensionRoleSyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleRepository = Mockery::mock(RoleRepositoryInterface::class);
        $this->permissionRepository = Mockery::mock(PermissionRepositoryInterface::class);

        $this->helper = new ExtensionRoleSyncHelper(
            $this->roleRepository,
            $this->permissionRepository,
        );
    }

    /**
     * Eloquent 모델 프로퍼티를 안전하게 설정하기 위한 헬퍼
     *
     * @param  string  $modelClass  모델 클래스명
     * @param  array  $attributes  설정할 속성
     * @return \Mockery\MockInterface
     */
    private function createModelMock(string $modelClass, array $attributes = []): \Mockery\MockInterface
    {
        $mock = Mockery::mock($modelClass)->makePartial();

        foreach ($attributes as $key => $value) {
            $mock->{$key} = $value;
        }

        return $mock;
    }

    // =========================================================
    // syncRole 테스트
    // =========================================================

    /**
     * 신규 역할 생성 시 user_overrides 없이 생성되는지 테스트
     */
    public function test_sync_role_creates_new_role_without_user_overrides(): void
    {
        $name = ['ko' => '게시판 관리자', 'en' => 'Board Manager'];
        $description = ['ko' => '게시판 관리 역할', 'en' => 'Board management role'];

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->once()
            ->with('board-manager')
            ->andReturnNull();

        $expectedRole = $this->createModelMock(Role::class);

        $this->roleRepository->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                ['identifier' => 'board-manager'],
                Mockery::on(function ($values) use ($name, $description) {
                    return $values['name'] === $name
                        && $values['description'] === $description
                        && $values['extension_type'] === ExtensionOwnerType::Module
                        && $values['extension_identifier'] === 'sirsoft-board'
                        && $values['is_active'] === true
                        && ! array_key_exists('user_overrides', $values);
                })
            )
            ->andReturn($expectedRole);

        $result = $this->helper->syncRole(
            identifier: 'board-manager',
            newName: $name,
            newDescription: $description,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            otherAttributes: ['is_active' => true],
        );

        $this->assertSame($expectedRole, $result);
    }

    /**
     * user_overrides에 "name"이 있으면 name 갱신을 건너뛰는지 테스트
     */
    public function test_sync_role_skips_user_modified_name(): void
    {
        $newName = ['ko' => 'Board 관리자', 'en' => 'Board Admin'];
        $newDescription = ['ko' => '게시판 관리 역할 v2', 'en' => 'Board management role v2'];

        $existingRole = $this->createModelMock(Role::class, [
            'name' => ['ko' => '우리 게시판 관리자', 'en' => 'Our Board Manager'],
            'description' => ['ko' => '이전 설명', 'en' => 'Previous description'],
            'user_overrides' => ['name'],  // 유저가 name을 수정한 적 있음
        ]);

        $freshRole = $this->createModelMock(Role::class);
        $existingRole->shouldReceive('fresh')->once()->andReturn($freshRole);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->once()
            ->with('board-manager')
            ->andReturn($existingRole);

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($existingRole, Mockery::on(function ($data) use ($newDescription) {
                // name은 user_overrides에 있으므로 포함되지 않아야 함
                return ! array_key_exists('name', $data)
                    && $data['description'] === $newDescription;
            }))
            ->andReturnTrue();

        $result = $this->helper->syncRole(
            identifier: 'board-manager',
            newName: $newName,
            newDescription: $newDescription,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertSame($freshRole, $result);
    }

    /**
     * user_overrides에 "description"이 있으면 description 갱신을 건너뛰는지 테스트
     */
    public function test_sync_role_skips_user_modified_description(): void
    {
        $newName = ['ko' => 'Board 관리자', 'en' => 'Board Admin'];
        $newDescription = ['ko' => '게시판 관리 역할 v2', 'en' => 'Board management role v2'];

        $existingRole = $this->createModelMock(Role::class, [
            'name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager'],
            'description' => ['ko' => '유저가 수정한 설명', 'en' => 'User modified description'],
            'user_overrides' => ['description'],  // 유저가 description을 수정한 적 있음
        ]);

        $freshRole = $this->createModelMock(Role::class);
        $existingRole->shouldReceive('fresh')->once()->andReturn($freshRole);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->once()
            ->with('board-manager')
            ->andReturn($existingRole);

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($existingRole, Mockery::on(function ($data) use ($newName) {
                // description은 user_overrides에 있으므로 포함되지 않아야 함
                return $data['name'] === $newName
                    && ! array_key_exists('description', $data);
            }))
            ->andReturnTrue();

        $result = $this->helper->syncRole(
            identifier: 'board-manager',
            newName: $newName,
            newDescription: $newDescription,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertSame($freshRole, $result);
    }

    /**
     * user_overrides에 없는 필드만 갱신되는지 테스트
     */
    public function test_sync_role_updates_unmodified_fields(): void
    {
        $newName = ['ko' => 'Board 관리자', 'en' => 'Board Admin'];
        $newDescription = ['ko' => '게시판 관리 역할 v2', 'en' => 'Board management role v2'];

        $existingRole = $this->createModelMock(Role::class, [
            'name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager'],
            'description' => ['ko' => '게시판 관리 역할', 'en' => 'Board management role'],
            'user_overrides' => [],  // 유저가 아무것도 수정하지 않음
        ]);

        $freshRole = $this->createModelMock(Role::class);
        $existingRole->shouldReceive('fresh')->once()->andReturn($freshRole);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->once()
            ->with('board-manager')
            ->andReturn($existingRole);

        $this->roleRepository->shouldReceive('update')
            ->once()
            ->with($existingRole, Mockery::on(function ($data) use ($newName, $newDescription) {
                return $data['name'] === $newName
                    && $data['description'] === $newDescription;
            }))
            ->andReturnTrue();

        $result = $this->helper->syncRole(
            identifier: 'board-manager',
            newName: $newName,
            newDescription: $newDescription,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
        );

        $this->assertSame($freshRole, $result);
    }

    // =========================================================
    // syncPermission 테스트
    // =========================================================

    /**
     * 권한은 항상 확장 정의값으로 덮어쓰는지 테스트
     */
    public function test_sync_permission_always_overwrites(): void
    {
        $name = ['ko' => '상품 조회', 'en' => 'View Products'];
        $description = ['ko' => '상품 목록을 조회합니다', 'en' => 'View product list'];

        $expectedPermission = $this->createModelMock(Permission::class);

        $this->permissionRepository->shouldReceive('updateOrCreate')
            ->once()
            ->with(
                ['identifier' => 'ecommerce.products.read'],
                Mockery::on(function ($values) use ($name, $description) {
                    return $values['name'] === $name
                        && $values['description'] === $description
                        && $values['extension_type'] === ExtensionOwnerType::Module
                        && $values['extension_identifier'] === 'sirsoft-ecommerce';
                })
            )
            ->andReturn($expectedPermission);

        $result = $this->helper->syncPermission(
            identifier: 'ecommerce.products.read',
            newName: $name,
            newDescription: $description,
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-ecommerce',
        );

        $this->assertSame($expectedPermission, $result);
    }

    // =========================================================
    // cleanupStalePermissions 테스트
    // =========================================================

    /**
     * stale 권한이 삭제되는지 테스트 (자식 포함)
     */
    public function test_cleanup_stale_permissions_removes_deleted_permissions(): void
    {
        $currentPerm = $this->createModelMock(Permission::class, [
            'identifier' => 'ecommerce.products.read',
        ]);

        $childPerm = $this->createModelMock(Permission::class, [
            'identifier' => 'child-perm',
        ]);
        $childPermRoles = Mockery::mock(BelongsToMany::class);
        $childPermRoles->shouldReceive('detach')->once();
        $childPerm->shouldReceive('roles')->once()->andReturn($childPermRoles);

        $stalePerm = $this->createModelMock(Permission::class, [
            'identifier' => 'ecommerce.old-feature.read',
        ]);
        $stalePermRoles = Mockery::mock(BelongsToMany::class);
        $stalePermRoles->shouldReceive('detach')->once();
        $stalePerm->shouldReceive('roles')->once()->andReturn($stalePermRoles);

        $childrenCollection = new Collection([$childPerm]);
        $stalePerm->shouldReceive('getAttribute')
            ->with('children')
            ->andReturn($childrenCollection);

        $this->permissionRepository->shouldReceive('getByExtension')
            ->once()
            ->with(ExtensionOwnerType::Module, 'sirsoft-ecommerce')
            ->andReturn(new Collection([$currentPerm, $stalePerm]));

        $this->permissionRepository->shouldReceive('delete')
            ->once()
            ->with($childPerm)
            ->andReturnTrue();

        $this->permissionRepository->shouldReceive('delete')
            ->once()
            ->with($stalePerm)
            ->andReturnTrue();

        $deleted = $this->helper->cleanupStalePermissions(
            ExtensionOwnerType::Module,
            'sirsoft-ecommerce',
            ['ecommerce.products.read'],
        );

        $this->assertEquals(2, $deleted);
    }

    /**
     * 유효한 권한은 보존되는지 테스트
     */
    public function test_cleanup_stale_permissions_preserves_current_permissions(): void
    {
        $currentPerm = $this->createModelMock(Permission::class, [
            'identifier' => 'ecommerce.products.read',
        ]);

        $this->permissionRepository->shouldReceive('getByExtension')
            ->once()
            ->andReturn(new Collection([$currentPerm]));

        $this->permissionRepository->shouldNotReceive('delete');

        $deleted = $this->helper->cleanupStalePermissions(
            ExtensionOwnerType::Module,
            'sirsoft-ecommerce',
            ['ecommerce.products.read'],
        );

        $this->assertEquals(0, $deleted);
    }

    // =========================================================
    // syncAllRoleAssignments 테스트 (DB 기반 diff)
    // =========================================================

    /**
     * 신규 권한이 DB 기반 diff로 attach되는지 테스트
     */
    public function test_sync_all_role_assignments_attaches_new_permissions(): void
    {
        $permissionsRelation = Mockery::mock(BelongsToMany::class);
        $permissionsRelation->shouldReceive('whereIn')
            ->with('identifier', ['board.read', 'board.create'])
            ->once()
            ->andReturnSelf();
        $permissionsRelation->shouldReceive('pluck')
            ->with('identifier')
            ->once()
            ->andReturn(collect([])); // DB에 아직 없음

        $adminRole = $this->createModelMock(Role::class, [
            'identifier' => 'admin',
            'user_overrides' => [],
        ]);
        $adminRole->shouldReceive('permissions')->once()->andReturn($permissionsRelation);

        $readPerm = $this->createModelMock(Permission::class, ['id' => 1]);
        $createPerm = $this->createModelMock(Permission::class, ['id' => 2]);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('admin')
            ->once()
            ->andReturn($adminRole);

        $this->permissionRepository->shouldReceive('findByIdentifier')
            ->with('board.read')
            ->once()
            ->andReturn($readPerm);

        $this->permissionRepository->shouldReceive('findByIdentifier')
            ->with('board.create')
            ->once()
            ->andReturn($createPerm);

        $this->roleRepository->shouldReceive('attachPermission')
            ->once()
            ->with($adminRole, 1, Mockery::type('array'));

        $this->roleRepository->shouldReceive('attachPermission')
            ->once()
            ->with($adminRole, 2, Mockery::type('array'));

        $this->roleRepository->shouldNotReceive('detachPermission');

        $this->helper->syncAllRoleAssignments(
            permissionRoleMap: [
                'board.read' => ['admin'],
                'board.create' => ['admin'],
            ],
            allExtensionPermIdentifiers: ['board.read', 'board.create'],
        );
    }

    /**
     * 제거된 권한이 DB 기반 diff로 detach되는지 테스트
     */
    public function test_sync_all_role_assignments_detaches_removed_permissions(): void
    {
        $permissionsRelation = Mockery::mock(BelongsToMany::class);
        $permissionsRelation->shouldReceive('whereIn')
            ->with('identifier', ['board.read', 'board.create'])
            ->once()
            ->andReturnSelf();
        $permissionsRelation->shouldReceive('pluck')
            ->with('identifier')
            ->once()
            ->andReturn(collect(['board.read', 'board.create'])); // DB에 2개 있음

        $adminRole = $this->createModelMock(Role::class, [
            'identifier' => 'admin',
            'user_overrides' => [],
        ]);
        $adminRole->shouldReceive('permissions')->once()->andReturn($permissionsRelation);

        $createPerm = $this->createModelMock(Permission::class, ['id' => 2]);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('admin')
            ->once()
            ->andReturn($adminRole);

        // board.create는 새 정의에서 제거됨 → detach
        $this->permissionRepository->shouldReceive('findByIdentifier')
            ->with('board.create')
            ->once()
            ->andReturn($createPerm);

        $this->roleRepository->shouldNotReceive('attachPermission');

        $this->roleRepository->shouldReceive('detachPermission')
            ->once()
            ->with($adminRole, 2);

        $this->helper->syncAllRoleAssignments(
            permissionRoleMap: [
                'board.read' => ['admin'],  // board.create 제거
            ],
            allExtensionPermIdentifiers: ['board.read', 'board.create'],
        );
    }

    /**
     * user_overrides에 기록된 개별 권한 식별자만 보호되는지 테스트
     *
     * 시나리오: user_overrides에 "board.delete"가 있고, 확장이 board.delete를 정의에서 제거함
     * → board.delete는 보호되므로 detach하지 않음
     * → board.read, board.create는 보호되지 않으므로 정상 동기화
     */
    public function test_sync_all_role_assignments_skips_user_overridden_permissions(): void
    {
        $permissionsRelation = Mockery::mock(BelongsToMany::class);
        $permissionsRelation->shouldReceive('whereIn')
            ->with('identifier', ['board.read', 'board.create', 'board.delete'])
            ->once()
            ->andReturnSelf();
        $permissionsRelation->shouldReceive('pluck')
            ->with('identifier')
            ->once()
            ->andReturn(collect(['board.read', 'board.create', 'board.delete'])); // DB에 3개 있음

        $adminRole = $this->createModelMock(Role::class, [
            'identifier' => 'admin',
            'user_overrides' => ['board.delete'],  // 유저가 board.delete를 변경한 적 있음
        ]);
        $adminRole->shouldReceive('permissions')->once()->andReturn($permissionsRelation);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('admin')
            ->once()
            ->andReturn($adminRole);

        // board.delete는 보호됨 → detach 안 됨
        // board.read, board.create는 정의에 포함 + DB에 있음 → 변경 없음
        $this->roleRepository->shouldNotReceive('attachPermission');
        $this->roleRepository->shouldNotReceive('detachPermission');

        $this->helper->syncAllRoleAssignments(
            permissionRoleMap: [
                'board.read' => ['admin'],
                'board.create' => ['admin'],
                // board.delete는 정의에서 제거됨 → 보호되므로 detach 안 됨
            ],
            allExtensionPermIdentifiers: ['board.read', 'board.create', 'board.delete'],
        );
    }

    /**
     * user_overrides에 없는 권한은 정상적으로 동기화되는지 테스트
     *
     * 시나리오: user_overrides에 "board.delete"만 있고, 확장 업데이트로 board.update 추가
     * → board.delete는 보호 (현재 상태 유지)
     * → board.update는 보호되지 않으므로 새로 attach
     */
    public function test_sync_all_role_assignments_syncs_non_overridden_permissions(): void
    {
        $permissionsRelation = Mockery::mock(BelongsToMany::class);
        $permissionsRelation->shouldReceive('whereIn')
            ->with('identifier', ['board.read', 'board.create', 'board.update', 'board.delete'])
            ->once()
            ->andReturnSelf();
        $permissionsRelation->shouldReceive('pluck')
            ->with('identifier')
            ->once()
            ->andReturn(collect(['board.read', 'board.create'])); // DB에 2개만 있음 (유저가 board.delete 해제)

        $adminRole = $this->createModelMock(Role::class, [
            'identifier' => 'admin',
            'user_overrides' => ['board.delete'],  // 유저가 board.delete를 변경한 적 있음
        ]);
        $adminRole->shouldReceive('permissions')->once()->andReturn($permissionsRelation);

        $updatePerm = $this->createModelMock(Permission::class, ['id' => 4]);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('admin')
            ->once()
            ->andReturn($adminRole);

        // board.update는 새 정의에 있고 DB에 없고 보호되지 않음 → attach
        $this->permissionRepository->shouldReceive('findByIdentifier')
            ->with('board.update')
            ->once()
            ->andReturn($updatePerm);

        $this->roleRepository->shouldReceive('attachPermission')
            ->once()
            ->with($adminRole, 4, Mockery::type('array'));

        // board.delete는 보호됨 → 정의에 있더라도 detach 안 됨
        $this->roleRepository->shouldNotReceive('detachPermission');

        $this->helper->syncAllRoleAssignments(
            permissionRoleMap: [
                'board.read' => ['admin'],
                'board.create' => ['admin'],
                'board.update' => ['admin'],  // 신규 추가
                'board.delete' => ['admin'],  // 확장은 다시 정의하지만, 유저가 해제한 상태이므로 보호
            ],
            allExtensionPermIdentifiers: ['board.read', 'board.create', 'board.update', 'board.delete'],
        );
    }

    /**
     * DB 기반 diff로 정확한 attach/detach가 이루어지는지 테스트
     *
     * 시나리오: DB에 [board.read] 있음, 정의에 [board.read, board.create]
     * → board.create만 attach, detach 없음
     */
    public function test_sync_all_role_assignments_uses_db_based_diff(): void
    {
        $permissionsRelation = Mockery::mock(BelongsToMany::class);
        $permissionsRelation->shouldReceive('whereIn')
            ->with('identifier', ['board.read', 'board.create'])
            ->once()
            ->andReturnSelf();
        $permissionsRelation->shouldReceive('pluck')
            ->with('identifier')
            ->once()
            ->andReturn(collect(['board.read'])); // DB에 board.read만 있음

        $adminRole = $this->createModelMock(Role::class, [
            'identifier' => 'admin',
            'user_overrides' => [],
        ]);
        $adminRole->shouldReceive('permissions')->once()->andReturn($permissionsRelation);

        $createPerm = $this->createModelMock(Permission::class, ['id' => 2]);

        $this->roleRepository->shouldReceive('findByIdentifier')
            ->with('admin')
            ->once()
            ->andReturn($adminRole);

        // board.create만 attach (board.read는 이미 DB에 있으므로 건너뜀)
        $this->permissionRepository->shouldReceive('findByIdentifier')
            ->with('board.create')
            ->once()
            ->andReturn($createPerm);

        $this->roleRepository->shouldReceive('attachPermission')
            ->once()
            ->with($adminRole, 2, Mockery::type('array'));

        $this->roleRepository->shouldNotReceive('detachPermission');

        $this->helper->syncAllRoleAssignments(
            permissionRoleMap: [
                'board.read' => ['admin'],
                'board.create' => ['admin'],
            ],
            allExtensionPermIdentifiers: ['board.read', 'board.create'],
        );
    }
}
