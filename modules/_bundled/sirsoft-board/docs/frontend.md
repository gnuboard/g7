# 게시판 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 55개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 55개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `admin_board_form` | `admin` | 화면 | `_admin_base` |
| `admin_board_index` | `admin` | 화면 | `_admin_base` |
| `admin_board_post_detail` | `admin` | 화면 | `_admin_base` |
| `admin_board_post_form` | `admin` | 화면 | `_admin_base` |
| `admin_board_posts_index` | `admin` | 화면 | `_admin_base` |
| `admin_board_reactions_index` | `admin` | 화면 | `_admin_base` |
| `admin_board_reports_detail` | `admin` | 화면 | `_admin_base` |
| `admin_board_reports_index` | `admin` | 화면 | `_admin_base` |
| `admin_board_scraps_index` | `admin` | 화면 | `_admin_base` |
| `admin_board_settings` | `admin` | 화면 | `_admin_base` |
| `_board_type_manage_modal` | `admin` | partial | - |
| `_tab_basic` | `admin` | partial | - |
| `_tab_list` | `admin` | partial | - |
| `_tab_notification` | `admin` | partial | - |
| `_tab_permissions` | `admin` | partial | - |
| `_tab_post` | `admin` | partial | - |
| `_comment` | `admin` | partial | - |
| `_comments` | `admin` | partial | - |
| `_post_card_content` | `admin` | partial | - |
| `_reply_card_content` | `admin` | partial | - |
| `_attachments` | `admin` | partial | - |
| `_form_fields` | `admin` | partial | - |
| `_parent_post` | `admin` | partial | - |
| `_partial_filter` | `admin` | partial | - |
| `_alert_status` | `admin` | partial | - |
| `_card_history` | `admin` | partial | - |
| `_card_report_info` | `admin` | partial | - |
| `_partial_filter` | `admin` | partial | - |
| `_bulk_apply_modal` | `admin` | partial | - |
| `_modal_identity_policy_delete` | `admin` | partial | - |
| `_modal_identity_policy_form` | `admin` | partial | - |
| `_modal_mail_template_edit` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_modal_privacy_run` | `admin` | partial | - |
| `_modal_privacy_targets` | `admin` | partial | - |
| `_reaction_type_cards` | `admin` | partial | - |
| `_reaction_type_editor` | `admin` | partial | - |
| `_tab_board_settings_attachment` | `admin` | partial | - |
| `_tab_board_settings_basic` | `admin` | partial | - |
| `_tab_board_settings_bulk_apply` | `admin` | partial | - |
| `_tab_board_settings_comment` | `admin` | partial | - |
| `_tab_board_settings_list` | `admin` | partial | - |
| `_tab_board_settings_notification` | `admin` | partial | - |
| `_tab_board_settings_permissions` | `admin` | partial | - |
| `_tab_board_settings_post` | `admin` | partial | - |
| `_tab_board_settings_reply` | `admin` | partial | - |
| `_tab_general` | `admin` | partial | - |
| `_tab_identity_policies` | `admin` | partial | - |
| `_tab_notification_definitions` | `admin` | partial | - |
| `_tab_privacy_retention` | `admin` | partial | - |
| `_tab_report_policy` | `admin` | partial | - |
| `_tab_seo` | `admin` | partial | - |
| `_tab_spam_security` | `admin` | partial | - |
<!-- @generated:layouts END -->

<!-- @intent START -->
56개 레이아웃이 **전부** `admin` 그룹입니다 — 이것은 이 모듈이 방문자 화면을 그리지 않는다는
증거입니다(§1, §architecture.md 참고). 새로 방문자용 게시판 화면(예: 다른 스타일의 목록)이
필요하면 이 디렉토리가 아니라 그 화면을 쓸 템플릿의 `layouts/` 에 추가합니다. `_tab_*` partial
이 21개로 가장 많은 것은 이 모듈에 탭 구조를 쓰는 화면이 최소 세 곳이기 때문입니다 —
게시판 생성/수정 폼(`_tab_basic`/`list`/`post`/`permissions`/`notification`), 게시판별
개별 설정 화면(`_tab_board_settings_*` 9개 — attachment/basic/bulk_apply/comment/list/
notification/permissions/post/reply), 모듈 전역 환경설정 화면(`_tab_general`/
`identity_policies`/`notification_definitions`/`privacy_retention`/`report_policy`/`seo`/`spam_security`).
세 화면의 탭 이름이 겹치더라도(`_tab_basic`, `_tab_board_settings_basic` 등) 서로 다른
partial 파일이므로 한쪽만 고치면 다른 화면은 그대로입니다 — 같은 항목을 여러 화면에
반영해야 한다면 파일을 전부 찾아 고쳐야 합니다.
빈 값도 **모양**이 계약입니다. 이름표로 값을 꺼내는 자리(`_local.expandedNotifications?.[알림키]` 처럼)는 비어도 map(`{}`)이고,
순서가 있는 목록은 빈 배열(`[]`)입니다. 마크업에서는 `:value="{}"` 와 `:value="[]"` 로 갈리며,
화면이 읽기만 할 때는 둘 다 똑같이 비어 보이지만 그 값이 서버로 실리거나 레이아웃을 `.json`
원문으로 설치하면 서버가 대상을 찾지 못해 오류도 로그도 없이 적용되지 않습니다. 이 모듈에서는 환경설정의 `initLocal.expandedNotifications` 와 본인인증 정책 폼의 `conditions`, 알림 정의 데이터소스의 `fallback.data.meta` 가 그 자리입니다.

반복되는 카드·박스 목록은 **간격을 스스로 갖지 않습니다.** 좁은 화면 카드(`excel-card`)는 바깥 여백이 없고
테두리 박스에 준 `p-3` 같은 안쪽 여백은 이웃과의 거리가 아니므로, 목록을 감싸는 쪽이 `stack-tight`(세로 gap)를
주거나 카드가 `mb-3` 을 집니다 — 둘 다 없으면 테두리끼리 맞붙어 한 덩어리로 보입니다(반응 유형 카드, 알림
템플릿 편집 창의 수신자 목록, SEO 탭의 제공 페이지 체크박스 목록이 그랬습니다). 알림 템플릿 편집 창은 코어·
이커머스·수출입은행 플러그인에 사본이 있어 간격도 네 벌을 함께 맞춥니다. 수신자 규칙 종류 전환도 네 벌이 같은
규칙을 씁니다 — 종류를 바꾸면 새 종류의 빈 규칙으로 갈아 끼우고(앞 종류의 값·관계는 버린다), 관련 사용자면 정의가
선언한 첫 관계를 채웁니다(코어 AGENTS.md 「알림 템플릿 편집 창 사본」).
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
이 모듈의 관리자 레이아웃은 코어 빌트인 핸들러(`apiCall`/`navigate`/`setState` 등)만으로
전부 구성됩니다 — 게시판 CRUD·신고 처리·설정 저장은 결국 REST 호출 + 표준 폼 상태 관리라
전용 핸들러를 등록할 필요가 없었습니다. 새 관리자 화면을 추가할 때도 먼저 빌트인 핸들러
조합으로 가능한지 확인하고, 그래도 부족할 때만(예: 파일 업로드 진행률 같은 복잡한 클라이언트
상태) `resources/js/` 에 전용 핸들러를 신설합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
액션 핸들러가 없는 것과 같은 이유로 `window.__[Name]` 재등록 진입점도 없습니다 — 로케일
전환 후 재등록해야 할 자체 핸들러가 이 모듈에는 없기 때문입니다. 프론트 전용 코드
(`resources/js/`)를 신설하면 그 순간부터 이 자리에 진입점을 만들어야 합니다(§코어 AGENTS.md
"확장 미들웨어는..." 항목 인근의 재등록 진입점 규정 참고) — 없으면 로케일 전환 후 그 확장의
액션이 전부 무반응이 됩니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
`editor-spec.json` 이 이 모듈의 유일한 프론트 자산인 것도 위와 같은 이유입니다 — 레이아웃
편집기가 게시판 관리 화면의 컴포넌트를 인식하려면 이 선언이 필요하지만, 실행 시점에 로드할
JS/CSS 번들은 없습니다. `priority: 100` 은 다른 확장의 에셋 우선순위와 충돌하지 않는 기본값이며,
`dependencies: []` 는 이 확장의 에디터 스펙이 다른 확장의 스펙 로드를 전제하지 않는다는 뜻입니다.
<!-- @intent END -->
