<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsBotHit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 봇 요청 Repository 인터페이스
 */
interface BotHitRepositoryInterface
{
    /**
     * 봇 요청을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  봇 요청 속성
     * @return AnalyticsBotHit 생성된 봇 요청
     */
    public function create(array $attributes): AnalyticsBotHit;

    /**
     * 최근 봇 요청을 조회합니다.
     *
     * @param  int  $limit  최대 건수
     * @return Collection<int, AnalyticsBotHit> 봇 요청 목록
     */
    public function recent(int $limit = 50): Collection;

    /**
     * 보존기간이 지난 봇 요청을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 요청이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;
}
