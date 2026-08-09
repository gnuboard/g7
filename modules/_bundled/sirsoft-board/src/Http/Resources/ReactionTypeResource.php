<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 반응 유형 API 리소스
 *
 * 반응 유형을 API 응답 형식으로 변환합니다. name 은 현재 로케일 문자열로
 * 내려주며, 미입력 언어는 getLocalizedName() 이 폴백 처리합니다 (이슈 #525 확정 15).
 */
class ReactionTypeResource extends BaseApiResource
{
    /**
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->getLocalizedName(),
            'icon' => $this->icon,
            'icon_name' => $this->getIconName(),
        ];
    }
}
