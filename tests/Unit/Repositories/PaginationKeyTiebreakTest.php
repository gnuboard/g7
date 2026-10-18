<?php

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 페이지네이션 정렬의 **전순서(total order) 보장** 회귀 가드.
 *
 * 목록을 `ORDER BY created_at DESC` 처럼 **비고유 컬럼만으로** 정렬하고 페이지를 나누면,
 * 동률 구간의 행 순서가 페이지마다 달라질 수 있다(MySQL 은 동률의 순서를 보장하지 않는다).
 * 그 결과 인접 페이지에 **같은 행이 두 번 나오고 다른 행은 사라진다** — 사용자에게는
 * "목록에 있는데 넘기면 없다" 로 보이고, 전 페이지를 모아도 총건수와 개수가 맞지 않는다.
 *
 * 실측(#492 D-24): 관리자 역할 목록 110건 / per_page 20 / 6페이지를 전수 순회했더니
 * 수집 키 110개, 고유 키 **109개**, 인접 페이지 중복 1건이었다. `roles.created_at` 은
 * 한 타임스탬프에 최대 10행이 몰려 있다(시더 일괄 생성).
 *
 * 불변식: **페이지네이션 쿼리의 정렬은 마지막에 고유 키(기본키)를 덧붙여 전순서를 만든다.**
 *
 * `ResolvesSortSpec` / `PaginatesWithDeferredJoin` 경유 목록은 트레이트가 키 컬럼을 자동으로
 * 덧붙이므로 이 규율을 이미 만족한다. 손으로 `orderBy` 를 조립한 뒤 `paginate()` 하는 지점만
 * 위험하다 — 그 지점을 소스에서 도출해 전수 검사한다(손으로 나열하지 않는다).
 */
class PaginationKeyTiebreakTest extends TestCase
{
    /**
     * 검사 대상 Repository 파일 목록.
     *
     * @return array<string, array{0: string}>
     */
    public static function repositoryFileProvider(): array
    {
        // 데이터 프로바이더는 앱 부팅 전에 평가되므로 base_path() 를 쓸 수 없다.
        $root = dirname(__DIR__, 3);

        $roots = [
            $root.'/app/Repositories',
            $root.'/modules/_bundled',
            $root.'/plugins/_bundled',
        ];

        $cases = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if (! str_ends_with($path, 'Repository.php')) {
                    continue;
                }

                if (preg_match('#/(vendor|node_modules|tests|docs)/#', $path)) {
                    continue;
                }

                $cases[str_replace(str_replace('\\', '/', $root).'/', '', $path)] = [$path];
            }
        }

        ksort($cases);

        return $cases;
    }

    #[DataProvider('repositoryFileProvider')]
    public function test_페이지네이션_메서드는_고유키_타이브레이크를_갖는다(string $path): void
    {
        $source = file_get_contents($path);
        $violations = [];
        $methods = self::splitMethods($source);

        foreach ($methods as $name => $rawBody) {
            if (! preg_match('/->paginate\(|->simplePaginate\(/', $rawBody)) {
                continue;
            }

            // 같은 클래스의 헬퍼(`$this->applySort(...)` 등)에 정렬을 위임한 경우 그 본문까지 함께 본다.
            $body = $rawBody;
            foreach (self::calledHelpers($rawBody) as $helper) {
                if (isset($methods[$helper])) {
                    $body .= "\n".$methods[$helper];
                }
            }

            // 트레이트 경유 목록은 키 컬럼이 자동으로 덧붙는다.
            if (preg_match('/paginateWithDeferredJoin\(|resolveSortSpec|applySortSpec/', $body)) {
                continue;
            }

            // 면제 선언 (사유 필수)
            if (preg_match('/pagination-key-tiebreak\s+reason:/', $body)) {
                continue;
            }

            if (! self::hasKeyTiebreak($body)) {
                $violations[] = $name;
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "%s 의 다음 메서드가 페이지네이션 정렬에 고유 키 타이브레이크를 갖지 않는다:\n  - %s\n".
                '동률 구간에서 인접 페이지가 같은 행을 중복 노출하고 다른 행을 누락한다. '.
                "정렬 마지막에 기본키(`->orderBy('id', ...)`)를 덧붙이거나 ResolvesSortSpec 을 경유할 것.",
                basename($path),
                implode("\n  - ", $violations)
            )
        );
    }

    /**
     * 클래스 소스를 메서드 단위로 분할합니다.
     *
     * @param  string  $source  PHP 소스
     * @return array<string, string> 메서드명 => 본문
     */
    private static function splitMethods(string $source): array
    {
        $parts = preg_split('/\n    (?:public|protected|private) function /', $source);

        if ($parts === false) {
            return [];
        }

        $methods = [];

        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^(\w+)/', $part, $m) === 1) {
                $methods[$m[1]] = $part;
            }
        }

        return $methods;
    }

    /**
     * 메서드 본문이 호출하는 같은 클래스 헬퍼 이름을 추출합니다.
     *
     * @param  string  $body  메서드 본문
     * @return list<string> 헬퍼 메서드명
     */
    private static function calledHelpers(string $body): array
    {
        preg_match_all('/\$this->(\w+)\(/', $body, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * 메서드 본문의 정렬이 고유 키로 끝나는지 판정합니다.
     *
     * @param  string  $body  메서드 본문
     * @return bool 키 타이브레이크 존재 여부
     */
    private static function hasKeyTiebreak(string $body): bool
    {
        // orderBy('id'|'uuid'|'x.id', ...) / orderByDesc('id') / orderByRaw('... id ...')
        return preg_match(
            '/orderBy(?:Desc)?\(\s*[\'"][\w.]*\b(?:id|uuid)[\'"]|orderByRaw\([^)]*\bid\b/',
            $body
        ) === 1;
    }
}
