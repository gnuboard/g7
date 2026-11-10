<?php

namespace Plugins\Sirsoft\Popup\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Popup\Enums\DisplayPeriod;
use Plugins\Sirsoft\Popup\Enums\PopupDevice;
use Plugins\Sirsoft\Popup\Enums\PopupStatus;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;

/**
 * 팝업 Repository
 *
 * 노출 상태 SQL 조건은 `applyStatusFilter()`·`applyStatusSort()` 한 곳에만 있다 — `Popup::statusAt()` 과 같은
 * 순서(비활성 > 예약 > 종료 > 노출중)와 경계(시작 정각부터, 종료 정각까지)를 쓴다.
 */
class PopupRepository implements PopupRepositoryInterface
{
    /**
     * 목록이 읽는 컬럼 — 본문·alt 는 응답에 싣지 않고 채운 언어(`filled_locales`)를 계산하려고만 읽는다.
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = [
        'id', 'title', 'is_active', 'sort_order', 'content_type', 'display_period', 'starts_at', 'ends_at',
        'page_scope', 'routes', 'audience', 'device', 'image_alt', 'body_html', 'created_at', 'updated_at',
    ];

    /**
     * 목록·순서 창 썸네일로 읽는 PC 이미지 컬럼 — 응답이 업로드 응답과 같은 첨부 모양이라 그 값까지 읽는다.
     */
    private const THUMBNAIL_RELATION = 'pcImage:id,popup_id,device,hash,original_name,mime,size,width,height';

    /**
     * {@inheritDoc}
     *
     * 기기 필터는 「그 기기에 보이는 팝업」이다 — 전체 기기 팝업도 함께 나온다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return LengthAwarePaginator 팝업 목록
     */
    public function paginateForAdmin(array $filters, CarbonInterface $now): LengthAwarePaginator
    {
        $query = Popup::query()->select(self::LIST_COLUMNS)->with(self::THUMBNAIL_RELATION);

        if (! empty($filters['status'])) {
            $this->applyStatusFilter($query, PopupStatus::from($filters['status']), $now);
        }

        if (! empty($filters['device'])) {
            $query->whereIn('device', [PopupDevice::All->value, $filters['device']]);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('title', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%');
        }

        $direction = ($filters['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($filters['sort_by'] ?? 'sort_order') {
            'status' => $this->applyStatusSort($query, $direction, $now)->orderBy('sort_order')->orderBy('id'),
            'created_at' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            default => $query->orderBy('sort_order', $direction)->orderBy('id', $direction),
        };

        // audit:allow repository-paginate-column-pruning reason: 팝업은 운영상 수십 건 규모라 깊은 OFFSET 이 없고, 목록 컬럼은 LIST_COLUMNS 로 이미 줄였다
        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * {@inheritDoc}
     *
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return array{total: int, statuses: array<string, int>} 통계
     */
    public function statistics(CarbonInterface $now): array
    {
        $statuses = [];
        foreach (PopupStatus::cases() as $status) {
            $statuses[$status->value] = $this->applyStatusFilter(Popup::query(), $status, $now)->count();
        }

        return ['total' => array_sum($statuses), 'statuses' => $statuses];
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  팝업 ID
     * @return Popup 팝업
     */
    public function findOrFail(int $id): Popup
    {
        return Popup::query()->with(['pcImage', 'mobileImage'])->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @return Collection<int, Popup> 팝업 목록
     */
    public function findMany(array $ids): Collection
    {
        return Popup::query()->with(['pcImage', 'mobileImage'])->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  팝업 ID
     * @return string|null 관리용 제목
     */
    public function findTitle(int $id): ?string
    {
        $title = Popup::query()->whereKey($id)->value('title');

        return $title === null ? null : (string) $title;
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  팝업 ID
     * @return Popup 최신 팝업
     */
    public function findForUpdate(int $id): Popup
    {
        return Popup::query()->lockForUpdate()->findOrFail($id)->load(['pcImage', 'mobileImage']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return Popup 만든 팝업
     */
    public function create(array $data): Popup
    {
        return Popup::query()->create($data);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, mixed>  $data  바꿀 값
     * @return Popup 고친 팝업
     */
    public function update(Popup $popup, array $data): Popup
    {
        $popup->fill($data)->forceFill(['lock_version' => $popup->lock_version + 1])->save();

        return $popup->fresh(['pcImage', 'mobileImage']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, mixed>|null  $overlays  글 상자 값
     * @return Popup 고친 팝업
     */
    public function replaceOverlays(Popup $popup, ?array $overlays): Popup
    {
        $popup->forceFill(['overlays' => $overlays])->save();

        return $popup->fresh(['pcImage', 'mobileImage']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Popup  $popup  대상 팝업
     * @return bool 삭제 여부
     */
    public function delete(Popup $popup): bool
    {
        return (bool) $popup->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @return int 다음 순서 값
     */
    public function nextSortOrder(): int
    {
        $max = Popup::query()->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * {@inheritDoc}
     *
     * @return Collection<int, Popup> 팝업 목록
     */
    public function listAllInOrder(): Collection
    {
        // audit:allow query-unbounded-get reason: 순서 변경은 팝업 전체가 있어야 한다(일부만 보내면 순서가 꼬인다) — 팝업은 운영상 수십 건
        return Popup::query()
            ->select(self::LIST_COLUMNS)
            ->with(self::THUMBNAIL_RELATION)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, int> 팝업 ID 목록
     */
    public function allIds(): array
    {
        // audit:allow query-unbounded-get reason: 순서 변경 검증은 팝업 id 전체와 대조해야 한다 — id 한 열만, 운영상 수십 건
        return Popup::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  새 순서의 팝업 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            Popup::query()->whereKey($id)->update(['sort_order' => $index]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int
    {
        return Popup::query()->whereIn('id', $ids)->update([
            'lock_version' => DB::raw('lock_version + 1'),
            'is_active' => $isActive,
            'updated_by' => $actorId,
            'updated_at' => now(),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Popup> 노출 중 팝업
     */
    public function activeInOrder(CarbonInterface $now): Collection
    {
        $query = Popup::query()->select(self::LIST_COLUMNS)->with(self::THUMBNAIL_RELATION);

        // audit:allow query-unbounded-get reason: 레이어는 노출 중 팝업을 모두 돌린다 — 운영상 수 건
        return $this->applyStatusFilter($query, PopupStatus::Active, $now)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $audiences  요청자에게 맞는 노출 대상 값
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Popup> 노출 후보
     */
    public function exposureCandidates(array $audiences, CarbonInterface $now): Collection
    {
        return Popup::query()
            ->with(['pcImage', 'mobileImage'])
            ->where('is_active', true)
            ->whereIn('audience', $audiences)
            ->where(function (Builder $q) use ($now) {
                $q->where('display_period', DisplayPeriod::Always->value)
                    ->orWhereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            // audit:allow query-unbounded-get reason: 캐시에 담는 노출 후보(사용 중 + 아직 안 끝난 팝업) 전체 — 운영상 수 건~수십 건
            ->get();
    }

    /**
     * {@inheritDoc}
     *
     * 예약 팝업은 시작 시각에, 노출 중인 기간 팝업은 종료 시각 다음 초에 상태가 바뀐다.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @return CarbonInterface|null 다음 전환 시각
     */
    public function nextTransitionAt(CarbonInterface $now): ?CarbonInterface
    {
        $base = fn () => Popup::query()->where('is_active', true)->where('display_period', DisplayPeriod::Range->value);

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
     * {@inheritDoc}
     *
     * @param  Builder  $query  팝업 쿼리
     * @param  PopupStatus  $status  거를 상태
     * @param  CarbonInterface  $now  기준 시각
     * @return Builder 조건을 건 쿼리
     */
    public function applyStatusFilter(Builder $query, PopupStatus $status, CarbonInterface $now): Builder
    {
        $range = DisplayPeriod::Range->value;

        return match ($status) {
            PopupStatus::Inactive => $query->where('is_active', false),
            PopupStatus::Scheduled => $query->where('is_active', true)
                ->where('display_period', $range)
                ->where('starts_at', '>', $now),
            PopupStatus::Ended => $query->where('is_active', true)
                ->where('display_period', $range)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where('ends_at', '<', $now),
            PopupStatus::Active => $query->where('is_active', true)
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
     * @param  Builder  $query  팝업 쿼리
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
            'CASE WHEN is_active = ? THEN ? '
            .'WHEN display_period = ? AND starts_at > ? THEN ? '
            .'WHEN display_period = ? AND ends_at < ? THEN ? '
            .'ELSE ? END '.$direction,
            [
                false, PopupStatus::Inactive->rank(),
                $range, $at, PopupStatus::Scheduled->rank(),
                $range, $at, PopupStatus::Ended->rank(),
                PopupStatus::Active->rank(),
            ]
        );
    }
}
