# Hello Admin Template — 컴포넌트

> 템플릿이 제공하는 컴포넌트 · 진입점: [AGENTS.md](../AGENTS.md)

## 제공 컴포넌트

<!-- @generated:components START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
컴포넌트 8개 (루트: `src/components`).

| 분류 | 개수 |
|---|---|
| `basic` | 8개 |
<!-- @generated:components END -->

<!-- @intent START -->
Basic 8개뿐이며 composite·layout 은 없습니다 — HTML 태그를 그대로 래핑한 최소 단위
(`Div`→`<div>`)만으로 관리자 화면 골격을 그릴 수 있음을 보이는 구성입니다.

**이 목록이 곧 모듈과의 계약**입니다. 모듈이 관리자 화면 조각에서 이 템플릿에 없는 컴포넌트
(`DataGrid` · `MultilingualInput` 등)를 쓰면 그 조각은 여기서 **렌더되지 않습니다.** 모듈이
어느 Admin 템플릿에서든 동작하려면 코어가 정한 필수 컴포넌트 집합만 써야 하며, 그 목록의
SSoT 는 코어 `config/template.php` 의 `required_admin_components` 입니다.

이 템플릿으로 바꿔 보면 그 규칙을 지키지 않은 화면이 드러납니다 — **모듈 호환성 확인용
실험 도구**로 쓸 수 있습니다.

컴포넌트를 추가할 때는 소스(`src/components/{분류}/`), `src/index.ts` 의 export,
`template.json` 의 레지스트리, `components.json` 을 함께 갱신합니다. 코어는 `components.json` 에
선언된 `name` 으로 번들의 export 를 찾아 등록하므로, 선언이 없거나 export 이름이 다르면
레이아웃이 그 이름을 찾지 못해 그 컴포넌트만 조용히 렌더되지 않습니다. TSX 를 고쳤으면 `template:build --production` 후
`dist/` 를 함께 커밋합니다.

`A` 의 `mergeQuery` 는 렌더할 때 현재 쿼리를 href 에 합치므로, 같은 화면에서 쿼리만 바꾸는 `navigate replace` 뒤에도
href 가 새 쿼리를 따르도록 코어 훅 `G7Core.navigation.useLocationSearch` 로 주소를 구독합니다(코어 7.1.3). 훅이 없는 7.1.2 코어에서는
엔진 `G7Core.navigation.subscribeLocationSearch` 로 구독하고, 그마저 없는 옛 엔진에서만 뒤로/앞으로를 봅니다.
링크 props 가 그대로라 엔진이 그 링크를 다시 그리지 않기 때문입니다 — 구독하지 않으면 가운데 클릭·새 탭·주소 복사가
바꾸기 전 쿼리로 갑니다. `mergeQuery` 가 없는 링크는 구독하지 않습니다(0.2.2).
`Img` 는 실사용 템플릿과 바이트 동일한 사본입니다. `thumbPreset`(규격 이름)을 주면 업로드 이미지 서빙 주소에 썸네일 규격과
1x·2x srcset 을 붙이고(판정은 코어 `G7Core.thumbnail`, 코어에 그 API 가 없으면 주소를 바꾸지 않는다), `loading` 은 기본 `lazy`,
`decoding` 은 기본 `async` 입니다. 첫 화면 이미지는 `loading="eager"` 로 명시합니다(코어 7.2.0).
`Img` 는 주소를 `src/support/thumbnail.ts` 어댑터의 `thumbUrl`, `thumbSrcset` 으로 만듭니다(어댑터는 본문용 `contentSrcset`,
확대 보기용 `stripThumb`, 첨부 칸용 `attachmentThumbUrl` 도 냅니다).
템플릿 번들은 코어와 별도 IIFE 라 코어 판정을 import 하지 못하므로, 규칙 사본을 두지 않고 `G7Core.thumbnail` 을 이름만 옮겨 부릅니다.
새 컴포넌트가 이미지 주소에 규격을 붙일 때도 이 어댑터를 씁니다.
<!-- @intent END -->
