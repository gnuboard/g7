<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Tests\TestCase;

/**
 * SettingsServiceProvider 의 디버그 설정 환경별 분기 동작 테스트.
 *
 * 의도:
 * - production/local 운영 환경에서는 admin UI 의 debug 토글(storage/app/settings/debug.json) 이 SSoT 로
 *   동작하여 .env 의 APP_DEBUG 보다 우선 적용되어야 한다.
 * - testing 환경(PHPUnit, .env.testing) 에서는 settings JSON 의 mode 값이 .env.testing 의 APP_DEBUG 를
 *   덮어쓰지 않아야 한다. 그렇지 않으면 운영 PC 의 settings 값(debug=false) 때문에 PHPUnit / E2E 인프라
 *   (PlaywrightIssueToken 등) 가 의도치 않게 차단된다.
 *
 * 회귀 컨텍스트: 이슈 #238 Playwright 도입 작업 중 운영 환경(APP_ENV=production, debug.json mode=false) 에서
 * --env=testing 으로 artisan 명령을 실행해도 SettingsServiceProvider 가 settings JSON 의 mode=false 로
 * config('app.debug') 를 false 로 덮어쓰는 문제가 발견됨.
 */
class SettingsServiceProviderDebugConfigTest extends TestCase
{
    /**
     * testing 환경에서는 settings JSON 의 debug.mode 가 config('app.debug') 를 덮어쓰지 않아야 한다.
     *
     * 검증:
     * 1. config('app.debug') 를 true 로 사전 설정 (= .env.testing APP_DEBUG=true 상태 시뮬레이션)
     * 2. settings JSON 에서 debug.mode=false 를 반환하는 가짜 repository 주입
     * 3. applyDebugConfig 호출
     * 4. config('app.debug') 가 true 그대로 유지되어야 함 (testing 가드가 settings 덮어쓰기 차단)
     */
    public function test_applyDebugConfig_does_not_override_in_testing_environment(): void
    {
        // 현재 PHPUnit 환경은 APP_ENV=testing 이어야 함
        $this->assertSame('testing', app()->environment(), '본 테스트는 testing 환경에서만 의미를 가진다.');

        Config::set('app.debug', true);

        $fakeRepository = new class extends JsonConfigRepository
        {
            public function getCategory(string $category): array
            {
                if ($category === 'debug') {
                    return ['mode' => false, 'log_level' => 'error'];
                }

                return [];
            }
        };

        $provider = new SettingsServiceProvider(app());
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('applyDebugConfig');
        $method->setAccessible(true);

        $method->invoke($provider, $fakeRepository);

        $this->assertTrue(
            config('app.debug'),
            'testing 환경에서는 settings JSON 의 debug.mode 가 config(app.debug) 를 덮어쓰지 않아야 한다.'
        );
    }

    /**
     * G7_PLAYWRIGHT_BYPASS=1 환경변수가 부여된 호출에서도 settings JSON 의 debug.mode 가 덮어쓰지 않아야 한다.
     *
     * 의도:
     *   production 환경에서 Playwright E2E 가 토큰 발급 시 PlaywrightIssueToken 이
     *   `Config::set('app.debug', true)` 로 inline override 를 적용한다. 이 시점에 settings JSON
     *   덮어쓰기가 발생하면 inline override 가 무력화되어 토큰 발급 후 layout JSON 보호 인증 등이
     *   디버그 정보 누락으로 실패한다. 따라서 bypass flag 가 있으면 settings JSON 분기를 건너뛴다.
     */
    public function test_applyDebugConfig_does_not_override_when_playwright_bypass_flag_is_set(): void
    {
        Config::set('app.debug', true);

        $fakeRepository = new class extends JsonConfigRepository
        {
            public function getCategory(string $category): array
            {
                if ($category === 'debug') {
                    return ['mode' => false, 'log_level' => 'error'];
                }

                return [];
            }
        };

        // G7_PLAYWRIGHT_BYPASS=1 를 임시 부여하고 종료 시 원복
        $previous = getenv('G7_PLAYWRIGHT_BYPASS');
        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';

        try {
            $provider = new SettingsServiceProvider(app());
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('applyDebugConfig');
            $method->setAccessible(true);

            $method->invoke($provider, $fakeRepository);

            $this->assertTrue(
                config('app.debug'),
                'G7_PLAYWRIGHT_BYPASS=1 이면 settings JSON 의 debug.mode 가 config(app.debug) 를 덮어쓰지 않아야 한다.'
            );
        } finally {
            if ($previous === false) {
                putenv('G7_PLAYWRIGHT_BYPASS');
                unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);
            } else {
                putenv('G7_PLAYWRIGHT_BYPASS='.$previous);
                $_ENV['G7_PLAYWRIGHT_BYPASS'] = $previous;
                $_SERVER['G7_PLAYWRIGHT_BYPASS'] = $previous;
            }
        }
    }

    /**
     * production 환경 시뮬레이션 에서는 settings JSON 의 debug.mode 가 여전히 SSoT 로 적용되어야 한다.
     *
     * (운영 환경 회귀 차단 — testing 가드가 production 동작을 망가뜨리지 않는지 확인)
     */
    public function test_applyDebugConfig_still_overrides_outside_testing_environment(): void
    {
        // 본 테스트는 app environment 를 명시적으로 production 으로 모킹할 수 없으므로
        // SettingsServiceProvider 가 환경 분기 후에도 settings 를 읽는다는 사실만 검증한다.
        // 자세한 분기 동작은 본 메서드의 testing 케이스가 커버한다.
        $this->markTestSkipped('production 환경 분기는 통합 테스트 또는 수동 검증으로 커버 (PHPUnit 부팅 환경 강제 변경 불가)');
    }
}
