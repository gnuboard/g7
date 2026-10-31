<?php

namespace App\Extension\Traits;

/**
 * 확장(모듈/플러그인) 컴포넌트 매니페스트(components.json) 생성 트레이트
 *
 * `module:build` / `plugin:build` 의 빌드 성공 직후 호출되어, 그 확장의 컴포넌트
 * 소스를 스캔해 `{ identifier, version, components: { basic[], composite[], layout[] }, for{} }`
 * 매니페스트를 빌드 경로 루트에 작성한다.
 *
 * 이 매니페스트를 읽는 소비자는 둘이다:
 *  - **컴파일러** — `.g7.html` 작성 시 확장 컴포넌트 태그를 해석한다
 *    (`App\Support\LayoutMarkup\ComponentManifest`). 확장 컴포넌트는 `{identifier}` 프리픽스
 *    태그로 작성되고 노드 `name` 은 `{identifier}.{이름}` 이다 — 핸들러의 네임스페이스 관례와 같다.
 *  - **런타임** — 확장 IIFE 가 self-register 할 때 이 매니페스트가 선언한 이름과 판본을 쓴다.
 *
 * 스캔 규칙:
 *  - 컴포넌트 소스는 확장의 `resources/js/components/{basic,composite,layout}/` 하위
 *    `*.tsx` / `*.ts` 파일로 본다. 하위 분류 디렉토리 이름이 type(basic/composite/layout).
 *  - 분류 디렉토리 밖의 컴포넌트는 `composite` 로 분류(보수적 기본값).
 *  - **`for/{template-id}/{Name}.tsx` 는 템플릿 전용 판본**이다 — 그 확장이 **자기** 컴포넌트를
 *    특정 템플릿에서만 다르게 내는 것이라, 별개 컴포넌트가 아니라 같은 이름의 다른 구현이다.
 *    따라서 `components` 에 중복 등재하지 않고 `for` 맵에만 싣는다. 판본 **선택**은 코어
 *    런타임이 하고 확장은 선언만 한다.
 *  - 컴포넌트 소스 디렉토리가 없으면 빈 매니페스트를 작성한다(핸들러 전용 확장 등).
 *
 * **오버라이드(`overrides`)는 여기서 방출하지 않는다.** 남의 컴포넌트를 대체할 권한은 템플릿에만
 * 있고(레이아웃 오버라이드가 `source_type=template` 일 때만 성립하는 것과 같은 규약), 이 트레이트는
 * 모듈·플러그인 빌드에서만 호출된다. 확장이 `overrides` 를 선언하면 컴파일러가 거부한다.
 *
 * 본 트레이트는 도메인-특화 컴포넌트 구조를 가정하지 않는다 — 디렉토리 컨벤션만으로
 * 분류하고, 컨벤션 밖 구조는 composite 로 보존한다.
 *
 * @since engine-v1.54.0
 */
trait GeneratesComponentManifest
{
    /** 템플릿 전용 판본을 담는 디렉토리 이름 (`components/for/{template-id}/`) */
    private const FOR_VARIANT_DIR = 'for';

    /**
     * 확장 빌드 경로의 컴포넌트 소스를 스캔해 components.json 을 작성합니다.
     *
     * @param  string  $buildPath  빌드 경로 (확장 루트 — _bundled 또는 활성)
     * @param  string  $identifier  확장 식별자 (vendor-extension 형식)
     * @return array{written: bool, count: int, path: string} 작성 결과
     */
    protected function generateComponentManifest(string $buildPath, string $identifier): array
    {
        $version = $this->readManifestVersion($buildPath);
        $scanned = $this->scanComponentSources($buildPath);

        $manifest = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'identifier' => $identifier,
            'version' => $version,
            'components' => $scanned['components'],
        ];

        // 판본이 없으면 키 자체를 넣지 않는다 — 빈 맵은 PHP 의 json_encode 에서 `[]` 로
        // 굳어 객체를 기대하는 소비자와 어긋난다.
        if ($scanned['for'] !== []) {
            $manifest['for'] = $scanned['for'];
        }

        $outputPath = $buildPath.'/components.json';
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // 종결 개행은 "\n" 고정 — PHP_EOL 은 Windows 에서 "\r\n" 이라, 같은 소스를 빌드해도
        // 빌드한 OS 에 따라 산출물의 마지막 바이트가 달라진다. components.json 은 Git 추적
        // 대상이므로 그 차이가 매 빌드마다 변경으로 잡히는데, 줄 내용이 같아 diff 는 비어
        // 보인다 — 무엇이 바뀐 것인지 알 수 없는 변경만 남는다.
        $written = $json !== false && file_put_contents($outputPath, $json."\n") !== false;

        $components = $scanned['components'];
        $count = count($components['basic']) + count($components['composite']) + count($components['layout']);

        return ['written' => $written, 'count' => $count, 'path' => $outputPath];
    }

    /**
     * 확장 매니페스트(module.json/plugin.json)에서 version 을 읽습니다.
     *
     * @param  string  $buildPath  빌드 경로
     * @return string version 문자열 (미확인 시 '0.0.0')
     */
    private function readManifestVersion(string $buildPath): string
    {
        foreach (['module.json', 'plugin.json'] as $file) {
            $path = $buildPath.'/'.$file;
            if (file_exists($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                    return $decoded['version'];
                }
            }
        }

        return '0.0.0';
    }

    /**
     * 컴포넌트 소스 디렉토리를 스캔해 type 별 컴포넌트 목록과 템플릿 전용 판본을 구성합니다.
     *
     * @param  string  $buildPath  빌드 경로
     * @return array{components: array{basic: list<array<string, mixed>>, composite: list<array<string, mixed>>, layout: list<array<string, mixed>>}, for: array<string, list<string>>}
     */
    private function scanComponentSources(string $buildPath): array
    {
        $components = ['basic' => [], 'composite' => [], 'layout' => []];
        $forVariants = [];

        $componentsRoot = $buildPath.'/resources/js/components';
        if (! is_dir($componentsRoot)) {
            return ['components' => $components, 'for' => $forVariants];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $this->isComponentSource($file)) {
                continue;
            }

            $name = $file->getBasename('.'.$file->getExtension());
            $segments = $this->pathSegments($componentsRoot, $file->getPathname());

            // for/{template-id}/{Name}.tsx — 같은 이름의 다른 구현이라 components 에 넣지 않는다.
            if (($segments[0] ?? '') === self::FOR_VARIANT_DIR) {
                $templateId = $segments[1] ?? '';

                if ($templateId !== '' && ! str_ends_with($templateId, '.tsx') && ! str_ends_with($templateId, '.ts')) {
                    $forVariants[$templateId][] = $name;
                }

                continue;
            }

            $type = match (strtolower($segments[0] ?? '')) {
                'basic' => 'basic',
                'layout' => 'layout',
                default => 'composite',
            };

            $components[$type][] = [
                'name' => $name,
                'type' => $type,
                'path' => $this->relativeSourcePath($file->getPathname()),
                'props' => $this->extractProps($file->getPathname(), $name),
            ];
        }

        // 결정적 순서 — name 기준 정렬 (빌드 재현성)
        foreach (array_keys($components) as $type) {
            usort($components[$type], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }

        foreach ($forVariants as $templateId => $names) {
            sort($names);
            $forVariants[$templateId] = array_values(array_unique($names));
        }

        ksort($forVariants);

        return ['components' => $components, 'for' => $forVariants];
    }

    /**
     * 파일이 컴포넌트 소스인지 판정합니다 (엔트리·테스트 파일 제외).
     *
     * @param  \SplFileInfo  $file  검사 대상 파일
     * @return bool 컴포넌트 소스면 true
     */
    private function isComponentSource(\SplFileInfo $file): bool
    {
        if (! $file->isFile()) {
            return false;
        }

        if (! in_array(strtolower($file->getExtension()), ['ts', 'tsx'], true)) {
            return false;
        }

        $name = $file->getBasename('.'.$file->getExtension());

        if (in_array(strtolower($name), ['index', 'main', 'entry'], true)) {
            return false;
        }

        return ! str_contains(strtolower($file->getFilename()), '.test.')
            && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'__tests__'.DIRECTORY_SEPARATOR);
    }

    /**
     * components 루트 기준 상대 경로 세그먼트를 반환합니다.
     *
     * @param  string  $componentsRoot  components 디렉토리 절대 경로
     * @param  string  $filePath  컴포넌트 파일 절대 경로
     * @return array<int, string> 경로 세그먼트 (마지막은 파일명)
     */
    private function pathSegments(string $componentsRoot, string $filePath): array
    {
        $relative = str_replace('\\', '/', substr($filePath, strlen($componentsRoot)));

        return array_values(array_filter(explode('/', $relative)));
    }

    /**
     * 프로젝트 루트 기준 상대 경로로 정규화합니다.
     *
     * @param  string  $filePath  컴포넌트 파일 절대 경로
     * @return string 슬래시 구분 상대 경로
     */
    private function relativeSourcePath(string $filePath): string
    {
        return ltrim(
            str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $filePath),
            '/'
        );
    }

    /**
     * 컴포넌트 소스의 `{Name}Props` 선언에서 prop 메타데이터를 추출합니다.
     *
     * 추출 결과는 **부분 목록**이다 — 번들 템플릿 실측 기준 실사용 props 의 75~77% 만
     * 선언되어 있고, `extends React.HTMLAttributes<...>` 로 상속되는 표준 속성은 여기에
     * 나타나지 않는다. 컴파일러도 이 선언을 화이트리스트가 아니라 타입 판정 힌트로만 쓴다(D19).
     * 이 추출의 실제 수요자는 **편집기 자동 컨트롤**이다 — 선언이 없으면 팔레트에서 prop 을
     * 편집할 방법이 생기지 않는다.
     *
     * @param  string  $filePath  컴포넌트 파일 절대 경로
     * @param  string  $name  컴포넌트명
     * @return array<string, array<string, mixed>>|\stdClass prop 명 → 메타, 선언이 없으면 빈 객체
     */
    private function extractProps(string $filePath, string $name): array|\stdClass
    {
        $source = @file_get_contents($filePath);

        if ($source === false) {
            return new \stdClass;
        }

        $body = $this->findPropsBody($source, $name);

        if ($body === null) {
            return new \stdClass;
        }

        $props = [];

        // `/** 설명 */` 이 앞에 붙은(또는 안 붙은) `name?: type;` 한 줄을 읽는다. 메서드
        // 시그니처·인덱스 시그니처·스프레드는 편집기가 다룰 수 있는 prop 이 아니라 건너뛴다.
        $pattern = '/(?:\/\*\*(?P<doc>.*?)\*\/\s*)?^[ \t]*(?P<name>[A-Za-z_$][\w$]*)(?P<optional>\?)?\s*:\s*(?P<type>[^;\n]+);/ms';

        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === false) {
            return new \stdClass;
        }

        foreach ($matches as $match) {
            $meta = ['type' => $this->normalizeTsType($match['type']), 'required' => $match['optional'] === ''];
            $description = $this->cleanDocComment($match['doc'] ?? '');

            if ($description !== '') {
                $meta['description'] = $description;
            }

            $props[$match['name']] = $meta;
        }

        return $props === [] ? new \stdClass : $props;
    }

    /**
     * `interface {Name}Props {...}` 또는 `type {Name}Props = {...}` 의 본문을 잘라냅니다.
     *
     * @param  string  $source  컴포넌트 소스 전문
     * @param  string  $name  컴포넌트명
     * @return string|null 선언 본문, 찾지 못하면 null
     */
    private function findPropsBody(string $source, string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        $pattern = '/(?:interface|type)\s+'.$quoted.'Props\b[^{]*\{/';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // 중괄호 깊이를 세어 본문 끝을 찾는다 — 제네릭·중첩 객체 타입이 섞이므로 첫 `}` 로
        // 자르면 본문이 잘린다.
        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return null;
    }

    /**
     * TypeScript 타입 표기를 매니페스트 타입 낱말로 정규화합니다.
     *
     * @param  string  $type  TypeScript 타입 표기
     * @return string 정규화된 타입 (string/number/boolean/array/object/function, 판정 불가 시 원문)
     */
    private function normalizeTsType(string $type): string
    {
        $type = trim($type);

        return match (true) {
            (bool) preg_match('/^(string|number|boolean)$/', $type) => $type,
            str_ends_with($type, '[]'), str_starts_with($type, 'Array<') => 'array',
            str_contains($type, '=>') => 'function',
            str_starts_with($type, '{'), str_starts_with($type, 'Record<') => 'object',
            default => $type,
        };
    }

    /**
     * JSDoc 블록에서 설명 한 줄을 뽑습니다.
     *
     * @param  string  $doc  JSDoc 본문 (구분자 제외)
     * @return string 설명, 없으면 빈 문자열
     */
    private function cleanDocComment(string $doc): string
    {
        $lines = [];

        foreach (explode("\n", $doc) as $line) {
            $line = trim(ltrim(trim($line), '*'));

            // @param·@since 등 태그 줄은 설명이 아니다.
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $lines[] = $line;
        }

        return implode(' ', $lines);
    }
}
