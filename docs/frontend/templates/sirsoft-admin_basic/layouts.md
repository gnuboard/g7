# sirsoft-admin_basic 레이아웃

> **템플릿 식별자**: `sirsoft-admin_basic` (type: admin)
> **관련 문서**: [컴포넌트](./components.md) | [핸들러](./handlers.md) | [레이아웃 JSON 스키마](../../layout-json.md) | [저작 마크업](../../layout-markup.md)
>
> **저작 포맷**: 아래 파일 트리는 저작 SSoT 인 `.g7.html`([레이아웃 마크업](../../layout-markup.md)) 기준이다. 설치 시 서버가 각 `.g7.html` 을 메모리로 JSON 컴파일해 DB(`template_layouts`)에 등록한다. 산출 JSON 키 계약은 [layout-json.md](../../layout-json.md) 가 SSoT 이며 불변이다. 디스크에는 진단·복구용 짝 `.json` 이 병존한다.

---

## TL;DR (5초 요약)

```text
1. 베이스: _admin_base.g7.html (사이드바 + 헤더 + 콘텐츠 슬롯)
2. 코어 페이지 25개(layouts 직속, `_admin_base` 제외): 대시보드, 사용자, 역할, 설정, 확장 관리 등
3. Partial 110개: 탭, 모달, 패널, 필터 등
4. 에러 페이지 6개: 401, 403, 404, 500, 503, maintenance
5. 패턴: 목록(DataGrid+Pagination), 상세(Card), 폼(Form+FormField), 설정(Tab)
```

---

## 목차

1. [페이지 맵 (트리 구조)](#페이지-맵-트리-구조)
2. [카테고리별 가이드](#카테고리별-가이드)
   - [목록 페이지 패턴](#목록-페이지-패턴)
   - [상세 페이지 패턴](#상세-페이지-패턴)
   - [폼 페이지 패턴](#폼-페이지-패턴)
   - [설정 페이지 패턴](#설정-페이지-패턴)
   - [확장 관리 패턴](#확장-관리-패턴)
   - [에러 페이지 패턴](#에러-페이지-패턴)

---

## 페이지 맵 (트리 구조)

```text
_admin_base.g7.html (베이스 레이아웃)
│
├── admin_dashboard.g7.html (대시보드)
├── admin_login.g7.html (로그인 — _admin_base 미상속)
│
├── 사용자 관리
│   ├── admin_user_list.g7.html (목록)
│   ├── admin_user_form.g7.html (생성/수정)
│   └── admin_user_detail.g7.html (상세)
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
│           └── _modal_delete.g7.html
│
├── 환경 설정
│   └── admin_settings.g7.html (탭 네비게이션)
│       └── partials/admin_settings/
│           ├── _tab_general.g7.html (일반)
│           ├── _tab_mail.g7.html (메일 발송 SMTP 설정)
│           ├── _tab_notification_definitions.g7.html (알림 정의)
│           ├── _tab_security.g7.html (보안)
│           ├── _tab_upload.g7.html (업로드)
│           ├── _tab_drivers.g7.html (드라이버)
│           ├── _tab_seo.g7.html (SEO)
│           ├── _tab_advanced.g7.html (고급)
│           ├── _tab_info.g7.html (시스템 정보)
│           ├── _modal_cache_delete.g7.html
│           ├── _modal_core_changelog.g7.html
│           ├── _modal_core_update_guide.g7.html
│           ├── _modal_core_update_result.g7.html
│           ├── _modal_notification_template_form.g7.html
│           ├── _modal_notification_template_preview.g7.html
│           └── _modal_password_confirm.g7.html
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
│   │       └── _modal_refresh_layouts.g7.html
│   └── admin_template_layout_edit.g7.html (레이아웃 편집기)
│       └── partials/admin_template_layout_edit/_modal_version_history.g7.html
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
├── 공통 Partial
│   ├── partials/_modal_changelog.g7.html
│   └── partials/_modal_license.g7.html
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

## 카테고리별 가이드

### 목록 페이지 패턴

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

> 위는 저작(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

### 상세 페이지 패턴

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

> 위는 저작(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

### 폼 페이지 패턴

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
```

---

### 설정 페이지 패턴

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

---

### 확장 관리 패턴

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

---

### 에러 페이지 패턴

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

## 베이스 레이아웃 구조

### _admin_base.g7.html

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

## 관련 문서

- [sirsoft-admin_basic 컴포넌트](./components.md)
- [sirsoft-admin_basic 핸들러](./handlers.md)
- [레이아웃 JSON 스키마](../../layout-json.md)
- [레이아웃 상속](../../layout-json-inheritance.md)
- [sirsoft-basic 레이아웃](../sirsoft-basic/layouts.md)
