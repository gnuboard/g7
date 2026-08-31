<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\ArtisanCommandExtractor;
use App\Scheduling\Exceptions\ScheduleCommandExtractionException;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\ProcessUtils;
use Tests\TestCase;

/**
 * shell 형태 스케줄 이벤트의 커맨드 되뽑기 단위 계약 (A-5 ㉢).
 *
 * 되뽑기는 프레임워크 내부 표현에 의존하므로, 그 표현이 바뀌면 여기가 먼저 red 가 된다.
 * 조립은 프레임워크와 같은 생성자(`ConsoleApplication::formatCommandString`)로 만든다 —
 * 리터럴로 적으면 실제 조립 형태가 바뀌어도 이 테스트만 통과한다.
 *
 * @scenario event_kind=shell_event
 */
class ArtisanCommandExtractorTest extends TestCase
{
    /** 되뽑기 대상 */
    private ArtisanCommandExtractor $extractor;

    /**
     * 테스트 준비.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new ArtisanCommandExtractor;
    }

    /**
     * 인자 없는 커맨드가 그대로 되뽑힌다.
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_plain_command_is_recovered(): void
    {
        $extraction = $this->extractor->extractFromCommandString(
            ConsoleApplication::formatCommandString('auth:clear-resets'),
        );

        $this->assertSame('auth:clear-resets', $extraction->command);
        $this->assertSame([], $extraction->parameters);
    }

    /**
     * `--opt=값` 옵션이 파라미터 표기로 옮겨진다.
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_value_option_is_recovered(): void
    {
        $extraction = $this->extractor->extractFromCommandString(
            ConsoleApplication::formatCommandString('sanctum:prune-expired --hours=24'),
        );

        $this->assertSame('sanctum:prune-expired', $extraction->command);
        $this->assertSame(['--hours' => '24'], $extraction->parameters);
    }

    /**
     * 값 없는 플래그는 true 로 옮겨진다 (Artisan::call 규약).
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_flag_option_is_recovered_as_true(): void
    {
        $extraction = $this->extractor->extractFromCommandString(
            ConsoleApplication::formatCommandString('attachments:prune-orphans --scheduled'),
        );

        $this->assertSame('attachments:prune-orphans', $extraction->command);
        $this->assertSame(['--scheduled' => true], $extraction->parameters);
    }

    /**
     * 같은 옵션이 반복되면 배열로 모인다 (배열 옵션).
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_repeated_option_becomes_an_array(): void
    {
        $extraction = $this->extractor->extractFromCommandString(
            ConsoleApplication::formatCommandString('demo:command --path=a --path=b'),
        );

        $this->assertSame(['--path' => ['a', 'b']], $extraction->parameters);
    }

    /**
     * 위치 인자는 숫자 키로 남아 러너가 인자명에 매핑한다.
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_positional_argument_keeps_numeric_key(): void
    {
        $extraction = $this->extractor->extractFromCommandString(
            ConsoleApplication::formatCommandString('demo:command target-value'),
        );

        $this->assertSame('demo:command', $extraction->command);
        $this->assertSame([0 => 'target-value'], $extraction->parameters);
    }

    /**
     * 공백이 든 인자는 인용부호가 걷힌 원래 값으로 돌아온다.
     *
     * 토큰화를 직접 다시 구현하면 프레임워크의 escape 규칙과 조용히 갈라진다 —
     * Symfony 의 토크나이저를 그대로 쓰는 것이 이 단언의 의도다.
     *
     * @effects shell_event_command_is_recovered
     */
    public function test_quoted_argument_is_unescaped(): void
    {
        $line = ConsoleApplication::formatCommandString(
            'demo:command '.ProcessUtils::escapeArgument('a b'),
        );

        $this->assertSame([0 => 'a b'], $this->extractor->extractFromCommandString($line)->parameters);
    }

    /**
     * Artisan 이 아닌 실행 문자열은 조용히 건너뛰지 않고 예외로 드러난다.
     *
     * 건너뛰면 그 예약은 오류 없이 사라진다 — 실행할 수 없다는 사실이 운영자에게
     * 도달해야 한다.
     *
     * @effects non_artisan_event_fails_loudly
     */
    public function test_non_artisan_command_throws(): void
    {
        $this->expectException(ScheduleCommandExtractionException::class);

        $this->extractor->extractFromCommandString('rsync -a /src /dst');
    }

    /**
     * 접두사만 있고 커맨드가 없으면 예외다.
     *
     * @effects non_artisan_event_fails_loudly
     */
    public function test_prefix_without_command_throws(): void
    {
        $this->expectException(ScheduleCommandExtractionException::class);

        $this->extractor->extractFromCommandString(ConsoleApplication::formatCommandString(''));
    }
}
