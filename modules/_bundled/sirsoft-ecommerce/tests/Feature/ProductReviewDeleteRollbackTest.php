<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 후기·후기 이미지 삭제의 롤백 — 파일은 커밋된 뒤에만 지운다 (#883 3단계)
 *
 * 종전에는 파일을 먼저 지우고 레코드를 지워, 같은 트랜잭션이 되돌아가면 레코드만 살아나 깨진 이미지가 남았다.
 * 후기 이미지 단건 삭제는 트랜잭션도 없었다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-save-transaction-hooks.yaml
 */
class ProductReviewDeleteRollbackTest extends ModuleTestCase
{
    private \App\Contracts\Extension\StorageInterface $storage;

    /**
     * 가짜 디스크와 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $this->storage = $this->serviceStorage(app(ProductReviewService::class));
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.reviews.delete', 'sirsoft-ecommerce.reviews.read']));
    }

    /**
     * 후기 삭제를 감싼 트랜잭션이 롤백되면 이미지 파일이 남고, 커밋되면 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=review_delete_rollback
     *
     * @effects review_image_file_kept_on_rollback
     */
    public function test_review_delete_keeps_files_when_outer_transaction_rolls_back(): void
    {
        $image = ProductReviewImage::factory()->create(['disk' => $this->storage->getDisk()]);
        $this->storage->put('images', $image->path, 'jpg');
        $review = ProductReview::query()->with('images')->findOrFail($image->review_id);

        try {
            DB::transaction(function () use ($review) {
                app(ProductReviewService::class)->deleteReview($review);

                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->storage->exists('images', $image->path), '롤백되면 파일이 남는다');
        $this->assertNotNull(ProductReviewImage::query()->find($image->id));

        app(ProductReviewService::class)->deleteReview($review->fresh(['images']));
        $this->assertFalse($this->storage->exists('images', $image->path), '커밋되면 파일을 지운다');
    }

    /**
     * 후기 이미지 단건 삭제도 레코드 삭제와 한 트랜잭션이고, 파일은 커밋 뒤에 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=review_delete_rollback
     *
     * @effects review_image_file_kept_on_rollback
     */
    public function test_review_image_delete_keeps_file_when_outer_transaction_rolls_back(): void
    {
        $image = ProductReviewImage::factory()->create(['disk' => $this->storage->getDisk()]);
        $this->storage->put('images', $image->path, 'jpg');

        try {
            DB::transaction(function () use ($image) {
                app(ProductReviewImageService::class)->delete($image);

                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->storage->exists('images', $image->path));

        app(ProductReviewImageService::class)->delete($image->fresh());
        $this->assertFalse($this->storage->exists('images', $image->path));
    }

    /**
     * 서비스가 주입받은 저장소를 돌려줍니다 — 모듈이 쓰는 디스크(구성에 따라 local·modules)를 그대로 쓰기 위해.
     *
     * @param  object  $service  저장소를 주입받은 서비스
     * @return \App\Contracts\Extension\StorageInterface 저장소
     */
    private function serviceStorage(object $service): \App\Contracts\Extension\StorageInterface
    {
        return (new \ReflectionProperty($service, 'storage'))->getValue($service);
    }
}
