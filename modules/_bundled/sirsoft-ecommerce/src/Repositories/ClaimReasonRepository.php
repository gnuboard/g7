<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;

/**
 * 클레임 사유 Repository 구현체
 */
class ClaimReasonRepository implements ClaimReasonRepositoryInterface
{
    public function __construct(
        protected ClaimReason $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 유형은 필수다 — 기본값을 두면 유형을 빠뜨린 호출부가 조용히 환불 카탈로그만 받는다.
        // 카탈로그가 3종이 된 뒤로는 그 결과가 "교환·반품 사유가 하나도 없다" 로 보이며,
        // 예외도 로그도 남지 않아 화면에서만 빈 목록으로 드러난다. 요청 경계
        // (`IndexClaimReasonRequest::filters()`)가 이미 미지정 시 환불로 해석하므로,
        // 그 결정은 한 곳에만 있어야 한다.
        if (! isset($filters['type'])) {
            // 사용자에게 닿는 문구가 아니라 호출부의 계약 위반(500)이라 다국어 키를 두지 않는다.
            throw new InvalidArgumentException('ClaimReasonRepository::getAll() requires a "type" filter.');
        }

        $query->where('type', $filters['type']);

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 귀책 유형 필터
        if (! empty($filters['fault_type'])) {
            $query->where('fault_type', $filters['fault_type']);
        }

        // 검색 키워드
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                }
                $q->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        // 정렬
        $query->orderBy('sort_order')->orderBy('id');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?ClaimReason
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByCode(string $code, string $type = 'refund'): ?ClaimReason
    {
        return $this->model->where('type', $type)->where('code', $code)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCodes(string $type, array $codes): Collection
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), fn (string $c) => $c !== '')));

        if ($codes === []) {
            return new Collection;
        }

        // 결과 크기는 요청 페이지의 서로 다른 사유 코드 수에 묶인다 (per_page 상한 이하).
        //
        // 형제(배송사·배송유형)는 `get(['id', 'code'])` 로 좁히는데 여기만 전컬럼인 것은 의도다 —
        // 소비처가 둘이고, `UserClaimService::resolveReasonLabels()` 가 `getLocalizedName()` 을
        // 부른다. 다국어 `name` 을 빼면 그 화면의 사유 라벨이 조용히 비므로 좁히지 않는다.
        // (형제는 소비처가 코드 맞바꾸기 판정 하나뿐이라 `id`·`code` 로 충분하다.)
        return $this->model->newQuery()
            ->where('type', $type)
            ->whereIn('code', $codes)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ClaimReason
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ClaimReason
    {
        $reason = $this->findById($id);
        $reason->update($data);

        return $reason->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $reason = $this->findById($id);

        return $reason->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function existsByCode(string $code, string $type = 'refund', ?int $excludeId = null): bool
    {
        $query = $this->model->where('type', $type)->where('code', $code);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveReasons(string $type): Collection
    {
        return $this->model->newQuery()
            ->ofType($type)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getUserSelectableReasons(string $type): Collection
    {
        return $this->model->newQuery()
            ->ofType($type)
            ->active()
            ->userSelectable()
            ->ordered()
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getIdsByType(string $type): array
    {
        return $this->model->newQuery()
            ->where('type', $type)
            ->pluck('id')
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function getCodesByType(string $type): array
    {
        return $this->model->newQuery()
            ->where('type', $type)
            ->pluck('code')
            ->all();
    }
}
