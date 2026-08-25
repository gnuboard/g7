<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 화면의 마크업 계약 (12차 #B28 · #B32 · #B33 · #B34 · #B36).
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
 *
 * @effects installer_decorative_svgs_are_hidden_from_assistive_tech, installer_views_do_not_dim_text_with_inline_opacity, installer_replaced_regions_declare_live_semantics, installer_translated_attribute_values_are_escaped, installer_running_card_header_is_inert
 */
class InstallerViewAccessibilityContractTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 확장자에 맞는 주석 제거 — 대조 전에 예외 없이 지난다.
     *
     * 원문에는 주석 처리된 마크업도 글자로 남아, 「그 화면 요소가 살아 있다」를 증명하지
     * 못한다. 두 스트리퍼 모두 줄 수를 보존하므로 `파일:줄번호` 보고는 그대로 성립한다.
     *
     * @param  string  $path  파일 경로 (확장자로 스트리퍼를 고른다)
     * @param  string  $source  원본
     * @return string 주석이 제거된 소스
     */
    private function withoutComments(string $path, string $source): string
    {
        return str_ends_with($path, '.js')
            ? $this->stripJsComments($source)
            : $this->stripComments($source);
    }

    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 검사 대상 파일 (인스톨러가 소유한 화면 표면).
     *
     * @return array<int, string>
     */
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

    private function markupFiles(): array
    {
        // 재귀 스캔 — 한 겹 `glob('*.php')` 은 `views/` 아래에 디렉토리가 하나 생기는 순간
        // 그 안을 통째로 놓친다. 모집단 밖의 위반은 영원히 초록이다.
        $files = $this->scanFiles($this->installRoot().'/views', ['php']);
        $files[] = $this->installRoot().'/index.php';
        $files[] = $this->installRoot().'/assets/js/installer.js';

        return $files;
    }

    public function test_모든_장식_sv_g_가_보조기술에서_감춰진다(): void
    {
        $offenders = [];

        foreach ($this->markupFiles() as $file) {
            $lines = explode("\n", $this->withoutComments($file, (string) file_get_contents($file)));
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

    public function test_화면_파일이_인라인_style_로_글자를_흐리게_하지_않는다(): void
    {
        $offenders = [];

        foreach ($this->scanFiles($this->installRoot().'/views', ['php']) as $file) {
            $lines = explode("\n", $this->withoutComments($file, (string) file_get_contents($file)));
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
            $source = $this->withoutComments($file, (string) file_get_contents($file));

            if (preg_match_all('/<div[^>]*\sid="([a-z0-9-]+-result)"/i', $source, $m)) {
                $expected[$relative] = array_values(array_unique($m[1]));
            }
        }

        $this->assertNotSame([], $expected, '결과 영역을 하나도 찾지 못했다 — 이름 규약이 바뀌었으면 도출 규칙도 함께 옮긴다');

        foreach ($expected as $relative => $ids) {
            $source = $this->withoutComments($relative, (string) file_get_contents($this->installRoot().'/'.$relative));

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

    public function test_번역문을_속성값에_넣을_때_이스케이프한다(): void
    {
        $offenders = [];

        foreach (array_merge($this->scanFiles($this->installRoot().'/views', ['php']), [$this->installRoot().'/index.php']) as $file) {
            $lines = explode("\n", $this->withoutComments($file, (string) file_get_contents($file)));
            foreach ($lines as $index => $line) {
                // 짧은 echo 태그로 속성값을 채우면서 이스케이프 헬퍼를 거치지 않은 것
                // 한 태그 안만 본다 — 닫는 태그를 넘겨 읽으면 같은 줄의 다음 태그까지 한 덩어리가 된다
                if (! preg_match_all('/[a-zA-Z-]+="<\?=((?:(?!\?>).)*)\?>/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $expression) {
                    $expression = trim($expression);

                    if (preg_match('/htmlspecialchars|json_encode|urlencode|rawurlencode|installerJsString/', $expression)) {
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

    public function test_설치_중_진행_카드_헤더가_tab_순서에_남지_않는다(): void
    {
        $source = $this->stripComments((string) file_get_contents($this->installRoot().'/views/5-installation.php'));

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
        $installer = $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installer.js'));
        $this->assertStringContainsString(
            'setInstallationCardInteractive(true)',
            $installer,
            '설치가 끝난 뒤 헤더를 다시 활성화하는 지점이 없다 — 접기/펼치기가 영영 막힌다',
        );
    }
}
