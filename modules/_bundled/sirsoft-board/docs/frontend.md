# 게시판 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 58개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 58개 |

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
| `_modal_bulk_members_confirm` | `admin` | partial | - |
| `_modal_identity_policy_delete` | `admin` | partial | - |
| `_modal_identity_policy_form` | `admin` | partial | - |
| `_modal_mail_template_edit` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_modal_privacy_run` | `admin` | partial | - |
| `_modal_privacy_targets` | `admin` | partial | - |
| `_modal_unsaved_tab_switch` | `admin` | partial | - |
| `_reaction_type_cards` | `admin` | partial | - |
| `_reaction_type_editor` | `admin` | partial | - |
| `_section_bulk_members` | `admin` | partial | - |
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

환경설정 「본인인증 정책」 탭(`_tab_identity_policies`)의 검색·필터·초기화·쪽 넘김은 `navigate`(replace)에 `refetch_data_sources="boardIdentityPolicies"` 를 붙여 목록 소스만 다시 받습니다(7.1.0~). 붙이지 않으면 설정 본체(`settings`, blocking·initLocal)까지 다시 받아 고친 설정 값과 「변경 있음」을 되돌립니다. 목록만 다시 받을 때도 목록 영역 덮개가 뜨도록 `boardIdentityPolicies` 를 `transition_overlay` 의 `wait_for` 에 둡니다. 잠금: `admin-board-settings-unsaved-tab-confirm.test.ts`. 필터 칸과 검색칸은 설정 폼 밖이라 자동 바인딩을 끄고(`:autoBinding="false"`), 입력 중인 검색어는 `_local.identityPolicySearch` 에 받아 두었다가 Enter 나 검색 단추가 쿼리로 싣습니다. 자동 바인딩되면 `form.filter.*` 에 남아 「변경 있음」을 켜고 탭 전환 확인 창을 잘못 띄웠습니다(7.1.0 G2 실측).

이 탭의 목록·켜고 끄기·추가·수정·삭제는 모듈 경로(`/api/modules/sirsoft-board/admin/identity-policies`)를 쓰고, 모듈 권한 `sirsoft-board.identity.policies.read`/`.update` 로 동작합니다(7.1.0~). 목록이 403 이면 데이터소스 `error_handling` 이 `_local.identityPoliciesLoadError='forbidden'` 을 남기고, computed `identityPoliciesNoReadPermission` 이 탭 내용 대신 「권한 없음」 안내(`identity_policies_no_permission`)를 띄웁니다. 수정 권한은 목록 응답 `abilities.can_update`·`can_delete` 로 읽어 읽기 전용 안내(`identity_policies_read_only_notice`)와 추가·토글·편집·삭제, 정책 창 [저장]·삭제 창 [삭제]를 잠급니다. 인증 제공자 선택지(`identityProviders`)는 권한이 필요 없는 공개 목록 `/api/identity/providers` 에서 받습니다. 잠금: `admin-board-identity-policy-gate.test.ts`.

반복되는 카드·박스 목록은 **간격을 스스로 갖지 않습니다.** 좁은 화면 카드(`excel-card`)는 바깥 여백이 없고
테두리 박스에 준 `p-3` 같은 안쪽 여백은 이웃과의 거리가 아니므로, 목록을 감싸는 쪽이 `stack-tight`(세로 gap)를
주거나 카드가 `mb-3` 을 집니다 — 둘 다 없으면 테두리끼리 맞붙어 한 덩어리로 보입니다(반응 유형 카드, 알림
템플릿 편집 창의 수신자 목록, SEO 탭의 제공 페이지 체크박스 목록이 그랬습니다). 알림 템플릿 편집 창은 코어·
이커머스·수출입은행 플러그인에 사본이 있어 간격도 네 벌을 함께 맞춥니다. 수신자 규칙 종류 전환도 네 벌이 같은
규칙을 씁니다 — 종류를 바꾸면 새 종류의 빈 규칙으로 갈아 끼우고(앞 종류의 값·관계는 버린다), 관련 사용자면 정의가
선언한 첫 관계를 채웁니다(코어 AGENTS.md 「알림 템플릿 편집 창 사본」). 관계 선택지와 목록 카드는 관계 키 대신 정의 응답의
`relation_labels` 이름을 보이며, 이름은 `src/lang/{ko,en}/messages.php` 의 `notification_relations` 에 둡니다(키가 없으면
`post_author` 같은 키가 그대로 보입니다).

환경설정 알림 템플릿 편집 창(`_modal_notification_template_edit`)의 수신자 규칙 중 「특정 회원」
칸은 입력할 때마다(300ms 디바운스) 코어 회원 검색 `GET /api/admin/users/search` 를 부릅니다. 이
호출에는 `if="{{String($args[0] ?? '').trim() !== ''}}"` 조건이 걸려 있어 **검색어가 비거나 공백뿐이면
부르지 않습니다** — 회원 검색은 빈 검색어를 422 로 거절하므로, 조건이 없던 때는 검색칸을 지우거나
공백만 적을 때마다 요청이 거절되고 검색 결과가 비었습니다(#703). 길이(`length > 0`)만 보면 공백이
통과하므로 `trim()` 비교를 유지합니다. 같은 창의 「역할」 칸은 역할 목록 API 가 빈 검색어에 전체를
돌려주므로 조건이 없습니다. 회원 검색 결과(`userSearchResults`)와 역할 검색 결과(`roleSearchResults`)는
`_global.board_notification_template_form_modal` 안의 서로 다른 키에 담습니다 — 한 자리를 나눠 쓰면
한쪽 검색이 다른 쪽 선택지를 갈아치웁니다.

게시판 폼 「권한」 탭(`admin_board_form/_tab_permissions`)의 운영자·단계 승인자 태그 입력은 회원 목록
`GET /api/admin/users` 에 검색어를 필터 값(`filters[0][value]`)으로 보냅니다. 이 API 는 빈 필터 값을 지우고
전체 회원을 돌려주므로, 다른 회원 검색 칸과 같이 검색어 없는 조회를 보내지 않도록 두 칸의 호출에 `if="{{String($event.target.value ?? '').trim() !== ''}}"` 가 걸려 있습니다 — 태그
입력은 검색칸을 지울 때 빈 문자열로 `onInputChange` 를 부르기 때문입니다(#703). 두 칸의 결과는
`managerSearchResults`·`stepSearchResults` 로 나눠 담습니다.

신고·반응·스크랩 관리 목록의 빠른 기간 버튼은 날짜 식에서 「오늘」을 **계정 시간대**(`current_user?.data?.timezone`
→ 없으면 `_global.settings?.general?.timezone`)로 만들고, 그 달력 날짜에서 UTC 셈으로 날·달을 빼 브라우저 시간대가
끼지 않게 합니다 — 서버가 날짜 칸을 보는 사람의 시간대 하루로 자르기 때문입니다.

게시판 유형 관리 창(`admin_board_form/_board_type_manage_modal`)과 반응 유형 추가 폼의 이름 칸은 **사이트 기본 언어 값이
필수**입니다 — 서버 규칙(`LocaleRequiredTranslatable`)이 사이트 기본 언어(`app.site_locale`) 값을 필수로 보기 때문입니다(화면 언어와
무관). 단추 잠금과 추가 확인은 `_global.appConfig?.siteLocale ?? $locale ?? 'ko'` 칸으로 판단합니다. 서버는 이름 오류를
필드 전체 키 `name` 이나 언어 키 `name.{언어}` 로 돌려주므로 칸의 `error` 는 `name.{사이트 기본 언어}` 를 먼저, `name` 을 다음으로
읽습니다. 문구는 MultilingualInput 이 칸 아래에 직접 그리므로 같은 문구를 담는 별도 문단을 두지 않습니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `downloadAttachment` | `sirsoft-board.downloadAttachment` |
<!-- @generated:handlers END -->

<!-- @intent START -->
하나뿐입니다 — 관리자 게시글 상세의 게시글·답변 첨부 카드가 부르는 `sirsoft-board.downloadAttachment`.
나머지 관리자 화면은 코어 빌트인 핸들러(`apiCall`/`navigate`/`setState` 등)만으로 구성됩니다 —
게시판 CRUD·신고 처리·설정 저장은 결국 REST 호출 + 표준 폼 상태 관리라 전용 핸들러가 필요 없었습니다.

이 핸들러를 모듈이 직접 두는 이유는 둘입니다. 첨부 내려받기 주소는 비회원도 열 수 있는 라우트라
`<a href>` 로 열면 로그인 토큰이 실리지 않아 요청이 비회원으로 처리되므로(활동 이력의 행위자가 비고
내려받기 권한도 비회원 기준), 코어 ApiClient 로 blob 을 받아야 합니다. 그런데 코어에는 내려받기
핸들러가 없고, 같은 이름의 핸들러를 관리자 템플릿(`sirsoft-admin_basic`)이 등록해 두었지만 이 화면은
**어느 관리자 템플릿으로 그려지든** 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않습니다.
핸들러는 실패하면 예외를 던지고, 안내 문구는 레이아웃의 `onError` 가 띄웁니다.

새 관리자 화면을 추가할 때도 먼저 빌트인 핸들러 조합으로 가능한지 확인하고, 부족할 때만
`resources/js/handlers/` 에 더합니다. 핸들러를 더하거나 고치면 셋이 함께 움직입니다 —
`resources/js/handlers/index.ts` 등록, `window.__SirsoftBoard.initModule()` 재등록 진입점,
`--production` 으로 구운 `dist/` 커밋.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftBoard` |
| 재등록 진입점 | `initModule()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 가 핸들러를 `sirsoft-board.{이름}` 으로 등록하고 `window.__SirsoftBoard` 에
`initModule()` 을 노출합니다.

이 진입점은 **필수**입니다. 코어는 로케일 전환 시 확장의 재등록 진입점을 다시 부르는데, 그 함수가
없거나 이름이 다르면 전환 직후 그 확장의 액션이 전부 무반응이 되고 오류도 토스트도 남지 않습니다.
진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/module.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/module.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
JS 번들 `dist/js/module.iife.js` 는 액션 핸들러만 담은 경량 IIFE 입니다(컴포넌트 없음).
관리자 화면에만 있는 호버·다크 짝·반투명 배경은 `resources/css/main.css` 에 ``board-`` 접두 클래스로 두고,
JS 엔트리가 불러와 빌드가 `dist/css/module.css` 로 냅니다(module.json `assets.css`). 관리자 템플릿은 자기
레이아웃만 훑어 Tailwind 규칙을 만들므로, 이 모듈 레이아웃에만 있는 유틸리티(`dark:bg-gray-700/30` 같은 것)는
규칙이 생성되지 않아 조용히 빠집니다. 새 레이아웃에 템플릿 CSS 에 없는 유틸리티가 필요하면 여기에 접두 클래스로
정의합니다 — 이 파일은 템플릿의 `@layer utilities` 밖에 실려 같은 속성의 유틸리티보다 앞서므로 다크·호버 짝을
함께 둡니다(규약: 코어 문서 `docs/extension/module-assets.md` 「확장 화면의 의미 색은 확장 CSS 가 싣는다」).

소스는 `resources/js/` 이고 `--production` 빌드(소스맵을 남기지 않는 배포용)로 굽습니다 — 이 모듈
디렉토리에서 `npm run build` 를 `G7_BUILD_SOURCEMAP=0` 으로 실행하거나, 설치본 manifest 에 에셋이
반영된 뒤에는 `php artisan module:build sirsoft-board --production` 을 씁니다. 굽고 나면 `dist/` 를
함께 커밋합니다(`dist/css/` 포함).

`editor-spec.json` 은 레이아웃 편집기가 게시판 관리 화면의 컴포넌트를 인식하기 위한 선언이며
실행 코드가 아니라 manifest 입니다. 로딩 설정(`strategy: global`, `priority: 100`)은 이 번들을 모든
화면에서 앱 초기화 때 싣습니다. `dependencies: []` 는 다른 확장의 전역·핸들러에 기대지 않는다는
뜻이라 순서에 민감하지 않습니다.
<!-- @intent END -->
