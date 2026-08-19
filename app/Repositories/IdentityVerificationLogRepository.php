<?php

namespace App\Repositories;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Helpers\TimezoneHelper;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Repositories\Concerns\DeletesInBatches;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * identity_verification_logs Repository 구현체.
 *
 * @since 7.0.0-beta.4
 */
class IdentityVerificationLogRepository implements IdentityVerificationLogRepositoryInterface
{
    use DeletesInBatches;
    use PaginatesWithDeferredJoin;

    /**
     * 검증 로그를 생성합니다.
     *
     * @param  array  $attributes  생성 속성
     * @return IdentityVerificationLog 생성된 로그
     */
    public function create(array $attributes): IdentityVerificationLog
    {
        return IdentityVerificationLog::create($attributes);
    }

    /**
     * ID로 검증 로그를 조회합니다.
     *
     * @param  string  $id  로그 ID
     * @return IdentityVerificationLog|null 조회된 로그 또는 null
     */
    public function findById(string $id): ?IdentityVerificationLog
    {
        return IdentityVerificationLog::find($id);
    }

    /**
     * ID로 검증 로그를 업데이트합니다.
     *
     * @param  string  $id  로그 ID
     * @param  array  $attributes  업데이트 속성
     * @return bool 업데이트 성공 여부
     */
    public function updateById(string $id, array $attributes): bool
    {
        return IdentityVerificationLog::whereKey($id)->update($attributes) > 0;
    }

    /**
     * 최근에 검증 완료된 로그를 조회합니다.
     *
     * @param  string  $purpose  본인인증 목적
     * @param  int|null  $userId  사용자 ID (null 가능)
     * @param  string|null  $targetHash  대상 해시 (null 가능)
     * @param  int  $withinMinutes  조회 범위 (분)
     * @return IdentityVerificationLog|null 가장 최근 검증 로그 또는 null
     */
    public function findRecentVerified(
        string $purpose,
        ?int $userId,
        ?string $targetHash,
        int $withinMinutes,
    ): ?IdentityVerificationLog {
        $query = IdentityVerificationLog::query()
            ->where('purpose', $purpose)
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->where('verified_at', '>=', Carbon::now()->subMinutes(max(0, $withinMinutes)));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif ($targetHash !== null) {
            $query->where('target_hash', $targetHash);
        } else {
            return null;
        }

        return $query->orderByDesc('verified_at')->first();
    }

    /**
     * 미소비된 검증 토큰으로 로그를 조회합니다.
     *
     * @param  string  $token  검증 토큰
     * @param  string  $purpose  본인인증 목적
     * @return IdentityVerificationLog|null 조회된 로그 또는 null
     */
    public function findVerifiedForToken(string $token, string $purpose): ?IdentityVerificationLog
    {
        return IdentityVerificationLog::query()
            ->where('verification_token', $token)
            ->where('purpose', $purpose)
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->whereNull('consumed_at')
            ->first();
    }

    /**
     * 만료 시각이 지난 챌린지를 일괄 만료 처리합니다.
     *
     * @return int 만료 처리된 행 수
     */
    public function expirePastDue(): int
    {
        return IdentityVerificationLog::query()
            ->whereIn('status', [
                IdentityVerificationStatus::Requested->value,
                IdentityVerificationStatus::Sent->value,
            ])
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => IdentityVerificationStatus::Expired->value]);
    }

    /**
     * 지정된 일수 이전의 로그를 일괄 삭제합니다.
     *
     * @param  int  $days  보존 일수
     * @return int 삭제된 행 수
     */
    public function purgeOlderThan(int $days): int
    {
        return $this->deleteInBatches(
            IdentityVerificationLog::query()
                ->where('created_at', '<', Carbon::now()->subDays(max(1, $days)))
        );
    }

    /**
     * 필터 기반 검증 로그 페이지네이션 결과를 반환합니다.
     *
     * @param  array  $filters  검색 필터
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이터
     */
    public function search(array $filters, int $perPage = 20)
    {
        $query = IdentityVerificationLog::query();

        // 단일값 + 다중값 — 다중값(*s) 우선, 없으면 단일값 fallback (외부 링크 호환)
        $columnMap = [
            'provider_id' => 'provider_ids',
            'purpose' => 'purposes',
            'status' => 'statuses',
            'channel' => 'channels',
            'origin_type' => 'origin_types',
        ];
        foreach ($columnMap as $singleKey => $multiKey) {
            $multi = $filters[$multiKey] ?? null;
            if (is_array($multi) && $multi !== []) {
                $query->whereIn($singleKey, $multi);
            } elseif (! empty($filters[$singleKey])) {
                $query->where($singleKey, $filters[$singleKey]);
            }
        }

        // source_type / source_identifier — identity_policies 의 source 컨텍스트로 이력 필터링.
        // 직접 컬럼이 아니라 origin_policy_key ∈ (해당 source 의 정책 키 목록) 으로 매칭한다.
        if (! empty($filters['source_type'])) {
            $query->whereIn('origin_policy_key', function ($q) use ($filters) {
                $q->select('key')
                    ->from((new IdentityPolicy)->getTable())
                    ->where('source_type', $filters['source_type']);
                if (! empty($filters['source_identifier'])) {
                    $q->where('source_identifier', $filters['source_identifier']);
                }
            });
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['target_hash'])) {
            $query->where('target_hash', $filters['target_hash']);
        }

        // search + search_type: auto/user_id/target_hash/ip_address/policy_key 통합 검색.
        // auto: 입력이 모두 숫자이면 user_id, 그 외(64자 hex 등)는 target_hash 로 라우팅.
        if (! empty($filters['search'])) {
            $term = (string) $filters['search'];
            $type = $filters['search_type'] ?? 'auto';
            if ($type === 'user_id' || ($type === 'auto' && ctype_digit($term))) {
                $query->where('user_id', (int) $term);
            } elseif ($type === 'ip_address') {
                $query->where('ip_address', $term);
            } elseif ($type === 'policy_key') {
                $query->where('origin_policy_key', 'like', $term.'%');
            } else {
                $query->where('target_hash', $term);
            }
        }

        // 기간 필터는 사이트 타임존 기준으로 해석한다 — created_at 은 UTC 로 저장되고
        // 화면은 사이트 타임존으로 보여주므로, 입력 문자열을 그대로 비교하면 하루가 어긋난다.
        // 종료값은 시각 없이 날짜만 오는 경우(`<input type="date">`) 그날 끝까지 포함한다.
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', TimezoneHelper::fromSiteDateTime($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', TimezoneHelper::fromSiteRangeEnd($filters['date_to']));
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['created_at', 'attempts'], true)
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // 본인인증 로그는 계속 쌓이는 이력이라 뒤쪽 페이지에서 OFFSET 비용이 커진다.
        // 응답이 요청/응답 payload 를 그대로 노출하므로 컬럼은 좁히지 않고 지연 조인만 적용한다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => $sortBy, 'direction' => $sortOrder]],
            perPage: $perPage,
            // 로그 테이블은 계속 쌓이기만 한다. 총 건수는 상한까지만 세고 "다음" 이동은
            // per_page + 1 실측으로 끝까지 열어 둔다 (계산 불가는 마지막 페이지 번호 하나뿐).
            resultCap: PaginationLimits::resultCap('admin.identity_logs'),
        );
    }

    /**
     * 비회원 검증 로그에 사용자 ID 를 채워넣습니다.
     *
     * @param  string  $id  로그 ID
     * @param  int  $userId  사용자 ID
     * @return bool 성공 여부 (이미 user_id 가 있으면 false)
     */
    public function backfillUserId(string $id, int $userId): bool
    {
        return IdentityVerificationLog::whereKey($id)
            ->whereNull('user_id')
            ->update(['user_id' => $userId]) > 0;
    }
}
