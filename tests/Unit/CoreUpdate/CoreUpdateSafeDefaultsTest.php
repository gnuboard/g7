<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\Http\Requests\CoreUpdate\StartCoreUpdateRequest;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 웹은 안전 기본값을 고정한다.
 *
 * CLI 에는 파괴적 선택이 있다 — `--force`(버전 비교 생략) · `--prune`(전체 덮어쓰기 +
 * 삭제) · `--no-backup`(되돌릴 것 없음) · `--no-maintenance`. 웹 동선에는 그 넷이
 * **없어야** 하는데, 「검증에서 거른다」로는 부족하다: 규칙 한 줄만 풀면 켜지는 값이
 * 남으면 그 선언은 유지되지 않는다. 그래서 **입력 표면 자체가 없다**는 것을 잰다.
 *
 * 커버 축: core_update_web safe_defaults·zip_source
 */
class CoreUpdateSafeDefaultsTest extends TestCase
{
    /** 웹에 노출되면 안 되는 CLI 선택 (D27). */
    private const FORBIDDEN_WEB_OPTIONS = ['force', 'prune', 'no_backup', 'no-backup', 'no_maintenance', 'no-maintenance', 'local', 'vendor_mode', 'vendor-mode'];

    /**
     * 착수 요청이 위험 선택을 받지 않는지 확인합니다.
     *
     * @scenario run_state=idle, source=github_download
     *
     * @effects the_start_request_has_no_input_for_dangerous_cli_options
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_start_request_has_no_input_for_dangerous_cli_options(): void
    {
        $rules = (new StartCoreUpdateRequest)->rules();

        foreach (self::FORBIDDEN_WEB_OPTIONS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $rules,
                "웹 착수 요청에 `{$forbidden}` 입력 표면이 생겼다 — D27 은 그 넷을 CLI 전용으로 둔다.",
            );
        }

        // 받아야 하는 넷은 그대로 있어야 한다 (D28 · D29 · D18).
        foreach (['update_bundled_extensions', 'rebuild_search_index', 'layout_strategy', 'zip_file'] as $expected) {
            $this->assertArrayHasKey($expected, $rules, "착수 화면의 선택 `{$expected}` 이 검증에서 빠졌다.");
        }
    }

    /**
     * 착수 선택 객체에도 위험 선택이 없는지 확인합니다.
     *
     * 요청만 막고 값 객체에 필드를 두면, 다른 호출부(훅·서비스)가 그 자리를 통해 켤 수
     * 있다 — 표면은 한 겹이 아니라 통로 전체에서 없어야 한다.
     *
     * @scenario run_state=idle, source=github_download
     *
     * @effects the_options_object_has_no_dangerous_fields
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_options_object_has_no_dangerous_fields(): void
    {
        $properties = array_map(
            static fn ($property): string => strtolower($property->getName()),
            (new ReflectionClass(CoreUpdateOptions::class))->getProperties(),
        );

        foreach (['force', 'prune', 'nobackup', 'nomaintenance', 'vendormode'] as $forbidden) {
            $this->assertNotContains($forbidden, $properties, "착수 선택 객체에 `{$forbidden}` 필드가 생겼다 (D27).");
        }
    }

    /**
     * 기본값이 안전한 쪽인지 확인합니다.
     *
     * @scenario run_state=idle, bundled_extensions=on, rebuild_search_index=off, layout_strategy=overwrite
     *
     * @effects defaults_are_the_safe_side
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_defaults_are_the_safe_side(): void
    {
        $options = CoreUpdateOptions::fromValidated([]);

        $this->assertTrue($options->updateBundledExtensions, '번들 확장 함께 업데이트는 기본 켬 (D28).');
        $this->assertFalse($options->rebuildSearchIndex, '검색 인덱스 재생성은 기본 끔 (재생성 옵트인 규율).');
        $this->assertSame(CoreUpdateOptions::LAYOUT_OVERWRITE, $options->layoutStrategy, 'CLI 기본과 같아야 한다 (D29).');
        $this->assertNull($options->zipFile, '기본은 자동 내려받기다.');
        $this->assertFalse($options->usesManualZip());
    }

    /**
     * 알 수 없는 레이아웃 전략은 덮어쓰기로 떨어지는지 확인합니다.
     *
     * @scenario run_state=idle, layout_strategy=overwrite
     *
     * @effects an_unknown_layout_strategy_falls_back_to_overwrite
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_an_unknown_layout_strategy_falls_back_to_overwrite(): void
    {
        $this->assertSame(
            CoreUpdateOptions::LAYOUT_OVERWRITE,
            CoreUpdateOptions::fromValidated(['layout_strategy' => 'nonsense'])->layoutStrategy,
        );

        $this->assertSame(
            CoreUpdateOptions::LAYOUT_KEEP,
            CoreUpdateOptions::fromValidated(['layout_strategy' => 'keep'])->layoutStrategy,
        );
    }

    /**
     * ZIP 이름에서 경로 성분이 제거되는지 확인합니다 (㉯ — D18).
     *
     * 디렉토리는 서버가 정한다. 이름만 받아도 경로 성분이 남으면 그 값이 곧 파일시스템
     * 탐색 표면이 된다.
     *
     * @scenario run_state=idle, source=manual_zip
     *
     * @effects zip_file_names_are_stripped_of_path_components
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_zip_file_names_are_stripped_of_path_components(): void
    {
        $cases = [
            '../../.env' => '.env',
            '/etc/passwd' => 'passwd',
            'sub/dir/g7.zip' => 'g7.zip',
            'sub\\dir\\g7.zip' => 'g7.zip',
            '  g7-7.1.0.zip  ' => 'g7-7.1.0.zip',
            '' => null,
            '.' => null,
            '..' => null,
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                CoreUpdateOptions::fromValidated(['zip_file' => $input])->zipFile,
                "ZIP 이름 정규화가 어긋났다: {$input}",
            );
        }
    }

    /**
     * 웹 오케스트레이터가 파괴적 인자를 서비스에 넘기지 않는지 소스로 확인합니다.
     *
     * 값 객체에 필드가 없어도 오케스트레이터가 리터럴로 `prune: true` 를 넘기면 같은
     * 결과가 된다 — 그 자리가 D27 의 마지막 통로다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects the_orchestrator_never_passes_destructive_arguments
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_orchestrator_never_passes_destructive_arguments(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(CoreUpdateOrchestrator::class))->getFileName(),
        );

        $this->assertStringContainsString(
            'prune: false',
            $source,
            '웹 적용은 3-way 증분 고정이어야 한다 (D27).',
        );
        $this->assertStringNotContainsString('prune: true', $source);

        // 아래 부재 단언은 **그 이름이 이 저장소에 실재할 때만** 의미가 있다. 실재한 적 없는
        // 문자열을 「없다」고 재면 무엇을 넣어도 통과하는 죽은 가드가 된다. 그래서 CLI 쪽에
        // 그 옵션들이 살아 있음을 먼저 확인하고(모집단 非0), 웹 경로에만 없음을 잰다.
        $cli = (string) file_get_contents(
            (string) (new ReflectionClass(CoreUpdateCommand::class))->getFileName(),
        );

        foreach (['--prune', '--no-backup', '--no-maintenance'] as $option) {
            $this->assertStringContainsString(
                $option,
                $cli,
                "CLI 에 {$option} 가 없으면 아래 부재 단언은 아무것도 재지 않는다.",
            );
            $this->assertStringNotContainsString(
                "'{$option}'",
                $source,
                "웹 실행 경로가 {$option} 를 넘기면 D27 의 안전 기본값이 무너진다.",
            );
        }

        // 백업·점검 모드는 **건너뛸 수 없는 단계**로 존재해야 한다.
        // 웹은 요청 시간 몫 안에서 나눠 만드는 커서 판(`createBackupChunk`)을 부른다 — 명령줄의 `createBackup()` 이
        // 같은 함수를 멈춤 없이 부르는 래퍼다.
        $this->assertStringContainsString('createBackupChunk(', $source, '웹은 백업 필수다 (D10 의 전제).');
        $this->assertStringContainsString('enableMaintenanceMode(', $source, '웹은 점검 모드 필수다 (D27).');
    }

    /**
     * CLI 는 그 선택들을 **그대로 유지**하는지 확인합니다.
     *
     * D27 은 「웹에 노출하지 않는다」이지 「기능을 없앤다」가 아니다. CLI 에서 사라지면
     * 그것은 이 안이 하려던 것과 다른 변경이다.
     *
     * @scenario run_state=idle, execution_mode=subprocess
     *
     * @effects the_cli_keeps_its_dangerous_options
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_cli_keeps_its_dangerous_options(): void
    {
        $signature = (new ReflectionClass(CoreUpdateCommand::class))
            ->getDefaultProperties()['signature'] ?? '';

        foreach (['--force', '--no-backup', '--prune', '--no-maintenance', '--zip=', '--source='] as $option) {
            $this->assertStringContainsString(
                $option,
                (string) $signature,
                "CLI 에서 `{$option}` 이 사라졌다 — D27 은 웹 미노출이지 기능 제거가 아니다.",
            );
        }
    }
}
