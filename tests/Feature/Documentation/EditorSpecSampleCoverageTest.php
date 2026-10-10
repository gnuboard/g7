<?php

namespace Tests\Feature\Documentation;

use App\Support\ExtensionDoc\EditorSpecCollector;
use App\Support\ExtensionDoc\FrontendInventory;
use Tests\TestCase;

/**
 * 편집기 프리뷰 샘플 커버리지 판정 계약 (#636)
 *
 * `ext:docgen` 은 확장 레이아웃의 `data_source` 중 편집기 프리뷰 샘플이 없는 것을 문서에
 * 적습니다. 샘플이 없으면 편집기 캔버스에서 그 자리만 빈 화면이 되는데, **실제 화면은
 * 정상이라 오류도 경고도 남지 않습니다** — 문서의 그 줄이 유일한 신호입니다.
 *
 * 그래서 이 판정이 조용히 0건으로 접히면 게이트가 초록인 채 누락이 영구히 숨습니다.
 * 이 테스트는 그 접힘을 막습니다.
 */
class EditorSpecSampleCoverageTest extends TestCase
{
    /**
     * 저작 경로가 `.g7.html` 인 레이아웃에서도 미커버 `data_source` 가 잡히는지 단언합니다.
     *
     * `FrontendInventory::authoringPath()` 는 짝 `.g7.html` 이 있으면 **그 경로**를 레이아웃
     * 목록에 싣습니다(저작 소스가 정본이므로 옳은 동작입니다). 그런데 `EditorSpecCollector`
     * 는 그 목록을 JSON 으로 읽으므로, 마크업 저작으로 옮긴 확장에서는 **모든 파싱이 실패하고
     * 그 실패가 `continue` 로 조용히 접혀** 미커버 집합이 항상 빈 배열이 됐습니다. 결과는
     * 「이 확장 레이아웃의 data_source 는 전부 프리뷰 샘플이 붙습니다」의 무조건 출력입니다.
     *
     * 2026-09-21 실측(#636 S3) — sirsoft-gdpr 의 신규 `data_source` 2종이 그렇게 통과했고,
     * 검증 라운드 둘이 그 출력을 근거로 부수의무를 닫았습니다.
     *
     * 판정은 **짝 `.json` 을 읽는지**로 합니다 — 같은 화면을 담은 두 파일 중 기계가 읽을
     * `data_sources` 를 가진 것은 산출물 쪽이기 때문입니다.
     *
     * @scenario authoring_path=markup
     *
     * @effects uncovered_data_source_is_reported_for_markup_authored_layout
     *
     * @return void 반환값 없음
     */
    public function test_uncovered_data_sources_are_found_when_layouts_are_authored_as_markup(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-editor-spec-'.bin2hex(random_bytes(6));
        $layoutDir = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'layouts';
        mkdir($layoutDir, 0o777, true);

        try {
            // 저작 소스와 짝 산출물이 **둘 다 디스크에 있는** 상태가 이 저장소의 레이아웃 규약이다.
            file_put_contents(
                $layoutDir.DIRECTORY_SEPARATOR.'sample.g7.html',
                '<layout><data-source id="vendorThing" type="api" endpoint="/api/x"/></layout>',
            );
            file_put_contents(
                $layoutDir.DIRECTORY_SEPARATOR.'sample.json',
                (string) json_encode(['data_sources' => [['id' => 'vendorThing']]]),
            );

            $uncovered = $this->uncoveredOf($root, 'resources/layouts/sample.g7.html');

            $this->assertContains(
                'vendorThing',
                $uncovered,
                '저작 경로가 `.g7.html` 이면 짝 `.json` 을 읽어야 합니다 — 그러지 않으면 파싱 실패가 '
                .'조용히 접혀 미커버 집합이 항상 비고, 문서가 「전부 커버」를 거짓으로 말합니다.',
            );
        } finally {
            $this->removeTree($root);
        }
    }

    /**
     * 레이아웃 목록이 저작 경로를 싣는다는 전제 자체를 고정합니다.
     *
     * 위 테스트는 「`.g7.html` 이 목록에 실린다」를 전제로 합니다. 그 전제가 조용히 바뀌면
     * 위 테스트는 초록인 채 아무것도 재지 않게 되므로, 전제도 함께 잠급니다.
     *
     * @scenario authoring_path=markup
     *
     * @effects layout_list_carries_the_authoring_path
     *
     * @return void 반환값 없음
     */
    public function test_layout_list_carries_the_markup_authoring_path(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-editor-spec-'.bin2hex(random_bytes(6));
        $layoutDir = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'layouts';
        mkdir($layoutDir, 0o777, true);

        try {
            file_put_contents($layoutDir.DIRECTORY_SEPARATOR.'sample.g7.html', '<layout/>');
            file_put_contents($layoutDir.DIRECTORY_SEPARATOR.'sample.json', '{}');

            $method = (new \ReflectionClass(FrontendInventory::class))->getMethod('authoringPath');
            $method->setAccessible(true);

            $this->assertSame(
                'resources/layouts/sample.g7.html',
                $method->invoke(new FrontendInventory, ['path' => $root], 'resources/layouts/sample.json'),
                '짝 마크업이 있으면 레이아웃 목록은 저작 경로를 싣습니다 — 위 축의 전제입니다.',
            );
        } finally {
            $this->removeTree($root);
        }
    }

    /**
     * 수집기의 미커버 판정을 호출합니다.
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $relFile  레이아웃 목록에 실린 상대 경로
     * @return array<int, string> 프리뷰 샘플이 없는 `data_source` ID 목록
     */
    private function uncoveredOf(string $root, string $relFile): array
    {
        $collector = new EditorSpecCollector;
        $method = (new \ReflectionClass($collector))->getMethod('uncoveredDataSources');
        $method->setAccessible(true);

        /** @var array<int, string> $uncovered */
        $uncovered = $method->invoke($collector, ['path' => $root], [$relFile], ['sampleData' => ['byDataSourceId' => []]]);

        return $uncovered;
    }

    /**
     * 임시 디렉토리를 정리합니다.
     *
     * @param  string  $root  지울 루트 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    }
}
