# 팝업 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 3개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 3개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
| `popup_form` | `admin` | 화면 | `_admin_base` |
| `popup_list` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 세 개(목록·등록/수정·환경설정)와 사용자 주입 조각 하나. 미리보기는 사용자 레이어와 같은 컴포넌트(`PopupPreview` → `PopupLayer` 미리보기 모드)를 쓴다 — 관리자가 보는 모양과 방문자가 보는 모양이 갈리지 않게.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
커스텀 액션 핸들러가 없다 — 엔진 기본 액션으로 충분하다. 「보지 않기」 쿠키는 레이아웃 값이 아니라 컴포넌트 책임이라 `resources/js/lib/dismissCookie.ts` 에서 쓴다(엔진에 쿠키 액션이 없다).
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 는 컴포넌트 4개(`PopupLayer`·`PopupPreview`·`PopupPeriodHint`·`PopupConflictResolver`)를 레지스트리에 등록만 한다. React 는 external, DOMPurify 는 번들에 포함한다(템플릿 것을 빌리지 않는다).
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
스타일은 `g7pp-` 접두의 플러그인 소유 CSS 다 — 확장 컴포넌트의 유틸리티 클래스는 템플릿 빌드에 들어 있다는 보장이 없다. 다크 모드는 `.dark` 조상 아래에서 바탕·띠·단추만 바꾼다. 배포 빌드는 `php artisan plugin:build sirsoft-popup --production`(소스맵 없음).
<!-- @intent END -->
