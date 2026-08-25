<?php

namespace Tests\Unit\Execution;

use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Execution\InProcessCommandRunner;
use App\Execution\SubprocessCommandRunner;
use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\SkipsWhenExtensionTablesAreMissing;
use Tests\TestCase;

/**
 * 실행 모드 해석기(CommandRunnerResolver) 단위 테스트.
 *
 * 판정 우선순위(명시 → 설정값 → 환경 감지)와 fail-safe(불확실 → subprocess),
 * 그리고 기본값 불변(설정 없는 기존 환경 = subprocess)을 고정한다.
 *
 * 커버 축: php_binary_source detected_autodetect·configured / requested_mode none·auto·subprocess·inprocess / saved_setting absent·auto·subprocess·inprocess·invalid
 *
 * @effects detected_php_binary_reaches_subprocess_runner, explicit_subprocess_uses_configured_binary, default_resolves_to_subprocess_without_any_setting, auto_follows_detection_to_inprocess, explicit_request_overrides_everything, saved_setting_overrides_detection, saved_auto_setting_still_detects, invalid_saved_setting_falls_back_to_detection, requested_auto_is_not_explicit, detection_report_exposes_basis
 */
class CommandRunnerResolverTest extends TestCase
{
    use SkipsWhenExtensionTablesAreMissing;

    protected function setUp(): void
    {
        // 확장 테이블 판정은 앱 부팅 **전**에 해야 한다 — 부팅이 바로 그 실패 지점이다.
        $this->skipWhenExtensionTablesAreMissing();

        parent::setUp();

        // 개발 환경의 저장 설정이 흘러들지 않게 고정 (설정 없음 상태)
        Config::set('g7_settings.core.system', []);
    }

    /**
     * 감지 결과를 고정한 해석기를 만듭니다.
     *
     * @param  string  $mode  감지 결과 모드 (subprocess|inprocess)
     * @return CommandRunnerResolver 해석기
     */
    private function resolverDetecting(string $mode): CommandRunnerResolver
    {
        return new CommandRunnerResolver(fn (string $phpBinary) => [
            'mode' => $mode,
            'blocked_functions' => $mode === ExecutionEnvironment::MODE_INPROCESS
                ? ExecutionEnvironment::SUBPROCESS_FUNCTIONS
                : [],
            'php_binary' => $phpBinary,
            'php_probe' => ['status' => 'valid', 'version' => '8.3.0', 'message' => 'stub'],
        ]);
    }

    /**
     * 기본값 불변 단언 — 설정이 없는 환경(= 기존 전 사이트)에서 감지가 subprocess 면
     * 판정도 subprocess 다. 이 테스트가 red 면 기존 사이트의 동작이 바뀐 것이다.
     */
    public function test_default_resolves_to_subprocess_without_any_setting(): void
    {
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_SUBPROCESS);

        $this->assertSame(ExecutionMode::Subprocess, $resolver->resolveMode());
        $this->assertInstanceOf(SubprocessCommandRunner::class, $resolver->resolve());
    }

    /**
     * 환경 감지가 inprocess 로 확정되면 (설정 없음 + auto) inprocess 러너.
     */
    public function test_auto_follows_detection_to_inprocess(): void
    {
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_INPROCESS);

        $this->assertSame(ExecutionMode::Inprocess, $resolver->resolveMode());
        $this->assertInstanceOf(InProcessCommandRunner::class, $resolver->resolve());
    }

    /**
     * 호출부 명시 모드가 최우선이다 (감지·설정 무시).
     */
    public function test_explicit_request_overrides_everything(): void
    {
        Config::set('g7_settings.core.system', ['execution_mode' => 'subprocess']);
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_SUBPROCESS);

        $this->assertSame(
            ExecutionMode::Inprocess,
            $resolver->resolveMode(ExecutionMode::Inprocess),
        );
    }

    /**
     * 운영자 저장 설정(auto 아님)이 환경 감지보다 우선한다.
     */
    public function test_saved_setting_overrides_detection(): void
    {
        Config::set('g7_settings.core.system', ['execution_mode' => 'inprocess']);
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_SUBPROCESS);

        $this->assertSame(ExecutionMode::Inprocess, $resolver->resolveMode());
    }

    /**
     * 저장 설정이 auto 면 고정이 아니다 — 감지 결과를 따른다 (매 판정마다 감지).
     */
    public function test_saved_auto_setting_still_detects(): void
    {
        Config::set('g7_settings.core.system', ['execution_mode' => 'auto']);
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_INPROCESS);

        $this->assertSame(ExecutionMode::Inprocess, $resolver->resolveMode());
    }

    /**
     * 저장 설정이 알 수 없는 값이면 auto 로 안전 변환되어 감지를 따른다.
     */
    public function test_invalid_saved_setting_falls_back_to_detection(): void
    {
        Config::set('g7_settings.core.system', ['execution_mode' => 'nonsense']);
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_SUBPROCESS);

        $this->assertSame(ExecutionMode::Subprocess, $resolver->resolveMode());
    }

    /**
     * requested = Auto 는 "명시" 가 아니다 — 설정 → 감지 순서로 진행한다.
     */
    public function test_requested_auto_is_not_explicit(): void
    {
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_INPROCESS);

        $this->assertSame(ExecutionMode::Inprocess, $resolver->resolveMode(ExecutionMode::Auto));
    }

    /**
     * D35 — 감지가 확인한 PHP 바이너리(자동 탐색 폴백 포함)가 서브프로세스 러너에 도달한다.
     *
     * "PATH 의 php 는 실행 불가, 비표준 경로에만 있음" 환경에서 판정(subprocess)과
     * 실행(설정값 php)이 어긋나지 않아야 한다.
     */
    public function test_detected_php_binary_reaches_subprocess_runner(): void
    {
        $resolver = new CommandRunnerResolver(fn (string $phpBinary) => [
            'mode' => ExecutionEnvironment::MODE_SUBPROCESS,
            'blocked_functions' => [],
            'php_binary' => '/usr/local/php83/bin/php',
            'php_probe' => ['status' => 'valid', 'version' => '8.3.0', 'message' => 'auto-detected'],
        ]);

        $runner = $resolver->resolve();

        $this->assertInstanceOf(SubprocessCommandRunner::class, $runner);
        $this->assertSame('/usr/local/php83/bin/php', $runner->phpBinary());
    }

    /**
     * 감지기가 **빈 경로**를 실어 올려도 러너는 그것을 스폰 대상으로 삼지 않는다.
     *
     * `$phpBinary !== ''` 가드가 없으면 빈 문자열이 그대로 `escapeshellarg('')` 로 내려가
     * `'' -d memory_limit=… artisan …` 이 되고, 모든 태스크가 원인 불명으로 죽는다.
     * `null` 만 주입하는 테스트로는 이 분기가 한 번도 평가되지 않는다.
     */
    public function test_empty_detected_binary_falls_back_to_configuration(): void
    {
        Config::set('process.php_binary', '/configured/php');

        $this->assertSame('/configured/php', (new SubprocessCommandRunner(''))->phpBinary());
        $this->assertSame('/configured/php', (new SubprocessCommandRunner(null))->phpBinary());
    }

    /**
     * 설정값도 비어 있으면 해석기와 같은 최종 폴백('php')을 쓴다 — 두 축이 같은 대상을 말한다.
     */
    public function test_empty_configuration_falls_back_to_plain_php(): void
    {
        Config::set('process.php_binary', '');

        $this->assertSame('php', (new SubprocessCommandRunner(''))->phpBinary());
    }

    /**
     * 명시·설정 고정 subprocess 는 감지를 거치지 않으므로 설정값 바이너리를 쓴다 (기본값 불변).
     */
    public function test_explicit_subprocess_uses_configured_binary(): void
    {
        Config::set('process.php_binary', '/configured/php');
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_INPROCESS);

        $runner = $resolver->resolve(ExecutionMode::Subprocess);

        $this->assertInstanceOf(SubprocessCommandRunner::class, $runner);
        $this->assertSame('/configured/php', $runner->phpBinary());
    }

    /**
     * detectionReport 는 판정 근거(차단 함수·probe)를 그대로 노출한다 (W5 표시용).
     */
    public function test_detection_report_exposes_basis(): void
    {
        $resolver = $this->resolverDetecting(ExecutionEnvironment::MODE_INPROCESS);

        $report = $resolver->detectionReport();

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $report['mode']);
        $this->assertSame(ExecutionEnvironment::SUBPROCESS_FUNCTIONS, $report['blocked_functions']);
        $this->assertArrayHasKey('php_probe', $report);
    }
}
