<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 업로드 탭의 썸네일 설정이 바뀌면 큐 워커에 재시작 신호를 보낸다.
 *
 * 썸네일 미리 만들기·옛 디스크 비우기는 큐 작업이라, 워커가 옛 썸네일 설정으로 계속 돌지 않게 한다.
 * 값이 실제로 바뀐 때만 보낸다 — 다른 업로드 키만 바꾸거나 같은 값을 다시 저장하면 보내지 않는다.
 */
class SettingsServiceThumbnailWorkerRestartTest extends TestCase
{
    /** 지금 저장된 업로드 설정 */
    private const EXISTING = [
        'max_file_size' => 10,
        'image_quality' => 85,
        'thumbnail_enabled' => true,
        'thumbnail_content_preset' => 'lg',
        'thumbnail_disk_public' => 'thumbnails',
        'thumbnail_disk_gated' => 'thumbnails',
        'thumbnail_prune_enabled' => true,
        'thumbnail_animated_original' => true,
    ];

    /**
     * 설정 저장소를 대역으로 둔 서비스를 만들고, 큐 재시작 호출 횟수를 기대값으로 겁니다.
     *
     * @param  int  $restarts  `queue:restart` 호출 기대 횟수
     * @param  mixed  $existingValue  단건 조회(`get`)가 돌려줄 기존 값
     * @return SettingsService 대상 서비스
     */
    private function service(int $restarts, mixed $existingValue = null): SettingsService
    {
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');

        Artisan::shouldReceive('call')->with('config:clear')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('config:cache')->zeroOrMoreTimes();
        Artisan::shouldReceive('call')->with('queue:restart')->times($restarts);

        $configRepository = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();
        $configRepository->shouldReceive('getCategory')->with('upload')->andReturn(self::EXISTING);
        $configRepository->shouldReceive('saveCategory')->andReturn(true);
        $configRepository->shouldReceive('get')->andReturn($existingValue);
        $configRepository->shouldReceive('set')->andReturn(true);

        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();
        $this->mock(CacheInterface::class)->shouldIgnoreMissing();

        return app(SettingsService::class);
    }

    /**
     * 썸네일 키가 바뀌면 재시작 신호를 보낸다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_changed_thumbnail_setting_restarts_queue_workers(): void
    {
        $this->assertTrue($this->service(restarts: 1)->saveSettings([
            '_tab' => 'upload',
            'upload' => [...self::EXISTING, 'thumbnail_content_preset' => 'md'],
        ]));
    }

    /**
     * 켜고 끄는 값은 화면이 보내는 문자열('0')도 바뀐 것으로 본다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_toggled_thumbnail_switch_restarts_queue_workers(): void
    {
        $this->assertTrue($this->service(restarts: 1)->saveSettings([
            '_tab' => 'upload',
            'upload' => [...self::EXISTING, 'thumbnail_enabled' => '0'],
        ]));
    }

    /**
     * 다른 업로드 키만 바뀌면 신호를 보내지 않는다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_other_upload_setting_does_not_restart_queue_workers(): void
    {
        $this->assertTrue($this->service(restarts: 0)->saveSettings([
            '_tab' => 'upload',
            'upload' => [...self::EXISTING, 'max_file_size' => 20, 'image_quality' => 70],
        ]));
    }

    /**
     * 같은 값을 다시 저장하면(형태만 다른 같은 값 포함) 신호를 보내지 않는다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_same_thumbnail_values_do_not_restart_queue_workers(): void
    {
        $this->assertTrue($this->service(restarts: 0)->saveSettings([
            '_tab' => 'upload',
            'upload' => [...self::EXISTING, 'thumbnail_enabled' => '1', 'thumbnail_prune_enabled' => 1],
        ]));
    }

    /**
     * 단건 저장(`PUT /api/admin/settings/{key}`)도 같다 — 썸네일 키가 바뀔 때만 보낸다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_single_key_save_restarts_only_when_a_thumbnail_value_changes(): void
    {
        $this->assertTrue($this->service(restarts: 1, existingValue: 'lg')->setSetting('upload.thumbnail_content_preset', 'md'));
    }

    /**
     * 단건 저장에서 같은 값이거나 썸네일 키가 아니면 보내지 않는다.
     *
     * @scenario surface=save_storage
     *
     * @effects upload_thumbnail_setting_change_restarts_workers
     *
     * @return void 반환값 없음
     */
    public function test_single_key_save_skips_same_value_and_other_keys(): void
    {
        $service = $this->service(restarts: 0, existingValue: 'lg');

        $this->assertTrue($service->setSetting('upload.thumbnail_content_preset', 'lg'));
        $this->assertTrue($service->setSetting('upload.max_file_size', 30));
    }
}
