<?php

namespace App\Extension\Traits;

use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use Illuminate\Support\Facades\Log;

/**
 * 확장 관리자(템플릿·모듈·플러그인)가 레이아웃 행 동기화기를 부르는 공통 부분 (#727).
 *
 * 행 쓰기 규칙 자체는 {@see LayoutRowSynchronizer} 한 곳에 있다. 여기는 관리자마다 반복되는 통계 집계와
 * 활성화 경로의 충돌 경고만 둔다.
 */
trait SynchronizesLayoutRows
{
    /**
     * 생성자로 받은 동기화기를 돌려줍니다 — 동기화기는 저장소만 쥔 무상태 객체라 관리자 인스턴스가 하나를 계속 쓴다.
     *
     * 이 트레이트를 쓰는 관리자는 생성자에서 `$layoutRowSynchronizer` 를 받는다.
     *
     * @return LayoutRowSynchronizer 동기화기
     */
    protected function layoutSynchronizer(): LayoutRowSynchronizer
    {
        return $this->layoutRowSynchronizer;
    }

    /**
     * 동기화 결과 한 건을 종전 통계 키(`created`·`updated`·`unchanged`·`deleted`·`skipped`)로 셉니다.
     *
     * @param  array<string, int>  $stats  통계 (참조)
     * @param  LayoutSyncEntry|null  $entry  결과
     * @return void 반환값 없음
     */
    protected function tallyLayoutSync(array &$stats, ?LayoutSyncEntry $entry): void
    {
        if ($entry === null) {
            return;
        }

        $key = match ($entry->verdict) {
            'created' => 'created',
            'updated', 'took_theirs', 'merged' => 'updated',
            'unchanged' => 'unchanged',
            'deleted' => 'deleted',
            default => 'skipped',
        };

        $stats[$key] = ($stats[$key] ?? 0) + 1;
    }

    /**
     * 활성화·템플릿 전환 경로에서 병합 충돌이 났으면 경고를 남깁니다 — 화면 보고는 업데이트 결과만 한다(D2).
     *
     * 활성화는 파일이 버전 변경 없이 바뀐 개발 환경에서만 충돌이 날 수 있다.
     *
     * @param  LayoutSyncEntry|null  $entry  결과
     * @param  string  $source  출처 식별자
     * @return void 반환값 없음
     */
    protected function warnActivationConflict(?LayoutSyncEntry $entry, string $source): void
    {
        if ($entry !== null && $entry->conflicts !== []) {
            Log::warning('레이아웃 병합 충돌 (활성화)', [
                'source' => $source,
                'template' => $entry->template,
                'layout' => $entry->name,
                'conflicts' => $entry->conflicts,
            ]);
        }
    }

    /**
     * 업데이트 창의 「수정한 레이아웃」 목록 응답을 만듭니다 — 레이아웃과 그 확장의 조각 수정본을 한 목록으로 (§5.9).
     *
     * 판정은 {@see LayoutRowSynchronizer::isOperatorModified()} 한 곳이 한다(원본 본문이 있으면 정규화 비교라 키 순서·
     * 빈 map 차이 같은 거짓 양성이 없다). 응답 모양은 종전과 같고 항목마다 `kind`(`layout`·`extension`)만 더했다.
     *
     * @param  iterable<TemplateLayout>  $layouts  대상 레이아웃 행
     * @param  iterable<LayoutExtension>  $fragments  대상 확장 조각 행
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: list<array{id: int, name: string, updated_at: string|null, size_diff: int, kind: string}>} 목록
     */
    protected function buildModifiedLayoutsReport(iterable $layouts, iterable $fragments = []): array
    {
        $synchronizer = $this->layoutSynchronizer();
        $items = [];

        foreach ($layouts as $layout) {
            if ($synchronizer->isOperatorModified($layout)) {
                $items[] = $this->modifiedItem($layout->id, $layout->name, $layout, 'layout');
            }
        }

        foreach ($fragments as $fragment) {
            if ($synchronizer->isOperatorModified($fragment)) {
                $label = __('extensions.layout_sync.fragment_label', ['target' => $fragment->target_name, 'key' => $fragment->fragment_key]);
                $items[] = $this->modifiedItem($fragment->id, $label, $fragment, 'extension');
            }
        }

        return [
            'has_modified_layouts' => $items !== [],
            'modified_count' => count($items),
            'modified_layouts' => $items,
        ];
    }

    /**
     * 수정 목록 항목 하나를 만듭니다.
     *
     * @param  int  $id  행 ID
     * @param  string  $name  표시 이름
     * @param  TemplateLayout|LayoutExtension  $row  행
     * @param  string  $kind  `layout` 또는 `extension`
     * @return array{id: int, name: string, updated_at: string|null, size_diff: int, kind: string} 항목
     */
    private function modifiedItem(int $id, string $name, $row, string $kind): array
    {
        $currentSize = $this->computeContentSize($row->content ?? []);

        return [
            'id' => $id,
            'name' => $name,
            'updated_at' => $row->updated_at?->format('Y-m-d H:i:s'),
            'size_diff' => $currentSize - ($row->original_content_size ?? $currentSize),
            'kind' => $kind,
        ];
    }

    /**
     * 템플릿 레이아웃 파일이 오버라이드 디렉토리(`layouts/overrides/`) 소속인지 판정합니다.
     *
     * 오버라이드는 전용 등록({@see TemplateManager::registerLayoutOverrides()})이 출처·본문을
     * 정해 쓰므로, 템플릿 자기 레이아웃 동기화에서는 뺀다(두 경로가 같은 행을 번갈아 덮지 않게).
     *
     * @param  string  $file  레이아웃 파일 경로
     * @return bool 오버라이드 파일이면 true
     */
    protected function isOverrideLayoutFile(string $file): bool
    {
        return str_contains(str_replace('\\', '/', $file), '/layouts/overrides/');
    }
}
