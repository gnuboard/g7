# Hello Admin Template — 레이아웃

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
| `_admin_base` | `(root)` | partial | - |
| `admin_dashboard` | `(root)` | 화면 | `_admin_base` |
| `401` | `errors` | 화면 | `_admin_base` |
| `403` | `errors` | 화면 | `_admin_base` |
| `404` | `errors` | 화면 | `_admin_base` |
| `500` | `errors` | 화면 | `_admin_base` |
| `503` | `errors` | 화면 | `_admin_base` |
| `maintenance` | `errors` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
8개 중 **6개가 오류 레이아웃**입니다. 이 비율이 이 샘플이 말하려는 것 그 자체입니다 — 오류
레이아웃 6종(401 · 403 · 404 · 500 · 503 · maintenance)은 선택이 아니라 **최소 구성**입니다.

코어는 오류 상황에서 활성 템플릿의 해당 레이아웃을 부릅니다. 없으면 그 오류를 표시할 수단이
없어 사용자에게는 백지가 되므로, 화면이 하나뿐인 템플릿에도 6종은 있어야 합니다.

여섯 모두 `_admin_base` 를 상속합니다. 오류 화면에서도 관리자 골격이 유지되고, 뼈대를 한 번
고치면 전 화면에 반영됩니다. **`extends` 없는 독립 레이아웃을 만들면** 그 화면에서는
`toast`·`openModal` 이 성공으로 기록되지만 화면에는 아무것도 나타나지 않습니다(호스트
컴포넌트가 마운트되지 않아서).

`401` 레이아웃에서 **로그인 리다이렉트를 직접 구현하지 않습니다** — 코어
`TemplateApp.showRouteError` 가드가 처리하므로, 여기서 다시 이동시키면 이중 리다이렉트가 되고
돌아올 위치도 어긋납니다.

레이아웃 JSON 만 고쳤다면 빌드 없이
`php artisan template:update gnuboard7-hello_admin_template --force` 로 반영합니다.
<!-- @intent END -->

## 라우트 매핑

<!-- @generated:layout-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 레이아웃 | 이름 |
|---|---|---|
| `*/admin` | `admin_dashboard` | - |
<!-- @generated:layout-map END -->

<!-- @intent START -->
하나뿐입니다 — `*/admin` → `admin_dashboard`.

앞의 `*` 는 로케일 접두 등 가변 구간을 받는 자리입니다. Admin 템플릿의 라우트는 이 형태로
`*/admin/...` 네임스페이스 안에 있어야 하며, 그 밖의 경로를 선언하면 모듈·User 템플릿이 소유한
경로와 다투게 되고 어느 쪽이 이기는지가 설치 순서에 좌우됩니다.

오류 레이아웃에는 라우트가 없습니다 — 코어가 상황을 보고 직접 부르므로 경로로 도달하는 화면이
아닙니다.

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
