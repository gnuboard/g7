<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;

/**
 * GDPR 관리자 동의 로그 조회 서비스
 *
 * `gdpr_user_consent_histories` 테이블을 관리자 화면에서 페이지네이션·필터로
 * 조회할 때 사용합니다. DPO 감사용 IP/User-Agent까지 함께 노출됩니다.
 */
class GdprConsentLogService
{
    /**
     * GdprConsentLogService 생성자
     *
     * @param  GdprUserConsentHistoryRepositoryInterface  $historyRepository  동의 이력 Repository
     */
    public function __construct(
        private readonly GdprUserConsentHistoryRepositoryInterface $historyRepository,
    ) {
    }

    /**
     * 동의 로그 페이지네이션 응답을 반환합니다.
     *
     * 필터:
     * - email: 회원 이메일 부분 일치 (LIKE %email%)
     * - session_id: 게스트 세션 ID 부분 일치 (LIKE %session_id%) — DataGrid 가 앞 8자만 표시하므로 prefix/중간 부분 검색 모두 허용
     * - consent_keys: 동의 항목 키 배열 (whereIn). 비어 있으면 전체
     * - actions: granted|revoked 배열 (whereIn). 비어 있으면 전체
     * - sources: banner|preference_center|mypage 배열 (whereIn). 비어 있으면 전체
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지당 행 수 (1~100)
     * @return LengthAwarePaginator
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->historyRepository->paginateForAdmin($filters, $perPage);
    }
}
