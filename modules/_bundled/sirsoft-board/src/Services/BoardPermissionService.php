<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Exceptions\PermissionEscalationException;
use App\Models\Permission;
use App\Models\User;
use App\Support\PermissionEscalationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판 권한 관리 서비스
 *
 * 게시판별 동적 권한 생성/삭제를 담당합니다.
 * 계층 구조: sirsoft-board (루트) → sirsoft-board.{slug} (카테고리) → sirsoft-board.{slug}.{action} (액션)
 */
class BoardPermissionService
{
    use ChecksBoardPermission;

    /**
     * 모듈 identifier
     *
     * @var string
     */
    private const MODULE_IDENTIFIER = 'sirsoft-board';

    /**
     * 권한 저장소
     */
    private PermissionRepositoryInterface $permissionRepository;

    /**
     * 역할 저장소
     */
    private RoleRepositoryInterface $roleRepository;

    /**
     * 게시판 저장소
     */
    private BoardRepositoryInterface $boardRepository;

    /**
     * 생성자
     *
     * 인자 없이 만드는 호출처(API 문서 표본 생성 등)가 있어 저장소는 컨테이너에서 기본값을 받는다.
     *
     * @param  PermissionRepositoryInterface|null  $permissionRepository  권한 저장소
     * @param  RoleRepositoryInterface|null  $roleRepository  역할 저장소
     * @param  BoardRepositoryInterface|null  $boardRepository  게시판 저장소
     */
    public function __construct(
        ?PermissionRepositoryInterface $permissionRepository = null,
        ?RoleRepositoryInterface $roleRepository = null,
        ?BoardRepositoryInterface $boardRepository = null
    ) {
        $this->permissionRepository = $permissionRepository ?? app(PermissionRepositoryInterface::class);
        $this->roleRepository = $roleRepository ?? app(RoleRepositoryInterface::class);
        $this->boardRepository = $boardRepository ?? app(BoardRepositoryInterface::class);
    }

    /**
     * 게시판 권한을 생성하거나 업데이트합니다.
     *
     * 계층 구조:
     * - 1단계: sirsoft-board (모듈 루트, parent_id=null)
     * - 2단계: sirsoft-board.{slug} (게시판 카테고리, parent_id=모듈 루트 ID)
     * - 3단계: sirsoft-board.{slug}.{action} (개별 권한, parent_id=카테고리 ID)
     *
     * @param  Board  $board  대상 게시판
     * @param  array  $permissions  사용자가 설정한 권한 (프론트엔드에서 전달)
     * @param  array<string>|null  $onlyKeys  null이면 전체 적용, 지정 시 해당 키만 역할 업데이트
     * @return void
     */
    public function ensureBoardPermissions(Board $board, array $permissions = [], ?array $onlyKeys = null): void
    {
        // 1. 모듈 루트 권한 생성 (최초 1회, 이미 존재하면 skip)
        $moduleRootPermission = $this->permissionRepository->findByIdentifier(self::MODULE_IDENTIFIER)
            ?? $this->permissionRepository->create([
                'identifier' => self::MODULE_IDENTIFIER,
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                'description' => ['ko' => '게시판 모듈', 'en' => 'Board module'],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => PermissionType::Admin,
                'parent_id' => null,
            ]);

        // 2. 게시판 카테고리 권한 생성 (부모)
        $categoryPermission = $this->permissionRepository->updateOrCreate(
            ['identifier' => self::MODULE_IDENTIFIER.".{$board->slug}"],
            [
                'name' => [
                    'ko' => ($board->name['ko'] ?? $board->name['en'] ?? '').' 게시판',
                    'en' => ($board->name['en'] ?? $board->name['ko'] ?? '').' board',
                ],
                'description' => [
                    'ko' => ($board->name['ko'] ?? $board->name['en'] ?? '').' 게시판 권한',
                    'en' => ($board->name['en'] ?? $board->name['ko'] ?? '').' board permissions',
                ],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => PermissionType::Admin,
                'parent_id' => $moduleRootPermission->id,
            ]
        );

        // 3. 액션 권한 생성 (자식)
        $permissionDefinitions = config('sirsoft-board.board_permission_definitions');
        $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $defaultPermissions = $basicDefaults['default_board_permissions'] ?? [];

        // 설정을 읽지 못하면 게시판 생성 쪽(BoardService::injectBoardRolesToPermissions)과 같이 권한
        // 정의 키마다 빈 역할로 채운다. 그래야 아래에서 주입하는 게시판 자기 관리자·스텝 역할이 기본
        // ACL 로 분류돼, 「요청이 더 얹은 역할」로 권한 부여 상한 검사를 받지 않는다.
        if (empty($defaultPermissions)) {
            $defaultPermissions = array_fill_keys(array_keys($permissionDefinitions), []);
        }

        // 게시판 생성/수정 시($onlyKeys=null)에만 manager/step 자동주입
        // 일괄적용 시($onlyKeys 지정)에는 해당 게시판의 기존 manager/step을 건드리지 않음
        if ($onlyKeys === null) {
            $defaultPermissions = $this->injectBoardRoles($defaultPermissions, $board->slug);
        }

        foreach ($permissionDefinitions as $key => $definition) {
            // 권한 identifier 생성 (3단계: sirsoft-board.{slug}.{action})
            $identifier = self::MODULE_IDENTIFIER.".{$board->slug}.{$key}";

            // 권한 타입 결정: admin.*로 시작하면 Admin, 아니면 User
            $permissionType = str_starts_with($key, 'admin.')
                ? PermissionType::Admin
                : PermissionType::User;

            // 권한 생성 또는 업데이트
            $attributes = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => $permissionType,
                'parent_id' => $categoryPermission->id,
            ];

            // scope 메타데이터가 정의된 경우 resource_route_key/owner_key 설정
            if (isset($definition['scope'])) {
                $attributes['resource_route_key'] = $definition['scope']['resource_route_key'];
                $attributes['owner_key'] = $definition['scope']['owner_key'];
            }

            $permission = $this->permissionRepository->updateOrCreate(
                ['identifier' => $identifier],
                $attributes
            );

            // 4. 역할 할당
            // $onlyKeys가 지정된 경우 해당 키만 역할 업데이트 (부분 적용), 나머지는 건너뜀
            if ($onlyKeys !== null && ! in_array($key, $onlyKeys, true)) {
                continue;
            }
            $this->assignRolesToPermission(
                $permission,
                $key,
                $board,
                $permissions,
                $defaultPermissions,
                $onlyKeys !== null,
                $permission->wasRecentlyCreated
            );
        }
    }

    /**
     * 권한에 역할을 할당합니다.
     *
     * @param  Permission  $permission  대상 권한
     * @param  string  $key  권한 키 (posts.list 등)
     * @param  Board  $board  게시판
     *                        역할을 붙이거나 떼는 것은 그 역할의 회원 전원에게 이 권한을 주거나 빼는 것이라, 액터가
     *                        이 권한을 가진 범위 안에서만 허용한다(권한 부여 상한 — PermissionEscalationGuard). 이
     *                        검사가 없어 게시판 수정 권한만 가진 위임 관리자가 자기 역할을 admin.manage·비밀글 열람에
     *                        붙여 스스로 올라가던 전례가 있다(KVE-2026-2367). 게시판을 새로 만들 때 설정 기본값이 정한
     *                        역할(기본 ACL)은 액터와 무관한 시스템 기준이라 검사하지 않고, 그 위에 요청이 더 얹는
     *                        역할만 기본 ACL 이 붙은 뒤의 액터 권한으로 판정한다.
     * @param  Permission  $permission  대상 권한
     * @param  string  $key  권한 키 (posts.list 등)
     * @param  Board  $board  게시판
     * @param  array  $permissions  사용자 설정 권한
     * @param  array  $defaultPermissions  기본 권한 설정
     * @param  bool  $isBulkApply  일괄 적용 여부 (게시판 manager/step 역할 해제 보호)
     * @param  bool  $isNewPermission  이번 호출에서 새로 만든 권한인지 (게시판 생성)
     * @return void 반환값 없음
     *
     * @throws PermissionEscalationException 액터가 이 권한을 가진 범위를 넘어 역할을 바꾸려 할 때
     */
    private function assignRolesToPermission(
        Permission $permission,
        string $key,
        Board $board,
        array $permissions,
        array $defaultPermissions,
        bool $isBulkApply = false,
        bool $isNewPermission = false
    ): void {
        // 프론트엔드에서는 posts_list 형식으로 전송되므로 변환하여 찾기
        $frontendKey = str_replace('.', '_', $key);
        $permissionData = $permissions[$frontendKey] ?? $permissions[$key] ?? null;

        // 최종 할당할 역할 배열 계산
        if ($permissionData !== null) {
            $targetRoles = $permissionData['roles'] ?? [];
        } else {
            // 사용자 설정이 없으면 config 기본값 사용 (null이면 전체 허용 → 빈 배열)
            $defaultRoles = $defaultPermissions[$key] ?? null;
            $targetRoles = is_array($defaultRoles) ? $defaultRoles : [];
        }

        // diff 계산: 추가/제거분만 처리하여 granted_at 보존
        $currentIdentifiers = $permission->roles()->pluck('identifier')->toArray();
        $toAdd = array_diff($targetRoles, $currentIdentifiers);
        $toRemove = array_diff($currentIdentifiers, $targetRoles);

        // 일괄적용 시에만 manager/step 보호 — 개별 게시판 수정 시에는 그대로 반영
        if ($isBulkApply) {
            $protectedRoles = [
                "sirsoft-board.{$board->slug}.manager",
                "sirsoft-board.{$board->slug}.step",
            ];
            $toRemove = array_values(array_diff($toRemove, $protectedRoles));
        }

        if (empty($toAdd) && empty($toRemove)) {
            return;
        }

        if ($isNewPermission) {
            // 새 권한은 붙어 있던 역할이 없어 떼는 쪽이 없다. 기본 ACL 먼저 붙이고, 요청이 더 얹는
            // 역할만 그 뒤의 액터 권한으로 판정한다 — 기본 ACL 로 이 권한을 갖게 된 액터(admin 역할
            // 등)가 생성과 동시에 역할을 더 얹는 정상 흐름을 막지 않기 위해서다.
            $defaultRoles = is_array($defaultPermissions[$key] ?? null) ? $defaultPermissions[$key] : [];
            $baseline = array_values(array_intersect($toAdd, $defaultRoles));
            $extras = array_values(array_diff($toAdd, $defaultRoles));

            if (! empty($baseline)) {
                $this->attachRoles($permission, $baseline, $board, $key);
            }

            if (! empty($extras)) {
                $this->assertRoleChangeWithinActorCeiling($permission);
                $this->attachRoles($permission, $extras, $board, $key);
            }

            return;
        }

        $this->assertRoleChangeWithinActorCeiling($permission);

        if (! empty($toRemove)) {
            $removeIds = $this->roleRepository->getByIdentifiers($toRemove)->pluck('id')->toArray();
            $permission->roles()->detach($removeIds);
        }

        if (! empty($toAdd)) {
            $this->attachRoles($permission, $toAdd, $board, $key);
        }
    }

    /**
     * 이 권한에 붙은 역할을 바꾸는 것이 액터의 권한 부여 상한 안인지 확인합니다.
     *
     * 역할-권한 매핑을 바꾸는 것은 그 권한을 부여·회수하는 것과 같으므로 코어 역할 편집과 같은
     * 상한(보유 + 범위)을 받는다. 부여되는 행은 범위 없이(전체) 붙으므로 액터도 이 권한을 전체
     * 범위로 가져야 한다. 슈퍼관리자와 인증 액터가 없는 내부 경로(설치·시드·업그레이드)는
     * 가드가 그대로 통과시킨다.
     *
     * @param  Permission  $permission  역할이 바뀌는 권한
     * @return void 반환값 없음
     *
     * @throws PermissionEscalationException 액터가 이 권한을 전체 범위로 갖지 않았을 때
     */
    private function assertRoleChangeWithinActorCeiling(Permission $permission): void
    {
        // 같은 요청 안에서 앞선 키의 매핑이 바뀌었을 수 있다 — 요청 시작 때 읽어 둔 액터 권한이
        // 아니라 지금 저장된 권한으로 판정한다.
        $actor = Auth::user();
        if ($actor instanceof User) {
            $actor->flushPermissionCaches();
            $actor->unsetRelation('roles');
        }

        app(PermissionEscalationGuard::class)->assertGrantWithinActorCeiling([
            ['id' => $permission->id, 'scope_type' => null],
        ]);
    }

    /**
     * 권한에 역할들을 attach합니다.
     *
     * @param  Permission  $permission  대상 권한
     * @param  array  $roleIdentifiers  역할 identifier 배열
     * @param  Board  $board  게시판
     * @param  string  $key  권한 키
     * @return void 반환값 없음
     */
    private function attachRoles(Permission $permission, array $roleIdentifiers, Board $board, string $key): void
    {
        $existingRoles = $this->roleRepository->getByIdentifiers($roleIdentifiers);

        if ($existingRoles->isEmpty()) {
            Log::warning("게시판 '{$board->slug}' 권한 '{$key}': 설정된 역할이 존재하지 않습니다.", [
                'board_slug' => $board->slug,
                'permission_key' => $key,
                'requested_roles' => $roleIdentifiers,
            ]);

            return;
        }

        foreach ($existingRoles as $role) {
            $permission->roles()->attach($role->id, [
                'granted_at' => now(),
                'granted_by' => Auth::id(),
            ]);
        }
    }

    /**
     * 게시판의 모든 권한을 삭제합니다.
     *
     * 그누보드7 규정: detach 후 삭제 순서를 따릅니다.
     * 카테고리 권한 삭제 시 CASCADE로 자식 권한도 함께 삭제됩니다.
     *
     * @param  Board  $board  대상 게시판
     * @return void 반환값 없음
     */
    public function removeBoardPermissions(Board $board): void
    {
        // 카테고리 권한 찾기
        $categoryPermission = $this->permissionRepository->findByIdentifier(self::MODULE_IDENTIFIER.".{$board->slug}");

        if ($categoryPermission) {
            // 그누보드7 규정: detach 후 삭제
            // CASCADE로 자식 권한도 함께 삭제됨
            $categoryPermission->roles()->detach();
            $categoryPermission->delete();
        }
    }

    /**
     * 게시판의 권한을 업데이트합니다.
     *
     * permissions 테이블은 유지하고, role_permissions만 동기화합니다.
     *
     * @param  Board  $board  대상 게시판
     * @param  array  $permissions  사용자가 설정한 권한 (프론트엔드에서 전달)
     * @param  array<string>|null  $onlyKeys  null이면 전체 적용, 지정 시 해당 키만 역할 업데이트
     * @return void
     */
    public function updateBoardPermissions(Board $board, array $permissions, ?array $onlyKeys = null): void
    {
        // ensureBoardPermissions가 updateOrCreate를 사용하므로
        // 권한 업데이트도 동일한 메서드 사용
        $this->ensureBoardPermissions($board, $permissions, $onlyKeys);
    }

    /**
     * 게시판별 관리자/스텝 역할을 기본 권한 설정에 주입합니다.
     *
     * 관리자 역할: 모든 권한에 추가
     * 스텝 역할: admin.manage, manager 제외한 모든 권한에 추가
     *
     * @param  array  $defaultPermissions  기본 권한 설정
     * @param  string  $slug  게시판 슬러그
     * @return array 게시판별 역할이 추가된 권한 설정
     */
    private function injectBoardRoles(array $defaultPermissions, string $slug): array
    {
        $managerIdentifier = "sirsoft-board.{$slug}.manager";
        $stepIdentifier = "sirsoft-board.{$slug}.step";

        // 스텝 역할이 제외되는 권한 키
        $stepExcludedKeys = ['admin.manage', 'manager'];

        foreach ($defaultPermissions as $key => $roles) {
            if (! is_array($roles)) {
                continue;
            }

            // 관리자 역할: 모든 권한에 추가
            $roles[] = $managerIdentifier;

            // 스텝 역할: admin.manage, manager 제외
            if (! in_array($key, $stepExcludedKeys)) {
                $roles[] = $stepIdentifier;
            }

            $defaultPermissions[$key] = $roles;
        }

        return $defaultPermissions;
    }

    /**
     * 권한이 "전체" 허용인지 확인합니다.
     *
     * role_permissions 테이블에 해당 권한 레코드가 없으면 전체 허용으로 판단합니다.
     *
     * @param  Permission  $permission  확인할 권한
     * @return bool 붙은 역할이 없어 전체 허용이면 true
     */
    public function isPublicPermission(Permission $permission): bool
    {
        return $permission->roles()->count() === 0;
    }

    /**
     * 모듈 레벨 권한에 역할을 재할당합니다.
     *
     * role_permissions 테이블에 granted_at, granted_by 컬럼이 존재하므로
     * sync() 대신 detach() + attach() 패턴을 사용합니다.
     *
     * @param  array  $permissionRoleMap  권한 identifier => 역할 identifier 배열 매핑
     * @return void
     */
    public function syncModulePermissionRoles(array $permissionRoleMap): void
    {
        // 한 키라도 상한 밖이면 아무것도 바꾸지 않는다 — 쓰기 전에 전 키를 먼저 판정한다.
        $this->assertModulePermissionRolesChangeAllowed($permissionRoleMap);

        foreach ($permissionRoleMap as $identifier => $roleIdentifiers) {
            $permission = $this->permissionRepository->findByIdentifier($identifier);
            if (! $permission) {
                continue;
            }

            $currentIdentifiers = $permission->roles()->pluck('identifier')->toArray();

            $toAdd = array_diff($roleIdentifiers, $currentIdentifiers);
            $toRemove = array_diff($currentIdentifiers, $roleIdentifiers);

            if (empty($toAdd) && empty($toRemove)) {
                continue;
            }

            if (! empty($toRemove)) {
                $removeIds = $this->roleRepository->getByIdentifiers($toRemove)->pluck('id')->toArray();
                $permission->roles()->detach($removeIds);
            }

            if (! empty($toAdd)) {
                $roles = $this->roleRepository->getByIdentifiers($toAdd);
                foreach ($roles as $role) {
                    $permission->roles()->attach($role->id, [
                        'granted_at' => now(),
                        'granted_by' => Auth::id(),
                    ]);
                }
            }
        }
    }

    /**
     * 모듈 레벨 권한의 역할 재할당이 액터의 권한 부여 상한 안인지 확인합니다.
     *
     * 역할이 실제로 바뀌는 권한만 판정한다 — 설정 화면은 저장할 때마다 현재 역할 목록을 그대로
     * 다시 보내므로, 바뀌지 않은 키까지 검사하면 이 권한을 갖지 않은 설정 관리자의 다른 설정
     * 저장까지 막힌다. 신고 관리 권한에 자기 역할을 붙여 신고 처리 권한을 스스로 얻던 경로를
     * 게시판 권한 저장(KVE-2026-2367)과 같은 상한으로 막는다.
     *
     * @param  array<string, array<int, string>>  $permissionRoleMap  권한 identifier => 역할 identifier 배열
     * @return void 반환값 없음
     *
     * @throws PermissionEscalationException 바뀌는 권한 중 하나라도 액터가 전체 범위로 갖지 않았을 때
     */
    public function assertModulePermissionRolesChangeAllowed(array $permissionRoleMap): void
    {
        foreach ($permissionRoleMap as $identifier => $roleIdentifiers) {
            $permission = $this->permissionRepository->findByIdentifier($identifier);
            if (! $permission) {
                continue;
            }

            $currentIdentifiers = $permission->roles()->pluck('identifier')->toArray();
            $roleIdentifiers = is_array($roleIdentifiers) ? $roleIdentifiers : [];

            if (array_diff($roleIdentifiers, $currentIdentifiers) === [] && array_diff($currentIdentifiers, $roleIdentifiers) === []) {
                continue;
            }

            $this->assertRoleChangeWithinActorCeiling($permission);
        }
    }

    /**
     * 환경설정의 게시판 기본 권한 변경이 액터의 권한 부여 상한 안인지 확인합니다.
     *
     * 기본 권한은 앞으로 만들 게시판에 그대로 붙는 역할 목록이라, 역할을 넣고 빼는 것은 새 게시판의
     * 그 권한을 주고 빼는 것과 같다. 게시판을 만들 때는 기본 권한을 시스템 기준으로 보고 검사하지
     * 않으므로, 이 자리에서 막지 않으면 설정 수정 권한만 있는 계정이 자기 역할을 「게시판 관리」
     * 기본값에 넣고 게시판을 만들어 그 권한을 얻는다(KVE-2026-2367 의 잔여 경로). 역할이 바뀌는
     * 키마다, 액터가 **기존 모든 게시판에서** 그 권한을 전체 범위로 가져야 한다 — 어디서도 갖지
     * 않은 권한을 앞으로의 게시판에 나눠 줄 수 없다. 게시판이 하나도 없으면 비교할 권한이 없으므로
     * 최고관리자·내부 경로만 바꿀 수 있다. 바뀌지 않은 키는 검사하지 않는다(설정 화면은 저장할
     * 때마다 현재 값을 그대로 다시 보낸다).
     *
     * @param  array<string, array<int, string>>  $newDefaults  저장하려는 기본 권한 (권한 키 => 역할 식별자 목록)
     * @param  array<string, array<int, string>>  $currentDefaults  현재 저장된 기본 권한
     * @return void 반환값 없음
     *
     * @throws PermissionEscalationException 바뀌는 키를 액터가 모든 게시판에서 전체 범위로 갖지 않았을 때
     */
    public function assertDefaultBoardPermissionsChangeAllowed(array $newDefaults, array $currentDefaults): void
    {
        $actor = Auth::user();
        if (! $actor instanceof User || $actor->isSuperAdmin()) {
            return;
        }

        $changedKeys = [];
        foreach (array_unique(array_merge(array_keys($newDefaults), array_keys($currentDefaults))) as $key) {
            $next = is_array($newDefaults[$key] ?? null) ? $newDefaults[$key] : [];
            $prev = is_array($currentDefaults[$key] ?? null) ? $currentDefaults[$key] : [];
            if (array_diff($next, $prev) !== [] || array_diff($prev, $next) !== []) {
                $changedKeys[] = (string) $key;
            }
        }

        if ($changedKeys === []) {
            return;
        }

        $slugs = $this->boardRepository->all()->pluck('slug')->all();
        if ($slugs === []) {
            throw new PermissionEscalationException;
        }

        $identifiers = [];
        foreach ($slugs as $slug) {
            foreach ($changedKeys as $key) {
                $identifiers[] = self::MODULE_IDENTIFIER.".{$slug}.{$key}";
            }
        }

        $permissions = $this->permissionRepository
            ->getByExtension(ExtensionOwnerType::Module, self::MODULE_IDENTIFIER)
            ->whereIn('identifier', $identifiers)
            ->map(static fn (Permission $permission) => ['id' => $permission->id, 'scope_type' => null])
            ->values()
            ->all();

        $actor->flushPermissionCaches();
        $actor->unsetRelation('roles');

        app(PermissionEscalationGuard::class)->assertGrantWithinActorCeiling($permissions);
    }

    /**
     * 모듈 레벨 권한에 현재 할당된 역할 identifier 배열을 반환합니다.
     *
     * 'sirsoft-board.reports.view' → 'view_roles' 키로 변환합니다.
     *
     * @param  array  $identifiers  조회할 권한 identifier 목록
     * @return array { view_roles: [...], manage_roles: [...] } 형태
     */
    public function getModulePermissionRoles(array $identifiers): array
    {
        $result = [];
        foreach ($identifiers as $identifier) {
            $permission = $this->permissionRepository->findByIdentifier($identifier);
            // 'sirsoft-board.reports.view' → 'view_roles'
            $key = str_replace('sirsoft-board.reports.', '', $identifier).'_roles';
            $result[$key] = $permission
                ? $permission->roles()->pluck('identifier')->toArray()
                : [];
        }

        return $result;
    }

    /**
     * 사용자가 특정 게시판의 특정 권한을 가지고 있는지 확인합니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  string  $action  권한 액션 (예: 'posts.create')
     * @return bool 권한 보유 여부
     */
    public function hasPermission(string $slug, string $action): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $identifier = self::MODULE_IDENTIFIER.".{$slug}.{$action}";

        return $this->checkPermissionByIdentifier($identifier);
    }
}
