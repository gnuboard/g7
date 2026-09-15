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

컴포넌트를 추가할 때는 소스(`src/components/{분류}/`) · `template.json` 의 레지스트리 ·
`components.json` 을 함께 갱신합니다. 하나라도 빠지면 레이아웃이 그 이름을 찾지 못해 그
컴포넌트만 조용히 렌더되지 않습니다. TSX 를 고쳤으면 `template:build --production` 후
`dist/` 를 함께 커밋합니다.
<!-- @intent END -->
