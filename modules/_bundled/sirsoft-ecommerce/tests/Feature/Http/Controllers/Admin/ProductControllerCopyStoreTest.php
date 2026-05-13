<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Contracts\Extension\StorageInterface;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 복사 모드 등록 통합 테스트
 *
 * 복사 모드에서 이미지(hash 기반) 처리를 검증합니다.
 * - 복사 이미지 유지/삭제/추가/순서변경 시나리오
 */
class ProductControllerCopyStoreTest extends ModuleTestCase
{
    private $user;

    private Category $category;

    private Product $sourceProduct;

    private string $sourceProductCode;

    protected function setUp(): void
    {
        parent::setUp();

        // StorageInterface mock — 파일 복사 시 실제 파일시스템 접근 방지
        // contextual binding을 오버라이드하기 위해 when()->needs()->give() 사용
        $storageMock = \Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('get')->andReturn('fake-file-content');
        $storageMock->shouldReceive('put')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('deleteDirectory')->andReturn(true);
        $storageMock->shouldReceive('exists')->andReturn(true);

        $storageServices = [
            \Modules\Sirsoft\Ecommerce\Services\ProductImageService::class,
            \Modules\Sirsoft\Ecommerce\Services\CategoryImageService::class,
            \Modules\Sirsoft\Ecommerce\Services\ProductReviewService::class,
            \Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService::class,
        ];
        $this->app->when($storageServices)
            ->needs(StorageInterface::class)
            ->give(fn () => $storageMock);

        // 상품 시퀀스 레코드 생성
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

        // 관리자 유저
        $this->user = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
        ]);

        // 카테고리
        $this->category = new Category([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'slug' => 'test-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $this->category->path = 'temp';
        $this->category->save();
        $this->category->generatePath();
        $this->category->save();

        // 원본 상품 + 이미지 3개
        $this->sourceProduct = Product::factory()->create();
        $this->sourceProductCode = $this->sourceProduct->product_code;

        for ($i = 0; $i < 3; $i++) {
            ProductImage::create([
                'product_id' => $this->sourceProduct->id,
                'original_filename' => "image_{$i}.jpg",
                'stored_filename' => "uuid_{$i}.jpg",
                'disk' => 'local',
                'path' => "products/{$this->sourceProductCode}/uuid_{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => 1024 * ($i + 1),
                'width' => 800,
                'height' => 600,
                'collection' => 'main',
                'is_thumbnail' => $i === 0,
                'sort_order' => $i,
                'created_by' => $this->user->id,
            ]);
        }
    }

    /**
     * 기본 상품 등록 데이터 생성
     */
    private function baseProductData(string $productCode = 'COPY-001'): array
    {
        return [
            'name' => ['ko' => '복사 상품', 'en' => 'Copy Product'],
            'product_code' => $productCode,
            'category_ids' => [$this->category->id],
            'list_price' => 10000,
            'selling_price' => 8000,
            'stock_quantity' => 100,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'options' => [
                [
                    'option_code' => 'OPT-001',
                    'option_name' => ['ko' => '기본 옵션', 'en' => 'Default Option'],
                    'option_values' => [
                        ['key' => ['ko' => '기본', 'en' => 'Default'], 'value' => ['ko' => '기본', 'en' => 'Default']],
                    ],
                    'list_price' => 10000,
                    'selling_price' => 8000,
                    'stock_quantity' => 100,
                ],
            ],
        ];
    }

    /**
     * 원본 이미지 hash 목록 반환
     */
    private function getSourceImageHashes(): array
    {
        return $this->sourceProduct->images()
            ->orderBy('sort_order')
            ->pluck('hash')
            ->toArray();
    }

    // ========================================
    // 1. 복사 모드 그대로 등록 (이미지 3개 모두 유지)
    // ========================================

    public function test_copy_store_keeps_all_images(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-001');
        $data['images'] = [
            ['hash' => $hashes[0], 'is_thumbnail' => true, 'sort_order' => 0],
            ['hash' => $hashes[1], 'is_thumbnail' => false, 'sort_order' => 1],
            ['hash' => $hashes[2], 'is_thumbnail' => false, 'sort_order' => 2],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-001')->first();
        $this->assertNotNull($newProduct);
        $this->assertEquals(3, $newProduct->images()->count());
        $this->assertEquals(1, $newProduct->images()->where('is_thumbnail', true)->count());
    }

    // ========================================
    // 2. 파일 하나 삭제 후 등록
    // ========================================

    public function test_copy_store_removes_one_image(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-002');
        // 첫 번째 이미지만 제외
        $data['images'] = [
            ['hash' => $hashes[1], 'is_thumbnail' => true, 'sort_order' => 0],
            ['hash' => $hashes[2], 'is_thumbnail' => false, 'sort_order' => 1],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-002')->first();
        $this->assertEquals(2, $newProduct->images()->count());
    }

    // ========================================
    // 3. 파일 하나 이상 삭제 후 등록
    // ========================================

    public function test_copy_store_removes_multiple_images(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-003');
        // 첫 번째 이미지만 남김
        $data['images'] = [
            ['hash' => $hashes[0], 'is_thumbnail' => true, 'sort_order' => 0],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-003')->first();
        $this->assertEquals(1, $newProduct->images()->count());
    }

    // ========================================
    // 4. 파일 하나 삭제 + 새 파일 추가 (temp_key 기반)
    // ========================================

    public function test_copy_store_removes_one_and_adds_new(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-004');
        // 첫 번째 이미지 제거, 나머지 유지
        $data['images'] = [
            ['hash' => $hashes[1], 'is_thumbnail' => true, 'sort_order' => 0],
            ['hash' => $hashes[2], 'is_thumbnail' => false, 'sort_order' => 1],
        ];
        // 새 이미지는 temp_key로 업로드된 것을 linkTempImages로 연결
        $data['image_temp_key'] = 'temp-copy-004';

        // temp_key에 해당하는 임시 이미지를 미리 생성
        // 실제 uploadImage() 엔드포인트 동작 재현: sort_order=1(자동배정), is_thumbnail=true(첫 번째 temp)
        ProductImage::create([
            'product_id' => null,
            'temp_key' => 'temp-copy-004',
            'original_filename' => 'new_image.jpg',
            'stored_filename' => 'new_uuid.jpg',
            'disk' => 'local',
            'path' => 'products/temp/temp-copy-004/new_uuid.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'width' => 1024,
            'height' => 768,
            'collection' => 'main',
            'is_thumbnail' => true,  // uploadImage()가 첫 번째 temp에 true 할당
            'sort_order' => 1,       // uploadImage()가 maxSortOrder+1 할당
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-004')->first();
        // 복사 이미지 2개 + 새 이미지 1개 = 3개
        $this->assertEquals(3, $newProduct->images()->count());

        // linkTempImages()가 sort_order를 기존 이미지 뒤로 재배치, is_thumbnail을 false로 보장
        $images = $newProduct->images()->orderBy('sort_order')->get();
        $this->assertEquals('new_image.jpg', $images->last()->original_filename);
        $this->assertEquals(2, $images->last()->sort_order); // 기존 max(1) + 1
        $this->assertFalse($images->last()->is_thumbnail);   // 복사 이미지의 thumbnail 유지
        $this->assertEquals(1, $newProduct->images()->where('is_thumbnail', true)->count());
    }

    // ========================================
    // 5. 파일 하나 이상 삭제 + 새 파일 하나 이상 추가
    // ========================================

    public function test_copy_store_removes_multiple_and_adds_multiple(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-005');
        // 이미지 하나만 유지
        $data['images'] = [
            ['hash' => $hashes[0], 'is_thumbnail' => true, 'sort_order' => 0],
        ];
        $data['image_temp_key'] = 'temp-copy-005';

        // 새 이미지 2개 — 실제 uploadImage() 동작 재현: 첫 번째만 is_thumbnail=true
        for ($i = 0; $i < 2; $i++) {
            ProductImage::create([
                'product_id' => null,
                'temp_key' => 'temp-copy-005',
                'original_filename' => "new_{$i}.jpg",
                'stored_filename' => "new_uuid_{$i}.jpg",
                'disk' => 'local',
                'path' => "products/temp/temp-copy-005/new_uuid_{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size' => 1024,
                'width' => 640,
                'height' => 480,
                'collection' => 'main',
                'is_thumbnail' => $i === 0,  // 첫 번째 temp에 true (실제 동작)
                'sort_order' => $i + 1,
                'created_by' => $this->user->id,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-005')->first();
        // 복사 1개 + 새 2개 = 3개
        $this->assertEquals(3, $newProduct->images()->count());

        // linkTempImages()가 is_thumbnail을 false로 재설정, sort_order를 기존 이미지 뒤로 재배치
        $images = $newProduct->images()->orderBy('sort_order')->get();
        // 복사 이미지(sort_order 0)만 thumbnail 유지
        $this->assertEquals(1, $newProduct->images()->where('is_thumbnail', true)->count());
        $this->assertTrue($images[0]->is_thumbnail);
        // 새 이미지들은 기존 이미지(sort_order 0) 뒤에 배치
        $this->assertEquals(1, $images[1]->sort_order);
        $this->assertEquals(2, $images[2]->sort_order);
        $this->assertFalse($images[1]->is_thumbnail);
        $this->assertFalse($images[2]->is_thumbnail);
    }

    // ========================================
    // 6. 파일 삭제 + 순서 변경 후 등록 — 순서 검증
    // ========================================

    public function test_copy_store_removes_and_reorders(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-006');
        // 첫 번째 이미지 삭제, 나머지 순서 역전
        $data['images'] = [
            ['hash' => $hashes[2], 'is_thumbnail' => true, 'sort_order' => 0],
            ['hash' => $hashes[1], 'is_thumbnail' => false, 'sort_order' => 1],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-006')->first();
        $images = $newProduct->images()->orderBy('sort_order')->get();

        $this->assertEquals(2, $images->count());
        // 순서 검증: sort_order 0 = 원본의 세 번째 이미지 (image_2.jpg)
        $this->assertEquals('image_2.jpg', $images[0]->original_filename);
        $this->assertTrue($images[0]->is_thumbnail);
        // sort_order 1 = 원본의 두 번째 이미지 (image_1.jpg)
        $this->assertEquals('image_1.jpg', $images[1]->original_filename);
        $this->assertFalse($images[1]->is_thumbnail);
    }

    // ========================================
    // 7. 파일 삭제 + 새 파일 추가 + 순서 뒤섞기 — 순서 검증
    // ========================================

    public function test_copy_store_removes_adds_and_shuffles_order(): void
    {
        $hashes = $this->getSourceImageHashes();
        $data = $this->baseProductData('COPY-007');
        // 두 번째 이미지 삭제, 세 번째를 첫째로, 첫째를 셋째로
        $data['images'] = [
            ['hash' => $hashes[2], 'is_thumbnail' => true, 'sort_order' => 0],
            ['hash' => $hashes[0], 'is_thumbnail' => false, 'sort_order' => 2],
        ];
        $data['image_temp_key'] = 'temp-copy-007';

        // 새 이미지 — 실제 uploadImage() 동작 재현: sort_order=1, is_thumbnail=true(첫 번째 temp)
        ProductImage::create([
            'product_id' => null,
            'temp_key' => 'temp-copy-007',
            'original_filename' => 'new_appended.jpg',
            'stored_filename' => 'new_appended_uuid.jpg',
            'disk' => 'local',
            'path' => 'products/temp/temp-copy-007/new_appended_uuid.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 3072,
            'width' => 1200,
            'height' => 900,
            'collection' => 'main',
            'is_thumbnail' => true,  // 첫 번째 temp에 true (실제 동작)
            'sort_order' => 1,       // uploadImage()가 부여한 값 (linkTempImages가 재배치)
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();

        $newProduct = Product::where('product_code', 'COPY-007')->first();
        $images = $newProduct->images()->orderBy('sort_order')->get();

        $this->assertEquals(3, $images->count());

        // sort_order 0 = 원본 세 번째 (image_2.jpg) — 복사 이미지, thumbnail
        $this->assertEquals('image_2.jpg', $images[0]->original_filename);
        $this->assertTrue($images[0]->is_thumbnail);

        // sort_order 2 = 원본 첫 번째 (image_0.jpg) — 복사 이미지
        $this->assertEquals('image_0.jpg', $images[1]->original_filename);
        $this->assertFalse($images[1]->is_thumbnail);

        // sort_order 3 = 새 이미지 (new_appended.jpg) — linkTempImages()가 기존 max(2) + 1로 재배치, is_thumbnail=false
        $this->assertEquals('new_appended.jpg', $images[2]->original_filename);
        $this->assertEquals(3, $images[2]->sort_order);
        $this->assertFalse($images[2]->is_thumbnail);

        // thumbnail은 복사 이미지에서 지정한 것 1개만 존재
        $this->assertEquals(1, $newProduct->images()->where('is_thumbnail', true)->count());
    }
}
