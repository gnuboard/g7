# 토스페이먼츠 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 편집기 스펙(`editor-spec.json`)을 두지 않습니다. 편집기는 코어 기본 팔레트와 활성 템플릿의 스펙만으로 이 확장의 화면을 다룹니다._
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
토스페이먼츠 결제 플러그인은 다른 결제 3종과 달리 편집기 스펙을 두지 않았습니다.
그럼에도 미커버가 없는 것은 이 플러그인의 설정 화면이 공용 ID 만 읽기 때문입니다 —
다른 결제 플러그인이 선언한 `vbank_info` 에 해당하는 영역을 이 플러그인은 설정 화면에서
같은 방식으로 다루지 않습니다.

즉 지금은 스펙 없이도 편집기에서 화면이 온전히 보입니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 편집기 스펙 블록이 없습니다._
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언한 블록이 없습니다. 형제 결제 플러그인(`sirsoft-pay_kginicis` 등)은 스펙을 갖고
있으므로, 이 플러그인에 가상계좌 안내 같은 도메인 영역을 추가할 때는 그쪽 스펙을 선례로
봅니다.
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

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
미커버가 없습니다. 설정 화면이 읽는 `settings` 는 공용 ID 라 admin 템플릿 스펙이
채우므로, 편집기에서 설정 화면이 값이 채워진 상태로 보입니다.

결제 흐름 화면(주문·결제·완료)은 `sirsoft-ecommerce` 가 소유하므로 그 프리뷰는 이커머스
스펙이 그립니다.
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
