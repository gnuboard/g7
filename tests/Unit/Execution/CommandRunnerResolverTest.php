<?php

namespace Tests\Unit\Execution;

use App\Execution\CommandRunnerResolver;
use App\Execution\ExecutionMode;
use App\Execution\InProcessCommandRunner;
use App\Execution\SubprocessCommandRunner;
use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 실행 모드 해석기(CommandRunnerResolver) 단위 테스트.
 *
 * 판정 우선순위(명시 → 설정값 → 환경 감지)와 fail-safe(불확실 → subprocess),
 * 그리고 기본값 불변(설정 없는 기존 환경 = subprocess)을 고정한다.
 */
class CommandRunnerResolverTest extends TestCase
{
    protected function setUp(): void
    {
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
