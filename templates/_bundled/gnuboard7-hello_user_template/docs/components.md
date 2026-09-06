# Hello 사용자 템플릿 — 컴포넌트

> 템플릿이 제공하는 컴포넌트 · 진입점: [AGENTS.md](../AGENTS.md)

## 제공 컴포넌트

<!-- @generated:components START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
컴포넌트 8개 (루트: `src/components`).

| 분류 | 개수 |
|---|---|
| `basic` | 8개 |
<!-- @generated:components END -->

<!-- @intent START -->
Basic 8개뿐이며 composite·layout 은 없습니다 — HTML 태그를 그대로 래핑한 최소 단위만으로
방문자 화면 골격을 그릴 수 있음을 보이는 구성입니다.

`sirsoft-basic` 이 79개(basic 38 · composite 36 · layout 5)를 갖는 것과 비교하면 그 차이가
곧 "필수" 와 "그 템플릿의 선택" 의 경계입니다. 상품 카드·이미지 뷰어·모바일 메뉴 같은 것들은
그 템플릿이 자기 화면을 위해 만든 것이지 템플릿의 요건이 아닙니다.

**이 목록이 다른 확장과의 계약**입니다. 모듈·플러그인이 이 템플릿의 화면에 조각을 끼워 넣을 때
여기 없는 컴포넌트를 쓰면 그 조각은 렌더되지 않습니다.

컴포넌트를 추가할 때는 소스(`src/components/{분류}/`) · `template.json` 의 레지스트리 ·
`components.json` 을 함께 갱신합니다. 하나라도 빠지면 레이아웃이 그 이름을 찾지 못해 그
컴포넌트만 조용히 렌더되지 않습니다. TSX 를 고쳤으면 `template:build --production` 후
`dist/` 를 함께 커밋합니다.
<!-- @intent END -->
