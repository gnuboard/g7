<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Console;

use App\Extension\Storage\PluginStorageDriver;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 미참조 이미지 정리 커맨드 테스트
 *
 * 인수 기준은 "설정을 만지지 않은 사이트에서 자동 삭제 0" 입니다. 스케줄 게이트가
 * 설정 조회 실패 시 true 로 폴백하므로, 커맨드가 `--scheduled` 에서 false 폴백으로
 * 재확인하는지를 회귀로 고정합니다.
 */
class PruneUnusedImagesCommandTest extends PluginTestCase
{
    /**
     * 플러그인 설정 조회를 지정 값으로 고정합니다.
     *
     * 테스트 환경에는 플러그인이 설치돼 있지 않아 PluginSettingsService 가 기본값을
     * 그대로 돌려주므로, 설정 분기를 밟으려면 조회 지점을 대체해야 합니다.
     *
     * @param  array<string, mixed>  $values  설정 키 => 값
     */
    private function fakePluginSettings(array $values): void
    {
        $service = Mockery::mock(PluginSettingsService::class);

        $service->shouldReceive('get')
            ->andReturnUsing(function (string $identifier, ?string $key = null, mixed $default = null) use ($values) {
                return array_key_exists($key, $values) ? $values[$key] : $default;
            });

        $this->app->instance(PluginSettingsService::class, $service);
    }

    /**
     * 미참조 업로드 1건(파일 포함)을 만듭니다.
     *
     * @param  int  $ageDays  업로드 경과 일수
     * @return Ckeditor5ImageUpload 생성된 기록
     */
    private function makeStaleUpload(int $ageDays = 40): Ckeditor5ImageUpload
    {
        $filename = uniqid('cmd-').'.png';
        (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))->put('images', "2026/08/14/{$filename}", 'bytes');

        $upload = Ckeditor5ImageUpload::create([
            'original_name' => $filename,
            'file_path' => "images/2026/08/14/{$filename}",
            'storage_disk' => 'plugins',
            'file_size' => 5,
            'mime_type' => 'image/png',
        ]);

        $upload->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $upload->refresh();
    }

    /**
     * 설정을 만지지 않은 사이트(설정 키 부재) 에서 스케줄 호출은 아무것도 지우지 않아야 한다.
     *
     * @effects schedule_gate_off_by_default
     */
    #[Test]
    public function scheduled_run_deletes_nothing_when_the_setting_was_never_touched(): void
    {
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --scheduled')
            ->assertSuccessful();

        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects schedule_gate_off_explicit
     */
    #[Test]
    public function scheduled_run_stops_early_when_the_toggle_is_off(): void
    {
        $this->fakePluginSettings(['unusedImageCleanup' => false]);
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --scheduled')
            ->assertSuccessful();

        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects schedule_gate_on
     */
    #[Test]
    public function scheduled_run_prunes_when_the_toggle_is_on(): void
    {
        $this->fakePluginSettings([
            'unusedImageCleanup' => true,
            'unusedImageRetentionDays' => 30,
        ]);
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --scheduled')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects retention_disabled_guard
     */
    #[Test]
    public function retention_below_one_day_performs_no_cleanup(): void
    {
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --days=0')
            ->assertSuccessful();

        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects days_option_overrides_setting
     */
    #[Test]
    public function days_option_overrides_the_configured_retention(): void
    {
        $this->fakePluginSettings(['unusedImageRetentionDays' => 365]);
        $upload = $this->makeStaleUpload(ageDays: 40);

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --days=7')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects manual_run_dry_run_no_delete
     */
    #[Test]
    public function dry_run_lists_targets_without_deleting(): void
    {
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * 수동 실행(스케줄 아님)은 토글과 무관하게 운영자 의사이므로 그대로 수행한다.
     *
     * @effects manual_run_ignores_toggle
     */
    #[Test]
    public function manual_run_prunes_regardless_of_the_schedule_toggle(): void
    {
        $this->fakePluginSettings(['unusedImageCleanup' => false, 'unusedImageRetentionDays' => 30]);
        $upload = $this->makeStaleUpload();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * 실행 결과는 로그에 남긴다.
     *
     * 스케줄 실행은 아무도 보고 있지 않으므로, 무엇이 몇 건 지워졌는지가 로그에 남지 않으면
     * 사후에 확인할 방법이 없다.
     *
     * @effects prune_logs_run_summary
     */
    #[Test]
    public function a_real_run_records_its_summary_in_the_log(): void
    {
        $this->fakePluginSettings(['unusedImageCleanup' => true, 'unusedImageRetentionDays' => 30]);
        $this->makeStaleUpload();

        Log::shouldReceive('info')
            ->once()
            ->with('PruneUnusedImagesCommand: 미참조 에디터 이미지 정리 완료', Mockery::on(function (array $context): bool {
                return ($context['deleted'] ?? null) === 1
                    && ($context['failed'] ?? null) === 0
                    && ($context['days'] ?? null) === 30;
            }));

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --scheduled')
            ->assertSuccessful();
    }

    /**
     * dry-run 은 아무것도 지우지 않으므로 완료 로그도 남기지 않는다.
     *
     * @effects prune_dry_run_no_delete
     */
    #[Test]
    public function a_dry_run_does_not_record_a_completion_log(): void
    {
        $this->fakePluginSettings(['unusedImageRetentionDays' => 30]);
        $this->makeStaleUpload();

        Log::shouldReceive('info')
            ->with('PruneUnusedImagesCommand: 미참조 에디터 이미지 정리 완료', Mockery::any())
            ->never();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --dry-run')
            ->assertSuccessful();
    }
}
