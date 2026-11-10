<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaHooks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 본문 미디어 업로드가 코어 공용 훅을 한 번씩 발화한다 — 페이지 첨부 훅은 발화하지 않는다 (#887 G2)
 *
 * 본문 미디어 업로드는 코어 골격이 `core.content_media.before_upload` → `filter_upload_file` → `after_upload` 를 발화하고,
 * 리스너는 문맥(`ContentMediaContext`)으로 어느 확장·문서의 미디어인지 안다. 페이지 첨부 훅(`sirsoft-page.attachment.*`)이 같이
 * 발화하면 첨부 리스너(활동 로그 등)가 본문 미디어를 첨부로 오인한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-hooks.yaml
 */
class PageContentMediaHooksTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 작성자로 로그인합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $this->actingAs(User::factory()->create());
    }

    /**
     * 업로드 한 건에 공용 훅이 한 번씩, 페이지 문맥으로 발화하고 첨부 훅은 발화하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario hook=upload
     *
     * @effects upload_fires_core_hooks_once, upload_context_is_page, attachment_hooks_not_fired
     */
    public function test_upload_fires_core_content_media_hooks_once(): void
    {
        $calls = [];
        HookManager::addAction(ContentMediaHooks::BEFORE_UPLOAD, function (UploadedFile $file, ContentMediaContext $context) use (&$calls) {
            $calls[] = ['before_upload', $context];
        });
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, function (UploadedFile $file, ContentMediaContext $context) use (&$calls) {
            $calls[] = ['filter_upload_file', $context];

            return $file;
        });
        HookManager::addAction(ContentMediaHooks::AFTER_UPLOAD, function ($row, ContentMediaContext $context) use (&$calls) {
            $calls[] = ['after_upload', $context];
        });
        $attachmentHooks = 0;
        foreach (['before_upload', 'after_upload'] as $hook) {
            HookManager::addAction('sirsoft-page.attachment.'.$hook, function () use (&$attachmentHooks) {
                $attachmentHooks++;
            });
        }
        HookManager::addFilter('sirsoft-page.attachment.filter_upload_file', function ($file) use (&$attachmentHooks) {
            $attachmentHooks++;

            return $file;
        });

        $tempKey = (string) Str::uuid();
        $row = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('a.png'), $tempKey);

        $this->assertSame(['before_upload', 'filter_upload_file', 'after_upload'], array_column($calls, 0), '한 번씩, 이 순서로');
        foreach ($calls as [, $context]) {
            $this->assertSame(ContentMediaContext::EXTENSION_MODULE, $context->extensionType);
            $this->assertSame('sirsoft-page', $context->extension);
            $this->assertSame('page', $context->documentType);
            $this->assertNull($context->documentId, '저장 전 업로드');
            $this->assertSame($tempKey, $context->tempKey);
            $this->assertSame((int) auth()->id(), $context->actorId);
        }
        $this->assertSame('image', $calls[2][1]->kind?->value);
        $this->assertSame(0, $attachmentHooks, '페이지 첨부 훅은 발화하지 않는다');
        $this->assertSame('image', $row->meta['kind'] ?? null);
    }
}
