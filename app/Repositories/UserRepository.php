<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\DisplayName\DisplayNameQuery;
use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Helpers\PermissionHelper;
use App\Models\FieldValue;
use App\Models\MenuPermission;
use App\Models\Permission;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class UserRepository implements UserRepositoryInterface
{
    use FiltersByDateRange;
    use HasMultipleSearchFilters;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /**
     * 검색어 대상 필드 (UserListRequest::SEARCHABLE_FIELDS 와 같은 집합)
     *
     * `contact`·`identifier` 는 실제 컬럼이 아니라 묶음이다 — {@see applySearchField()} 가 푼다.
     */
    private const SEARCHABLE_FIELDS = ['name', 'nickname', 'email', 'contact', 'identifier', 'admin_memo'];

    /**
     * 검색 대상에서 운영자 추가 항목을 가리키는 접두 (UserService::CUSTOM_SEARCH_PREFIX 와 같은 값)
     */
    private const CUSTOM_SEARCH_PREFIX = 'custom:';

    /**
     * 검색 대상 「전체」가 LIKE 로 훑는 컬럼
     *
     * 관리자 메모는 TEXT 라 전체 검색에 넣으면 모든 검색이 긴 본문을 훑는다 — 메모는 대상을 골라서만
     * 찾는다. 회원번호·고유ID 는 LIKE 가 아니라 정확 일치로 함께 본다.
     */
    private const KEYWORD_ALL_COLUMNS = ['name', 'nickname', 'email', 'mobile', 'phone'];

    /**
     * 연산자(eq·starts_with·ends_with)를 그대로 받는 텍스트 컬럼
     */
    private const OPERATOR_TEXT_COLUMNS = ['name', 'nickname', 'email'];

    /**
     * 허용 정렬 컬럼 (UserListRequest 의 sort_by 허용 목록과 동일 집합 — SortWhitelistGateParityTest 가 대조)
     */
    private const SORTABLE_COLUMNS = ['created_at', 'name', 'email', 'last_login_at', 'status', 'language', 'country'];

    /**
     * UUID 형식 (고유ID 정확 일치 판정)
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * 이메일로 사용자를 찾습니다.
     *
     * @param  string  $email  찾을 사용자의 이메일
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * 새로운 사용자를 생성합니다.
     *
     * @param  array  $data  사용자 생성 데이터
     * @return User 생성된 사용자 모델
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * 기존 사용자를 업데이트합니다.
     *
     * @param  User  $user  업데이트할 사용자 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(User $user, array $data): bool
    {
        return $user->update($data);
    }

    /**
     * 회원 이메일을 인증된 것으로 기록합니다 (이미 기록돼 있으면 처음 시각을 유지).
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    public function markEmailVerified(User $user): void
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    /**
     * 사용자를 삭제합니다.
     *
     * @param  User  $user  삭제할 사용자 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(User $user): bool
    {
        return $user->delete();
    }

    /**
     * 회원에게 직접 준 메뉴 권한(`menu_permissions.user_id`)을 지웁니다 — 회원을 지우기 직전에 부른다(DB CASCADE 비의존).
     *
     * @param  User  $user  대상 회원
     * @return int 지운 행 수
     */
    public function deleteMenuPermissions(User $user): int
    {
        return MenuPermission::query()->where('user_id', $user->id)->delete();
    }

    /**
     * 모든 사용자를 조회합니다.
     *
     * @return Collection 사용자 컬렉션
     */
    public function getAll(): Collection
    {
        return User::all();
    }

    /**
     * ID로 사용자를 찾습니다.
     *
     * @param  int  $id  사용자 ID
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    /**
     * 필터링 및 페이지네이션이 적용된 사용자 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건 배열
     * @param  array<int, callable(Builder, array): void>  $extensions  확장이 더한 쿼리 조건 클로저 목록
     * @return LengthAwarePaginator 페이지네이션된 사용자 목록
     */
    public function getPaginatedUsers(array $filters = [], array $extensions = []): LengthAwarePaginator
    {
        $query = $this->scopedUserQuery();

        // 검색 조건 적용
        $this->applyFilters($query, $filters, $extensions);

        // 정렬 적용 (허용 컬럼 화이트리스트로 해석)
        $sort = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at');

        // 페이지네이션 적용
        $perPage = $filters['per_page'] ?? 15;

        // 지연 조인: inner 는 id 만 훑고 roles eager loading 은 해당 페이지에만 적용된다
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $sort,
            perPage: $perPage,
            relations: ['roles'],
            // 회원 수가 커져도 목록 첫 화면 비용이 총 건수 COUNT 에 끌려가지 않도록 상한을 건다.
            resultCap: PaginationLimits::resultCap('admin.users'),
        );
    }

    /**
     * 현재 사용자의 `core.users.read` 스코프를 건 회원 조회 쿼리를 만듭니다.
     *
     * 관리자 회원 목록·검색·최근 가입·통계는 모두 같은 권한 하나로 열리므로 스코프도 한
     * 자리에서 건다. 경로마다 따로 걸면 한 경로의 누락이 그대로 우회로가 된다 — 목록만
     * 스코프를 걸고 최근 가입·통계에는 걸지 않아, 본인만 보는 위임 관리자가 전체 회원의
     * 개인정보를 받던 전례가 있다(KVE-2026-2368). 슈퍼관리자·전체 스코프는 조건이 붙지 않는다.
     *
     * @return Builder 스코프가 적용된 회원 쿼리
     */
    private function scopedUserQuery(): Builder
    {
        $query = User::query();

        PermissionHelper::applyPermissionScope($query, 'core.users.read');

        return $query;
    }

    /**
     * 쿼리에 필터 조건을 적용합니다.
     *
     * 확장 클로저는 코어 조건을 다 건 **뒤에** 적용한다 — 확장이 코어 조건을 풀 수 없고
     * 더 좁히기만 하게 하려는 것이다. 훅은 Service 가 발화하고 여기에는 결과 클로저만 온다
     * (저장소 계층에 HookManager 를 들이지 않는 관례).
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  적용할 필터 조건 배열
     * @param  array<int, callable(Builder, array): void>  $extensions  확장 쿼리 조건 클로저 목록
     * @return void 없음
     */
    private function applyFilters(Builder $query, array $filters, array $extensions = []): void
    {
        // 다중 검색 조건 적용
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $this->applyKeywordSearch($query, $filters['filters'], (array) ($filters['custom_search_definitions'] ?? []));
        }

        // 날짜 필터
        $this->applyDateFilters($query, $filters);

        $this->applyStatusFilter($query, $filters);
        $this->applyRoleFilter($query, $filters);
        $this->applyAdminScopeFilter($query, $filters);
        $this->applyLockStateFilter($query, $filters);
        $this->applyActivityDateFilters($query, $filters);
        $this->applyProfileFilters($query, $filters);
        $this->applyVerificationFilters($query, $filters);
        $this->applyMemberFieldFilters($query, $filters);

        foreach ($extensions as $extension) {
            if (is_callable($extension)) {
                $extension($query, $filters);
            }
        }
    }

    /**
     * 회원 항목 필터를 적용합니다 — 닉네임이 다른 회원과 겹치는 회원, 필수 항목이 비어 있는 회원.
     *
     * 「필수 항목 미입력」의 대상 항목은 Service 가 `required_missing_spec` 으로 넘긴다
     * (`existing` 은 모든 회원에게 요구하는 항목, `deferred` 는 필수 항목을 건너뛰고 만들어진 회원에게만 요구하는 항목).
     * 요구하는 항목이 하나도 없으면 결과는 0건이다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyMemberFieldFilters(Builder $query, array $filters): void
    {
        if (filter_var($filters['nickname_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $table = (new User)->getTable();
            $query->whereIn('nickname_normalized', function ($sub) use ($table) {
                $sub->select('duplicated.nickname_normalized')
                    ->from($table.' as duplicated')
                    ->whereNotNull('duplicated.nickname_normalized')
                    ->where('duplicated.nickname_normalized', '!=', '')
                    ->groupBy('duplicated.nickname_normalized')
                    ->havingRaw('COUNT(*) > 1');
            });
        }

        if (! filter_var($filters['required_missing'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $spec = is_array($filters['required_missing_spec'] ?? null) ? $filters['required_missing_spec'] : [];
        $existing = $this->normalizeMissingSpec($spec['existing'] ?? null);
        $deferred = $this->normalizeMissingSpec($spec['deferred'] ?? null);

        if ($existing === null && $deferred === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($existing, $deferred) {
            if ($existing !== null) {
                $outer->where(fn (Builder $inner) => $this->whereAnyMemberFieldEmpty($inner, $existing));
            }
            if ($deferred !== null) {
                $outer->orWhere(function (Builder $inner) use ($deferred) {
                    $inner->whereNotNull('required_fields_deferred_at')
                        ->where(fn (Builder $any) => $this->whereAnyMemberFieldEmpty($any, $deferred));
                });
            }
        });
    }

    /**
     * 미입력 필터의 대상 묶음을 정리합니다.
     *
     * @param  mixed  $group  `{columns, definition_ids}` 묶음
     * @return array{columns: array<int, string>, definition_ids: array<int, int>}|null 대상이 없으면 null
     */
    private function normalizeMissingSpec(mixed $group): ?array
    {
        if (! is_array($group)) {
            return null;
        }

        $columns = array_values(array_filter((array) ($group['columns'] ?? []), fn ($column) => is_string($column) && preg_match('/^[a-z_][a-z0-9_]*$/', $column) === 1));
        $ids = array_values(array_filter(array_map('intval', (array) ($group['definition_ids'] ?? [])), fn (int $id) => $id > 0));

        return $columns === [] && $ids === [] ? null : ['columns' => $columns, 'definition_ids' => $ids];
    }

    /**
     * 대상 항목 가운데 하나라도 비어 있는 회원 조건을 겁니다 (컬럼 항목은 null 또는 빈 글자, 값 테이블 항목은 행 없음).
     *
     * @param  Builder  $query  조건을 걸 쿼리 (OR 로 묶는다)
     * @param  array{columns: array<int, string>, definition_ids: array<int, int>}  $group  대상 묶음
     * @return void 없음
     */
    private function whereAnyMemberFieldEmpty(Builder $query, array $group): void
    {
        foreach ($group['columns'] as $column) {
            $query->orWhereNull('users.'.$column)->orWhere('users.'.$column, '');
        }

        foreach ($group['definition_ids'] as $definitionId) {
            $query->orWhereNotExists(function ($sub) use ($definitionId) {
                $sub->selectRaw('1')
                    ->from('field_values')
                    ->whereColumn('field_values.owner_id', 'users.id')
                    ->where('field_values.owner_type', FieldValue::OWNER_USER)
                    ->where('field_values.field_definition_id', $definitionId);
            });
        }
    }

    /**
     * 검색어 조건(`filters[*]`)을 적용합니다. 조건끼리는 AND 로 묶는다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $searchFilters  [['field' => ..., 'value' => ..., 'operator' => ...], ...]
     * @param  array<string, int>  $customDefinitions  검색할 수 있는 운영자 추가 항목 (항목 키 => 항목 ID)
     * @return void 없음
     */
    private function applyKeywordSearch(Builder $query, array $searchFilters, array $customDefinitions = []): void
    {
        $query->where(function (Builder $outer) use ($searchFilters, $customDefinitions) {
            foreach ($searchFilters as $filter) {
                $field = $filter['field'] ?? null;
                $value = trim((string) ($filter['value'] ?? ''));

                if (! is_string($field) || $value === '') {
                    continue;
                }

                // 운영자 추가 항목(`custom:{키}`) — 그 항목의 값 행에서 찾는다.
                if (str_starts_with($field, self::CUSTOM_SEARCH_PREFIX)) {
                    $definitionId = $customDefinitions[substr($field, strlen(self::CUSTOM_SEARCH_PREFIX))] ?? null;
                    if ($definitionId !== null) {
                        $outer->where(fn (Builder $q) => $this->whereCustomFieldValue($q, (int) $definitionId, $value));
                    }

                    continue;
                }

                if ($field !== 'all' && ! in_array($field, self::SEARCHABLE_FIELDS, true)) {
                    continue;
                }

                $operator = (string) ($filter['operator'] ?? 'like');
                $outer->where(fn (Builder $q) => $this->applySearchField($q, $field, $value, $operator));
            }
        });
    }

    /**
     * 검색 대상 하나를 실제 컬럼 조건으로 풉니다.
     *
     * LIKE 검색어의 `%`·`_` 는 글자 그대로 찾도록 이스케이프한다 — 그대로 두면 `%` 한 글자가
     * 전체 회원과 일치한다.
     *
     * @param  Builder  $query  조건을 붙일 빌더
     * @param  string  $field  검색 대상(all·name·nickname·email·contact·identifier·admin_memo)
     * @param  string  $value  검색어
     * @param  string  $operator  like·eq·starts_with·ends_with (텍스트 3칸에만 적용)
     * @return void 없음
     */
    private function applySearchField(Builder $query, string $field, string $value, string $operator): void
    {
        $contains = '%'.KeywordSearch::escapeLikeWildcards($value).'%';

        match ($field) {
            'all' => $query->where(function (Builder $w) use ($value, $contains) {
                foreach (self::KEYWORD_ALL_COLUMNS as $column) {
                    $w->orWhere($column, 'like', $contains);
                }
                $this->orWhereIdentifier($w, $value);
            }),
            'contact' => $query->where(fn (Builder $w) => $w->where('mobile', 'like', $contains)->orWhere('phone', 'like', $contains)),
            'identifier' => $this->whereIdentifier($query, $value),
            // TEXT 칸이라 정확 일치·접두 검색이 의미가 없다 — 연산자와 무관하게 포함 검색만 한다.
            'admin_memo' => $query->where('admin_memo', 'like', $contains),
            // 이름·닉네임·이메일은 연산자를 받는다. LIKE 계열은 검색어를 이스케이프한다.
            default => $this->whereTextColumn($query, $field, $value, $operator),
        };
    }

    /**
     * 운영자 추가 항목의 값에 검색어가 들어 있는 회원 조건을 붙입니다.
     *
     * 색인 칸(선택 값, 글의 정규화 앞부분)과 글 칸을 함께 본다. 색인은 소문자로 저장되므로 검색어도 소문자로 맞춘다.
     *
     * @param  Builder  $query  조건을 붙일 빌더
     * @param  int  $definitionId  항목 ID
     * @param  string  $value  검색어(빈 문자열이 아님)
     * @return void 없음
     */
    private function whereCustomFieldValue(Builder $query, int $definitionId, string $value): void
    {
        $contains = '%'.KeywordSearch::escapeLikeWildcards($value).'%';
        $containsLower = '%'.KeywordSearch::escapeLikeWildcards(mb_strtolower($value)).'%';

        $query->whereExists(function ($sub) use ($definitionId, $contains, $containsLower) {
            $sub->selectRaw('1')
                ->from('field_values')
                ->whereColumn('field_values.owner_id', 'users.id')
                ->where('field_values.owner_type', FieldValue::OWNER_USER)
                ->where('field_values.field_definition_id', $definitionId)
                ->where(fn ($w) => $w->where('field_values.value_index', 'like', $containsLower)->orWhere('field_values.value_text', 'like', $contains));
        });
    }

    /**
     * 이름·닉네임·이메일 칸에 연산자별 조건을 붙입니다.
     *
     * 공용 `applySearchFilter()` 는 `! $value` 로 빈 값을 걸러 검색어 「0」 을 버리므로 여기서 직접 푼다.
     *
     * @param  Builder  $query  조건을 붙일 빌더
     * @param  string  $field  name·nickname·email
     * @param  string  $value  검색어(빈 문자열이 아님)
     * @param  string  $operator  like·eq·starts_with·ends_with
     * @return void 없음
     */
    private function whereTextColumn(Builder $query, string $field, string $value, string $operator): void
    {
        if (! in_array($field, self::OPERATOR_TEXT_COLUMNS, true)) {
            return;
        }

        $escaped = KeywordSearch::escapeLikeWildcards($value);

        match ($operator) {
            'eq' => $query->where($field, '=', $value),
            'starts_with' => $query->where($field, 'like', $escaped.'%'),
            'ends_with' => $query->where($field, 'like', '%'.$escaped),
            default => $query->where($field, 'like', '%'.$escaped.'%'),
        };
    }

    /**
     * 회원번호(숫자) 또는 고유ID(UUID) 정확 일치 조건을 붙입니다. 둘 다 아니면 결과가 없다.
     *
     * @param  Builder  $query  조건을 붙일 빌더
     * @param  string  $value  검색어
     * @return void 없음
     */
    private function whereIdentifier(Builder $query, string $value): void
    {
        if (ctype_digit($value)) {
            $query->where('id', (int) $value);
        } elseif (preg_match(self::UUID_PATTERN, $value)) {
            $query->where('uuid', strtolower($value));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * 「전체」 검색에 회원번호·고유ID 정확 일치를 OR 로 더합니다(형식이 맞을 때만).
     *
     * @param  Builder  $query  OR 그룹 빌더
     * @param  string  $value  검색어
     * @return void 없음
     */
    private function orWhereIdentifier(Builder $query, string $value): void
    {
        if (ctype_digit($value)) {
            $query->orWhere('id', (int) $value);
        } elseif (preg_match(self::UUID_PATTERN, $value)) {
            $query->orWhere('uuid', strtolower($value));
        }
    }

    /**
     * 계정 상태 다중선택 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyStatusFilter(Builder $query, array $filters): void
    {
        $statuses = $this->normalizeList($filters['status'] ?? null);

        // 여섯 상태를 전부 고르면 조건이 없는 것과 같다 — IN 목록을 붙이지 않는다.
        if ($statuses === [] || array_diff(UserStatus::values(), $statuses) === []) {
            return;
        }

        $query->whereIn('status', $statuses);
    }

    /**
     * 권한 그룹(역할) 다중선택 필터를 적용합니다. 고른 역할 중 하나라도 가진 회원이 남는다.
     *
     * `whereHas` 는 행마다 상관 서브쿼리를 돌리므로 `id IN (user_roles 서브쿼리)` 로 푼다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyRoleFilter(Builder $query, array $filters): void
    {
        $roleIds = array_map('intval', $this->normalizeList($filters['role_ids'] ?? null));

        if ($roleIds === []) {
            return;
        }

        // audit:allow repository-raw-hardcoded-table reason: user_roles 는 모델이 없는 피벗 테이블이다(User::roles 가 같은 이름을 쓴다).
        $query->whereIn('id', function ($sub) use ($roleIds) {
            $sub->select('user_id')->from('user_roles')->whereIn('role_id', $roleIds);
        });
    }

    /**
     * 관리자 여부 필터를 적용합니다.
     *
     * 관리자 = 슈퍼관리자이거나 admin 타입 권한을 가진 역할에 속한 회원. `User::isAdmin()` 은
     * 행마다 권한 트리를 훑으므로 목록 쿼리에 쓰지 않고, 그 역할 집합을 서브쿼리 하나로 푼다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyAdminScopeFilter(Builder $query, array $filters): void
    {
        $scope = $filters['admin_scope'] ?? null;

        if (! in_array($scope, ['admin', 'member'], true)) {
            return;
        }

        $permissions = (new Permission)->getTable();

        // audit:allow repository-raw-hardcoded-table reason: user_roles·role_permissions 는 모델이 없는 피벗 테이블이다(User::roles·Role::permissions 가 같은 이름을 쓴다).
        $adminUserIds = function ($sub) use ($permissions) {
            $sub->select('user_roles.user_id')
                ->from('user_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
                ->join($permissions, $permissions.'.id', '=', 'role_permissions.permission_id')
                ->where($permissions.'.type', PermissionType::Admin->value);
        };

        if ($scope === 'admin') {
            $query->where(fn (Builder $q) => $q->where('is_super', true)->orWhereIn('id', $adminUserIds));
        } else {
            $query->where('is_super', false)->whereNotIn('id', $adminUserIds);
        }
    }

    /**
     * 계정 잠금 상태 필터를 적용합니다 — {@see isLocked()} 와 같은 판정을 쿼리로 옮긴 것이다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyLockStateFilter(Builder $query, array $filters): void
    {
        $state = $filters['lock_state'] ?? null;
        $now = now();

        if ($state === 'locked') {
            $query->where(fn (Builder $q) => $q->where('locked_permanently', true)->orWhere('locked_until', '>', $now));
        } elseif ($state === 'unlocked') {
            $query->where('locked_permanently', false)
                ->where(fn (Builder $q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now));
        }
    }

    /**
     * 최근 로그인·휴면 전환일·상태 변경일(탈퇴/차단) 기간 필터를 적용합니다.
     *
     * 「로그인 이력 없음」을 켜면 최근 로그인 기간은 무시한다 — 둘은 동시에 참일 수 없다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyActivityDateFilters(Builder $query, array $filters): void
    {
        if (filter_var($filters['never_logged_in'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('last_login_at');
        } else {
            $this->applyViewerDateRangeFilter($query, 'last_login_at', $filters['last_login_start'] ?? null, $filters['last_login_end'] ?? null);
        }

        $this->applyViewerDateRangeFilter($query, 'dormant_at', $filters['dormant_start'] ?? null, $filters['dormant_end'] ?? null);

        $changedColumn = match ($filters['status_changed_type'] ?? null) {
            'withdrawn' => 'withdrawn_at',
            'blocked' => 'blocked_at',
            default => null,
        };

        if ($changedColumn === null) {
            return;
        }

        $start = $filters['status_changed_start'] ?? null;
        $end = $filters['status_changed_end'] ?? null;

        // 날짜 없이 종류만 고르면 「그 일이 있었던 회원」을 뜻한다.
        if (empty($start) && empty($end)) {
            $query->whereNotNull($changedColumn);
        } else {
            $this->applyViewerDateRangeFilter($query, $changedColumn, $start, $end);
        }
    }

    /**
     * 언어·국가 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyProfileFilters(Builder $query, array $filters): void
    {
        if (isset($filters['language']) && $filters['language'] !== '') {
            $query->where('language', $filters['language']);
        }

        if (isset($filters['country']) && $filters['country'] !== '') {
            $query->where('country', strtoupper($filters['country']));
        }
    }

    /**
     * 이메일 인증·본인인증 완료 여부 필터를 적용합니다(인증 시각 칸의 존재로 판정).
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyVerificationFilters(Builder $query, array $filters): void
    {
        foreach (['email_verified' => 'email_verified_at', 'identity_verified' => 'identity_verified_at'] as $key => $column) {
            match ($filters[$key] ?? null) {
                'verified' => $query->whereNotNull($column),
                'unverified' => $query->whereNull($column),
                default => null,
            };
        }
    }

    /**
     * 다중선택 값을 빈 원소 없는 문자열 배열로 정규화합니다.
     *
     * @param  mixed  $value  배열·단일 값·null
     * @return array<int, string> 정규화된 목록
     */
    private function normalizeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $list = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $list),
            static fn (string $v) => $v !== ''
        )));
    }

    /**
     * 날짜 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     * @return void 없음
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        // whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 무력화한다 — 범위 조건으로 준다.
        $this->applyViewerDateRangeFilter(
            $query,
            'created_at',
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null
        );

        // 기본 날짜 필터 (전체가 아닌 경우)
        if (empty($filters['start_date']) && empty($filters['end_date']) &&
            ($filters['date_filter'] ?? 'all') !== 'all') {
            $dateFilter = $filters['date_filter'] ?? 'all';

            match ($dateFilter) {
                'week' => $query->where('created_at', '>=', now()->subWeek()),
                'month' => $query->where('created_at', '>=', now()->subMonth()),
                default => null,
            };
        }
    }

    /**
     * 사용자 관련 통계 정보를 조회합니다.
     *
     * 기본은 현재 사용자의 `core.users.read` 스코프 안에서 센다 — 본인만 볼 수 있는 위임
     * 관리자에게 전체 회원 수가 나가면 목록이 숨긴 것을 숫자로 흘린다(KVE-2026-2368 형제).
     * 사용자와 무관하게 캐시하는 사이트 전체 집계만 `$applyPermissionScope = false` 로 부른다
     * — 스코프를 건 결과가 공용 캐시에 앉으면 다른 방문자에게 그 사람 기준 숫자가 나간다.
     *
     * @param  bool  $applyPermissionScope  현재 사용자의 조회 스코프 적용 여부
     * @return array 사용자 통계 데이터 배열
     */
    public function getStatistics(bool $applyPermissionScope = true): array
    {
        $base = fn (): Builder => $applyPermissionScope ? $this->scopedUserQuery() : User::query();

        // 「오늘 가입」은 보는 사람의 오늘이다 — 목록이 가입일을 보는 사람의 시간대로 그리므로
        // UTC 오늘로 세면 한국 시간 새벽 가입자가 어제로 빠진다.
        $today = $base();
        $this->applyViewerTodayFilter($today, 'created_at');

        return [
            'total_users' => $base()->count(),
            'users_this_week' => $base()->where('created_at', '>=', now()->subWeek())->count(),
            'users_this_month' => $base()->where('created_at', '>=', now()->subMonth())->count(),
            'users_today' => $today->count(),
            'active_users_this_week' => $base()->where('last_login_at', '>=', now()->subWeek())->count(),
        ];
    }

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param  string  $keyword  검색할 키워드
     * @param  bool  $excludeWithdrawn  탈퇴 회원을 뺄지 여부 — 알림 수신자처럼 고르는 자리는 true, 기록을 찾는 목록 필터는 false(기본)
     * @return Collection 검색된 사용자 컬렉션
     */
    public function searchByKeyword(string $keyword, bool $excludeWithdrawn = false): Collection
    {
        // 목록 검색과 같이 `%`·`_` 는 글자 그대로 찾는다 — 그대로 두면 `%` 한 글자가 범위 안 전체 회원을 돌려준다.
        $contains = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';

        // 이름과 닉네임 조건은 관리자 화면 작성자 검색과 같은 한 곳(DisplayNameQuery)이 만든다.
        return $this->scopedUserQuery()
            ->where(function (Builder $q) use ($contains, $keyword) {
                app(DisplayNameQuery::class)->constrainUsersForAdmin($q, $keyword)
                    ->orWhere('email', 'like', $contains);
            })
            // 탈퇴 판정은 User::scopeActive()(「탈퇴 아님」 — 차단·비활성·휴면은 통과)와 같다.
            ->when($excludeWithdrawn, fn (Builder $q) => $q->active())
            ->get();
    }

    /**
     * 최근 등록된 사용자들을 조회합니다.
     *
     * @param  int  $limit  조회할 사용자 수 (기본값: 10)
     * @return Collection 최근 사용자 컬렉션
     */
    public function getRecentUsers(int $limit = 10): Collection
    {
        return $this->scopedUserQuery()->orderBy('created_at', 'desc')->limit($limit)->get();
    }

    /**
     * 언어별 사용자 수를 조회합니다 (현재 사용자의 조회 스코프 안에서).
     *
     * @return array 언어별 사용자 수 배열
     */
    public function getUsersByLanguage(): array
    {
        return $this->scopedUserQuery()
            ->selectRaw('language, count(*) as count')
            ->groupBy('language')
            ->pluck('count', 'language')
            ->toArray();
    }

    /**
     * UUID 목록으로 사용자들을 조회하고 UUID 키 맵으로 반환합니다.
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return Collection<string, User> uuid => User 매핑
     */
    public function findManyByUuidsKeyed(array $uuids): Collection
    {
        return User::whereIn('uuid', $uuids)->get()->keyBy('uuid');
    }

    /**
     * 사용자 번호 목록으로 사용자들을 조회하고 번호 키 맵으로 반환합니다.
     *
     * 화면에 필요한 칸만 읽는다 — 회원 목록을 통째로 들고 오면 응답에 실을 일이 없는
     * 값까지 메모리에 앉는다. 읽는 칸은 표시 이름 계산에 필요한 `User::DISPLAY_COLUMNS` 다
     * (이름, 닉네임, 이메일, 상태, 회원이 고른 종류) — 이름을 응답에 실을 때는 `DisplayNameResolver` 로 계산한다.
     *
     * @param  array<int, int>  $ids  사용자 번호 목록
     * @return Collection<int, User> id => User 매핑
     */
    public function findManyByIdsKeyed(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return User::whereIn('id', $ids)->get(User::DISPLAY_COLUMNS)->keyBy('id');
    }

    /**
     * UUID 목록으로 사용자들을 조회합니다.
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return Collection<int, User> 조회된 사용자 컬렉션
     */
    public function findManyByUuids(array $uuids): Collection
    {
        return User::whereIn('uuid', $uuids)->get();
    }

    /**
     * 슈퍼관리자 1명을 조회합니다.
     *
     * 역할 기반 수신자 해석에서 대상 역할에 사용자가 없을 때 폴백 수신자로 사용합니다.
     *
     * @return User|null 슈퍼관리자 또는 없으면 null
     */
    public function findSuperAdmin(): ?User
    {
        return User::superAdmins()->first();
    }

    /**
     * 탈퇴하지 않은 최고관리자 전원을 가입 순으로 조회합니다.
     *
     * @return Collection<int, User> 최고관리자 목록 (없으면 빈 컬렉션)
     */
    public function getSuperAdmins(): Collection
    {
        return User::superAdmins()->active()->orderBy('id')->get();
    }

    /**
     * 특정 권한 identifier 를 가진 역할에 소속된 모든 사용자를 조회합니다.
     *
     * "권한을 가진 자" 기준이므로 권한이 회수되면 자동으로 결과에서 제외됩니다.
     *
     * @param  string  $permissionIdentifier  권한 identifier
     * @return Collection<int, User> 권한 보유 사용자 컬렉션
     */
    public function findManyByPermissionIdentifier(string $permissionIdentifier): Collection
    {
        return User::query()
            ->whereHas('roles.permissions', function ($query) use ($permissionIdentifier) {
                $query->where('permissions.identifier', $permissionIdentifier);
            })
            ->get();
    }

    /**
     * 특정 권한을 가진 역할에 소속된 사용자의 id 만 오름차순으로 조회합니다 (#139).
     *
     * 회원 행 전체를 싣지 않고 id 열만 커서로 읽는다.
     *
     * @param  string  $permissionIdentifier  권한 identifier
     * @return array<int, int> 사용자 id (오름차순, 중복 없음)
     */
    public function findIdsByPermissionIdentifier(string $permissionIdentifier): array
    {
        $ids = [];

        foreach (User::query()
            ->select('users.id')
            ->whereHas('roles.permissions', function ($query) use ($permissionIdentifier) {
                $query->where('permissions.identifier', $permissionIdentifier);
            })
            ->orderBy('users.id')
            ->toBase()
            ->cursor() as $row) {
            $ids[] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * UUID 목록에 해당하는 사용자의 id 만 오름차순으로 조회합니다 (#139).
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return array<int, int> 사용자 id (오름차순)
     */
    public function findIdsByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return User::query()
            ->whereIn('uuid', array_values($uuids))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 알림 발송에 쓸 사용자들을 id 순으로 다시 싣습니다 (#139).
     *
     * @param  array<int, int>  $ids  사용자 id 목록
     * @return Collection<int, User> id 오름차순 사용자
     */
    public function findNotifiablesByIds(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', array_values($ids))
            ->with([
                'roles',
                'roles.permissions' => fn ($query) => $query->select([
                    'permissions.id',
                    'permissions.identifier',
                    'permissions.type',
                ]),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * 사용자의 연속 로그인 실패 카운터를 1 증가시킵니다.
     *
     * 직전 실패가 `$windowMinutes` 보다 오래됐으면 1 부터 다시 센다. 이 기간이 없으면 며칠에 걸친
     * 드문 오타가 쌓여 계정이 잠긴다 — 보안 환경설정의 "최대 로그인 시도 횟수" 는 짧은 시간 안의
     * 연속 실패를 뜻한다.
     *
     * @param  User  $user  대상 사용자
     * @param  int|null  $windowMinutes  실패를 연속으로 인정하는 기간(분). null·0 이하는 무기한
     * @return int 증가 후 카운트
     */
    public function incrementFailedAttempts(User $user, ?int $windowMinutes = null): int
    {
        // DB 가 한 문장으로 계산한다 — 메모리의 횟수에 1 을 더해 저장하면 같은 회원을 먼저 읽은 동시 오답이 서로를 덮어
        // 실패가 덜 세지고 잠금을 우회할 수 있었다(KVE 동형 O8, 7.1.6). 창(windowMinutes) 판정도 같은 문장 안에서 한다.
        $now = now();
        $query = User::query()->whereKey($user->getKey());

        // 직전 실패가 창보다 오래됐으면 1 부터 다시 센다(종전 규칙). 창 경계 시각은 PDO quote 로 넣는다 —
        // update() 의 원시 식 안 자리표시자는 바인딩되지 않는다. 할당 순서상 last_failed_login_at 은 갱신 전 값으로 판정된다.
        $increment = 'COALESCE(failed_login_attempts, 0) + 1';
        if ($windowMinutes !== null && $windowMinutes > 0) {
            $cutoff = $query->getConnection()->getPdo()->quote(
                // audit:allow datetime-display-user-timezone reason: DB 비교용 기계값 — 저장 시각과 같은 앱 시간대(UTC)로 둔다, 화면 표시 아님
                $now->copy()->subMinutes($windowMinutes)->format('Y-m-d H:i:s')
            );
            $increment = "CASE WHEN last_failed_login_at IS NOT NULL AND last_failed_login_at < {$cutoff} THEN 1 ELSE {$increment} END";
        }

        (clone $query)->update([
            'failed_login_attempts' => DB::raw($increment),
            'last_failed_login_at' => $now,
        ]);

        $next = (int) (clone $query)->value('failed_login_attempts');

        $user->forceFill([
            'failed_login_attempts' => $next,
            'last_failed_login_at' => $now,
        ])->syncOriginal();

        return $next;
    }

    /**
     * 사용자의 계정을 지정된 분만큼 잠급니다.
     *
     * `$minutes <= 0` 은 보안 환경설정의 "0 = 무한대" 규약에 따라 영구 잠금으로 처리합니다.
     * 영구 잠금은 해제 시각이 없으므로 `locked_until` 을 NULL 로 두고
     * `locked_permanently` 플래그로 상태를 표현합니다 (NULL 은 이미 "잠금 없음" 을 의미).
     *
     * @param  User  $user  잠글 사용자
     * @param  int  $minutes  잠금 유지 시간(분). 0 이하는 무기한
     * @return Carbon|null 잠금 해제 시각 (영구 잠금은 null)
     */
    public function lockAccount(User $user, int $minutes): ?Carbon
    {
        $permanent = $minutes <= 0;
        $lockedUntil = $permanent ? null : now()->addMinutes($minutes);

        $user->forceFill([
            'locked_until' => $lockedUntil,
            'locked_permanently' => $permanent,
            'failed_login_attempts' => 0,
        ])->save();

        return $lockedUntil;
    }

    /**
     * 사용자의 모든 로그인 시도 추적 컬럼을 초기화합니다.
     *
     * 멱등 — 모든 컬럼이 이미 초기 상태면 UPDATE 를 발행하지 않습니다.
     *
     * @param  User  $user  대상 사용자
     * @return void 없음
     */
    public function resetLoginAttempts(User $user): void
    {
        $needsReset = ($user->failed_login_attempts ?? 0) > 0
            || $user->locked_until !== null
            || (bool) $user->locked_permanently
            || $user->last_failed_login_at !== null;

        if (! $needsReset) {
            return;
        }

        // locked_permanently 를 빠뜨리면 성공 로그인·관리자 해제 후에도 영구 잠금이 잔존한다.
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'locked_permanently' => false,
            'last_failed_login_at' => null,
        ])->save();
    }

    /**
     * 사용자의 계정이 현재 시점에 잠금 상태인지 판정합니다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 잠금 여부
     */
    public function isLocked(User $user): bool
    {
        // 영구 잠금 판정이 먼저 — 해제 시각이 없으므로 NULL 체크에 먼저 걸리면 안 된다.
        if ((bool) $user->locked_permanently) {
            return true;
        }

        if ($user->locked_until === null) {
            return false;
        }

        return $user->locked_until->isFuture();
    }

    /**
     * UUID 로 사용자를 찾습니다.
     *
     * @param  string  $uuid  사용자 UUID
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findByUuid(string $uuid): ?User
    {
        return User::where('uuid', $uuid)->first();
    }

    /**
     * UUID 목록에 해당하는 사용자의 정수 ID 배열을 반환합니다.
     *
     * @param  array  $uuids  사용자 UUID 배열
     * @return array<int, int> 사용자 ID 배열
     */
    public function getIdsByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        return User::whereIn('uuid', $uuids)->pluck('id')->all();
    }

    /**
     * 사용자 ID 목록에 해당하는 이름(원본 실명)을 ID 로 색인해 반환합니다.
     *
     * 화면에 보일 이름을 만드는 용도가 아니다. 응답에 이름을 실을 때는 `findManyByIdsKeyed()` 로 회원을 읽어
     * `DisplayNameResolver::resolveMany()` 로 표시 이름을 계산한다. 이름을 받지 않는 사이트의 회원은 빈 문자열이다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @return array<int, string> 사용자 ID => 이름 (이름이 없으면 빈 문자열)
     */
    public function getNamesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return User::whereIn('id', $ids)->pluck('name', 'id')
            ->map(static fn ($name): string => (string) ($name ?? ''))
            ->all();
    }

    /**
     * 사용자 ID 목록의 지정 컬럼을 일괄 갱신합니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @param  array  $data  갱신할 컬럼 값
     * @return int 갱신된 행 수
     */
    public function updateManyByIds(array $ids, array $data): int
    {
        if (empty($ids)) {
            return 0;
        }

        return User::whereIn('id', $ids)->update($data);
    }

    /**
     * 사용자 ID 목록의 인증 토큰을 모두 삭제합니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @return int 삭제된 토큰 수
     */
    public function deleteTokensByUserIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $ids)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findAccessTokenFor(User $user, int|string $tokenId): ?PersonalAccessToken
    {
        return PersonalAccessToken::query()
            ->whereKey($tokenId)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function updateAccessTokenExpiry(PersonalAccessToken $token, \DateTimeInterface $expiresAt): void
    {
        // 모델 save() 는 지워진 토큰 객체를 INSERT 로 되살린다(KVE-2026-3187) — 지운 토큰은 늘리지 않고,
        // 행이 있을 때만 바뀌는 조건부 UPDATE 로 늘린 뒤 메모리 값을 맞춘다.
        if (! $token->exists) {
            return;
        }

        if ($this->extendTokenExpiration((int) $token->getKey(), $expiresAt)) {
            $token->expires_at = $expiresAt;
            $token->syncOriginalAttribute('expires_at');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateAccessTokenSessionMeta(PersonalAccessToken $token, ?string $loginMethod, ?\DateTimeInterface $absoluteExpiresAt): void
    {
        $token->forceFill([
            // 로그인 문맥 정규화와 같은 64자(컬럼 길이 — 2026_10_25 마이그레이션)
            'login_method' => $loginMethod === null ? null : mb_substr($loginMethod, 0, 64),
            'absolute_expires_at' => $absoluteExpiresAt,
        ])->save();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAllAccessTokens(User $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAccessToken(PersonalAccessToken $token): void
    {
        $token->delete();
    }

    /**
     * 인증 토큰의 만료 시각을 조건부 UPDATE 로 늦춥니다.
     *
     * 모델 save() 는 지워진 토큰 객체를 INSERT 로 되살린다(KVE-2026-3187) — 행이 있을 때만 바꾸는 UPDATE 로 한다.
     *
     * @param  int  $tokenId  토큰 ID
     * @param  \DateTimeInterface  $expiresAt  새 만료 시각
     * @return bool 행이 있어 갱신했으면 true
     */
    public function extendTokenExpiration(int $tokenId, \DateTimeInterface $expiresAt): bool
    {
        return PersonalAccessToken::query()
            ->whereKey($tokenId)
            ->update(['expires_at' => $expiresAt]) > 0;
    }

    /**
     * 회원들의 web 세션을 지웁니다.
     *
     * @param  array<int, int>  $ids  회원 ID 목록
     * @param  string|null  $exceptSessionId  남겨 둘 세션 ID
     * @return int 지운 세션 수 (database 외 드라이버는 0)
     */
    public function deleteSessionsByUserIds(array $ids, ?string $exceptSessionId = null): int
    {
        if (empty($ids)) {
            return 0;
        }

        if (config('session.driver') !== 'database') {
            Log::debug('[auth] 세션 드라이버가 database 가 아니어서 회원 세션을 지우지 않습니다', [
                'driver' => config('session.driver'),
                'user_count' => count($ids),
            ]);

            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->whereIn('user_id', $ids)
            ->when($exceptSessionId !== null && $exceptSessionId !== '', fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * 비밀번호 만료 처리 후보 쿼리를 반환합니다 — 비밀번호가 있는 활성 회원이면서 관리자 지정 변경 대기가 아닌 회원.
     *
     * 휴면·차단·탈퇴·인증대기는 로그인 관문에서 처리하고, 관리자 지정 대기자에게 만료 안내는 모순이다.
     * 비밀번호가 없는 회원(외부 인증 전용)은 만료될 비밀번호가 없어 안내·토큰 회수 대상이 아니다.
     *
     * @return Builder<User> 후보 쿼리
     */
    public function passwordExpiryCandidateQuery(): Builder
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('password')
            ->where('password_change_required', false);
    }

    /**
     * 회원 ID 목록으로 제한한 쿼리를 반환합니다.
     *
     * @param  array<int, int>  $ids  회원 ID 배열
     * @return Builder<User> 쿼리
     */
    public function queryByIds(array $ids): Builder
    {
        return User::query()->whereKey($ids);
    }

    /**
     * 주어진 컬럼 가운데 하나라도 빈 회원 수를 반환합니다 (탈퇴 회원 제외).
     *
     * @param  array<int, string>  $columns  users 컬럼명 목록
     * @return int 회원 수
     */
    public function countMembersWithEmptyColumns(array $columns): int
    {
        if ($columns === []) {
            return 0;
        }

        return User::query()
            ->where('status', '!=', UserStatus::Withdrawn->value)
            ->where(function (Builder $query) use ($columns) {
                foreach ($columns as $column) {
                    $query->orWhereNull($column)->orWhere($column, '');
                }
            })
            ->count();
    }

    /**
     * 값 테이블 저장 항목에 값이 없는 회원 수를 반환합니다 (탈퇴 회원 제외).
     *
     * @param  int  $definitionId  항목 ID
     * @return int 회원 수
     */
    public function countMembersWithoutFieldValue(int $definitionId): int
    {
        return User::query()
            ->where('status', '!=', UserStatus::Withdrawn->value)
            ->whereNotExists(function ($query) use ($definitionId) {
                $query->selectRaw('1')
                    ->from('field_values')
                    ->whereColumn('field_values.owner_id', 'users.id')
                    ->where('field_values.owner_type', FieldValue::OWNER_USER)
                    ->where('field_values.field_definition_id', $definitionId);
            })
            ->count();
    }

    /**
     * 같은 정규화 닉네임을 쓰는 다른 회원이 있는지 반환합니다.
     *
     * @param  string  $normalized  정규화 닉네임
     * @param  int|null  $exceptUserId  비교에서 뺄 회원 ID
     * @return bool 있으면 true
     */
    public function nicknameNormalizedTaken(string $normalized, ?int $exceptUserId = null): bool
    {
        return User::query()
            ->where('nickname_normalized', $normalized)
            ->when($exceptUserId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptUserId))
            ->exists();
    }

    /**
     * 닉네임이 있는 회원 수를 반환합니다.
     *
     * @return int 회원 수
     */
    public function countMembersWithNickname(): int
    {
        return User::query()->whereNotNull('nickname')->where('nickname', '!=', '')->count();
    }

    /**
     * 닉네임이 있는 회원을 ID 순으로 한 묶음 읽습니다.
     *
     * @param  int  $afterId  이 ID 다음부터
     * @param  int  $limit  묶음 크기
     * @return Collection<int, User> 회원 묶음
     */
    public function nicknameRowsAfter(int $afterId, int $limit): Collection
    {
        return User::query()
            ->select(['id', 'nickname', 'nickname_normalized', 'nickname_unique_key'])
            ->whereNotNull('nickname')
            ->where('nickname', '!=', '')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 주어진 정규화 닉네임 가운데 두 회원 이상이 쓰는 값을 반환합니다.
     *
     * @param  array<int, string>  $normalizedValues  정규화 닉네임 목록
     * @return array<int, string> 겹치는 값
     */
    public function duplicatedNicknameNormalized(array $normalizedValues): array
    {
        $normalizedValues = array_values(array_unique(array_filter($normalizedValues, fn ($value) => is_string($value) && $value !== '')));
        if ($normalizedValues === []) {
            return [];
        }

        return User::query()
            ->whereIn('nickname_normalized', $normalizedValues)
            ->groupBy('nickname_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('nickname_normalized')
            ->all();
    }

    /**
     * 지금 겹치는 닉네임의 종 수와 그 닉네임을 쓰는 회원 수를 반환합니다.
     *
     * @return array{nicknames: int, members: int} 겹치는 닉네임 종 수, 회원 수
     */
    public function nicknameDuplicateSummary(): array
    {
        $groups = User::query()
            ->selectRaw('COUNT(*) as member_count')
            ->whereNotNull('nickname_normalized')
            ->where('nickname_normalized', '!=', '')
            ->groupBy('nickname_normalized')
            ->havingRaw('COUNT(*) > 1');

        $summary = $groups->getConnection()->query()
            ->fromSub($groups->toBase(), 'duplicated')
            ->selectRaw('COUNT(*) as nicknames, COALESCE(SUM(member_count), 0) as members')
            ->first();

        return ['nicknames' => (int) ($summary->nicknames ?? 0), 'members' => (int) ($summary->members ?? 0)];
    }

    /**
     * 회원의 닉네임 비교 컬럼을 고칩니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array{nickname_normalized?: string|null, nickname_unique_key?: string|null}  $columns  고칠 컬럼
     * @return void
     */
    public function updateNicknameKeys(int $userId, array $columns): void
    {
        $columns = array_intersect_key($columns, ['nickname_normalized' => true, 'nickname_unique_key' => true]);
        if ($columns === []) {
            return;
        }

        // 쿼리 빌더로 고친다 — 수정 시각과 모델 이벤트(활동 로그 등)를 건드리지 않는 내부 보정이다.
        User::query()->toBase()->where('id', $userId)->update($columns);
    }

    /**
     * 모든 회원의 닉네임 중복 키를 비웁니다.
     *
     * @return int 비운 행 수
     */
    public function clearNicknameUniqueKeys(): int
    {
        return User::query()->toBase()->whereNotNull('nickname_unique_key')->update(['nickname_unique_key' => null]);
    }
}
