<?php

namespace Plugins\Sirsoft\CdnCloudfront\Repositories\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\CdnCloudfront\Models\CloudfrontPurge;

/**
 * 실패한 캐시 무효화 대기열 저장소
 */
interface PurgeQueueRepositoryInterface
{
    /**
     * 실패한 무효화 한 건을 대기열에 넣습니다.
     *
     * @param  list<string>  $paths  무효화할 CDN 경로 (첫 시도에 보낸 그대로)
     * @param  string  $callerReference  첫 시도에 쓴 호출자 참조 UUID — 재시도가 같은 값으로 다시 보낸다
     * @param  int  $attempts  시도 횟수로 센 실패 수 (진행 중 한도·호출 제한 오류는 세지 않아 0)
     * @param  string  $error  실패 사유
     * @param  Carbon|null  $nextAttemptAt  다음 재시도 시각 (null 이면 재시도 안 함)
     * @return CloudfrontPurge 넣은 행
     */
    public function create(array $paths, string $callerReference, int $attempts, string $error, ?Carbon $nextAttemptAt): CloudfrontPurge;

    /**
     * 재시도 시각이 된 건을 오래된 순으로 가져옵니다.
     *
     * @param  Carbon  $now  기준 시각
     * @param  int  $limit  최대 건수
     * @return Collection<int, CloudfrontPurge> 재시도할 건
     */
    public function due(Carbon $now, int $limit): Collection;

    /**
     * 재시도가 실패한 건의 시도 횟수·사유·다음 시각을 기록합니다.
     *
     * @param  CloudfrontPurge  $purge  대상 행
     * @param  int  $attempts  누적 실패 횟수
     * @param  string  $error  실패 사유
     * @param  Carbon|null  $nextAttemptAt  다음 재시도 시각 (null 이면 재시도 중단)
     * @return void 반환값 없음
     */
    public function recordFailure(CloudfrontPurge $purge, int $attempts, string $error, ?Carbon $nextAttemptAt): void;

    /**
     * 성공한 건을 대기열에서 지웁니다.
     *
     * @param  CloudfrontPurge  $purge  대상 행
     * @return void 반환값 없음
     */
    public function delete(CloudfrontPurge $purge): void;

    /**
     * 재시도를 멈춘 지 오래된 건을 지웁니다.
     *
     * @param  Carbon  $before  이 시각 전에 마지막으로 실패한 건만
     * @return int 지운 건수
     */
    public function deleteAbandonedBefore(Carbon $before): int;

    /**
     * 재시도를 기다리는 건수
     *
     * @return int 건수
     */
    public function countPending(): int;

    /**
     * 시도 상한에 닿아 멈춘 건수
     *
     * @return int 건수
     */
    public function countAbandoned(): int;

    /**
     * 가장 최근에 실패한 건 (대기·중단 모두)
     *
     * @return CloudfrontPurge|null 행
     */
    public function latestFailure(): ?CloudfrontPurge;
}
