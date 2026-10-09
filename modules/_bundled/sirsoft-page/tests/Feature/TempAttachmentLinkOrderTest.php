<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Str;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 임시 첨부를 페이지에 연결할 때의 첨부 순서 테스트
 *
 * 수정 화면에서 새 첨부를 올려 저장하면 임시 첨부를 페이지에 연결하면서 순서를 1 부터 다시 매겼다.
 * 이미 첨부가 있는 페이지에서는 새 첨부가 기존 첨부와 같은 번호를 받아 목록 중간에 끼었다(7.1.0 전수
 * 실측 영역 L 후속, O-L1 재실측에서 발견 — 4개가 있는 페이지에 1개를 더하면 order 1 이 둘). 새 첨부는
 * 기존 첨부 뒤에 고른 순서대로 붙어야 한다. 페이지를 새로 만들 때는 종전대로 1..N 이다.
 *
 * @scenario-manifest tests/scenarios/temp-attachment-link-order.yaml
 */
class TempAttachmentLinkOrderTest extends FeatureTestCase
{
    /**
     * 저장소 대역 — 파일 내용이 없으면 서비스는 이동을 건너뛰고 DB 만 갱신한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('get')->andReturn(null);
        $storage->shouldReceive('deleteDirectory')->andReturn(true);
        $storage->shouldReceive('getDisk')->andReturn('local');
        $this->app->instance(StorageInterface::class, $storage);
    }

    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'link-order-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 임시 첨부를 올린 순서대로 만든다.
     *
     * @param  string  $tempKey  임시 키
     * @param  array<int, string>  $names  파일명(올린 순서)
     * @return void 반환값 없음
     */
    private function makeTempAttachments(string $tempKey, array $names): void
    {
        foreach ($names as $i => $name) {
            PageAttachment::factory()->create([
                'page_id' => null,
                'temp_key' => $tempKey,
                'original_filename' => $name,
                'path' => "temp/{$tempKey}/{$name}",
                'order' => $i + 1,
            ]);
        }
    }

    /**
     * 페이지 첨부를 순서대로 읽어 「파일명#순서」 목록으로 돌려준다.
     *
     * @param  int  $pageId  페이지 ID
     * @return array<int, string> 파일명#순서
     */
    private function orderedNames(int $pageId): array
    {
        return PageAttachment::where('page_id', $pageId)->orderBy('order')->orderBy('id')->get()
            ->map(fn ($a) => $a->original_filename.'#'.$a->order)->all();
    }

    /**
     * 첨부가 있는 페이지에 새 첨부를 연결하면 기존 첨부 뒤에 고른 순서대로 붙는다.
     *
     * @return void 반환값 없음
     *
     * @scenario form_mode=edit
     *
     * @effects linked_temp_attachments_follow_existing_ones
     */
    public function test_new_attachments_are_appended_after_existing_ones(): void
    {
        $page = Page::factory()->create(['slug' => 'link-order-edit']);
        foreach (['old-a.pdf', 'old-b.pdf'] as $i => $name) {
            PageAttachment::factory()->create(['page_id' => $page->id, 'original_filename' => $name, 'order' => $i + 1]);
        }
        $tempKey = (string) Str::uuid();
        $this->makeTempAttachments($tempKey, ['new-1.png', 'new-2.png']);

        $linked = app(PageAttachmentService::class)->linkTempAttachmentsWithMove($tempKey, $page->id);

        $this->assertSame(2, $linked);
        $this->assertSame(['old-a.pdf#1', 'old-b.pdf#2', 'new-1.png#3', 'new-2.png#4'], $this->orderedNames($page->id));
    }

    /**
     * 첨부가 없는 새 페이지에 연결하면 올린 순서대로 1..N 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario form_mode=create
     *
     * @effects linked_temp_attachments_numbered_from_one_on_new_page
     */
    public function test_new_page_gets_attachments_numbered_from_one(): void
    {
        $page = Page::factory()->create(['slug' => 'link-order-create']);
        $tempKey = (string) Str::uuid();
        $this->makeTempAttachments($tempKey, ['a.pdf', 'b.png', 'c.gif']);

        app(PageAttachmentService::class)->linkTempAttachmentsWithMove($tempKey, $page->id);

        $this->assertSame(['a.pdf#1', 'b.png#2', 'c.gif#3'], $this->orderedNames($page->id));
    }
}
