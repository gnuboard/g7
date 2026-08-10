<?php

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 지연 조인 호출 계약 전수 검사
 *
 * 개별 저장소마다 페이지 경계 테스트를 손으로 나열하면 새 호출처가 늘어날 때 조용히 빠진다.
 * 여기서는 **호출처를 스캔해서 도출**한 뒤 계약 위반을 기계적으로 판정한다.
 *
 * 검사하는 계약 (PaginatesWithDeferredJoin docblock 의 "호출 계약"):
 *   - `columns:` 를 명시할 것 — 생략하면 outer 가 무엇을 읽는지 호출처에서 드러나지 않는다
 *   - `sort:` 를 명시하고 비우지 말 것 — 정렬이 비면 키 컬럼만 남아 화면 정렬이 사라진다
 *   - 넘기는 `$query` 에 관계를 미리 붙이지 말 것 — 트레이트가 inner 뿐 아니라 outer 에서도
 *     `setEagerLoads([])` 로 지우므로, `relations:` 없이 `with()` 만 하면 관계가 조용히 사라진다
 *
 * `columns: ['*']` 자체는 위반이 아니다. 지연 조인의 목적인 **OFFSET 구간의 넓은 컬럼 읽기**는
 * inner 가 키 컬럼만 읽는 것으로 이미 제거되고, outer 가 읽는 행 수는 페이지 크기로 고정된다.
 * 넓은 컬럼을 가진 테이블에서 컬럼을 좁히는 것은 그 위에 얹는 추가 최적화다.
 *
 * 관계 계약을 손으로 나열하지 않고 메서드 본문을 스캔해 판정하는 이유: 이 위반은 예외도
 * 쿼리 오류도 내지 않고 **응답에서 관계 필드만 사라진다**. HTTP 테스트가 그 필드를 단언하지
 * 않으면 전부 초록으로 통과한다(실제로 스케줄 실행 이력의 `triggered_by` 가 이렇게 유실됐다).
 *
 * @scenario case=deferred_join_call_site_contract
 *
 * @effects call_sites_declare_columns, call_sites_declare_sort, call_sites_pass_relations_as_argument
 */
class DeferredJoinCallSiteContractTest extends TestCase
{
    /** 스캔 대상 루트 (저장소 상대) */
    private const SCAN_ROOTS = ['app', 'modules/_bundled', 'plugins/_bundled'];

    /**
     * 지연 조인 호출처를 스캔해 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: int, 2: string}> [파일, 줄번호, 인자 텍스트]
     */
    public static function callSiteProvider(): array
    {
        // tests/Unit/Repositories/Concerns → 저장소 루트
        $root = dirname(__DIR__, 4);
        $cases = [];

        foreach (self::SCAN_ROOTS as $scanRoot) {
            $base = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $scanRoot);

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                // 트레이트 본체와 테스트 코드는 호출처가 아니다
                if (str_contains($path, '/tests/') || str_contains($path, '/Concerns/PaginatesWithDeferredJoin.php')) {
                    continue;
                }

                $content = file_get_contents($path);

                if (! str_contains($content, 'paginateWithDeferredJoin(')) {
                    continue;
                }

                foreach (self::extractCallSites($content) as [$line, $args]) {
                    $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
                    $cases["{$relative}:{$line}"] = [$relative, $line, $args];
                }
            }
        }

        return $cases;
    }

    /**
     * 파일 내용에서 호출처의 인자 텍스트를 추출합니다.
     *
     * @param  string  $content  파일 내용
     * @return array<int, array{0: int, 1: string}> [줄번호, 인자 텍스트]
     */
    private static function extractCallSites(string $content): array
    {
        $sites = [];
        $offset = 0;

        while (($pos = strpos($content, 'paginateWithDeferredJoin(', $offset)) !== false) {
            $open = $pos + strlen('paginateWithDeferredJoin(') - 1;
            $depth = 0;
            $end = null;

            for ($i = $open; $i < strlen($content); $i++) {
                $ch = $content[$i];

                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            if ($end === null) {
                break;
            }

            $line = substr_count(substr($content, 0, $pos), "\n") + 1;
            $sites[] = [$line, substr($content, $open + 1, $end - $open - 1)];
            $offset = $end;
        }

        return $sites;
    }

    /**
     * 호출처를 그 호출을 감싸는 메서드 본문과 함께 돌려줍니다.
     *
     * 본문에서 호출 인자 텍스트는 제거한다 — `relations:` 값이나 `outerUsing:` 클로저 안의
     * `with()` 는 정상 사용이므로 검사 대상이 아니다.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}> [파일, 줄번호, 인자, 인자 제외 메서드 본문]
     */
    public static function callSiteWithMethodBodyProvider(): array
    {
        $root = dirname(__DIR__, 4);
        $cases = [];

        foreach (self::callSiteProvider() as $key => [$relative, $line, $args]) {
            $content = file_get_contents($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $body = self::enclosingMethodBody($content, $line);

            // 호출 인자 텍스트 제거 — 그 안의 with() 는 트레이트가 outer 에 적용하는 정상 경로다
            $cases[$key] = [$relative, $line, $args, str_replace($args, '', $body)];
        }

        return $cases;
    }

    /**
     * 지정한 줄을 감싸는 메서드 본문을 돌려줍니다.
     *
     * @param  string  $content  파일 내용
     * @param  int  $line  호출이 위치한 줄번호
     * @return string 메서드 본문 (찾지 못하면 빈 문자열)
     */
    private static function enclosingMethodBody(string $content, int $line): string
    {
        $offset = 0;
        $lines = explode("\n", $content);
        $target = 0;

        for ($i = 0; $i < $line - 1; $i++) {
            $target += strlen($lines[$i]) + 1;
        }

        preg_match_all('/^\s{4}(?:public|protected|private)\s+function\s+\w+/m', $content, $m, PREG_OFFSET_CAPTURE);
        $starts = array_map(fn ($x) => $x[1], $m[0]);
        $starts[] = strlen($content);

        for ($i = 0; $i < count($starts) - 1; $i++) {
            if ($starts[$i] <= $target && $target < $starts[$i + 1]) {
                return substr($content, $starts[$i], $starts[$i + 1] - $starts[$i]);
            }
            $offset = $starts[$i];
        }

        return '';
    }

    #[DataProvider('callSiteWithMethodBodyProvider')]
    public function test_call_site_passes_relations_as_argument(string $file, int $line, string $args, string $body): void
    {
        $appliesEagerLoad = preg_match('/(->|::)\s*with(Count)?\s*\(/', $body) === 1;

        if (! $appliesEagerLoad) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertMatchesRegularExpression(
            '/\b(relations|withCount)\s*:/',
            $args,
            "{$file}:{$line} — 쿼리에 with()/withCount() 를 붙였는데 relations:/withCount: 인자가 없다. "
            .'트레이트가 outer 에서도 eager load 를 지우므로 관계가 조용히 사라진다 (예외도 오류도 나지 않는다).'
        );
    }

    #[DataProvider('callSiteProvider')]
    public function test_call_site_declares_columns(string $file, int $line, string $args): void
    {
        $this->assertMatchesRegularExpression(
            '/\bcolumns\s*:/',
            $args,
            "{$file}:{$line} — columns: 를 명시해야 한다 (미지정 시 outer 가 읽는 범위가 호출처에서 보이지 않는다)"
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bcolumns\s*:\s*\[\s*\]/',
            $args,
            "{$file}:{$line} — 빈 컬럼 목록은 어떤 컬럼도 선택하지 않는다"
        );
    }

    #[DataProvider('callSiteProvider')]
    public function test_call_site_declares_sort_spec(string $file, int $line, string $args): void
    {
        $this->assertMatchesRegularExpression(
            '/\bsort\s*:/',
            $args,
            "{$file}:{$line} — sort: 를 명시해야 한다 (미지정 시 키 컬럼 정렬만 남는다)"
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bsort\s*:\s*\[\s*\]/',
            $args,
            "{$file}:{$line} — 빈 정렬 스펙은 화면 정렬을 잃는다"
        );
    }

    public function test_scan_finds_call_sites(): void
    {
        // 스캔이 0건이면 위 두 테스트가 조용히 통과해 계약 검사가 무력화된다
        $this->assertNotEmpty(self::callSiteProvider(), '지연 조인 호출처를 하나도 찾지 못했다 — 스캔 경로를 확인할 것');
    }
}
