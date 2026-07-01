<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 대시보드 미처리 신고 리소스
 *
 * 관리자 대시보드 신고 관리 카드에 표시할 최소 필드만 노출합니다.
 */
class PendingReportResource extends BaseApiResource
{
    use FormatsBoardDate;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $reportable = $this->resource->reportableData ?? [];

        // 댓글 신고: 부모 게시글 제목 + 댓글 본문 발췌
        // 게시글 신고: 게시글 제목 (excerpt 는 없음)
        $isComment = $this->target_type?->value === 'comment';
        $title = $isComment ? ($reportable['post']['title'] ?? null) : ($reportable['title'] ?? null);
        $excerpt = $isComment ? $this->makeExcerpt($reportable['content'] ?? null) : null;
        $targetPostId = $isComment ? ($reportable['post']['id'] ?? null) : $this->target_id;

        return [
            'id' => $this->id,
            'board_slug' => $this->board?->slug,
            'board_name' => $this->board?->getLocalizedName(),
            'target_type' => $this->target_type?->value,
            'target_type_label' => $this->target_type_label,
            'target_post_id' => $targetPostId,
            'target_title' => $title,
            'target_excerpt' => $excerpt,
            'status' => $this->status?->value,
            'status_label' => $this->status_label,
            'author_name' => $this->author?->name,
            'last_reported_at' => $this->formatCreatedAtFormat(
                $this->last_reported_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
        ];
    }

    /**
     * HTML 을 제거하고 최대 60자 발췌 문자열을 만듭니다.
     *
     * @param  string|null  $content  원본 문자열 (HTML 가능)
     * @return string|null 평문 발췌 또는 null (빈 문자열/입력 null 시)
     */
    private function makeExcerpt(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }
        $plain = trim(strip_tags($content));

        return mb_strlen($plain) > 60 ? mb_substr($plain, 0, 60).'…' : ($plain !== '' ? $plain : null);
    }
}
