<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 관리자 이력 화면 저장소 계약 — 로그인 시도, SCIM 수신.
 *
 * @since 1.0.0
 */
interface AdminLogRepositoryInterface
{
    /**
     * 로그인 시도 목록 (최신순).
     *
     * @param  array{idp_id?: int|null, outcome?: string|null, intent?: string|null, from?: string|null, to?: string|null, per_page?: int|null}  $filters  조건
     * @return LengthAwarePaginator 페이지
     */
    public function attempts(array $filters): LengthAwarePaginator;

    /**
     * SCIM 수신 이력 목록 (최신순). 표가 아직 없으면 빈 페이지.
     *
     * @param  array{idp_id?: int|null, status_class?: string|null, from?: string|null, to?: string|null, per_page?: int|null}  $filters  조건
     * @return LengthAwarePaginator 페이지
     */
    public function scimEvents(array $filters): LengthAwarePaginator;

    /**
     * IdP 의 최근 시도 N건 (진단 카드).
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $limit  건수
     * @return array<int, array<string, mixed>> 시도
     */
    public function recentAttempts(int $idpId, int $limit): array;

    /**
     * IdP 의 최근 실패 수를 셉니다 (연속 실패 알림 임계).
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $windowMinutes  최근 몇 분
     * @return int 실패 수 (`outcome=failed`)
     */
    public function recentFailureCount(int $idpId, int $windowMinutes): int;
}
