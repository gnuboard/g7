<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Jobs\GenerateThumbnailsJob;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 등록 시 미리 생성 테스트 (D12)
 *
 * 드라이버 `put` 직후 원본 제공자로 분류해, 「등록 시 미리 생성」이 켜진 규격이 있으면 예약한다.
 * 큐가 `sync` 면 응답 뒤(`terminating`)에 만들고 — 업로드 응답을 늦추지 않는다 — 큐가 있으면
 * `GenerateThumbnailsJob` 을 넣는다. 규격이 없거나 어느 제공자 원본도 아니면 아무 것도 하지 않는다.
 */
class ThumbnailPregenerateOnStoreTest extends TestCase
{
    /**
     * 디스크를 가짜로 바꾸고 sm 규격만 미리 생성을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('thumbnails');
        Storage::fake('modules');
        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.image_quality', 85);
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.presets', [['name' => 'sm', 'pregenerate' => true]]);
        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * 임시 이미지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * sm 파생본 키
     *
     * @param  string  $disk  원본 디스크
     * @param  string  $path  원본 전체 경로
     * @return string 키
     */
    private function smKey(string $disk, string $path): string
    {
        $spec = app(ThumbnailPresetRegistry::class)->find('sm');

        return ThumbnailStore::ROOT."/{$disk}/{$path}/sm_".$spec->fingerprint('gd').'.jpg';
    }

    /**
     * 큐가 sync 면 저장 직후가 아니라 응답 뒤(terminating)에 만든다.
     *
     * @effects pregenerated_after_response_on_sync_queue
     *
     * @return void 반환값 없음
     */
    public function test_sync_queue_generates_after_the_response(): void
    {
        Config::set('queue.default', 'sync');
        $path = '2026/11/05/pre.jpg';

        (new CoreStorageDriver('attachments'))->put('', $path, (string) file_get_contents(TestImages::solid(800, 600, 'jpeg')));

        Storage::disk('thumbnails')->assertMissing($this->smKey('attachments', $path));

        $this->app->terminate();

        Storage::disk('thumbnails')->assertExists($this->smKey('attachments', $path));
        $this->assertSame([160, 160], array_slice(getimagesizefromstring(Storage::disk('thumbnails')->get($this->smKey('attachments', $path))), 0, 2));
    }

    /**
     * 큐가 있으면 작업을 넣는다 — 코어 첨부는 게이트 원본으로, 레이아웃 첨부는 공개 원본으로.
     *
     * @effects pregenerate_job_dispatched_on_real_queue
     *
     * @return void 반환값 없음
     */
    public function test_real_queue_dispatches_the_job(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();
        $bytes = (string) file_get_contents(TestImages::solid(800, 600, 'jpeg'));
        $storage = new CoreStorageDriver('attachments');

        $storage->put('', '2026/11/05/core.jpg', $bytes);
        $storage->put('template-layout-attachments', 'sirsoft-basic/2026/11/05/layout.jpg', $bytes);

        Queue::assertPushed(GenerateThumbnailsJob::class, 2);
        Queue::assertPushed(GenerateThumbnailsJob::class, fn (GenerateThumbnailsJob $job) => $job->disk === 'attachments'
            && $job->path === '2026/11/05/core.jpg' && $job->mime === 'image/jpeg' && $job->gated === true
            && $job->uniqueId() === 'attachments|2026/11/05/core.jpg');
        Queue::assertPushed(GenerateThumbnailsJob::class, fn (GenerateThumbnailsJob $job) => $job->path === 'template-layout-attachments/sirsoft-basic/2026/11/05/layout.jpg'
            && $job->gated === false);
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '큐가 있는데 요청 안에서 만들었다');
    }

    /**
     * 「비공개 첨부 저장소」로 고른 디스크에 올린 코어 첨부도 코어 첨부 원본(게이트)으로 분류해 작업을 넣는다 .
     *
     * 업로드 디스크는 `PrivateAttachmentDisk::forCore()` 가 정한다. 원본 제공자가 종전 `attachment.disk` 하나만 알면
     * 고른 디스크의 새 첨부가 「모르는 원본」이 되어 미리 생성이 조용히 빠진다.
     *
     * @effects pregenerate_job_dispatched_on_real_queue
     *
     * @return void 반환값 없음
     */
    public function test_core_attachment_on_selected_private_disk_dispatches_the_job(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();
        // 해석기는 `filesystems.disks` 에 정의된 디스크만 선택으로 인정한다(미정의 = 꺼진 플러그인 디스크)
        Config::set('filesystems.disks.fx916-vault', ['driver' => 'local', 'root' => storage_path('framework/testing/fx916-vault')]);
        Storage::fake('fx916-vault');
        Config::set('attachment.disk_explicit', null);
        Config::set('core.storage.private_attachment_disk', 'fx916-vault');
        $bytes = (string) file_get_contents(TestImages::solid(800, 600, 'jpeg'));

        (new CoreStorageDriver('attachments'))->withDisk('fx916-vault')->put('', '2026/11/16/vault.jpg', $bytes);

        Queue::assertPushed(GenerateThumbnailsJob::class, fn (GenerateThumbnailsJob $job) => $job->disk === 'fx916-vault'
            && $job->path === '2026/11/16/vault.jpg' && $job->gated === true);
    }

    /**
     * 작업을 실행하면 미리 생성 규격만 만든다.
     *
     * @effects pregenerate_job_dispatched_on_real_queue
     *
     * @return void 반환값 없음
     */
    public function test_job_generates_the_pregenerate_presets(): void
    {
        $path = '2026/11/05/job.jpg';
        Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::solid(800, 600, 'jpeg')));

        app()->call([new GenerateThumbnailsJob('attachments', $path, 'image/jpeg', true), 'handle']);

        $this->assertSame([$this->smKey('attachments', $path)], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 미리 생성 규격이 없거나, 기능이 꺼졌거나, 어느 제공자 원본도 아니거나, 래스터가 아니면 예약하지 않는다.
     *
     * @effects pregenerate_skipped_without_preset_or_provider
     *
     * @return void 반환값 없음
     */
    public function test_nothing_is_scheduled_when_not_applicable(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();
        $bytes = (string) file_get_contents(TestImages::solid(400, 400, 'jpeg'));
        $storage = new CoreStorageDriver('attachments');

        // 어느 제공자 원본도 아님 — 임시 업로드·모듈 디스크의 모르는 경로
        $storage->put('temp', 'upload.jpg', $bytes);
        (new ModuleStorageDriver('g7test-thumbpre', 'modules'))->put('images', 'a.jpg', $bytes);
        // 래스터가 아님
        $storage->put('', '2026/11/05/doc.pdf', 'pdf');
        Queue::assertNothingPushed();

        // 미리 생성 규격 없음
        Config::set('core.thumbnail.presets', null);
        $storage->put('', '2026/11/05/a.jpg', $bytes);
        Queue::assertNothingPushed();

        // 기능 꺼짐
        Config::set('core.thumbnail.presets', [['name' => 'sm', 'pregenerate' => true]]);
        Config::set('core.thumbnail.enabled', false);
        $storage->put('', '2026/11/05/b.jpg', $bytes);
        Queue::assertNothingPushed();
    }
}
