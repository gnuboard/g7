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
     * @param  string|null  $from  시작일 (Y-m-d, 사이트 표준시 — null 이면 제한 없음)
     * @param  string|null  $to  종료일 (Y-m-d, 사이트 표준시 — null 이면 제한 없음)
     * @return Collection<int, AnalyticsBotHit> 봇 요청 목록 (최신순)
     */
    public function recent(int $limit = 50, ?string $from = null, ?string $to = null): Collection;

    /**
     * 보존기간이 지난 봇 요청을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 요청이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 기간 안의 봇 기록을 ID 순서로 나눠 읽습니다.
     *
     * @param  Carbon  $from  시작 시각 (포함, UTC)
     * @param  Carbon  $to  끝 시각 (미포함, UTC)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkBetween(Carbon $from, Carbon $to, int $chunkSize, callable $callback): void;

    /**
     * 기준 시각보다 오래된 봇 기록 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;

    /**
     * 기간 안의 봇 기록을 나눠서 순회합니다.
     *
     * 소급 익명화가 이 기록의 주소도 방문 원장과 같은 규칙으로 바꾸는 데 씁니다.
     *
     * @param  string|null  $from  시작 일자 (Y-m-d, null 이면 처음부터)
     * @param  string|null  $to  끝 일자 (Y-m-d, null 이면 끝까지)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void;

    /**
     * 봇 기록의 주소를 덮어씁니다.
     *
     * @param  AnalyticsBotHit  $hit  대상 기록
     * @param  string|null  $ip  새 주소 (null 이면 지움)
     * @return void 없음
     */
    public function updateIp(AnalyticsBotHit $hit, ?string $ip): void;
}
