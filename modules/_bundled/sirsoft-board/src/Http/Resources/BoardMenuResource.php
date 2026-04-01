<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 게시판 네비게이션 메뉴용 경량 리소스
 *
 * 네비게이션 메뉴에 필요한 최소한의 필드만 반환합니다.
 * - id: 게시판 식별자
 * - name: 다국어 처리된 게시판 이름
 * - slug: 게시판 URL 슬러그
 */
class BoardMenuResource extends BaseApiResource
{
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
            'name' => $this->getLocalizedName(),
            'slug' => $this->slug,
        ];
    }
}
