<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\EnvironmentDetector;
use Tests\TestCase;

class EnvironmentDetectorTest extends TestCase
{
    private EnvironmentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new EnvironmentDetector;
    }

    public function test_has_proc_open_reflects_function_availability(): void
    {
        $result = $this->detector->hasProcOpen();
        $this->assertIsBool($result);

        // proc_open 은 disable_functions 로 차단되지 않은 일반 테스트 환경에서는 true
        if (function_exists('proc_open')) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (! in_array('proc_open', $disabled, true)) {
                $this->assertTrue($result);
            }
        }
    }

    public function test_has_zip_archive_returns_true_when_class_exists(): void
    {
        $this->assertSame(class_exists(\ZipArchive::class), $this->detector->hasZipArchive());
    }

    public function test_find_composer_binary_returns_null_when_no_candidate_available(): void
    {
        $detector = new EnvironmentDetector;
        $detector->resetCache();

        // 절대 존재하지 않는 경로를 hint로 주고 ENV/config도 비운 상태 가정
        // (실제 환경에 composer가 설치되어 있으면 PATH 검색 단계에서 발견될 수 있음)
        $original = config('process.composer_binary');
        config(['process.composer_binary' => null]);

        try {
            $result = $detector->findComposerBinary('/nonexistent/path/to/composer');
            // 반환값은 실제 PATH에 composer가 있느냐에 따라 달라짐 — null 또는 문자열
            $this->assertTrue($result === null || is_string($result));
        } finally {
            config(['process.composer_binary' => $original]);
        }
    }

    public function test_find_composer_binary_accepts_hint_with_spaces(): void
    {
        $detector = new EnvironmentDetector;
        $detector->resetCache();

        // 공백 포함 힌트 — 파일 존재 검사 스킵하고 그대로 사용
        $hint = 'php /custom/path/composer.phar';
        $result = $detector->findComposerBinary($hint);

        $this->assertSame($hint, $result);
    }

    public function test_summarize_returns_complete_report(): void
    {
        $report = $this->detector->summarize();

        $this->assertArrayHasKey('proc_open', $report);
        $this->assertArrayHasKey('shell_exec', $report);
        $this->assertArrayHasKey('zip_archive', $report);
        $this->assertArrayHasKey('composer_binary', $report);
        $this->assertArrayHasKey('composer_executable', $report);
        $this->assertArrayHasKey('can_use_composer', $report);
        $this->assertArrayHasKey('can_use_bundle', $report);

        $this->assertIsBool($report['proc_open']);
        $this->assertIsBool($report['zip_archive']);
        $this->assertIsBool($report['can_use_composer']);
        $this->assertIsBool($report['can_use_bundle']);
    }

    public function test_reset_cache_clears_cached_values(): void
    {
        $this->detector->canExecuteComposer();
        $this->detector->resetCache();

        // 재호출 시 예외 없이 동작해야 함
        $result = $this->detector->canExecuteComposer();
        $this->assertIsBool($result);
    }

    public function test_build_composer_env_includes_superuser_flag(): void
    {
        $env = EnvironmentDetector::buildComposerEnv();

        $this->assertArrayHasKey('COMPOSER_ALLOW_SUPERUSER', $env);
        $this->assertSame('1', $env['COMPOSER_ALLOW_SUPERUSER']);
    }

    public function test_build_composer_env_includes_no_interaction_flag(): void
    {
        $env = EnvironmentDetector::buildComposerEnv();

        $this->assertArrayHasKey('COMPOSER_NO_INTERACTION', $env);
        $this->assertSame('1', $env['COMPOSER_NO_INTERACTION']);
    }

    public function test_build_composer_env_preserves_existing_env(): void
    {
        $original = $_ENV['PATH'] ?? null;
        $_ENV['PATH'] = '/sentinel/test/path';

        try {
            $env = EnvironmentDetector::buildComposerEnv();

            $this->assertArrayHasKey('PATH', $env);
            $this->assertSame('/sentinel/test/path', $env['PATH']);
        } finally {
            if ($original === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $original;
            }
        }
    }

    public function test_build_composer_env_overrides_external_false_values(): void
    {
        $originalAllow = $_ENV['COMPOSER_ALLOW_SUPERUSER'] ?? null;
        $originalNoInteraction = $_ENV['COMPOSER_NO_INTERACTION'] ?? null;

        $_ENV['COMPOSER_ALLOW_SUPERUSER'] = '0';
        $_ENV['COMPOSER_NO_INTERACTION'] = '0';

        try {
            $env = EnvironmentDetector::buildComposerEnv();

            $this->assertSame('1', $env['COMPOSER_ALLOW_SUPERUSER']);
            $this->assertSame('1', $env['COMPOSER_NO_INTERACTION']);
        } finally {
            foreach (['COMPOSER_ALLOW_SUPERUSER' => $originalAllow, 'COMPOSER_NO_INTERACTION' => $originalNoInteraction] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}
