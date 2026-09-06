# Admin Basic — 레이아웃

> 레이아웃 목록과 라우트 매핑 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃 목록

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 146개 (루트: `layouts`).

| 그룹 | 개수 |
|---|---|
| `(root)` | 27개 |
| `auth` | 1개 |
| `errors` | 6개 |
| `overrides` | 1개 |
| `partials` | 111개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_admin_base` | `(root)` | partial | - |
| `admin_activity_log_list` | `(root)` | 화면 | `_admin_base` |
| `admin_dashboard` | `(root)` | 화면 | `_admin_base` |
| `admin_forgot_password` | `(root)` | 화면 | - |
| `admin_identity_logs` | `(root)` | 화면 | `_admin_base` |
| `admin_language_pack_list` | `(root)` | 화면 | `_admin_base` |
| `admin_language_packs` | `(root)` | 화면 | `admin_language_pack_list` |
| `admin_language_packs_install_modal` | `(root)` | 화면 | - |
| `admin_login` | `(root)` | 화면 | - |
| `admin_menu_list` | `(root)` | 화면 | `_admin_base` |
| `admin_module_language_packs` | `(root)` | 화면 | `admin_language_pack_list` |
| `admin_module_list` | `(root)` | 화면 | `_admin_base` |
| `admin_notification_log_list` | `(root)` | 화면 | `_admin_base` |
| `admin_plugin_language_packs` | `(root)` | 화면 | `admin_language_pack_list` |
| `admin_plugin_list` | `(root)` | 화면 | `_admin_base` |
| `admin_reset_password` | `(root)` | 화면 | - |
| `admin_role_form` | `(root)` | 화면 | `_admin_base` |
| `admin_role_list` | `(root)` | 화면 | `_admin_base` |
| `admin_schedule_list` | `(root)` | 화면 | `_admin_base` |
| `admin_settings` | `(root)` | 화면 | `_admin_base` |
| `admin_template_language_packs` | `(root)` | 화면 | `admin_language_pack_list` |
| `admin_template_layout_edit` | `(root)` | 화면 | `_admin_base` |
| `admin_template_list` | `(root)` | 화면 | `_admin_base` |
| `admin_user_detail` | `(root)` | 화면 | `_admin_base` |
| `admin_user_form` | `(root)` | 화면 | `_admin_base` |
| `admin_user_list` | `(root)` | 화면 | `_admin_base` |
| `identity_challenge` | `auth` | 화면 | `_admin_base` |
| `401` | `errors` | 화면 | `_admin_base` |
| `403` | `errors` | 화면 | `_admin_base` |
| `404` | `errors` | 화면 | `_admin_base` |
| `500` | `errors` | 화면 | `_admin_base` |
| `503` | `errors` | 화면 | `_admin_base` |
| `maintenance` | `errors` | 화면 | `_admin_base` |
| `index` | `overrides` | 화면 | `_admin_base` |
| `_identity_challenge_modal` | `partials` | partial | - |
| `_modal_changelog` | `partials` | partial | - |
| `_modal_license` | `partials` | partial | - |
| `_modal_notification_delete_all_confirm` | `partials` | partial | - |
| `_partial_datagrid` | `partials` | partial | - |
| `_partial_filter` | `partials` | partial | - |
| `_content` | `partials` | partial | - |
| `_modal_log_detail` | `partials` | partial | - |
| `_modal_purge_confirm` | `partials` | partial | - |
| `_partial_datagrid` | `partials` | partial | - |
| `_partial_filter` | `partials` | partial | - |
| `_content` | `partials` | partial | - |
| `_drawer_manifest_preview` | `partials` | partial | - |
| `_modal_detail` | `partials` | partial | - |
| `_modal_install` | `partials` | partial | - |
| `_modal_install_bundled` | `partials` | partial | - |
| `_modal_refresh_cache` | `partials` | partial | - |
| `_modal_slot_conflict` | `partials` | partial | - |
| `_modal_uninstall` | `partials` | partial | - |
| `_modal_update` | `partials` | partial | - |
| `_modal_delete` | `partials` | partial | - |
| `_panel_detail` | `partials` | partial | - |
| `_panel_form` | `partials` | partial | - |
| `_panel_menu_list` | `partials` | partial | - |
| `_panel_view` | `partials` | partial | - |
| `_drawer_manifest_preview` | `partials` | partial | - |
| `_modal_deactivate_warning` | `partials` | partial | - |
| `_modal_detail` | `partials` | partial | - |
| `_modal_extension_license` | `partials` | partial | - |
| `_modal_force_activate` | `partials` | partial | - |
| `_modal_force_deactivate` | `partials` | partial | - |
| `_modal_install` | `partials` | partial | - |
| `_modal_manual_install` | `partials` | partial | - |
| `_modal_reactivate_language_packs` | `partials` | partial | - |
| `_modal_refresh_layouts` | `partials` | partial | - |
| `_modal_uninstall` | `partials` | partial | - |
| `_modal_update` | `partials` | partial | - |
| `_modal_log_detail` | `partials` | partial | - |
| `_partial_datagrid` | `partials` | partial | - |
| `_partial_filter` | `partials` | partial | - |
| `_drawer_manifest_preview` | `partials` | partial | - |
| `_modal_detail` | `partials` | partial | - |
| `_modal_extension_license` | `partials` | partial | - |
| `_modal_force_activate` | `partials` | partial | - |
| `_modal_force_deactivate` | `partials` | partial | - |
| `_modal_install` | `partials` | partial | - |
| `_modal_manual_install` | `partials` | partial | - |
| `_modal_reactivate_language_packs` | `partials` | partial | - |
| `_modal_refresh_layouts` | `partials` | partial | - |
| `_modal_uninstall` | `partials` | partial | - |
| `_modal_update` | `partials` | partial | - |
| `_modal_delete` | `partials` | partial | - |
| `_modal_delete` | `partials` | partial | - |
| `_modal_duplicate` | `partials` | partial | - |
| `_modal_form` | `partials` | partial | - |
| `_modal_history` | `partials` | partial | - |
| `_modal_run` | `partials` | partial | - |
| `_tab_schedules` | `partials` | partial | - |
| `_modal_cache_delete` | `partials` | partial | - |
| `_modal_core_changelog` | `partials` | partial | - |
| `_modal_core_update_guide` | `partials` | partial | - |
| `_modal_core_update_result` | `partials` | partial | - |
| `_modal_identity_message_definition_add` | `partials` | partial | - |
| `_modal_identity_message_definition_delete` | `partials` | partial | - |
| `_modal_identity_message_definition_reset` | `partials` | partial | - |
| `_modal_identity_message_template_form` | `partials` | partial | - |
| `_modal_identity_message_template_preview` | `partials` | partial | - |
| `_modal_identity_policy_delete` | `partials` | partial | - |
| `_modal_identity_policy_form` | `partials` | partial | - |
| `_modal_mail_template_form` | `partials` | partial | - |
| `_modal_notification_definition_reset` | `partials` | partial | - |
| `_modal_notification_template_form` | `partials` | partial | - |
| `_modal_notification_template_preview` | `partials` | partial | - |
| `_modal_password_confirm` | `partials` | partial | - |
| `_modal_static_cache_republish` | `partials` | partial | - |
| `_tab_advanced` | `partials` | partial | - |
| `_tab_drivers` | `partials` | partial | - |
| `_tab_general` | `partials` | partial | - |
| `_tab_identity` | `partials` | partial | - |
| `_tab_identity_basic` | `partials` | partial | - |
| `_tab_identity_messages` | `partials` | partial | - |
| `_tab_identity_policies` | `partials` | partial | - |
| `_tab_identity_providers` | `partials` | partial | - |
| `_tab_info` | `partials` | partial | - |
| `_tab_language_packs` | `partials` | partial | - |
| `_tab_mail` | `partials` | partial | - |
| `_tab_mail_templates` | `partials` | partial | - |
| `_tab_notification_definitions` | `partials` | partial | - |
| `_tab_security` | `partials` | partial | - |
| `_tab_seo` | `partials` | partial | - |
| `_tab_upload` | `partials` | partial | - |
| `_modal_extension_preview_layout` | `partials` | partial | - |
| `_modal_extension_version_history` | `partials` | partial | - |
| `_modal_version_history` | `partials` | partial | - |
| `_drawer_manifest_preview` | `partials` | partial | - |
| `_modal_activate` | `partials` | partial | - |
| `_modal_deactivate` | `partials` | partial | - |
| `_modal_detail` | `partials` | partial | - |
| `_modal_extension_license` | `partials` | partial | - |
| `_modal_force_activate` | `partials` | partial | - |
| `_modal_install` | `partials` | partial | - |
| `_modal_manual_install` | `partials` | partial | - |
| `_modal_reactivate_language_packs` | `partials` | partial | - |
| `_modal_refresh_layouts` | `partials` | partial | - |
| `_modal_uninstall` | `partials` | partial | - |
| `_modal_update` | `partials` | partial | - |
| `_tab_admin` | `partials` | partial | - |
| `_tab_user` | `partials` | partial | - |
| `_content_section` | `partials` | partial | - |
| `_header_section` | `partials` | partial | - |
| `_info_card` | `partials` | partial | - |
| `template_partial_test` | `(root)` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
그룹은 "이 파일이 독립된 화면 URL을 갖는가"로 나뉩니다 — `(root)`/`auth`/`errors`/`overrides` 는
라우트에 직접 매핑되는 화면이고, `partials`(110개, 전체의 76%)는 화면에 `extends`/포함되는
조각(탭·모달·패널)이라 그 자체로는 URL이 없습니다. partial 비중이 압도적으로 큰 것은 관리자
화면 하나가 보통 탭 여러 개 + 모달 여러 개로 구성되기 때문입니다(예: 확장 관리 화면 하나가
설치/삭제/업데이트/강제활성화 등 모달을 5~10개씩 갖습니다).

이 목록은 코드에서 실측되므로 구체적인 개수·파일명을 프로즈에 하드코딩해 반복하지 않습니다
— 과거 버전 문서가 손으로 그린 전체 페이지 트리(약 20개 화면 기준)는 이미 145개로 늘어난
현재 상태와 크게 어긋나 있었습니다. 전체 목록은 항상 위 생성 표를 신뢰합니다.

### 화면 유형별 구성 패턴

새 관리자 화면을 만들 때 아래 패턴 중 가장 가까운 것을 참고합니다(구체적 파일명이 아니라
**구조**를 재사용하는 것이 목적입니다 — 실제 예시 파일은 위 표에서 비슷한 이름을 찾습니다).

**목록 화면**: `extends: _admin_base`, `slots.content` 에 `PageHeader`(제목·액션) →
`FilterGroup`(선택) → `DataGrid`/`CardGrid`(목록) → `Pagination`. data_sources 는
`auto_fetch: true` + `params`에 `_local.page`/`per_page` 바인딩. 삭제·상태변경은 `apiCall`,
상세/수정 이동은 `navigate`, 필터·페이지네이션은 `setState`. 목록 컨텍스트 왕복 규약
(`mergeQuery`)을 지킵니다(§코어 AGENTS.md).

**상세 화면**: `extends: _admin_base`, `data_sources`에 `route.id` 기반 상세 API,
`slots.content`에 `PageHeader` + `Card` 여러 개(기본 정보/활동 내역/권한 정보 등 섹션별 분리).

**폼 화면(생성/수정 겸용)**: `route.id` 존재 여부로 생성/수정을 분기, `Form` 안에
`FormField`+`Input`/`Select`/`Toggle` 조합. `Button` 은 `type="button"` 명시(submit 방지),
서버 검증 에러는 `FormField` 의 `error` prop 으로 표시.

**설정(탭) 화면**: `TabNavigation` + `activeTab` 상태에 따라 `_tab_*.json` partial 을
조건부 렌더링. 탭 전환은 `setState`, 저장은 `apiCall`, 파괴적 동작 확인은 `openModal`.

**확장 관리 화면**(모듈/플러그인/템플릿 목록): `DataGrid`/`CardGrid` + `StatusBadge`(상태) +
`ActionMenu`(설치/활성화/비활성화/삭제/업데이트) + `ExtensionBadge`. 설치·삭제·업데이트마다
전용 확인 모달(`_modal_install`/`_modal_uninstall`/`_modal_update` 등)을 개별 파일로 분리 —
한 모달에 여러 동작을 조건 분기로 몰아넣지 않습니다(모달마다 문구·부작용이 다릅니다).

**에러 화면**(`errors/*.json`): `extends` 없는 독립 레이아웃. `Div`(중앙 정렬) 안에
`Icon`+`H1`(코드)+`P`(메시지)+`Button`(홈 이동). 독립 레이아웃이므로 `Toast`/`Modal` 같은
전역 호스트 컴포넌트가 필요하면 직접 마운트해야 합니다(§코어 AGENTS.md "독립 레이아웃의 글로벌
호스트 컴포넌트").

### `_admin_base.json` 에 대한 정정

과거 버전 문서는 `_admin_base.json` 이 `init_actions: [initTheme, initMenuFromUrl]` 를 갖는다고
적었으나, 현재 `_admin_base.json` 에는 `init_actions` 키 자체가 없습니다 — 두 핸들러는 현재
`_admin_base` 를 상속하지 않는 인증 화면(`admin_login`/`admin_forgot_password`/
`admin_reset_password`)에서만 호출됩니다. 사이드바 접힘 상태 복원(`initSidebar`)은 레이아웃이
아니라 템플릿 부트스트랩(`src/index.ts`)에서 직접 호출됩니다. `_admin_base` 를 고칠 때 이
문서의 낡은 구조를 그대로 믿지 말고 실제 JSON 을 확인하세요.
<!-- @intent END -->

## 라우트 매핑

<!-- @generated:layout-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 레이아웃 | 이름 |
|---|---|---|
| `*/admin` | `-` | - |
| `*/admin/login` | `admin_login` | - |
| `*/admin/forgot-password` | `admin_forgot_password` | - |
| `*/admin/reset-password` | `admin_reset_password` | - |
| `*/admin/dashboard` | `admin_dashboard` | - |
| `*/admin/users` | `admin_user_list` | - |
| `*/admin/users/create` | `admin_user_form` | - |
| `*/admin/users/:id` | `admin_user_detail` | - |
| `*/admin/users/:id/edit` | `admin_user_form` | - |
| `*/admin/modules` | `admin_module_list` | - |
| `*/admin/settings/language-packs` | `-` | - |
| `*/admin/modules/:identifier/language-packs` | `admin_module_language_packs` | - |
| `*/admin/plugins/:identifier/language-packs` | `admin_plugin_language_packs` | - |
| `*/admin/templates/:identifier/language-packs` | `admin_template_language_packs` | - |
| `*/admin/plugins` | `admin_plugin_list` | - |
| `*/admin/menus` | `admin_menu_list` | - |
| `*/admin/templates` | `-` | - |
| `*/admin/templates/:identifier/edit` | `admin_template_layout_edit` | - |
| `*/admin/templates/:type` | `admin_template_list` | - |
| `*/admin/template/partial` | `template_partial_test` | - |
| `*/admin/activity-logs` | `admin_activity_log_list` | - |
| `*/admin/notification-logs` | `admin_notification_log_list` | - |
| `*/admin/settings` | `admin_settings` | - |
| `*/admin/roles` | `admin_role_list` | - |
| `*/admin/roles/create` | `admin_role_form` | - |
| `*/admin/roles/:id/edit` | `admin_role_form` | - |
| `*/admin/schedules` | `admin_schedule_list` | - |
| `*/admin/identity/logs` | `admin_identity_logs` | - |
| `*/admin/identity/challenge` | `auth/identity_challenge` | - |
<!-- @generated:layout-map END -->

<!-- @intent START -->
`레이아웃` 열이 `-` 인 두 행(`*/admin`, `*/admin/settings/language-packs`)은 레이아웃이
없다는 뜻이 아니라 **다른 라우트로 리다이렉트되는 진입점**입니다 — 예를 들어 `/admin` 은
로그인 여부에 따라 `/admin/login` 또는 `/admin/dashboard` 로 넘어가는 게이트 라우트입니다.
새 화면을 추가할 때 이 표에 라우트를 등록하는 것만으로 끝나지 않습니다 — 사이드바 메뉴에서
그 화면으로 이동하는 진입점도 함께 추가해야 실제로 도달 가능해집니다(라우트만 있고 메뉴
항목이 없으면 URL을 직접 입력해야만 닿는 화면이 됩니다).
<!-- @intent END -->

## 확장 오버라이드

<!-- @generated:template-overrides START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_오버라이드하는 레이아웃 확장 조각이 없습니다._
<!-- @generated:template-overrides END -->

<!-- @intent START -->
이 템플릿은 현재 어떤 모듈/플러그인의 레이아웃 확장 조각도 오버라이드하지 않습니다 —
`sirsoft-basic` 템플릿과 달리 관리자 화면은 확장이 끼워 넣는 조각(예: 이커머스 문의 설정,
GDPR 배너)을 코어 대시보드 위젯 형태로만 받고, 이 템플릿이 그 조각을 대체할 필요가 아직
없었기 때문입니다. 특정 확장의 관리자 UI 를 이 템플릿에서만 다르게 보이게 하려면
`extensions/{module-identifier}/*.json` 을 신설합니다(§docs/extension/layout-extensions.md
"템플릿 오버라이드").
<!-- @intent END -->

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-admin_basic/layouts.md` 에 있던 원문을
> 이 문서로 옮긴 것입니다(#601). 페이지 맵 트리와 화면 유형별 패턴 상세가 여기에 있습니다.
> 이관 시점 그대로 보존하되, 현재 코드와 어긋나는 부분에는 정정 주석을 달았습니다 —
> 레이아웃·라우트의 SSoT 는 위 「레이아웃 목록」·「라우트 매핑」 블록입니다.

### 페이지 맵 (트리 구조)

```text
_admin_base.json (베이스 레이아웃)
│
├── admin_dashboard.json (대시보드)
├── admin_login.json (로그인 — _admin_base 미상속)
│
├── 사용자 관리
│   ├── admin_user_list.json (목록)
│   ├── admin_user_form.json (생성/수정)
│   └── admin_user_detail.json (상세)
│
├── 역할 관리
│   ├── admin_role_list.json (목록)
│   │   └── partials/admin_role_list/_modal_delete.json
│   └── admin_role_form.json (생성/수정)
│
├── 메뉴 관리
│   └── admin_menu_list.json (3패널 레이아웃)
│       └── partials/admin_menu_list/
│           ├── _panel_menu_list.json (좌측: 메뉴 트리)
│           ├── _panel_form.json (중앙: 편집 폼)
│           ├── _panel_detail.json (중앙: 상세 보기)
│           ├── _panel_view.json (우측: 미리보기)
│           └── _modal_delete.json
│
├── 환경 설정
│   └── admin_settings.json (탭 네비게이션)
│       └── partials/admin_settings/
│           ├── _tab_general.json (일반)
│           ├── _tab_mail.json (메일 발송 SMTP 설정)
│           ├── _tab_notification_definitions.json (알림 정의)
│           ├── _tab_security.json (보안)
│           ├── _tab_upload.json (업로드)
│           ├── _tab_drivers.json (드라이버)
│           ├── _tab_seo.json (SEO)
│           ├── _tab_advanced.json (고급)
│           ├── _tab_info.json (시스템 정보)
│           ├── _modal_cache_delete.json
│           ├── _modal_core_changelog.json
│           ├── _modal_core_update_guide.json
│           ├── _modal_core_update_result.json
│           ├── _modal_notification_template_form.json
│           ├── _modal_notification_template_preview.json
│           └── _modal_password_confirm.json
│
├── 모듈 관리
│   └── admin_module_list.json
│       └── partials/admin_module_list/
│           ├── _modal_detail.json
│           ├── _modal_install.json
│           ├── _modal_manual_install.json
│           ├── _modal_uninstall.json
│           ├── _modal_update.json
│           ├── _modal_deactivate_warning.json
│           ├── _modal_force_activate.json
│           ├── _modal_force_deactivate.json
│           ├── _modal_extension_license.json
│           └── _modal_refresh_layouts.json
│
├── 플러그인 관리
│   └── admin_plugin_list.json
│       └── partials/admin_plugin_list/
│           ├── (모듈과 동일 구조 — 10개 모달)
│           └── ...
│
├── 템플릿 관리
│   ├── admin_template_list.json
│   │   └── partials/admin_template_list/
│   │       ├── _tab_admin.json (Admin 템플릿 탭)
│   │       ├── _tab_user.json (User 템플릿 탭)
│   │       ├── _modal_detail.json
│   │       ├── _modal_install.json
│   │       ├── _modal_manual_install.json
│   │       ├── _modal_uninstall.json
│   │       ├── _modal_update.json
│   │       ├── _modal_activate.json
│   │       ├── _modal_deactivate.json
│   │       ├── _modal_force_activate.json
│   │       ├── _modal_extension_license.json
│   │       └── _modal_refresh_layouts.json
│   └── admin_template_layout_edit.json (레이아웃 편집기)
│       └── partials/admin_template_layout_edit/_modal_version_history.json
│
├── 스케줄 관리
│   └── admin_schedule_list.json
│       └── partials/admin_schedule_list/
│           ├── _tab_schedules.json
│           ├── _modal_form.json
│           ├── _modal_delete.json
│           ├── _modal_duplicate.json
│           ├── _modal_history.json
│           └── _modal_run.json
│
├── 메일 발송 로그
│   └── admin_mail_send_log_list.json
│       └── partials/admin_mail_send_log_list/
│           ├── _partial_datagrid.json
│           └── _partial_filter.json
│
├── 공통 Partial
│   ├── partials/_modal_changelog.json
│   └── partials/_modal_license.json
│
├── 에러 페이지
│   └── errors/
│       ├── 401.json (인증 필요)
│       ├── 403.json (접근 거부)
│       ├── 404.json (페이지 없음)
│       ├── 500.json (서버 오류)
│       ├── 503.json (서비스 불가)
│       └── maintenance.json (점검 중)
│
├── 오버라이드
│   └── overrides/sirsoft-sample/index.json
│
└── 테스트
    └── template_partial_test.json
        └── partials/template_partial_test/
            ├── _content_section.json
            ├── _header_section.json
            └── _info_card.json
```

---

### 카테고리별 가이드

#### 목록 페이지 패턴

**대표**: `admin_user_list.json`, `admin_role_list.json`

**구성**:
```text
extends: _admin_base
slots.content:
  └── PageHeader (제목, 액션 버튼)
  └── FilterGroup (선택적)
  └── DataGrid (columns, data, pagination)
  └── Pagination
```

**data_sources**:
```json
{
  "id": "users",
  "endpoint": "/api/admin/users",
  "method": "GET",
  "auto_fetch": true,
  "params": { "page": "{{_local.page ?? 1}}", "per_page": "{{_local.per_page ?? 15}}" }
}
```

**핸들러 패턴**:
- `apiCall` — 삭제, 상태 변경
- `navigate` — 상세/수정 페이지 이동
- `setState` — 필터, 페이지네이션 상태

**Partial 구조**: 모달 (삭제 확인, 상세 보기 등)

---

#### 상세 페이지 패턴

**대표**: `admin_user_detail.json`

**구성**:
```text
extends: _admin_base
data_sources: [상세 API (route.id 기반)]
slots.content:
  └── PageHeader
  └── Card (기본 정보 섹션)
  └── Card (활동 내역 섹션)
  └── Card (권한 정보 섹션)
```

**data_sources**:
```json
{
  "id": "user",
  "endpoint": "/api/admin/users/{{route.id}}",
  "method": "GET",
  "auto_fetch": true
}
```

**핸들러 패턴**:
- `apiCall` — 상태 변경 (활성화/비활성화, 역할 변경)
- `navigate` — 목록으로 이동, 수정 페이지 이동

---

#### 폼 페이지 패턴

**대표**: `admin_user_form.json`, `admin_role_form.json`

**구성**:
```text
extends: _admin_base
data_sources: [상세 API (수정 시), 참조 데이터 (Select 옵션)]
slots.content:
  └── PageHeader
  └── Form
      ├── FormField + Input (텍스트)
      ├── FormField + Select (선택)
      ├── FormField + Toggle (토글)
      └── Button (저장/취소)
```

**핸들러 패턴**:
- `apiCall` — 생성 (POST) / 수정 (PUT)
- `navigate` — 성공 후 목록/상세로 이동

**주의사항**:
```text
✅ Form 내 Button에 type="button" 명시 (submit 방지)
✅ 수정 폼은 route.id 존재 여부로 생성/수정 구분
✅ FormField에 error prop으로 서버 검증 에러 표시
```

---

#### 설정 페이지 패턴

**대표**: `admin_settings.json`

**구성**:
```text
extends: _admin_base
data_sources: [설정 API]
slots.content:
  └── PageHeader
  └── TabNavigation (tabs)
  └── Div (탭별 partial 조건부 렌더링)
      ├── if: activeTab === 'general' → partial: _tab_general.json
      ├── if: activeTab === 'mail' → partial: _tab_mail.json
      ├── if: activeTab === 'security' → partial: _tab_security.json
      └── ...
```

**Partial 구조**: `partials/admin_settings/_tab_*.json` (9개 탭)

**핸들러 패턴**:
- `setState` — 탭 전환
- `apiCall` — 설정 저장
- `openModal` — 확인 다이얼로그

---

#### 확장 관리 패턴

**대표**: `admin_module_list.json`, `admin_plugin_list.json`, `admin_template_list.json`

**구성**:
```text
extends: _admin_base
data_sources: [확장 목록 API]
slots.content:
  └── PageHeader (새로고침, 수동 설치 버튼)
  └── DataGrid/CardGrid (확장 목록)
      ├── StatusBadge (상태)
      ├── ActionMenu (설치/활성화/비활성화/삭제/업데이트)
      └── ExtensionBadge (모듈 식별)
modals:
  ├── _modal_detail.json (상세 정보)
  ├── _modal_install.json (설치 확인)
  ├── _modal_uninstall.json (삭제 확인)
  ├── _modal_update.json (업데이트)
  └── _modal_force_activate.json 등
```

**핸들러 패턴**:
- `apiCall` — 설치, 활성화, 비활성화, 삭제, 업데이트
- `openModal` — 확인 다이얼로그
- `setState` — 선택된 확장 정보 저장

**특수사항**:
- 템플릿 관리는 Admin/User 탭 분리 (`_tab_admin.json`, `_tab_user.json`)
- 레이아웃 편집기 (`admin_template_layout_edit.json`)는 CodeEditor + 실시간 미리보기

---

#### 에러 페이지 패턴

**대표**: `errors/404.json`

**구성**:
```text
(extends 없음 — 독립 레이아웃)
components:
  └── Div (전체 화면 중앙 정렬)
      ├── Icon (에러 아이콘)
      ├── H1 (에러 코드)
      ├── P (에러 메시지)
      └── Button (홈으로 이동)
```

**핸들러 패턴**:
- `navigate` — 대시보드/홈으로 이동

---

### 베이스 레이아웃 구조

#### _admin_base.json

모든 관리자 페이지의 공통 구조를 정의합니다.

```text
_admin_base.json
├── init_actions: [initTheme, initMenuFromUrl]
├── data_sources: [admin_menu, notifications]
├── components:
│   ├── AdminSidebar (menu: admin_menu.data)
│   ├── AdminHeader (user, notifications)
│   ├── Toast
│   ├── PageTransitionIndicator
│   └── Div (content area)
│       └── slot: "content" (← 하위 레이아웃이 채움)
└── AdminFooter
```

**슬롯**:
- `content` — 각 페이지의 메인 콘텐츠가 삽입되는 위치

---

### 관련 문서

- [sirsoft-admin_basic 컴포넌트](components.md)
- [sirsoft-admin_basic 핸들러](handlers.md)
- [레이아웃 JSON 스키마](../../../../docs/frontend/layout-json.md)
- [레이아웃 상속](../../../../docs/frontend/layout-json-inheritance.md)
- [sirsoft-basic 레이아웃](../../sirsoft-basic/docs/layouts.md)
