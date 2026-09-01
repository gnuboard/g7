# CKEditor 5 WYSIWYG 에디터 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 편집기 스펙(`editor-spec.json`)을 두지 않습니다. 편집기는 코어 기본 팔레트와 활성 템플릿의 스펙만으로 이 확장의 화면을 다룹니다._
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
위지윅 에디터 플러그인이지만 편집기 스펙은 두지 않았습니다. 이 플러그인이 다루는 것은
**본문 작성기**이고, 레이아웃 편집기가 다루는 것은 그 작성기를 **배치하는 화면**이라
서로 다른 층이기 때문입니다.

에디터 자체의 팔레트(툴바 구성)는 이 플러그인의 설정 화면에서 정하지, 레이아웃 편집기
스펙과는 무관합니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 편집기 스펙 블록이 없습니다._
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언한 블록이 없습니다. 다만 이 플러그인은 설정 화면 외에 **업로드 관리 화면**을 하나
더 갖고 있고, 그 화면은 자기 도메인 데이터를 읽습니다 — 아래 미커버 목록에 그 결과가
드러납니다.
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

**프리뷰 샘플이 없는 `data_source` 1개** — 편집기 캔버스에서 이 자리만 빈 화면이 됩니다. 실제 화면은 정상이라 오류도 경고도 남지 않습니다.

`ckeditor5Uploads`
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`ckeditor5Uploads` 가 미커버입니다. 업로드 관리 화면의 목록 영역이 편집기 캔버스에서
빈 채로 보입니다.

설정 화면 쪽 `settings` 는 공용 ID 라 템플릿 스펙이 채우므로 문제가 없습니다. 즉 이
플러그인은 **화면 둘 중 하나만** 편집기에서 온전히 보이는 상태입니다.
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
