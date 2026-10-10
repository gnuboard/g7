# 관리자 템플릿 교체 계약

> **목적**: 관리자 템플릿(`type: admin`)을 새로 만들거나 교체할 때, 번들 모듈·플러그인의 관리자 화면이 그 템플릿에 기대는 것(컴포넌트·공용 문구 키·스타일 클래스·조각이 붙는 자리)을 한곳에 정리한다.

---

## TL;DR (5초 요약)

```text
1. 확장의 관리자 화면은 템플릿 레이아웃이 아니라 «템플릿이 주는 부품» 위에 그려진다 — 부품이 없으면 그 부분만 조용히 빠진다
2. 필수 계약 4종: 컴포넌트(68종) · 공용 문구 키(common.*·admin.* 137개) · 스타일 클래스 · 조각 앵커(확장점 13 + 앵커 id 7)
3. 기준 구현은 sirsoft-admin_basic — 이 표의 모든 항목을 갖춘다
4. 학습용 gnuboard7-hello_admin_template 은 이 계약을 채우지 않는다(알려진 제한) — 번들 확장 관리자 화면 운영용이 아니다
5. 계약 밖에서 확장이 템플릿에 기대는 것을 새로 만들면 그 확장의 문서와 이 표를 함께 고친다
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
없는 스타일 클래스는 규칙이 없어 배치·색이 사라진다. 어느 경우도 오류로 막히지 않으므로, 템플릿을 교체한 운영자는
「일부 화면이 이상하다」로만 겪는다. 아래 표는 2026-10-09 기준 번들 확장 22종의 관리자 화면·조각 393개를 실측한 결과다.

## 필수 계약 표

| 계약 | 규모 | 기준 위치(sirsoft-admin_basic) | 빠지면 |
| --- | --- | --- | --- |
| 컴포넌트 | 68종 | `components.json`, `src/components/**` | 그 노드가 그려지지 않는다(자식까지 함께 사라짐) |
| 공용 문구 키 | `common.*`·`admin.*` 137개 | `lang/{ko,en}.json` → `lang/partial/{ko,en}/{common,admin}.json` (+ 언어팩 `frontend/`) | 버튼·제목에 키 문자열이 그대로 보인다 |
| 스타일 클래스 | 레이아웃 의미 클래스(아래 묶음) | `templates/_bundled/sirsoft-admin_basic/src/styles/main.css` 의 `@layer components` | 배치·여백·색 규칙이 없어 화면이 무너진다 |
| 조각 앵커 | 확장점 13 · 앵커 id 7 | `layouts/**` | 그 자리에 붙는 확장 조각이 등록 단계에서 빠진다 |

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

### 스타일 클래스

확장 레이아웃은 템플릿의 `@layer components` 의미 클래스로 배치를 정한다. 묶음별 대표는 다음과 같다.

| 묶음 | 대표 클래스 |
| --- | --- |
| 페이지 골격 | `admin-page-content`(+`-compact`·`-fluid`·`-responsive` 변형) · `page-header` · `page-title` · `page-description` · `page-actions` |
| 카드·섹션 | `admin-card` · `card-title` · `card-description` · `section-heading` · `section-heading-md` · `panel-header-row` |
| 배치 | `flex-between` · `flex-center` · `flex-start` · `grid-2col-responsive` · `grid-3col-responsive` · `row-stack` · `stack-tight` |
| 폼 | `form-label` · `form-hint` · `form-error` · `input` · `input-readonly` · `select-composite` · `textarea` · `text-required` |
| 버튼 | `btn` · `btn-primary` · `btn-secondary` · `btn-danger` · `btn-outline` · `btn-ghost` · `btn-icon` · `btn-sm` |
| 하단 고정 | `sticky-footer-buttons` · `sticky-footer-buttons-between` · `modal-footer-buttons` |
| 글자 | `text-heading` · `text-body` · `text-label` · `text-hint` · `text-secondary` · `text-tertiary` |
| 상태 상자 | `alert-info` · `alert-warning` · `alert-danger` · `text-error-strong` · `text-error-soft`(저장 오류 상자) |
| 배지·탭 | `badge`·`badge-*` · `tab-nav-default` · `sticky-tab-nav` · `tab-content` |

뜻이 있는 색(경고·성공·위험·안내 글자색)은 이 계약에 넣지 않는다. 확장이 자기 CSS 에 접두 클래스로 싣는다 —
[module-assets.md 「확장 화면의 의미 색은 확장 CSS 가 싣는다」](module-assets.md#확장-화면의-의미-색은-확장-css-가-싣는다).
번들 확장 일부가 아직 템플릿의 `text-*-soft` 를 쓰고 있으며 7.1.x 에서 옮긴다.

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
| 앵커 id | `header_currency_inject_anchor` | `_admin_base` 머리글 |
| 앵커 id | `user_detail_tabs`, `extension_tab_content` | 회원 상세 |
| 앵커 id | `user_form_tabs`, `extension_form_content` | 회원 등록·수정 |
| 앵커 id | `notif_channel_content` | 환경설정 알림 채널 탭 |
| 앵커 id | `notification_log_datagrid` | 알림 발송 이력 |

모듈이 자기 화면 안에 둔 확장점(예: 이커머스 주문 목록의 결제수단 배지 자리, 게시판 설정의 문의 게시판 자리)은 그 모듈이
제공하므로 템플릿의 계약이 아니다. 확장점 전체 목록은 [layout-extensions.md](layout-extensions.md) 를 본다.

## 알려진 제한: 학습용 관리자 템플릿

`gnuboard7-hello_admin_template` 은 관리자 템플릿의 **최소 구성을 보여주는 학습용 샘플**이다([sample-extensions.md](sample-extensions.md)).
기본 컴포넌트 8종과 문구 키 19개만 있고 위 계약을 채우지 않으므로, 이 템플릿을 활성화하면 번들 모듈·플러그인의
관리자 화면은 대부분 그려지지 않거나 키 문자열로 보인다(실측: 위 68종 중 61종 없음, `common.*`·`admin.*` 공용 키 없음).
이는 결함이 아니라 의도된 범위다 — 번들 확장 관리자 화면을 운영할 템플릿이 필요하면 `sirsoft-admin_basic` 을 쓰거나,
이 문서의 계약을 채운 템플릿을 만든다.

## 새 관리자 템플릿 점검 절차

1. 위 [컴포넌트](#컴포넌트-68종) 목록을 템플릿 `components.json` 과 대조해 빠진 이름이 없는지 본다.
2. `lang/ko.json`·`lang/en.json` 에 `common`·`admin` 묶음이 `sirsoft-admin_basic` 과 같은 키 구조로 있는지 본다.
3. 템플릿 CSS 빌드가 위 스타일 클래스를 생성하는지 본다(템플릿 빌드는 자기 레이아웃만 훑으므로, 확장만 쓰는 클래스는
   `@layer components` 에 직접 정의해야 한다).
4. 조각 앵커 표의 확장점·id 가 같은 화면에 있는지 본다.
5. 번들 확장을 켠 상태로 템플릿을 활성화하고, 회원 상세·환경설정 알림 탭·대시보드·플러그인 설정 화면을 브라우저로 열어
   빈 자리와 키 문자열이 없는지 확인한다.

## 관련 문서

- [template-basics.md](template-basics.md) — 템플릿 타입·메타데이터
- [layout-extensions.md](layout-extensions.md) — 확장점·조각 등록
- [module-assets.md](module-assets.md) — 확장 자기 CSS
- [sample-extensions.md](sample-extensions.md) — 학습용 샘플 확장
