<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface;

/**
 * 관리자 이력 화면 저장소.
 *
 * SCIM 수신 이력 표(`sso_scim_events`)는 프로비저닝 영역이 만든다 — 이 저장소는 그 표를 읽기만 하고, 표가 없으면 빈 목록이다.
 *
 * @since 1.0.0
 */
class AdminLogRepository implements AdminLogRepositoryInterface
{
    /** 기본 쪽 크기 */
    private const PER_PAGE = 20;

    /** @var array<int, string> 시도 목록 컬럼 */
    private const ATTEMPT_COLUMNS = ['id', 'idp_id', 'user_id', 'intent', 'guard', 'outcome', 'reason_code', 'ip_address', 'user_agent', 'created_at'];

    /** @var array<int, string> SCIM 이력 컬럼 */
    private const SCIM_COLUMNS = ['id', 'idp_id', 'method', 'resource', 'target_id', 'status', 'error_code', 'created_at'];

    /**
     * {@inheritDoc}
     */
    public function attempts(array $filters): LengthAwarePaginator
    {
        return SsoLoginAttempt::query()
            ->when(! empty($filters['idp_id']), fn ($q) => $q->where('idp_id', (int) $filters['idp_id']))
            ->when(! empty($filters['outcome']), fn ($q) => $q->where('outcome', $filters['outcome']))
            ->when(! empty($filters['intent']), fn ($q) => $q->where('intent', $filters['intent']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(! empty($filters['to']), fn ($q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? self::PER_PAGE), self::ATTEMPT_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function scimEvents(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE);

        if (! Schema::hasTable('sso_scim_events')) {
            return new Paginator([], 0, $perPage);
        }

        return DB::table('sso_scim_events')
            ->when(! empty($filters['idp_id']), fn (Builder $q) => $q->where('idp_id', (int) $filters['idp_id']))
            ->when(($filters['status_class'] ?? null) === 'success', fn (Builder $q) => $q->where('status', '<', 400))
            ->when(($filters['status_class'] ?? null) === 'error', fn (Builder $q) => $q->where('status', '>=', 400))
            ->when(! empty($filters['from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(! empty($filters['to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, self::SCIM_COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function recentAttempts(int $idpId, int $limit): array
    {
        return SsoLoginAttempt::query()
            ->select(['id', 'intent', 'guard', 'outcome', 'reason_code', 'response_summary', 'created_at'])
            ->where('idp_id', $idpId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (SsoLoginAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'intent' => $attempt->intent,
                'guard' => $attempt->guard,
                'outcome' => $attempt->outcome,
                'reason_code' => $attempt->reason_code,
                'response_summary' => $attempt->response_summary,
                'created_at' => $attempt->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function recentFailureCount(int $idpId, int $windowMinutes): int
    {
        return SsoLoginAttempt::query()
            ->where('idp_id', $idpId)
            ->where('outcome', 'failed')
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();
    }
}
