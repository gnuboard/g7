<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\StoreInquiryRequest;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 임시 이미지 키 경로 순회 방어 테스트 (KVE-2026-2349 부수의무)
 *
 * 상품 이미지는 임시 키를 `products/temp/{temp_key}` 저장 경로에 넣고, 상품 저장 시
 * 그 임시 디렉토리를 지운다. 게시판과 같은 패턴이라 순회 키가 모듈 저장소 루트를 지울 수
 * 있었다. 요청 계층이 UUID 가 아닌 키를 422 로 거부하는지, 임시 이미지가 없으면 정리하지
 * 않는지, 정상 흐름(UUID 키 업로드 → 연결)은 그대로인지 확인한다.
 */
class TempKeyPathSafetyTest extends ModuleTestCase
{
    /**
     * 경로 순회·인코딩 임시 키 변종
     *
     * @var array<int, string>
     */
    private const TRAVERSAL_KEYS = ['../../../..', '..%2f..', 'a/../../../../..', '\\', '.'];

    /**
     * 상품·분류 관리 권한 관리자
     */
    private User $admin;

    /**
     * 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
            'sirsoft-ecommerce.categories.read',
            'sirsoft-ecommerce.categories.create',
            'sirsoft-ecommerce.categories.update',
        ]);
    }

    /**
     * 상품·분류 저장과 공개 문의의 순회 임시 키는 검증 오류로 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=product_store,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422
     */
    public function test_store_requests_reject_traversal_temp_keys(): void
    {
        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-ecommerce/admin/products', ['image_temp_key' => $tempKey])
                ->assertStatus(422)
                ->assertJsonValidationErrors('image_temp_key');

            $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-ecommerce/admin/categories', ['temp_key' => $tempKey])
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');

            // 공개 문의는 임시 이미지를 잇지 않아 저장 경로에 닿지 않지만 같은 형식만 받는다
            $validator = Validator::make(['content' => '문의', 'temp_key' => $tempKey], (new StoreInquiryRequest)->rules());
            $this->assertTrue($validator->errors()->has('temp_key'), $tempKey);
        }
    }

    /**
     * 분류 수정 요청의 순회 임시 키도 검증 오류로 거부된다 (수정도 임시 이미지를 잇는다).
     *
     * @return void 반환값 없음
     *
     * @effects traversal_temp_key_rejected_with_422
     */
    public function test_category_update_rejects_traversal_temp_keys(): void
    {
        $category = new Category([
            'name' => ['ko' => '임시 키 분류', 'en' => 'Temp key category'],
            'slug' => 'temp-key-guard-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $category->path = 'temp';
        $category->save();
        $category->generatePath();
        $category->save();

        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->admin)
                ->putJson("/api/modules/sirsoft-ecommerce/admin/categories/{$category->id}", ['temp_key' => $tempKey])
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');
        }
    }

    /**
     * 상품·분류 이미지 업로드의 순회 임시 키는 422 로 거부되고 이미지가 만들어지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=image_upload,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422
     */
    public function test_image_uploads_reject_traversal_temp_keys(): void
    {
        $before = ProductImage::query()->count();

        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            foreach (['products', 'categories'] as $resource) {
                $this->actingAs($this->admin)
                    ->postJson("/api/modules/sirsoft-ecommerce/admin/{$resource}/images", [
                        'file' => UploadedFile::fake()->image('probe.png'),
                        'temp_key' => $tempKey,
                    ])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('temp_key');
            }
        }

        $this->assertSame($before, ProductImage::query()->count());
    }

    /**
     * 연결할 임시 이미지가 없는 UUID 키로 연결하면 0 을 돌려주고 임시 디렉토리를 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=product_store,temp_key_shape=uuid
     *
     * @effects empty_temp_link_deletes_nothing
     */
    public function test_link_without_temp_images_deletes_nothing(): void
    {
        $tempKey = (string) Str::uuid();
        $service = app(ProductImageService::class);
        $storage = (new \ReflectionProperty($service, 'storage'))->getValue($service);
        $stray = "products/temp/{$tempKey}/stray.jpg";
        $storage->put('images', $stray, 'x');

        try {
            $linked = $service->linkTempImages($tempKey, 999999, 'NO-SUCH-CODE');

            $this->assertSame(0, $linked);
            $this->assertTrue($storage->exists('images', $stray));
        } finally {
            $storage->deleteDirectory('images', "products/temp/{$tempKey}");
        }
    }

    /**
     * UUID 키로 올린 임시 이미지는 상품에 연결·이동되고 임시 디렉토리가 정리된다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=image_upload,temp_key_shape=uuid
     *
     * @effects uuid_temp_image_linked_and_temp_dir_cleaned
     */
    public function test_uuid_temp_image_is_linked_and_temp_dir_cleaned(): void
    {
        $tempKey = (string) Str::uuid();

        $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products/images', [
                'file' => UploadedFile::fake()->image('linked.png'),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(201);

        $image = ProductImage::query()->where('temp_key', $tempKey)->firstOrFail();
        $tempDir = "sirsoft-ecommerce/images/products/temp/{$tempKey}";
        $this->assertTrue(Storage::disk($image->disk)->exists("sirsoft-ecommerce/images/{$image->path}"));

        $product = Product::factory()->create();

        try {
            $linked = app(ProductImageService::class)->linkTempImages($tempKey, $product->id, $product->product_code);

            $image->refresh();
            $this->assertSame(1, $linked);
            $this->assertSame($product->id, $image->product_id);
            $this->assertTrue(Storage::disk($image->disk)->exists("sirsoft-ecommerce/images/{$image->path}"));
            $this->assertFalse(Storage::disk($image->disk)->exists($tempDir));
        } finally {
            Storage::disk($image->disk)->deleteDirectory($tempDir);
            Storage::disk($image->disk)->deleteDirectory("sirsoft-ecommerce/images/products/{$product->product_code}");
        }
    }
}
