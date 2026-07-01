<?php

namespace App\Extension\Traits;

/**
 * 확장(모듈/플러그인) 컴포넌트 매니페스트(components.json) 생성 트레이트
 *
 * `module:build` / `plugin:build` 의 빌드 성공 직후 호출되어, 그 확장의 컴포넌트
 * 소스를 스캔해 `{ identifier, version, components: { basic[], composite[], layout[] } }`
 * 매니페스트를 빌드 경로 루트에 작성한다.
 *
 * 편집 모드 부팅 시 코어 `ComponentRegistry` 가 활성 확장의 components.json 을
 * 네임스페이스 병합해 자동 컨트롤 생성(props 메타)의 입력으로 쓴다. 본 매니페스트가
 * 없으면 그 확장 컴포넌트는 메타데이터 없이 무손실 보존만 된다(원칙 4.6 디그레이드).
 *
 * 스캔 규칙:
 *  - 컴포넌트 소스는 확장의 `resources/js/components/{basic,composite,layout}/` 하위
 *    `*.tsx` / `*.ts` 파일로 본다. 하위 분류 디렉토리 이름이 type(basic/composite/layout).
 *  - 분류 디렉토리 밖의 컴포넌트는 `composite` 로 분류(보수적 기본값).
 *  - 컴포넌트 name = 파일명(확장자 제외). props 정밀 추출은 본 S6-1 범위 밖 —
 *    `props: {}` 빈 메타로 둔다(코어 ComponentRegistry 가 런타임 props 메타로 보강).
 *  - 컴포넌트 소스 디렉토리가 없으면 빈 매니페스트를 작성한다(핸들러 전용 확장 등).
 *
 * 본 트레이트는 도메인-특화 컴포넌트 구조를 가정하지 않는다 — 디렉토리 컨벤션만으로
 * 분류하고, 컨벤션 밖 구조는 composite 로 보존한다.
 *
 * @since engine-v1.54.0
 */
trait GeneratesComponentManifest
{
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
        $components = $this->scanComponentSources($buildPath);

        $manifest = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'identifier' => $identifier,
            'version' => $version,
            'components' => $components,
        ];

        $outputPath = $buildPath.'/components.json';
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $written = $json !== false && file_put_contents($outputPath, $json.PHP_EOL) !== false;

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
     * 컴포넌트 소스 디렉토리를 스캔해 type 별 컴포넌트 목록을 구성합니다.
     *
     * @param  string  $buildPath  빌드 경로
     * @return array{basic: list<array<string, mixed>>, composite: list<array<string, mixed>>, layout: list<array<string, mixed>>}
     */
    private function scanComponentSources(string $buildPath): array
    {
        $result = ['basic' => [], 'composite' => [], 'layout' => []];

        $componentsRoot = $buildPath.'/resources/js/components';
        if (! is_dir($componentsRoot)) {
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, ['ts', 'tsx'], true)) {
                continue;
            }
            $name = $file->getBasename('.'.$file->getExtension());
            // index/엔트리 파일·테스트 파일은 컴포넌트로 보지 않는다
            if (in_array(strtolower($name), ['index', 'main', 'entry'], true)) {
                continue;
            }
            if (str_contains(strtolower($file->getFilename()), '.test.')
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'__tests__'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $type = $this->classifyComponentByPath($componentsRoot, $file->getPathname());
            $relativePath = ltrim(
                str_replace(
                    [base_path().DIRECTORY_SEPARATOR, '\\'],
                    ['', '/'],
                    $file->getPathname()
                ),
                '/'
            );

            $result[$type][] = [
                'name' => $name,
                'type' => $type,
                'path' => $relativePath,
                'props' => new \stdClass,
            ];
        }

        // 결정적 순서 — name 기준 정렬 (빌드 재현성)
        foreach ($result as $type => $list) {
            usort($result[$type], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }

        return $result;
    }

    /**
     * 파일 경로의 분류 디렉토리(basic/composite/layout)로 컴포넌트 type 을 판정합니다.
     *
     * @param  string  $componentsRoot  components 디렉토리 절대 경로
     * @param  string  $filePath  컴포넌트 파일 절대 경로
     * @return 'basic'|'composite'|'layout' 컴포넌트 type (컨벤션 밖이면 composite)
     */
    private function classifyComponentByPath(string $componentsRoot, string $filePath): string
    {
        $relative = str_replace('\\', '/', substr($filePath, strlen($componentsRoot)));
        $segments = array_values(array_filter(explode('/', $relative)));
        $firstDir = $segments[0] ?? '';

        return match (strtolower($firstDir)) {
            'basic' => 'basic',
            'layout' => 'layout',
            default => 'composite',
        };
    }
}
