<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 게시글 스크랩 리소스 (마이페이지 카드)
 *
 * 스크랩 카드에 필요한 필드만 평탄화해 반환한다: 제목·게시판명·작성자·댓글수·스크랩일·원글삭제여부.
 * 조회수는 확정 08 에 따라 제외한다. 원글이 소프트 삭제된 경우 post 관계가 null 이므로
 * is_deleted=true 로 프론트가 "삭제된 게시글" 카드를 표시하게 한다.
 */
class PostScrapResource extends BaseApiResource
{
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

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            // 스크랩 당시 게시판 slug (원글/게시판이 사라져도 목록 이동 링크에 사용)
            'board_slug' => $this->relationLoaded('board') && $this->board ? $this->board->slug : null,
            'board_name' => $this->relationLoaded('board') && $this->board ? $this->board->getLocalizedName() : null,
            // 원글 삭제 여부 — 카드 톤다운·"삭제된 게시글" 표기의 근거
            'is_deleted' => $isDeleted,
            // 삭제되지 않은 원글만 상세 정보 노출 (삭제글은 제목·작성자·댓글수 미노출)
            'title' => ! $isDeleted ? $post->title : null,
            'author_name' => ! $isDeleted ? $this->resolveAuthorName($post) : null,
            'comments_count' => ! $isDeleted ? (int) ($post->comments_count ?? 0) : null,
            // 스크랩일 (최근 스크랩순 정렬 기준이자 카드 표시 값)
            'scrapped_at' => $this->created_at ? $this->formatDateTimeStringForUser($this->created_at) : null,
        ];
    }

    /**
     * 원글 작성자명을 해석합니다 (회원 글은 회원명, 비회원 글은 작성자명).
     *
     * @param  mixed  $post  원글 모델 (삭제되지 않은 상태 보장)
     * @return string|null 작성자명
     */
    private function resolveAuthorName($post): ?string
    {
        if ($post->relationLoaded('user') && $post->user) {
            return $post->user->name;
        }

        return $post->author_name;
    }
}
