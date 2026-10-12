<?php

namespace Tests\Unit\Support;

use App\Support\LayoutEmptyObjects;
use App\Support\LayoutMarkup\EmptyMap;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 번들 레이아웃·확장 `.json` 전량의 빈 객체 보존 코퍼스 테스트 (#758)
 *
 * 등록(원문 보존 디코드) → DB 저장(캐스트 인코딩) → 서빙 디코드 → 응답 인코딩을 거친 뒤에도
 * 원문의 `{}` 자리가 모두 `{}` 로 남는지 본다. 새 번들 레이아웃이 보존 규칙 밖에 `{}` 를 쓰면 여기서 빨개진다.
 *
 * 구조 키 자기 자신(`props: {}`·`params: {}` 등)은 병합 코드가 배열로 다루므로 대상에서 뺀다.
 */
class BundledLayoutEmptyObjectCorpusTest extends TestCase
{
    /**
     * 자기 자신이 비면 배열로 두는 구조 키 — 이 키가 가리키는 `{}` 는 비교에서 뺀다.
     *
     * @var list<string>
     */
    private const STRUCTURAL_SELF_KEYS = ['props', 'params', 'fallback', 'data', 'headers', 'isolatedState', 'extensionPointProps'];

    /**
     * 번들 소스 원문의 `{}` 경로가 서빙 처리 결과에 모두 남는다.
     *
     * @scenario position=expanded_value
     *
     * @effects bundled_corpus_empty_objects_survive_serving
     *
     * @return void 반환값 없음
     */
    public function test_bundled_layout_empty_objects_survive_store_and_serve(): void
    {
        $root = dirname(__DIR__, 3);
        $files = $this->collectFiles($root);

        $this->assertGreaterThan(700, count($files), '번들 레이아웃·확장 .json 을 찾지 못했다');

        $missing = [];
        $total = 0;

        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $source = json_decode($raw);

            if (! $source instanceof stdClass) {
                continue;
            }

            // 등록: 원문 보존 디코드 → 캐스트 인코딩(DB 원문)
            $stored = json_encode(EmptyMap::decodePreserving($raw));
            // 서빙: 값 자리 보존 디코드 → 빈 루트 섹션 복원 → 응답 인코딩
            $served = json_decode((string) json_encode(
                LayoutEmptyObjects::restoreRootMapSections(LayoutEmptyObjects::decode($stored, []))
            ));

            $expected = $this->emptyObjectPaths($source);
            $actual = $this->emptyObjectPaths($served);
            $total += count($expected);

            foreach (array_diff($expected, $actual) as $path) {
                $missing[] = substr($file, strlen($root) + 1).' '.$path;
            }
        }

        $this->assertGreaterThan(0, $total, '번들에서 비교할 빈 객체를 찾지 못했다');
        $this->assertSame([], $missing, '서빙에서 [] 로 바뀌는 번들 빈 객체 자리');
    }

    /**
     * 번들 확장의 layouts·extensions 디렉터리에서 `.json` 을 모읍니다.
     *
     * @param  string  $root  프로젝트 루트
     * @return list<string> 파일 경로 목록
     */
    private function collectFiles(string $root): array
    {
        $files = [];
        $skip = ['node_modules', 'dist', 'vendor', '__tests__', 'tests'];

        foreach (['templates/_bundled', 'modules/_bundled', 'plugins/_bundled'] as $base) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root.'/'.$base, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $entry): bool => ! ($entry->isDir() && in_array($entry->getFilename(), $skip, true))
                )
            );

            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());

                if (str_ends_with($path, '.json') && preg_match('#/(layouts|extensions)/#', $path)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 객체 디코드 결과에서 빈 객체 자리의 경로를 모읍니다 (구조 키 자기 자신은 제외).
     *
     * @param  mixed  $node  json_decode(객체 모드) 결과 노드
     * @param  string  $path  현재 경로
     * @return list<string> 빈 객체 경로 목록
     */
    private function emptyObjectPaths(mixed $node, string $path = ''): array
    {
        if ($node instanceof stdClass) {
            $vars = get_object_vars($node);

            if ($vars === []) {
                $key = (string) preg_replace('/^.*\./', '', $path);

                return in_array($key, self::STRUCTURAL_SELF_KEYS, true) ? [] : [$path];
            }

            $paths = [];
            foreach ($vars as $key => $value) {
                $paths = [...$paths, ...$this->emptyObjectPaths($value, $path === '' ? (string) $key : $path.'.'.$key)];
            }

            return $paths;
        }

        if (is_array($node)) {
            $paths = [];
            foreach ($node as $index => $value) {
                $paths = [...$paths, ...$this->emptyObjectPaths($value, $path.'['.$index.']')];
            }

            return $paths;
        }

        return [];
    }
}
