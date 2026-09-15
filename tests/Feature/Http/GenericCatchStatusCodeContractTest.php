<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * generic catch 의 상태코드 · 예외 원문 노출 전수 계약 (#104)
 *
 * `catch (\Exception)` / `catch (\Throwable)` 는 도메인 예외가 아닌 것을 잡는 자리다.
 * 여기서 4xx 를 돌려주면 인프라 장애·코드 결함이 "입력 오류" 로 위장되어 장애 인지가 늦어진다.
 * 그리고 이미 번역된 예외 메시지를 응답의 메시지 **키** 자리에 넘기면 키 해석에 실패해
 * 원문(스택 힌트·SQL 상태코드 포함)이 그대로 사용자 화면에 나간다.
 *
 * 이 계약은 코어 한 곳에서만 세워졌던 것이 아니다 — 같은 형태가 코어와 번들 확장 양쪽에
 * 흩어져 있었다. 판정기를 한 확장 안에 두면 그 확장 밖에서 같은 결함이 재발해도 red 가
 * 되지 않으므로, 코어 + 모든 번들 모듈/플러그인의 컨트롤러를 한 번에 훑는다.
 */
class GenericCatchStatusCodeContractTest extends TestCase
{
    /**
     * 의도적으로 4xx 를 유지하는 지점 (사유를 함께 기록한다).
     *
     * @var array<string, string>
     */
    private const INTENTIONAL_4XX = [
        // 업로드된 zip 의 manifest 해석 실패는 사용자가 올린 파일의 문제다 —
        // 미리보기 단계이므로 설치 부작용이 없고, 운영자는 파일을 고쳐 다시 올리면 된다.
        'app/Http/Controllers/Api/Admin/LanguagePackController.php::manifestPreview' => '업로드 파일 해석 실패 = 클라이언트 입력 오류',
        'app/Http/Controllers/Api/Admin/ModuleController.php::manifestPreview' => '업로드 파일 해석 실패 = 클라이언트 입력 오류',
        'app/Http/Controllers/Api/Admin/PluginController.php::manifestPreview' => '업로드 파일 해석 실패 = 클라이언트 입력 오류',
        'app/Http/Controllers/Api/Admin/TemplateController.php::manifestPreview' => '업로드 파일 해석 실패 = 클라이언트 입력 오류',
        // 카테고리 이미지 서빙은 요청 파라미터 기반 파일 해석(검증성 로직) 비중이 크다.
        'modules/_bundled/sirsoft-ecommerce/src/Http/Controllers/Admin/CategoryController.php::downloadImage' => '검증성 로직(요청 파라미터 기반 파일 해석) 비중이 큼',
        'modules/_bundled/sirsoft-ecommerce/src/Http/Controllers/Public/CategoryImageController.php::download' => '검증성 로직(요청 파라미터 기반 파일 해석) 비중이 큼',
    ];

    /**
     * 예외 원문을 메시지 키로 넘기는 것이 아직 남아 있는 지점.
     *
     * 확장 설치 경로 6곳(모듈·플러그인·템플릿 × from-file/from-github)이 여기 있었다.
     * 도메인 실패가 이미 `*OperationException`(errorKey + params)으로 승격되어 있었는데
     * catch 만 부모 `\RuntimeException` 으로 남아, 이미 번역된 문장을 키 자리로 넘기고
     * 있었다. typed catch 로 좁히고 원본 키·파라미터를 넘기도록 바꿔 전부 해소했다.
     *
     * 비운 채로 둔다 — 새 위반이 생기면 그 자리에서 실패해야 한다.
     *
     * @var array<int, string>
     */
    private const KNOWN_EXCEPTION_TEXT_AS_KEY = [];

    /**
     * 광역 `\RuntimeException` catch 가 4xx 를 반환하는 것이 아직 남아 있는 지점.
     *
     * `\RuntimeException` 은 도메인 예외의 부모가 되기 쉬워, 도메인 실패를 typed 로
     * 승격한 뒤에도 이 catch 를 남겨 두면 남는 것은 인프라 예외뿐인데 그것까지 4xx 로
     * 뭉갠다. 위 `KNOWN_EXCEPTION_TEXT_AS_KEY` 와 같은 줄이었고 함께 해소되었다.
     *
     * 비운 채로 둔다 — 새 위반이 생기면 그 자리에서 실패해야 한다.
     *
     * @var array<string, string>
     */
    private const KNOWN_BROAD_RUNTIME_CATCH_4XX = [];

    /**
     * 스캔 대상 컨트롤러 루트 목록 (코어 + 번들 모듈/플러그인).
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function controllerRoots(): array
    {
        $roots = [base_path('app/Http/Controllers')];

        // 컨트롤러 위치는 확장마다 규약이 갈린다 — 모듈·일부 플러그인은
        // `src/Http/Controllers`, PG 플러그인 4종은 `src/Controllers` 를 쓴다.
        // 한 규약만 보면 다른 규약을 쓰는 확장이 통째로 사각이 된다(실제로 PG
        // 플러그인 44파일이 그랬고, 그 안에 위반 1건이 살아 있었다).
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
     * 루트 아래 PHP 파일을 저장소 상대 경로로 수집합니다.
     *
     * @return array<int, array{path: string, rel: string}>
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

        usort($files, fn ($a, $b) => strcmp($a['rel'], $b['rel']));

        return $files;
    }

    /**
     * generic catch 블록의 응답 상태코드를 추출합니다.
     *
     * @return array<int, array{file: string, method: string, line: int, status: int}>
     */
    private function collectGenericCatchStatuses(): array
    {
        $found = [];

        foreach ($this->collectFiles() as $file) {
            $lines = file($file['path'], FILE_IGNORE_NEW_LINES);
            $method = '?';

            foreach ($lines as $i => $line) {
                if (preg_match('/public function (\w+)\(/', $line, $m)) {
                    $method = $m[1];
                }

                // 광역 catch: \Exception / \Throwable / \RuntimeException 단독
                //
                // `\RuntimeException` 을 포함하는 이유는 그것이 도메인 예외의 부모가 되기
                // 때문이다 — 서비스가 도메인 실패를 typed 로 승격하고 나면 이 catch 에
                // 남는 것은 인프라 예외뿐인데, 4xx 를 돌려주면 그대로 위장된다. 실제로
                // `User/ProductInquiryController` 4곳이 이 사각에 있었다.
                if (! preg_match('/\}\s*catch\s*\(\s*\\\\?(Exception|Throwable|RuntimeException)\s*(\$\w+)?\s*\)/', $line)) {
                    continue;
                }

                $status = null;
                for ($j = $i + 1; $j < min($i + 25, count($lines)); $j++) {
                    if (preg_match('/\}\s*catch\s*\(/', $lines[$j])) {
                        break;
                    }
                    if (preg_match('/^\s*(\d{3}),?\s*$/', $lines[$j], $sm)) {
                        $status = (int) $sm[1];
                        break;
                    }
                    // ->error('key', 404) 처럼 한 줄에 쓰인 형태.
                    // 첫 인자에 함수 호출이 올 수 있으므로(`error($e->getMessage(), 422)`)
                    // 괄호를 배제하지 않는다 — 배제하면 그 형태를 통째로 놓친다.
                    if (preg_match('/(?:error|moduleError)\(.*?,\s*(\d{3})\s*[,)]/', $lines[$j], $sm)) {
                        $status = (int) $sm[1];
                        break;
                    }
                    // 상태코드 인자를 아예 생략한 한 줄 호출 — ResponseHelper 기본값 400 이 적용된다.
                    // 숫자만 찾는 판정은 이 형태를 통째로 놓친다 (실제 사각이었다: WishlistController).
                    if (preg_match("/moduleError\(\s*'[^']+',\s*'[^']+'\s*\)/", $lines[$j])
                        || preg_match("/[^e]error\(\s*'[^']+'\s*\)/", $lines[$j])) {
                        $status = 400;
                        break;
                    }
                }

                if ($status !== null) {
                    $found[] = ['file' => $file['rel'], 'method' => $method, 'line' => $i + 1, 'status' => $status];
                }
            }
        }

        return $found;
    }

    /**
     * 판정기가 실제로 코어와 확장 양쪽을 보고 있는지 먼저 확인한다.
     *
     * 모집단이 비거나 한쪽으로 쏠리면 이후 단언은 초록이어도 아무것도 증명하지 못한다.
     */
    public function test_scanner_population_covers_core_and_bundled_extensions(): void
    {
        $files = $this->collectFiles();
        $rels = array_column($files, 'rel');

        $this->assertNotEmpty($rels, '컨트롤러 스캔 결과가 비어 있습니다 — 판정기가 대상을 못 찾고 있습니다.');

        $core = array_filter($rels, fn ($r) => str_starts_with($r, 'app/Http/Controllers/'));
        $ext = array_filter($rels, fn ($r) => str_starts_with($r, 'modules/_bundled/') || str_starts_with($r, 'plugins/_bundled/'));

        $this->assertNotEmpty($core, '코어 컨트롤러가 모집단에 없습니다.');
        $this->assertNotEmpty($ext, '번들 확장 컨트롤러가 모집단에 없습니다.');

        // 이 브랜치가 계약을 세운 세 영역이 모두 잡혀야 한다
        $this->assertContains('modules/_bundled/sirsoft-ecommerce/src/Http/Controllers/Public/OrderController.php', $rels);
        $this->assertContains('modules/_bundled/sirsoft-board/src/Http/Controllers/Admin/BoardTypeController.php', $rels);
        $this->assertContains('app/Http/Controllers/Api/Admin/LanguagePackController.php', $rels);

        // 두 컨트롤러 규약이 모두 잡혀야 한다. `src/Http/Controllers` 앵커만 두면
        // `src/Controllers` 를 쓰는 확장이 빠져도 이 테스트가 초록으로 남는다.
        $this->assertContains('plugins/_bundled/sirsoft-pay_nicepayments/src/Controllers/AdminEscrowController.php', $rels);

        // 규약별 확장 수를 세어, 어느 한쪽 규약이 통째로 빠지는 회귀를 막는다.
        $byConvention = [];
        foreach ($rels as $rel) {
            if (preg_match('#^(modules|plugins)/_bundled/([^/]+)/(src/Http/Controllers|src/Controllers)/#', $rel, $m)) {
                $byConvention[$m[3]][$m[2]] = true;
            }
        }

        $this->assertArrayHasKey('src/Http/Controllers', $byConvention, '`src/Http/Controllers` 규약 확장이 모집단에 없습니다.');
        $this->assertArrayHasKey('src/Controllers', $byConvention, '`src/Controllers` 규약 확장이 모집단에 없습니다 — PG 플러그인이 사각이 됩니다.');
    }

    /**
     * generic catch 는 5xx 를 반환해야 한다 (의도적 예외는 상수에 명시).
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects update_shipping_address_infrastructure_exception_returns_500_not_422, guest_confirm_option_has_no_domain_exception_so_generic_catch_returns_500
     */
    public function test_generic_catch_blocks_never_return_4xx(): void
    {
        $sites = $this->collectGenericCatchStatuses();

        $this->assertNotEmpty($sites, 'generic catch 를 하나도 찾지 못했습니다 — 판정기 정규식을 확인하세요.');

        $declared = self::INTENTIONAL_4XX + self::KNOWN_BROAD_RUNTIME_CATCH_4XX;

        $violations = [];
        $hit = [];
        foreach ($sites as $s) {
            if ($s['status'] < 400 || $s['status'] >= 500) {
                continue;
            }
            $key = $s['file'].'::'.$s['method'];
            if (array_key_exists($key, $declared)) {
                $hit[$key] = true;

                continue;
            }
            $violations[] = "{$s['file']}:{$s['line']} ({$s['method']}) -> {$s['status']}";
        }

        $this->assertSame(
            [],
            $violations,
            "광역 catch 가 4xx 를 반환합니다 (인프라 장애가 입력 오류로 위장됨):\n".implode("\n", $violations)
        );

        // 면제 목록도 모집단이다 — 해소된 지점이 남아 있으면 "알고 남긴 것" 과
        // "이미 고쳐진 것" 이 구분되지 않아, 다음 사람이 그 줄을 근거로 삼는다.
        // `KNOWN_EXCEPTION_TEXT_AS_KEY` 는 이 검사를 갖고 있었는데 4xx 축에는 없었다.
        $stale = array_values(array_diff(array_keys($declared), array_keys($hit)));

        $this->assertSame(
            [],
            $stale,
            "면제 목록에 이미 해소된 항목이 남아 있습니다 (목록을 줄이세요):\n".implode("\n", $stale)
        );
    }

    /**
     * `$e->getMessage(),` 한 줄이 응답 헬퍼의 **메시지 키 자리**인지 판정한다.
     *
     * 여는 호출을 위로 되짚어 `error(` / `moduleError(` 를 찾고, 그 사이에 놓인
     * 인자 줄 수로 위치를 센다. `error()` 는 0번째, `moduleError()` 는 확장 식별자
     * 다음인 1번째가 메시지 키다. 그 밖의 호출(도메인 서비스·리다이렉트 헬퍼)은
     * 아무리 같은 모양이어도 응답 키가 아니므로 위반이 아니다.
     *
     * @param  array<int, string>  $lines  파일 전체 줄
     * @param  int  $index  `$e->getMessage(),` 가 있는 줄의 0-based 인덱스
     * @return bool 메시지 키 자리면 true
     */
    private function isResponseMessageKeyPosition(array $lines, int $index): bool
    {
        $argLinesBefore = 0;

        // 여는 줄은 멀지 않다 — 인자 6개를 넘겨 쓰는 응답 헬퍼는 없다.
        for ($j = $index - 1; $j >= 0 && $j >= $index - 7; $j--) {
            $candidate = trim($lines[$j]);

            if ($candidate === '') {
                continue;
            }

            // 여는 호출 발견 — 위치로 판정한다.
            if (preg_match('/(?:ResponseHelper::)?(?:->)?\b(error|moduleError)\($/', $candidate, $m)) {
                $keyPosition = $m[1] === 'moduleError' ? 1 : 0;

                return $argLinesBefore === $keyPosition;
            }

            // 여는 줄에 도달하기 전의 다른 호출/블록 경계면 응답 헬퍼가 아니다.
            if (str_ends_with($candidate, '(') || str_ends_with($candidate, '{') || str_ends_with($candidate, ';')) {
                return false;
            }

            $argLinesBefore++;
        }

        return false;
    }

    /**
     * 예외 메시지 원문을 응답 메시지 키 자리에 넘기지 않는다.
     *
     * `error($e->getMessage(), 422)` 는 이미 번역된 문장을 키로 해석하려다 실패해
     * 원문을 그대로 노출한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects update_shipping_address_500_response_excludes_exception_message, guest_action_5xx_responses_exclude_exception_message
     */
    public function test_exception_message_is_not_passed_as_response_message_key(): void
    {
        $violations = [];

        foreach ($this->collectFiles() as $file) {
            $lines = file($file['path'], FILE_IGNORE_NEW_LINES);

            foreach ($lines as $i => $line) {
                // 한 줄 형태: error($e->getMessage(), 422) / moduleError('mod', $e->getMessage(), …)
                if (preg_match('/(?:error|moduleError)\((?:[^,]+,\s*)?\$e->getMessage\(\)/', $line)) {
                    $violations[] = $file['rel'].':'.($i + 1);

                    continue;
                }

                // 여러 줄 형태: 인자가 줄마다 놓인 호출에서 `$e->getMessage(),` 만 있는 줄.
                // 이 모양 자체는 응답과 무관한 곳에도 흔하다 — 도메인 서비스 호출
                // (`failPayment($order, 'CODE', $e->getMessage())`)이나 리다이렉트 헬퍼
                // 인자가 그렇다. 여는 호출을 되짚어 **메시지 키 자리**일 때만 위반이다.
                if (preg_match('/^\s*\$e->getMessage\(\),\s*$/', $line)
                    && $this->isResponseMessageKeyPosition($lines, $i)) {
                    $violations[] = $file['rel'].':'.($i + 1);
                }
            }
        }

        $unexpected = array_values(array_diff($violations, self::KNOWN_EXCEPTION_TEXT_AS_KEY));
        $stale = array_values(array_diff(self::KNOWN_EXCEPTION_TEXT_AS_KEY, $violations));

        $this->assertSame(
            [],
            $unexpected,
            "예외 메시지 원문이 응답 메시지 키로 전달됩니다:\n".implode("\n", $unexpected)
        );

        $this->assertSame(
            [],
            $stale,
            "KNOWN_EXCEPTION_TEXT_AS_KEY 에 이미 해소된 항목이 남아 있습니다 (목록을 줄이세요):\n".implode("\n", $stale)
        );
    }
}
