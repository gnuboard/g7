<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * 신고 케이스 API 리소스
 *
 * 1케이스 구조: boards_reports 1행 = 1케이스.
 * 케이스 기준으로 정보를 변환하며, 신고자별 상세(reason_type 등)는 ReportDetailResource에서 제공합니다.
 */
class ReportResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        // 상세 페이지 여부 확인
        $isDetailRequest = $request->routeIs('*.show') || $request->routeIs('*.reports.show');

        // Controller에서 주입된 데이터 우선, 없으면 첫 번째 로그 스냅샷 폴백 (목록 N+1 방지)
        $firstLog = $this->logs?->first();
        $firstLogSnapshot = $firstLog?->snapshot ?? [];
        $reportable = $this->reportableData ?? [
            'title' => $firstLogSnapshot['title'] ?? null,
            'content' => $firstLogSnapshot['content'] ?? '',
            'content_mode' => $firstLogSnapshot['content_mode'] ?? 'text',
            'author_name' => $firstLogSnapshot['author_name'] ?? '',
            'author_id' => $this->author_id,
            'author_email' => null,
            'created_at' => null,
            'deleted_at' => null,
            'post' => null,
            'current_status' => null,
            'is_currently_deleted' => false,
        ];

        $contentText = $reportable['content'] ?? ($firstLogSnapshot['content'] ?? '');

        return [
            'id' => $this->id,
            'board_id' => $this->board_id,

            // 게시판 정보 (board_id 있으면 관계, 없으면 스냅샷)
            'board' => ($this->board_id && $this->board)
                ? [
                    'id' => $this->board->id,
                    'name' => $this->board->getLocalizedName(),
                    'slug' => $this->board->slug,
                    'title' => $reportable['title'] ?? $reportable['post']['title'] ?? null,
                    'current_status' => $reportable['current_status'] ?? null,
                    'deleted_at' => $reportable['deleted_at'] ?? null,
                ]
                : [
                    'id' => null,
                    'name' => $firstLogSnapshot['board_name'] ?? '',
                    'slug' => null,
                    'title' => $reportable['title'] ?? $reportable['post']['title'] ?? null,
                    'current_status' => $reportable['current_status'] ?? null,
                    'deleted_at' => $reportable['deleted_at'] ?? null,
                ],

            // 신고 대상 정보
            'target_type' => $this->target_type->value,
            'target_type_label' => $this->target_type->label(),
            'target_id' => $this->target_id,
            'post_id' => $this->target_type->value === 'post'
                ? $this->target_id
                : ($this->target_post_id ?? $reportable['post']['id'] ?? $firstLogSnapshot['post_id'] ?? null),
            'content' => $isDetailRequest ? $contentText : null,
            'content_mode' => $reportable['content_mode'] ?? 'text',
            'content_preview' => ! $isDetailRequest ? mb_substr($contentText, 0, 100) : null,

            // 작성자 정보 (케이스 author_id 우선, 없으면 스냅샷)
            'author' => ($this->author_id && $this->author)
                ? [
                    'uuid' => $this->author->uuid,
                    'name' => $this->author->name,
                    'email' => $this->author->email,
                    'is_guest' => false,
                ]
                : [
                    'uuid' => null,
                    'name' => ($firstLogSnapshot['author_name'] ?? '') ?: __('sirsoft-board::messages.common.guest'),
                    'email' => null,
                    'is_guest' => true,
                ],

            // 최초 신고자 (첫 번째 로그에서 추출, 목록용)
            'reporter' => $firstLog
                ? ($firstLog->reporter
                    ? [
                        'uuid'     => $firstLog->reporter->uuid,
                        'name'     => $firstLog->reporter->name,
                        'email'    => $firstLog->reporter->email,
                        'is_guest' => false,
                    ]
                    : [
                        'uuid'     => null,
                        'name'     => __('sirsoft-board::messages.common.guest'),
                        'email'    => null,
                        'is_guest' => true,
                    ])
                : null,

            // 대표 신고 사유 (첫 번째 로그에서 추출, 목록용)
            'reason_type' => $firstLog?->reason_type?->value ?? null,
            'reason_type_label' => $firstLog?->reason_type?->label() ?? null,

            // 상태 정보
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_variant' => $this->status->variant(),

            // 처리 정보
            'processor' => ($this->processed_by && $this->processor)
                ? [
                    'uuid' => $this->processor->uuid,
                    'name' => $this->processor->name,
                ]
                : null,
            'processed_at' => $this->processed_at
                ? $this->formatDateTimeStringForUser($this->processed_at)
                : null,

            // 메타데이터 (상세에서만)
            'metadata' => $isDetailRequest ? $this->metadata : null,

            // 케이스 신고 건수 (paginateGrouped에서 추가)
            'report_count' => isset($this->report_count) ? (int) $this->report_count : null,

            // 마지막 신고일시 (재접수 시 갱신)
            'last_reported_at' => $this->last_reported_at
                ? $this->formatDateTimeStringForUser(Carbon::parse($this->last_reported_at))
                : null,

            // 재접수 여부 (last_activated_at이 있으면 반려/중단 후 재접수된 케이스)
            'is_reactivated' => ! is_null($this->last_activated_at),

            // 대상 상태 (paginateGrouped에서 추가)
            'target_status' => $this->target_status ?? null,
            'target_trigger_type' => $this->target_trigger_type ?? null,
            'target_status_label' => isset($this->target_status)
                ? __('sirsoft-board::messages.common.status.'.$this->target_status)
                    . (($this->target_status === 'blinded' && isset($this->target_trigger_type))
                        ? match ($this->target_trigger_type) {
                            'auto_hide' => ' ('.__('sirsoft-board::messages.common.blind_type.auto').')',
                            'admin'     => ' ('.__('sirsoft-board::messages.common.blind_type.manual').')',
                            default     => '',
                        }
                        : '')
                : null,

            // 타임스탬프
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 표준 권한 메타 (permissions)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_view' => 'sirsoft-board.reports.view',
            'can_manage' => 'sirsoft-board.reports.manage',
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null
     */
    protected function ownerField(): ?string
    {
        return 'reporter_id';
    }

    /**
     * 스냅샷 기반 최소 데이터를 반환합니다 (목록 조회 시 DB 쿼리 방지).
     *
     * @return array 스냅샷 기반 reportable 데이터
     */
    private function buildSnapshotFallback(): array
    {
        return [
            'title' => $this->snapshot['title'] ?? null,
            'content' => $this->snapshot['content'] ?? '',
            'content_mode' => $this->snapshot['content_mode'] ?? 'text',
            'author_name' => $this->snapshot['author_name'] ?? '',
            'author_id' => $this->author_id,
            'author_email' => null,
            'created_at' => null,
            'deleted_at' => null,
            'post' => null,
            'current_status' => null,
            'is_currently_deleted' => false,
        ];
    }
}
