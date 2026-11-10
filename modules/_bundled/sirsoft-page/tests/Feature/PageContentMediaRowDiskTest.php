<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 페이지 본문 미디어 — 기본 디스크를 바꾼 뒤에도 옛 행은 그 행의 디스크에서 서빙·복제·제거 (#887 G1 행 디스크)
 *
 * 종전 페이지 본문 미디어 서비스는 서빙·복제 원본 읽기·본문에서 빠진 것의 삭제를 주입 저장소(지금 기본 디스크)로 고정해, 운영자가
 * 디스크를 바꾸면 옛 행이 404·복제 실패·파일 잔존이 됐다. 이제 코어 골격이 행에 기록된 디스크(`RowDiskStorage`)로 읽고 지운다.
 * 새 파일(업로드·복제 사본)은 기본 디스크에 쓴다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-row-disk.yaml
 */
class PageContentMediaRowDiskTest extends ModuleTestCase
{
    /** 전환 전 디스크(옛 행이 기록된 디스크) */
    private const OLD_DISK = 'page_cm_old';

    /**
     * 기본 디스크(`modules`)와 옛 디스크를 가짜로 두고 작성자로 로그인합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.'.self::OLD_DISK, [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.self::OLD_DISK),
        ]);
        Storage::fake(self::OLD_DISK);
        Storage::fake('local');
        Storage::fake('modules');
        $this->actingAs(User::factory()->create());
        $this->registerModuleRoutes();
    }

    /**
     * 옛 디스크에 기록된 행은 그 디스크에서 서빙한다(기본 디스크에는 파일이 없다).
     *
     * @return void 반환값 없음
     *
     * @scenario operation=serve
     *
     * @effects serve_reads_row_disk
     */
    public function test_serves_row_from_its_recorded_disk(): void
    {
        $page = $this->page(published: true);
        $row = $this->rowOnOldDisk($page, 'old-bytes');
        $this->assertFalse($this->driver('modules')->exists('attachments', $row->path), '전제: 기본 디스크에는 없다');

        $response = $this->get(PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$row->hash);

        $response->assertStatus(200);
        $base = $response->baseResponse;
        $body = match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse => $response->streamedContent(),
            default => (string) $base->getContent(),
        };
        $this->assertSame('old-bytes', $body);
    }

    /**
     * 다른 페이지의 옛 디스크 행을 쓰면 원본을 그 행의 디스크에서 읽어 기본 디스크에 사본을 만든다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=clone
     *
     * @effects clone_reads_row_disk_writes_default
     */
    public function test_clone_reads_source_from_row_disk(): void
    {
        $source = $this->page(published: true);
        $row = $this->rowOnOldDisk($source, 'source-bytes');

        $page = app(PageService::class)->createPage([
            'slug' => 'cm-disk-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '복제'],
            'content' => ['ko' => $this->img($row->hash)],
            'content_mode' => 'html',
            'published' => false,
        ]);

        $copy = PageAttachment::query()->where('page_id', $page->id)->where('collection', PageAttachment::COLLECTION_CONTENT_MEDIA)->firstOrFail();
        $this->assertNotSame($row->hash, $copy->hash);
        $this->assertSame('modules', $copy->disk, '사본은 기본 디스크에 쓴다');
        $this->assertSame('source-bytes', $this->driver('modules')->get('attachments', $copy->path));
        $this->assertTrue($this->driver(self::OLD_DISK)->exists('attachments', $row->path), '원본은 그대로');
    }

    /**
     * 어느 버전도 참조하지 않는 옛 디스크 행이 본문에서 빠지면 그 행의 디스크에서 파일을 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=remove
     *
     * @effects removed_unreferenced_purged_from_row_disk
     */
    public function test_removed_unreferenced_media_is_purged_from_row_disk(): void
    {
        $page = $this->page(published: true);
        // 버전 스냅샷을 거치지 않고 붙인 행 — 어느 버전 본문도 이 해시를 참조하지 않는다
        $row = $this->rowOnOldDisk($page, 'bytes');

        app(PageService::class)->updatePage($page, ['content' => ['ko' => '<p>미디어 없음</p>'], 'content_mode' => 'html']);

        $this->assertDatabaseMissing('page_attachments', ['id' => $row->id]);
        $this->assertFalse($this->driver(self::OLD_DISK)->exists('attachments', $row->path), '옛 디스크의 파일을 지운다');
    }

    /**
     * 페이지를 지우면 옛 디스크 행의 파일도 그 디스크에서 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=page_delete
     *
     * @effects page_delete_purges_row_disk
     */
    public function test_page_delete_purges_file_on_row_disk(): void
    {
        $page = $this->page(published: false);
        $row = $this->rowOnOldDisk($page, 'bytes');

        app(PageService::class)->deletePage($page);

        $this->assertDatabaseMissing('page_attachments', ['id' => $row->id]);
        $this->assertFalse($this->driver(self::OLD_DISK)->exists('attachments', $row->path));
    }

    /**
     * 보존기간이 지난 옛 디스크의 임시 본문 미디어는 정리 명령이 그 디스크에서 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=prune
     *
     * @effects prune_deletes_on_row_disk, prune_removes_emptied_directory_on_row_disk
     */
    public function test_prune_deletes_stale_temp_media_on_row_disk(): void
    {
        $row = $this->rowOnOldDisk(null, 'bytes');
        PageAttachment::query()->whereKey($row->id)->update(['created_at' => now()->subDays(5)]);

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertDatabaseMissing('page_attachments', ['id' => $row->id]);
        $this->assertFalse($this->driver(self::OLD_DISK)->exists('attachments', $row->path));
        $this->assertDirectoryDoesNotExist(
            rtrim($this->driver(self::OLD_DISK)->getBasePath('attachments'), '/\\').'/'.dirname($row->path),
            '비워진 날짜 디렉터리도 그 행의 디스크에서 지운다'
        );
    }

    /**
     * 본문 미디어 없는 페이지를 만듭니다.
     *
     * @param  bool  $published  발행 여부
     * @return Page 페이지
     */
    private function page(bool $published): Page
    {
        return app(PageService::class)->createPage([
            'slug' => 'cm-disk-src-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '디스크'],
            'content' => ['ko' => '<p>본문</p>'],
            'content_mode' => 'html',
            'published' => $published,
        ]);
    }

    /**
     * 옛 디스크에 파일과 본문 미디어 행을 만듭니다(페이지가 있으면 본문도 그 주소로 바꾼다 — 버전 스냅샷 없이).
     *
     * @param  Page|null  $page  이을 페이지 (null 이면 임시 행)
     * @param  string  $bytes  파일 내용
     * @return PageAttachment 행
     */
    private function rowOnOldDisk(?Page $page, string $bytes): PageAttachment
    {
        $stored = Str::uuid()->toString().'.png';
        $path = 'content-media/2026/01/01/'.$stored;
        $this->driver(self::OLD_DISK)->put('attachments', $path, $bytes);

        $row = PageAttachment::query()->create([
            'page_id' => $page?->id,
            'temp_key' => $page === null ? (string) Str::uuid() : null,
            'original_filename' => 'old.png',
            'stored_filename' => $stored,
            'disk' => self::OLD_DISK,
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($bytes),
            'collection' => PageAttachment::COLLECTION_CONTENT_MEDIA,
            'order' => 1,
            'meta' => ['kind' => 'image'],
        ]);

        if ($page !== null) {
            Page::query()->whereKey($page->id)->update(['content' => json_encode(['ko' => $this->img($row->hash)])]);
            $page->refresh();
        }

        return $row;
    }

    /**
     * 본문 미디어 정식 주소 이미지 태그.
     *
     * @param  string  $hash  본문 미디어 해시 (12자)
     * @return string 이미지 태그를 담은 문단 마크업
     */
    private function img(string $hash): string
    {
        return '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash.'"></p>';
    }

    /**
     * 디스크의 페이지 모듈 저장소.
     *
     * @param  string  $disk  디스크
     * @return ModuleStorageDriver 저장소
     */
    private function driver(string $disk): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-page', $disk);
    }
}
