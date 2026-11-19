<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;

/**
 * 소셜 로그인 시도 기록 저장소 구현.
 *
 * @since 1.0.0
 */
class AttemptRepository implements AttemptRepositoryInterface
{
    /**
     * 시도 1건을 기록합니다.
     *
     * 문자열 길이는 컬럼 한도에 맞춰 자른다 — 제공자 오류 본문이 길어도 기록이 실패하지 않게.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (provider_key·intent·outcome 필수)
     * @return SocialLoginAttempt 기록한 행
     */
    public function record(array $attributes): SocialLoginAttempt
    {
        foreach (['error_code' => 50, 'error_message' => 500, 'user_agent' => 500, 'ip' => 45] as $column => $limit) {
            if (isset($attributes[$column]) && is_string($attributes[$column])) {
                $attributes[$column] = mb_substr($attributes[$column], 0, $limit);
            }
        }

        return SocialLoginAttempt::query()->create($attributes + ['created_at' => now()]);
    }

    /**
     * 조건에 맞는 시도 기록을 최신순 페이지로 반환합니다 (진단 화면).
     *
     * @param  array<string, mixed>  $filters  provider·outcome·intent·error_code
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 시도 기록
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SocialLoginAttempt::query();

        foreach (['provider' => 'provider_key', 'outcome' => 'outcome', 'intent' => 'intent', 'error_code' => 'error_code'] as $input => $column) {
            if (isset($filters[$input]) && $filters[$input] !== '') {
                $query->where($column, $filters[$input]);
            }
        }

        // 기록 표는 보존 기간(기본 90일)으로 잘리고 화면은 앞쪽 몇 쪽만 넘긴다 — 깊은 OFFSET 이 생기지 않아 지연 조인은 쓰지 않는다.
        return $query->orderByDesc('id')->paginate($perPage, ['id', 'provider_key', 'user_id', 'intent', 'outcome', 'error_code', 'error_message', 'ip', 'created_at']);
    }

    /**
     * 기간 안의 제공자 × 결과별 시도 수를 반환합니다 — 관리자 연결 테스트(test 의도)는 뺀다.
     *
     * @param  \DateTimeInterface  $since  시작 시각
     * @return array<string, array<string, int>> 제공자 키 => 결과 => 수
     */
    public function outcomeCountsByProvider(\DateTimeInterface $since): array
    {
        $map = [];

        SocialLoginAttempt::query()
            ->selectRaw('provider_key, outcome, COUNT(*) as aggregate')
            ->where('created_at', '>=', $since)
            ->where('intent', '!=', AttemptIntent::Test->value)
            ->groupBy('provider_key', 'outcome')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[$row->provider_key][$row->outcome] = (int) $row->aggregate;
            });

        return $map;
    }

    /**
     * 기간 안의 오류 코드별 시도 수를 반환합니다.
     *
     * @param  string  $errorCode  오류 코드
     * @param  \DateTimeInterface  $since  시작 시각
     * @return int 수
     */
    public function countErrorSince(string $errorCode, \DateTimeInterface $since): int
    {
        return SocialLoginAttempt::query()->where('error_code', $errorCode)->where('created_at', '>=', $since)->count();
    }

    /**
     * 보존 기한이 지난 기록을 지웁니다.
     *
     * @param  \DateTimeInterface  $before  이 시각보다 오래된 행
     * @param  bool  $dryRun  true 면 세기만
     * @return int 대상 행 수
     */
    public function pruneBefore(\DateTimeInterface $before, bool $dryRun = false): int
    {
        $query = SocialLoginAttempt::query()->where('created_at', '<', $before);
        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;
        $query->select('id')->chunkById(500, function ($rows) use (&$deleted) {
            $deleted += SocialLoginAttempt::query()->whereIn('id', $rows->pluck('id'))->delete();
        });

        return $deleted;
    }
}
