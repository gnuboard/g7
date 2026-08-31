<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 관리자 카테고리 이미지 다운로드 서빙 회귀 테스트
 *
 * 배경: downloadImage() 가 과거 response()->streamDownload(fn => echo Storage::get())
 * 로 파일 전체를 메모리에 적재하는 안티패턴이었다. 서빙은 CategoryImageService::getFileInfo()
 * (StorageInterface::response() 기반 스트리밍 + 검증자 메타)로 위임하고, 캐시 계약은 코어
 * 단일 지점(`streamedFileResponse()`)이 입힌다 (14차 G3 라운드).
 *
 * 이 테스트는 컨트롤러가 서비스의 스트리밍 경로로 위임하는지를 단언한다 —
 * 전체 적재 경로(getByHash 조회 후 직접 Storage 접근)로 회귀하면 실패한다.
 */
class CategoryImageDownloadTest extends ModuleTestCase
{
    /** @var MockInterface&CategoryImageService */
    private $categoryImageService;

    private CategoryController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $categoryService = Mockery::mock(CategoryService::class);
        $this->categoryImageService = Mockery::mock(CategoryImageService::class);

        $this->controller = new CategoryController(
            $categoryService,
            $this->categoryImageService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 다운로드가 서비스의 스트리밍 응답으로 위임되는지 검증한다.
     *
     * 전체 적재 경로(getByHash + Storage::get)로 회귀하면 download() 미호출 +
     * getByHash 호출로 이 테스트가 실패한다.
     */
    #[Test]
    public function test_download_delegates_to_streaming_service(): void
    {
        $hash = 'abcdef012345';
        $streamed = new StreamedResponse(function () {}, 200, [
            'Content-Type' => 'image/jpeg',
        ]);

        // 서비스의 스트리밍 서빙 정보가 호출되어야 한다.
        $this->categoryImageService
            ->shouldReceive('getFileInfo')
            ->once()
            ->with($hash)
            ->andReturn([
                'response' => $streamed,
                'etag_source' => 'modules|category/x.jpg|1700000000|11',
                'mime_type' => 'image/jpeg',
                'filename' => 'x.jpg',
                'last_modified' => 1700000000,
                'max_age' => CategoryImageService::IMAGE_CACHE_MAX_AGE,
            ]);

        // 전체 적재 경로(getByHash 후 직접 Storage 접근)는 사용하면 안 된다.
        $this->categoryImageService
            ->shouldReceive('getByHash')
            ->never();

        $result = $this->controller->downloadImage($hash);

        $this->assertInstanceOf(
            StreamedResponse::class,
            $result,
            '관리자 다운로드는 스트리밍 응답을 반환해야 한다 (전체 메모리 적재 금지)'
        );
        $this->assertSame($streamed, $result, '서비스가 생성한 스트리밍 응답을 그대로 반환해야 한다');

        // 캐시 계약은 코어 지점이 입힌다 — 검증자가 없으면 회차 13 이전 상태(재검증 불가)다.
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $result->headers->get('ETag'));
        $this->assertNotNull($result->headers->get('Cache-Control'));
        $this->assertNotNull($result->headers->get('Expires'));
        $this->assertNotNull($result->headers->get('Last-Modified'));
    }

    /**
     * 이미지가 없으면 404 를 반환한다 (기존 동작 회귀 유지).
     */
    #[Test]
    public function test_download_returns_not_found_when_image_missing(): void
    {
        $hash = 'missinghash0';

        $this->categoryImageService
            ->shouldReceive('getFileInfo')
            ->once()
            ->with($hash)
            ->andReturn(null);

        $result = $this->controller->downloadImage($hash);

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(404, $result->getStatusCode());
    }
}
