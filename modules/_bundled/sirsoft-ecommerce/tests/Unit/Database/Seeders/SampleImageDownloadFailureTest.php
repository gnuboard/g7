<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use App\Extension\ModuleManager;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ProductReviewSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ProductSeeder;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 샘플 시더의 외부 이미지 내려받기가 연결 실패를 견디는지 검증합니다.
 *
 * Http::pool 은 연결에 실패한 요청을 응답이 아니라 ConnectionException 객체로 돌려준다. 그 객체에
 * 응답 메서드를 부르면 시더가 중단되는데, 상품 시더는 이 시점에 이미 기존 상품·주문·문의를 지운 뒤라
 * 샘플 없이 빈 쇼핑몰로 끝났다. 실패한 이미지는 건너뛰고 나머지로 계속해야 한다.
 */
class SampleImageDownloadFailureTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('public');
    }

    /**
     * 상품 이미지 풀 내려받기에서 일부 요청이 연결 실패해도 중단하지 않고 성공분만 담는다.
     *
     * @scenario run=image_download_failure
     *
     * @effects image_download_failure_does_not_abort
     *
     * @return void 반환값 없음
     */
    public function test_product_image_pool_skips_failed_connections(): void
    {
        $count = 0;
        Http::fake(function () use (&$count) {
            return ++$count % 2 === 1 ? Http::failedConnection('timeout') : Http::response('image-bytes', 200);
        });

        $seeder = new ProductSeeder;
        $seeder->setCommand($this->command());

        $method = new \ReflectionMethod(ProductSeeder::class, 'downloadImagePool');
        $method->setAccessible(true);
        $pool = $method->invoke($seeder, 2);

        $downloaded = array_sum(array_map('count', $pool));
        $this->assertGreaterThan(0, $downloaded, '성공한 요청의 이미지는 담겨야 합니다');
        foreach ($pool as $images) {
            foreach ($images as $image) {
                $this->assertSame('image-bytes', $image['content']);
            }
        }
    }

    /**
     * 리뷰 이미지 내려받기가 모두 연결 실패해도 중단하지 않고 이미지 없이 지나간다.
     *
     * @scenario run=image_download_failure
     *
     * @effects image_download_failure_does_not_abort
     *
     * @return void 반환값 없음
     */
    public function test_review_images_skip_failed_connections(): void
    {
        Http::fake(fn () => Http::failedConnection('timeout'));

        $user = User::factory()->create();
        $review = ProductReview::factory()->create(['user_id' => $user->id]);

        $seeder = new ProductReviewSeeder;
        $seeder->setCommand($this->command());
        $storage = new \ReflectionProperty(ProductReviewSeeder::class, 'storage');
        $storage->setAccessible(true);
        $storage->setValue($seeder, app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage());

        $method = new \ReflectionMethod(ProductReviewSeeder::class, 'createReviewImages');
        $method->setAccessible(true);
        $method->invoke($seeder, $review, $user->id);

        $this->assertSame(0, ProductReviewImage::where('review_id', $review->id)->count());
    }

    /**
     * 출력을 버리는 콘솔 명령을 만듭니다.
     *
     * @return Command 명령
     */
    private function command(): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:ecommerce-image-download';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        return $command;
    }
}
