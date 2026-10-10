# Admin Basic — 레이아웃

> 레이아웃 목록과 라우트 매핑 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃 목록

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 184개 (루트: `layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `(root)` | 41개 |
| `auth` | 1개 |
| `errors` | 6개 |
| `partials` | 136개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_admin_base` | `(root)` | partial | - |
| `admin_activity_log_list` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_actions` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_bots` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_diagnostics` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_environment` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_overview` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_pages` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_patterns` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_privacy_requests` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_realtime` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_referrers` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_regions` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_settings` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_visitor_detail` | `(root)` | 화면 | `_admin_base` |
| `admin_analytics_visitors` | `(root)` | 화면 | `_admin_base` |
| `admin_dashboard` | `(root)` | 화면 | `_admin_base` |
| `admin_forgot_password` | `(root)` | 화면 | - |
| `admin_identity_logs` | `(root)` | 화면 | `_admin_base` |
| `admin_language_pack_list` | `(root)` | 화면 | `_admin_base` |
| `admin_language_packs` | `(root)` | 화면 | `admin_language_pack_list` |
| `admin_login` | `(root)` | 화면 | - |
| `admin_member_notification_list` | `(root)` | 화면 | `_admin_base` |
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
| `_identity_challenge_modal` | `partials` | partial | - |
| `_modal_changelog` | `partials` | partial | - |
| `_modal_license` | `partials` | partial | - |
| `_modal_notification_delete_all_confirm` | `partials` | partial | - |
| `_partial_datagrid` | `partials` | partial | - |
| `_partial_filter` | `partials` | partial | - |
| `_modal_purge_all` | `partials` | partial | - |
| `_modal_reaggregate` | `partials` | partial | - |
| `_modal_retro_anonymize` | `partials` | partial | - |
| `_modal_row_trend` | `partials` | partial | - |
| `_modal_subject_delete` | `partials` | partial | - |
| `_partial_kpi_row` | `partials` | partial | - |
| `_partial_notification_definitions` | `partials` | partial | - |
| `_partial_period_bar` | `partials` | partial | - |
| `_partial_realtime_bar` | `partials` | partial | - |
| `_partial_report_grid` | `partials` | partial | - |
| `_partial_status_alerts` | `partials` | partial | - |
| `_analytics_widget` | `partials` | partial | - |
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
| `_partial_datagrid` | `partials` | partial | - |
| `_partial_filter` | `partials` | partial | - |
| `_modal_bulk_roles_confirm` | `partials` | partial | - |
| `_modal_delete` | `partials` | partial | - |
| `_panel_bulk_roles` | `partials` | partial | - |
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
| `_modal_identity_policy_delete` | `partials` | partial | - |
| `_modal_identity_policy_form` | `partials` | partial | - |
| `_modal_ip_restriction_rule_form` | `partials` | partial | - |
| `_modal_notification_channel_off_confirm` | `partials` | partial | - |
| `_modal_notification_definition_reset` | `partials` | partial | - |
| `_modal_notification_identity_definition_add` | `partials` | partial | - |
| `_modal_notification_identity_definition_delete` | `partials` | partial | - |
| `_modal_notification_template_form` | `partials` | partial | - |
| `_modal_notification_template_preview` | `partials` | partial | - |
| `_modal_password_confirm` | `partials` | partial | - |
| `_modal_password_expiry_run` | `partials` | partial | - |
| `_modal_privacy_run` | `partials` | partial | - |
| `_modal_privacy_targets` | `partials` | partial | - |
| `_modal_static_cache_republish` | `partials` | partial | - |
| `_modal_unsaved_tab_switch` | `partials` | partial | - |
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
| `_tab_notification_definitions` | `partials` | partial | - |
| `_tab_privacy` | `partials` | partial | - |
| `_tab_security` | `partials` | partial | - |
| `_tab_security_activity_log` | `partials` | partial | - |
| `_tab_security_ip_restriction` | `partials` | partial | - |
| `_tab_security_password_policy` | `partials` | partial | - |
| `_tab_seo` | `partials` | partial | - |
| `_tab_upload` | `partials` | partial | - |
| `_modal_extension_preview_layout` | `partials` | partial | - |
| `_modal_extension_version_history` | `partials` | partial | - |
| `_modal_file_delete_confirm` | `partials` | partial | - |
| `_modal_file_form` | `partials` | partial | - |
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
| `_modal_bulk_roles` | `partials` | partial | - |
| `_partial_filter_section` | `partials` | partial | - |
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
업데이트 모달의 성공 토스트는 서버 응답 문구(`response.message`)를 띄웁니다 — 업데이트할 것이 없었으면
「업데이트할 내용이 없습니다」가 옵니다. 실패 분기는 409(같은 확장의 업데이트가 이미 진행 중 — 버튼 연속 클릭,
Artisan 커맨드와 동시 실행)를 오류 상자 없이 안내 토스트로 보여 주고 진행 표시를 풉니다. 커맨드가 업데이트하는
동안 409 를 받고도 진행 표시를 켜 두면 취소 버튼까지 잠겨 창을 닫을 수 없기 때문입니다.
업데이트 모달의 「레이아웃 전략」 라디오는 `layout_strategy` 를 요청 본문에 싣습니다. 「수정 유지(keep)」 는 운영자가
고친 레이아웃·확장 조각을 통째로 두는 것이 아니라 고친 부분은 유지하고 손대지 않은 부분에 새 버전을 병합하므로, 설명·
목록 제목·안내 문구(`layout_strategy_keep_desc`·`modified_layouts_list_title`·`modified_layouts_keep_notice`)도 그 뜻으로
씁니다. 목록은 유지를 골랐고 수정본이 있을 때만 보이며, 병합 결과 요약은 서버가 성공 문구 뒤에 붙여 보내므로 모달 마크업이
따로 그리지 않습니다(규칙: 코어 `docs/extension/extension-update-system.md` §10).

**본인인증 메일 문구**(7.1.0~): 별도 편집 화면 없이 「알림 설정」 탭의 분류 칩 「본인인증」에서 일반 알림과 같은 카드·
편집 모달로 다룹니다. 본인인증 탭의 「메시지」 하위 구획(`_tab_identity_messages`)은 그 자리로 가는 안내 상자만 남습니다.

**본인인증 정책 목록의 검색·필터·쪽 넘김**(7.1.0~): `_tab_identity_policies` 의 목록 조작은 모두 `navigate`(replace)에 `refetch_data_sources="policies"` 를 붙여 정책 목록 소스만 다시 받습니다. 붙이지 않으면 쿼리 갱신이 설정 본체(`settings`, initLocal)까지 다시 받아 같은 탭 위쪽에서 고친 칸이 저장된 값으로 돌아가고 「변경 있음」이 풀립니다. 새 목록 조작을 더할 때도 같은 속성을 붙이고, 레이아웃 테스트 `admin-settings-unsaved-tab-confirm.test.ts` 가 이를 잠급니다.

**에러 화면**(`errors/*.json`): `extends` 없는 독립 레이아웃. `Div`(중앙 정렬) 안에
`Icon`+`H1`(코드)+`P`(메시지)+`Button`(홈 이동). 독립 레이아웃이므로 `Toast`/`Modal` 같은
전역 호스트 컴포넌트가 필요하면 직접 마운트해야 합니다(§코어 AGENTS.md "독립 레이아웃의 글로벌
호스트 컴포넌트").

### `_admin_base.g7.html` 에 대한 정정

과거 버전 문서는 `_admin_base.g7.html` 이 `init_actions: [initTheme, initMenuFromUrl]` 를 갖는다고
적었으나, 현재 `_admin_base.g7.html` 에는 `init_actions` 키 자체가 없습니다 — 두 핸들러는 현재
`_admin_base` 를 상속하지 않는 인증 화면(`admin_login`/`admin_forgot_password`/
`admin_reset_password`)에서만 호출됩니다. 사이드바 접힘 상태 복원(`initSidebar`)은 레이아웃이
아니라 템플릿 부트스트랩(`src/index.ts`)에서 직접 호출됩니다. `_admin_base` 를 고칠 때 이
문서의 낡은 구조를 그대로 믿지 말고 실제 JSON 을 확인하세요.

### `admin_login.json` 의 2단계 인증(인증번호) 단계

보안 설정에서 2단계 인증을 켠 사이트에서는 관리자 로그인 응답도 두 형태로 갈립니다 — 정상
로그인과 인증번호 요구(challenge)입니다. `admin_login.json` 은 그 둘을 같은 카드 안에서 단계
전환으로 처리합니다. 이 화면은 `_admin_base` 를 상속하지 않는 독립 레이아웃이라 모달을 쓸 수
없고, Toast 호스트도 이 레이아웃이 직접 마운트합니다.

- 2단계(인증번호) 블록은 `if: "{{_local.twoFactor?.required}}"` 이고, 1단계(이메일·비밀번호) 블록은 그 부정형에
  비밀번호 변경 단계의 부정까지 더합니다(아래 「비밀번호 변경 단계」 절). 단계 `if` 는 언제나 상보여야 합니다. 상태는 `_global` 이 아니라 **`_local`** 입니다.
- 제출 시퀀스의 `login` 과 `loginTwoFactor` 도 같은 쌍으로 상호배타입니다. `loginTwoFactor` 쪽
  `if` 가 빠지면 인증번호 단계에서 Enter 를 눌렀을 때 새 challenge 가 발급되어 흐름이 끊깁니다.
- 인증번호 입력은 controlled 입니다(`value` + `onChange` 의 `setState` 쌍). `events: {}` 래퍼는
  쓰지 않습니다.
- 화면 진입 시 `init_actions` 의 `setState` 가 `twoFactor` 를 `null` 로 되돌립니다.
- 관리자가 아닌 계정이 인증번호를 맞춰도 서버가 403(`auth.admin_required`)으로 거부하고 이미
  발급한 토큰을 회수합니다. 그 문구는 `loginTwoFactor` 의 `onError` 가 인증번호 오류 자리에 그대로
  싣습니다 — 별도 분기를 두지 않습니다.
- 만료 시각은 정적 표기입니다(`| datetime`). 카운트다운을 쓰지 않습니다.
- **인증 수단(`provider_id`)에 따라 화면이 갈립니다.** challenge 응답의 `provider_id` 를
  `_local.twoFactor` 에 실어 두고 그 값으로 분기합니다.
  - `'totp'`(인증 앱): 서버가 **아무것도 보내지 않습니다**. 그래서 「인증번호를 보냈습니다」
    안내 대신 앱 코드 안내를 띄우고 **「인증번호 다시 받기」 버튼을 감춥니다** — 남겨 두면
    눌러도 아무 일이 없는 것처럼 보입니다. 백업 코드(`xxxxx-xxxxx`)도 같은 칸에 받으므로
    `maxLength` 를 넓히고 안내 문구를 함께 바꿉니다.
  - 그 외(메일 등): 종전 화면 그대로입니다.
  - 분기 기준은 **provider 목록을 늘려도 따라오도록** 코어가 내려주는 값을 그대로 씁니다 —
    수단이 추가될 때마다 화면에 조건을 덧대지 않습니다.

### `admin_login.json` 의 비밀번호 변경 단계 (#736, engine-v1.77.0)

비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 로그인·인증번호 확인 응답이 토큰 대신 변경 단계를 돌려줍니다
(`response.password_change_required`). 2단계 인증과 같은 짜임의 **3단계**로 처리합니다(상태 `_local.passwordChange`).

- 세 단계의 블록 `if` 와 제출 액션(`login`·`loginTwoFactor`·`loginPasswordChange`)의 `if` 는 상호배타입니다 — 1단계는
  `!twoFactor.required && !passwordChange.required`. `init` 이 `passwordChange` 를 `null` 로 둡니다.
- 성공 분기는 `response.password_change_required` 를 먼저 봅니다(이동·휴면 토스트는 그 응답이 아닐 때만).
- 노랑 안내 상자의 사유는 응답대로 — 만료(변경해야 로그인) / 만료(다음에 변경 허용 — `postpone_days` 일 뒤 다시 안내) / 관리자 지정.
  「다음에 변경」은 `can_postpone` 일 때만 그립니다.
- 422 `errors.challenge_id`(시간 초과·무효)는 단계를 비우고 1단계 오류 자리에 「처음부터 다시 로그인」 안내를 싣습니다. 규칙 위반·같은 값·
  재사용은 칸 아래, 잠금(423)·관리자 아님(403)은 단계 상단 오류에 싣습니다.
- 켜진 비밀번호 규칙은 `_global.settings.security.*` 로 한 줄 안내합니다(관리자 화면에는 `PasswordInput` 이 없다). 관리자 재설정
  화면(`admin_reset_password`)·회원 등록/수정 화면도 같은 줄을 씁니다.
- 휴면 해제 토스트는 모든 최종 성공 지점에 있습니다 — engine-v1.76.0 전에는 엔진이 `dormancy_restored` 를 버려 뜨지 않았습니다.

### 보안 탭 「비밀번호 정책」·「비밀번호 만료」 카드 (#736)

`partials/admin_settings/_tab_security_password_policy` 조각이 보안 설정 카드 뒤·접근 제한 카드 앞에 들어갑니다(최소 길이·특수문자 칸도
이 조각으로 옮겼다 — data-ref 유지). 체크박스는 자동 바인딩을 끄고 change 로 직접 담고, 조건부 칸은 느슨하게 참 판정합니다. 숫자 칸의
min/max 는 `_meta.limits.security_*` 바인딩입니다. 만료 대상 두 행(사전 안내·만료 처리)은 개인정보 탭 휴면 행과 같은 모양이고 꺼져 있어도
보입니다 — 건수(`passwordExpiryPreview`)·「대상 보기」(개인정보 명단 창 재사용, 명단 권한이 있으면 0명이어도)·「지금 실행」(전용 확인 창
`_modal_password_expiry_run`, 저장 안 한 변경·처리 중·**저장된 만료 꺼짐**이면 잠김 — 서버도 409)·마지막 실행 결과 줄
(`passwordExpiryManualRuns`, 진행 중이면 3초 폴링). 저장 성공 시 보안 탭이면 건수를 다시 받습니다.

### 보안 탭 「활동 로그(접속기록) 보관」 카드

`partials/admin_settings/_tab_security_activity_log` 조각이 접근 제한 카드 뒤·앱 키 카드 앞에 들어갑니다. 보관 기간 숫자 칸 하나
(`security.activity_log_retention_days`, min/max 는 `_meta.limits.security_activity_log_retention_days_*` — 하한 365일은 법정 최소)와
법정 안내 상자(접속기록 1·2년, 권한·역할 변경 기록은 이 값과 관계없이 3년)입니다. 저장은 보안 탭 공용 저장 버튼이 함께 보냅니다.
반응형 프리셋은 쓰지 않습니다.

### 회원 등록·수정·상세 / 역할 폼의 비밀번호 칸 (#736)

- 회원 수정에서 **본인** 계정의 새 비밀번호를 입력하면 「현재 비밀번호」 칸이 나타납니다(서버도 같은 확인·재사용 검사). **다른 회원**의
  비밀번호를 정하면(등록 포함) 「다음 로그인 때 비밀번호를 바꿔야 한다」 안내가 뜹니다.
- 상세의 「비밀번호」 구획(마지막 변경·만료 예정·다음 로그인 때 변경 필요·연기 종료·만료 개별 설정)과 같은 자리·같은 순서로 수정 화면에도
  「비밀번호」 구획이 섭니다(#599 상세↔수정 정합). 기록 칸은 잠긴 글자이고 「비밀번호 만료」(따름/적용/제외) 선택만 고칩니다.
- 역할 폼의 「비밀번호 만료」(사이트 설정 따름/적용/제외)는 저장 본문에 `password_expiry_policy` 로 실립니다.

**금지 — 같은 시퀀스·onSuccess 안에서 방금 저장한 상태를 다시 읽지 않습니다**

`setState` 직후 형제 액션의 `if` 나 값으로 그 상태를 재독하면 갱신 이전 값을 읽습니다. 그 자리에서는
`{{response.*}}` 만 씁니다. 오류도 경고도 남지 않고 분기만 조용히 어긋납니다.
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
| `*/admin/analytics` | `admin_analytics_overview` | - |
| `*/admin/analytics/pages` | `admin_analytics_pages` | - |
| `*/admin/analytics/referrers` | `admin_analytics_referrers` | - |
| `*/admin/analytics/environment` | `admin_analytics_environment` | - |
| `*/admin/analytics/regions` | `admin_analytics_regions` | - |
| `*/admin/analytics/patterns` | `admin_analytics_patterns` | - |
| `*/admin/analytics/actions` | `admin_analytics_actions` | - |
| `*/admin/analytics/realtime` | `admin_analytics_realtime` | - |
| `*/admin/analytics/visitors` | `admin_analytics_visitors` | - |
| `*/admin/analytics/visitors/:key` | `admin_analytics_visitor_detail` | - |
| `*/admin/analytics/bots` | `admin_analytics_bots` | - |
| `*/admin/analytics/diagnostics` | `admin_analytics_diagnostics` | - |
| `*/admin/analytics/privacy-requests` | `admin_analytics_privacy_requests` | - |
| `*/admin/analytics/settings` | `admin_analytics_settings` | - |
| `*/admin/member-notifications` | `admin_member_notification_list` | - |
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

## 알림 템플릿 편집 모달 — 채널이 정하는 편집기와 수신자 규칙

<!-- @intent START -->
`_modal_notification_template_form.json` 은 세 갈래로 분기합니다. 분기의 근거는 전부
**서버가 준 값**이며, 화면은 판정을 복제하지 않습니다 — 복제하면 같은 모달의 3벌(코어·게시판·
이커머스) 중 한쪽만 고쳐졌을 때 그 화면만 다르게 동작합니다.

| 축 | 읽는 값 | 분기 |
|---|---|---|
| 본문 편집기 | 채널 메타 `template_editor` | `rich` = 확장점 `html_editor`(CKEditor, 없으면 기존 입력 폴백) · `plain` = 평문 입력 · `none` = 기본 편집기를 통째로 감춤(확장이 폼 슬롯에서 대신한다) |
| 미리보기 HTML 해석 | 같은 값 | `rich` 일 때만 HTML 로 그립니다. 사이트내 알림은 알림센터가 평문으로 그리므로, 여기서만 HTML 로 해석하면 관리자가 본 문구와 사용자가 받는 문구가 다릅니다 |
| 「관련 사용자」 선택지 | 정의의 `relations` | 선언이 비면 타입 드롭다운에서 그 항목 자체를 감춥니다 — 지원하지 않는 관계를 고르면 수신자가 **0명**이 되고 예외도 로그도 남지 않습니다 |
| 역할 선택 | `recipients[].value` (서버가 언제나 배열로 정규화) | 다중 선택. 표시 이름은 `display_names` 가 **저장 순서 그대로** 나릅니다 |

`rich` 편집기는 상태를 `_global` 네임스페이스에 두므로 확장점 props 로 `writeScope: "global"`
과 활성 언어까지 포함한 `writePath` 를 넘깁니다. 노드 `id` 에 활성 언어를 넣어 언어 탭을 바꿀
때 편집기가 다시 서게 합니다 — 같은 인스턴스를 유지하면 언어를 바꾼 뒤의 입력이 **이전 언어**
로 저장됩니다.

종전 채널 메타 `hidden_template_editor: true` 는 서버가 `template_editor: 'none'` 으로
정규화하므로 화면은 한 값만 읽습니다.

수신자 카드 목록은 `stack-tight`(세로 gap)로 감쌉니다. 카드는 유틸리티 `p-3` 을 가져 `row-stack` 의 자식 패딩
규칙보다 우선하므로, `row-stack` 으로 감싸면 구분선만 생기고 카드 사이 간격은 0 이 되어 테두리가 맞붙습니다.
이 모달은 게시판·이커머스·수출입은행 플러그인에도 사본이 있어 간격도 네 벌을 함께 맞춥니다.

`row-stack` 직속 자식으로 테두리 상자(`border`·`admin-card`)를 두지 않습니다. `row-stack` 이 자식에 주는
세로 패딩(`py-2`, 첫 자식 `pt-0`, 마지막 자식 `pb-0`)이 테두리 **안쪽**에 들어가 상자 첫 행이 8px 내려앉고,
`admin-card` 의 자체 패딩은 위 8 / 아래 0 으로 덮어써집니다. 상자 하나면 테두리 없는 `<div>` 래퍼로 감싸고
(조건·반복 속성은 래퍼로), 상자 묶음이면 `stack-tight`·`card-stack`·`stack-flush`, 단순 세로 쌓기면
`flex flex-col gap-*` 를 씁니다. `<responsive on="portable" class="… row-stack"/>` 처럼 모바일에서만 class 를
통째로 바꾸는 자리는 PC 화면에서 드러나지 않으므로 특히 주의합니다(정적 검사가 이 형태를 잡습니다).
<!-- @intent END -->

## 알림 설정 — 분류 칩 · 본인인증 문구 · 메일 채널 끄기 확인

<!-- @intent START -->
`_tab_notification_definitions` 은 분류 칩(전체 · 일반 알림 · 본인인증)을 `?category=` 쿼리로 들고, 목록 데이터 소스
`notificationDefinitions` 에 그대로 넘깁니다(`extension_type="core"` — 코어 선언과 운영자가 추가한 정책별 문구가 함께
옵니다). 본인인증 카드(`category === 'identity'`)는 일반 알림과 세 가지가 다릅니다.

| 축 | 본인인증 카드 | 이유 |
|---|---|---|
| 수신자 | 수신자 규칙 영역을 감추고 「인증을 요청한 사람에게만」 고정 문구 | 인증 메일의 받는 사람은 요청자 하나뿐이라 규칙을 고르면 의미 없는 값만 쌓입니다 |
| 기본값 복원 | 운영자가 추가한 정의(`extension_identifier === 'admin'`)는 감춤 | 되돌릴 선언 기본값이 없습니다 — 대신 [삭제] 를 씁니다 |
| 삭제 | 서버가 준 `abilities.can_delete` 일 때만 | 판정(운영자 추가 · 본인인증 · 기본 아님)은 서버 몫입니다. 화면이 복제하면 둘이 갈립니다 |

[정책별 문구 추가] 는 분류가 「본인인증」 이고 서버가 `abilities.can_create` 를 줄 때만 보입니다. 정책 선택지는
`adminIdentityPolicies` 에서 이미 문구가 있는 정책(`identityPolicyDefinitions` — 분류·페이지가 안 걸린 별도 목록)을 뺀
것입니다. 목록 데이터 소스로 거르면 다른 페이지에 있는 정의가 빠져 이미 있는 정책을 또 고르게 됩니다.

탭 [저장] 은 메일 채널을 켜짐 → 꺼짐으로 바꿔 저장할 때만 `_modal_notification_channel_off_confirm` 을 띄웁니다
(7.1.0 부터 본인인증·관리자 2단계 인증 메일도 이 토글을 따릅니다). 창은 부모 화면 `_local` 을 읽지 못하므로 저장할 채널
목록을 `_global.notification_channel_off_modal.channels` 에 담아 넘기고, [취소] 는 `$parent._local.form.notifications.channels`
의 메일 항목을 켜짐으로 되돌립니다.
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

## 방문자 통계 화면의 확장 지점

방문자 통계 화면 11면은 본문 맨 아래에 확장이 조각을 붙이는 `extension-point` 를 둡니다 —
이름은 `analytics_{화면}_widgets` 입니다(`admin_analytics_{화면}` 의 `{화면}` 그대로). 대시보드
통계 카드는 `admin_dashboard_analytics` 로 감싸 있습니다. 조각의 데이터는 확장 자기 API 로 읽고,
수집 진단 화면의 조각은 진단 응답의 `extensions.{식별자}`(코어 필터 `core.analytics.diagnostics_sections`)를
읽습니다. 설정·정보주체 요청·방문자 상세 세 화면은 운영 도구라 두지 않았습니다.

| 화면 | 확장 지점 |
| --- | --- |
| 개요 | `analytics_overview_widgets` |
| 페이지 · 유입 경로 · 환경 · 지역 · 요일/시간대 | `analytics_pages_widgets` · `analytics_referrers_widgets` · `analytics_environment_widgets` · `analytics_regions_widgets` · `analytics_patterns_widgets` |
| 검색어·다운로드·전환 · 실시간 · 봇 · 방문자 · 수집 진단 | `analytics_actions_widgets` · `analytics_realtime_widgets` · `analytics_bots_widgets` · `analytics_visitors_widgets` · `analytics_diagnostics_widgets` |

「검색어·다운로드·전환」 화면의 「커스텀 차원」 탭은 `GET /api/admin/analytics/registered` 로 활성 확장이
선언한 차원을 받아 고르게 합니다(데이터 소스 `analytics_registered`, 차원 탭에서만 요청). 선언이 없으면
안내만 보이고, 편집기 미리보기는 `editor-spec/sampleData.json` 의 같은 이름 샘플을 씁니다.

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-admin_basic/layouts.md` 에 있던 원문을
> 이 문서로 옮긴 것입니다(#601). 페이지 맵 트리와 화면 유형별 패턴 상세가 여기에 있습니다.
> 이관 시점 그대로 보존하되, 현재 코드와 어긋나는 부분에는 정정 주석을 달았습니다 —
> 레이아웃·라우트의 SSoT 는 위 「레이아웃 목록」·「라우트 매핑」 블록입니다.

> **마크업 형식**: 아래 파일 트리는 소스 SSoT 인 `.g7.html`([레이아웃 마크업](../../../../docs/frontend/layout-markup.md)) 기준입니다. 설치 시 서버가 각 `.g7.html` 을 메모리로 JSON 컴파일해 DB(`template_layouts`)에 등록합니다. 산출 JSON 키 계약은 [layout-json.md](../../../../docs/frontend/layout-json.md) 가 SSoT 이며 불변입니다. 디스크에는 진단·복구용 짝 `.json` 이 병존합니다.

> **요약**(현행 디스크 실측 — 총 149개):
> 1. 베이스: `_admin_base.g7.html` (사이드바 + 헤더 + 콘텐츠 슬롯)
> 2. 코어 페이지 26개(layouts 직속, `_admin_base` 제외): 대시보드, 사용자, 역할, 설정, 확장 관리, 언어팩, 로그, 2단계 인증 등
> 3. `auth/` 1개 · `errors/` 6개
> 4. Partial 115개: 탭, 모달, 패널, 필터, 드로어 등

### 페이지 맵 (트리 구조)

```text
_admin_base.g7.html (베이스 레이아웃)
│
├── admin_dashboard.g7.html (대시보드)
├── admin_login.g7.html (로그인 — _admin_base 미상속)
│
├── 방문자 통계 (#682 S3)
│   ├── admin_analytics_overview.g7.html (개요 — KPI·추이·상위 3열·실시간 한 줄)
│   ├── admin_analytics_pages.g7.html (페이지 — 인기·진입·이탈 탭)
│   ├── admin_analytics_referrers.g7.html (유입 경로 — 유형·사이트·검색어·캠페인 탭)
│   │   └── 사이트 탭에서 도메인 행을 누르면 그 도메인의 전체 유입 주소 목록(같은 화면, `host` 질의)
│   ├── admin_analytics_environment.g7.html (환경 — 브라우저·OS·기기·해상도·언어·로케일 탭)
│   ├── admin_analytics_regions.g7.html (지역 — 국가·지역·도시 탭)
│   ├── admin_analytics_patterns.g7.html (요일·시간대 — 지표 선택은 `metric` 질의)
│   ├── admin_analytics_actions.g7.html (행동 — 검색어·내려받기·외부 링크·전환·확장 차원 탭)
│   ├── admin_analytics_realtime.g7.html (실시간 — 창 크기는 `minutes` 질의)
│   ├── admin_analytics_visitors.g7.html (방문자 목록 — 회원 칸의 행위자 메뉴 포함)
│   ├── admin_analytics_visitor_detail.g7.html (방문자 상세 — 방문 아코디언·액션 타임라인)
│   ├── admin_analytics_bots.g7.html (봇·크롤러 — 봇별 집계 + 최근 요청)
│   ├── admin_analytics_diagnostics.g7.html (수집 진단 — 실패 로그·집계 실행 이력·구간 재집계)
│   ├── admin_analytics_privacy_requests.g7.html (정보주체 요청)
│   ├── admin_analytics_settings.g7.html (설정 — 수집·개인정보·봇/제외·보존/용량·리포트 탭. 개인정보 탭의 「동의 출처」는 코어가 주는 `provider`·`filter`·`none` 값만 해석한다 — 특정 동의 관리 확장의 이름을 알지 않는다)
│   └── partials/admin_analytics/
│       ├── _partial_period_bar.g7.html (공통 기간 바 — 프리셋 8·달력·단위·제외·CSV)
│       ├── _partial_report_grid.g7.html (공통 리포트 표 — 검색·정렬·쪽·부가 열)
│       ├── _partial_status_alerts.g7.html (공통 상단 경고 — 수집 꺼짐·동의 폴백·큐 적체)
│       ├── _partial_kpi_row.g7.html (KPI 카드 4)
│       ├── _partial_realtime_bar.g7.html (실시간 한 줄)
│       ├── _partial_notification_definitions.g7.html (설정>리포트 탭의 알림 정의 상태)
│       ├── _modal_row_trend.g7.html (한 항목 추이 — 리포트 표 행 끝의 추이 아이콘이 연다)
│       ├── _modal_reaggregate.g7.html (구간 재집계)
│       ├── _modal_purge_all.g7.html (전체 삭제)
│       ├── _modal_retro_anonymize.g7.html (소급 익명화)
│       └── _modal_subject_delete.g7.html (정보주체 삭제)
│       └── 대시보드 카드는 partials/admin_dashboard/_analytics_widget.g7.html
│
├── 사용자 관리
│   ├── admin_user_list.g7.html (목록)
│   ├── admin_user_form.g7.html (생성/수정)
│   ├── admin_user_detail.g7.html (상세)
│   └── partials/admin_user_list/
│       ├── _partial_filter_section.g7.html (필터 카드 — 기본/상세 슬롯·검색편집·빠른 기간·확장 필터 자리)
│       └── _modal_bulk_roles.g7.html (선택 회원 역할 일괄 추가·제거 창)
│
├── 역할 관리
│   ├── admin_role_list.g7.html (목록)
│   │   └── partials/admin_role_list/_modal_delete.g7.html
│   └── admin_role_form.g7.html (생성/수정)
│
├── 메뉴 관리
│   └── admin_menu_list.g7.html (3패널 레이아웃)
│       └── partials/admin_menu_list/
│           ├── _panel_menu_list.g7.html (좌측: 메뉴 트리)
│           ├── _panel_form.g7.html (중앙: 편집 폼)
│           ├── _panel_detail.g7.html (중앙: 상세 보기)
│           ├── _panel_view.g7.html (우측: 미리보기)
│           ├── _panel_bulk_roles.g7.html (여러 개 선택 모드: 고른 메뉴에 역할 일괄 추가·제거)
│           ├── _modal_bulk_roles_confirm.g7.html (역할 일괄 적용 확인)
│           └── _modal_delete.g7.html
│
├── 환경 설정
│   └── admin_settings.g7.html (탭 네비게이션)
│       └── partials/admin_settings/
│           ├── _tab_general.g7.html (일반)
│           ├── _tab_mail.g7.html (메일 발송 SMTP 설정)
│           ├── _tab_notification_definitions.g7.html (알림 정의)
│           ├── _tab_security.g7.html (보안)
│           ├── _tab_security_ip_restriction.g7.html (보안 > IP 접근 제한 카드)
│           ├── _tab_security_password_policy.g7.html (보안 > 비밀번호 정책 카드 — 작성 규칙·재사용 금지·만료)
│           ├── _modal_password_expiry_run.g7.html (보안 — 비밀번호 만료 지금 실행 확인 창)
│           ├── _tab_security_activity_log.g7.html (보안 > 활동 로그(접속기록) 보관 카드)
│           ├── _tab_privacy.g7.html (개인정보 — 보유기간 파기·휴면 계정, 조치 대상은 대상마다 한 행: 건수 · 대상 보기 · 지금 실행 · 결과 줄)
│           ├── _modal_privacy_targets.g7.html (개인정보 — 조치 대상 명단 창, 무한 스크롤 · 0건이면 빈 명단 안내)
│           ├── _modal_privacy_run.g7.html (개인정보 — 직권 실행 확인 창, 건수 재조회 · 휴면 전환·탈퇴의 대기기간 생략)
│           ├── _tab_upload.g7.html (업로드)
│           ├── _tab_drivers.g7.html (드라이버)
│           ├── _tab_seo.g7.html (SEO)
│           ├── _tab_advanced.g7.html (고급)
│           ├── _tab_info.g7.html (시스템 정보)
│           ├── _modal_ip_restriction_rule_form.g7.html (IP 접근 제한 규칙 추가·수정)
│           ├── _modal_cache_delete.g7.html
│           ├── _modal_core_changelog.g7.html
│           ├── _modal_core_update_guide.g7.html
│           ├── _modal_core_update_result.g7.html
│           ├── _modal_notification_template_form.g7.html
│           ├── _modal_notification_template_preview.g7.html
│           ├── _modal_password_confirm.g7.html
│           ├── _modal_static_cache_republish.g7.html
│           ├── _modal_unsaved_tab_switch.g7.html (저장하지 않은 변경이 있는 채 다른 탭을 누르면 여는 확인 창 — 게시판·이커머스 환경설정도 같은 창)
│           ├── _tab_identity.g7.html (2단계 인증 — 하위 탭 허브)
│           ├── _tab_identity_basic.g7.html (기본 정책)
│           ├── _tab_identity_messages.g7.html (인증 메시지 — 알림 설정으로 옮겨졌다는 안내와 이동 링크)
│           ├── _tab_identity_policies.g7.html (인증 정책)
│           ├── _tab_identity_providers.g7.html (인증 수단)
│           ├── _tab_language_packs.g7.html (언어팩)
│           ├── _modal_notification_identity_definition_add.g7.html (알림 설정 > 본인인증 — 정책별 문구 추가)
│           ├── _modal_notification_identity_definition_delete.g7.html (알림 설정 > 본인인증 — 정책별 문구 삭제)
│           ├── _modal_notification_channel_off_confirm.g7.html (알림 설정 [저장] — 메일 채널 끄기 확인)
│           ├── _modal_identity_policy_delete.g7.html
│           ├── _modal_identity_policy_form.g7.html
│           └── _modal_notification_definition_reset.g7.html
│
├── 모듈 관리
│   └── admin_module_list.g7.html
│       └── partials/admin_module_list/
│           ├── _modal_detail.g7.html
│           ├── _modal_install.g7.html
│           ├── _modal_manual_install.g7.html
│           ├── _modal_uninstall.g7.html
│           ├── _modal_update.g7.html
│           ├── _modal_deactivate_warning.g7.html
│           ├── _modal_force_activate.g7.html
│           ├── _modal_force_deactivate.g7.html
│           ├── _modal_extension_license.g7.html
│           └── _modal_refresh_layouts.g7.html
│
├── 플러그인 관리
│   └── admin_plugin_list.g7.html
│       └── partials/admin_plugin_list/
│           ├── (모듈과 동일 구조 — 10개 모달)
│           └── ...
│
├── 템플릿 관리
│   ├── admin_template_list.g7.html
│   │   └── partials/admin_template_list/
│   │       ├── _tab_admin.g7.html (Admin 템플릿 탭)
│   │       ├── _tab_user.g7.html (User 템플릿 탭)
│   │       ├── _modal_detail.g7.html
│   │       ├── _modal_install.g7.html
│   │       ├── _modal_manual_install.g7.html
│   │       ├── _modal_uninstall.g7.html
│   │       ├── _modal_update.g7.html
│   │       ├── _modal_activate.g7.html
│   │       ├── _modal_deactivate.g7.html
│   │       ├── _modal_force_activate.g7.html
│   │       ├── _modal_extension_license.g7.html
│   │       ├── _modal_refresh_layouts.g7.html
│   │       ├── _modal_reactivate_language_packs.g7.html
│   │       └── _drawer_manifest_preview.g7.html
│   └── admin_template_layout_edit.g7.html (레이아웃 편집기)
│       └── partials/admin_template_layout_edit/
│           ├── _modal_version_history.g7.html
│           ├── _modal_extension_preview_layout.g7.html
│           ├── _modal_extension_version_history.g7.html
│           ├── _modal_file_delete_confirm.g7.html
│           └── _modal_file_form.g7.html
│
├── 스케줄 관리
│   └── admin_schedule_list.g7.html
│       └── partials/admin_schedule_list/
│           ├── _tab_schedules.g7.html
│           ├── _modal_form.g7.html
│           ├── _modal_delete.g7.html
│           ├── _modal_duplicate.g7.html
│           ├── _modal_history.g7.html
│           └── _modal_run.g7.html
│
├── 언어팩 관리
│   ├── admin_language_pack_list.g7.html (허브 — 아래 3화면이 이것을 extends)
│   │   └── partials/admin_language_pack_list/
│   │       ├── _content.g7.html (목록 본문 — extends 화면이 공유)
│   │       ├── _modal_install_bundled.g7.html
│   │       ├── _modal_refresh_cache.g7.html
│   │       └── _modal_slot_conflict.g7.html
│   ├── admin_language_packs.g7.html (코어 언어팩)
│   ├── admin_module_language_packs.g7.html (모듈 언어팩)
│   ├── admin_plugin_language_packs.g7.html (플러그인 언어팩)
│   └── admin_template_language_packs.g7.html (템플릿 언어팩)
│       └── partials/admin_extension_language_packs/_content.g7.html
│
├── 활동·알림 로그
│   ├── admin_activity_log_list.g7.html (활동 로그)
│   │   └── partials/admin_activity_log_list/
│   │       ├── _partial_datagrid.g7.html
│   │       └── _partial_filter.g7.html
│   ├── admin_member_notification_list.g7.html (회원 알림)
│   │   └── partials/admin_member_notification_list/
│   │       ├── _partial_datagrid.g7.html
│   │       └── _partial_filter.g7.html
│   └── admin_notification_log_list.g7.html (알림 발송 로그)
│       └── partials/admin_notification_log_list/
│           ├── _partial_datagrid.g7.html
│           ├── _partial_filter.g7.html
│           └── _modal_log_detail.g7.html
│
├── 2단계 인증 (#657)
│   ├── auth/identity_challenge.g7.html (인증 챌린지 화면)
│   ├── admin_identity_logs.g7.html (인증 로그)
│   │   └── partials/admin_identity_logs/_modal_purge_confirm.g7.html
│   ├── admin_forgot_password.g7.html (비밀번호 찾기 — _admin_base 미상속)
│   └── admin_reset_password.g7.html (비밀번호 재설정 — _admin_base 미상속)
│
├── 공통 Partial
│   ├── partials/_modal_changelog.g7.html
│   ├── partials/_modal_license.g7.html
│   ├── partials/_identity_challenge_modal.g7.html (2단계 인증 재확인 모달)
│   └── partials/_modal_notification_delete_all_confirm.g7.html
│
├── 에러 페이지
│   └── errors/
│       ├── 401.g7.html (인증 필요)
│       ├── 403.g7.html (접근 거부)
│       ├── 404.g7.html (페이지 없음)
│       ├── 500.g7.html (서버 오류)
│       ├── 503.g7.html (서비스 불가)
│       └── maintenance.g7.html (점검 중)
│
└── 테스트
    └── template_partial_test.g7.html
        └── partials/template_partial_test/
            ├── _content_section.g7.html
            ├── _header_section.g7.html
            └── _info_card.g7.html
```

---

### 카테고리별 가이드

#### 목록 페이지 패턴

**대표**: `admin_user_list.g7.html`, `admin_role_list.g7.html`

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
```html
<data-source id="users" endpoint="/api/admin/users" method="GET" :auto_fetch="true">
  <params page="{{_local.page ?? 1}}" per_page="{{_local.per_page ?? 15}}"/>
</data-source>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

**대표**: `admin_user_detail.g7.html`

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
```html
<data-source id="user" endpoint="/api/admin/users/{{route.id}}" method="GET" :auto_fetch="true"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

**대표**: `admin_user_form.g7.html`, `admin_role_form.g7.html`

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
✅ 모듈이 주입한 칸도 같은 폼 상태에 실어 저장 요청 한 번으로 보낸다 (아래)
```

**모듈이 주입한 칸의 저장**: `admin_user_form` 의 [저장]은 코어 회원 저장 요청 **한 번**입니다
(`_local.form` 전체가 본문). 이커머스의 결제 통화·배송국가처럼 모듈이 `extension_form_content` 에 주입한
칸도 `name` 바인딩으로 같은 `_local.form` 에 실리고, 그 모듈이 코어 훅으로 같은 요청 안에서 검증·저장합니다.
검증 오류는 필드 이름 키로 `_local.errors` 에 담겨 주입 칸 아래에 뜹니다. 저장 성공 뒤 모듈 전용 엔드포인트를
따로 부르지 않습니다 — 저장이 두 요청으로 갈라지면 한쪽만 실패한 채 저장 완료가 뜨는 상태가 생깁니다.

---

#### 설정 페이지 패턴

**대표**: `admin_settings.g7.html`

**구성**:
```text
extends: _admin_base
data_sources: [설정 API]
slots.content:
  └── PageHeader
  └── TabNavigation (tabs)
  └── Div (탭별 partial 조건부 렌더링)
      ├── if: activeTab === 'general' → partial: _tab_general.g7.html
      ├── if: activeTab === 'mail' → partial: _tab_mail.g7.html
      ├── if: activeTab === 'security' → partial: _tab_security.g7.html
      └── ...
```

**Partial 구조**: `partials/admin_settings/_tab_*.g7.html` (9개 탭)

**핸들러 패턴**:
- `setState` — 탭 전환
- `apiCall` — 설정 저장
- `openModal` — 확인 다이얼로그

**상태 배지 색**: 정적 파일 상태(일반 탭)·사이트맵 진행(SEO 탭) 배지처럼 값에 따라 색을 바꾸는 배지는 `<class-map key="{{…}}">` 안에
`<variant when="값">클래스</variant>` 로 적는다. 굽기 결과는 노드 최상위 `classMap` 이고 엔진은 그 자리만 읽는다 — 컴포넌트 속성으로
`classMap` 을 넘기면 색이 적용되지 않고 DOM 에 `classmap="[object Object]"` 로 샌다(#703 에서 두 배지를 이렇게 바로잡음).

**언어팩 탭의 빌려 온 창**: 언어팩 탭은 언어팩 관리 화면의 목록 조각(`admin_language_pack_list/_content`)을 그대로 쓰므로,
그 조각이 여는 창도 `admin_settings.g7.html` 이 같은 조각 파일(`partials/admin_language_pack_list/_modal_*`)을 `<modal>` 로
선언해야 동작이 이어진다(사본을 두지 않는다). 같은 로케일 자리를 두 언어팩이 다툴 때 스위치의 409 갈래가 여는
`_modal_slot_conflict` 도 여기에 포함된다 — 이 선언이 빠져 있어 환경설정에서는 충돌 때 아무 창도 뜨지 않았다(#703).
목록 조각이 여는 창을 늘리면 이 화면의 선언도 함께 늘린다.

---

#### 확장 관리 패턴

**대표**: `admin_module_list.g7.html`, `admin_plugin_list.g7.html`, `admin_template_list.g7.html`

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
  ├── _modal_detail.g7.html (상세 정보)
  ├── _modal_install.g7.html (설치 확인)
  ├── _modal_uninstall.g7.html (삭제 확인)
  ├── _modal_update.g7.html (업데이트)
  └── _modal_force_activate.g7.html 등
```

**핸들러 패턴**:
- `apiCall` — 설치, 활성화, 비활성화, 삭제, 업데이트
- `openModal` — 확인 다이얼로그
- `setState` — 선택된 확장 정보 저장

**특수사항**:
- 템플릿 관리는 Admin/User 탭 분리 (`_tab_admin.g7.html`, `_tab_user.g7.html`)
- 레이아웃 편집기 (`admin_template_layout_edit.g7.html`)는 CodeEditor + 실시간 미리보기
- 목록 격자는 `ExtensionCardGrid` 다(언어팩 목록 `admin_language_pack_list/_content` 도 같다).
- **「숨김 항목 포함」 체크는 주소(`query.include_hidden`)가 원천이다.** 목록 `params` 는
  `include_hidden="{{query.include_hidden === '1' ? 1 : 0}}"` 로 읽고, 체크 `change` 는
  `<navigate path="…" :mergeQuery="true"><query include_hidden="{{$event.target.checked ? '1' : undefined}}" :page="1"/></navigate>`
  로 주소만 바꾼다(주소가 바뀌면 목록을 다시 받으므로 따로 `refetch-data-source` 하지 않는다). 종전의
  `_local.showHidden` 은 페이지를 넘기면 풀려 체크는 켜진 채 숨김을 뺀 목록을 받았고, `set-state` 직후 재조회가
  이전 값으로 나가 한 박자 늦게 반영됐다(#703). 이 값을 화면 상태로 되돌리지 않는다.
- 템플릿 관리의 종류 탭 전환(`tab_navigation` 의 `change`)은 다른 주소(`/admin/templates/{type}`)로의 이동이라 쿼리를
  넘기지 않지만, `include_hidden` 만은 `<query include_hidden="{{query.include_hidden === '1' ? '1' : undefined}}"/>` 로
  이어 준다 — 보는 방식이지 목록 상태가 아니기 때문이다. 검색어·쪽은 탭마다 다른 목록이라 넘기지 않는다.
- 설치·제거·업데이트·레이아웃 갱신·비활성화·강제 활성화 확인 창의 성공 갈래는 **`<close-modal/>` 을 먼저 하고 그 뒤에**
  `selectedModule`·`selectedPlugin`·`selectedTemplate` 를 비운다. 순서를 거꾸로 두면 목록 재조회·확장 재로딩이 끝날 때까지
  닫히는 창 문구의 확장 이름 자리가 빈 표시로 남았다. 회귀 고정: `__tests__/layouts/admin-extension-modal-close-keeps-name.test.ts`.
- 레이아웃 편집기의 미리보기(레이아웃 파일·덮어쓰기형 확장 조각·주입형 조각 대상 고르는 창)는 서버가 템플릿 종류에 맞춰 내주는
  `response.data.preview_url`(관리자 템플릿 `/admin/preview/{token}`, 사용자 템플릿 `/preview/{token}`)을 연다. 주소를
  `/preview/{token}` 으로 고정하면 관리자 템플릿 레이아웃이 사용자 셸에서 활성 사용자 템플릿 자원으로 그려진다.
- 이 체크는 최고관리자 전용이며 `data-if="{{current_user?.data?.is_super === true}}"` 로 가린다 — 관리자 로그인
  응답 전역 이름은 `current_user` 이고 값은 `data` 아래에 있다. 존재하지 않는 `currentUser` 를 읽어 최고관리자에게도
  체크가 보이지 않던 것을 #703 에서 바로잡았다.
- 체크는 모듈·플러그인·템플릿(사용자/관리자 탭 각각) 화면 모두 **툴바 안** [보기 전환, 체크, 검색] 순서다. 툴바는
  `flex flex-wrap items-center gap-x-4 gap-y-3` 한 줄 구조이고, PC 는 보기 전환의 `mr-auto` 가 체크와 검색을 오른쪽으로
  밀어 체크가 검색칸 바로 왼쪽(16px)에 붙는다. 모바일(portable)은 보기 전환에서 `mr-auto` 를 빼 체크가 보기 전환 바로
  오른쪽에 오고 검색이 전폭으로 다음 줄에 내려간다. 검색칸 너비는 래퍼(`search_bar_box`: PC `w-64`, 모바일 `w-full`)가
  맡는다 — `SearchBar` 는 받은 class 를 안쪽 input 에만 걸어 바깥 div 가 내용 폭이 되기 때문이다. 종전처럼 툴바를
  `justify-between` 에 자식 셋으로 두면 체크가 남는 폭 정중앙에 뜨고, 모바일 class 를 `row-stack` 으로 바꾸면 보기 전환
  상자 안에 8px 이 들어가 아래가 빈다.
- 켜기/끄기 스위치는 격자의 `resyncKey="{{_global.extensionToggleResync ?? 0}}"` 계약을 따른다
  (§[components.md](components.md) `ExtensionCardGrid` 스위치 재동기). 토글 요청의 `fail` 과 409(의존 충돌 창)
  갈래에서 `_global.extensionToggleResync` 를 1 올린다 — 409 는 `fail` 을 거치지 않으므로 그 갈래에서도 목록을
  다시 받는다. 템플릿은 확인 창을 여는 시점에 올려, 창이 답을 받을 때까지 스위치를 서버 값에 둔다.
- 버튼 잠금은 서버가 422 로 거절하는 상태와 맞춘다: 코어 요구 버전 미충족(`core_compatible === false`)이면 설치와
  켜기를, `installing`·`uninstalling`·`updating` 진행 중이면 스위치·삭제·업데이트를 잠근다.
- 모듈·플러그인·템플릿을 다시 켜면 서버가 함께 꺼졌던 언어팩(`pending_language_packs`)을 한 번만 실어 주므로, 활성
  성공 갈래(모듈·플러그인은 스위치 요청, 템플릿은 `_modal_activate`·`_modal_force_activate`)가 `_global.reactivateLanguagePacksData` 에 담고 언어팩 관리 권한(`core.language_packs.manage`)이 있을
  때만 `_modal_reactivate_language_packs` 를 연다. 각 목록 레이아웃이 이 창을 `<modal>` 로 선언해 둔다.

---

#### 에러 페이지 패턴

**대표**: `errors/404.g7.html`

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

#### _admin_base.g7.html

모든 관리자 페이지의 공통 구조를 정의합니다.

```text
_admin_base.g7.html
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

활동 로그 목록은 `admin_activity_log_list.g7.html`, 본인인증 이력 목록은 `admin_identity_logs.g7.html`에서 필터와 상세 조회를 제공하며, 조각 `partials/admin_identity_logs/_partial_datagrid.g7.html`·`_partial_filter.g7.html`·`_modal_log_detail.g7.html`·`_modal_purge_confirm.g7.html`(기간 일괄 삭제 확인) 네 벌로 나뉩니다.

활동 로그 목록의 행위자 칸(`templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_activity_log_list/_partial_datagrid.g7.html`)은 `row.user.uuid` 유무로 회원 메뉴(회원 정보 보기·이 사람으로 검색)를 가릅니다. 회원이 없는 로그(시스템·CLI)도 API 가 `user` 객체(`uuid: null`, `name`: "시스템" / "시스템(CLI)")를 주므로, `user` 유무로 가르면 시스템 로그에 회원 메뉴가 그려집니다.

- [sirsoft-admin_basic 컴포넌트](components.md)
- [sirsoft-admin_basic 핸들러](handlers.md)
- [레이아웃 JSON 스키마](../../../../docs/frontend/layout-json.md)
- [레이아웃 상속](../../../../docs/frontend/layout-json-inheritance.md)
- [sirsoft-basic 레이아웃](../../sirsoft-basic/docs/layouts.md)

## 레이아웃 주석

새 이벤트 블록(`<on>`)·모달 참조(`<modal src>`)를 만들면 같은 변경에서 설명 주석을 단다 — 이벤트 블록은 `<on>` 앞이 아니라 **첫 액션 앞**에 쓴다(`<on>` 앞 주석은 컴파일 에러). 주석 주입(S21)이 완료로 선언된 뒤라 빈 자리가 생기면 `CommentAnchorStageLedgerTest` 가 red 가 된다. 주석은 산출 JSON 의 `comment` 키로만 들어가고 공개 서빙에서 제거되므로 화면 동작은 바뀌지 않는다.
