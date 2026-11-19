<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 대시보드 최신 게시글 리소스
 *
 * 관리자 대시보드 최신글 카드에 표시할 최소 필드만 노출합니다.
 */
class RecentPostResource extends BaseApiResource
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
        $displayNames = app(BoardDisplayNames::class);
        $authorName = $displayNames->authorOf($this->resource, $displayNames->areaFor($request));
        $realName = $displayNames->realName($this->user_id ? $this->user : null, $authorName, $request);

        return [
            'id' => $this->id,
            'board_slug' => $this->board?->slug,
            'board_name' => $this->board?->getLocalizedName(),
            'title' => $this->title,
            // 회원 글은 관리자 화면의 표시 이름, 비회원 글은 입력한 이름. 실명은 회원 조회 권한이 있고 표시 이름과 다를 때만 더한다.
            'author_name' => $authorName,
            ...($realName !== null ? ['real_name' => $realName] : []),
            'comments_count' => $this->comments_count,
            'created_at' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
        ];
    }
}
