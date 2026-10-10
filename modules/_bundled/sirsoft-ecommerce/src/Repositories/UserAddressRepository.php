<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 사용자 배송지 Repository 구현체
 */
class UserAddressRepository implements UserAddressRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * UserAddressRepository 생성자
     *
     * @param  UserAddress  $model  배송지 모델
     */
    public function __construct(
        protected UserAddress $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?UserAddress
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): UserAddress
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(UserAddress $address, array $data): UserAddress
    {
        $address->update($data);

        return $address->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(UserAddress $address): bool
    {
        return $address->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원의 배송지 목록만 고른다 — 주소록은 회원이 직접 등록하는 소량 집합이라 데이터 증가에 비례하지 않는다
        return $this->model
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findDefaultByUserId(int $userId): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function setDefault(int $userId, int $addressId): bool
    {
        return DB::transaction(function () use ($userId, $addressId) {
            // 기존 기본 배송지 해제
            $this->model
                ->where('user_id', $userId)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // 새 기본 배송지 설정
            return $this->model
                ->where('user_id', $userId)
                ->where('id', $addressId)
                ->update(['is_default' => true]) > 0;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function countByUserId(int $userId): int
    {
        return $this->model
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserIdAndId(int $userId, int $addressId): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('id', $addressId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserIdAndName(int $userId, string $name): ?UserAddress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $table = $this->model->getTable();
        $query = $this->model->newQuery();

        if (! empty($filters['user_id'])) {
            $query->where("{$table}.user_id", (int) $filters['user_id']);
        }

        // 회원 다중 한정 — 키가 있으면 빈 배열도 조건이다(고른 uuid 가 전부 사라진 회원인 경우).
        // array_key_exists 로 보는 이유가 그것이다: empty() 로 보면 그 경우가 «필터 없음» 으로
        // 삼켜져 전 회원의 배송지가 뜬다.
        if (array_key_exists('user_ids', $filters) && is_array($filters['user_ids'])) {
            $query->whereIn("{$table}.user_id", array_map('intval', $filters['user_ids']));
        }

        if (! empty($filters['country_code'])) {
            $query->where("{$table}.country_code", $filters['country_code']);
        }

        // is_default 는 「기본 배송지만」 이라는 뜻이라 false 를 명시적으로 구분한다 —
        // empty() 로 보면 "false 로 거르기" 가 필터 미적용으로 삼켜진다.
        if (array_key_exists('is_default', $filters) && $filters['is_default'] !== null && $filters['is_default'] !== '') {
            $query->where("{$table}.is_default", (bool) $filters['is_default']);
        }

        $this->applyViewerDateRangeFilter($query, "{$table}.created_at", $filters['start_date'] ?? null, $filters['end_date'] ?? null);
        $this->applyAdminSearch($query, (string) ($filters['search_field'] ?? 'member'), $filters['search_keyword'] ?? null);

        $direction = ($filters['sort'] ?? 'created_at_desc') === 'created_at_asc' ? 'asc' : 'desc';

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ["{$table}.*"],
            sort: [['column' => 'created_at', 'direction' => $direction]],
            perPage: $perPage,
            relations: ['user:id,uuid,name,email,status'],
            resultCap: PaginationLimits::resultCap('admin.user_addresses'),
        );
    }

    /**
     * 관리자 목록의 검색 조건을 적용합니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $field  검색 대상
     * @param  string|null  $keyword  검색어
     * @return void
     */
    private function applyAdminSearch(Builder $query, string $field, ?string $keyword): void
    {
        if ($keyword === null || trim($keyword) === '') {
            return;
        }

        $needle = '%'.trim($keyword).'%';
        $table = $this->model->getTable();

        match ($field) {
            // 기본 갈래 — 받는 사람·주소·회원을 한 번에 훑는다. 운영자가 손에 든 단서가 무엇인지
            // (이름인지 주소인지) 모르는 채로 검색창에 넣는 일이 대부분이다.
            'all' => $query->where(fn (Builder $w) => $w
                ->where("{$table}.recipient_name", 'like', $needle)
                ->orWhere("{$table}.recipient_phone", 'like', $needle)
                ->orWhere("{$table}.address", 'like', $needle)
                ->orWhere("{$table}.address_line_1", 'like', $needle)
                ->orWhere("{$table}.city", 'like', $needle)
                ->orWhereHas('user', fn (Builder $u) => $u->where(
                    fn (Builder $uw) => $uw->where('name', 'like', $needle)->orWhere('email', 'like', $needle)
                ))),
            'member_id' => $query->whereHas('user', fn (Builder $u) => $u->where('uuid', trim($keyword))),
            'email' => $query->whereHas('user', fn (Builder $u) => $u->where('email', 'like', $needle)),
            'recipient' => $query->where(fn (Builder $w) => $w
                ->where("{$table}.recipient_name", 'like', $needle)
                ->orWhere("{$table}.recipient_phone", 'like', $needle)),
            // 국내/해외 주소가 서로 다른 컬럼에 담기므로 둘 다 본다
            'address' => $query->where(fn (Builder $w) => $w
                ->where("{$table}.address", 'like', $needle)
                ->orWhere("{$table}.address_line_1", 'like', $needle)
                ->orWhere("{$table}.city", 'like', $needle)),
            default => $query->whereHas('user', fn (Builder $u) => $u->where(
                fn (Builder $w) => $w->where('name', 'like', $needle)->orWhere('email', 'like', $needle)
            )),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $id): bool
    {
        return (bool) $this->model->where('id', $id)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        return $this->model
            ->with(['user:id,uuid,name,email,status'])
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $ids): int
    {
        return (int) $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function countByUser(int $userId): int
    {
        return (int) $this->model->where('user_id', $userId)->count();
    }

    /**
     * 배송지를 가진 회원 ID 서브쿼리를 만듭니다 (회원 목록 확장 필터용, #705).
     *
     * 국가 조건은 `country_code` 인덱스를 탄다. 한 회원이 배송지를 여럿 가질 수 있어 결과에 같은
     * user_id 가 반복되지만 `IN (서브쿼리)` 로만 쓰이므로 중복을 걷지 않는다(정렬 비용을 아낀다).
     *
     * @param  string|null  $countryCode  국가 코드 (null 이면 국가 무관 — 배송지가 하나라도 있는 회원)
     * @return \Illuminate\Database\Query\Builder user_id 한 열을 고르는 서브쿼리
     */
    public function userIdsWithAddressQuery(?string $countryCode = null): \Illuminate\Database\Query\Builder
    {
        $query = UserAddress::query()->select('user_id');

        if ($countryCode !== null && $countryCode !== '') {
            $query->where('country_code', strtoupper($countryCode));
        }

        return $query->toBase();
    }
}
