# 수출입은행 환율 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 12개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 12개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_history_filter` | `admin` | partial | - |
| `_history_grid` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_notifications_card` | `admin` | partial | - |
| `_recent_runs` | `admin` | partial | - |
| `_settings_form` | `admin` | partial | - |
| `_status_card` | `admin` | partial | - |
| `_tab_history` | `admin` | partial | - |
| `_tab_settings` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
plugin_settings 하나에 상태·설정·알림·최근 이력과 전체 이력 탭을 담습니다. tab/status/trigger/date/page를 URL에서 읽고 필터 변경은 페이지를 1로 되돌립니다. 행 펼침 시 단건 API로 통화 상세를 가져옵니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
별도 JavaScript 핸들러 없이 코어 apiCall/navigate/setState/refetchDataSource를 사용합니다. Select는 $event.target.value, TabNavigation과 DataGrid는 각 컴포넌트의 인자 계약을 사용합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 함수는 추가하지 않습니다. 알림 모달 상태와 펼친 통화 상세 맵만 페이지의 고유 접두사로 구분하고 API 인증·알림 발송은 코어 경로를 사용합니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
기존 관리자 템플릿의 컴포넌트와 CSS 유틸리티를 사용하므로 런타임 에셋 번들이 없습니다. 레이아웃 변경 후 플러그인 업데이트로 반영하며, 관리 배지는 입력줄 아래에서 한 줄을 유지합니다.
<!-- @intent END -->
