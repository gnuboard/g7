# Hello 모듈 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 편집기 스펙(`editor-spec.json`)을 두지 않습니다. 편집기는 코어 기본 팔레트와 활성 템플릿의 스펙만으로 이 확장의 화면을 다룹니다._
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
학습용 샘플 모듈이라 편집기 스펙을 일부러 두지 않았습니다. 이 모듈의 목적은 모듈의
최소 구조(라우트 → 컨트롤러 → 서비스 → 저장소)를 보여 주는 것이고, 편집기 스펙은 그
구조와 무관한 별개 축입니다.

다만 아래 "샘플 데이터와 페이지 상태" 절이 보여 주듯, 이 모듈에는 프리뷰가 비는 자리가
실제로 있습니다. 스펙이 없어도 되는 상태와 스펙이 필요한데 없는 상태는 다릅니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 편집기 스펙 블록이 없습니다._
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언한 블록이 없으므로 표가 비어 있습니다. 이것은 "편집기가 이 모듈을 다루지 않는다"
가 아니라 "이 모듈이 편집기에 아무것도 알려 주지 않는다" 는 뜻입니다 — 편집기는 여전히
이 모듈의 레이아웃을 열 수 있고, 다만 데이터가 붙지 않은 채로 엽니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 편집기 팔레트에 항목을 추가하지 않습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
컴포넌트를 만드는 것은 템플릿의 일이므로, 이 확장이 팔레트에 얹을 것은 원래 없습니다.
편집기 팔레트는 활성 템플릿의 스펙이 정합니다 — 이 확장에 편집기 스펙이 생기더라도
`componentPalette` 는 여전히 비어 있을 것입니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 편집기 스펙을 두지 않아 선언된 샘플 데이터·페이지 상태가 없습니다._

**프리뷰 샘플이 없는 `data_source` 2개** — 편집기 캔버스에서 이 자리만 빈 화면이 됩니다. 실제 화면은 정상이라 오류도 경고도 남지 않습니다.

`memoData` · `memos`
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`memoData` · `memos` 두 ID 가 미커버입니다. 메모 목록과 폼이 편집기 캔버스에서 빈
채로 보인다는 뜻입니다.

샘플 모듈이므로 이 상태를 그대로 두는 것도 선택입니다 — 다만 그것은 "편집기 스펙이
없으면 어떤 화면이 되는가" 를 보여 주는 교보재로서 의도적으로 남긴 것이지, 문제가
없다는 뜻이 아닙니다. 스펙을 하나 만들어 보는 것이 이 모듈로 할 수 있는 좋은 연습입니다.
<!-- @intent END -->

## 수정 시 동반 의무

<!-- @generated:editor-spec-obligations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 아직 편집기 스펙을 두지 않습니다. 아래 변경이 생기면 `editor-spec.json` 을 신설합니다._

| 이런 변경을 했다면 | 편집기 스펙에서 함께 할 일 |
|---|---|
| 컴포넌트를 새로 만들었다 | `componentPalette` 에 항목 추가 · `componentCapabilities` 에 편집 역량 선언 · `nesting` 에 담길 자리 규정 |
| 레이아웃에 `data_sources` 를 추가했다 | `sampleData` 에 같은 ID 로 프리뷰 응답 추가 (없으면 편집기 캔버스만 빈 화면) |
| `_global.*` 을 새로 읽는다 | `sampleGlobal` 에 baseline 값 추가 |
| 빈 목록·오류 같은 화면 변종을 추가했다 | `states` 에 변종 추가 · `stateLabels` 에 친화 명칭 |
| 새 액션·조건 패턴을 도입했다 | `actionRecipes` / `conditionRecipes` 에 친화 명칭 등록 |
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
편집기 스펙을 신설해야 하는 시점은 하나입니다 — **이 확장이 소유한 레이아웃에
이 확장만 쓰는 `data_source` 가 생겼을 때**. 그 순간부터 편집기 캔버스의 그 영역은
빈 화면이 되고, 실제 화면은 정상 동작하므로 오류도 경고도 남지 않습니다.

신설 절차는 확장 루트에 `editor-spec.json` 을 만들고 `sampleData.byDataSourceId` 에
그 ID 를 넣는 것으로 시작합니다. 팔레트·컨트롤은 템플릿의 일이므로 넣지 않습니다.
파일을 만든 뒤 update 커맨드로 활성 디렉토리에 반영해야 편집기가 읽습니다 —
`_bundled` 폴백이 없습니다.
<!-- @intent END -->
