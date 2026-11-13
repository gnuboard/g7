# 관리자 템플릿 교체 계약

> **목적**: 관리자 템플릿(`type: admin`)을 새로 만들거나 교체할 때, 번들 모듈·플러그인의 관리자 화면이 그 템플릿에 기대는 것(컴포넌트·공용 문구 키·조각이 붙는 자리)과, 템플릿이 선택해서 줄 수 있는 확장 CSS 덮기를 한곳에 정리한다.

---

## TL;DR (5초 요약)

```text
1. 확장의 관리자 화면은 템플릿 레이아웃이 아니라 «템플릿이 주는 부품» 위에 그려진다 — 부품이 없으면 그 부분만 조용히 빠진다
2. 필수 계약 3종: 컴포넌트(68종) · 공용 문구 키(common.*·admin.* 137개) · 조각 앵커(확장점 16 + 앵커 id 9). 선택: 확장 CSS 덮기
3. 기준 구현은 sirsoft-admin_basic — 이 표의 모든 항목을 갖춘다
4. 학습용 gnuboard7-hello_admin_template 은 이 계약을 채우지 않는다(알려진 제한) — 번들 확장 관리자 화면 운영용이 아니다
5. 계약 밖에서 확장이 템플릿에 기대는 것을 새로 만들면 그 확장의 문서와 이 표를 함께 고친다
6. 스타일 클래스는 계약이 아니다 — 확장 화면 스타일은 확장이 자기 CSS 로 싣는다(7.2.0+)
7. 회원 폼, 상세, 목록은 회원 항목 API 가 준 계약대로 그린다. 확장이 선언한 회원 항목은 조각 없이 「확장 항목」 구획에 나온다
```

---

## 목차

1. [왜 계약이 필요한가](#왜-계약이-필요한가)
2. [필수 계약 표](#필수-계약-표)
3. [알려진 제한: 학습용 관리자 템플릿](#알려진-제한-학습용-관리자-템플릿)
4. [새 관리자 템플릿 점검 절차](#새-관리자-템플릿-점검-절차)

---

## 왜 계약이 필요한가

모듈·플러그인의 관리자 화면(`resources/layouts/admin/**`)과 조각(`resources/extensions/**`)은 활성 관리자 템플릿에 등록되어
그 템플릿의 컴포넌트로 그려진다. 템플릿에 없는 컴포넌트 이름은 렌더되지 않고, 없는 문구 키는 키 문자열이 그대로 보이며,
없는 조각 자리에는 조각이 붙지 않는다. 어느 경우도 오류로 막히지 않으므로, 템플릿을 교체한 운영자는
「일부 화면이 이상하다」로만 겪는다. 아래 표는 2026-10-09 기준 번들 확장 22종의 관리자 화면·조각 393개를 실측한 결과다.

## 필수 계약 표

| 계약 | 규모 | 기준 위치(sirsoft-admin_basic) | 빠지면 |
| --- | --- | --- | --- |
| 컴포넌트 | 68종 | `components.json`, `src/components/**` | 그 노드가 그려지지 않는다(자식까지 함께 사라짐) |
| 공용 문구 키 | `common.*`·`admin.*` 137개 | `lang/{ko,en}.json` → `lang/partial/{ko,en}/{common,admin}.json` (+ 언어팩 `frontend/`) | 버튼·제목에 키 문자열이 그대로 보인다 |
| 조각 앵커 | 확장점 16 · 앵커 id 9 | `layouts/**` | 그 자리에 붙는 확장 조각이 등록 단계에서 빠진다 |
| 회원 항목 그리기 | 폼, 상세, 목록, 환경설정 회원 탭 | `layouts/admin_user_*`, `layouts/partials/member/**` | 운영자가 추가한 항목과 확장이 선언한 항목이 관리자 화면에 나오지 않는다 |
| 확장 CSS 덮기 **(선택)** | 확장별 CSS 파일 | `template.json` 의 `extension_css_overrides` | 빠져도 화면은 무너지지 않는다 — 확장 화면이 확장 원래 모양으로 그려진다 |

**스타일 클래스는 계약에서 빠졌다(7.2.0).** 종전에는 확장 레이아웃이 템플릿의 `@layer components` 의미 클래스(`admin-card`·`btn`·`input` 등)로 배치를 정해, 새 관리자 템플릿이 그 클래스를 모두 정의해야 했다. 이제 확장 화면의 스타일은 확장이 자기 CSS 에 자기 앞글자 클래스로 싣는다 — 템플릿이 어떤 CSS 라이브러리를 쓰든, CSS 를 거의 두지 않든 확장 화면 모양은 같다. 규약: [module-assets.md 「확장 CSS 자기 공급 규약」](module-assets.md#확장-css-자기-공급-규약).

### 컴포넌트 (68종)

확장 관리자 화면이 쓰는 컴포넌트 이름이다. 같은 이름·같은 주요 props 로 제공해야 한다.

- 기본(HTML 래핑): `Div` `Span` `Button` `Input` `Textarea` `Select` `Option` `Label` `Form` `Checkbox` `Img` `Icon` `H1` `H2` `H3` `H4` `Hr` `Pre` `Ul` `Ol` `Li` `Table` `Thead` `Tbody` `Tr` `Th` `Td` `Section` `Fragment` `Grid`
- 조합: `Accordion` `ActionMenu` `Alert` `Badge` `BarChart` `BrandMark` `CardGrid` `CategoryTree` `ChipCheckbox` `ColumnSelector` `DataGrid` `Dropdown` `DynamicFieldList` `EmptyState` `ExtensionBadge` `FileUploader` `FilterVisibilitySelector` `FormField` `HtmlContent` `HtmlEditor` `LoadingSpinner` `Modal` `MultilingualInput` `MultilingualTabPanel` `MultilingualTagInput` `PageHeader` `Pagination` `RadioGroup` `SearchableDropdown` `SearchBar` `SectionLayout` `SlotContainer` `SortableMenuList` `StatusBadge` `TabNavigation` `TabNavigationScroll` `TagInput` `Toggle`

컴포넌트별 props 는 [sirsoft-admin_basic 컴포넌트 문서](../../templates/_bundled/sirsoft-admin_basic/docs/components.md)를 기준으로 맞춘다.

### 공용 문구 키 (137개)

확장 화면은 자주 쓰는 문구를 템플릿의 공용 키로 가져다 쓴다. 키 묶음과 쓰는 확장 수는 아래와 같다.

- `common.*` — 버튼·상태(`save`·`saving`·`cancel`·`close`·`confirm`·`delete`·`deleting`·`edit`·`refresh`·`reset`·`back`·`add`·`remove`), 목록·페이지 이동(`first_page`·`prev_page`·`next_page`·`last_page`·`no_data`·`no_results`·`list`), 진행 상태(`loading`·`processing`·`sending`·`downloading`), 오류(`error`·`error_occurred`·`validation_error`), 열 선택(`column_selector*`), 범위(`range_start`·`range_end`), 펼침(`expand`·`collapse`), 값 보기(`show_value`·`hide_value`), `actions`·`more_actions`·`required`·`search`·`select_placeholder`·`enter_value`·`all`·`prev`·`next`
- `admin.*` — `admin.plugins.*`(플러그인 설정 화면 공통 문구, 13개 확장), `admin.settings.*`, `admin.dashboard.*`, `admin.identity.*`, `admin.users.*`

정확한 키 목록은 `templates/_bundled/sirsoft-admin_basic/lang/partial/ko/common.json`·`admin.json` 이다. 새 템플릿은 이 두 묶음을 같은 키 구조로
제공해야 하며, 일본어 등 추가 언어는 그 템플릿의 언어팩이 같은 키를 채운다.

### 조각 앵커

확장 조각은 템플릿 레이아웃의 확장점(`extension_point`)이나 노드 id(`target_id`)에 붙는다. 템플릿이 이 자리를 두지 않으면
조각 등록이 그 자리를 건너뛴다.

| 종류 | 이름 | 놓이는 화면 |
| --- | --- | --- |
| 확장점 | `admin_dashboard_commerce`, `admin_dashboard_community`, `admin_dashboard_quick_menu` | 대시보드 |
| 확장점 | `admin_user_list_filters` | 회원 목록 필터 |
| 확장점 | `identity_provider_ui:{provider}` | 본인인증 설정 |
| 확장점 | `notification_definition_row_footer`, `notification_template_form_sections`, `notification_template_form_footer_actions` | 알림 설정·알림 템플릿 편집 창 |
| 확장점 | `html_editor` | 본문 편집기 자리(편집기 플러그인이 채움) |
| 확장점 | `address_search_slot` | 주소 검색 자리(관리자·사용자 양쪽) |
| 확장점 | `admin_login_extensions` (7.2.0+) | 관리자 로그인 폼 바로 뒤(SSO 단추). 7.1.x 화면으로 만든 템플릿은 코어 [호환 심](layout-extensions.md#옛-템플릿-호환-심-720)이 채운다 |
| 확장점 | `member_field_input` | 회원 항목 조각(`partials/member/_member_fields`)에서 확장이 더한 유형의 입력칸 자리 |
| 확장점 | `member_field_type_options` | 환경설정 회원 탭의 항목 추가, 수정 창에서 확장이 더한 유형의 옵션 자리 |
| 앵커 id | `header_currency_inject_anchor` | `_admin_base` 머리글 |
| 앵커 id | `users_data_grid`, `column_selector` | 회원 목록의 표와 열 선택기(소셜 로그인, SSO 가 열을 더한다) |
| 앵커 id | `user_detail_tabs`, `extension_tab_content` | 회원 상세 |
| 앵커 id | `user_form_tabs`, `extension_form_content` | 회원 등록·수정 |
| 앵커 id | `notif_channel_content` | 환경설정 알림 채널 탭 |
| 앵커 id | `notification_log_datagrid` | 알림 발송 이력 |

모듈이 자기 화면 안에 둔 확장점(예: 이커머스 주문 목록의 결제수단 배지 자리, 게시판 설정의 문의 게시판 자리)은 그 모듈이
제공하므로 템플릿의 계약이 아니다. 확장점 전체 목록은 [layout-extensions.md](layout-extensions.md) 를 본다.

### 회원 항목 그리기

회원 한 사람에게 딸린 값(이름, 연락처, 운영자가 추가한 항목, 확장이 선언한 항목)은 관리자 템플릿이 칸을 직접 적지 않고 서버가 준 항목 계약을 돌며 그린다. 확장은 회원 폼에 조각을 붙이지 않고 항목을 선언한다([field-sets.md](field-sets.md)). 번들 확장이 회원 폼에 붙이던 조각(게시판 알림 수신, 이커머스 결제 통화와 배송 국가, 마케팅 동의)은 없어졌고, 그 값은 아래 「확장 항목」 구획에 나온다. 사용자 템플릿 쪽 짝 계약은 [user-template-contract.md](user-template-contract.md) 다.

**회원 폼과 상세**

| 화면 | 데이터소스 id | API |
| --- | --- | --- |
| 회원 추가(`admin_user_form`) | `memberFields` | `GET /api/admin/users/form-fields` |
| 회원 수정(`admin_user_form`) | `memberFields` | `GET /api/admin/users/{user}/form-fields` |
| 회원 상세(`admin_user_detail`) | `memberFields` | `GET /api/admin/users/{user}/form-fields` |

응답의 `data.fields` 가 항목 계약 배열이고, `data.member` 는 그 회원의 항목 상태(닉네임 변경 시각, 비어 있는 필수 항목, 표시 이름과 고른 종류)다. 응답 필드는 [회원 API](../backend/api/users.md) 에 있다.

- 조각은 둘이다. 입력은 `layouts/partials/member/_member_fields`, 보기는 `_member_fields_view`. 부르는 화면이 `data-each="memberFieldGroup in …"` 안에서 include 하고, 반복 변수 `memberFieldGroup` 은 `{ key, fields, className, wideClassName }` 이다(`wideClassName` 은 768px 이상에서 쓸 클래스).
- 묶음과 무관한 문맥은 화면의 computed `memberFieldCtx`(`formKey`, `errors`, `disabled`)가 준다. 회원 폼의 폼 상태 이름은 `form` 이다. 조각은 계산값을 `_computed` 접두로 읽는다.
- 화면의 computed `memberFieldGroups` 가 항목을 여섯 묶음으로 나눈다. `basic`, `contact`, `location`, `profile` 은 항목의 `ui.group`, `extra` 는 묶음이 없는 운영자 추가 항목, `extension` 은 `owner_type` 이 `module` 또는 `plugin` 인 항목이다.
- 구획 노드는 `section_basic_info`, `section_contact_info`, `section_location_info`, `section_profile_memo`, `section_extra_fields`, `section_extension_fields` 다. 「확장 항목」 구획(`section_extension_fields`, 탭 id `extension_fields`)은 확장이 선언한 항목이 하나라도 있을 때만 그려진다. 동의 항목(`ui.group` 이 `agreements`)도 이 구획에서 다른 항목과 같이 그린다.

조각이 만드는 노드 id:

| id | 무엇 |
| --- | --- |
| `member_fields_group_{묶음 key}`, `member_fields_view_group_{묶음 key}` | 묶음을 감싼 노드(입력, 보기) |
| `field_{항목 키}` | 항목 한 줄(입력, 보기 공통) |
| `mf_source_{항목 키}` | 라벨 앞의 확장 이름 꼬리표. 계약의 `source_label` 이 있을 때만(확장이 선언한 항목) |
| `mf_scope_{항목 키}` | 수정 범위 배지(`edit_scope` 가 `self`, `admin_edit`, `admin_only`). 코어 항목의 `self` 는 달지 않는다 |
| `mf_error_{항목 키}` | 이 칸의 검증 오류 줄. 오류 키가 항목의 `name`, 그 하위 경로, `parts` 의 값이면 이 항목의 오류다 |
| `mf_deferred_{항목 키}` | 회원 추가 화면의 파일 항목 안내 줄(저장한 뒤에 올린다) |
| `mf_files_{항목 키}`, `mf_file_add_{항목 키}` | 파일 갈래. `POST`, `DELETE /api/admin/users/{user}/fields/{키}/file` 로 바로 처리하고 `memberFields` 를 다시 받는다 |
| `mf_locked_sso_{키}`, `mf_locked_identity_{키}`, `mf_locked_other_{키}` | 잠긴 항목(`readonly`)의 사유 안내 |
| `mf_view_value_{항목 키}`, `mf_view_files_{항목 키}` | 보기 조각의 값(`display_value`)과 파일 목록 |
| `member_field_input_slot_{항목 키}` | 확장점 `member_field_input`. `renderer` 가 `extension` 인 항목 자리이고 `field`, `error`, `disabled`, `formKey` 를 넘긴다 |

권한이 없어 고칠 수 없는 확장 항목은 계약이 `readonly: true` 와 `locked_reason` 으로 온다(예: 이커머스 결제 통화). 템플릿은 사유를 가리지 않고 읽기 전용으로 그리면 된다. 검증 오류가 난 구획의 탭에 표시를 다는 계산(`memberFieldErrorTabs`)과 어느 칸에도 속하지 않는 오류를 모으는 계산(`memberFieldOrphanErrors`)도 같은 오류 규칙을 쓴다.

**회원 목록**

- 운영자가 「회원 목록 열」로 켠 항목은 목록 응답의 `member_fields.columns`(`field`, `header`)로 온다. 화면의 computed `memberFieldColumns` 하나를 표(`users_data_grid`)와 열 선택기(`column_selector`) 양쪽의 `dynamicColumns` 로 넘겨 닉네임 열 뒤에 넣는다. 두 곳이 같은 값을 읽어야 열 집합과 순서가 갈리지 않는다.
- 「회원 검색」으로 켠 항목은 `member_fields.search_fields` 로 오고, 검색 대상 선택지의 고정 항목 뒤에 붙인다. 선택지의 값은 `custom:{항목 키}` 다.
- 고정 열 `display_name`(표시 이름), `pending_required_fields`(비어 있는 필수 항목), 필터 `required_missing`, `nickname_duplicate` 도 회원 항목 계약의 일부다.

**환경설정 회원 탭**

회원 탭(`partials/admin_settings/_tab_member`)은 세 구획을 차례로 싣는다.

| 구획 | 조각 | 데이터소스 |
| --- | --- | --- |
| 항목 관리 | `_tab_member_fields`(좁은 화면 카드 `_tab_member_fields_cards`), 항목 추가와 수정 창 `_modal_member_field_form` | `memberFieldDefs`(`GET /api/admin/field-sets/core.user/fields`). 응답의 `types` 가 유형 선택 목록이다 |
| 표시 이름 | `_tab_member_display_name` | `memberDisplayNameOptions`(`GET /api/admin/member-fields/display-name-options`), `memberNicknameEmptyCount`(`GET /api/admin/member-fields/nickname-empty-count`) |
| 닉네임 정책 | `_tab_member_nickname` | `memberNicknameRecompute`(`GET /api/admin/member-fields/nickname-recompute-status`) |

- 항목 표의 변경은 즉시 저장된다. 표시 이름과 닉네임 정책은 설정 폼(`_local.form.member.*`)에 담아 화면 하단 「저장」이 한 번에 보낸다. 표시 이름의 저장 키와 규칙은 [display-name.md](display-name.md), 항목 API 는 [항목 묶음 API](../backend/api/field-sets.md) 에 있다.
- 항목 추가, 수정 창은 고른 유형이 코어 유형이 아니면 확장점 `member_field_type_options` 를 연다. 속성 `field`(창이 편집 중인 항목 값), `disabled` 를 넘기고, 조각은 옵션 값을 `_global.member_field_form_modal.form.constraints.*` 에 담는다.

### 확장 CSS 덮기 (선택)

관리자 템플릿이 확장 관리자 화면을 자기 모양(색·테두리·글자)에 맞추고 싶으면 확장 CSS 를 고치지 않고 확장별 **덮기 파일**을 선언한다.

```json
"extension_css_overrides": [
    { "target": "sirsoft-board", "targetVersion": "^1.3", "path": "dist/css/overrides/sirsoft-board.css" }
]
```

- 대상 확장이 활성이고 버전이 맞을 때만 실리며, 맞지 않으면 빠져 확장 원래 모양으로 돌아온다.
- 확장 CSS 뒤에 실려 확장의 의미 규칙(`@layer g7-extension`)과 배치 규칙을 덮는다. 대상 확장의 클래스만 쓴다.
- 다크 모드가 있는 템플릿은 `features.color_scheme` 도 선언한다. 선언하지 않으면 확장 화면은 늘 라이트로 그려진다.
- 형식·판정·로드 순서: [module-assets.md 「템플릿 확장 CSS 덮기」](module-assets.md#템플릿-확장-css-덮기), 선언 검증: [template-basics.md](template-basics.md#확장-css-덮기-파일-extension_css_overrides)

## 알려진 제한: 학습용 관리자 템플릿

`gnuboard7-hello_admin_template` 은 관리자 템플릿의 **최소 구성을 보여주는 학습용 샘플**이다([sample-extensions.md](sample-extensions.md)).
기본 컴포넌트 8종과 문구 키 19개만 있고 위 계약을 채우지 않으므로, 이 템플릿을 활성화하면 번들 모듈·플러그인의
관리자 화면은 대부분 그려지지 않거나 키 문자열로 보인다(실측: 위 68종 중 61종 없음, `common.*`·`admin.*` 공용 키 없음).
이는 결함이 아니라 의도된 범위다 — 번들 확장 관리자 화면을 운영할 템플릿이 필요하면 `sirsoft-admin_basic` 을 쓰거나,
이 문서의 계약을 채운 템플릿을 만든다.

## 새 관리자 템플릿 점검 절차

1. 위 [컴포넌트](#컴포넌트-68종) 목록을 템플릿 `components.json` 과 대조해 빠진 이름이 없는지 본다.
2. `lang/ko.json`·`lang/en.json` 에 `common`·`admin` 묶음이 `sirsoft-admin_basic` 과 같은 키 구조로 있는지 본다.
3. 조각 앵커 표의 확장점·id 가 같은 화면에 있는지 본다.
4. 다크 모드가 있으면 `template.json` 에 `features.color_scheme` 을 선언했는지 본다. 확장 화면을 자기 모양으로 바꾸려면 확장 CSS 덮기 파일을 선언한다(선택).
5. 회원 폼과 상세가 `memberFields` 의 항목 계약을 돌며 그리는지, 「확장 항목」 구획과 확장점 `member_field_input` 이 있는지, 회원 목록이 `member_fields.columns` 와 `member_fields.search_fields` 를 쓰는지 본다([회원 항목 그리기](#회원-항목-그리기)).
6. 번들 확장을 켠 상태로 템플릿을 활성화하고, 회원 상세·환경설정 알림 탭·대시보드·플러그인 설정 화면을 브라우저로 열어
   빈 자리와 키 문자열이 없는지 확인한다.

## 관련 문서

- [template-basics.md](template-basics.md) — 템플릿 타입·메타데이터
- [layout-extensions.md](layout-extensions.md) — 확장점·조각 등록
- [user-template-contract.md](user-template-contract.md) — 사용자 템플릿 계약(가입, 내 정보 화면의 앵커와 회원 항목 그리기)
- [module-assets.md](module-assets.md#확장-css-자기-공급-규약) — 확장 자기 CSS·템플릿 확장 CSS 덮기
- [sample-extensions.md](sample-extensions.md) — 학습용 샘플 확장
