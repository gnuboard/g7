<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * 실패 리다이렉트 쿼리에 예외 원문을 싣지 않는다는 전수 계약
 *
 * 결제 콜백은 응답 본문이 아니라 **리다이렉트 쿼리스트링**으로 실패 사유를 전달한다.
 * 그래서 응답 메시지 키를 보는 계약(GenericCatchStatusCodeContractTest)의 축 밖에 있고,
 * 여기에 `$e->getMessage()` 를 실으면 브라우저 주소창과 참조 로그에 내부 오류 원문
 * (SQL 상태코드·클래스명·경로)이 그대로 남는다. 결제 실패 페이지는 이 값을 화면에
 * 그대로 출력하므로 구매자에게도 노출된다.
 *
 * 사유 식별은 같은 쿼리의 `error` 코드가 담당하고, 사람에게 보일 문구는 다국어 키로
 * 해석한 문장이어야 한다.
 */
class RedirectQueryExceptionTextContractTest extends TestCase
{
    /**
     * 아직 해소하지 못한 지점 (사유와 함께 선언한다).
     *
     * 목록이 늘어나면 실패하고, 해소된 항목이 남아 있어도 실패한다.
     *
     * @var array<int, string>
     */
    private const KNOWN_REDIRECT_EXCEPTION_TEXT = [];

    /**
     * 스캔 대상 컨트롤러 루트 목록 (코어 + 번들 모듈/플러그인).
     *
     * 컨트롤러 위치 규약은 확장마다 갈린다 — 결제대행사 플러그인은 `src/Controllers`,
     * 그 밖은 `src/Http/Controllers` 를 쓴다. 한 규약만 보면 다른 규약이 통째로 사각이 된다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function controllerRoots(): array
    {
        $roots = [base_path('app/Http/Controllers')];
        $conventions = ['src/Http/Controllers', 'src/Controllers'];

        foreach (['modules', 'plugins'] as $kind) {
            $bundled = base_path($kind.'/_bundled');
            if (! is_dir($bundled)) {
                continue;
            }
            foreach (scandir($bundled) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                foreach ($conventions as $convention) {
                    $dir = $bundled.'/'.$entry.'/'.$convention;
                    if (is_dir($dir)) {
                        $roots[] = $dir;
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * 스캔 대상 PHP 파일을 저장소 상대 경로와 함께 수집합니다.
     *
     * @return array<int, array{path: string, rel: string}> 파일 목록
     */
    private function collectFiles(): array
    {
        $files = [];
        $prefix = str_replace('\\', '/', base_path()).'/';

        foreach ($this->controllerRoots() as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) {
                    continue;
                }
                $full = str_replace('\\', '/', $f->getPathname());
                $files[] = ['path' => $f->getPathname(), 'rel' => str_replace($prefix, '', $full)];
            }
        }

        return $files;
    }

    /**
     * 판정기가 실제로 결제대행사 플러그인을 보고 있는지 스스로 단언합니다.
     *
     * 모집단이 비어 있으면 이 계약은 아무것도 증명하지 못한 채 초록이 된다.
     *
     * @scenario contract_check=scanner_population
     *
     * @effects redirect_contract_scanner_population_covers_payment_gateway_plugins
     */
    public function test_scanner_population_covers_payment_gateway_plugins(): void
    {
        $rels = array_column($this->collectFiles(), 'rel');

        $this->assertNotEmpty($rels, '스캔 대상 파일이 비어 있다.');

        foreach (['sirsoft-pay_kginicis', 'sirsoft-pay_nhnkcp', 'sirsoft-tosspayments', 'sirsoft-pay_nicepayments'] as $plugin) {
            $hit = array_filter($rels, fn ($r) => str_contains($r, 'plugins/_bundled/'.$plugin.'/'));
            $this->assertNotEmpty($hit, $plugin.' 의 컨트롤러가 모집단에 없다.');
        }

        // 리다이렉트 쿼리를 조립하는 지점 자체가 모집단에 있어야 한다.
        $withFailUrl = array_filter(
            $this->collectFiles(),
            fn ($f) => str_contains((string) file_get_contents($f['path']), 'resolveFailUrl(')
        );
        $this->assertNotEmpty($withFailUrl, 'resolveFailUrl 을 쓰는 파일이 모집단에 없다.');
    }

    /**
     * 리다이렉트 쿼리의 사람이 볼 값에 예외 원문을 싣지 않는다.
     *
     * @scenario contract_check=redirect_query_text
     *
     * @effects redirect_query_omits_exception_text_repository_wide
     */
    public function test_redirect_query_does_not_carry_exception_text(): void
    {
        $violations = [];

        foreach ($this->collectFiles() as $file) {
            $source = (string) file_get_contents($file['path']);
            if (! str_contains($source, '$e->getMessage()')) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];
            foreach ($lines as $i => $line) {
                // `'message' => $e->getMessage(),` 처럼 사람이 볼 쿼리 값 자리에 원문을 넣는 형태.
                if (! preg_match('/[\'"](message|msg|reason|detail)[\'"]\s*=>\s*\$e->getMessage\(\)/', $line)) {
                    continue;
                }

                // 리다이렉트 URL 조립 블록 안인지 — 위쪽 12줄에서 조립 호출을 되짚는다.
                $context = implode("\n", array_slice($lines, max(0, $i - 12), min(12, $i)));
                if (! preg_match('/resolveFailUrl\(|redirect\(|RedirectResponse|->away\(/', $context)) {
                    continue;
                }

                $violations[] = $file['rel'].':'.($i + 1);
            }
        }

        $unexpected = array_values(array_diff($violations, self::KNOWN_REDIRECT_EXCEPTION_TEXT));
        $stale = array_values(array_diff(self::KNOWN_REDIRECT_EXCEPTION_TEXT, $violations));

        $this->assertSame([], $unexpected, "리다이렉트 쿼리에 예외 원문을 싣는 지점:\n".implode("\n", $unexpected));
        $this->assertSame([], $stale, "이미 해소되어 면제 목록에서 지워야 하는 항목:\n".implode("\n", $stale));
    }
}
