<?php

namespace Tests\Support\Concerns;

/**
 * `extension_point` 콜백 액션 배치 회귀 가드.
 *
 * 엔진은 확장점 노드의 두 자리를 다르게 다룬다 (`DynamicRenderer.tsx:1882-1897`).
 *
 *   props     → 표현식을 **평가한 뒤** `extensionPointProps` 로 전달
 *   callbacks → 평가 없이 **그대로** `extensionPointCallbacks` 로 전달
 *
 * 주입되는 확장 컴포넌트가 콜백을 읽는 자리는 `extensionPointCallbacks` 다. 액션 객체를
 * `props` 에 두면 그 액션은 확장 컴포넌트에 도달하지 않고, 도달하더라도 액션 안의
 * `{{$event.*}}` 바인딩이 `$event` 가 생기기 전에 렌더 컨텍스트로 평가되어 빈 값으로 굳는다.
 *
 * 확장점 이름을 손으로 나열하지 않는다. 확장점 노드의 props 를 전수로 훑어 `on` + 대문자로
 * 시작하는 키에 액션 모양(`handler` 문자열 또는 `actions` 배열)이 담겨 있으면 잡는다.
 * 새 확장점이 추가돼도 자동으로 검사된다.
 */
trait AssertsExtensionPointCallbackPlacement
{
    /**
     * 확장점의 props 에는 액션 객체가 없어야 한다 — 콜백은 형제 키 `callbacks` 에 둔다.
     *
     * @param  array<int, string>  $roots
     */
    protected function assertExtensionPointCallbacksNotInProps(array $roots): void
    {
        $nodes = $this->collectExtensionPointNodes($roots);

        $this->assertNotEmpty(
            $nodes,
            '확장점 노드를 하나도 찾지 못했습니다 — 수집기가 깨졌을 수 있습니다.'
        );

        $misplaced = [];

        foreach ($nodes as $node) {
            foreach ($node['props'] as $key => $value) {
                if (! $this->looksLikeCallbackKey($key) || ! $this->looksLikeAction($value)) {
                    continue;
                }

                $misplaced[] = sprintf(
                    '%s — id=%s name=%s props.%s',
                    $node['file'],
                    $node['id'],
                    $node['name'],
                    $key
                );
            }
        }

        $this->assertSame(
            [],
            $misplaced,
            "확장점 콜백이 props 에 있습니다. `callbacks` 로 옮기세요 — props 는 평가되어 전달되고\n".
            "확장 컴포넌트는 `extensionPointCallbacks` 를 읽으므로, 이 자리의 액션은 조용히 사라집니다:\n  ".
            implode("\n  ", $misplaced)
        );
    }

    /**
     * `onAddressSelect` 처럼 on + 대문자로 시작하는 키만 콜백 후보로 본다.
     */
    private function looksLikeCallbackKey(string $key): bool
    {
        return (bool) preg_match('/^on[A-Z]/', $key);
    }

    /**
     * 액션 모양 — `handler` 문자열을 가진 객체이거나 `actions` 배열을 가진 객체.
     */
    private function looksLikeAction(mixed $value): bool
    {
        if (! is_array($value) || array_is_list($value)) {
            return false;
        }

        return is_string($value['handler'] ?? null) || is_array($value['actions'] ?? null);
    }

    /**
     * 레이아웃 전수에서 확장점 노드를 모은다.
     *
     * @param  array<int, string>  $roots
     * @return array<int, array{file: string, id: string, name: string, props: array<string, mixed>}>
     */
    private function collectExtensionPointNodes(array $roots): array
    {
        $nodes = [];

        foreach ($this->extensionPointLayoutFiles($roots) as $file) {
            $json = json_decode(file_get_contents($file), true);

            if (! is_array($json)) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            $this->walkForExtensionPoints($json, $relative, $nodes);
        }

        return $nodes;
    }

    /**
     * @param  array<int, array{file: string, id: string, name: string, props: array<string, mixed>}>  $nodes
     */
    private function walkForExtensionPoints(mixed $node, string $file, array &$nodes): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'extension_point' && is_array($node['props'] ?? null)) {
            $nodes[] = [
                'file' => $file,
                'id' => (string) ($node['id'] ?? '?'),
                'name' => (string) ($node['name'] ?? '?'),
                'props' => $node['props'],
            ];
        }

        foreach ($node as $child) {
            $this->walkForExtensionPoints($child, $file, $nodes);
        }
    }

    /**
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    private function extensionPointLayoutFiles(array $roots): array
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

                if ($entry->isFile() && str_ends_with($path, '.json')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }
}
