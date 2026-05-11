<?php

namespace Tests\Unit\Console\Helpers;

use App\Console\Helpers\ConsoleConfirm;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ConsoleConfirm 헬퍼 단위 테스트.
 *
 * - parse(): 입력 정규화 규칙 (대소문자/공백/empty/유효값/무효값)
 * - ask():   STDIN 루프 동작 (1회 결정/재질문/EOF/non-tty)
 */
class ConsoleConfirmTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool|null}>
     */
    public static function parseCases(): array
    {
        return [
            'empty + default true' => ['', true, true],
            'empty + default false' => ['', false, false],
            'yes lowercase' => ['yes', false, true],
            'y short' => ['y', false, true],
            'Y uppercase' => ['Y', false, true],
            'YES uppercase' => ['YES', false, true],
            'yes with surrounding whitespace' => ['  yes  ', false, true],
            'yes with newline (fgets style)' => ["\nyes\n", false, true],
            'no lowercase' => ['no', true, false],
            'n short' => ['n', true, false],
            'N uppercase + default true' => ['N', true, false],
            'NO uppercase' => ['NO', true, false],
            'invalid - yell_no (default false)' => ['yell_no', false, null],
            'invalid - nope (default true)' => ['nope', true, null],
            'invalid - yeah (y로 시작이지만 무관)' => ['yeah', false, null],
            'invalid - 1 (numeric truthy)' => ['1', false, null],
            'invalid - 0 (numeric falsy)' => ['0', false, null],
            'invalid - true literal' => ['true', false, null],
            'invalid - false literal' => ['false', false, null],
            'invalid - 한글 예 (비ASCII)' => ['예', false, null],
            'invalid - 한글 네 (비ASCII)' => ['네', false, null],
            'whitespace-only → empty → default' => ["   \t \n", true, true],
        ];
    }

    #[DataProvider('parseCases')]
    public function test_parse_normalizes_input(string $raw, bool $default, ?bool $expected): void
    {
        $this->assertSame($expected, ConsoleConfirm::parse($raw, $default));
    }

    public function test_ask_returns_immediately_on_first_valid_input(): void
    {
        $stdin = $this->makeStdin(["yes\n"]);
        $output = '';

        $result = ConsoleConfirm::ask(
            '진행할까요?',
            false,
            $stdin,
            function (string $text) use (&$output) {
                $output .= $text;
            },
        );

        $this->assertTrue($result);
        $this->assertStringContainsString('진행할까요? (yes/no) [no]: ', $output);
        $this->assertStringNotContainsString('yes, y, no, n', $output);
    }

    public function test_ask_loops_on_invalid_input_then_resolves(): void
    {
        $stdin = $this->makeStdin(["abc\n", "y\n"]);
        $output = '';

        $result = ConsoleConfirm::ask(
            '진행할까요?',
            false,
            $stdin,
            function (string $text) use (&$output) {
                $output .= $text;
            },
        );

        $this->assertTrue($result);
        $this->assertStringContainsString('yes, y, no, n 중 하나로 입력해 주세요.', $output);
        // 질문이 정확히 2회 출력되어야 함
        $this->assertSame(2, substr_count($output, '진행할까요? (yes/no) [no]: '));
    }

    public function test_ask_loops_multiple_times_on_invalid_input(): void
    {
        $stdin = $this->makeStdin(["a\n", "b\n", "no\n"]);
        $output = '';

        $result = ConsoleConfirm::ask(
            '진행할까요?',
            true,
            $stdin,
            function (string $text) use (&$output) {
                $output .= $text;
            },
        );

        $this->assertFalse($result);
        $this->assertSame(2, substr_count($output, 'yes, y, no, n 중 하나로 입력해 주세요.'));
        $this->assertSame(3, substr_count($output, '진행할까요? (yes/no) [yes]: '));
    }

    public function test_ask_returns_default_on_eof(): void
    {
        $stdin = $this->makeStdin([]);

        $resultDefaultTrue = ConsoleConfirm::ask('Q?', true, $stdin, fn () => null);
        $this->assertTrue($resultDefaultTrue);

        $stdin2 = $this->makeStdin([]);
        $resultDefaultFalse = ConsoleConfirm::ask('Q?', false, $stdin2, fn () => null);
        $this->assertFalse($resultDefaultFalse);
    }

    public function test_ask_returns_default_on_empty_input(): void
    {
        $stdin = $this->makeStdin(["\n"]);

        $result = ConsoleConfirm::ask('Q?', true, $stdin, fn () => null);

        $this->assertTrue($result);
    }

    public function test_ask_uses_yes_hint_when_default_true(): void
    {
        $stdin = $this->makeStdin(["yes\n"]);
        $output = '';

        ConsoleConfirm::ask('Q?', true, $stdin, function (string $text) use (&$output) {
            $output .= $text;
        });

        $this->assertStringContainsString('Q? (yes/no) [yes]: ', $output);
    }

    public function test_ask_uses_no_hint_when_default_false(): void
    {
        $stdin = $this->makeStdin(["no\n"]);
        $output = '';

        ConsoleConfirm::ask('Q?', false, $stdin, function (string $text) use (&$output) {
            $output .= $text;
        });

        $this->assertStringContainsString('Q? (yes/no) [no]: ', $output);
    }

    /**
     * 메모리 기반 STDIN 리소스 생성.
     *
     * @param  array<string>  $lines  fgets() 가 차례로 반환할 줄 (개행 포함 권장)
     * @return resource
     */
    private function makeStdin(array $lines)
    {
        $stream = fopen('php://memory', 'r+');
        foreach ($lines as $line) {
            fwrite($stream, $line);
        }
        rewind($stream);

        return $stream;
    }
}
