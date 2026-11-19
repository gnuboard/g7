<?php

namespace App\Http\Resources;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Http\Resources\Traits\HasAbilityCheck;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class UserCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_read' => 'core.users.read',
            'can_create' => 'core.users.create',
            'can_update' => 'core.users.update',
            'can_delete' => 'core.users.delete',
            // 역할 부여는 "사용자 관리"(core.users.update)의 일부다 — "역할 정의 수정"
            // (core.permissions.update)이 아니다. 부여 가능한 개별 역할의 범위는 서버
            // 상한(PermissionEscalationGuard)이 역할별로 강제한다.
            'can_assign_roles' => 'core.users.update',
        ];
    }

    /**
     * 사용자 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 사용자 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->listRows(),
            'member_fields' => $this->memberFieldMeta(),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 목록 행을 만들고 확장이 열 데이터를 한 번에 붙이게 합니다.
     *
     * 행마다 `core.user.filter_resource_data` 를 걸면 구독자마다 회원 수만큼 쿼리가 나간다(N+1). 목록은 페이지 전체 행과
     * 그 회원 모델 묶음을 필터 `core.user.filter_list_rows` 에 **한 번** 넘긴다 — 확장은 회원 id 로 한 번 조회해 각 행에
     * 자기 키를 더한다(기존 키 덮어쓰기 금지). 돌려준 값이 배열이 아니거나 행 수가 다르면 원래 행을 쓰고 경고를 남긴다.
     *
     * @return array<int, array<string, mixed>> 목록 행
     *
     * @since 7.2.0
     */
    private function listRows(): array
    {
        // 「보이는 이름」 열 — 추가 항목 종류가 쓰이면 페이지의 회원 값을 쿼리 한 번으로 읽어 둔다(행마다 읽지 않게).
        app(DisplayNameResolver::class)->preload(
            $this->collection->map(fn ($item) => $item instanceof JsonResource ? $item->resource : $item)->all(),
            DisplayNameAreaRegistry::AREA_SITE,
        );

        $rows = $this->mapWithRowNumber(function ($user) {
            return (new UserResource($user))->toListArray(request());
        })->values()->all();

        /** @var Collection<int, User> $users */
        $users = $this->collection
            ->map(fn ($item) => $item instanceof JsonResource ? $item->resource : $item)
            ->values();

        $rows = $this->withCustomFieldColumns($rows, $users);
        $rows = $this->withPendingRequiredFields($rows, $users);

        $filtered = HookManager::applyFilters('core.user.filter_list_rows', $rows, $users);

        if (! is_array($filtered) || count($filtered) !== count($rows)) {
            Log::warning('core.user.filter_list_rows 필터가 행 배열을 돌려주지 않아 원래 행을 사용합니다', [
                'expected_rows' => count($rows),
                'returned_type' => get_debug_type($filtered),
            ]);

            return $rows;
        }

        return array_values($filtered);
    }

    /**
     * 운영자가 「회원 목록 열」로 켠 추가 항목의 표시 값을 행마다 싣습니다 (7.2.0).
     *
     * 값은 페이지의 회원 전체를 쿼리 한 번으로 읽는다. 행의 `custom_fields` 는 `{항목 키: 보기용 문자열}` 이고,
     * 목록 열로 켠 항목이 없으면 키를 싣지 않는다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행
     * @param  Collection<int, User>  $users  행과 같은 순서의 회원 모델
     * @return array<int, array<string, mixed>> 추가 항목 값이 실린 행
     */
    private function withCustomFieldColumns(array $rows, Collection $users): array
    {
        $definitions = app(FieldRegistry::class)->active(FieldSet::CORE_USER)
            ->filter(fn (FieldDefinition $definition) => $definition->isCustom() && $definition->admin_list_column)
            ->values();

        if ($definitions->isEmpty() || $users->isEmpty()) {
            return $rows;
        }

        $service = app(FieldValueService::class);
        $values = $service->preload($users, FieldSet::CORE_USER, $definitions);

        foreach ($users as $index => $user) {
            if (! isset($rows[$index])) {
                continue;
            }
            $columns = [];
            foreach ($definitions as $definition) {
                $columns[$definition->key] = $service->display($definition, $values[$user->getKey()][$definition->key] ?? null);
            }
            $rows[$index]['custom_fields'] = $columns;
        }

        return $rows;
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 사용자 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $isPaginator = $this->resource instanceof LengthAwarePaginator;

        return [
            'data' => $this->listRows(),
            'member_fields' => $this->memberFieldMeta(),
            'statistics' => $statistics,
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'pagination' => $isPaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
        ];
    }

    /**
     * 관리자용 상세 정보가 포함된 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 관리자 정보가 포함된 사용자 컬렉션
     */
    public function withAdminInfo(): array
    {
        return [
            'data' => $this->mapWithRowNumber(function ($user) {
                return (new UserResource($user))->withAdminInfo();
            }),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 간단한 목록 형태의 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 간략한 사용자 정보 배열
     */
    public function toSimpleArray(): array
    {
        return $this->collection->map(function ($user) {
            return [
                'uuid' => $user->uuid,
                ...$this->memberNameFields($user),
                'email' => $this->canExposeMemberEmail(null, $user->id) ? $user->email : null,
            ];
        })->toArray();
    }

    /**
     * 행마다 비어 있는 필수 항목을 싣습니다 (7.2.0).
     *
     * 행의 `pending_required_fields` 는 `[{key, label}]` 이고 미입력이 없으면 빈 배열이다. 페이지의 회원 전체를 한 번에 판정한다.
     * 목록에서는 회원마다 잠긴 칸을 따로 알아내지 않는다(행마다 훅을 부르지 않는다) — 잠긴 채 비어 있는 칸도 센다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행
     * @param  Collection<int, User>  $users  행과 같은 순서의 회원 모델
     * @return array<int, array<string, mixed>> 미입력 항목이 실린 행
     */
    private function withPendingRequiredFields(array $rows, Collection $users): array
    {
        $checker = app(RequiredFieldsChecker::class);
        $pending = $checker->pendingFor($users, excludeLocked: false);

        foreach ($users as $index => $user) {
            if (isset($rows[$index])) {
                $rows[$index]['pending_required_fields'] = $checker->describe($pending[$user->getKey()] ?? []);
            }
        }

        return $rows;
    }

    /**
     * 목록 화면이 운영자 추가 항목으로 그릴 열과 검색 대상을 반환합니다 (7.2.0).
     *
     * | 키 | 뜻 |
     * | --- | --- |
     * | columns | 「회원 목록 열」로 켠 항목. `field` 는 행에서 값을 읽는 경로(`custom_fields.{키}`), `header` 는 항목 이름 |
     * | search_fields | 「회원 검색」으로 켠 항목. `value` 는 검색 대상 값(`custom:{키}`), `label` 은 항목 이름 |
     *
     * @return array{columns: array<int, array{key: string, field: string, header: string}>, search_fields: array<int, array{value: string, label: string}>} 열과 검색 대상
     */
    private function memberFieldMeta(): array
    {
        $registry = app(FieldRegistry::class);
        $searchable = app(UserService::class)->searchableCustomFields();
        $columns = [];
        $searchFields = [];

        foreach ($registry->active(FieldSet::CORE_USER) as $definition) {
            if (! $definition->isCustom()) {
                continue;
            }
            $label = $definition->localized('name') ?? $definition->key;
            if ($definition->admin_list_column) {
                $columns[] = ['key' => $definition->key, 'field' => 'custom_fields.'.$definition->key, 'header' => $label];
            }
            if (isset($searchable[$definition->key])) {
                $searchFields[] = ['value' => UserService::CUSTOM_SEARCH_PREFIX.$definition->key, 'label' => $label];
            }
        }

        return ['columns' => $columns, 'search_fields' => $searchFields];
    }
}
