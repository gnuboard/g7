<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 화면의 마크업 계약.
 *
 * 여기서 잠그는 것들은 전부 **정상 화면에서 아무 증상도 내지 않는다** — 텍스트는 그대로
 * 보이고, 오류도 로그도 남지 않는다. 사람이 스크린샷을 봐도 지나간다. 그래서 정적 검사다.
 *
 *  - #B32 장식 SVG 에 `aria-hidden`/`focusable` 이 없으면 판독기가 이름 없는 그래픽을 한 번 더 읽는다
 *  - #B33 인라인 `opacity` 는 색 토큰이 정상이어도 대비를 깎는다 (토큰 검사가 원리상 못 보는 채널)
 *  - #B34 통째로 갈아 끼워지는 영역에 라이브 리전이 없으면 결과가 화면 밖에 전달되지 않는다
 *  - #B36 `lang()` 값을 속성에 그대로 넣으면 따옴표가 든 번역문이 속성을 닫아 버린다
 *  - #B28 진행 중 눌리지 않는 트리거가 Tab 순서에 남으면 「죽은 버튼」이 된다
 *
 * 커버 축: markup_channel svg·inline_style·live_region·attribute_escape·inert_trigger
 */
class InstallerViewAccessibilityContractTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 저장소 안 설치기 폴더의 절대 경로를 만든다.
     *
     * @return string 설치기 폴더 경로
     */
    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 재귀 스캔 — 하위 디렉토리까지 훑는다.
     *
     * @param  string  $dir  뿌리 디렉토리
     * @param  array<int, string>  $extensions  대상 확장자
     * @return array<int, string> 파일 경로 (정렬됨)
     */
    private function scanFiles(string $dir, array $extensions): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && in_array(strtolower($entry->getExtension()), $extensions, true)) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $entry->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 검사 대상 파일 (인스톨러가 소유한 화면 표면).
     *
     * @return array<int, string> 화면 파일 전수와 진입 파일, 설치기 스크립트 경로
     */
    private function markupFiles(): array
    {
        // 재귀 스캔 — 한 겹 `glob('*.php')` 은 `views/` 아래에 디렉토리가 하나 생기는 순간
        // 그 안을 통째로 놓친다. 모집단 밖의 위반은 영원히 초록이다.
        $files = $this->scanFiles($this->installRoot().'/views', ['php']);
        $files[] = $this->installRoot().'/index.php';
        $files[] = $this->installRoot().'/assets/js/installer.js';

        return $files;
    }

    /**
     * 화면 파일의 모든 SVG 가 보조기술 숨김과 포커스 제외 속성을 함께 달았는지 줄 단위로 확인한다.
     *
     * @scenario accessibility_surface=decorative_svg
     *
     * @effects installer_decorative_svgs_are_hidden_from_assistive_tech
     *
     * @return void 반환값 없음
     */
    public function test_모든_장식_sv_g_가_보조기술에서_감춰진다(): void
    {
        $offenders = [];

        foreach ($this->markupFiles() as $file) {
            $lines = explode("\n", $this->sourceWithoutComments($file));
            foreach ($lines as $index => $line) {
                if (! preg_match('/<svg\b/', $line)) {
                    continue;
                }
                // 19차 G#6 — docblock 과 실패 문구가 **두 속성**을 계약으로 선언하는데
                // 단언은 `aria-hidden` 하나뿐이었다. `focusable="false"` 가 빠지면 SVG 가
                // Tab 순서에 들어와 키보드 사용자가 뜻 없는 정거장을 지나야 한다. 형제
                // 검사(`InstallerMonitorContractTest`)는 이미 두 속성을 함께 본다.
                if (str_contains($line, 'aria-hidden') && str_contains($line, 'focusable')) {
                    continue;
                }
                // 주석 안의 `<svg>` 는 마크업이 아니다 (이 결함을 설명하는 주석이 실재한다)
                if (preg_match('#^\s*(//|\*|/\*)#', $line)) {
                    continue;
                }
                $offenders[] = basename($file).':'.($index + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'aria-hidden 이 없는 SVG: '.implode(', ', $offenders)
            ."\n뜻은 형제 텍스트가 전한다 — 아이콘까지 읽히면 같은 항목이 두 번 읽힌다."
            ."\n저장소 표준은 getIconSvg() 의 `aria-hidden=\"true\" focusable=\"false\"` 다.",
        );
    }

    /**
     * 화면 파일의 인라인 style 이 투명도로 글자 대비를 깎지 않는지 확인한다.
     *
     * @scenario accessibility_surface=inline_opacity
     *
     * @effects installer_views_do_not_dim_text_with_inline_opacity
     *
     * @return void 반환값 없음
     */
    public function test_화면_파일이_인라인_style_로_글자를_흐리게_하지_않는다(): void
    {
        $offenders = [];

        foreach ($this->scanFiles($this->installRoot().'/views', ['php']) as $file) {
            $lines = explode("\n", $this->sourceWithoutComments($file));
            foreach ($lines as $index => $line) {
                if (preg_match('/style\s*=\s*"[^"]*opacity\s*:/i', $line)) {
                    $offenders[] = basename($file).':'.($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '인라인 style 의 opacity: '.implode(', ', $offenders)
            ."\n색 토큰이 기준을 지켜도 투명도가 대비를 깎는다 — 토큰만 보는 검사는 이 채널을 볼 수 없다."
            ."\n강조를 낮출 때는 보조 텍스트 토큰이나 굵기를 쓴다.",
        );
    }

    /**
     * 결과로 통째로 교체되는 영역을 소스에서 도출하고 그 영역마다 라이브 리전 선언이 있는지 확인한다.
     *
     * @scenario a11y_axis=live_region
     *
     * @effects installer_replaced_regions_declare_live_semantics
     *
     * @return void 반환값 없음
     */
    public function test_통째로_갈아_끼워지는_결과_영역이_라이브_리전을_선언한다(): void
    {
        // 스피너/빈 상태 → 결과로 **통째로** 교체되는 컨테이너. 교체 자체는 조용하므로
        // 라이브 리전 선언이 없으면 결과가 화면 밖에 전달되지 않는다.
        //
        // 19차 G#5 — 모집단을 **소스에서 도출한다**. 종전에는 7개를 손으로 적었고, 같은
        // 클래스의 형제 4개 메서드는 전부 `scanFiles()` 재귀 스캔으로 도출하는데 이 하나만
        // 하드코딩이었다. 「스피너 → 결과로 통째로 교체」 패턴은 화면이 늘 때마다 함께 느는데,
        // 목록을 사람이 갱신해야 하면 갱신을 잊는 순간 그 화면이 영원히 사각이 된다.
        //
        // 도출 규칙: id 가 `-result` 로 끝나는 `<div>` — 이 저장소가 그 패턴에 쓰는 이름 규약이다.
        $expected = [];
        foreach ($this->scanFiles($this->installRoot().'/views', ['php']) as $file) {
            $relative = 'views/'.basename($file);
            $source = $this->sourceWithoutComments($file);

            if (preg_match_all('/<div[^>]*\sid="([a-z0-9-]+-result)"/i', $source, $m)) {
                $expected[$relative] = array_values(array_unique($m[1]));
            }
        }

        $this->assertNotSame([], $expected, '결과 영역을 하나도 찾지 못했다 — 이름 규약이 바뀌었으면 도출 규칙도 함께 옮긴다');

        foreach ($expected as $relative => $ids) {
            $source = $this->sourceWithoutComments($this->installRoot().'/'.$relative);

            foreach ($ids as $id) {
                $matched = preg_match('/<div[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $source, $m);
                $this->assertSame(1, $matched, "{$relative} 에서 #{$id} 를 찾지 못했다");

                $this->assertStringContainsString(
                    'aria-live',
                    $m[0],
                    "#{$id} 에 라이브 리전 선언이 없다 ({$relative})"
                    ."\n검사가 끝나 결과가 나타났다는 사실이 화면 밖에서는 전달되지 않는다.",
                );
            }
        }
    }

    /**
     * 짧은 출력 태그로 속성값을 채우는 자리가 모두 이스케이프 헬퍼를 거치는지 확인한다(상수와 리터럴 삼항은 예외).
     *
     * @scenario accessibility_surface=attribute_escape
     *
     * @effects installer_translated_attribute_values_are_escaped
     *
     * @return void 반환값 없음
     */
    public function test_번역문을_속성값에_넣을_때_이스케이프한다(): void
    {
        $offenders = [];

        foreach (array_merge($this->scanFiles($this->installRoot().'/views', ['php']), [$this->installRoot().'/index.php']) as $file) {
            $lines = explode("\n", $this->sourceWithoutComments($file));
            foreach ($lines as $index => $line) {
                // 짧은 echo 태그로 속성값을 채우면서 이스케이프 헬퍼를 거치지 않은 것
                // 한 태그 안만 본다 — 닫는 태그를 넘겨 읽으면 같은 줄의 다음 태그까지 한 덩어리가 된다
                if (! preg_match_all('/[a-zA-Z-]+="<\?=((?:(?!\?>).)*)\?>/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $expression) {
                    $expression = trim($expression);

                    if (preg_match('/htmlspecialchars|json_encode|\be\(|urlencode|rawurlencode|installerJsString/', $expression)) {
                        continue;
                    }
                    // 코드 안의 상수는 사용자 입력·번역문이 아니다 (INSTALLER_BASE_URL 등)
                    if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $expression)) {
                        continue;
                    }
                    // 결과가 코드 안의 리터럴로 닫힌 삼항 — 값이 밖에서 오지 않는다
                    if (preg_match("/^[^?]+\?\s*'[^']*'\s*:\s*'[^']*'$/", $expression)) {
                        continue;
                    }

                    $offenders[] = basename($file).':'.($index + 1).' → '.$expression;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "이스케이프 없이 속성값에 들어가는 값:\n  ".implode("\n  ", $offenders)
            ."\n번역문에 따옴표가 들어가면 속성이 그 자리에서 닫힌다 — 형제 출력은 전부 htmlspecialchars 를 거친다.",
        );
    }

    /**
     * 설치 중 진행 카드 헤더가 처음에는 Tab 순서에서 빠져 비활성으로 알려지고, 끝난 뒤 다시 활성화하는 지점이 있는지 확인한다.
     *
     * @scenario accessibility_surface=focus_order
     *
     * @effects installer_running_card_header_is_inert
     *
     * @return void 반환값 없음
     */
    public function test_설치_중_진행_카드_헤더가_tab_순서에_남지_않는다(): void
    {
        $source = $this->sourceWithoutComments($this->installRoot().'/views/5-installation.php');

        $matched = preg_match('/<div[^>]*id="installation-card-header"[^>]*>/s', $source, $m);
        $this->assertSame(1, $matched, '진행 카드 헤더를 찾지 못했다');

        $header = $m[0];

        $this->assertStringContainsString(
            'tabindex="-1"',
            $header,
            '진행 카드 헤더가 처음부터 Tab 순서에 있다 — 설치가 끝나기 전에는 핸들러가 첫 줄에서 되돌아가므로 눌러도 아무 일이 없다',
        );
        $this->assertStringContainsString(
            'aria-disabled="true"',
            $header,
            '눌리지 않는다는 사실이 보조기술에 전달되지 않는다',
        );

        // 완료·실패·중단에서 해제하는 지점이 실제로 있어야 한다 — 없으면 영영 못 누른다.
        $installer = $this->sourceWithoutComments($this->installRoot().'/assets/js/installer.js');
        $this->assertStringContainsString(
            'setInstallationCardInteractive(true)',
            $installer,
            '설치가 끝난 뒤 헤더를 다시 활성화하는 지점이 없다 — 접기/펼치기가 영영 막힌다',
        );
    }

    /**
     * 라이브 리전 역할을 갖는 `role` 값 (암묵 `aria-live` 를 동반한다).
     *
     * `role="status"`·`role="log"`·`role="marquee"` 는 암묵 polite, `role="alert"` 는 암묵
     * assertive, `role="timer"` 는 암묵 off 다. 즉 `aria-live` 를 적지 않아도 리전이 선다 —
     * 리터럴 `aria-live` 하나만 세는 검사는 이 축을 통째로 놓친다.
     *
     * @return string 정규식 대안 목록 (`alert|status|…`)
     */
    private function liveRegionRoleAlternation(): string
    {
        return 'alert|status|log|marquee|timer';
    }

    /**
     * installer.js 안의 라이브 리전 **선언 전수**를 도출한다.
     *
     * 두 표기 모두 본다 — 템플릿 문자열의 리터럴 속성(`role="status"` · `aria-live="polite"`)과
     * DOM API 호출(`setAttribute('role', 'alert')` · `setAttribute('aria-live', …)`). 한 표기만
     * 세면 다른 표기로 쓰인 선언이 영원히 사각이다(종전 검사가 정확히 그랬다).
     *
     * 각 선언에는 **그것을 그리는 함수**를 함께 붙인다 — 중첩 여부는 「그 조각이 어디에
     * 붙는가」로 갈리고, 그 판별자가 함수 단위이기 때문이다.
     *
     * @return array<string, array{line: int, function: string, snippet: string, appendsToBody: bool}> 라이브 리전 선언 목록
     *                                                                                                 키는 `줄번호:함수명` (한 줄이 두 패턴에 걸려도 한 건으로 접는다)
     */
    private function jsLiveRegionDeclarations(): array
    {
        $source = $this->sourceWithoutComments($this->installRoot().'/assets/js/installer.js');
        $live = $this->liveRegionRoleAlternation();

        // 함수 경계 도출 — 이름을 손으로 적지 않는다. 각 함수는 다음 함수 선언 직전까지다.
        $functions = [];
        if (preg_match_all('/^[ \t]*(?:async[ \t]+)?function[ \t]+(\w+)[ \t]*\(/m', $source, $fm, PREG_OFFSET_CAPTURE)) {
            foreach ($fm[1] as $index => $capture) {
                $functions[] = ['name' => $capture[0], 'start' => $fm[0][$index][1]];
            }
        }
        $functions[] = ['name' => '<eof>', 'start' => strlen($source)];

        $patterns = [
            '/\brole\s*=\s*"(?:'.$live.')"/',
            '/\baria-live\s*=\s*"/',
            '/\.setAttribute\(\s*([\'"])role\1\s*,\s*([\'"])(?:'.$live.')\2/',
            '/\.setAttribute\(\s*([\'"])aria-live\1/',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as $hit) {
                [$snippet, $offset] = $hit;
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;

                $name = '<top-level>';
                $start = 0;
                $end = $functions[0]['start'];
                for ($i = 0; $i < count($functions) - 1; $i++) {
                    if ($offset >= $functions[$i]['start'] && $offset < $functions[$i + 1]['start']) {
                        $name = $functions[$i]['name'];
                        $start = $functions[$i]['start'];
                        $end = $functions[$i + 1]['start'];
                        break;
                    }
                }

                $body = substr($source, $start, $end - $start);

                $found[$line.':'.$name] = [
                    'line' => $line,
                    'function' => $name,
                    'snippet' => trim($snippet),
                    // 최상위 리전인가 — `document.body` 에 직접 붙는 조각만 자기 리전을 갖는다.
                    'appendsToBody' => str_contains($body, 'document.body.appendChild'),
                ];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * 라이브 리전은 **한 층만** 둔다.
     *
     * 화면(`views/*.php` · `public/install/includes/functions.php`)이 결과 영역을 `role="status"
     * aria-live="polite"` 로 선언하고, JS 가 그 안을 갈아 끼운다. 그 안쪽 조각이 다시
     * 리전을 선언하면 중첩이고, 화면 판독기 구현에 따라 **중복 낭독** 또는 **안쪽 역할
     * 무시**로 갈린다 — 어느 쪽이든 의도한 안내가 아니다.
     *
     * 안쪽 리전이 없어도 갱신은 낭독된다. 바깥 리전의 `aria-busy` 는 결과를 그린 직후
     * false 로 풀리므로, 그 뒤에 오는 비동기 갱신도 바깥 리전이 알린다(`aria-atomic`
     * 기본값이 false 라 **바뀐 subtree 만** 읽는다). 안쪽에 남기는 것은 `aria-busy`
     * 뿐이며 — 그것은 리전 선언이 아니라 노출 지연 표시다.
     *
     * 판정은 **도출한다.** 종전에는 리터럴 `role="alert"` 한 형태만 셌는데, `role="status"`
     * 는 암묵 polite 라 polite-in-polite 3곳과 `setAttribute` 형태의 alert-in-polite 1곳이
     * 그 계수 밖에 있었다.
     *
     * @scenario a11y_axis=live_region, nesting=none
     *
     * @effects installer_live_regions_are_not_nested
     *
     * @return void 반환값 없음
     */
    public function test_라이브_리전이_중첩되지_않는다(): void
    {
        $declarations = $this->jsLiveRegionDeclarations();

        $offenders = [];
        $topLevel = [];
        foreach ($declarations as $key => $declaration) {
            if ($declaration['appendsToBody']) {
                $topLevel[$key] = $declaration;

                continue;
            }
            $offenders[] = 'installer.js:'.$declaration['line']
                .' ('.$declaration['function'].') → '.$declaration['snippet'];
        }

        $this->assertSame(
            [],
            $offenders,
            "JS 가 그리는 조각이 스스로 라이브 리전을 선언한다:\n  ".implode("\n  ", $offenders)
            ."\n그 조각은 화면이 소유한 `aria-live` 영역 **안**으로 들어가므로 리전이 중첩된다."
            ."\n바깥 리전이 이미 그 갱신을 낭독한다 — 안쪽에 남길 것은 `aria-busy` 뿐이고,"
            ."\n시각적 강조는 카드/`alert alert-error` 클래스가 담당한다."
            ."\n자기 리전이 필요한 조각은 `document.body` 에 직접 붙는 것(토스트)뿐이다.",
        );

        // 반대 방향 ① — 최상위(body 직결) 리전까지 지우면 그 안내가 통째로 사라진다.
        $this->assertNotSame(
            [],
            $topLevel,
            'document.body 에 직접 붙는 조각의 라이브 리전이 하나도 없다 — 안쪽 중첩을 '
            .'정리하면서 최상위 리전까지 지웠다(의존성 잠금 토스트가 화면 밖에서 침묵한다)',
        );

        // 반대 방향 ② — 조각이 얹히는 **바깥** 리전. 이것이 사라지면 안쪽을 비워 둔 조각의
        // 갱신을 알릴 주체가 아무도 없다. `views/*.php` 의 `-result` 영역은 형제 검사가
        // 이미 잠그므로, 여기서는 `functions.php` 가 만드는 결과 섹션을 본다
        // (완료·실패·중단 — 마무리 거부 안내가 덧붙는 자리다).
        $functions = $this->sourceWithoutComments($this->installRoot().'/includes/functions.php');
        $matched = preg_match('/<div[^>]*class="result-section[^"]*"[^>]*>/', $functions, $m);
        $this->assertSame(1, $matched, 'functions.php 에서 결과 섹션 컨테이너를 찾지 못했다 — 도출 규칙이 뒤처졌다');
        $this->assertMatchesRegularExpression(
            '/\brole="(?:'.$this->liveRegionRoleAlternation().')"|\baria-live=/',
            $m[0],
            '완료/실패/중단 결과 섹션에 라이브 리전 선언이 없다 — 이 섹션은 JS 가 덧붙이는 '
            .'안내(마무리 거부 등)의 유일한 낭독 주체다',
        );
    }
}
