<?php

namespace App\Repositories;

use App\Contracts\Repositories\MailSendLogRepositoryInterface;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * 메일 발송 이력 리포지토리 구현체
 */
class MailSendLogRepository implements MailSendLogRepositoryInterface
{
    /**
     * 발송 이력을 생성합니다.
     *
     * @param array $data 생성 데이터
     * @return MailSendLog 생성된 발송 이력 모델
     */
    public function create(array $data): MailSendLog
    {
        return MailSendLog::create($data);
    }

    /**
     * 발송 이력 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'sent_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query = MailSendLog::query()->orderBy($sortBy, $sortOrder);

        if (! empty($filters['extension_type'])) {
            $types = is_array($filters['extension_type']) ? $filters['extension_type'] : [$filters['extension_type']];
            $query->whereIn('extension_type', $types);
        }

        if (! empty($filters['extension_identifier'])) {
            $identifiers = is_array($filters['extension_identifier']) ? $filters['extension_identifier'] : [$filters['extension_identifier']];
            $query->whereIn('extension_identifier', $identifiers);
        }

        if (! empty($filters['template_type'])) {
            $query->where('template_type', $filters['template_type']);
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $searchType = $filters['search_type'] ?? 'all';
            $query->where(function ($q) use ($search, $searchType) {
                if ($searchType === 'recipient_email') {
                    $q->where('recipient_email', 'like', "%{$search}%");
                } elseif ($searchType === 'recipient_name') {
                    $q->where('recipient_name', 'like', "%{$search}%");
                } elseif ($searchType === 'subject') {
                    $q->where('subject', 'like', "%{$search}%");
                } else {
                    $q->where('recipient_email', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                }
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('sent_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('sent_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * 발송 통계를 조회합니다.
     *
     * @return array{total: int, sent: int, failed: int, today: int} 통계 정보
     */
    public function getStatistics(): array
    {
        return [
            'total' => MailSendLog::count(),
            'sent' => MailSendLog::where('status', MailSendStatus::Sent->value)->count(),
            'failed' => MailSendLog::where('status', MailSendStatus::Failed->value)->count(),
            'today' => MailSendLog::whereDate('sent_at', Carbon::today())->count(),
        ];
    }

    /**
     * 발송 이력을 삭제합니다.
     *
     * @param int $id 삭제할 발송 이력 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        return MailSendLog::where('id', $id)->delete() > 0;
    }

    /**
     * 여러 발송 이력을 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 발송 이력 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        return MailSendLog::whereIn('id', $ids)->delete();
    }
}
