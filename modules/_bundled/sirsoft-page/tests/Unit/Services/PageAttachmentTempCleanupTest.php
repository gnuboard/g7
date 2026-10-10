<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 임시 첨부 연결 뒤 임시 디렉토리 정리 실패의 격리 회귀 테스트 (#703 브라우저 실측)
 *
 * 게시판 첨부에서 막 옮긴 대용량 파일을 운영체제가 잠시 붙잡고 있으면 임시 디렉토리 정리가 lstat 오류를
 * 던졌고, 그 예외가 저장 전체를 500 으로 되돌려 파일은 본경로, DB 는 임시 경로를 가리키는 어긋남이 남았다.
 * 같은 이동·정리 구조인 페이지 첨부 연결도 정리 실패가 연결을 되돌리지 않아야 한다.
 *
 * 시나리오 매니페스트: tests/scenarios/temp-link-cleanup-isolation.yaml
 */
class PageAttachmentTempCleanupTest extends ModuleTestCase
{
    private User $adminUser;

    /**
     * 정리만 실패하는 저장소를 주입한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser([]);
        $this->actingAs($this->adminUser);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('put')->andReturn(true);
        $storage->shouldReceive('get')->andReturn('file content');
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('delete')->andReturn(true);
        $storage->shouldReceive('getDisk')->andReturn('local');
        $storage->shouldReceive('url')->andReturn(null);
        $storage->shouldReceive('response')->andReturn(null);
        $storage->shouldReceive('deleteDirectory')->andThrow(new \RuntimeException('SplFileInfo::getType(): Lstat failed'));
        $this->app->instance(StorageInterface::class, $storage);
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
     * 임시 디렉토리 정리가 예외를 던져도 첨부는 페이지에 연결된다.
     *
     * @scenario module=page_attachment
     *
     * @effects temp_cleanup_failure_does_not_abort_link
     *
     * @return void 반환값 없음
     */
    public function test_link_survives_temp_directory_cleanup_failure(): void
    {
        $service = app(PageAttachmentService::class);
        $page = Page::factory()->create([
            'slug' => 'inspect703-temp-cleanup',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
        $tempKey = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';
        $attachment = $service->upload(UploadedFile::fake()->create('big.pdf', 100, 'application/pdf'), null, 'attachments', $tempKey);

        $this->assertSame(1, $service->linkTempAttachmentsWithMove($tempKey, $page->id));

        $attachment->refresh();
        $this->assertSame($page->id, $attachment->page_id);
        $this->assertNull($attachment->temp_key);
    }
}
