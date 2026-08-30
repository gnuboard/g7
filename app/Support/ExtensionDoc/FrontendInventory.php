<?php

namespace App\Support\ExtensionDoc;

use Illuminate\Support\Facades\File;

/**
 * 확장 프론트엔드 진입점 수집기
 *
 * 레이아웃 JSON · 액션 핸들러 · 전역 재등록 진입점 · 컴포넌트 · 빌드 산출물을 수집합니다.
 *
 * 레이아웃 경로는 유형마다 다릅니다 — 모듈/플러그인은 `resources/layouts/`, 템플릿은
 * `layouts/` 가 루트입니다. 유형별 분기를 이 수집기 한 곳에 두어, 소비자(스캐폴더·검사
 * 스크립트)가 경로 규약을 각자 알 필요가 없게 합니다.
 */
class FrontendInventory
{
    /**
     * 확장의 프론트엔드 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array<string, mixed> 프론트 인벤토리
     */
    public function collect(array $record): array
    {
        $layouts = $this->collectLayouts($record);

        return [
            'layoutRoot' => $this->layoutRoot($record),
            'layouts' => $layouts,
            'layoutGroups' => $this->groupLayouts($layouts),
            'layoutExtensions' => $this->collectJsonFiles($record, $this->layoutExtensionRoot($record)),
            'handlers' => $this->collectHandlers($record),
            'entryPoints' => $this->collectEntryPoints($record),
            'components' => $this->collectComponents($record),
            'builtAssets' => $this->collectBuiltAssets($record),
            'vendoredAssets' => $this->collectVendoredAssets($record),
            'customDir' => is_dir($record['path'].DIRECTORY_SEPARATOR.'custom'),
            'editorSpec' => $this->collectEditorSpec($record),
            'routesJson' => is_file($record['path'].DIRECTORY_SEPARATOR.'routes.json') ? 'routes.json' : null,
            'routeCount' => $this->countDeclaredRoutes($record),
        ];
    }

    /**
     * `routes.json` 에 선언된 주소 수를 셉니다 (셀 수 없으면 null).
     *
     * 템플릿은 선언형 표면(`getRoutes()`)을 갖지 않으므로 집계 배지의 "라우트 수" 가
     * 구조적으로 항상 0 이 됩니다 — 주소를 `routes.json` 에 적기 때문입니다. 0 은 사실이
     * 아닌데 템플릿에는 "확인하지 못함" 안내도 붙지 않아(선언형 표면 부재가 정상이라)
     * 단서 없이 사실처럼 읽힙니다. 실측은 `sirsoft-basic` 40 · `sirsoft-admin_basic` 29.
     *
     * "라우트 수 = 주소 개수" 규율은 모듈·플러그인과 같습니다.
     *
     * 읽지 못한 것과 없는 것은 구분합니다 — 파일이 없으면 `null`, 파일이 깨졌어도 `null`
     * 입니다. 0 을 돌려주면 "주소가 없다" 는 사실 주장이 됩니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return int|null 주소 수, 셀 수 없으면 null
     */
    private function countDeclaredRoutes(array $record): ?int
    {
        $path = $record['path'].DIRECTORY_SEPARATOR.'routes.json';

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['routes']) || ! is_array($data['routes'])) {
            return null;
        }

        return count(array_filter(
            $data['routes'],
            static fn (mixed $row): bool => is_array($row) && isset($row['path']),
        ));
    }

    /**
     * 유형별 레이아웃 루트(확장 루트 기준 상대 경로)를 반환합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return string 레이아웃 루트
     */
    private function layoutRoot(array $record): string
    {
        return $record['type'] === ExtensionInventory::TYPE_TEMPLATE ? 'layouts' : 'resources/layouts';
    }

    /**
     * 유형별 레이아웃 확장 조각 루트(확장 루트 기준 상대 경로)를 반환합니다.
     *
     * 레이아웃과 같은 규율이다 — 템플릿은 확장 루트 직속, 모듈·플러그인은 `resources/`
     * 아래. 형제인 `layoutRoot()` 만 유형을 갈랐던 탓에 템플릿의 조각이 항상 빈 목록으로
     * 수집돼(`sirsoft-basic` 실측 1건) 문서에 "없음" 으로 실렸다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return string 레이아웃 확장 루트
     */
    private function layoutExtensionRoot(array $record): string
    {
        return $record['type'] === ExtensionInventory::TYPE_TEMPLATE ? 'extensions' : 'resources/extensions';
    }

    /**
     * 레이아웃 JSON 을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> 레이아웃 목록
     */
    private function collectLayouts(array $record): array
    {
        $layouts = [];

        foreach ($this->collectJsonFiles($record, $this->layoutRoot($record)) as $rel) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $root = $this->layoutRoot($record).'/';
            $inner = str_starts_with($rel, $root) ? substr($rel, strlen($root)) : $rel;
            $segments = explode('/', $inner);

            $layouts[] = [
                'relFile' => $rel,
                'name' => basename($inner, '.json'),
                'group' => count($segments) > 1 ? $segments[0] : '(root)',
                'partial' => str_starts_with(basename($inner), '_'),
                'extends' => $this->readJsonString($abs, 'extends'),
            ];
        }

        return $layouts;
    }

    /**
     * 레이아웃을 그룹(admin/user 등)별로 집계합니다.
     *
     * @param  array<int, array<string, mixed>>  $layouts  레이아웃 목록
     * @return array<string, int> 그룹 => 개수
     */
    private function groupLayouts(array $layouts): array
    {
        $groups = [];

        foreach ($layouts as $layout) {
            $groups[$layout['group']] = ($groups[$layout['group']] ?? 0) + 1;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * 액션 핸들러 이름을 수집합니다.
     *
     * `handlerMap` 객체의 최상위 키가 핸들러 이름이며, 엔트리포인트가
     * `{identifier}.{name}` 으로 네임스페이스를 붙여 등록합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{namespace: string|null, names: array<int, string>, source: string|null}
     */
    private function collectHandlers(array $record): array
    {
        $candidates = [
            'resources/js/handlers/index.ts',
            'src/handlers/index.ts',
            'resources/js/index.ts',
            'src/index.ts',
        ];

        foreach ($candidates as $rel) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_file($abs)) {
                continue;
            }

            $content = (string) file_get_contents($abs);
            $names = [];

            // 핸들러 맵 이름은 확장마다 다르다 (`handlerMap` / `handlers` + alias 재수출).
            // 앞선 후보가 alias 대입(`handlerMap = handlers;`)이면 객체 리터럴이 아니므로 비고,
            // 다음 후보에서 실제 리터럴을 찾는다.
            foreach (['handlerMap', 'handlers'] as $objectName) {
                $names = $this->objectKeys($content, $objectName);
                if ($names !== []) {
                    break;
                }
            }

            if ($names === []) {
                $names = $this->literalHandlerNames($content);
            }

            if ($names !== []) {
                return [
                    'namespace' => $record['type'] === ExtensionInventory::TYPE_TEMPLATE ? null : $record['id'],
                    'names' => $names,
                    'source' => $rel,
                ];
            }
        }

        return ['namespace' => null, 'names' => [], 'source' => null];
    }

    /**
     * 전역 재등록 진입점(`window.__[Name]`)과 초기화 함수를 수집합니다.
     *
     * 로케일 전환 후 액션이 무반응이 되는 결함을 막는 계약이므로, 노출 여부 자체가
     * 문서에 드러나야 합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{global: string|null, initFunction: string|null, source: string|null}
     */
    private function collectEntryPoints(array $record): array
    {
        $candidates = ['resources/js/index.ts', 'src/index.ts', 'src/index.tsx'];

        foreach ($candidates as $rel) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_file($abs)) {
                continue;
            }

            $content = (string) file_get_contents($abs);

            $global = null;
            if (preg_match('/window[^;\n]*\)\.\s*(__\w+)\s*=/', $content, $m)) {
                $global = $m[1];
            }

            $init = null;
            foreach (['initModule', 'initPlugin', 'initTemplate'] as $fn) {
                if (preg_match('/function\s+'.$fn.'\s*\(/', $content)) {
                    $init = $fn;
                    break;
                }
            }

            if ($global !== null || $init !== null) {
                return ['global' => $global, 'initFunction' => $init, 'source' => $rel];
            }
        }

        return ['global' => null, 'initFunction' => null, 'source' => null];
    }

    /**
     * 템플릿이 제공하는 컴포넌트를 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{total: int, byCategory: array<string, int>, root: string|null}
     */
    private function collectComponents(array $record): array
    {
        $root = 'src/components';
        $dir = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root);

        if (! is_dir($dir)) {
            return ['total' => 0, 'byCategory' => [], 'root' => null];
        }

        $byCategory = [];
        $total = 0;

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'tsx') {
                continue;
            }
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/__tests__/')) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePath());
            $category = $relative === '' ? '(root)' : explode('/', $relative)[0];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            $total++;
        }

        ksort($byCategory);

        return ['total' => $total, 'byCategory' => $byCategory, 'root' => $root];
    }

    /**
     * 커밋된 빌드 산출물을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, string> 산출물 상대 경로
     */
    private function collectBuiltAssets(array $record): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.'dist';
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($dir) as $file) {
            if (! in_array($file->getExtension(), ['js', 'css'], true)) {
                continue;
            }
            $rel = $this->relative($record, $file->getPathname());
            if (str_starts_with($rel, 'dist/vendor/')) {
                continue;
            }
            $files[] = $rel;
        }

        sort($files);

        return $files;
    }

    /**
     * 동봉(self-hosted) 제3자 자산을 수집합니다.
     *
     * 외부 CDN 대신 확장이 자기 서버에서 제공하는 구동 자산이며, 버전이 디렉토리명에
     * 드러나므로 문서에 그대로 노출합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, string> `{라이브러리}/{버전}` 목록
     */
    private function collectVendoredAssets(array $record): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'vendor';
        if (! is_dir($dir)) {
            return [];
        }

        $entries = [];
        foreach (File::directories($dir) as $libPath) {
            $lib = basename($libPath);
            $versions = array_map('basename', File::directories($libPath));

            if ($versions === []) {
                $entries[] = $lib;

                continue;
            }

            foreach ($versions as $version) {
                $entries[] = $lib.'/'.$version;
            }
        }

        sort($entries);

        return $entries;
    }

    /**
     * 편집기 스펙(단일 파일 / 분할) 보유 형태를 판정합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{manifest: bool, split: int}
     */
    private function collectEditorSpec(array $record): array
    {
        $manifest = is_file($record['path'].DIRECTORY_SEPARATOR.'editor-spec.json');
        $splitDir = $record['path'].DIRECTORY_SEPARATOR.'editor-spec';
        $split = 0;

        if (is_dir($splitDir)) {
            foreach (File::allFiles($splitDir) as $file) {
                if ($file->getExtension() === 'json') {
                    $split++;
                }
            }
        }

        return ['manifest' => $manifest, 'split' => $split];
    }

    /**
     * 하위 디렉토리의 JSON 파일 상대 경로를 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $sub  확장 루트 기준 하위 경로
     * @return array<int, string> 상대 경로 목록 (정렬)
     */
    private function collectJsonFiles(array $record, string $sub): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() === 'json') {
                $files[] = $this->relative($record, $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * JSON 파일에서 최상위 문자열 키 값을 읽습니다.
     *
     * @param  string  $absolute  파일 절대 경로
     * @param  string  $key  키
     * @return string|null 값 (없거나 문자열이 아니면 null)
     */
    private function readJsonString(string $absolute, string $key): ?string
    {
        $data = json_decode((string) @file_get_contents($absolute), true);

        return is_array($data) && isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * `export const {name} = { ... }` 객체의 최상위 키를 뽑습니다.
     *
     * @param  string  $content  TS 소스
     * @param  string  $name  객체 변수명
     * @return array<int, string> 키 목록
     */
    private function objectKeys(string $content, string $name): array
    {
        // 타입 주석이 붙은 선언(`handlerMap: Record<string, (...a) => unknown> = {`)까지 잡되,
        // alias 대입(`handlerMap = handlers;`)은 잡지 않는다. `[^;{]*` 가 문(statement) 경계를
        // 넘지 못하게 하고, `=\s*\{` 로 객체 리터럴 대입만 받는다.
        if (! preg_match('/\b'.preg_quote($name, '/').'\b[^;{]*=\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $open = strpos($content, '{', (int) $m[0][1]);
        if ($open === false) {
            return [];
        }

        $depth = 0;
        $len = strlen($content);
        $close = null;

        for ($i = $open; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }

        if ($close === null) {
            return [];
        }

        $body = substr($content, $open + 1, $close - $open - 1);
        $keys = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_$][\w$]*)\s*[,:]/', $line, $km)) {
                $keys[] = $km[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * `registerHandler(...)` 인자에서 핸들러 이름을 뽑습니다.
     *
     * 두 형태를 모두 봅니다 — 리터럴(`'name'`)과 식별자 보간 템플릿 리터럴
     * (`` `${PLUGIN_IDENTIFIER}.name` ``). 후자를 놓치면 핸들러 맵을 쓰지 않고 개별
     * 등록하는 확장(gdpr 등)이 "핸들러 0개" 로 잘못 집계됩니다.
     *
     * @param  string  $content  TS 소스
     * @return array<int, string> 핸들러 이름 목록
     */
    private function literalHandlerNames(string $content): array
    {
        $names = [];

        if (preg_match_all("/registerHandler\s*\(\s*'([^']+)'/", $content, $m)) {
            foreach ($m[1] as $name) {
                $names[] = $this->stripIdentifierPrefix($name);
            }
        }

        if (preg_match_all('/registerHandler\s*\(\s*`\$\{[^}]+\}\.([A-Za-z_$][\w$]*)`/', $content, $m)) {
            $names = array_merge($names, $m[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * `{identifier}.{handler}` 형태의 이름에서 확장 식별자 접두를 떼어냅니다.
     *
     * 등록 코드는 네임스페이스를 붙인 전체 이름을 쓰지만, 표는 핸들러 이름과 호출 이름을
     * 각각 보여주므로 접두를 벗긴 이름이 필요합니다.
     *
     * @param  string  $name  등록된 이름
     * @return string 접두를 뗀 핸들러 이름
     */
    private function stripIdentifierPrefix(string $name): string
    {
        $pos = strrpos($name, '.');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * 확장 루트 기준 상대 경로로 변환합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $absolute  절대 경로
     * @return string 상대 경로 (POSIX 구분자)
     */
    private function relative(array $record, string $absolute): string
    {
        $base = rtrim((string) $record['path'], '/\\').DIRECTORY_SEPARATOR;
        $rel = str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;

        return str_replace('\\', '/', $rel);
    }
}
