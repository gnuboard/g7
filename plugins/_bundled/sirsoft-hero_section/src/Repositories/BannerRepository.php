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
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;

/**
 * 배너 Repository
 *
 * 노출 상태 SQL 조건은 `applyStatusFilter()`·`applyStatusSort()` 한 곳에만 있다 — `Banner::statusAt()` 과 같은
 * 순서(비활성 > 예약 > 종료 > 노출중)와 경계(시작 정각부터, 종료 정각까지)를 쓴다.
 */
class BannerRepository implements BannerRepositoryInterface
{
    /**
     * 목록이 읽는 컬럼 — 화면이 그리는 것만 싣는다(설명·버튼 문구·색은 상세에서).
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = [
        'id', 'position', 'is_active', 'sort_order', 'display_period', 'starts_at', 'ends_at', 'title', 'created_at', 'updated_at',
    ];

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
            ->with(self::THUMBNAIL_RELATION)
            ->where('position', $filters['position'] ?? HeroPosition::HomeHero->value);

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
     * @param  string  $position  상태별 건수를 낼 위치
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return array{positions: array<string, int>, statuses: array<string, int>} 통계
     */
    public function statistics(string $position, CarbonInterface $now): array
    {
        $counts = Banner::query()->selectRaw('position, COUNT(*) as aggregate')->groupBy('position')->pluck('aggregate', 'position');

        $positions = [];
        foreach (HeroPosition::values() as $key) {
            $positions[$key] = (int) ($counts[$key] ?? 0);
        }

        $statuses = [];
        foreach (BannerStatus::cases() as $status) {
            $query = Banner::query()->where('position', $position);
            $statuses[$status->value] = $this->applyStatusFilter($query, $status, $now)->count();
        }

        return ['positions' => $positions, 'statuses' => $statuses];
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  배너 ID
     * @return Banner 배너
     */
    public function findOrFail(int $id): Banner
    {
        return Banner::query()->with(['pcImage', 'mobileImage'])->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @return Collection<int, Banner> 배너 목록
     */
    public function findMany(array $ids): Collection
    {
        return Banner::query()->with(['pcImage', 'mobileImage'])->whereIn('id', $ids)->orderBy('id')->get();
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
        return Banner::query()->lockForUpdate()->findOrFail($id)->load(['pcImage', 'mobileImage']);
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

        return $banner->fresh(['pcImage', 'mobileImage']);
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
     * @param  string  $position  위치 키
     * @return int 다음 순서 값
     */
    public function nextSortOrder(string $position): int
    {
        $max = Banner::query()->where('position', $position)->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return Collection<int, Banner> 배너 목록
     */
    public function listForPosition(string $position): Collection
    {
        // audit:allow query-unbounded-get reason: 순서 변경은 그 위치의 배너 전체가 있어야 한다(일부만 보내면 순서가 꼬인다) — 위치당 배너는 운영상 수십 건
        return Banner::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::THUMBNAIL_RELATION)
            ->where('position', $position)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return array<int, int> 배너 ID 목록
     */
    public function idsForPosition(string $position): array
    {
        // audit:allow query-unbounded-get reason: 순서 변경 검증은 그 위치의 배너 id 전체와 대조해야 한다 — id 한 열만, 위치당 운영상 수십 건
        return Banner::query()->where('position', $position)->pluck('id')->map(fn ($id) => (int) $id)->all();
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
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 배너
     */
    public function exposedForPosition(string $position, CarbonInterface $now): Collection
    {
        $query = Banner::query()->with(['pcImage', 'mobileImage'])->where('position', $position);

        // audit:allow query-unbounded-get reason: 슬라이더는 노출 중인 배너를 모두 돌려야 한다 — 위치당 노출 배너는 운영상 수 건~수십 건
        return $this->applyStatusFilter($query, BannerStatus::Active, $now)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Banner> 노출 후보
     */
    public function exposureCandidatesForPosition(string $position, CarbonInterface $now): Collection
    {
        return Banner::query()
            ->with(['pcImage', 'mobileImage'])
            ->where('position', $position)
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->where('display_period', DisplayPeriod::Always->value)
                    ->orWhereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            // audit:allow query-unbounded-get reason: 캐시에 담는 노출 후보(사용 중 + 아직 안 끝난 배너) 전체 — 위치당 운영상 수십 건
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * 예약 배너는 시작 시각에, 노출 중인 기간 배너는 종료 시각 다음 초에 상태가 바뀐다.
     *
     * @param  string  $position  위치 키
     * @param  CarbonInterface  $now  기준 시각
     * @return CarbonInterface|null 다음 전환 시각
     */
    public function nextTransitionAt(string $position, CarbonInterface $now): ?CarbonInterface
    {
        $base = fn () => Banner::query()
            ->where('position', $position)
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
