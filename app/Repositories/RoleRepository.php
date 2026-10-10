<?php

namespace App\Repositories;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository implements RoleRepositoryInterface
{
    /**
     * 모든 역할을 조회합니다.
     *
     * @return Collection 역할 컬렉션
     */
    public function getAll(): Collection
    {
        // audit:allow query-unbounded-get reason: 역할은 운영자가 정의한 수만큼만 존재한다 (회원 수와 무관)
        return Role::with(['permissions'])
            ->orderBy('id')
            ->get();
    }

    /**
     * 활성화된 역할들만 조회합니다.
     *
     * @return Collection 활성화된 역할 컬렉션
     */
    public function getActiveRoles(): Collection
    {
        // audit:allow query-unbounded-get reason: 역할은 운영자가 정의한 수만큼만 존재한다 (회원 수와 무관)
        return Role::where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * ID로 역할을 찾습니다.
     *
     * @param  int  $id  역할 ID
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findById(int $id): ?Role
    {
        return Role::with(['permissions'])->find($id);
    }

    /**
     * 식별자로 역할을 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Role
    {
        return Role::where('identifier', $identifier)->first();
    }

    /**
     * 식별자 목록에 해당하는 역할들을 조회합니다 (없는 식별자는 건너뜁니다).
     *
     * @param  array<int, string>  $identifiers  역할 식별자 목록
     * @return Collection<int, Role> 역할 컬렉션
     */
    public function getByIdentifiers(array $identifiers): Collection
    {
        if ($identifiers === []) {
            return new Collection;
        }

        return Role::whereIn('identifier', array_values($identifiers))->get();
    }

    /**
     * 역할 ID 목록을 식별자로 바꿉니다 (없는 ID 는 건너뜁니다).
     *
     * @param  array<int, int>  $ids  역할 ID 목록
     * @return array<int, string> 역할 ID => 식별자
     */
    public function getIdentifiersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Role::whereIn('id', array_values($ids))->pluck('identifier', 'id')->all();
    }

    /**
     * 식별자로 역할에 소속된 사용자들을 조회합니다.
     *
     * 역할이 없거나 소속 사용자가 없으면 빈 컬렉션을 반환합니다.
     *
     * @param  string  $identifier  역할 식별자
     * @return Collection 역할 소속 사용자 컬렉션
     */
    public function getUsersByIdentifier(string $identifier): Collection
    {
        $role = Role::where('identifier', $identifier)->first();

        return $role ? $role->users()->get() : new Collection;
    }

    /**
     * {@inheritDoc}
     */
    public function getUsersByIdentifiers(array $identifiers): Collection
    {
        $identifiers = array_values(array_filter(array_map(
            static fn ($identifier) => is_string($identifier) ? trim($identifier) : '',
            $identifiers,
        ), static fn (string $identifier): bool => $identifier !== ''));

        if ($identifiers === []) {
            return new Collection;
        }

        // 권한까지 함께 싣는다 — 알림 수신자 루프는 사람마다 "이 화면을 열 수 있는가" 를 한 번씩
        // 묻고(링크 승격 판정), 그 판정이 각자 역할·권한을 다시 조회하면 쿼리가 **수신자 수에
        // 비례해** 늘어난다. 느려질 뿐 오류가 없어 드러나지도 않는다.
        //
        // 사용자 수는 줄일 수 없다(전원이 수신자다). 대신 권한 행은 권한 판정
        // (`User::permissionGrants()`)이 읽는 컬럼만 싣는다 — 권한 행은 역할마다 수십~수백 건이고
        // 다국어 JSON(name·description)을 싣고 있어, 전 컬럼을 읽으면 수신자 한 번 해석에 그 JSON
        // 이 통째로 올라온다. 피벗 컬럼(scope_type 등)은 관계 정의의 withPivot 이 그대로 붙인다.
        // 역할 행은 사람당 몇 건이고 수신자를 받은 확장이 역할 이름을 읽을 수 있어 좁히지 않는다.
        $roles = Role::whereIn('identifier', $identifiers)
            ->with([
                'users.roles',
                'users.roles.permissions' => fn ($query) => $query->select([
                    'permissions.id',
                    'permissions.identifier',
                    'permissions.type',
                ]),
            ])
            ->get();

        // `flatMap()` 을 쓰지 않는다 — Eloquent 컬렉션의 `collapse()` 가 **베이스 컬렉션**을
        // 돌려주어 이 메서드의 반환 타입과 어긋나고, 그 TypeError 를 수신자 해석의 try/catch 가
        // 삼켜 **수신자 0명**이 된다(화면에도 응답에도 흔적이 없다).
        $users = new Collection;

        foreach ($roles as $role) {
            foreach ($role->users as $user) {
                $users->push($user);
            }
        }

        // 두 역할에 함께 속한 사용자를 한 번만 담는다 — 그대로 두면 같은 사람에게 두 통이 간다.
        return $users->unique('id')->values();
    }

    /**
     * 새로운 역할을 생성합니다.
     *
     * @param  array  $data  역할 생성 데이터
     * @return Role 생성된 역할 모델
     */
    public function create(array $data): Role
    {
        return Role::create($data);
    }

    /**
     * 역할을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Role 생성 또는 업데이트된 역할 모델
     */
    public function updateOrCreate(array $attributes, array $values): Role
    {
        return Role::updateOrCreate($attributes, $values);
    }

    /**
     * 기존 역할을 업데이트합니다.
     *
     * @param  Role  $role  업데이트할 역할 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Role $role, array $data): bool
    {
        return $role->update($data);
    }

    /**
     * 역할을 삭제합니다.
     *
     * @param  Role  $role  삭제할 역할 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Role $role): bool
    {
        return $role->delete();
    }

    /**
     * 확장이 소유한 역할을 식별자로 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findExtensionRoleByIdentifier(string $identifier, ExtensionOwnerType $extensionType, string $extensionIdentifier): ?Role
    {
        return Role::where('identifier', $identifier)
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->first();
    }

    /**
     * 확장이 소유한 모든 역할을 조회합니다.
     *
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Collection 해당 확장 소유 역할 컬렉션
     */
    public function getByExtension(ExtensionOwnerType $extensionType, string $extensionIdentifier): Collection
    {
        // audit:allow query-unbounded-get reason: 역할은 운영자가 정의한 수만큼만 존재한다 (회원 수와 무관)
        return Role::where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->get();
    }

    /**
     * 역할에 권한을 할당합니다.
     *
     * 기존 권한을 유지하면서 새 권한만 추가합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @param  array  $pivotData  피벗 테이블에 저장할 추가 데이터
     * @return void 반환값 없음
     */
    public function attachPermission(Role $role, int $permissionId, array $pivotData = []): void
    {
        $role->permissions()->syncWithoutDetaching([
            $permissionId => $pivotData,
        ]);
    }

    /**
     * 역할에서 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @return int 해제된 권한 수
     */
    public function detachPermission(Role $role, int $permissionId): int
    {
        return $role->permissions()->detach($permissionId);
    }

    /**
     * 역할의 모든 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 해제된 권한 수
     */
    public function detachAllPermissions(Role $role): int
    {
        return $role->permissions()->detach();
    }

    /**
     * 역할 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 역할 목록
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 권한 관계는 목록에서 로드하지 않는다. 목록 화면이 쓰는 것은 이름·설명·사용자 수·활성
        // 여부뿐인데, 로드하면 Resource 가 같은 권한 집합을 `permissions`(계층 트리)·
        // `permission_ids`·`permission_values` 세 형태로 중복 직렬화하고, 계층 트리를 만드느라
        // 행마다 상위/카테고리 권한을 다시 조회한다(행 수만큼 추가 쿼리).
        //
        // Resource 의 `relationLoaded('permissions')` 가드는 그대로 두면 여기서 로드하지 않는 것만으로
        // 세 필드가 함께 빠진다 — 권한 편집은 단건 조회(`GET /admin/roles/{id}`)가 공급한다.
        //
        // 권한 **개수**는 집계로 남긴다. 목록에서 뺀 값의 대체 경로 — 권한 트리를 전송하지 않고도
        // "이 역할에 권한이 몇 개 걸려 있는가" 를 화면이 보여줄 수 있다. 값 검사가 아니라 별칭
        // 존재 여부로 판정하므로(RoleResource) 0건인 역할도 0 으로 정확히 표시된다.
        $query = Role::query()
            ->withCount(['users', 'permissions']);

        // 검색 필터
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('identifier', 'like', "%{$search}%")
                    ->orWhere('name->ko', 'like', "%{$search}%")
                    ->orWhere('name->en', 'like', "%{$search}%");
            });
        }

        // 활성화 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 정렬 (코어/확장 소유 역할 먼저, 그 다음 사용자 생성 역할)
        // 정렬 마지막의 기본키는 전순서 보장용이다. 시더가 일괄 생성한 역할은 created_at 이
        // 한 타임스탬프에 몰려 있어(실측: 한 값에 최대 10행) 키가 없으면 동률 구간의 순서가
        // 페이지마다 달라지고, 인접 페이지가 같은 역할을 중복 노출하면서 다른 역할을 누락한다.
        $query->orderByRaw('CASE WHEN extension_type IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        // audit:allow repository-paginate-column-pruning reason: 역할 정의 테이블 — 행 수가 운영상 고정(수십 건)이고 넓은 컬럼이 없다
        return $query->paginate($perPage);
    }

    /**
     * 역할에 할당된 권한 개수를 반환합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 권한 개수
     */
    public function getPermissionCount(Role $role): int
    {
        return $role->permissions()->count();
    }
}
