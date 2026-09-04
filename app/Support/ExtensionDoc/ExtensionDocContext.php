<?php

namespace App\Support\ExtensionDoc;

/**
 * 확장 문서 수집 컨텍스트 조립기
 *
 * 문서 블록을 렌더하려면 수집기 여덟 축(선언형 표면·훅·데이터 모델·프론트·테스트·의존
 * 관계·편집기 스펙)의 산출물을 한 배열로 묶어야 합니다. 그 조립을 커맨드와 계약 테스트가
 * 각자 하고 있으면, 축이 하나 늘 때 한쪽만 갱신되어 **드리프트가 아닌 드리프트**가 보고
 * 됩니다 — 테스트가 만든 블록에는 그 축이 없어 파일과 달라지기 때문입니다.
 *
 * 그 어긋남은 "생성기를 다시 돌려라" 라는 메시지로 나타나는데, 아무리 돌려도 사라지지
 * 않습니다. 조립을 이 한 곳이 소유해 그 경로 자체를 없앱니다.
 */
class ExtensionDocContext
{
    /**
     * 수집기를 주입받습니다.
     *
     * @param  DeclarativeSurfaceCollector  $surface  선언형 표면 수집기
     * @param  HookInventory  $hooks  훅 인벤토리
     * @param  DataModelCollector  $data  데이터 모델 수집기
     * @param  FrontendInventory  $frontend  프론트 인벤토리
     * @param  TestPathCollector  $tests  테스트 경로 수집기
     * @param  DependencyGraphCollector  $deps  의존 관계 수집기
     * @param  EditorSpecCollector  $editorSpec  편집기 스펙 수집기
     */
    public function __construct(
        private readonly DeclarativeSurfaceCollector $surface,
        private readonly HookInventory $hooks,
        private readonly DataModelCollector $data,
        private readonly FrontendInventory $frontend,
        private readonly TestPathCollector $tests,
        private readonly DependencyGraphCollector $deps,
        private readonly EditorSpecCollector $editorSpec,
    ) {}

    /**
     * 확장 하나의 수집 컨텍스트를 조립합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array<string, mixed> 수집 컨텍스트
     */
    public function build(array $record): array
    {
        // 선언형 표면을 먼저 모은다 — 발행 훅의 1차 출처가 그 안의 `getHooks()` 선언이다.
        $surface = $this->surface->collect($record);
        $declaredHooks = $surface['values']['getHooks'] ?? [];

        // 편집기 스펙 수집기는 레이아웃 목록을 받아 "프리뷰 샘플이 없는 data_source" 를
        // 판정한다. 레이아웃 경로 규약(모듈·플러그인 `resources/layouts/` ↔ 템플릿
        // `layouts/`)은 FrontendInventory 가 단독으로 소유하므로, 그 결과를 넘겨 분기가
        // 두 곳에 생기지 않게 한다.
        $frontend = $this->frontend->collect($record);
        $layoutRelFiles = array_map(
            static fn (array $layout): string => $layout['relFile'],
            $frontend['layouts'],
        );

        return [
            'record' => $record,
            'surface' => $surface,
            'hooks' => $this->hooks->collect($record, is_array($declaredHooks) ? $declaredHooks : []),
            'data' => $this->data->collect($record),
            'frontend' => $frontend,
            'tests' => $this->tests->collect($record),
            'deps' => $this->deps->collect($record),
            'editorSpec' => $this->editorSpec->collect($record, $layoutRelFiles),
        ];
    }
}
