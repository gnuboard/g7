<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Console;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Plugin;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 고아 임시 이미지 정리 명령 테스트 (매니페스트 temp-image-prune.yaml)
 *
 * 이커머스 임시 상품 이미지 정리와 같은 방식 — 기본 2일, 최대 500건, dry-run, 보존 1일 미만이면 아무것도 지우지 않음.
 */
class PruneTempImagesCommandTest extends PluginTestCase
{
    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크, 시각 고정.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        Carbon::setTestNow(Carbon::parse('2026-12-10 00:00:00', 'UTC'));
    }

    /**
     * 테스트 정리 — 고정 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 2일보다 오래된 연결 안 된 임시 이미지는 행과 파일을 지운다.
     *
     * @scenario surface=removes_old_unlinked_temp_images_and_files
     *
     * @effects orphan_files_removed, prune_result_logged
     *
     * @return void 반환값 없음
     */
    public function test_removes_old_unlinked_temp_images_and_files(): void
    {
        $old = $this->tempImage(now()->subDays(3));

        $this->artisan('sirsoft-hero_section:prune-temp-images')->assertSuccessful()
            ->expectsOutputToContain(__('sirsoft-hero_section::messages.console.prune_temp_images.done', ['days' => 2, 'scanned' => 1, 'deleted' => 1, 'failed' => 0]));

        $this->assertNull(BannerImage::query()->find($old->id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$old->path);
    }

    /**
     * 보존 기간 안의 임시 이미지는 남긴다.
     *
     * @scenario surface=keeps_recent_temp_images
     *
     * @effects orphan_files_removed
     *
     * @return void 반환값 없음
     */
    public function test_keeps_recent_temp_images(): void
    {
        $recent = $this->tempImage(now()->subDay());

        $this->artisan('sirsoft-hero_section:prune-temp-images')->assertSuccessful();

        $this->assertNotNull(BannerImage::query()->find($recent->id));
        $this->imageStorage()->assertExists('sirsoft-hero_section/images/'.$recent->path);
    }

    /**
     * 배너에 연결된 이미지는 오래돼도 남긴다.
     *
     * @scenario surface=keeps_images_linked_to_banners
     *
     * @effects orphan_files_removed
     *
     * @return void 반환값 없음
     */
    public function test_keeps_images_linked_to_banners(): void
    {
        $banner = Banner::factory()->withImages()->create();
        BannerImage::query()->where('banner_id', $banner->id)->update(['created_at' => now()->subDays(30)]);

        $this->artisan('sirsoft-hero_section:prune-temp-images')->assertSuccessful();

        $this->assertSame(1, BannerImage::query()->where('banner_id', $banner->id)->count());
    }

    /**
     * dry-run 은 세기만 하고 지우지 않는다.
     *
     * @scenario surface=dry_run_deletes_nothing
     *
     * @effects prune_result_logged
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->tempImage(now()->subDays(5));

        $this->artisan('sirsoft-hero_section:prune-temp-images', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN]');

        $this->assertNotNull(BannerImage::query()->find($old->id));
        $this->imageStorage()->assertExists('sirsoft-hero_section/images/'.$old->path);
    }

    /**
     * 보존 기간이 1일 미만이면 방금 올린 것까지 지우지 않도록 아무것도 하지 않는다.
     *
     * @scenario surface=days_below_one_is_noop
     *
     * @effects prune_result_logged
     *
     * @return void 반환값 없음
     */
    public function test_days_below_one_is_noop(): void
    {
        $old = $this->tempImage(now()->subDays(5));

        $this->artisan('sirsoft-hero_section:prune-temp-images', ['--days' => 0])->assertSuccessful()
            ->expectsOutputToContain(__('sirsoft-hero_section::messages.console.prune_temp_images.retention_disabled'));

        $this->assertNotNull(BannerImage::query()->find($old->id));
    }

    /**
     * 한 번에 limit 건까지만 지운다.
     *
     * @scenario surface=respects_limit
     *
     * @effects orphan_files_removed
     *
     * @return void 반환값 없음
     */
    public function test_respects_limit(): void
    {
        foreach (range(1, 3) as $i) {
            $this->tempImage(now()->subDays(3 + $i));
        }

        $this->artisan('sirsoft-hero_section:prune-temp-images', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(1, BannerImage::query()->whereNull('banner_id')->count());
    }

    /**
     * 파일 삭제가 실패하면 행을 남겨 다음 회차에 다시 시도하고 failed 로 센다.
     *
     * @scenario surface=file_delete_failure_keeps_row
     *
     * @effects prune_result_logged
     *
     * @return void 반환값 없음
     */
    public function test_file_delete_failure_keeps_row(): void
    {
        $old = $this->tempImage(now()->subDays(3));
        $storage = \Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('delete')->andReturn(false);
        $service = new BannerImageService(app(BannerImageRepositoryInterface::class), $storage);

        $result = $service->pruneTempUploads(2, 500, false);

        $this->assertSame(['scanned' => 1, 'deleted' => 0, 'failed' => 1], $result);
        $this->assertNotNull(BannerImage::query()->find($old->id));
    }

    /**
     * 플러그인이 매일 0시 정리 스케줄을 선언한다.
     *
     * @scenario surface=schedule_registered_daily
     *
     * @effects prune_result_logged
     *
     * @return void 반환값 없음
     */
    public function test_schedule_registered_daily(): void
    {
        $schedules = (new Plugin(['identifier' => 'sirsoft-hero_section', 'vendor' => 'sirsoft', 'version' => '1.0.0']))->getSchedules();

        $this->assertCount(1, $schedules);
        $this->assertSame('sirsoft-hero_section:prune-temp-images', $schedules[0]['command']);
        $this->assertSame('daily', $schedules[0]['frequency']);
        $this->assertSame('0 0 * * *', $schedules[0]['expression']);
    }

    /**
     * 파일이 있는 임시 이미지를 만듭니다.
     *
     * @param  Carbon  $createdAt  올린 시각
     * @return BannerImage 임시 이미지
     */
    private function tempImage(Carbon $createdAt): BannerImage
    {
        $image = BannerImage::factory()->temp('tmp-'.uniqid())->create(['path' => '2026/12/'.uniqid().'.jpg']);
        $image->forceFill(['created_at' => $createdAt])->save();
        $this->imageStorage()->put('sirsoft-hero_section/images/'.$image->path, 'x');

        return $image->fresh();
    }
}
