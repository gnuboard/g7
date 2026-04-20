<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * SettingsService 설정 저장 시 config:clear 자동 실행 테스트
 *
 * 환경설정 저장 후 Laravel 설정 캐시를 자동으로 클리어하여
 * config:cache 상태에서도 변경사항이 즉시 반영되는지 검증합니다.
 */
class SettingsServiceConfigClearTest extends TestCase
{
    private function mockDependencies(array $configExpectations): SettingsService
    {
        $configRepository = $this->mock(ConfigRepositoryInterface::class)->shouldIgnoreMissing();

        foreach ($configExpectations as $expectation) {
            $configRepository->shouldReceive($expectation['method'])
                ->with(...$expectation['args'])
                ->andReturn($expectation['return']);
        }

        $this->mock(AttachmentRepositoryInterface::class)->shouldIgnoreMissing();

        return app(SettingsService::class);
    }

    /**
     * 일반 탭 설정 저장 시 config:clear가 호출되는지 테스트합니다.
     */
    public function test_save_settings_calls_config_clear(): void
    {
        Artisan::shouldReceive('call')
            ->with('config:clear')
            ->once();

        $this->mock(CacheInterface::class)
            ->shouldReceive('forget')
            ->with('settings.system')
            ->once()
            ->andReturn(true);

        $service = $this->mockDependencies([
            ['method' => 'getCategory', 'args' => ['mail'], 'return' => []],
            ['method' => 'saveCategory', 'args' => ['mail', \Mockery::type('array')], 'return' => true],
        ]);

        $result = $service->saveSettings([
            '_tab' => 'mail',
            'mail' => ['mailer' => 'smtp'],
        ]);

        $this->assertTrue($result);
    }

    /**
     * advanced 탭 설정 저장 시 config:clear가 호출되는지 테스트합니다.
     */
    public function test_save_advanced_settings_calls_config_clear(): void
    {
        Artisan::shouldReceive('call')
            ->with('config:clear')
            ->once();

        $this->mock(CacheInterface::class)
            ->shouldReceive('forget')
            ->with('settings.system')
            ->once()
            ->andReturn(true);

        $service = $this->mockDependencies([
            ['method' => 'getCategory', 'args' => ['cache'], 'return' => []],
            ['method' => 'saveCategory', 'args' => ['cache', \Mockery::type('array')], 'return' => true],
        ]);

        $result = $service->saveSettings([
            '_tab' => 'advanced',
            'advanced' => ['enabled' => true],
        ]);

        $this->assertTrue($result);
    }

    /**
     * 설정 저장 실패 시 config:clear가 호출되지 않는지 테스트합니다.
     */
    public function test_save_settings_does_not_call_config_clear_on_failure(): void
    {
        Artisan::shouldReceive('call')
            ->with('config:clear')
            ->never();

        $this->mock(CacheInterface::class)
            ->shouldReceive('forget')
            ->with('settings.system')
            ->never();

        $service = $this->mockDependencies([
            ['method' => 'getCategory', 'args' => ['mail'], 'return' => []],
            ['method' => 'saveCategory', 'args' => ['mail', \Mockery::type('array')], 'return' => false],
        ]);

        $result = $service->saveSettings([
            '_tab' => 'mail',
            'mail' => ['mailer' => 'smtp'],
        ]);

        $this->assertFalse($result);
    }
}
