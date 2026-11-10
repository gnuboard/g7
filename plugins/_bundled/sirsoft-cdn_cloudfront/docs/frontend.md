# CloudFront CDN — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
설정 화면은 코어 플러그인 설정 API(`/api/admin/plugins/{id}/settings`)로 폼을 저장하고, 계산값(무효화 대기 현황)은 플러그인 자체 API 로 따로 받는다 — 코어 설정 응답에는 계산값을 덧붙일 자리가 없다. 연결 시험은 저장된 값으로만 하므로 폼의 고친 값은 보내지 않는다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
플러그인 전용 액션 핸들러가 없다. 코어 액션(api-call, set-state, refetch-data-source, toast)만 쓴다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
화면 스크립트가 없어 전역 진입점이 없다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
화면 모양은 손으로 쓴 순수 CSS(`resources/css/main.css`, 클래스 앞글자 `cdncf-`)가 싣는다. 템플릿 CSS 에 기대지 않으며, 다크 모드는 코어 표식 `data-g7-color-scheme` 만 본다. CSS 는 화면 폭을 보지 않는다 — 바깥 여백(640~1023px `cdncf-settings--md`, 1024px 이상 `--lg`)·이름과 입력 칸 1:2 배치(1024px 이상 `cdncf-field-row--wide`)·현황 4열(1024px 이상 `cdncf-stat-grid--wide`)은 레이아웃의 엔진 `responsive` 구간 값이 붙인다.
<!-- @intent END -->
