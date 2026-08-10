<?php

namespace Tests\Unit\Search;

use App\Search\SearchPagePolicy;
use Tests\TestCase;

/**
 * 커서 판정에 페이지 번호가 도달하는지 검사하는 패리티 테스트 (#519)
 *
 * {@see SearchPagePolicy::usesCursor()} 는 커서가 없을 때 `page <= 1` 인지로
 * "시작점" 과 "깊은 페이지 딥링크" 를 가른다. 그래서 호출자가 페이지 번호를 넘기지 않으면
 * 기본값 1 이 적용되어 **모든 요청이 시작점으로 판정**된다. 그 결과 `?page=3` 딥링크가
 * 조용히 1 페이지를 돌려주고, 예외도 오류 응답도 남지 않는다.
 *
 * 호출 지점을 손으로 열거하면 검색에 새로 합류하는 도메인이 같은 함정을 다시 밟는다.
 * 여기서는 저장소를 훑어 **모든 호출 지점**이 페이지 번호를 넘기는지 확인한다.
 *
 * @scenario case=search_cursor_page_propagation
 *
 * @effects every_uses_cursor_call_forwards_page
 */
class SearchCursorPagePropagationParityTest extends TestCase
{
    /** 판정 함수 호출 형태 */
    private const CALL_NEEDLE = 'SearchPagePolicy::usesCursor(';

    /** 훑을 소스 루트 (선언 파일 자신은 제외) */
    private const SOURCE_ROOTS = [
        'app',
        'modules/_bundled',
        'plugins/_bundled',
    ];

    /**
     * 커서 판정을 호출하는 모든 곳이 페이지 번호를 함께 넘기는지 확인
     *
     * @effects every_uses_cursor_call_forwards_page
     */
    public function test_every_uses_cursor_call_forwards_the_page_number(): void
    {
        $callSites = $this->collectCallSites();

        // 스캐너가 아무것도 못 찾으면 초록은 아무 의미가 없다 — 모집단부터 단언한다.
        $this->assertNotEmpty(
            $callSites,
            self::CALL_NEEDLE.' 호출을 한 건도 찾지 못했다. 스캔 대상 경로가 잘못됐을 수 있다'
        );

        $missing = [];

        foreach ($callSites as $site) {
            if ($this->countArguments($site['arguments']) < 4 && ! str_contains($site['arguments'], 'page:')) {
                $missing[] = $site['file'].':'.$site['line'];
            }
        }

        $this->assertSame(
            [],
            $missing,
            "커서 판정에 페이지 번호를 넘기지 않는 호출이 있다. 커서 없이 깊은 페이지를 지목한 요청이\n".
            "첫 페이지로 조용히 되돌아간다.\n".
            implode("\n", $missing)
        );
    }

    /**
     * 저장소에서 커서 판정 호출 지점을 모읍니다.
     *
     * @return array<int, array{file: string, line: int, arguments: string}>
     */
    private function collectCallSites(): array
    {
        $sites = [];

        foreach ($this->phpFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false || ! str_contains($contents, self::CALL_NEEDLE)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));

            // 선언 파일과 문서 주석의 참조는 호출이 아니다.
            if (str_ends_with($relative, 'app/Search/SearchPagePolicy.php')) {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $line) {
                $offset = strpos($line, self::CALL_NEEDLE);

                if ($offset === false || $this->isComment($line)) {
                    continue;
                }

                $sites[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'arguments' => $this->extractArguments($line, $offset + strlen(self::CALL_NEEDLE) - 1),
                ];
            }
        }

        return $sites;
    }

    /**
     * 스캔 대상 PHP 파일 목록을 만듭니다.
     *
     * @return array<int, string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SOURCE_ROOTS as $root) {
            $path = base_path($root);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * 여는 괄호 위치에서 시작해 짝이 맞는 닫는 괄호까지의 인자 문자열을 잘라냅니다.
     *
     * @param  string  $line  호출이 있는 소스 한 줄
     * @param  int  $openParen  여는 괄호의 위치
     * @return string 괄호 안 인자 문자열
     */
    private function extractArguments(string $line, int $openParen): string
    {
        $depth = 0;
        $length = strlen($line);

        for ($i = $openParen; $i < $length; $i++) {
            if ($line[$i] === '(') {
                $depth++;
            } elseif ($line[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($line, $openParen + 1, $i - $openParen - 1);
                }
            }
        }

        // 여러 줄로 끊긴 호출은 그 줄에서 닫히지 않는다 — 남은 부분을 그대로 돌려준다.
        return substr($line, $openParen + 1);
    }

    /**
     * 괄호 중첩을 고려해 최상위 쉼표 기준으로 인자 수를 셉니다.
     *
     * @param  string  $arguments  괄호 안 인자 문자열
     * @return int 인자 수
     */
    private function countArguments(string $arguments): int
    {
        if (trim($arguments) === '') {
            return 0;
        }

        $depth = 0;
        $count = 1;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 그 줄이 주석인지 판정합니다.
     *
     * @param  string  $line  소스 한 줄
     * @return bool 주석이면 true
     */
    private function isComment(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*');
    }
}
