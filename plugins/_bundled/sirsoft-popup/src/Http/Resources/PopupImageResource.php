<?php

namespace Plugins\Sirsoft\Popup\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Popup\Models\PopupImage;

/**
 * 팝업 이미지 응답 — 업로드 응답과 팝업 상세·목록의 이미지가 모두 이 모양을 쓴다(저장 경로는 싣지 않는다).
 *
 * 관리자 이미지 업로더(`file-uploader`)가 기대하는 첨부 모양(id·hash·original_filename·mime_type·size·
 * size_formatted·download_url·order·is_image)에 미리보기·권장 크기 안내용 url·width·height·device 를 더한다.
 *
 * 주소 필드(#878): `url`·`preview_url` = 공개 API 주소(썸네일 `?size=` 기준, 같은 출처), `direct_url` = 행 disk 의 직접
 * 주소(공개 자산 디스크가 아니면 null), `download_url` = 원본 한 장(직접 주소가 있으면 그것, 없으면 API).
 *
 * @mixin PopupImage
 */
class PopupImageResource extends BaseApiResource
{
    /**
     * 이미지 응답을 반환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 이미지 정보
     */
    public function toArray(Request $request): array
    {
        $url = $this->resource->publicUrl();
        $direct = $this->resource->directUrl();

        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'device' => $this->device,
            'url' => $url,
            'preview_url' => $url,
            'direct_url' => $direct,
            'download_url' => $direct ?? $url,
            'original_filename' => $this->original_name,
            'mime_type' => $this->mime,
            'size' => $this->size,
            'size_formatted' => $this->formatFileSize($this->size),
            'width' => $this->width,
            'height' => $this->height,
            'order' => 0,
            'is_image' => true,
        ];
    }
}
