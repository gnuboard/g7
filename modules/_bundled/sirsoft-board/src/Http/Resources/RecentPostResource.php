<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
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
        return [
            'id' => $this->id,
            'board_slug' => $this->board?->slug,
            'board_name' => $this->board?->getLocalizedName(),
            'title' => $this->title,
            'author_name' => $this->user?->name ?? $this->author_name,
            'comments_count' => $this->comments_count,
            'created_at' => $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ),
        ];
    }
}
