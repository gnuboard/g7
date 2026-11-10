<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use RuntimeException;

/**
 * 페이지 본문 미디어 수명 — 페이지 삭제·임시 정리 (#887)
 *
 * 페이지를 지우면 본문 미디어도 일반 첨부처럼 파일과 행이 함께 지워진다. 종전에는 첨부 조회가 본문 미디어(예약 컬렉션)를
 * 일부러 빼서 행은 외래키 연쇄로만 사라지고 파일은 저장소에 영구히 남았다. 파일은 커밋된 뒤에 지우므로 삭제가 되돌아가면
 * 파일도 남는다. 저장하지 않고 나간 임시 본문 미디어는 임시 첨부 정리 명령이 보존기간 뒤 파일과 함께 지운다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-lifecycle.yaml
 */
class PageContentMediaLifecycleTest extends ModuleTestCase
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
     * 페이지를 지우면 본문 미디어 파일과 행이 함께 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=page_deleted
     *
     * @effects page_delete_removes_media_file, page_delete_removes_media_row
     */
    public function test_deleting_page_removes_content_media_file_and_row(): void
    {
        [$page, $media] = $this->pageWithMedia();
        $this->assertTrue($this->disk()->exists('attachments', $media->path), '전제: 본문 미디어 파일이 있다');

        app(PageService::class)->deletePage($page);

        $this->assertNull(Page::query()->find($page->id));
        $this->assertFalse($this->disk()->exists('attachments', $media->path), '페이지 삭제가 본문 미디어 파일을 지운다');
        $this->assertDatabaseMissing('page_attachments', ['id' => $media->id]);
    }

    /**
     * 페이지 삭제가 되돌아가면(바깥 트랜잭션 롤백) 본문 미디어 파일도 남는다 — 커밋 뒤에만 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=delete_rolled_back
     *
     * @effects rollback_keeps_media_file
     */
    public function test_rolled_back_delete_keeps_content_media_file(): void
    {
        [$page, $media] = $this->pageWithMedia();

        try {
            DB::transaction(function () use ($page) {
                app(PageService::class)->deletePage($page);

                throw new RuntimeException('롤백 유도');
            });
        } catch (RuntimeException) {
            // 의도한 롤백
        }

        $this->assertNotNull(Page::query()->find($page->id), '페이지가 되돌아왔다');
        $this->assertNotNull(PageAttachment::query()->find($media->id), '본문 미디어 행이 되돌아왔다');
        $this->assertTrue($this->disk()->exists('attachments', $media->path), '롤백되면 본문 미디어 파일을 지우지 않는다');
    }

    /**
     * 저장하지 않고 나간 임시 본문 미디어는 보존기간이 지나면 임시 첨부 정리 명령이 파일과 함께 지우고, 보존기간 안이면 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_pruned
     *
     * @effects stale_temp_media_pruned, fresh_temp_media_kept
     */
    public function test_prune_command_removes_stale_temp_content_media(): void
    {
        $service = app(PageContentMediaService::class);
        $stale = $service->upload(UploadedFile::fake()->image('stale.png'), (string) Str::uuid());
        $fresh = $service->upload(UploadedFile::fake()->image('fresh.png'), (string) Str::uuid());
        PageAttachment::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(5)]);

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertDatabaseMissing('page_attachments', ['id' => $stale->id]);
        $this->assertFalse($this->disk()->exists('attachments', $stale->path), '임시 본문 미디어 파일도 지운다');
        $this->assertDatabaseHas('page_attachments', ['id' => $fresh->id]);
        $this->assertTrue($this->disk()->exists('attachments', $fresh->path), '보존기간 안의 임시 본문 미디어는 남긴다');
    }

    /**
     * 임시 본문 미디어를 정리해 비워진 날짜 디렉터리는 지우고, 다른 파일이 남은 날짜 디렉터리는 남긴다.
     *
     * 종전에는 임시 첨부 정리가 본문 미디어 임시 행까지 처리하며 비워진 디렉터리를 지웠다. 본문 미디어 정리를 코어 골격으로 옮긴
     * 뒤에도(#887) 같은 동작을 지킨다 — 빠지면 정리를 돌릴 때마다 빈 날짜 디렉터리가 쌓인다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_pruned
     *
     * @effects emptied_date_directory_removed, date_directory_with_files_kept
     */
    public function test_prune_removes_emptied_date_directory_only(): void
    {
        $emptied = $this->staleTempMedia('content-media/2020/01/01');
        $shared = $this->staleTempMedia('content-media/2020/01/02');
        $this->disk()->put('attachments', 'content-media/2020/01/02/linked.png', 'linked');

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertDatabaseMissing('page_attachments', ['id' => $emptied->id]);
        $this->assertDatabaseMissing('page_attachments', ['id' => $shared->id]);
        $base = rtrim($this->disk()->getBasePath('attachments'), '/\\');
        $this->assertDirectoryDoesNotExist($base.'/content-media/2020/01/01', '비워진 날짜 디렉터리는 지운다');
        $this->assertTrue($this->disk()->exists('attachments', 'content-media/2020/01/02/linked.png'), '다른 파일은 그대로');
        $this->assertDirectoryExists($base.'/content-media/2020/01/02', '파일이 남은 날짜 디렉터리는 남긴다');
    }

    /**
     * 보존기간이 지난 임시 본문 미디어 행과 파일을 만듭니다.
     *
     * @param  string  $directory  파일을 둘 날짜 디렉터리 (카테고리 기준)
     * @return PageAttachment 임시 본문 미디어 행
     */
    private function staleTempMedia(string $directory): PageAttachment
    {
        $stored = Str::uuid()->toString().'.png';
        $path = $directory.'/'.$stored;
        $this->disk()->put('attachments', $path, 'bytes');

        $row = PageAttachment::query()->create([
            'page_id' => null,
            'temp_key' => (string) Str::uuid(),
            'original_filename' => 'stale.png',
            'stored_filename' => $stored,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 5,
            'collection' => PageAttachment::COLLECTION_CONTENT_MEDIA,
            'order' => 0,
            'meta' => ['kind' => 'image'],
        ]);
        PageAttachment::query()->whereKey($row->id)->update(['created_at' => now()->subDays(5)]);

        return $row;
    }

    /**
     * 본문 미디어 하나를 본문에 넣은 페이지를 만듭니다.
     *
     * @return array{0: Page, 1: PageAttachment} [페이지, 이어진 본문 미디어]
     */
    private function pageWithMedia(): array
    {
        $service = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(UploadedFile::fake()->image('a.png'), $tempKey);

        $page = app(PageService::class)->createPage([
            'slug' => 'cm-life-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '본문 미디어 수명', 'en' => 'Content media lifecycle'],
            'content' => ['ko' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$media->hash.'"></p>'],
            'content_mode' => 'html',
            'published' => true,
            'temp_key' => $tempKey,
        ]);

        $media->refresh();
        $this->assertSame($page->id, (int) $media->page_id, '전제: 본문 미디어가 페이지에 이어졌다');

        return [$page, $media];
    }

    /**
     * 페이지 모듈 저장소를 돌려줍니다.
     *
     * @return ModuleStorageDriver 저장소
     */
    private function disk(): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-page', 'modules');
    }
}
