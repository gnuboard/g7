<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use Modules\Sirsoft\Board\Traits\ResolvesScrapAuthorName;

/**
 * 게시글 스크랩 리소스 (마이페이지 카드)
 *
 * 스크랩 카드에 필요한 필드만 평탄화해 반환한다: 제목·게시판명·작성자·댓글수·스크랩일·원글삭제여부.
 * 조회수는 확정 08 에 따라 제외한다. 원글이 소프트 삭제된 경우 post 관계가 null 이므로
 * is_deleted=true 로 프론트가 "삭제된 게시글" 카드를 표시하게 한다.
 * author_name 은 삭제글도 노출한다 — "어떤 글이 삭제됐는지" 맥락(게시판·작성자)을 유지하기 위함.
 * title·comments_count 는 원글 내용 자체이므로 삭제글에서는 계속 미노출한다.
 * scrapped_at 표시는 PostResource·CommentResource와 동일하게 FormatsBoardDate 를 공유해
 * 게시판 설정(display.date_display_format)과 다국어를 그대로 따른다 — 프론트 파이프로 별도 구현하지 않는다.
 * scrap_enabled=false 는 스크랩이 중지된 게시판(비활성 또는 use_scrap=false)의 스크랩이라는 뜻이다.
 * 행을 목록에서 지우지 않고 화면이 「스크랩 중지」 배지 + 해제만 허용하도록 구분할 근거를 낸다.
 */
class PostScrapResource extends BaseApiResource
{
    use FormatsBoardDate;
    use ResolvesScrapAuthorName;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 스크랩 카드 배열
     */
    public function toArray(Request $request): array
    {
        $post = $this->relationLoaded('post') ? $this->post : null;
        $isDeleted = $post === null || $post->deleted_at !== null;
        $board = $this->relationLoaded('board') ? $this->board : null;

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            // 스크랩 당시 게시판 slug (원글/게시판이 사라져도 목록 이동 링크에 사용)
            'board_slug' => $board?->slug,
            'board_name' => $board?->getLocalizedName(),
            // 원글 삭제 여부 — 카드 톤다운·"삭제된 게시글" 표기의 근거
            'is_deleted' => $isDeleted,
            // 스크랩 기능이 살아 있는 게시판인지 — false 면 화면이 「스크랩 중지」 분기로 간다
            // (게시판 비활성도 같은 분기: 원글로 이동할 수 없는 상태라는 점에서 동일하다)
            'scrap_enabled' => $board !== null && (bool) $board->is_active && (bool) $board->use_scrap,
            // 게시판 자체가 하드 삭제된 스크랩 (board_id NULL) — 「게시판 삭제됨」 배지의 근거
            'board_deleted' => $board === null,
            // 삭제되지 않은 원글만 상세 정보 노출 (삭제글은 제목·댓글수 미노출)
            'title' => ! $isDeleted ? $post->title : null,
            'author_name' => $post !== null ? $this->resolveAuthorName($post) : null,
            'comments_count' => ! $isDeleted ? (int) ($post->comments_count ?? 0) : null,
            // 스크랩일 (최근 스크랩순 정렬 기준이자 카드 표시 값) — PostResource·CommentResource와 동일한
            // 게시판 표시 설정·다국어를 따른다 (예: "3시간 전" / "3 hours ago").
            'scrapped_at' => $this->created_at ? $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ) : null,
        ];
    }
}
