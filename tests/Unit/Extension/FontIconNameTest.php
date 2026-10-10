<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 글꼴 아이콘 이름 계약 테스트
 *
 * 번들 템플릿의 `Icon` 은 이름을 `fa-{이름}` 클래스로 바꿔 Font Awesome 6.4.0 글꼴로 그린다. 그 버전에 없는
 * 이름(`x-circle`·`refresh-cw`·`more-horizontal` 같은 Lucide 식 이름)은 클래스만 붙고 글리프가 없어 **빈칸**으로
 * 그려진다 — 오류도 경고도 없다. 사용자 템플릿(sirsoft-basic)의 `Icon` 은 변환표(`iconNameMap`)로 몇몇 별칭을
 * FA 이름으로 바꿔 주지만, 관리자 템플릿(sirsoft-admin_basic)의 `Icon` 은 변환표를 쓰지 않는다. 그래서 같은
 * 이름이라도 **어느 템플릿이 그리는 화면이냐**로 판정한다(#682 PO 실측 후속 4 — 전수조사에서 35개 파일 126곳).
 *
 * 이름 목록의 원천은 저장소에 커밋된 FA 스타일시트(`dist/vendor/font-awesome/6.4.0/css/all.inlined.css`)다 —
 * 화면이 실제로 싣는 파일과 같은 것이다.
 *
 * 트러블슈팅: [case:components-misc-icon-name-not-in-fa]
 *
 * 소유 시나리오: `font-icon-names`
 *
 * @effects font_icon_names_resolve_to_a_glyph
 */
class FontIconNameTest extends TestCase
{
    /** @var string[] 아이콘이 아닌 FA 보조 클래스(스타일·크기·동작) */
    private const FA_UTILITY = ['solid', 'regular', 'brands', 'spin', 'pulse', 'fw', 'lg', 'xs', 'sm', 'xl', '2xl', '2xs', '1x', '2x', '3x', '4x', '5x', '6x', '7x', '8x', '9x', '10x'];

    /**
     * FA 6.4.0 스타일시트가 정의하는 아이콘 이름 집합.
     *
     * @return array<string, true> 이름 → true
     */
    private function faNames(): array
    {
        $css = (string) file_get_contents(base_path('templates/_bundled/sirsoft-admin_basic/dist/vendor/font-awesome/6.4.0/css/all.inlined.css'));
        preg_match_all('/\.fa-([a-z0-9-]+)(?=[:,{ .])/', $css, $matches);

        return array_fill_keys(array_merge($matches[1], self::FA_UTILITY), true);
    }

    /**
     * 사용자 템플릿 `Icon` 의 변환표 — 별칭 → FA 이름.
     *
     * @return array<string, string> 별칭 → FA 이름(접두 없음)
     */
    private function userTemplateAliases(): array
    {
        $source = (string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/src/components/basic/IconTypes.ts'));
        preg_match_all("/^\s*(\w+)\s*=\s*'([a-z0-9-]+)'/m", $source, $enum, PREG_SET_ORDER);
        $values = [];
        foreach ($enum as [, $member, $value]) {
            $values[$member] = preg_replace('/^fa-/', '', $value);
        }

        preg_match_all("/'([a-z0-9-]+)'\s*:\s*IconName\.(\w+)/", $source, $map, PREG_SET_ORDER);
        $aliases = [];
        foreach ($map as [, $alias, $member]) {
            if (isset($values[$member])) {
                $aliases[$alias] = $values[$member];
            }
        }

        return $aliases;
    }

    /** @var string[] 아이콘이 있는데 그리는 템플릿을 못 정한 파일 */
    private array $unclassified = [];

    /** @var array<string, array<string, true>>|null 확장 지점 이름 → 그 지점을 선언한 화면의 템플릿 집합 */
    private ?array $pointRenderers = null;

    /**
     * 레이아웃 파일을 그리는 템플릿 — 경로로 정해진다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @return string|null `user`·`admin`, 경로로 정해지지 않으면 null
     */
    private function layoutRenderer(string $relative): ?string
    {
        if (preg_match('#^templates/_bundled/(sirsoft-basic|gnuboard7-hello_user_template)/|/resources/layouts/user/#', $relative) === 1) {
            return 'user';
        }
        if (preg_match('#^templates/_bundled/(sirsoft-admin_basic|gnuboard7-hello_admin_template)/|/resources/layouts/admin/#', $relative) === 1) {
            return 'admin';
        }

        return null;
    }

    /**
     * 확장 지점마다 그 지점을 선언한 화면의 템플릿을 모읍니다(한 지점이 관리자·사용자 양쪽에 있을 수 있다).
     *
     * @return array<string, array<string, true>> 지점 이름 → 템플릿 집합
     */
    private function pointRenderers(): array
    {
        if ($this->pointRenderers !== null) {
            return $this->pointRenderers;
        }

        $base = strlen(str_replace('\\', '/', base_path())) + 1;
        $points = [];
        $roots = array_merge(glob(base_path('templates/_bundled/*/layouts'), GLOB_ONLYDIR) ?: [], glob(base_path('{modules,plugins}/_bundled/*/resources/layouts'), GLOB_ONLYDIR | GLOB_BRACE) ?: []);
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.g7.html')) {
                    continue;
                }
                $renderer = $this->layoutRenderer(substr(str_replace('\\', '/', $file->getPathname()), $base));
                preg_match_all('/<extension-point\b[^>]*?\bname="([^"{]+)"/', (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $point) {
                    $points[$point][$renderer ?? 'unknown'] = true;
                }
            }
        }

        return $this->pointRenderers = $points;
    }

    /**
     * 파일을 그리는 템플릿 — 사용자 화면이면 `user`, 관리자 화면이면 `admin`, 양쪽이거나 알 수 없으면 `both`.
     *
     * 확장 조각(`resources/extensions/*`)은 파일 이름이 아니라 **선언한 대상**으로 정한다 — 대상 레이아웃
     * (`layout="admin_user_detail"`)이거나 확장 지점(`extension-point="address_search_slot"`, 그 지점을 선언한
     * 화면들의 템플릿). `both` 는 변환표 없이 FA 이름만 허용한다 — 어느 쪽 `Icon` 이 그려도 풀려야 한다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @param  string  $source  파일 내용
     * @return string|null `user`·`admin`·`both`, 화면이 아닌 파일이면 null
     */
    private function renderer(string $relative, string $source): ?string
    {
        if (! str_contains($relative, '/resources/extensions/')) {
            return $this->layoutRenderer($relative);
        }

        $renderers = [];
        preg_match_all('/<extension\b[^>]*?\blayout="([^"]+)"/', $source, $layouts);
        foreach ($layouts[1] as $layout) {
            $renderers[str_starts_with($layout, 'admin_') || $layout === '_admin_base' ? 'admin' : 'user'] = true;
        }
        preg_match_all('/<extension\b[^>]*?\bextension-point="([^"]+)"/', $source, $points);
        foreach ($points[1] as $point) {
            $renderers += $this->pointRenderers()[$point] ?? ['unknown' => true];
        }

        return count($renderers) === 1 && ! isset($renderers['unknown']) ? array_key_first($renderers) : 'both';
    }

    /**
     * 번들 템플릿·모듈·플러그인의 레이아웃(`.g7.html`)·TSX 에서 아이콘 이름을 모은다.
     *
     * 식 안의 이름(`{{ok ? 'circle-check' : 'circle-xmark'}}`)은 결과 자리의 문자열만 — 비교 대상(`=== 'pending'`)은
     * 이름이 아니다.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> [파일:줄, 그리는 템플릿, 이름]
     */
    private function iconNames(): array
    {
        $roots = [];
        foreach (['templates/_bundled/*', 'modules/_bundled/*/resources', 'plugins/_bundled/*/resources'] as $pattern) {
            $roots = array_merge($roots, glob(base_path($pattern), GLOB_ONLYDIR) ?: []);
        }

        $base = strlen(str_replace('\\', '/', base_path())) + 1;
        $names = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (preg_match('#/(node_modules|dist|__tests__|tests)/#', $path) || preg_match('/\.(g7\.html|tsx)$/', $path) !== 1) {
                    continue;
                }

                $relative = substr($path, $base);
                $source = (string) file_get_contents($path);
                $renderer = $this->renderer($relative, $source);
                if ($renderer === null) {
                    // 그리는 템플릿을 못 정한 파일에 아이콘이 있으면 판정이 그 파일을 조용히 건너뛴 것이다.
                    if (preg_match('/<(?:Icon|icon(?!-))\b|\biconName=/', $source) === 1) {
                        $this->unclassified[] = $relative;
                    }

                    continue;
                }

                $patterns = str_ends_with($path, '.tsx')
                    ? ['/<Icon\b[^>]*?\bname=(?:"([^"]+)"|\{\'([^\']+)\'\})/s', '/\biconName=(?:"([^"]+)"|\{\'([^\']+)\'\})/']
                    : ['/<icon\b(?!-)[^>]*?\bname="([^"]+)"/s', '/\biconName="([^"]+)"/', '/\bicon="([^"{]+)"/'];

                foreach ($patterns as $pattern) {
                    preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
                    foreach ($matches as $match) {
                        $raw = ($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? '');
                        $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                        $candidates = [$raw];
                        if (str_contains($raw, '{{')) {
                            preg_match_all("/(===?\s*|!==?\s*)?'([a-z0-9][a-z0-9 -]*)'/", $raw, $literals, PREG_SET_ORDER);
                            $candidates = array_map(fn ($m) => $m[2], array_filter($literals, fn ($m) => $m[1] === ''));
                        }

                        foreach ($candidates as $candidate) {
                            $name = preg_replace(['/^(fas|far|fab|fa-solid|fa-regular|fa-brands)\s+/', '/^fa-/'], '', trim($candidate));
                            if ($name === '' || preg_match('/[\s{}$]/', $name) === 1) {
                                continue;
                            }
                            $names[] = [$relative.':'.$line, $renderer, $name];
                        }
                    }
                }
            }
        }

        return $names;
    }

    /**
     * 모든 아이콘 이름이 그 화면을 그리는 템플릿에서 글리프로 풀린다.
     *
     * @scenario source=layout
     *
     * @effects font_icon_names_resolve_to_a_glyph
     *
     * @return void 없음
     */
    #[Test]
    public function every_icon_name_resolves_to_a_font_awesome_glyph(): void
    {
        $fa = $this->faNames();
        $aliases = $this->userTemplateAliases();
        $names = $this->iconNames();

        $this->assertGreaterThan(1500, count($fa), 'FA 이름 모집단이 비었다 — 스타일시트 경로·정규식을 확인');
        $this->assertArrayHasKey('x-circle', $aliases, '사용자 템플릿 변환표를 못 읽는다 — IconTypes.ts 구조를 확인');
        $this->assertGreaterThan(1000, count($names), '아이콘 이름 모집단이 비었다 — 스캔 경로·정규식이 소스를 못 읽는다');
        $this->assertNotEmpty(array_filter($names, fn ($n) => $n[1] === 'user'), '사용자 화면 아이콘 모집단이 비었다');
        $this->assertNotEmpty(array_filter($names, fn ($n) => $n[1] === 'admin'), '관리자 화면 아이콘 모집단이 비었다');
        $this->assertSame([], $this->unclassified, '아이콘이 있는데 그리는 템플릿을 못 정한 파일 — 판정 규칙(renderer)을 넓힌다');

        $violations = [];
        foreach ($names as [$where, $renderer, $name]) {
            $resolved = $renderer === 'user' ? ($aliases[$name] ?? $name) : $name;
            if (! isset($fa[$resolved])) {
                $violations[] = "{$where}  [{$renderer}] \"{$name}\"";
            }
        }

        $this->assertSame([], $violations, "Font Awesome 6.4.0 에 없는 아이콘 이름은 빈칸으로 그려진다 — FA 이름으로 바꾼다:\n".implode("\n", $violations));
    }

    /**
     * 서버(PHP)가 글리프 이름을 내려 주는 기존 계약(결제수단·PG·현금영수증 제공자·정렬 메뉴 Resource 등)의 이름은
     * 풀리는 FA 이름이어야 한다 — 어느 템플릿이 그릴지 모르므로 변환표 없이 판정한다. 새 백엔드 표면은 글리프 이름
     * 대신 의미 토큰(`status-*`)을 내고 템플릿 `iconNameMap` 이 해석하므로(PO 지시 — 메모리
     * `backend-emits-icon-semantic-tokens-not-glyph-names`) 그 토큰은 이 판정에서 뺀다.
     *
     * @scenario source=server
     *
     * @effects font_icon_names_resolve_to_a_glyph
     *
     * @return void 없음
     */
    #[Test]
    public function server_declared_icon_names_are_font_awesome_names(): void
    {
        $fa = $this->faNames();
        $roots = array_merge(
            [base_path('app')],
            glob(base_path('modules/_bundled/*/src'), GLOB_ONLYDIR) ?: [],
            glob(base_path('plugins/_bundled/*/src'), GLOB_ONLYDIR) ?: [],
        );

        $base = strlen(str_replace('\\', '/', base_path())) + 1;
        $count = 0;
        $violations = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $lines = file($file->getPathname()) ?: [];
                foreach ($lines as $index => $text) {
                    // 문서 주석의 예시(`'icon' => 'icon-name'`)는 값이 아니다.
                    if (preg_match('#^\s*(\*|//)#', $text) === 1 || preg_match("/'icon'\s*=>\s*'([a-z0-9 -]+)'/", $text, $m) !== 1) {
                        continue;
                    }
                    if (str_starts_with($m[1], 'status-')) {
                        continue;
                    }
                    $count++;
                    $name = preg_replace(['/^(fas|far|fab|fa-solid|fa-regular|fa-brands)\s+/', '/^fa-/'], '', $m[1]);
                    if (! isset($fa[$name])) {
                        $violations[] = substr(str_replace('\\', '/', $file->getPathname()), $base).':'.($index + 1)."  \"{$m[1]}\"";
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $count, '서버 선언 아이콘 모집단이 비었다');
        $this->assertSame([], $violations, "서버가 내려 주는 아이콘 이름이 FA 6.4.0 에 없다:\n".implode("\n", $violations));
    }
}
