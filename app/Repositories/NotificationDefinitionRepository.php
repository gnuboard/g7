<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Models\NotificationDefinition;
use App\Repositories\Concerns\ResolvesSortSpec;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationDefinitionRepository implements NotificationDefinitionRepositoryInterface
{
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (NotificationDefinitionIndexRequest 와 동일 집합) */
    private const SORTABLE_COLUMNS = [
        'id',
        'type',
        'extension_type',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * ID로 알림 정의 조회.
     *
     * @param  int  $id
     * @return NotificationDefinition|null
     */
    public function findById(int $id): ?NotificationDefinition
    {
        return NotificationDefinition::find($id);
    }

    /**
     * 타입으로 알림 정의 조회.
     *
     * @param  string  $type
     * @return NotificationDefinition|null
     */
    public function findByType(string $type): ?NotificationDefinition
    {
        return NotificationDefinition::byType($type)->first();
    }

    /**
     * 활성 상태인 특정 타입 알림 정의 조회.
     *
     * @param  string  $type
     * @return NotificationDefinition|null
     */
    public function getActiveByType(string $type): ?NotificationDefinition
    {
        return NotificationDefinition::active()->byType($type)->first();
    }

    /**
     * 모든 활성 알림 정의 조회.
     *
     * @return Collection
     */
    public function getAllActive(): Collection
    {
        return NotificationDefinition::active()->get();
    }

    /**
     * 활성 알림 정의의 로케일별 라벨 맵을 반환합니다.
     *
     * 알림 목록 응답에서 N+1 회피 목적으로 한 번 호출하여 [type => label] 맵을 생성합니다.
     * 라벨 fallback 우선순위: 사용자 로케일 → ko → en → type 식별자
     *
     * @param  string|null  $locale  사용자 로케일 (null이면 app()->getLocale())
     * @return array<string, string>
     */
    public function getLabelMap(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return NotificationDefinition::active()
            ->get(['type', 'name'])
            ->mapWithKeys(fn (NotificationDefinition $def) => [
                $def->type => $def->getLocalizedName($locale),
            ])
            ->all();
    }

    /**
     * 전체 알림 정의 목록 조회.
     *
     * @return Collection
     */
    public function getAll(): Collection
    {
        return NotificationDefinition::all();
    }

    /**
     * 특정 확장의 알림 정의 목록 조회.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection
    {
        return NotificationDefinition::byExtension($extensionType, $extensionIdentifier)->get();
    }

    /**
     * 알림 정의 수정.
     *
     * @param  NotificationDefinition  $definition
     * @param  array  $data
     * @return NotificationDefinition
     */
    public function update(NotificationDefinition $definition, array $data): NotificationDefinition
    {
        $definition->update($data);

        return $definition->fresh();
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // 목록은 템플릿 본문을 싣지 않는다.
        //
        // 좁히지 않으면 한 페이지를 여는 것만으로 그 페이지 전 정의의 **모든 채널** 제목·본문이
        // 응답에 실린다(정의 하나당 채널 × 로케일). 화면은 한 번에 한 채널만 그리므로 그 채널을
        // `template_channel` 로 지정해 요청한다 — 지정하지 않은 호출자는 템플릿을 받지 않고,
        // 필요하면 단건 조회(`GET .../notification-definitions/{id}`)가 전 채널을 제공한다.
        $templateChannel = $filters['template_channel'] ?? null;

        $query = NotificationDefinition::query()
            // "되돌리기" 버튼 노출 조건은 채널을 가리지 않고 "커스터마이즈된 템플릿이 하나라도
            // 있는가" 다. 템플릿을 한 채널로 좁히면 배열만으로는 알 수 없으므로 집계로 낸다.
            ->withCount([
                'templates as customized_templates_count' => function ($q) {
                    $q->where('is_default', false);
                },
            ]);

        if (! empty($templateChannel)) {
            $query->with([
                'templates' => fn ($q) => $q->where('channel', $templateChannel),
            ]);
        }

        if (! empty($filters['extension_type'])) {
            $query->where('extension_type', $filters['extension_type']);
        }

        if (! empty($filters['extension_identifier'])) {
            $query->where('extension_identifier', $filters['extension_identifier']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['channel'])) {
            $query->whereJsonContains('channels', $filters['channel']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $locales = config('app.supported_locales', ['ko', 'en']);

            $query->where(function ($q) use ($search, $locales) {
                $q->where('type', 'like', "%{$search}%");
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$search}%");
                }
            });
        }

        foreach ($this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'id', 'asc') as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        // audit:allow repository-paginate-column-pruning reason: 알림 정의 테이블 — 정의 수가 고정이고 넓은 컬럼이 없다
        return $query->paginate($perPage);
    }
}
