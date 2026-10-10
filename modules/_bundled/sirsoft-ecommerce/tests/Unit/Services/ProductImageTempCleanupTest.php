<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 임시 이미지 연결 뒤 임시 디렉토리 정리 실패의 격리 회귀 테스트 (#703 브라우저 실측)
 *
 * 게시판 첨부에서 막 옮긴 대용량 파일을 운영체제가 잠시 붙잡고 있으면 임시 디렉토리 정리가 lstat 오류를
 * 던졌고, 그 예외가 저장 전체를 500 으로 되돌려 파일은 본경로, DB 는 임시 경로를 가리키는 어긋남이 남았다.
 * 같은 이동·정리 구조인 상품 이미지 연결도 정리 실패가 연결을 되돌리지 않아야 한다.
 *
 * 시나리오 매니페스트: tests/scenarios/temp-link-cleanup-isolation.yaml
 */
class ProductImageTempCleanupTest extends ModuleTestCase
{
    /**
     * 훅 작업을 막고 로그인한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Auth::login(User::factory()->create());
    }

    /**
     * Mockery 를 닫는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 임시 디렉토리 정리가 예외를 던져도 옮긴 이미지는 상품에 연결되고 연결 건수를 돌려준다.
     *
     * @scenario module=ecommerce_product_image
     *
     * @effects temp_cleanup_failure_does_not_abort_link
     *
     * @return void 반환값 없음
     */
    public function test_link_survives_temp_directory_cleanup_failure(): void
    {
        $tempKey = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
        $image = new ProductImage([
            'product_id' => null,
            'temp_key' => $tempKey,
            'stored_filename' => 'big.jpg',
            'path' => "products/temp/{$tempKey}/big.jpg",
        ]);
        $image->id = 21;

        $repository = Mockery::mock(ProductImageRepositoryInterface::class);
        $repository->shouldReceive('getByTempKey')->once()->with($tempKey)->andReturn(new EloquentCollection([$image]));
        $repository->shouldReceive('getMaxSortOrder')->once()->andReturn(0);
        $repository->shouldReceive('update')->once()->andReturn($image);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('get')->once()->andReturn('image-bytes');
        $storage->shouldReceive('put')->once()->andReturn(true);
        $storage->shouldReceive('delete')->once()->andReturn(true);
        $storage->shouldReceive('deleteDirectory')->andThrow(new \RuntimeException('SplFileInfo::getType(): Lstat failed'));

        $service = new ProductImageService($repository, $storage, Mockery::mock(ProductRepositoryInterface::class));

        $this->assertSame(1, $service->linkTempImages($tempKey, 9, 'PROD-009'));
    }
}
