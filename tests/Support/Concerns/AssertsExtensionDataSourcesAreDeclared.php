<?php

namespace Tests\Support\Concerns;

/**
 * 확장 정의(`resources/extensions/*.json`)가 **자기가 선언하지 않은 데이터소스**를 참조하는
 * 것을 막는 회귀 가드.
 *
 * 확장 정의는 자기 `data_sources[]` 에 선언한 것만 쓸 수 있다. 남의 플러그인이 선언한
 * 데이터소스 id 를 표현식에 적으면, 그 플러그인이 **설치되지 않은 사이트에서 미정의 참조**가
 * 된다. 표현식 평가는 조용히 undefined 를 내므로 오류도 로그도 남지 않고, 그 조건에 걸린
 * 노드가 영영 나타나지 않거나(혹은 반대로 늘 나타나) 운영자는 이유를 알 수 없다.
 *
 * 실제 사례(#599): `sirsoft-pay_kginicis` 의 테스트결제 배지가 `nicepay_test_map` ·
 * `nhnkcp_test_map` 을 함께 보는데 그 플러그인은 `kginicis_test_map` 하나만 선언했다.
 * KG이니시스만 설치한 사이트에서는 미정의 참조였고, 셋 다 설치한 사이트에서는 남의 PG
 * 테스트 결제에까지 「KG이니시스」 배지 문구가 붙었다.
 *
 * 검사는 확장 정의 파일 단위다 — 한 파일이 참조하는 id 가 그 파일의 `data_sources[]` 에
 * 있어야 한다. 호스트 레이아웃이 제공하는 바인딩(`row`·`item`·`_local` 등)과 엔진 전역은
 * 데이터소스가 아니므로 대상에서 뺀다.
 */
trait AssertsExtensionDataSourcesAreDeclared
{
    /**
     * 확장 정의가 참조하는 데이터소스는 모두 그 파일이 선언한 것이어야 한다.
     *
     * @param  array<int, string>  $roots
     */
    protected function assertExtensionDataSourcesAreDeclared(array $roots): void
    {
        $definitions = $this->collectExtensionDefinitions($roots);

        $this->assertNotEmpty(
            $definitions,
            '확장 정의를 하나도 찾지 못했습니다 — 수집기가 깨졌을 수 있습니다.'
        );

        // 남의 확장이 선언한 id → 그 확장 이름. 이 표에 있으면서 내가 선언하지 않았다면
        // 그 확장이 없는 사이트에서 미정의 참조다.
        $ownerOf = [];

        foreach ($definitions as $definition) {
            foreach ($definition['declared'] as $id) {
                $ownerOf[$id] ??= $definition['extension'];
            }
        }

        $undeclared = [];

        foreach ($definitions as $definition) {
            $declared = $definition['declared'];

            foreach ($definition['referenced'] as $id) {
                if (in_array($id, $declared, true)) {
                    continue;
                }

                // 호스트 레이아웃이 주는 이름(order·user 등)은 확장 정의에 선언되지 않는다.
                // 대상 레이아웃을 확장 정의만으로 확정할 수 없으므로, **다른 번들 확장이
                // 소유한 id** 만 위반으로 본다 — 그것은 호스트가 줄 수 없는 이름이다.
                $owner = $ownerOf[$id] ?? null;

                if ($owner === null || $owner === $definition['extension']) {
                    continue;
                }

                $undeclared[] = sprintf(
                    '%s — `%s` 참조(소유: %s). 선언: [%s]',
                    $definition['file'],
                    $id,
                    $owner,
                    $declared === [] ? '없음' : implode(', ', $declared)
                );
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "확장 정의가 자기가 선언하지 않은 데이터소스를 참조합니다.\n".
            "그 데이터소스를 가진 확장이 없는 사이트에서는 미정의 참조가 되고, 표현식은\n".
            "조용히 undefined 를 내므로 오류 없이 화면만 어긋납니다:\n  ".
            implode("\n  ", $undeclared)
        );
    }

    /**
     * 확장 정의마다 선언한 데이터소스 id 와 표현식이 참조하는 id 를 모은다.
     *
     * @param  array<int, string>  $roots
     * @return array<int, array{file: string, declared: array<int, string>, referenced: array<int, string>}>
     */
    private function collectExtensionDefinitions(array $roots): array
    {
        $definitions = [];

        foreach ($this->extensionDefinitionFiles($roots) as $file) {
            $json = json_decode(file_get_contents($file), true);

            if (! is_array($json)) {
                continue;
            }

            $declared = [];

            foreach ($json['data_sources'] ?? [] as $source) {
                if (is_array($source) && is_string($source['id'] ?? null)) {
                    $declared[] = $source['id'];
                }
            }

            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));

            $definitions[] = [
                'file' => $relative,
                'extension' => $this->extensionIdOf($relative),
                'declared' => $declared,
                'referenced' => $this->referencedDataSourceIds($json),
            ];
        }

        return $definitions;
    }

    /**
     * 표현식에서 `<id>.data` 꼴로 읽는 데이터소스 id 를 뽑는다.
     *
     * 데이터소스는 언제나 `.data` 를 거쳐 읽으므로 이 형태만 본다. 호스트가 넘겨 주는
     * 반복 변수(`row.data` 같은 것은 없다)나 전역과 섞이지 않는 가장 좁은 신호다.
     *
     * @return array<int, string>
     */
    private function referencedDataSourceIds(array $json): array
    {
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return [];
        }

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\.data(?![A-Za-z0-9_])/', $encoded, $matches);

        $ids = array_values(array_unique($matches[1] ?? []));

        // 데이터소스가 아닌 것 — 엔진·호스트가 넘겨 주는 이름.
        $notDataSources = ['response', 'result', 'event', 'form', 'state', 'props', 'item', 'row'];

        return array_values(array_filter(
            $ids,
            fn (string $id) => ! in_array($id, $notDataSources, true)
        ));
    }

    /**
     * `plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/{name}.json` → `sirsoft-pay_kginicis`
     */
    private function extensionIdOf(string $relativePath): string
    {
        $parts = explode('/', $relativePath);

        return $parts[2] ?? $relativePath;
    }

    /**
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    private function extensionDefinitionFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn ($current) => ! in_array($current->getFilename(), ['node_modules', 'dist', 'vendor', '.git'], true)
                )
            );

            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());

                if ($entry->isFile() && str_contains($path, '/resources/extensions/') && str_ends_with($path, '.json')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }
}
