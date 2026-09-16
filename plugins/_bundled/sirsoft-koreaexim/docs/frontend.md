# 수출입은행 환율 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 12개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

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

상태 카드의 「연결 테스트」와 「지금 갱신」은 나란히 놓여 항상 둘 다 보이므로, 진행 중 표시를 `_local.busy` 한 개의 참·거짓으로 두지 않습니다. 누른 쪽이 `busy` 에 자기 이름(`test` · `update`)을 넣고 스피너는 `_local.busy === '<자기 이름>'` 으로 자기 것만 봅니다. 반면 `disabled` 는 `_local.busy` 단독 판정을 그대로 씁니다 — 서버가 두 경로에 같은 실행 락을 걸어 한쪽이 도는 동안 다른 쪽을 눌러도 거절되므로, 함께 잠그는 편이 화면과 서버가 일치합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 함수는 추가하지 않습니다. 알림 모달 상태와 펼친 통화 상세 맵만 페이지의 고유 접두사로 구분하고 API 인증·알림 발송은 코어 경로를 사용합니다.

알림 채널은 화면에 적어 두지 않습니다. 코어가 `core.notification.filter_available_channels` 로 런타임에 정하는 목록을 `/api/admin/notification-channels` 로 받아 **발송 수단을 줄로 깔고 복수로** 켭니다 — 채널을 화면에 적어 두면 확장이 들인 수단(문자 플러그인의 SMS·알림톡 등)에 도달할 수 없고, 단일 선택이면 여러 수단으로 동시에 보낼 수 없습니다. 코어가 아닌 수단은 어느 확장이 들였는지 함께 표시합니다.

켠 값은 폼에만 두고 **저장 버튼을 눌러야** `PUT /api/admin/notification-definitions/{id}` 로 반영합니다(코어 알림 정의 탭과 같은 규약 — 같은 카드의 문구 토글이 즉시 저장인 것과 구분됩니다). 하나도 켜지 않으면 코어가 `min:1` 로 거절하므로 버튼에서 먼저 막습니다. 켰는데 그 수단의 문구가 없으면 코어는 발송 때 조용히 건너뛰고 로그에만 남기므로, 그 줄에 「발송되지 않습니다」를 표시합니다.

문구 목록은 채널을 나눠 그리지 않습니다 — 정의 목록을 `template_channel=all` 로 받아 켠 수단의 문구를 한 번에 보여주므로 수단을 바꾸려고 화면을 다시 부르지 않습니다.
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
