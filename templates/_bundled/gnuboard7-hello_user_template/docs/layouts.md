# Hello 사용자 템플릿 — 레이아웃

> 레이아웃 목록과 라우트 매핑 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃 목록

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 8개 (루트: `layouts`).

| 그룹 | 개수 |
|---|---|
| `(root)` | 2개 |
| `errors` | 6개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_user_base` | `(root)` | partial | - |
| `401` | `errors` | 화면 | `_user_base` |
| `403` | `errors` | 화면 | `_user_base` |
| `404` | `errors` | 화면 | `_user_base` |
| `500` | `errors` | 화면 | `_user_base` |
| `503` | `errors` | 화면 | `_user_base` |
| `maintenance` | `errors` | 화면 | `_user_base` |
| `home` | `(root)` | 화면 | `_user_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
8개 중 **6개가 오류 레이아웃**입니다. 오류 6종(401 · 403 · 404 · 500 · 503 · maintenance)은
선택이 아니라 **최소 구성**입니다 — 코어가 그 상황에서 활성 템플릿의 레이아웃을 부르는데
없으면 방문자에게 백지가 됩니다.

나머지 둘이 베이스(`_user_base`)와 홈(`home`)입니다. **홈이 이 샘플의 학습 포인트**로,
`data_sources` 로 학습용 모듈의 메모 API 를 `auto_fetch` 호출해 목록을 그립니다
(`loading_strategy: progressive`). 모듈이 데이터를, 템플릿이 화면을 담당하는 경계가 이 한
파일에 들어 있습니다.

여덟 모두 `_user_base` 를 상속합니다. **`extends` 없는 독립 레이아웃을 만들면** 그 화면에서는
`toast`·`openModal` 이 성공으로 기록되지만 화면에는 아무것도 나타나지 않습니다(호스트
컴포넌트가 마운트되지 않아서).

`401` 레이아웃에서 **로그인 리다이렉트를 직접 구현하지 않습니다** — 코어
`TemplateApp.showRouteError` 가드가 처리하므로, 여기서 다시 이동시키면 이중 리다이렉트가 되고
돌아올 위치도 어긋납니다.

레이아웃 JSON 만 고쳤다면 빌드 없이
`php artisan template:update gnuboard7-hello_user_template --force` 로 반영합니다.
<!-- @intent END -->

## 라우트 매핑

<!-- @generated:layout-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 레이아웃 | 이름 |
|---|---|---|
| `/` | `home` | - |
<!-- @generated:layout-map END -->

<!-- @intent START -->
하나뿐입니다 — `/` → `home`, 그리고 `auth_required: false`.

**로그인 없이 접근하는 화면임을 선언**하는 것이 이 플래그의 역할입니다. User 템플릿의 대부분
화면이 그렇고, 마이페이지처럼 회원 전용인 화면만 `true` 로 둡니다. 빠뜨리면 방문자가 첫 화면에서
로그인으로 튕깁니다.

오류 레이아웃에는 라우트가 없습니다 — 코어가 상황을 보고 직접 부르므로 경로로 도달하는 화면이
아닙니다.

실제 템플릿에서는 라우트 경로에 표현식을 쓸 수 있습니다(`sirsoft-basic` 의 상점 경로가
이커머스 설정을 반영하듯). 이 샘플은 정적 경로 하나로 최소 형태만 보입니다.

라우트를 바꾼 뒤에는 `template:update --force` 로 반영합니다.
<!-- @intent END -->

## 확장 오버라이드

<!-- @generated:template-overrides START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_오버라이드하는 레이아웃 확장 조각이 없습니다._
<!-- @generated:template-overrides END -->

<!-- @intent START -->
없습니다. 이 샘플은 다른 확장이 제공한 조각을 대체하지 않습니다.

오버라이드는 `extensions/{확장}/` 에 그 확장의 조각과 같은 이름의 파일을 두면 성립합니다 —
플러그인이 제공한 조각의 디자인이 이 템플릿과 어긋날 때 **조각을 고치는 대신 템플릿이 자기
버전을 얹는** 방향입니다.

그 대가로 원본이 바뀌어도 사본은 따라가지 않습니다. 오버라이드를 두었다면 그 확장을 업그레이드한
뒤 동작을 확인해야 합니다 — 원본이 핸들러 이름이나 필드 계약을 바꾸면 오버라이드만 옛 계약을
붙들고 있게 되고, 증상은 "그 자리만 무반응" 으로 나타납니다.
<!-- @intent END -->
