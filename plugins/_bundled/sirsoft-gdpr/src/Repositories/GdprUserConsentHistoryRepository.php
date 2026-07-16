<?php

namespace Plugins\Sirsoft\Gdpr\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;

/**
 * GDPR 동의 변경 이력 Repository 구현체 (immutable append-only)
 */
class GdprUserConsentHistoryRepository implements GdprUserConsentHistoryRepositoryInterface
{
    /**
     * 동의 이력 레코드를 생성합니다.
     *
     * @param array $data 이력 데이터
     * @return GdprUserConsentHistory
     */
    public function record(array $data): GdprUserConsentHistory
    {
        return GdprUserConsentHistory::create($data);
    }

    /**
     * 관리자 화면용 페이지네이션 조회.
     *
     * @param  array<string, mixed>  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = GdprUserConsentHistory::query()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['email'])) {
            $email = (string) $filters['email'];
            $query->whereHas('user', function ($q) use ($email) {
                $q->where('email', 'like', '%'.$email.'%');
            });
        }

        if (! empty($filters['session_id'])) {
            // 부분 일치 (LIKE %val%) — DataGrid 가 session_id 앞 8자만 표시하므로
            // 운영자가 본 prefix 또는 중간 부분으로도 검색할 수 있도록 함. email 필터와 일관성 유지.
            // history 테이블은 운영자 화면 단위라 LIKE 풀스캔 허용 가능.
            $query->where('session_id', 'like', '%'.(string) $filters['session_id'].'%');
        }

        if (! empty($filters['consent_keys']) && is_array($filters['consent_keys'])) {
            $query->whereIn('consent_key', $filters['consent_keys']);
        }

        if (! empty($filters['actions']) && is_array($filters['actions'])) {
            $query->whereIn('action', $filters['actions']);
        }

        if (! empty($filters['sources']) && is_array($filters['sources'])) {
            $query->whereIn('source', $filters['sources']);
        }

        return $query->paginate(max(1, min(100, $perPage)));
    }

    /**
     * 사용자 ID로 동의 이력을 조회합니다 (최신순).
     *
     * @param int $userId 사용자 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getByUserId(int $userId): Collection
    {
        return GdprUserConsentHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 게스트 세션 ID로 동의 이력을 조회합니다 (최신순).
     *
     * @param string $sessionId 게스트 세션 ID
     * @return Collection<int, GdprUserConsentHistory>
     */
    public function getBySessionId(string $sessionId): Collection
    {
        return GdprUserConsentHistory::where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 사용자 식별 정보를 NULL로 익명화합니다 (사용자 완전 삭제 시 감사 추적용).
     *
     * @param int $userId 사용자 ID
     * @return int 영향받은 행 수
     */
    public function anonymizeForUser(int $userId): int
    {
        return GdprUserConsentHistory::where('user_id', $userId)->update([
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
