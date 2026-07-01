<?php

namespace Tests\Unit\Extension;

use App\Extension\UpgradeContext;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * 업그레이드 로그 채널 분리 회귀 테스트 (버그 ③ 로그 영역).
 *
 * 코어 업그레이드(sudo/root 실행)와 확장 업그레이드(php-fpm/www-data 실행)가 같은
 * daily 로그 파일(upgrade-YYYY-MM-DD.log)을 공유하면, root 가 만든 파일에 www-data 가
 * append 하지 못해 "Permission denied" 로 실패한다. UpgradeContext 에 로그 채널을 주입
 * 가능하게 하여 코어는 'upgrade'(logs/upgrade.log), 확장은 'extension-upgrade'
 * (logs/extension-upgrade.log) 로 파일을 분리 — 각 실행 주체가 자기 소유 파일에만 쓰도록
 * 하여 교차 소유권 충돌을 원천 차단한다.
 */
class UpgradeContextLogChannelTest extends TestCase
{
    /**
     * 기본 채널은 'upgrade' — 코어 업그레이드 경로가 사용한다 (기존 동작 보존).
     */
    public function test_default_channel_is_upgrade(): void
    {
        $context = new UpgradeContext(fromVersion: '1.0.0', toVersion: '2.0.0');

        $this->assertSame('upgrade', $context->logChannel);
    }

    /**
     * 확장(모듈/플러그인) 경로는 'extension-upgrade' 채널을 명시 주입한다.
     */
    public function test_extension_channel_can_be_injected(): void
    {
        $context = new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '2.0.0',
            logChannel: 'extension-upgrade',
        );

        $this->assertSame('extension-upgrade', $context->logChannel);
    }

    /**
     * withCurrentStep 은 채널을 승계한다 — step 별 파생 컨텍스트가 원본과 다른 파일에 쓰면
     * 확장 업그레이드 로그가 코어 'upgrade' 파일로 새어 소유권 충돌이 재발한다.
     */
    public function test_with_current_step_preserves_channel(): void
    {
        $context = new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '2.0.0',
            logChannel: 'extension-upgrade',
        );

        $stepContext = $context->withCurrentStep('1.5.0');

        $this->assertSame('extension-upgrade', $stepContext->logChannel);
        $this->assertSame('1.5.0', $stepContext->currentStep);
    }

    /**
     * 두 채널이 서로 다른 daily 로그 파일 경로로 매핑되는지 config 로 검증.
     * 같은 경로면 파일 분리가 무의미해지므로 회귀 가드로 확정한다.
     */
    public function test_core_and_extension_channels_map_to_distinct_files(): void
    {
        $corePath = config('logging.channels.upgrade.path');
        $extensionPath = config('logging.channels.extension-upgrade.path');

        $this->assertNotNull($extensionPath, 'extension-upgrade 채널이 정의되어야 함');
        $this->assertNotSame($corePath, $extensionPath, '코어/확장 로그 파일 경로는 달라야 함');
        $this->assertStringEndsWith('upgrade.log', $corePath);
        $this->assertStringEndsWith('extension-upgrade.log', $extensionPath);
    }

    /**
     * 주입된 채널로 logger 가 실제 LoggerInterface 로 해석되고 예외 없이 로깅되는지 스모크 검증.
     */
    public function test_logger_resolves_and_logs_without_error(): void
    {
        $context = new UpgradeContext(
            fromVersion: '1.0.0',
            toVersion: '2.0.0',
            logChannel: 'extension-upgrade',
        );

        $this->assertInstanceOf(LoggerInterface::class, $context->logger);

        // 예외 없이 로깅 가능해야 함 (채널이 실제로 해석됨).
        $context->logger->info('테스트 로그');
    }
}
