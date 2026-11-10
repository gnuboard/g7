<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seeders;

use App\Extension\ModuleManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Issue162MeasureCleanupSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Issue162MeasureSeeder;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\PostContentMediaService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 실측 정리 시더가 게시판 서비스와 같은 저장소에서 파일을 지우는지 검증합니다.
 *
 * 종전 시더는 `new ModuleStorageDriver('sirsoft-board')`(기본 `local` 디스크)로 지워, 서비스가 실제로 쓰는 모듈 저장소의
 * 파일은 지우지 못하고 행만 지웠다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/measure-cleanup-seeder.yaml
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
     * 실측 회원이 남긴 연결 전 본문 미디어는 행과 함께 서비스 저장소의 파일까지 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cleanup
     *
     * @effects member_temp_media_files_deleted
     */
    public function test_cleanup_deletes_member_temp_media_from_the_service_storage(): void
    {
        $board = Board::factory()->create(['slug' => 'cleanup-'.Str::lower(Str::random(6))]);
        $member = User::factory()->create(['email' => Issue162MeasureSeeder::MEMBER_EMAILS[0]]);
        $this->actingAs($member);

        $media = app(PostContentMediaService::class)->upload($board->slug, UploadedFile::fake()->image('a.png'), (string) Str::uuid());

        $storage = app(ModuleManager::class)->getModule('sirsoft-board')->getStorage();
        $this->assertTrue($storage->exists('attachments', $media->path), '전제: 서비스가 올린 파일이 모듈 저장소에 있다');

        $this->seed(Issue162MeasureCleanupSeeder::class);

        $this->assertNull(Attachment::withTrashed()->find($media->id));
        $this->assertFalse($storage->exists('attachments', $media->path), '서비스가 쓰는 저장소에서 파일을 지운다');
        $this->assertNull(User::query()->find($member->id), '실측 회원도 지운다');
    }
}
