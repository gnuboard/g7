<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\Helpers\SettingsMigrator;
use App\Extension\UpgradeContext;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_0_7_0;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * v0.7.0 업그레이드 스텝 테스트
 *
 * SEO 설정 코어 이관에 따른 User Agent 마이그레이션 및 비이커머스 설정 제거를 검증합니다.
 */
class Upgrade_0_7_0_Test extends TestCase
{
    private Upgrade_0_7_0 $upgradeStep;

    private UpgradeContext $context;

    private ModuleSettingsService $moduleSettings;

    private ConfigRepositoryInterface $coreRepo;

    private LoggerInterface $mockLogger;

    /**
     * 로그 메시지 수집용 배열
     *
     * @var array<string>
     */
    private array $logMessages = [];

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->upgradeStep = new Upgrade_0_7_0;
        $this->logMessages = [];

        // 로거 모킹 - upgrade 채널을 모킹된 로거로 교체
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockLogger->method('info')
            ->willReturnCallback(function (string $message) {
                $this->logMessages[] = $message;
            });
        Log::shouldReceive('channel')
            ->with('upgrade')
            ->andReturn($this->mockLogger);

        // UpgradeContext 생성 (내부에서 Log::channel('upgrade')를 호출)
        $this->context = new UpgradeContext(
            fromVersion: '0.4.0',
            toVersion: '0.5.0',
            currentStep: '0.5.0'
        );

        // ModuleSettingsService 모킹
        $this->moduleSettings = $this->createMock(ModuleSettingsService::class);
        $this->app->instance(ModuleSettingsService::class, $this->moduleSettings);

        // ConfigRepositoryInterface 모킹
        $this->coreRepo = $this->createMock(ConfigRepositoryInterface::class);
        $this->app->instance(ConfigRepositoryInterface::class, $this->coreRepo);
    }

    // ========================================
    // User Agent 마이그레이션 테스트
    // ========================================

    /**
     * 커스텀 UA가 존재할 때 코어 bot_user_agents에 병합되는지 검증
     */
    public function test_custom_user_agents_merged_to_core(): void
    {
        $moduleUa = ['CustomBot/1.0', 'MySpider'];
        $coreUa = ['Googlebot', 'Bingbot'];
        $expectedMerged = ['Googlebot', 'Bingbot', 'CustomBot/1.0', 'MySpider'];

        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn($moduleUa);

        $this->coreRepo->method('get')
            ->with('seo.bot_user_agents', [])
            ->willReturn($coreUa);

        $this->coreRepo->expects($this->once())
            ->method('set')
            ->with('seo.bot_user_agents', $expectedMerged);

        $this->upgradeStep->run($this->context);

        $this->assertNotEmpty(
            array_filter($this->logMessages, fn ($msg) => str_contains($msg, 'Migrated 2 user agents')),
            '로그에 "Migrated 2 user agents" 메시지가 기록되어야 합니다.'
        );
    }

    /**
     * 커스텀 UA가 없을 때 코어 설정이 변경되지 않는지 검증
     */
    public function test_no_custom_user_agents_leaves_core_unchanged(): void
    {
        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn(null);

        $this->coreRepo->expects($this->never())
            ->method('set');

        $this->upgradeStep->run($this->context);

        $this->assertNotEmpty(
            array_filter($this->logMessages, fn ($msg) => str_contains($msg, 'No custom user agents')),
            '로그에 "No custom user agents" 메시지가 기록되어야 합니다.'
        );
    }

    /**
     * 빈 배열 UA 시 코어 설정이 변경되지 않는지 검증
     */
    public function test_empty_user_agents_array_leaves_core_unchanged(): void
    {
        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn([]);

        $this->coreRepo->expects($this->never())
            ->method('set');

        $this->upgradeStep->run($this->context);

        $this->assertNotEmpty($this->logMessages, '로그 메시지가 기록되어야 합니다.');
    }

    /**
     * 중복 UA가 병합 시 중복 제거되는지 검증
     */
    public function test_duplicate_user_agents_are_deduplicated(): void
    {
        $moduleUa = ['Googlebot', 'CustomBot'];
        $coreUa = ['Googlebot', 'Bingbot'];

        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn($moduleUa);

        $this->coreRepo->method('get')
            ->with('seo.bot_user_agents', [])
            ->willReturn($coreUa);

        $this->coreRepo->expects($this->once())
            ->method('set')
            ->with(
                'seo.bot_user_agents',
                $this->callback(function ($merged) {
                    // 중복 제거 후 3개만 남아야 함
                    $this->assertCount(3, $merged);
                    $this->assertContains('Googlebot', $merged);
                    $this->assertContains('Bingbot', $merged);
                    $this->assertContains('CustomBot', $merged);
                    // array_values로 인덱스 재정렬
                    $this->assertEquals(array_values($merged), $merged);

                    return true;
                })
            );

        $this->upgradeStep->run($this->context);

        $this->assertNotEmpty($this->logMessages, '로그 메시지가 기록되어야 합니다.');
    }

    /**
     * 코어에 기존 UA가 없을 때 모듈 UA만 설정되는지 검증
     */
    public function test_module_user_agents_set_when_core_is_empty(): void
    {
        $moduleUa = ['CustomBot/1.0'];

        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn($moduleUa);

        $this->coreRepo->method('get')
            ->with('seo.bot_user_agents', [])
            ->willReturn([]);

        $this->coreRepo->expects($this->once())
            ->method('set')
            ->with('seo.bot_user_agents', ['CustomBot/1.0']);

        $this->upgradeStep->run($this->context);

        $this->assertNotEmpty($this->logMessages, '로그 메시지가 기록되어야 합니다.');
    }

    // ========================================
    // SettingsMigrator 필드 제거 테스트
    // ========================================

    /**
     * 업그레이드 실행 후 로그에 필드 제거 메시지가 기록되는지 검증
     *
     * SettingsMigrator는 정적 팩토리 메서드를 사용하므로 직접 모킹이 불가능합니다.
     * 대신 로그 메시지와 실행 완료를 통해 간접 검증합니다.
     */
    public function test_upgrade_logs_removed_fields_count(): void
    {
        $this->moduleSettings->method('get')
            ->with('sirsoft-ecommerce', 'seo.seo_user_agents')
            ->willReturn(null);

        $this->upgradeStep->run($this->context);

        // info가 최소 2회 호출: migrateUserAgentsToCore + removeNonEcommerceSettings
        $this->assertGreaterThanOrEqual(2, count($this->logMessages), '최소 2개의 로그 메시지가 기록되어야 합니다.');

        $this->assertNotEmpty(
            array_filter($this->logMessages, fn ($msg) => str_contains($msg, 'Removed')),
            '로그에 "Removed" 메시지가 기록되어야 합니다.'
        );
    }
}
