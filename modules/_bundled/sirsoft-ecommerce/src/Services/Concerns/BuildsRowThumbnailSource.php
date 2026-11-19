<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Model;

/**
 * 이미지 행 → 썸네일 원본 trait (서비스 공용)
 *
 * 상품·카테고리·리뷰·클레임 이미지는 모두 모듈 스토리지 `images` 카테고리에 저장되고 행에 disk·path·mime_type·
 * width·height 를 둔다. 썸네일 원본의 경로는 **드라이버가 디스크 위에 실제로 쓴 전체 경로**
 * (`sirsoft-ecommerce/images/{행 path}`)여야 파생본 키와 저장소 계층 무효화(원본 삭제·덮어쓰기)가 같은 값을
 * 본다 — 그래서 경로를 여기서 조립하지 않고 행 disk 로 맞춘 드라이버(`storageForRow`)에게 묻는다.
 *
 * 사용 서비스는 `ResolvesRowStorage` 를 함께 쓴다.
 */
trait BuildsRowThumbnailSource
{
    /**
     * 이미지 행의 썸네일 원본을 만듭니다.
     *
     * @param  Model  $image  이미지 행 (disk·path·mime_type·width·height 보유)
     * @param  bool  $gated  권한이 걸린 원본인지 (true 면 파생본은 비공개 디스크·`private` 캐시)
     * @param  int|null  $maxAge  썸네일 캐시 유지 상한(초) — 원본 통로가 더 짧게 캐시하면 그 값 (null 이면 코어 기본)
     * @return ThumbnailSource|null 원본 (래스터가 아니거나 드라이버가 원본 정보를 낼 수 없으면 null)
     */
    protected function rowThumbnailSource(Model $image, bool $gated, ?int $maxAge = null): ?ThumbnailSource
    {
        if (! InlineMediaPolicy::isInlineImage($image->mime_type)) {
            return null;
        }

        return ThumbnailSource::fromStorage(
            $this->storageForRow($image->disk),
            'images',
            (string) $image->path,
            (string) $image->mime_type,
            $gated,
            $image->width !== null ? (int) $image->width : null,
            $image->height !== null ? (int) $image->height : null,
            $maxAge,
        );
    }
}
