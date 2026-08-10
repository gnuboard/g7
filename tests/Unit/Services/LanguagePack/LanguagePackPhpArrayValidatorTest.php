<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 언어팩 PHP 파일 문법 검사기 테스트.
 *
 * KVE-2026-1678: 언어팩의 PHP 파일은 활성화되면 `require` 되어 그대로 실행된다. 기존 검사는
 * 위험 함수 11개를 정규식으로 나열해 막았고, 목록에 없는 함수·문법으로 전부 우회됐다.
 * 본 검사기는 "번역 배열만" 통과시키는 화이트리스트 문법이므로, 우회 매트릭스는
 * **함수 이름이 아니라 문법 형태**를 축으로 전개한다.
 *
 * DB·프레임워크 부팅이 필요 없는 순수 판정 유틸이라 PHPUnit\Framework\TestCase 를 직접 상속한다.
 */
class LanguagePackPhpArrayValidatorTest extends TestCase
{
    /**
     * 번역 배열만 담은 정상 파일은 통과한다 (기존 자산 비파괴).
     *
     * @param  string  $source  PHP 소스
     *
     * @scenario vector=php_syntax_validator, actor_permission=cli_system
     *
     * @effects translation_array_files_are_accepted
     */
    #[Test]
    #[DataProvider('safeSourceProvider')]
    public function it_accepts_translation_array_files(string $source): void
    {
        $violation = LanguagePackPhpArrayValidator::inspect($source);

        $this->assertNull(
            $violation,
            '정상 언어팩 파일이 거부됨: '.($violation['reason'] ?? '').' — '.$source
        );
    }

    /**
     * 번역 배열 외의 문법은 전부 거부한다.
     *
     * 기존 블록리스트가 놓치던 함수들(`file_put_contents` `fopen` `call_user_func` `assert`
     * 백틱)과, 함수 이름을 아예 쓰지 않는 우회(문자열 리터럴 호출·가변 변수·heredoc·
     * 닫는 태그 뒤 인라인 코드)를 같은 규칙 하나로 막는지 확인한다.
     *
     * @param  string  $source  PHP 소스
     * @param  string  $vector  우회 수법 (실패 메시지용)
     *
     * @scenario vector=php_syntax_validator, actor_permission=cli_system
     *
     * @effects function_calls_are_rejected_regardless_of_name, name_free_bypasses_are_rejected
     */
    #[Test]
    #[DataProvider('unsafeSourceProvider')]
    public function it_rejects_anything_that_is_not_a_translation_array(string $source, string $vector): void
    {
        $this->assertFalse(
            LanguagePackPhpArrayValidator::isSafe($source),
            "차단되어야 하는 소스가 통과함 ({$vector}): {$source}"
        );
    }

    /**
     * 깨진 소스는 파싱 단계에서 조기 거부한다.
     *
     * @scenario vector=php_syntax_validator, actor_permission=cli_system
     *
     * @effects broken_and_oversized_sources_are_rejected_early
     */
    #[Test]
    public function it_rejects_sources_that_do_not_parse(): void
    {
        $violation = LanguagePackPhpArrayValidator::inspect('<?php return [ ;');

        $this->assertNotNull($violation);
        $this->assertSame('parse_error', $violation['reason']);
    }

    /**
     * 크기 상한을 넘는 소스는 파서를 돌리지 않고 거부한다.
     */
    #[Test]
    public function it_rejects_sources_over_the_size_limit(): void
    {
        $source = '<?php return ['.str_repeat("'a' => 'b', ", 200000).'];';

        $this->assertGreaterThan(LanguagePackPhpArrayValidator::MAX_BYTES, strlen($source));

        $violation = LanguagePackPhpArrayValidator::inspect($source);

        $this->assertNotNull($violation);
        $this->assertSame('file_too_large', $violation['reason']);
    }

    /**
     * 중첩 깊이 상한을 넘는 배열은 거부한다.
     */
    #[Test]
    public function it_rejects_arrays_deeper_than_the_depth_limit(): void
    {
        $depth = LanguagePackPhpArrayValidator::MAX_DEPTH + 5;
        $source = '<?php return '.str_repeat('[', $depth).str_repeat(']', $depth).';';

        $this->assertFalse(LanguagePackPhpArrayValidator::isSafe($source));
    }

    /**
     * 위반 지점의 줄 번호를 보고한다 (운영자가 어느 줄을 고쳐야 하는지 알 수 있어야 한다).
     *
     * @scenario vector=php_syntax_validator, actor_permission=cli_system
     *
     * @effects violation_line_is_reported
     */
    #[Test]
    public function it_reports_the_line_of_the_violation(): void
    {
        $source = "<?php\n\nreturn [\n    'a' => 'b',\n    'c' => system('id'),\n];\n";

        $violation = LanguagePackPhpArrayValidator::inspect($source);

        $this->assertNotNull($violation);
        $this->assertSame(5, $violation['line']);
    }

    /**
     * 통과해야 하는 소스 목록.
     *
     * @return array<string, array{string}>
     */
    public static function safeSourceProvider(): array
    {
        return [
            '빈 배열' => ["<?php\n\nreturn [];\n"],
            '단순 문자열 배열' => ["<?php\n\nreturn ['hello' => 'こんにちは'];\n"],
            '중첩 배열' => ["<?php\nreturn ['a' => ['b' => ['c' => 'd']]];\n"],
            '숫자 키' => ["<?php\nreturn [0 => 'zero', 1 => 'one'];\n"],
            '키 없는 항목' => ["<?php\nreturn ['first', 'second'];\n"],
            '후행 쉼표' => ["<?php\nreturn [\n    'a' => 'b',\n];\n"],
            'declare 프리픽스' => ["<?php\n\ndeclare(strict_types=1);\n\nreturn ['a' => 'b'];\n"],
            '문자열 리터럴 연결' => ["<?php\nreturn ['a' => 'long '.'message'];\n"],
            '숫자·bool·null' => ["<?php\nreturn ['n' => 1, 'f' => 1.5, 'neg' => -3, 't' => true, 'f2' => false, 'x' => null];\n"],
            '주석 포함' => ["<?php\n// 인사말\nreturn [\n    /* 그룹 */ 'a' => 'b', // 항목\n];\n"],
            'array() 레거시 문법' => ["<?php\nreturn array('a' => 'b');\n"],
            '닫는 태그 포함' => ["<?php\nreturn ['a' => 'b'];\n?>\n"],
            '이스케이프 포함 문자열' => ["<?php\nreturn ['a' => 'It\\'s ok', 'b' => \"tab\\there\"];\n"],
            '대문자 상수 표기' => ["<?php\nreturn ['a' => TRUE, 'b' => NULL];\n"],
        ];
    }

    /**
     * 차단되어야 하는 소스 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function unsafeSourceProvider(): array
    {
        return [
            // 기존 블록리스트가 잡던 것
            'eval' => ["<?php\neval(\$_GET['c']);\nreturn [];\n", '기존 목록 — eval'],
            // 기존 블록리스트가 놓치던 것
            'file_put_contents' => ["<?php\nfile_put_contents('/tmp/g7','x');\nreturn [];\n", '목록 누락 — 파일 쓰기'],
            'fopen/fwrite' => ["<?php\n\$h = fopen('/tmp/g7','w');\nfwrite(\$h,'x');\nreturn [];\n", '목록 누락 — 스트림'],
            'unlink' => ["<?php\nunlink('/etc/passwd');\nreturn [];\n", '목록 누락 — 삭제'],
            'call_user_func' => ["<?php\ncall_user_func('system','id');\nreturn [];\n", '목록 누락 — 간접 호출'],
            'assert' => ["<?php\nassert(\"system('id')\");\nreturn [];\n", '목록 누락 — assert'],
            '백틱 실행 연산자' => ["<?php\n\$o = `id`;\nreturn [];\n", '목록 누락 — 백틱'],
            'file_get_contents 반환' => ["<?php\nreturn ['a' => file_get_contents('/etc/passwd')];\n", '목록 누락 — 값 자리 호출'],

            // 함수 이름을 쓰지 않는 우회 (블록리스트로는 원리적으로 불가)
            '문자열 리터럴 호출' => ["<?php\nreturn [('system')('id')];\n", '이름 없는 호출 — 토큰 집합 검사 우회'],
            '가변 변수 호출' => ["<?php\n\$a='system';\$a('id');\nreturn [];\n", '가변 변수'],
            '변수 사용' => ["<?php\nreturn ['a' => \$GLOBALS['x']];\n", '변수'],
            'heredoc 보간' => ["<?php\n\$x = <<<EOT\n{\$_GET['c']}\nEOT;\nreturn [];\n", 'heredoc'],
            '닫는 태그 뒤 인라인 코드' => ["<?php\nreturn [];\n?>\n<?php system('id');\n", '닫는 태그 뒤 두 번째 블록'],
            '닫는 태그 뒤 출력' => ["<?php\nreturn [];\n?>\nleaked\n", '닫는 태그 뒤 인라인 출력'],
            'new 객체' => ["<?php\nreturn ['a' => new \\stdClass];\n", 'new'],
            '상수 참조' => ["<?php\nreturn ['a' => PHP_OS];\n", '상수 — 정보 노출'],
            '삼항 연산' => ["<?php\nreturn ['a' => 1 > 0 ? 'y' : 'n'];\n", '연산자'],
            '함수 선언' => ["<?php\nfunction g7_evil(){ system('id'); }\nreturn [];\n", '함수 선언'],
            '클래스 선언' => ["<?php\nclass Evil { }\nreturn [];\n", '클래스 선언'],
            'use 문' => ["<?php\nuse App\\Models\\User;\nreturn [];\n", 'use'],
            'return 없음' => ["<?php\n\$a = 1;\n", 'return 부재'],
            'return 뒤 추가 구문' => ["<?php\nreturn [];\nsystem('id');\n", 'return 뒤 잔여 토큰'],
            '단축 에코 태그' => ["<?= system('id') ?>\n", '단축 태그'],
            '선행 인라인 HTML' => ["leaked\n<?php\nreturn [];\n", '여는 태그 앞 출력'],
            '괄호 그룹화' => ["<?php\nreturn ('a');\n", '괄호 — array 뒤가 아님'],
            'declare 위장' => ["<?php\ndeclare(ticks=1);\nreturn [];\n", 'declare 다른 지시자'],
            '스프레드' => ["<?php\nreturn [...\$a];\n", '스프레드 연산자'],
            '함수 호출을 키로' => ["<?php\nreturn [system('id') => 'x'];\n", '키 자리 호출'],
        ];
    }
}
