<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ProductSeeder;
use Tests\TestCase;

/**
 * ProductSeeder 이미지 색상 필터 테스트
 *
 * applyColorFilter 메서드의 GD 기반 색상 필터 로직을 검증합니다.
 */
class ProductSeederImageTest extends TestCase
{
    private ProductSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD 확장이 필요합니다.');
        }

        $this->seeder = new ProductSeeder;
    }

    /**
     * 유효한 JPEG 이미지에 필터를 적용하면 유효한 JPEG을 반환합니다.
     */
    public function test_apply_color_filter_returns_valid_jpeg(): void
    {
        $original = $this->createTestJpeg(100, 100);

        $filtered = $this->seeder->applyColorFilter($original);

        // 결과가 비어있지 않아야 함
        $this->assertNotEmpty($filtered);

        // JPEG 시그니처 확인 (FF D8 FF)
        $this->assertEquals("\xFF\xD8\xFF", substr($filtered, 0, 3));
    }

    /**
     * 필터 적용 후 이미지가 원본과 다릅니다.
     */
    public function test_apply_color_filter_modifies_image(): void
    {
        $original = $this->createTestJpeg(100, 100);

        // 여러 번 시도하여 최소 한 번은 다른 결과가 나와야 함
        $isDifferent = false;
        for ($i = 0; $i < 10; $i++) {
            $filtered = $this->seeder->applyColorFilter($original);
            if ($filtered !== $original) {
                $isDifferent = true;
                break;
            }
        }

        $this->assertTrue($isDifferent, '필터 적용 후 이미지가 원본과 달라야 합니다.');
    }

    /**
     * 잘못된 이미지 데이터를 전달하면 원본을 그대로 반환합니다.
     */
    public function test_apply_color_filter_returns_original_for_invalid_image(): void
    {
        $invalidData = 'not-an-image-data';

        $result = $this->seeder->applyColorFilter($invalidData);

        $this->assertEquals($invalidData, $result);
    }

    /**
     * 빈 문자열을 전달하면 원본을 그대로 반환합니다.
     */
    public function test_apply_color_filter_returns_original_for_empty_string(): void
    {
        $result = $this->seeder->applyColorFilter('');

        $this->assertEquals('', $result);
    }

    /**
     * 필터 적용 결과가 imagecreatefromstring으로 다시 파싱 가능합니다.
     */
    public function test_filtered_image_is_parseable(): void
    {
        $original = $this->createTestJpeg(200, 150);

        $filtered = $this->seeder->applyColorFilter($original);

        $image = @imagecreatefromstring($filtered);
        $this->assertNotFalse($image, '필터링된 이미지가 GD로 파싱 가능해야 합니다.');

        // 이미지 크기 유지 확인
        $this->assertEquals(200, imagesx($image));
        $this->assertEquals(150, imagesy($image));

        imagedestroy($image);
    }

    /**
     * generateUniqueHashInMemory가 고유한 해시를 생성합니다.
     */
    public function test_generate_unique_hash_in_memory_produces_unique_hashes(): void
    {
        $seeder = new ProductSeeder;
        $hashes = [];
        $count = 1000;

        for ($i = 0; $i < $count; $i++) {
            $hashes[] = $seeder->generateUniqueHashInMemory();
        }

        // 모든 해시가 고유해야 함
        $uniqueHashes = array_unique($hashes);
        $this->assertCount($count, $uniqueHashes, "{$count}개의 해시가 모두 고유해야 합니다.");

        // 해시 형식 확인 (12자리 16진수)
        foreach (array_slice($hashes, 0, 10) as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hash);
        }
    }

    /**
     * leafCategories N+1 방지를 위한 in-memory 필터링 로직을 검증합니다.
     */
    public function test_leaf_categories_filter_without_query(): void
    {
        // 카테고리 구조 시뮬레이션
        $categories = collect([
            (object) ['id' => 1, 'parent_id' => null],   // root (부모)
            (object) ['id' => 2, 'parent_id' => 1],      // child (부모)
            (object) ['id' => 3, 'parent_id' => 2],      // leaf
            (object) ['id' => 4, 'parent_id' => 2],      // leaf
            (object) ['id' => 5, 'parent_id' => null],   // root (leaf - 자식 없음)
        ]);

        // in-memory 필터링 (ProductSeeder에서 사용하는 동일한 로직)
        $parentIds = $categories->pluck('parent_id')->filter()->unique();
        $leafCategories = $categories->reject(fn ($cat) => $parentIds->contains($cat->id));

        // leaf 카테고리: id=3, 4, 5 (다른 카테고리의 parent_id로 참조되지 않는 것)
        $leafIds = $leafCategories->pluck('id')->sort()->values()->toArray();
        $this->assertEquals([3, 4, 5], $leafIds);
    }

    /**
     * 상품 SKU가 카테고리 접두사 + 순번 형식으로 생성됩니다.
     */
    public function test_product_sku_uses_category_prefix_format(): void
    {
        // 템플릿의 product_code에서 접두사를 추출하여 SKU를 생성하는 로직 검증
        $templates = [
            ['product_code' => 'TS-001', 'index' => 1, 'expected' => 'TS-0001'],
            ['product_code' => 'BG-001', 'index' => 12, 'expected' => 'BG-0012'],
            ['product_code' => 'EP-001', 'index' => 100, 'expected' => 'EP-0100'],
            ['product_code' => 'SK-001', 'index' => 5, 'expected' => 'SK-0005'],
        ];

        foreach ($templates as $template) {
            $prefix = explode('-', $template['product_code'])[0];
            $sku = sprintf('%s-%04d', $prefix, $template['index']);
            $this->assertEquals($template['expected'], $sku, "product_code '{$template['product_code']}' + index {$template['index']}");
        }
    }

    /**
     * 옵션 SKU가 상품SKU + 옵션값 약어 형식으로 생성됩니다.
     */
    public function test_option_sku_generation(): void
    {
        $seeder = new ProductSeeder;
        $method = new \ReflectionMethod($seeder, 'generateOptionSku');

        // 단일 옵션 (색상만)
        $combination = [
            0 => ['ko' => '화이트', 'en' => 'White'],
        ];
        $result = $method->invoke($seeder, 'TS-0001', $combination);
        $this->assertEquals('TS-0001-WHT', $result);

        // 복수 옵션 (색상 + 사이즈)
        $combination = [
            0 => ['ko' => '블랙', 'en' => 'Black'],
            1 => ['ko' => 'M', 'en' => 'M'],
        ];
        $result = $method->invoke($seeder, 'TS-0001', $combination);
        $this->assertEquals('TS-0001-BLK-M', $result);

        // 숫자+단위 옵션 (용량)
        $combination = [
            0 => ['ko' => '350ml', 'en' => '350ml'],
            1 => ['ko' => '실버', 'en' => 'Silver'],
        ];
        $result = $method->invoke($seeder, 'TB-0005', $combination);
        $this->assertEquals('TB-0005-350ML-SLV', $result);
    }

    /**
     * 옵션값 약어가 올바르게 생성됩니다.
     */
    public function test_abbreviate_option_value(): void
    {
        $seeder = new ProductSeeder;
        $method = new \ReflectionMethod($seeder, 'abbreviateOptionValue');

        // 매핑된 색상
        $this->assertEquals('WHT', $method->invoke($seeder, 'White'));
        $this->assertEquals('BLK', $method->invoke($seeder, 'Black'));
        $this->assertEquals('NVY', $method->invoke($seeder, 'Navy'));
        $this->assertEquals('GRY', $method->invoke($seeder, 'Gray'));
        $this->assertEquals('BLU', $method->invoke($seeder, 'Blue'));
        $this->assertEquals('RED', $method->invoke($seeder, 'Red'));
        $this->assertEquals('PNK', $method->invoke($seeder, 'Pink'));
        $this->assertEquals('BRN', $method->invoke($seeder, 'Brown'));
        $this->assertEquals('RSG', $method->invoke($seeder, 'Rose Gold'));

        // 사이즈
        $this->assertEquals('S', $method->invoke($seeder, 'S'));
        $this->assertEquals('M', $method->invoke($seeder, 'M'));
        $this->assertEquals('L', $method->invoke($seeder, 'L'));
        $this->assertEquals('XL', $method->invoke($seeder, 'XL'));

        // 숫자+단위
        $this->assertEquals('350ML', $method->invoke($seeder, '350ml'));
        $this->assertEquals('1M', $method->invoke($seeder, '1m'));
        $this->assertEquals('256GB', $method->invoke($seeder, '256GB'));

        // 기본
        $this->assertEquals('STD', $method->invoke($seeder, 'Default'));

        // 매핑에 없는 값 (처음 3자)
        $this->assertEquals('CUS', $method->invoke($seeder, 'Custom'));
    }

    /**
     * 테스트용 JPEG 이미지를 생성합니다.
     *
     * @param  int  $width  이미지 너비
     * @param  int  $height  이미지 높이
     * @return string JPEG 바이너리
     */
    private function createTestJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 100, 150, 200);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}
