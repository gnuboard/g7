<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 신고 케이스 상세 API 리소스
 *
 * 1케이스 구조: report(케이스 1행) + reporters(boards_report_logs) + process_histories 타임라인을 반환합니다.
 */
class ReportDetailResource extends BaseApiResource
{
    use ChecksBoardPermission;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        // Service에서 배열로 넘어옴
        $report = $this->resource['report'];
        $reporters = $this->resource['reporters'] ?? collect([]);        // boards_report_logs 컬렉션
        $cancelledReports = $this->resource['cancelled_reports'] ?? collect([]);
        $reportCount = $this->resource['report_count'];
        $firstReportedAt = $this->resource['first_reported_at'];

        $reportable = $this->resource['reportable'] ?? [];
        $targetType = $report->target_type->value;

        // 첫 번째 로그 스냅샷 (board_name 등 참조용)
        $firstLog = $reporters->first();
        $firstSnapshot = $firstLog?->snapshot ?? [];

        return [
            'id' => $report->id,
            'board_id' => $report->board_id,

            // 게시판 정보
            'board' => $this->getBoardInfo($report, $firstSnapshot),

            // 신고 대상 타입 정보
            'target_type' => $targetType,
            'target_id' => $report->target_id,

            // 신고 대상 상세 정보 (post 또는 comment)
            'post' => $this->buildPostData($report, $reportable, $targetType, $firstSnapshot),
            'comment' => $this->buildCommentData($report, $reportable, $targetType, $firstSnapshot),

            // 대상 상태 정보
            'target_status' => $reportable['current_status'] ?? null,
            'target_status_label' => isset($reportable['current_status'])
                ? __('sirsoft-board::messages.common.status.'.$reportable['current_status'])
                : null,
            'blind_trigger_type' => $reportable['trigger_type'] ?? null,
            'blind_trigger_type_label' => isset($reportable['trigger_type'])
                ? (TriggerType::tryFrom($reportable['trigger_type'])?->label() ?? $reportable['trigger_type'])
                : null,

            // 상태 정보
            'status' => $report->status->value,
            'available_actions' => $report->status->getAvailableTransitions(),

            // 권한 정보
            'abilities' => [
                'can_view' => $this->checkModulePermission('reports', 'view'),
                'can_manage' => $this->checkModulePermission('reports', 'manage'),
            ],

            // 신고자 목록 (boards_report_logs 기반)
            'reporters' => $reporters->map(fn ($log) => $this->formatLogItem($log))->values()->toArray(),
            'report_count' => $reportCount,
            'reason_summary' => $this->buildReasonSummary($reporters),
            'first_reported_at' => $this->formatDateTimeStringForUser($firstReportedAt),
            'last_reported_at' => $report->last_reported_at
                ? $this->formatDateTimeStringForUser($report->last_reported_at)
                : null,

            // 처리 이력 타임라인 (process_histories JSON)
            'histories' => $this->buildHistories($report),

            // 메타데이터
            'metadata' => $report->metadata,

            // 타임스탬프
            'created_at' => $this->formatDateTimeStringForUser($report->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($report->updated_at),
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 게시판/대상 정보
    // =========================================================================

    /**
     * 게시판 정보를 반환합니다.
     *
     * @param  mixed  $report  신고 모델
     * @param  array  $firstSnapshot  첫 번째 로그 스냅샷
     * @return array<string, mixed> 게시판 정보
     */
    private function getBoardInfo($report, array $firstSnapshot): array
    {
        if ($report->board_id && $report->board) {
            return [
                'id' => $report->board->id,
                'name' => $report->board->getLocalizedName(),
                'slug' => $report->board->slug,
            ];
        }

        return [
            'id' => null,
            'name' => $firstSnapshot['board_name'] ?? '',
            'slug' => null,
        ];
    }

    /**
     * 게시글 정보를 구성합니다.
     *
     * @param  mixed  $report  신고 모델
     * @param  array|null  $reportable  신고 대상 정보
     * @param  string  $targetType  대상 타입
     * @param  array  $firstSnapshot  첫 번째 로그 스냅샷
     * @return array<string, mixed>|null 게시글 정보
     */
    private function buildPostData($report, ?array $reportable, string $targetType, array $firstSnapshot): ?array
    {
        if ($targetType === 'post') {
            $createdAtRaw = $reportable['created_at'] ?? null;

            return [
                'id' => $report->target_id,
                'title' => $reportable['title'] ?? ($firstSnapshot['title'] ?? null),
                'content' => $reportable['content'] ?? ($firstSnapshot['content'] ?? ''),
                'content_mode' => $reportable['content_mode'] ?? 'text',
                'created_at' => $createdAtRaw ? $this->formatDateTimeStringForUser(\Carbon\Carbon::parse($createdAtRaw)) : null,
                'author' => $this->buildAuthorInfo($reportable, $firstSnapshot['author_name'] ?? ''),
            ];
        }

        // 댓글인 경우 상위 게시글 정보
        if ($targetType === 'comment') {
            if (! isset($reportable['post'])) {
                return [
                    'id' => null,
                    'title' => $firstSnapshot['title'] ?? null,
                    'content' => null,
                    'created_at' => null,
                    'author' => null,
                ];
            }

            $postCreatedAtRaw = $reportable['post']['created_at'] ?? null;

            return [
                'id' => $reportable['post']['id'] ?? null,
                'title' => $reportable['post']['title'] ?? null,
                'content' => null,
                'created_at' => $postCreatedAtRaw ? $this->formatDateTimeStringForUser(\Carbon\Carbon::parse($postCreatedAtRaw)) : null,
                'author' => isset($reportable['post']['author'])
                    ? [
                        'uuid' => $reportable['post']['author']['uuid'] ?? null,
                        'name' => $reportable['post']['author']['name'] ?? null,
                        'email' => $reportable['post']['author']['email'] ?? null,
                    ]
                    : null,
            ];
        }

        return null;
    }

    /**
     * 댓글 정보를 구성합니다.
     *
     * @param  mixed  $report  신고 모델
     * @param  array|null  $reportable  신고 대상 정보
     * @param  string  $targetType  대상 타입
     * @param  array  $firstSnapshot  첫 번째 로그 스냅샷
     * @return array<string, mixed>|null 댓글 정보
     */
    private function buildCommentData($report, ?array $reportable, string $targetType, array $firstSnapshot): ?array
    {
        if ($targetType !== 'comment') {
            return null;
        }

        $commentCreatedAtRaw = $reportable['created_at'] ?? null;

        return [
            'id' => $report->target_id,
            'content' => $reportable['content'] ?? ($firstSnapshot['content'] ?? ''),
            'created_at' => $commentCreatedAtRaw ? $this->formatDateTimeStringForUser(\Carbon\Carbon::parse($commentCreatedAtRaw)) : null,
            'author' => $this->buildAuthorInfo($reportable, $firstSnapshot['author_name'] ?? ''),
        ];
    }

    /**
     * 작성자 정보를 구성합니다.
     *
     * @param  array|null  $reportable  신고 대상 정보
     * @param  string  $snapshotName  스냅샷 작성자 이름
     * @return array<string, mixed> 작성자 정보
     */
    private function buildAuthorInfo(?array $reportable, string $snapshotName): array
    {
        $authorId = $reportable['author_id'] ?? null;

        return [
            'uuid' => $reportable['author_uuid'] ?? null,
            'name' => $reportable['author_name'] ?? $snapshotName ?: __('sirsoft-board::messages.common.guest'),
            'email' => $reportable['author_email'] ?? null,
            'is_guest' => $authorId === null,
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 신고자 로그 목록
    // =========================================================================

    /**
     * 신고자 로그 항목을 포맷팅합니다 (boards_report_logs 기반).
     *
     * @param  mixed  $log  ReportLog 모델
     * @return array<string, mixed> 포맷팅된 신고자 항목
     */
    private function formatLogItem($log): array
    {
        return [
            'id' => $log->id,
            'reporter' => $log->reporter
                ? [
                    'uuid' => $log->reporter->uuid,
                    'name' => $log->reporter->name,
                    'email' => $log->reporter->email,
                ]
                : null,
            'reason_type' => $log->reason_type?->value ?? null,
            'reason_type_label' => $log->reason_type?->label() ?? null,
            'reason_detail' => $log->reason_detail,
            'snapshot' => $log->snapshot,
            'reported_at' => $this->formatDateTimeStringForUser($log->created_at),
        ];
    }

    // =========================================================================
    // 헬퍼 메서드 - 처리 이력
    // =========================================================================

    /**
     * 처리 이력을 구성합니다 (process_histories JSON 타임라인 기반).
     *
     * @param  mixed  $report  신고 모델
     * @return array<int, array<string, mixed>> 처리 이력 배열
     */
    private function buildHistories($report): array
    {
        $histories = $report->process_histories ?? [];

        return collect($histories)->reverse()->values()->map(function ($history, $index) {
            $type = $history['type'] ?? $history['action'] ?? null;
            $processorId = $history['processor_id'] ?? null;
            $processor = $processorId ? User::find($processorId) : null;

            // reported/re_reported는 신고자 접수 이벤트 — 처리자 없음
            $isReportEvent = in_array($type, ['reported', 're_reported']);
            $processorName = $isReportEvent
                ? null
                : ($processor?->name ?? __('sirsoft-board::messages.common.system'));
            $processorEmail = $isReportEvent ? '' : ($processor?->email ?? '');

            $createdAtRaw = $history['created_at'] ?? null;
            $createdAt = $createdAtRaw ? \Carbon\Carbon::parse($createdAtRaw) : null;

            return [
                'id' => $index + 1,
                'type' => $type,
                'action_label' => $history['action_label'] ?? null,
                'processor_id' => $processorId,
                'processor_name' => $processorName,
                'processor_email' => $processorEmail,
                'reason' => $history['reason'] ?? null,
                'reporter_count' => $history['reporter_count'] ?? null,
                'created_at' => $createdAt ? $this->formatDateTimeStringForUser($createdAt) : null,
            ];
        })->toArray();
    }

    // =========================================================================
    // 헬퍼 메서드 - 사유 요약
    // =========================================================================

    /**
     * 신고 사유 요약을 생성합니다 (boards_report_logs 기반).
     *
     * 상위 2개 사유를 보여주고 나머지는 "외 N건" 형식으로 표시합니다.
     *
     * @param  \Illuminate\Support\Collection  $reporters  신고자 로그 컬렉션
     * @return string 신고 사유 요약
     */
    private function buildReasonSummary(\Illuminate\Support\Collection $reporters): string
    {
        $reasonCounts = $reporters
            ->filter(fn ($log) => $log->reason_type !== null)
            ->groupBy(fn ($log) => $log->reason_type->value)
            ->map(fn ($group) => [
                'label' => $group->first()->reason_type->label(),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        if ($reasonCounts->isEmpty()) {
            return '-';
        }

        $topReasons = $reasonCounts->take(2)->map(function ($reason) {
            $format = __('sirsoft-board::messages.reports.reason_count_format');
            $format = str_replace('{reason}', $reason['label'], $format);

            return str_replace('{count}', $reason['count'], $format);
        })->join(' · ');

        $remainingCount = $reasonCounts->skip(2)->sum('count');
        if ($remainingCount > 0) {
            $format = __('sirsoft-board::messages.reports.reason_others_format');
            $topReasons .= ' '.str_replace('{count}', $remainingCount, $format);
        }

        return $topReasons;
    }
}
