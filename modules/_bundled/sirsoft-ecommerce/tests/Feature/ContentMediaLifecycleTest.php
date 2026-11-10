<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Services\ProductCommonInfoService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use RuntimeException;

/**
 * 이커머스 본문 미디어 수명 — 상품·공통 정보 삭제와 임시 정리 (#887)
 *
 * 상품(영구 삭제)·상품 공통 정보를 지우면 그 소유의 본문 미디어를 행(본문에서 빠져 소프트 삭제된 것 포함)과 파일째 영구 삭제한다.
 * 종전에는 삭제가 본문 미디어 행을 건드리지 않아 행·파일이 영구히 남았다(행은 범용 첨부 표 `ecommerce_attachments`). 파일은 커밋된 뒤에 지우므로 삭제가
 * 되돌아가면 파일도 남는다. 저장하지 않고 나간 임시 본문 미디어(상품·공통 정보 모두)는 임시 상품 이미지 정리 명령
 * (`sirsoft-ecommerce:prune-temp-product-images`, 매일 스케줄)이 보존기간 뒤 함께 지운다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media-lifecycle.yaml
 */
class ContentMediaLifecycleTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
    }

    /**
     * 상품을 지우면 이어진 본문 미디어와 본문에서 빠져 소프트 삭제된 본문 미디어가 파일과 함께 영구 삭제된다.
     * 다른 상품의 본문 미디어는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=product_deleted
     *
     * @effects owner_delete_purges_rows, owner_delete_purges_files, other_owner_untouched
     */
    public function test_deleting_product_purges_its_content_media(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        [$kept, $removed] = $this->linkMedia(ContentMediaOwnerType::Product, $product->id, true);
        [$otherMedia] = $this->linkMedia(ContentMediaOwnerType::Product, $other->id, false);
        $this->assertSoftDeleted('ecommerce_attachments', ['id' => $removed->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}")
            ->assertOk();

        foreach ([$kept, $removed] as $media) {
            $this->assertNull(ContentMedia::withTrashed()->find($media->id), '상품 삭제가 본문 미디어 행을 영구 삭제한다');
            $this->assertFalse($this->exists($media), '상품 삭제가 본문 미디어 파일을 지운다');
        }
        $this->assertNotNull(ContentMedia::query()->find($otherMedia->id), '다른 상품의 본문 미디어는 그대로');
        $this->assertTrue($this->exists($otherMedia));
    }

    /**
     * 상품 공통 정보를 지우면 그 소유의 본문 미디어가 파일과 함께 영구 삭제된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=common_info_deleted
     *
     * @effects owner_delete_purges_rows, owner_delete_purges_files
     */
    public function test_deleting_common_info_purges_its_content_media(): void
    {
        $commonInfo = $this->commonInfo();
        [$kept, $removed] = $this->linkMedia(ContentMediaOwnerType::CommonInfo, $commonInfo->id, true);

        app(ProductCommonInfoService::class)->deleteCommonInfo($commonInfo->id);

        foreach ([$kept, $removed] as $media) {
            $this->assertNull(ContentMedia::withTrashed()->find($media->id), '공통 정보 삭제가 본문 미디어 행을 영구 삭제한다');
            $this->assertFalse($this->exists($media), '공통 정보 삭제가 본문 미디어 파일을 지운다');
        }
    }

    /**
     * 상품 삭제가 되돌아가면(바깥 트랜잭션 롤백) 본문 미디어 행과 파일이 남는다 — 파일은 커밋 뒤에만 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=delete_rolled_back
     *
     * @effects rollback_keeps_media_file
     */
    public function test_rolled_back_product_delete_keeps_content_media(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.delete']);
        $this->actingAs($admin);
        $product = Product::factory()->create();
        [$media] = $this->linkMedia(ContentMediaOwnerType::Product, $product->id, false);

        try {
            DB::transaction(function () use ($product) {
                app(ProductService::class)->delete($product);

                throw new RuntimeException('롤백 유도');
            });
        } catch (RuntimeException) {
            // 의도한 롤백
        }

        $this->assertNotNull(Product::query()->find($product->id), '상품이 되돌아왔다');
        $this->assertNotNull(ContentMedia::query()->find($media->id), '본문 미디어 행이 되돌아왔다');
        $this->assertTrue($this->exists($media), '롤백되면 본문 미디어 파일을 지우지 않는다');
    }

    /**
     * 저장하지 않고 나간 임시 본문 미디어는 상품·공통 정보 모두 임시 상품 이미지 정리 명령이 보존기간 뒤 파일과 함께 지우고,
     * 보존기간 안이거나 소유자에 이어진 것은 남긴다. `--dry-run` 은 아무것도 지우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_pruned
     *
     * @effects stale_temp_media_pruned_both_owner_types, fresh_or_linked_media_kept, dry_run_keeps_media
     */
    public function test_prune_command_removes_stale_temp_content_media_of_both_owner_types(): void
    {
        $service = app(ContentMediaService::class);
        $staleProduct = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('p.png'), (string) Str::uuid());
        $staleCommon = $service->upload(ContentMediaOwnerType::CommonInfo, UploadedFile::fake()->image('c.png'), (string) Str::uuid());
        $fresh = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('f.png'), (string) Str::uuid());
        $product = Product::factory()->create();
        [$linked] = $this->linkMedia(ContentMediaOwnerType::Product, $product->id, false);
        ContentMedia::query()->whereKey([$staleProduct->id, $staleCommon->id, $linked->id])->update(['created_at' => now()->subDays(5)]);

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images --dry-run')->assertSuccessful();
        $this->assertNotNull(ContentMedia::query()->find($staleProduct->id), 'dry-run 은 지우지 않는다');

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        foreach ([$staleProduct, $staleCommon] as $media) {
            $this->assertNull(ContentMedia::withTrashed()->find($media->id), '보존기간이 지난 임시 본문 미디어를 지운다');
            $this->assertFalse($this->exists($media), '임시 본문 미디어 파일도 지운다');
        }
        foreach ([$fresh, $linked] as $media) {
            $this->assertNotNull(ContentMedia::query()->find($media->id), '보존기간 안이거나 이어진 본문 미디어는 남긴다');
            $this->assertTrue($this->exists($media));
        }
    }

    /**
     * 임시 본문 미디어를 지우는 정리 명령이 매일 스케줄로 등록돼 있다 (등록 누락 회귀 차단).
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_pruned
     *
     * @effects prune_scheduled_daily
     */
    public function test_prune_command_is_scheduled_daily(): void
    {
        $entry = collect((new Module)->getSchedules())
            ->firstWhere('command', 'sirsoft-ecommerce:prune-temp-product-images');

        $this->assertNotNull($entry, '임시 상품 이미지·본문 미디어 정리 스케줄이 등록되어야 합니다.');
        $this->assertSame('daily', $entry['schedule']);
        $this->assertNull($entry['enabled_config'], '토글 없이 상시 동작한다');
    }

    /**
     * 소유자에 본문 미디어를 잇습니다 — 둘째 인자가 참이면 하나를 더 이은 뒤 본문에서 빼 소프트 삭제 상태로 만듭니다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @param  bool  $withRemoved  본문에서 빠진(소프트 삭제) 본문 미디어도 만들지
     * @return array<int, ContentMedia> [이어진 것, (본문에서 빠진 것)]
     */
    private function linkMedia(ContentMediaOwnerType $ownerType, int $ownerId, bool $withRemoved): array
    {
        $service = app(ContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $kept = $service->upload($ownerType, UploadedFile::fake()->image('k.png'), $tempKey);
        $html = fn (ContentMedia $m) => '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$m->hash.'"></p>';

        if (! $withRemoved) {
            $service->syncWithContent($ownerType, $ownerId, ['ko' => $html($kept)], true, $tempKey);

            return [$kept->fresh()];
        }

        $removed = $service->upload($ownerType, UploadedFile::fake()->image('r.png'), $tempKey);
        $service->syncWithContent($ownerType, $ownerId, ['ko' => $html($kept).$html($removed)], true, $tempKey);
        $service->syncWithContent($ownerType, $ownerId, ['ko' => $html($kept)], true, null);

        return [$kept->fresh(), ContentMedia::withTrashed()->findOrFail($removed->id)];
    }

    /**
     * 상품 공통 정보를 만듭니다.
     *
     * @return ProductCommonInfo 공통 정보
     */
    private function commonInfo(): ProductCommonInfo
    {
        return ProductCommonInfo::create([
            'name' => ['ko' => '본문 미디어 안내', 'en' => 'Content media info'],
            'content' => ['ko' => '<p>안내</p>', 'en' => '<p>info</p>'],
            'content_mode' => 'html',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * 본문 미디어 파일이 저장소에 있는지.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @return bool 있으면 true
     */
    private function exists(ContentMedia $media): bool
    {
        return (new ModuleStorageDriver('sirsoft-ecommerce', $media->disk))->exists(ContentMediaService::CATEGORY, $media->storagePath());
    }
}
