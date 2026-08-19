<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageCleanupService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageReferenceScanService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 미참조 업로드 이미지 정리 서비스 테스트
 *
 * "파일 먼저, 기록 나중" 순서와 행 disk 존중, 참조 보존(두 URL 변종), dry-run 무삭제를
 * 회귀로 고정합니다.
 */
class ImageCleanupServiceTest extends PluginTestCase
{
    private ImageCleanupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);

        Ckeditor5ImageUpload::flushStorageCache();

        $this->service = app(ImageCleanupService::class);
    }

    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();

        parent::tearDown();
    }

    /**
     * 파일과 기록을 함께 만듭니다.
     *
     * @param  string  $hash  이미지 해시
     * @param  string  $filename  저장 파일명
     * @param  int  $ageDays  업로드 경과 일수
     * @param  string  $disk  스토리지 디스크
     * @param  bool  $withFile  실제 파일 생성 여부
     * @return Ckeditor5ImageUpload 생성된 기록
     */
    private function makeUpload(
        string $hash,
        string $filename,
        int $ageDays = 40,
        string $disk = 'plugins',
        bool $withFile = true,
    ): Ckeditor5ImageUpload {
        $path = "2026/08/14/{$filename}";

        if ($withFile) {
            (new PluginStorageDriver('sirsoft-ckeditor5', $disk))->put('images', $path, 'image-bytes');
        }

        $upload = Ckeditor5ImageUpload::create([
            'hash' => $hash,
            'original_name' => $filename,
            'file_path' => "images/{$path}",
            'storage_disk' => $disk,
            'file_size' => 11,
            'mime_type' => 'image/png',
        ]);

        $upload->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $upload->refresh();
    }

    /**
     * 코어 참조 소스에 본문을 심습니다.
     *
     * @param  string  $content  본문
     */
    private function seedReference(string $content): void
    {
        DB::table('mail_templates')->insert([
            'type' => 'cleanup-test-'.uniqid(),
            'subject' => json_encode(['ko' => '정리 테스트']),
            'body' => $content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @scenario age=past_retention, disk_state=default_local, url_variant=absent
     *
     * @effects prune_deletes_file_and_record
     */
    #[Test]
    public function unreferenced_upload_past_retention_loses_both_file_and_record(): void
    {
        $upload = $this->makeUpload('aaaaaaaaaaaa', 'orphan.png');
        $storage = new PluginStorageDriver('sirsoft-ckeditor5', 'plugins');

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(1, $result['deleted']);
        $this->assertFalse($storage->exists('images', '2026/08/14/orphan.png'));
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @scenario age=past_retention, disk_state=default_local, url_variant=hash_api_fallback
     *
     * @effects prune_keeps_referenced_hash_form
     */
    #[Test]
    public function referenced_by_hash_url_is_kept(): void
    {
        $upload = $this->makeUpload('bbbbbbbbbbbb', 'used-hash.png');
        $this->seedReference('<img src="/api/plugins/sirsoft-ckeditor5/images/bbbbbbbbbbbb">');

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['referenced']);
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @scenario age=past_retention, disk_state=default_local, url_variant=direct_url_uuid
     *
     * @effects prune_keeps_referenced_direct_url
     */
    #[Test]
    public function referenced_by_direct_disk_url_is_kept(): void
    {
        $upload = $this->makeUpload('cccccccccccc', 'used-direct.png');
        $this->seedReference('<img src="https://cdn.test/assets/ckeditor5/images/2026/08/14/used-direct.png">');

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(0, $result['deleted']);
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @scenario age=within_retention, disk_state=default_local, url_variant=absent
     *
     * @effects prune_keeps_within_retention
     */
    #[Test]
    public function upload_within_retention_is_not_a_candidate(): void
    {
        $upload = $this->makeUpload('dddddddddddd', 'fresh.png', ageDays: 3);

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(0, $result['scanned']);
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects prune_dry_run_no_delete
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $upload = $this->makeUpload('eeeeeeeeeeee', 'dry.png');
        $storage = new PluginStorageDriver('sirsoft-ckeditor5', 'plugins');

        $result = $this->service->pruneUnused(30, 100, dryRun: true);

        $this->assertSame(0, $result['deleted']);
        $this->assertCount(1, $result['items']);
        $this->assertTrue($storage->exists('images', '2026/08/14/dry.png'));
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects prune_limit_oldest_first
     */
    #[Test]
    public function limit_processes_the_oldest_candidates_first(): void
    {
        $oldest = $this->makeUpload('111111111111', 'oldest.png', ageDays: 90);
        $newer = $this->makeUpload('222222222222', 'newer.png', ageDays: 40);

        $result = $this->service->pruneUnused(30, 1);

        $this->assertSame(1, $result['scanned']);
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $oldest->id]);
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $newer->id]);
    }

    /**
     * 파일이 이미 없는 행은 고아 기록이므로 기록만 지운다 (재시도 루프 방지).
     *
     * @scenario age=past_retention, disk_state=file_missing, url_variant=absent
     *
     * @effects prune_missing_file_removes_record
     */
    #[Test]
    public function record_without_a_file_is_still_removed(): void
    {
        $upload = $this->makeUpload('333333333333', 'ghost.png', withFile: false);

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(1, $result['deleted']);
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * 행마다 disk 가 다른 혼재 상태에서도 그 행의 저장 위치를 향해 지워야 한다.
     *
     * @scenario age=past_retention, disk_state=secondary_disk, url_variant=absent
     *
     * @effects prune_respects_row_disk
     */
    #[Test]
    public function deletion_follows_the_disk_recorded_on_each_row(): void
    {
        $local = $this->makeUpload('444444444444', 'on-local.png');
        $cdn = $this->makeUpload('555555555555', 'on-cdn.png', disk: 'fake_cdn');

        $result = $this->service->pruneUnused(30, 100);

        $this->assertSame(2, $result['deleted']);
        $this->assertFalse(
            (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))->exists('images', '2026/08/14/on-local.png')
        );
        $this->assertFalse(
            (new PluginStorageDriver('sirsoft-ckeditor5', 'fake_cdn'))->exists('images', '2026/08/14/on-cdn.png')
        );
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $local->id]);
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $cdn->id]);
    }

    /**
     * @effects delete_upload_shared_by_ui_and_command
     */
    #[Test]
    public function delete_upload_removes_file_and_record_for_a_single_row(): void
    {
        $upload = $this->makeUpload('666666666666', 'single.png', ageDays: 1);
        $storage = new PluginStorageDriver('sirsoft-ckeditor5', 'plugins');

        $this->assertTrue($this->service->deleteUpload($upload));
        $this->assertFalse($storage->exists('images', '2026/08/14/single.png'));
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * 파일 삭제가 실패하면 기록을 남긴다.
     *
     * 파일이 남아 있는데 기록만 지우면 그 파일을 가리키는 것이 아무것도 없어져 다음 회차에도
     * 회수 대상이 되지 못한다. 실패는 failed 로 계상하고 다음 회차에 재시도한다.
     *
     * @scenario age=past_retention, disk_state=default_local, url_variant=absent
     *
     * @effects prune_delete_failure_keeps_record
     */
    #[Test]
    public function file_delete_failure_keeps_the_record_and_counts_as_failed(): void
    {
        $upload = $this->makeUpload('777777777777', 'locked.png');

        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('getDisk')->andReturn('local');
        $storage->shouldReceive('withDisk')->andReturnSelf();
        $storage->shouldReceive('exists')->andReturnTrue();
        $storage->shouldReceive('delete')->andReturnFalse();

        $service = new ImageCleanupService(
            app(ImageUploadRepositoryInterface::class),
            app(ImageReferenceScanService::class),
            $storage,
        );

        $result = $service->pruneUnused(30, 100);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['deleted']);
        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }
}
