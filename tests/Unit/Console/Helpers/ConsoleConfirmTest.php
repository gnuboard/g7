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
     * parse() 입력 정규화 사례(원문 입력, 기본값, 기대 결과)를 제공한다.
     *
     * @return array<string, array{string, bool, bool|null}> 사례 이름별 [원문 입력, 기본값, 기대 결과(무효면 null)]
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

    /**
     * parse() 가 대소문자·공백·빈 입력을 정규화하고 yes·y·no·n 외 값은 무효(null)로 돌려주는지 검증한다.
     *
     * @effects confirm_answer_parse_normalizes_case_and_whitespace
     *
     * @param  string  $raw  사용자가 입력한 원문
     * @param  bool  $default  빈 입력일 때 쓸 기본값
     * @param  bool|null  $expected  기대 결과, 무효 입력이면 null
     * @return void 반환값 없음
     */
    #[DataProvider('parseCases')]
    public function test_parse_normalizes_input(string $raw, bool $default, ?bool $expected): void
    {
        $this->assertSame($expected, ConsoleConfirm::parse($raw, $default));
    }

    /**
     * 첫 답이 유효하면 재질문 없이 바로 결정하고 무효 안내를 보이지 않는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_valid_answer_resolves_immediately
     *
     * @return void 반환값 없음
     */
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

    /**
     * 무효 답이면 안내 후 질문을 다시 출력하고 유효한 답에서 결정되는지 검증한다.
     *
     * @scenario answer=invalid_then_valid
     *
     * @effects confirm_invalid_answer_shows_notice_and_reasks
     *
     * @return void 반환값 없음
     */
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
        $this->assertStringContainsString(__('common.console.console_confirm.invalid_answer'), $output);
        // 질문이 정확히 2회 출력되어야 함
        $this->assertSame(2, substr_count($output, '진행할까요? (yes/no) [no]: '));
    }

    /**
     * 무효 답이 여러 번 이어지면 그 횟수만큼 안내와 재질문을 반복하는지 검증한다.
     *
     * @scenario answer=invalid_then_valid
     *
     * @effects confirm_invalid_answer_shows_notice_and_reasks
     *
     * @return void 반환값 없음
     */
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
        $this->assertSame(2, substr_count($output, __('common.console.console_confirm.invalid_answer')));
        $this->assertSame(3, substr_count($output, '진행할까요? (yes/no) [yes]: '));
    }

    /**
     * 입력 스트림이 끝나(EOF) 답을 받을 수 없으면 기본값을 돌려주는지 검증한다.
     *
     * @scenario answer=unanswerable
     *
     * @effects confirm_returns_default_without_prompt_when_answer_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_ask_returns_default_on_eof(): void
    {
        $stdin = $this->makeStdin([]);

        $resultDefaultTrue = ConsoleConfirm::ask('Q?', true, $stdin, fn () => null);
        $this->assertTrue($resultDefaultTrue);

        $stdin2 = $this->makeStdin([]);
        $resultDefaultFalse = ConsoleConfirm::ask('Q?', false, $stdin2, fn () => null);
        $this->assertFalse($resultDefaultFalse);
    }

    /**
     * 빈 줄 입력이면 기본값을 돌려주는지 검증한다.
     *
     * @scenario answer=empty
     *
     * @effects confirm_empty_answer_uses_default
     *
     * @return void 반환값 없음
     */
    public function test_ask_returns_default_on_empty_input(): void
    {
        $stdin = $this->makeStdin(["\n"]);

        $result = ConsoleConfirm::ask('Q?', true, $stdin, fn () => null);

        $this->assertTrue($result);
    }

    /**
     * 기본값이 true 이면 질문 끝에 [yes] 힌트를 붙이는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_prompt_marks_default_in_hint
     *
     * @return void 반환값 없음
     */
    public function test_ask_uses_yes_hint_when_default_true(): void
    {
        $stdin = $this->makeStdin(["yes\n"]);
        $output = '';

        ConsoleConfirm::ask('Q?', true, $stdin, function (string $text) use (&$output) {
            $output .= $text;
        });

        $this->assertStringContainsString('Q? (yes/no) [yes]: ', $output);
    }

    /**
     * 기본값이 false 이면 질문 끝에 [no] 힌트를 붙이는지 검증한다.
     *
     * @scenario answer=valid
     *
     * @effects confirm_prompt_marks_default_in_hint
     *
     * @return void 반환값 없음
     */
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
     * @return resource 읽기 위치를 처음으로 되돌린 메모리 스트림
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
