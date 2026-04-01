<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 상품 이미지 리소스
 */
class ProductImageResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'original_filename' => $this->original_filename,
            'download_url' => $this->download_url,
            'alt_text' => $this->alt_text,
            'alt_text_localized' => $this->alt_text_localized,
            'alt_text_current' => $this->getLocalizedAltText(),
            'is_thumbnail' => $this->is_thumbnail,
            'sort_order' => $this->sort_order,
            'order' => $this->sort_order, // FileUploader 호환
            'width' => $this->width,
            'height' => $this->height,
            'file_size' => $this->file_size,
            'size' => $this->file_size, // FileUploader 호환
            'size_formatted' => $this->formatFileSize($this->file_size),
            'mime_type' => $this->mime_type,
            'is_image' => str_starts_with($this->mime_type ?? '', 'image/'),
        ];
    }

    /**
     * 파일 크기를 읽기 쉬운 형식으로 변환
     *
     * @param  int|null  $bytes  바이트 크기
     * @return string
     */
    protected function formatFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
