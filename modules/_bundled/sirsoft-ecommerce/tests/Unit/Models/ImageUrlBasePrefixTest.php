<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Support\AssetUrl;
use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이미지 서빙 폴백 URL 이 마운트 접두사를 갖는지 고정한다.
 *
 * `download_url` 은 `resolveDirectUrl() ?? apiDownloadUrl()` 이다. 앞쪽(직접 URL)은
 * 스토리지 드라이버가 만들어 접두사를 이미 갖지만, 뒤쪽 폴백은 손조립이라 갖지 않았다.
 * 그 폴백은 `disk`/`path` 가 빈 레거시 행과 `url` 미설정 디스크에서 발화하므로 「폴백이라
 * 드물다」가 아니다 — 그 사이트에서는 그것이 상시 경로다.
 *
 * 소비 지점은 레이아웃의 `Img.src` 이고 라우터를 거치지 않으므로, 접두사를 붙일 주체는
 * 이 조립 지점밖에 없다. 하위 폴더 배치에서 증상은 오류 없이 이미지가 깨지는 것이다.
 *
 * @scenario base_path=subpath, url_kind=ecommerce_image_fallback
 */
class ImageUrlBasePrefixTest extends ModuleTestCase
{
    /**
     * 강제로 지정한 마운트 접두사를 해제해 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 이미지 모델 3종(상품, 카테고리, 리뷰)과 /g7 마운트에서 기대하는 폴백 다운로드 주소를 묶는다.
     *
     * @return array<string, array{0: class-string, 1: string}> 모델 클래스와 기대 주소 케이스
     */
    public static function imageModels(): array
    {
        return [
            'product' => [ProductImage::class, '/g7/api/modules/sirsoft-ecommerce/product-image/h1'],
            'category' => [CategoryImage::class, '/g7/api/modules/sirsoft-ecommerce/category-image/h1'],
            'review' => [ProductReviewImage::class, '/g7/api/modules/sirsoft-ecommerce/review-image/h1'],
        ];
    }

    /**
     * /g7 마운트에서 직접 URL 을 만들 수 없는 레거시 형태의 이미지 행이 접두사가 붙은 폴백 다운로드 주소를 내는지 확인한다.
     *
     * @param  string  $model  검사할 이미지 모델 클래스
     * @param  string  $expected  기대하는 폴백 다운로드 주소
     *
     * @scenario base_path=subpath, url_kind=ecommerce_image_fallback
     * @effects ecommerce_image_fallback_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('imageModels')]
    public function image_fallback_urls_carry_the_mount_prefix(string $model, string $expected): void
    {
        AssetUrl::forceBasePrefix('/g7');

        /** @var Model $image */
        $image = new $model;
        $image->hash = 'h1';
        // 직접 URL 해석이 불가능한 상태 — 레거시 행과 같은 형태다.
        $image->disk = '';
        $image->path = '';

        $this->assertSame($expected, $image->download_url);
    }

    /**
     * 접두사가 없는 배치에서는 종전 문자열 그대로다 (회귀 0).
     *
     * @scenario base_path=root, url_kind=ecommerce_image_fallback
     * @effects ecommerce_image_fallback_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function image_fallback_urls_are_unchanged_without_a_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('');

        $image = new ProductImage;
        $image->hash = 'h1';
        $image->disk = '';
        $image->path = '';

        $this->assertSame(
            '/api/modules/sirsoft-ecommerce/product-image/h1',
            $image->download_url
        );
    }
}
