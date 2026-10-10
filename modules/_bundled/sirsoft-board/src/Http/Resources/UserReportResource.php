<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 마이페이지 「내 신고」 행 리소스 (ReportLog 기준)
 *
 * 신고자 본인에게만 보이는 화면이므로 **다른 신고자·처리자·관리자 메모·신고자 IP/UA 는
 * 싣지 않는다** (PO 결정 R10). 제목·내용은 신고 시점 스냅샷에서 읽어, 원본이 수정되거나
 * 삭제돼도 "내가 무엇을 신고했는지" 가 흔들리지 않게 한다.
 */
class UserReportResource extends BaseApiResource
{
    use FormatsBoardDate;

    /**
     * 본문 발췌 길이.
     */
    private const EXCERPT_LENGTH = 120;

    /**
     * 신고 로그를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $report = $this->report;
        $board = $report?->board;
        $snapshot = is_array($this->snapshot) ? $this->snapshot : [];
        $isComment = $report?->target_type?->value === 'comment';
        $status = $report?->status;

        $canCancel = $report !== null
            && ! $report->trashed()
            && $status === ReportStatus::Pending;

        return [
            // 로그 ID — 취소 엔드포인트가 받는 값
            'id' => (int) $this->id,
            'report_id' => $report === null ? null : (int) $report->id,

            'board' => $board === null ? null : [
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
            ],
            'board_slug' => $board?->slug,
            'board_name' => $board?->getLocalizedName(),

            'target_type' => $report?->target_type?->value,
            'target_type_label' => $report?->target_type?->label(),
            'target_id' => $report === null ? null : (int) $report->target_id,

            // 댓글 신고는 부모 글로 이동한다 (저장소가 미리 채워 둔 값).
            'post_id' => $isComment
                ? ($this->parent_post_id_preloaded === null ? null : (int) $this->parent_post_id_preloaded)
                : ($report === null ? null : (int) $report->target_id),
            'comment_id' => $isComment && $report !== null ? (int) $report->target_id : null,

            'title' => $snapshot['title'] ?? null,
            'excerpt' => $this->buildExcerpt($snapshot['content'] ?? null),

            'reason_type' => $this->reason_type?->value,
            'reason_type_label' => $this->reason_type?->label(),
            'reason_detail' => $this->reason_detail,

            'status' => $status?->value,
            'status_label' => $status?->label(),

            'reported_at' => $this->formatDateTimeStringForUser($this->created_at),

            'can_cancel' => $canCancel,
            'target_deleted' => $report === null || $report->trashed(),
        ];
    }

    /**
     * 스냅샷 본문의 발췌를 만듭니다.
     *
     * @param  string|null  $content  스냅샷 본문
     * @return string|null 발췌
     */
    private function buildExcerpt(?string $content): ?string
    {
        $text = trim(strip_tags((string) $content));

        return $text === '' ? null : mb_strimwidth($text, 0, self::EXCERPT_LENGTH, '…');
    }
}
