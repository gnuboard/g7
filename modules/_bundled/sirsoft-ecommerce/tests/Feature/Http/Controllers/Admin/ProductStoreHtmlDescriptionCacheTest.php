<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\InspectsHtmlPurifierCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * HTML 상세설명 상품 등록의 정의 캐시 경로 회귀 테스트 (공개 #125)
 *
 * 실제 등록 엔드포인트를 태워, 정의 캐시가 모듈 설치 폴더(vendor)가 아니라 `storage/` 아래에
 * 기록되는지와 두 번째 저장이 기존 캐시를 재사용하는지를 확인합니다.
 */
class ProductStoreHtmlDescriptionCacheTest extends ModuleTestCase
{
    use InspectsHtmlPurifierCache;

    private $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(
            class_exists(\HTMLPurifier::class),
            'HTMLPurifier 가 로드되지 않았습니다. 모듈 vendor 가 설치되어 있고 '
            .'tests/bootstrap.php 의 확장 vendor 오토로드 등록이 동작하는지 확인하세요.'
        );

        $defaultConfig = SequenceType::PRODUCT->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::PRODUCT->value],
            [
                'algorithm' => $defaultConfig['algorithm']->value,
                'prefix' => $defaultConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $defaultConfig['max_value'],
                'cycle' => false,
                'pad_length' => $defaultConfig['pad_length'],
                'max_history_count' => $defaultConfig['max_history_count'],
            ]
        );

        $this->user = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
        ]);

        $this->category = new Category([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'slug' => 'issue125-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $this->category->path = 'temp';
        $this->category->save();
        $this->category->generatePath();
        $this->category->save();

        File::deleteDirectory($this->purifierStorageBase());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->purifierStorageBase());

        parent::tearDown();
    }

    /**
     * HTML 상세설명 상품 등록 payload.
     *
     * @param  string  $productCode  상품코드
     * @return array 등록 요청 body
     */
    private function htmlProductData(string $productCode): array
    {
        return [
            'name' => ['ko' => 'ISSUE125 상품', 'en' => 'ISSUE125 Product'],
            'product_code' => $productCode,
            'category_ids' => [$this->category->id],
            'list_price' => 10000,
            'selling_price' => 9000,
            'stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description_mode' => 'html',
            'description' => ['ko' => '<p>설명</p><script>alert(1)</script>'],
            'options' => [
                [
                    'option_code' => $productCode.'-O1',
                    'option_name' => ['ko' => '기본옵션', 'en' => 'Default Option'],
                    'option_values' => [
                        ['key' => ['ko' => '색상'], 'value' => ['ko' => '빨강']],
                    ],
                    'list_price' => 10000,
                    'selling_price' => 9000,
                    'stock_quantity' => 10,
                ],
            ],
        ];
    }

    /**
     * HTML 상세설명 상품을 등록하면 정의 캐시가 storage 아래에 기록됩니다.
     *
     * 거짓 green 경고: 개발 머신 vendor 에는 이미 같은 이름의 `.ser` 가 있어 "vendor 스냅샷
     * 불변" 단언은 수정 전에도 통과할 수 있다. 결함을 증명하는 것은 storage 스냅샷 단언이다.
     *
     * @scenario description_mode=html, cache_dir=writable
     *
     * @effects definition_cache_written_under_storage, vendor_install_directory_never_written
     */
    public function test_store_with_html_description_writes_cache_under_storage(): void
    {
        $vendorBefore = $this->serSnapshot($this->vendorSerializerBase());

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->htmlProductData('ISSUE125-001'));

        $response->assertCreated();

        $this->assertNotEmpty(
            $this->serSnapshot($this->purifierStorageBase()),
            'storage 아래에 정의 캐시가 기록되지 않았습니다.'
        );
        $this->assertSame(
            $vendorBefore,
            $this->serSnapshot($this->vendorSerializerBase()),
            'HTMLPurifier 설치 폴더에 정의 캐시가 기록되었습니다.'
        );

        $description = $response->json('data.description');
        $this->assertStringNotContainsString('<script', is_array($description) ? ($description['ko'] ?? '') : (string) $description);
    }

    /**
     * 두 번째 저장은 이미 기록된 정의 캐시를 재사용합니다 (재기록하지 않음).
     *
     * @scenario description_mode=html, cache_dir=already_populated
     *
     * @effects second_save_reuses_cached_definition
     */
    public function test_second_store_reuses_existing_definition_cache(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->htmlProductData('ISSUE125-002'))
            ->assertCreated();

        $snapshot = $this->serSnapshot($this->purifierStorageBase());
        $this->assertNotEmpty($snapshot);

        // mtime 해상도가 1초라 같은 초 안의 재기록이 감지되지 않는다 — 과거로 밀어 baseline 확보.
        foreach (array_keys($snapshot) as $path) {
            touch($path, time() - 3600);
        }
        $baseline = $this->serSnapshot($this->purifierStorageBase());

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $this->htmlProductData('ISSUE125-003'))
            ->assertCreated();

        $this->assertSame(
            $baseline,
            $this->serSnapshot($this->purifierStorageBase()),
            '두 번째 저장이 정의 캐시를 다시 기록했습니다 (재사용되지 않음).'
        );
    }
}
