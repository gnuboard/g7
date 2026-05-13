<?php

namespace App\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;

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
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
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
     * 만료 상태로 전환할 pending challenge 를 일괄 expire 처리합니다.
     *
     * @return int 처리된 행 수
     */
    public function expirePastDue(): int
    {
        return $this->logRepository->expirePastDue();
    }
}
