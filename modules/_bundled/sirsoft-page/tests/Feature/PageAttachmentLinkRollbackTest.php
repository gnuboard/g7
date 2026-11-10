<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 임시 첨부 연결의 롤백 — 파일도 임시 자리로 (#883 3단계)
 *
 * 페이지 저장이 임시 첨부를 본경로로 옮긴 뒤 같은 트랜잭션의 뒤 단계가 실패하면, 종전에는 파일만 본경로에 남았다.
 * 이동은 StorageTransaction 이라 롤백되면 사본을 지우고 원본을 둔다. 커밋되면 원본을 지운다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-attachment-link-rollback.yaml
 */
class PageAttachmentLinkRollbackTest extends ModuleTestCase
{
    /**
     * 롤백되면 첨부 행과 파일이 임시 자리에 그대로, 커밋되면 옮겨진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=rollback_then_commit
     *
     * @effects page_temp_attachment_file_restored_on_rollback
     */
    public function test_temp_attachment_link_is_compensated_on_rollback(): void
    {
        Storage::fake('local');
        Storage::fake('modules');
        $service = app(PageAttachmentService::class);
        /** @var StorageInterface $storage */
        $storage = (new \ReflectionProperty($service, 'storage'))->getValue($service);
        $page = Page::factory()->create();
        $tempKey = (string) Str::uuid();
        $tempPath = "temp/{$tempKey}/p.pdf";
        $storage->put('attachments', $tempPath, 'pdf');
        $attachment = PageAttachment::create([
            'page_id' => null, 'temp_key' => $tempKey, 'original_filename' => 'p.pdf', 'stored_filename' => 'p.pdf',
            'disk' => 'local', 'path' => $tempPath, 'mime_type' => 'application/pdf', 'size' => 3,
            'collection' => 'attachments', 'order' => 1,
        ]);

        try {
            DB::transaction(function () use ($service, $tempKey, $page) {
                $service->linkTempAttachmentsWithMove($tempKey, $page->id);

                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $attachment->refresh();
        $this->assertNull($attachment->page_id);
        $this->assertSame($tempPath, $attachment->path);
        $this->assertTrue($storage->exists('attachments', $tempPath));
        $this->assertFalse($storage->exists('attachments', date('Y/m/d').'/p.pdf'));

        DB::transaction(fn () => $service->linkTempAttachmentsWithMove($tempKey, $page->id));

        $this->assertFalse($storage->exists('attachments', $tempPath));
        $this->assertTrue($storage->exists('attachments', date('Y/m/d').'/p.pdf'));
    }
}
