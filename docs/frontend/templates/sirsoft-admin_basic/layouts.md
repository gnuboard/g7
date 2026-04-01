# sirsoft-admin_basic 레이아웃

> **템플릿 식별자**: `sirsoft-admin_basic` (type: admin)
> **관련 문서**: [컴포넌트](./components.md) | [핸들러](./handlers.md) | [레이아웃 JSON 스키마](../../layout-json.md)

---

## TL;DR (5초 요약)

```text
1. 베이스: _admin_base.json (사이드바 + 헤더 + 콘텐츠 슬롯)
2. 코어 페이지 17개: 대시보드, 사용자, 역할, 설정, 확장 관리 등
3. Partial 70개+: 탭, 모달, 패널, 필터 등
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
│           ├── _tab_mail.json (메일 발송)
│           ├── _tab_mail_templates.json (메일 템플릿)
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
│           ├── _modal_mail_template_form.json
│           ├── _modal_mail_template_preview.json
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

## 카테고리별 가이드

### 목록 페이지 패턴

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

### 상세 페이지 패턴

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

### 폼 페이지 패턴

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

### 설정 페이지 패턴

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

### 확장 관리 패턴

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

### 에러 페이지 패턴

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

## 베이스 레이아웃 구조

### _admin_base.json

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

## 관련 문서

- [sirsoft-admin_basic 컴포넌트](./components.md)
- [sirsoft-admin_basic 핸들러](./handlers.md)
- [레이아웃 JSON 스키마](../../layout-json.md)
- [레이아웃 상속](../../layout-json-inheritance.md)
- [sirsoft-basic 레이아웃](../sirsoft-basic/layouts.md)
