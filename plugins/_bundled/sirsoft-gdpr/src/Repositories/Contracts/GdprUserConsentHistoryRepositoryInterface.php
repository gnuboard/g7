<?php

namespace Plugins\Sirsoft\Gdpr\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;

/**
 * GDPR 동의 변경 이력 Repository 인터페이스 (immutable append-only)
 */
interface GdprUserConsentHistoryRepositoryInterface
{
    /**
     * 동의 이력 레코드를 생성합니다.
     *
     * @param array $data 이력 데이터
     * @return GdprUserConsentHistory
     */
    public function record(array $data): GdprUserConsentHistory;

    /**
     * 관리자 화면용 페이지네이션 조회.
     *
     * 필터:
     * - email: 회원 이메일 부분 일치 (LIKE)
     * - session_id: 게스트 세션 ID 완전 일치
     * - consent_keys: 동의 항목 키 배열 (whereIn)
     * - actions: granted|revoked 배열 (whereIn)
     * - sources: banner|preference_center|mypage 배열 (whereIn)
     *
     * @param  array<string, mixed>  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * 사용자 ID로 동의 이력을 조회합니다 (최신순).
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getByUserId(int $userId): Collection;

    /**
     * 게스트 세션 ID로 동의 이력을 조회합니다 (최신순).
     *
     * @param string $sessionId 게스트 세션 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getBySessionId(string $sessionId): Collection;

    /**
     * 사용자 식별 정보를 NULL로 익명화합니다 (사용자 완전 삭제 시 감사 추적용).
     *
     * @param int $userId 사용자 ID
     * @return int 영향받은 행 수
     */
    public function anonymizeForUser(int $userId): int;
}
