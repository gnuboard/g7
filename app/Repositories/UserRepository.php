<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Models\User;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UserRepository implements UserRepositoryInterface
{
    use HasMultipleSearchFilters;

    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'email'];

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
     * @return LengthAwarePaginator 페이지네이션된 사용자 목록
     */
    public function getPaginatedUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::query();

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.users.read');

        // roles 관계 eager loading
        $query->with('roles');

        // 검색 조건 적용
        $this->applyFilters($query, $filters);

        // 정렬 적용
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // 페이지네이션 적용
        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * 쿼리에 필터 조건을 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  적용할 필터 조건 배열
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // 다중 검색 조건 적용
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $this->applyMultipleSearchFilters($query, $filters['filters'], self::SEARCHABLE_FIELDS);
        }

        // 날짜 필터
        $this->applyDateFilters($query, $filters);
    }

    /**
     * 날짜 필터를 적용합니다.
     *
     * @param  Builder  $query  Eloquent 쿼리 빌더
     * @param  array  $filters  필터 조건 배열
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

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
     * @return array 사용자 통계 데이터 배열
     */
    public function getStatistics(): array
    {
        return [
            'total_users' => User::count(),
            'users_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'users_this_month' => User::where('created_at', '>=', now()->subMonth())->count(),
            'users_today' => User::whereDate('created_at', today())->count(),
            'active_users_this_week' => User::where('last_login_at', '>=', now()->subWeek())->count(),
        ];
    }

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param  string  $keyword  검색할 키워드
     * @return Collection 검색된 사용자 컬렉션
     */
    public function searchByKeyword(string $keyword): Collection
    {
        $query = User::where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
                ->orWhere('nickname', 'like', "%{$keyword}%")
                ->orWhere('email', 'like', "%{$keyword}%");
        });

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.users.read');

        return $query->get();
    }

    /**
     * 최근 등록된 사용자들을 조회합니다.
     *
     * @param  int  $limit  조회할 사용자 수 (기본값: 10)
     * @return Collection 최근 사용자 컬렉션
     */
    public function getRecentUsers(int $limit = 10): Collection
    {
        return User::orderBy('created_at', 'desc')->limit($limit)->get();
    }

    /**
     * 언어별 사용자 수를 조회합니다.
     *
     * @return array 언어별 사용자 수 배열
     */
    public function getUsersByLanguage(): array
    {
        return User::selectRaw('language, count(*) as count')
            ->groupBy('language')
            ->pluck('count', 'language')
            ->toArray();
    }
}
