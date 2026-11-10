<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Seeders;

use App\Extension\ModuleManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Database\Seeders\Sample\Issue162MeasureCleanupSeeder;
use Modules\Sirsoft\Page\Database\Seeders\Sample\Issue162MeasureSeeder;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 실측 정리 시더가 페이지 서비스와 같은 저장소에서 파일을 지우는지 검증합니다.
 *
 * 종전 시더는 `new ModuleStorageDriver('sirsoft-page')`(기본 `local` 디스크)로 지워, 서비스가 실제로 쓰는 모듈 저장소
 * (`modules` 디스크)의 파일은 0건 지우고 행만 지웠다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/measure-cleanup-seeder.yaml
 */
class Issue162MeasureCleanupSeederTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 실측 시더 가드를 엽니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 실측 시더 가드를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');

        parent::tearDown();
    }

    /**
     * 실측 페이지의 본문 미디어는 행과 함께 서비스 저장소의 파일까지 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cleanup
     *
     * @effects service_storage_files_deleted
     */
    public function test_cleanup_deletes_files_from_the_service_storage(): void
    {
        $page = Page::query()->create([
            'slug' => Issue162MeasureSeeder::SLUG_PREFIX.Str::lower(Str::random(6)),
            'title' => ['ko' => '정리 대상'],
            'content' => ['ko' => '<p>x</p>'],
            'content_mode' => 'html',
            'published' => false,
        ]);
        $media = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('a.png'), (string) Str::uuid());
        $media->update(['page_id' => $page->id]);

        $storage = app(ModuleManager::class)->getModule('sirsoft-page')->getStorage();
        $this->assertTrue($storage->exists('attachments', $media->path), '전제: 서비스가 올린 파일이 모듈 저장소에 있다');

        $this->seed(Issue162MeasureCleanupSeeder::class);

        $this->assertNull(Page::query()->find($page->id));
        $this->assertNull(PageAttachment::query()->find($media->id));
        $this->assertFalse($storage->exists('attachments', $media->path), '서비스가 쓰는 저장소에서 파일을 지운다');
    }
}
