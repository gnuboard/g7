<?php

namespace Plugins\Sirsoft\HeroSection\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Enums\DisplayPeriod;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;

/**
 * 배너 Repository
 *
 * 노출 상태 SQL 조건은 `applyStatusFilter()`·`applyStatusSort()` 한 곳에만 있다 — `Banner::statusAt()` 과 같은
 * 순서(비활성 > 예약 > 종료 > 노출중)와 경계(시작 정각부터, 종료 정각까지)를 쓴다.
 * 위치 + 대상(`Placement`) 조건은 `applyPlacement()` 한 곳에만 있다 — 정적 위치는 대상이 null 인 행만 본다.
 */
class BannerRepository implements BannerRepositoryInterface
{
    /**
     * 목록이 읽는 컬럼 — 화면이 그리는 것만 싣는다(설명·버튼 문구·색은 상세에서).
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = [
        'id', 'position', 'target_key', 'media_type', 'is_active', 'sort_order', 'display_period', 'starts_at', 'ends_at', 'title', 'button_text', 'link_url',
        'created_at', 'updated_at',
    ];

    /**
     * 목록 행의 제작 상태 — 모바일 이미지 유무만 알면 되므로 이미지를 읽지 않고 존재 여부(`has_mobile_image`)만 붙인다.
     */
    private const MOBILE_IMAGE_EXISTS = 'mobileImage as has_mobile_image';

    /**
     * 목록·순서 창 썸네일로 읽는 PC 이미지 컬럼 — 응답이 업로드 응답과 같은 첨부 모양(기기·원본 파일명·형식·크기)이라 그 값까지 읽는다.
     */
    private const THUMBNAIL_RELATION = 'pcImage:id,banner_id,device,hash,original_name,mime,size,width,height';

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return LengthAwarePaginator 배너 목록
     */
    public function paginateForAdmin(array $filters, CarbonInterface $now): LengthAwarePaginator
    {
        $query = Banner::query()
            ->select(self::LIST_COLUMNS)
            ->withExists(self::MOBILE_IMAGE_EXISTS)
            ->with(self::THUMBNAIL_RELATION);
        $this->applyPlacement($query, self::placementFromFilters($filters));

        if (! empty($filters['status'])) {
            $this->applyStatusFilter($query, BannerStatus::from($filters['status']), $now);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $this->applyTitleSearch($query, (string) $filters['search']);
        }

        $direction = ($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($filters['sort_by'] ?? 'sort_order') {
            'status' => $this->applyStatusSort($query, $direction, $now)->orderBy('sort_order')->orderBy('id'),
            'created_at' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            default => $query->orderBy('sort_order', $direction)->orderBy('id', $direction),
        };

        // audit:allow repository-paginate-column-pruning reason: 위치당 배너는 수십 건 규모라 깊은 OFFSET 이 없고, 목록 컬럼은 LIST_COLUMNS 로 이미 줄였다
        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  상태별 건수를 낼 자리
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return array{positions: array<string, int>, targets: array<string, int>, statuses: array<string, int>} 통계
     */
    public function statistics(Placement $placement, CarbonInterface $now): array
    {
        $positions = Banner::query()->selectRaw('position, COUNT(*) as aggregate')->groupBy('position')->pluck('aggregate', 'position')
            ->map(fn ($count) => (int) $count)->all();

        $targets = Banner::query()->where('position', $placement->position)->whereNotNull('target_key')
            ->selectRaw('target_key, COUNT(*) as aggregate')->groupBy('target_key')->pluck('aggregate', 'target_key')
            ->map(fn ($count) => (int) $count)->all();

        $statuses = [];
        foreach (BannerStatus::cases() as $status) {
            $query = $this->applyPlacement(Banner::query(), $placement);
            $statuses[$status->value] = $this->applyStatusFilter($query, $status, $now)->count();
        }

        return ['positions' => $positions, 'targets' => $targets, 'statuses' => $statuses];
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, string> 위치 키
     */
    public function positionKeys(): array
    {
        // audit:allow query-unbounded-get reason: 위치 키 한 열의 중복 제거 — 위치는 확장이 등록한 수만큼(운영상 한 자리 수)
        return Banner::query()->distinct()->orderBy('position')->pluck('position')->map(fn ($key) => (string) $key)->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return bool 있으면 true
     */
    public function hasBannersAt(string $position): bool
    {
        return Banner::query()->where('position', $position)->exists();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  배너 ID
     * @return Banner 배너
     */
    public function findOrFail(int $id): Banner
    {
        return Banner::query()->with(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo'])->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return Collection<int, Banner> 배너 목록
     */
    public function findMany(array $ids): Collection
    {
        return Banner::query()->with(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo'])->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  배너 ID
     * @return array<string, string>|null 제목
     */
    public function findTitle(int $id): ?array
    {
        $banner = Banner::query()->select(['id', 'title'])->find($id);

        return $banner === null ? null : (array) $banner->title;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return Banner 만든 배너
     */
    public function create(array $data): Banner
    {
        return Banner::query()->create($data);
    }

    /**
     * 편집·이미지 교체를 부모 행 잠금으로 직렬화합니다.
     *
     * @param  int  $id  배너 ID
     * @return Banner 최신 배너
     */
    public function findForUpdate(int $id): Banner
    {
        return Banner::query()->lockForUpdate()->findOrFail($id)->load(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo']);
    }

    /**
     * {@inheritDoc}
     *
     * 한 문장으로 id 순 잠금을 잡아(단건 편집과 같은 순서라 교착이 없다) 건수와 무관하게 조회 수가 일정하다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return Collection<int, Banner> 잠근 배너 목록 (id 순)
     */
    public function findManyForUpdate(array $ids): Collection
    {
        return Banner::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()
            ->load(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, mixed>  $data  바꿀 값
     * @return Banner 고친 배너
     */
    public function update(Banner $banner, array $data): Banner
    {
        $banner->fill($data)->forceFill(['lock_version' => $banner->lock_version + 1])->save();

        return $banner->fresh(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Banner  $banner  대상 배너
     * @return bool 삭제 여부
     */
    public function delete(Banner $banner): bool
    {
        return (bool) $banner->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return int 지운 건수
     */
    public function deleteMany(array $ids): int
    {
        return $ids === [] ? 0 : Banner::query()->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @return int 다음 순서 값
     */
    public function nextSortOrder(Placement $placement): int
    {
        $max = $this->applyPlacement(Banner::query(), $placement)->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @return Collection<int, Banner> 배너 목록
     */
    public function listForPlacement(Placement $placement): Collection
    {
        $query = Banner::query()
            ->select(self::LIST_COLUMNS)
            ->withExists(self::MOBILE_IMAGE_EXISTS)
            ->with(self::THUMBNAIL_RELATION);

        // audit:allow query-unbounded-get reason: 순서 변경은 그 자리의 배너 전체가 있어야 한다(일부만 보내면 순서가 꼬인다) — 자리당 배너는 운영상 수십 건
        return $this->applyPlacement($query, $placement)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @return array<int, int> 배너 ID 목록
     */
    public function idsForPlacement(Placement $placement): array
    {
        // audit:allow query-unbounded-get reason: 순서 변경 검증은 그 자리의 배너 id 전체와 대조해야 한다 — id 한 열만, 자리당 운영상 수십 건
        return $this->applyPlacement(Banner::query(), $placement)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  새 순서의 배너 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            Banner::query()->whereKey($id)->update(['sort_order' => $index]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int
    {
        return Banner::query()->whereIn('id', $ids)->update([
            'lock_version' => DB::raw('lock_version + 1'),
            'is_active' => $isActive,
            'updated_by' => $actorId,
            'updated_at' => now(),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 배너
     */
    public function exposedForPlacement(Placement $placement, CarbonInterface $now): Collection
    {
        $query = $this->applyPlacement(Banner::query()->with(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo']), $placement);

        // audit:allow query-unbounded-get reason: 슬라이더는 노출 중인 배너를 모두 돌려야 한다 — 자리당 노출 배너는 운영상 수 건~수십 건
        return $this->applyStatusFilter($query, BannerStatus::Active, $now)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 후보
     */
    public function exposureCandidatesForPlacement(Placement $placement, CarbonInterface $now): Collection
    {
        return $this->exposureCandidatesQuery($placement, $now)
            ->with(['pcImage', 'mobileImage', 'pcVideo', 'mobileVideo'])
            ->orderBy('sort_order')
            ->orderBy('id')
            // audit:allow query-unbounded-get reason: 캐시에 담는 노출 후보(사용 중 + 아직 안 끝난 배너) 전체 — 자리당 운영상 수십 건
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @return bool 있으면 true
     */
    public function hasExposureCandidates(Placement $placement, CarbonInterface $now): bool
    {
        return $this->exposureCandidatesQuery($placement, $now)->exists();
    }

    /**
     * 노출 후보(사용 중이고 아직 끝나지 않은 배너) 조회의 공통 조건입니다.
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @return Builder<Banner> 조회
     */
    private function exposureCandidatesQuery(Placement $placement, CarbonInterface $now): Builder
    {
        return $this->applyPlacement(Banner::query(), $placement)
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->where('display_period', DisplayPeriod::Always->value)
                    ->orWhereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * {@inheritDoc}
     *
     * 예약 배너는 시작 시각에, 노출 중인 기간 배너는 종료 시각 다음 초에 상태가 바뀐다.
     *
     * @param  Placement  $placement  자리
     * @param  CarbonInterface  $now  기준 시각
     * @return CarbonInterface|null 다음 전환 시각
     */
    public function nextTransitionAt(Placement $placement, CarbonInterface $now): ?CarbonInterface
    {
        $base = fn () => $this->applyPlacement(Banner::query(), $placement)
            ->where('is_active', true)
            ->where('display_period', DisplayPeriod::Range->value);

        $nextStart = $base()->where('starts_at', '>', $now)->min('starts_at');
        $nextEnd = $base()->where('ends_at', '>=', $now)->min('ends_at');

        $candidates = [];
        if ($nextStart !== null) {
            $candidates[] = Carbon::parse($nextStart, 'UTC');
        }
        if ($nextEnd !== null) {
            $candidates[] = Carbon::parse($nextEnd, 'UTC')->addSecond();
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (CarbonInterface $a, CarbonInterface $b) => $a <=> $b);

        return $candidates[0];
    }

    /**
     * 위치 + 대상 조건을 쿼리에 겁니다 — 정적 위치(대상 null)는 대상이 비어 있는 행만, 「대상 전체」는 위치만 본다.
     *
     * @param  Builder  $query  배너 쿼리
     * @param  Placement  $placement  자리
     * @return Builder 조건을 건 쿼리
     */
    private function applyPlacement(Builder $query, Placement $placement): Builder
    {
        $query->where('position', $placement->position);
        if ($placement->anyTarget) {
            return $query;
        }

        return $placement->targetKey === null ? $query->whereNull('target_key') : $query->where('target_key', $placement->targetKey);
    }

    /**
     * 목록 필터에서 자리를 읽습니다 — `all_targets` 가 참이면 대상 전체(등록되지 않은 위치 목록), 아니면 위치 + `target_key`.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @return Placement 자리
     */
    private static function placementFromFilters(array $filters): Placement
    {
        $position = (string) ($filters['position'] ?? PositionRegistry::DEFAULT_POSITION);

        return ! empty($filters['all_targets'])
            ? Placement::allTargets($position)
            : Placement::of($position, isset($filters['target_key']) ? (string) $filters['target_key'] : null);
    }

    /**
     * 노출 상태 조건을 쿼리에 겁니다 — `Banner::statusAt()` 과 같은 규칙.
     *
     * @param  Builder  $query  배너 쿼리
     * @param  BannerStatus  $status  거를 상태
     * @param  CarbonInterface  $now  기준 시각
     * @return Builder 조건을 건 쿼리
     */
    public function applyStatusFilter(Builder $query, BannerStatus $status, CarbonInterface $now): Builder
    {
        $range = DisplayPeriod::Range->value;

        return match ($status) {
            BannerStatus::Inactive => $query->where('is_active', false),
            BannerStatus::Scheduled => $query->where('is_active', true)
                ->where('display_period', $range)
                ->where('starts_at', '>', $now),
            BannerStatus::Ended => $query->where('is_active', true)
                ->where('display_period', $range)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where('ends_at', '<', $now),
            BannerStatus::Active => $query->where('is_active', true)
                ->where(function (Builder $q) use ($now, $range) {
                    $q->where('display_period', '!=', $range)
                        ->orWhere(function (Builder $r) use ($now) {
                            $r->where(fn (Builder $s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                                ->where(fn (Builder $e) => $e->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
                        });
                }),
        };
    }

    /**
     * 노출 상태 순(노출중 → 예약 → 종료 → 비활성)으로 정렬합니다.
     *
     * @param  Builder  $query  배너 쿼리
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  CarbonInterface  $now  기준 시각
     * @return Builder 정렬을 건 쿼리
     */
    public function applyStatusSort(Builder $query, string $direction, CarbonInterface $now): Builder
    {
        $range = DisplayPeriod::Range->value;
        // audit:allow datetime-display-user-timezone reason: UTC DB 질의값 — 기계값
        $at = $now->copy()->utc()->format('Y-m-d H:i:s');
        $direction = $direction === 'desc' ? 'DESC' : 'ASC';

        return $query->orderByRaw(
            'CASE WHEN is_active = 0 THEN ? '
            .'WHEN display_period = ? AND starts_at > ? THEN ? '
            .'WHEN display_period = ? AND ends_at < ? THEN ? '
            .'ELSE ? END '.$direction,
            [
                BannerStatus::Inactive->rank(),
                $range, $at, BannerStatus::Scheduled->rank(),
                $range, $at, BannerStatus::Ended->rank(),
                BannerStatus::Active->rank(),
            ]
        );
    }

    /**
     * 제목 검색 — 번역 가능한 모든 언어의 제목에서 찾습니다.
     *
     * @param  Builder  $query  배너 쿼리
     * @param  string  $search  검색어
     * @return void 반환값 없음
     */
    private function applyTitleSearch(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        $locales = config('app.translatable_locales', ['ko', 'en']);

        $query->where(function (Builder $q) use ($locales, $like) {
            foreach ($locales as $locale) {
                $q->orWhere("title->{$locale}", 'like', $like);
            }
        });
    }
}
