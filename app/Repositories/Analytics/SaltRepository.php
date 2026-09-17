<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Models\Analytics\AnalyticsSalt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * 일자별 솔트 Repository
 */
class SaltRepository implements SaltRepositoryInterface
{
    /**
     * 요청 안에서 쓰는 솔트 캐시
     *
     * 익명 모드에서는 방문마다 솔트를 찾습니다. 캐시 저장소가 데이터베이스인 설치가
     * 기본이라 그때마다 조회하면 수집이 DB 를 한 번 더 두드립니다. 솔트는 하루
     * 내내 같은 값이므로 프로세스 안에서 한 번만 읽으면 충분합니다.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * 지정한 날짜의 솔트를 가져오고 없으면 만듭니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return string 그날의 솔트
     */
    public function forDate(string $date): string
    {
        if (isset($this->cache[$date])) {
            return $this->cache[$date];
        }

        $existing = AnalyticsSalt::query()->find($date);

        if ($existing) {
            return $this->cache[$date] = $existing->salt;
        }

        $salt = bin2hex(random_bytes(32));

        try {
            AnalyticsSalt::query()->create([
                'salt_date' => $date,
                'salt' => $salt,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // 같은 순간에 다른 요청이 그날 솔트를 만들었다. 먼저 만든 쪽 값을 써야
            // 같은 날 방문자 키가 두 갈래로 갈라지지 않는다.
            $raced = AnalyticsSalt::query()->find($date);

            if (! $raced) {
                throw $e;
            }

            return $this->cache[$date] = $raced->salt;
        }

        return $this->cache[$date] = $salt;
    }

    /**
     * 지정한 날짜의 솔트를 조회합니다. 없으면 만들지 않습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return string|null 그날의 솔트 (없으면 null)
     */
    public function findForDate(string $date): ?string
    {
        if (isset($this->cache[$date])) {
            return $this->cache[$date];
        }

        $salt = AnalyticsSalt::query()->find($date)?->salt;

        if ($salt !== null) {
            $this->cache[$date] = $salt;
        }

        return $salt;
    }

    /**
     * 보존기간이 지난 솔트를 삭제합니다.
     *
     * @param  Carbon  $before  이 일자 이전 솔트가 대상
     * @return int 삭제한 행 수
     */
    public function deleteOlderThan(Carbon $before): int
    {
        return AnalyticsSalt::query()
            ->where('salt_date', '<', $before->toDateString())
            ->delete();
    }

    /**
     * 기준 시각보다 오래된 솔트 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsSalt::query()
            ->where('salt_date', '<', $before->toDateString())
            ->count();
    }
}
