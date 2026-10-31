<?php

namespace App\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * IDV 인증 이력 조회/파기 Service.
 *
 * 관리자 로그 뷰어(S2/S3) 및 보관주기 기반 파기 작업에 사용됩니다.
 *
 * @since 7.0.0-beta.4
 */
class IdentityLogService
{
    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  로그 Repository
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 로그 목록을 필터와 함께 페이지네이션 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (provider_id/purpose/status/user_id/date_from/date_to 등)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator
     */
    public function search(array $filters, int $perPage = 20)
    {
        return $this->logRepository->search($filters, $perPage);
    }

    /**
     * 보관주기 경과 로그를 파기합니다.
     *
     * @param  int  $days  보관 일수 (기본 180)
     * @return int 삭제된 행 수
     */
    public function purge(int $days = 180): int
    {
        return $this->logRepository->purgeOlderThan(max(1, $days));
    }

    /**
     * 만료 시각이 지난 진행 중 challenge 를 만료 처리하고, 만료된 건마다 만료 훅을 발화합니다.
     *
     * 예전에는 상태만 일괄로 바꿔 `core.identity.challenge_expired` 구독자(활동 로그 등)가 한 번도 불리지 않았다.
     * 만료된 요청 1건 = 발화 1회 = 활동 로그 1줄이다. 조회와 변경 사이에 인증이 끝난 건은 만료로 덮지 않고
     * 발화도 하지 않는다.
     *
     * @return int 이번 실행에서 만료로 바뀐 건수
     */
    public function expirePastDue(): int
    {
        $expired = 0;

        $this->logRepository->chunkPendingPastDue(500, function ($logs) use (&$expired) {
            foreach ($logs as $log) {
                if (! $this->logRepository->expireIfPending($log)) {
                    continue;
                }

                $log->status = IdentityVerificationStatus::Expired;
                HookManager::doAction('core.identity.challenge_expired', $log);
                $expired++;
            }
        });

        return $expired;
    }
}
