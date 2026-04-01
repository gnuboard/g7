# 레이아웃 JSON - 상속 (Extends, Partial, 병합)

> **메인 문서**: [layout-json.md](layout-json.md)
> **관련 문서**: [layout-json-features.md](layout-json-features.md) | [layout-json-components.md](layout-json-components.md)

---

## TL;DR (5초 요약)

```text
1. extends: 베이스 레이아웃 상속 (type: "slot" 위치에 삽입)
2. slots: 상속 시 슬롯별 컴포넌트 배열 정의
3. partials: 파일 분할 (컴포넌트 내용만 치환)
4. 병합 규칙: extends 상속 시 data_sources, modals, init_actions, computed, globalHeaders 자동 병합
5. globalHeaders 병합: pattern 기준으로 병합, 동일 pattern은 headers 병합 (자식 우선)
6. Partial은 컴포넌트 치환만! computed/data_sources 등은 부모 레이아웃에 정의!
```

---

## Partial 처리 방식

```text
필수: Partial 처리는 "단순 컴포넌트 치환"입니다!

Partial 처리 시 {"partial": "path"} 객체가 해당 파일의 컴포넌트 내용으로 치환됩니다.
최상위 속성(computed, data_sources, modals, init_actions 등)은 병합되지 않습니다!

✅ Partial에서 사용 가능:
   - 컴포넌트 정의 (type, name, children, props 등)
   - 데이터 바인딩 표현식 ({{...}})
   - $computed 참조 (부모 레이아웃에 정의된 computed 사용)
   - 조건부 렌더링 (if), 반복 (iteration) 등

❌ Partial에서 정의해도 무시되는 속성 (전체 목록):
   - computed: 부모 레이아웃과 병합되지 않음
   - data_sources: 부모 레이아웃과 병합되지 않음
   - modals: 부모 레이아웃과 병합되지 않음
   - init_actions: 부모 레이아웃과 병합되지 않음
   - state: 부모 레이아웃과 병합되지 않음
   - version: 부모 레이아웃 버전이 사용됨
   - layout_name: 부모 레이아웃 이름이 사용됨
   - extends: partial에서 extends 사용 불가
   - slots: partial은 슬롯 정의 불가
   - classMap: 부모 레이아웃과 병합되지 않음
   - errorHandling: 부모 레이아웃과 병합되지 않음
   - scripts: 부모 레이아웃과 병합되지 않음

최상위 속성은 반드시 부모 레이아웃(메인 레이아웃)에 정의해야 합니다!

💡 기술적 이유: Partial 처리는 {"partial": "path"} 객체를 파일 내용으로 치환합니다.
   치환된 내용은 컴포넌트 배열 내에 삽입되므로, 레이아웃 최상위 속성은 의미가 없습니다.
   extends 상속과 달리 partial은 mergeComputed(), mergeDataSources() 등을 호출하지 않습니다.
```

**병합 흐름**:

```text
[메인 레이아웃]
    ↓
[{"partial": "path"} 객체 탐색]
    ↓
[해당 파일 로드 후 컴포넌트 내용으로 치환]
    ↓
[DB에 병합된 단일 JSON 저장]
    ↓
[프론트엔드는 병합된 JSON만 수신]
```

**올바른 computed 정의 방법**:

```json
// ✅ admin_product_list.json (부모 레이아웃) - computed 정의
{
  "layout_name": "admin_product_list",
  "computed": {
    "filterPriceType": "{{_local.filter.priceType ?? query.price_type ?? 'selling_price'}}",
    "filterDateType": "{{_local.filter.dateType ?? query.date_type ?? 'created_at'}}"
  },
  "slots": {
    "content": [
      { "partial": "partials/_filter_section.json" }
    ]
  }
}

// ✅ _filter_section.json (partial 파일) - $computed 참조만 가능
{
  "meta": { "is_partial": true },
  "type": "basic",
  "name": "Div",
  "children": [
    {
      "type": "basic",
      "name": "Span",
      "text": "가격 유형: {{$computed.filterPriceType}}"
    }
  ]
}
```

**잘못된 예시 (partial에서 computed 정의)**:

```json
// ❌ _filter_section.json (partial 파일) - computed 정의해도 무시됨!
{
  "meta": { "is_partial": true },
  "computed": {
    "filterPriceType": "..."  // 이 computed는 병합되지 않음!
  },
  "type": "basic",
  "name": "Div"
}
```

위 partial의 `computed`는 **부모 레이아웃과 병합되지 않습니다**. `$computed.filterPriceType`은 `undefined`로 평가됩니다.

---

## 목차

1. [레이아웃 상속 (Extends & Slots)](#레이아웃-상속-extends--slots)
2. [레이아웃 Partial (파일 분할)](#레이아웃-partial-파일-분할)
3. [병합 결과 구조](#병합-결과-구조)
4. [상속 체인 검증 규칙](#레이아웃-상속-체인-검증-규칙)

---

## 레이아웃 상속 (Extends & Slots)

**목적**: 레이아웃 재사용 및 확장성 향상

### Base 레이아웃 예시

```json
{
  "version": "1.0.0",
  "layout_name": "base_admin",
  "components": [
    {
      "id": "header",
      "type": "composite",
      "name": "AdminHeader"
    },
    {
      "id": "main-content",
      "type": "slot",
      "name": "content",
      "default": []
    },
    {
      "id": "footer",
      "type": "composite",
      "name": "AdminFooter"
    }
  ]
}
```

### 상속 레이아웃 예시

```json
{
  "version": "1.0.0",
  "layout_name": "dashboard",
  "extends": "base_admin",
  "meta": {
    "title": "$t:dashboard.title",
    "description": "$t:dashboard.description"
  },
  "data_sources": [],
  "slots": {
    "content": [
      {
        "id": "dashboard-content",
        "type": "composite",
        "name": "DashboardStats"
      }
    ]
  }
}
```

---

## 레이아웃 Partial (파일 분할)

**목적**: 거대한 레이아웃 JSON 파일을 작은 파일들로 분할하여 가독성 및 유지보수성 향상

**처리 시점**: 템플릿/모듈 활성화 시점 (DB 저장 전)

### 기본 문법

```json
{
  "partial": "경로"
}
```

**핵심 원칙**:
- ✅ `partial` 키워드 사용 ($ 접두사 없음)
- ✅ 상대 경로만 허용
- ✅ 어디든 사용 가능 (객체, 배열 항목 등)
- ✅ 무제한 중첩 깊이 (최대 10단계)
- ✅ data_sources 전파 (partial 파일에서도 접근 가능)

### 사용 예시

#### 1. 기본 예시 (템플릿)

**디렉토리 구조**:
```
templates/sirsoft-admin_basic/layouts/
├── template_partial_test.json          # 메인 레이아웃
└── partials/                           # Partial 파일 폴더
    └── template_partial_test/          # 메인 레이아웃명으로 그룹핑
        ├── _content_section.json      # Level 1 partial
        └── _info_card.json            # Level 2 partial
```

**메인 레이아웃**:
```json
{
  "version": "1.0.0",
  "layout_name": "template_partial_test",
  "extends": "_admin_base",
  "slots": {
    "content": [
      {
        "type": "basic",
        "name": "Div",
        "children": [
          {
            "partial": "partials/template_partial_test/_content_section.json"
          }
        ]
      }
    ]
  }
}
```

**Partial 파일** (_content_section.json):
```json
{
  "meta": {
    "is_partial": true,
    "description": "컨텐츠 섹션"
  },
  "type": "basic",
  "name": "Div",
  "children": [
    {
      "partial": "_info_card.json"
    }
  ]
}
```

**중첩 Partial 파일** (_info_card.json):
```json
{
  "meta": {
    "is_partial": true,
    "description": "정보 카드"
  },
  "type": "composite",
  "name": "Card",
  "props": {
    "title": "Partial 테스트",
    "content": "이 Card는 partial로 포함되었습니다.\n\n특징:\n• 무제한 중첩 깊이\n• data_sources 전파"
  }
}
```

### is_partial 메타데이터

Partial 전용 파일임을 표시하는 메타데이터 (선택사항):

```json
{
  "meta": {
    "is_partial": true,
    "description": "Partial 전용 파일 설명"
  }
}
```

**용도**:
- Partial 전용 파일임을 명시적으로 표시
- DB 저장 방지 (활성화 시 자동 스킵)
- 문서화 및 가독성 향상

### 경로 해석 규칙

**기본 경로**: 현재 레이아웃 파일이 있는 디렉토리

| 경로 형식 | 설명 | 예시 |
|----------|------|------|
| `folder/file.json` | 하위 디렉토리의 파일 | `partials/template_partial_test/_content_section.json` |
| `file.json` | 같은 디렉토리의 파일 | `_section_header.json` |
| `../file.json` | 상위 디렉토리 | `../common/header.json` |

**보안 제약**:
- ✅ 상대 경로만 허용
- 절대 경로 사용 금지
- ❌ 크로스 템플릿/모듈 참조 금지
- ✅ `resources/layouts/{category}/` 디렉토리 내부로 제한

### data_sources 전파

**핵심 기능**: 메인 레이아웃의 `data_sources`가 partial 파일에서도 접근 가능

```json
// 메인 레이아웃
{
  "data_sources": [
    {"id": "items", "endpoint": "/api/admin/sample/test-data"}
  ],
  "slots": {
    "content": [
      {"partial": "partials/sample_content/_tab_content.json"}
    ]
  }
}

// _tab_content.json (partial 파일)
{
  "type": "composite",
  "name": "Card",
  "props": {
    "content": "Items 총 개수: {{items.data.pagination.total}}"
  }
}
```

### 제약사항

- **최대 깊이**: 10단계 (`config('template.layout.max_inheritance_depth')`)
- **순환 참조**: 자동 감지 및 로그 기록 (에러 컴포넌트 반환)
- **보안**: layouts 디렉토리 외부 참조 금지
- **에러 처리**: 파일 없음, JSON 파싱 실패 시 로그 기록 + 에러 컴포넌트 반환 (개발 환경에서만)

### 에러 처리

**개발 환경** (`APP_DEBUG=true`):
- 에러 메시지를 화면에 빨간색 박스로 표시
- 로그에 상세 정보 기록

**프로덕션 환경** (`APP_DEBUG=false`):
- 에러 컴포넌트를 빈 배열로 반환 (아무것도 렌더링 안 함)
- 로그에만 기록

#### 2. 하이브리드 폴더 구조 예시 (권장)

**디렉토리 구조**:
```
modules/sirsoft-board/resources/layouts/admin/
├── admin_board_form.json               # 메인 레이아웃
└── partials/
    ├── admin_board_form/               # 레이아웃 전용 Partial 파일
    │   ├── _tab_basic.json
    │   ├── _tab_permissions.json
    │   ├── _tab_list.json
    │   ├── _tab_post.json
    │   └── _tab_notification.json
    └── common/                         # 재사용 가능한 공통 Partial (향후)
        └── _common_section.json
```

**메인 레이아웃** (admin_board_form.json):
```json
{
  "version": "1.0.0",
  "layout_name": "admin_board_form",
  "extends": "_admin_base",
  "slots": {
    "content": [
      {
        "id": "board_form",
        "type": "basic",
        "name": "Form",
        "children": [
          {
            "partial": "partials/admin_board_form/_tab_basic.json"
          },
          {
            "partial": "partials/admin_board_form/_tab_permissions.json"
          }
        ]
      }
    ]
  }
}
```

**Partial 파일** (partials/admin_board_form/_tab_basic.json):
```json
{
  "meta": {
    "is_partial": true,
    "description": "Board Form - Basic Tab Content"
  },
  "id": "tab_content_basic",
  "type": "basic",
  "name": "Div",
  "condition": "{{(_local.activeTab ?? 'basic') === 'basic'}}",
  "props": {
    "className": "space-y-6 max-w-2xl pt-6"
  },
  "children": [
    {
      "id": "board_id_field",
      "type": "basic",
      "name": "Input",
      "props": {
        "type": "text",
        "name": "board_id",
        "placeholder": "$t:sirsoft-board.admin.form.board_id_placeholder"
      }
    }
  ]
}
```

**하이브리드 구조의 장점**:
- ✅ **레이아웃별 격리**: `admin_board_form/` 폴더로 해당 레이아웃 전용 파일 관리
- ✅ **확장성**: `common/` 폴더에 향후 재사용 가능한 컴포넌트 추가 가능
- ✅ **명확한 구조**: 파일 경로만 봐도 용도를 알 수 있음
- ✅ **유지보수성**: 특정 레이아웃 수정 시 해당 폴더만 작업

### Partial 내부 데이터 바인딩 규칙

```
Partial은 PHP의 include와 동일하게 "단순 문자열 삽입"입니다.
병합 후 하나의 완전한 레이아웃이 되므로, 프론트엔드는 partial 여부를 알 수 없습니다.

주의: partial 내부에서 {{props.xxx}} 사용 불가
   - props는 partial 호출 시 전달되는 것이 아님
   - 병합 후 프론트엔드에서 props 컨텍스트가 없어 평가 불가

✅ 필수: partial 내부에서 직접 data_sources ID로 참조
   - {{stats?.data?.users ?? 0}} (O)
   - {{props.value}} (X)
```

**잘못된 예시 ({{props.xxx}} 사용)**:

```json
// ❌ _stat_card.json (partial 파일)
{
  "meta": { "is_partial": true },
  "type": "basic",
  "name": "Div",
  "children": [
    { "type": "basic", "name": "Span", "text": "{{props.value}}" },
    { "type": "basic", "name": "P", "text": "{{props.label}}" }
  ]
}

// ❌ home.json (메인 레이아웃) - props 전달 시도
{
  "partial": "partials/home/_stat_card.json",
  "props": { "value": "{{stats?.data?.users ?? 0}}", "label": "회원수" }
}
```

**올바른 예시 (data_sources 직접 참조)**:

```json
// ✅ _stat_card.json (partial 파일) - data_sources ID 직접 참조
{
  "meta": { "is_partial": true },
  "type": "basic",
  "name": "Div",
  "children": [
    { "type": "basic", "name": "Span", "text": "{{stats?.data?.users ?? 0}}" },
    { "type": "basic", "name": "P", "text": "$t:home.stats.members" }
  ]
}

// ✅ home.json (메인 레이아웃) - partial만 참조
{
  "partial": "partials/home/_stat_card.json"
}
```

**왜 {{props.xxx}}가 작동하지 않는가?**

1. Partial 처리는 **활성화 시점**에 수행되어 DB에 병합된 결과가 저장됨
2. 프론트엔드는 병합된 하나의 레이아웃만 받아서 렌더링
3. 프론트엔드 입장에서 "partial이었던 부분"과 "원래 메인 레이아웃 부분"의 구분이 불가능
4. `props` 컨텍스트는 컴포넌트 내부에서만 유효하며, partial 호출 시 전달되는 것이 아님
5. 따라서 `{{props.xxx}}`는 평가 시점에 `undefined`로 해석됨

**체크리스트**:

```
□ partial 파일 내부에서 {{props.xxx}} 패턴 사용하지 않았는가?
□ data_sources ID를 직접 참조하고 있는가? (예: {{stats?.data?.xxx}})
□ 메인 레이아웃의 data_sources가 partial에서 접근하는 ID를 포함하는가?
□ Optional chaining (?.) 과 nullish coalescing (??) 을 사용하여 안전하게 접근하는가?
```

---

### 베스트 프랙티스

**분할 기준**:
- ✅ 반복되는 큰 구조 (탭, 섹션)
- ✅ 재사용 가능한 컴포넌트 조합
- ✅ 독립적으로 관리하고 싶은 부분
- ❌ meta, state (짧고 맥락 필요)
- ❌ data_sources (관계 파악 필요)

**폴더 구조 규칙**:
- ✅ **하이브리드 구조 (권장)**: `partials/{레이아웃명}/` + `partials/common/`
- ✅ **레이아웃 전용**: `partials/{레이아웃명}/` (특정 레이아웃에만 사용)
- ✅ **공통 폴더**: `partials/common/` (여러 레이아웃에서 재사용)
- ❌ **단일 폴더**: `partials/` (파일이 많아질 경우 관리 어려움)

**네이밍 규칙**:
- 파일: `_{파일명}.json` (언더스코어 접두사 권장)
- is_partial 메타데이터 추가 권장

**경로 표기**:
```json
// ✅ 권장 (하이브리드 구조)
{"partial": "partials/admin_board_form/_tab_basic.json"}
{"partial": "partials/common/_section_header.json"}

// ✅ 허용 (같은 디렉토리)
{"partial": "_section_header.json"}

// ❌ 금지 (절대 경로, 크로스 참조)
{"partial": "/modules/sirsoft-sample/resources/layouts/..."}
{"partial": "./partials/_tab_content.json"}
```

### extends와의 차이

| 항목 | extends | partial |
|------|---------|---------|
| 목적 | 레이아웃 상속 | 파일 분할 |
| 구조 | 선형 체인 | 트리 |
| 개수 | 1개만 | 제한 없음 |
| 깊이 | 10단계 | 10단계 |
| 병합 | 슬롯 교체 | 직접 치환 |
| 처리 시점 | Runtime | 활성화 시점 |
| data_sources | 상속됨 | 전파됨 |

### 처리 흐름

```
[템플릿/모듈 활성화]
    ↓
[syncLayouts() 실행]
    ↓
[레이아웃 JSON 파일 읽기]
    ↓
[resolveAllPartials() 실행] ← Partial 처리
  - partial 키 탐색 (DFS)
  - 파일 로드 및 JSON 파싱
  - data_sources 전파
  - 순환 참조 감지
  - 치환된 데이터로 교체
    ↓
[partial이 모두 병합된 JSON]
    ↓
[DB 저장 (template_layouts 테이블)]
```

**중요**: Partial 처리는 **활성화 시점에 한 번만** 실행되며, DB에는 병합된 결과가 저장됩니다. Runtime에서는 DB에서 병합된 JSON을 직접 사용합니다.

---

## 병합 결과 구조

레이아웃 병합 시 `LayoutService::mergeLayouts()`가 반환하는 결과 구조:

```json
{
  "version": "1.0.0",
  "layout_name": "dashboard",
  "meta": { ... },
  "data_sources": [ ... ],
  "components": [ ... ],
  "modals": [ ... ],
  "init_actions": [ ... ]
}
```

### 필수 필드

| 필드 | 설명 | 병합 우선순위 |
|------|------|--------------|
| `version` | 레이아웃 스키마 버전 | 자식 → 부모 → "1.0.0" |
| `layout_name` | 레이아웃 식별자 | 자식 → 부모 → "" |
| `meta` | 메타 정보 | 자식이 부모를 덮어씀 |
| `data_sources` | 데이터 소스 | 부모 + 자식 (ID 중복 불가) |
| `components` | 컴포넌트 트리 | 슬롯 교체 병합 |
| `modals` | 모달 컴포넌트 | ID 기반 병합 (자식 우선) |
| `initActions` / `init_actions` | 초기화 액션 | 부모 먼저 + 자식 나중에 (실행 순서 보장) |
| `initLocal` / `state` | 로컬 상태 초기값 | 얕은 병합 (자식이 동일 키 덮어씀) |
| `initGlobal` | 전역 상태 초기값 | 얕은 병합 (자식이 동일 키 덮어씀) |
| `initIsolated` | 격리 상태 초기값 | 얕은 병합 (자식이 동일 키 덮어씀) |
| `scripts` | 외부 스크립트 | ID 기반 병합 (자식 우선) |

### 상태 초기화 속성 병합 규칙 (engine-v1.11.0+)

> **얕은 병합 (Shallow Merge)**: 부모와 자식의 최상위 키만 병합, 동일 키는 자식이 덮어씀

```text
// 부모 레이아웃
"initLocal": { "activeTab": "basic", "isLoading": false }

// 자식 레이아웃
"initLocal": { "activeTab": "advanced", "filter": {} }

// 병합 결과 (자식이 동일 키 덮어씀)
"initLocal": { "activeTab": "advanced", "isLoading": false, "filter": {} }
```

| 속성 | 병합 방식 | 하위 호환 |
|------|----------|----------|
| `initLocal` | 얕은 병합 (array_merge) | `state` 속성도 동일 처리. 데이터소스 `initLocal`은 `_merge` 옵션으로 병합 방식 지정 가능 |
| `initGlobal` | 얕은 병합 (array_merge) | - |
| `initIsolated` | 얕은 병합 (array_merge) | - |
| `initActions` | 배열 연결 (부모 + 자식 순서) | `init_actions` 속성도 동일 처리 |
| `scripts` | ID 기반 병합 (자식 우선) | - |
| `globalHeaders` | pattern 기준 병합 (자식 우선) | 동일 pattern은 headers 병합 (engine-v1.16.0+) |

### meta.seo 병합 규칙

부모의 meta.seo 기본값을 자식이 부분적으로 오버라이드 가능:

| 키 유형 | 병합 전략 |
|---------|----------|
| 스칼라 (enabled, priority 등) | 자식 우선 오버라이드 |
| 연관 배열 (og, vars, structured_data) | deep merge (array_replace_recursive) |
| data_sources (숫자 배열) | 합집합 + 중복 제거 (permissions와 동일) |

- 부모: `{ enabled: true, priority: 0.5 }`
- 자식: `{ priority: 0.8, og: {...} }`
- 결과: `{ enabled: true, priority: 0.8, og: {...} }` ← 부모 enabled 보존

자식이 `enabled: false` 지정 시 → SEO 비활성화 (자식 우선)

> 상세: [seo-system.md](../backend/seo-system.md)

#### 데이터소스 initLocal의 `_merge` 옵션 (engine-v1.18.0+)

데이터소스의 `initLocal`에서 `_merge` 옵션을 사용하면 API 응답을 `_local`에 매핑할 때 병합 방식을 지정할 수 있습니다.

| `_merge` 값 | 동작 |
|-------------|------|
| `"deep"` (기본값) | 깊은 병합 — 기존 `_local` 값 보존, 매핑 결과만 덮어쓰기 |
| `"shallow"` | 얕은 병합 — 최상위 키만 병합 |
| `"replace"` | 완전 교체 — 기존 `_local` 무시, 매핑 결과만 남음 |

> **상세 문서**: [data-sources-advanced.md](data-sources-advanced.md#3-병합-전략-옵션-_merge)

### 병합 시 제거되는 필드

- `extends`: 상속 관계 정보
- `slots`: 슬롯 정의
- `slot`: 컴포넌트의 슬롯 속성

### 프론트엔드 검증

- `LayoutLoader.ts`에서 `version`, `layout_name` 필드 필수 검증
- 필드 누락 시 `VALIDATION_FAILED` 오류 발생

### 검증 규칙

- `extends` 필드가 있으면 `components` 또는 `slots` 중 하나 필수
- `extends`가 없으면 `components` 필드 필수 (base 레이아웃)
- 순환 참조 방지 (A → B → A)
- 최대 상속 깊이: 10단계

### 슬롯 병합 규칙

- 부모 레이아웃의 슬롯을 자식 레이아웃의 컴포넌트로 대체
- 슬롯 이름이 일치하지 않으면 부모 레이아웃의 `default` 컴포넌트 사용
- 상속 체인은 재귀적으로 병합 (최대 10단계)

### globalHeaders 병합

> **버전**: engine-v1.16.0+

부모와 자식 레이아웃의 globalHeaders는 **pattern 기준**으로 병합됩니다.

#### 병합 규칙

1. **배열 합치기**: 부모 + 자식 배열을 순서대로 합침
2. **동일 pattern**: 같은 pattern이 있으면 headers를 병합 (자식이 동일 키 덮어씀)
3. **다른 pattern**: 별도로 유지

#### 예시

**부모 레이아웃** (`_user_base.json`):

```json
{
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Template": "basic" } },
    { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
  ]
}
```

**자식 레이아웃** (`shop/cart.json`):

```json
{
  "extends": "_user_base",
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Page": "cart" } }
  ]
}
```

**병합 결과**:

```json
{
  "globalHeaders": [
    { "pattern": "*", "headers": { "X-Template": "basic", "X-Page": "cart" } },
    { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
  ]
}
```

- 동일 pattern `*`: headers가 병합됨 (자식의 `X-Page` 추가)
- 부모에만 있는 pattern `/api/modules/sirsoft-ecommerce/*`: 그대로 유지

#### 병합 순서

```text
부모 레이아웃 (_base.json)
    ↓ 병합
자식 레이아웃 (shop/cart.json)
    ↓ 병합 (있을 경우)
Partial은 병합되지 않음 (컴포넌트 치환만)
```

---

## 레이아웃 상속 체인 검증 규칙

```
중요: 레이아웃 상속 시 순환 참조 및 깊이 제한 검증 필수
✅ 필수: LayoutService에서 validateInheritanceChain() 구현
```

### 핵심 원칙

- **최대 상속 깊이**: 10단계 (MAX_DEPTH 상수)
- **순환 참조 방지**: A → B → A 패턴 감지
- **검증 시점**: 레이아웃 생성/수정 시 (FormRequest)
- **예외 처리**: CircularReferenceException, MaxDepthExceededException

### 검증 구현

```php
<?php

namespace App\Services\Template;

use App\Exceptions\Template\CircularReferenceException;
use App\Exceptions\Template\MaxDepthExceededException;
use App\Models\TemplateLayout;

class LayoutService
{
    private const MAX_DEPTH = 10;

    /**
     * 레이아웃 상속 체인 검증
     *
     * @param string $layoutName 검증할 레이아웃명
     * @param array $stack 상속 체인 스택 (재귀 추적용)
     * @param int $depth 현재 깊이
     * @throws CircularReferenceException 순환 참조 발생 시
     * @throws MaxDepthExceededException 깊이 초과 시
     */
    public function validateInheritanceChain(
        string $layoutName,
        array $stack = [],
        int $depth = 0
    ): void {
        // 1. 순환 참조 검사
        if (in_array($layoutName, $stack)) {
            throw new CircularReferenceException($stack, $layoutName);
        }

        // 2. 깊이 검사
        if ($depth > self::MAX_DEPTH) {
            throw new MaxDepthExceededException($depth, self::MAX_DEPTH);
        }

        // 3. 레이아웃 조회
        $layout = TemplateLayout::where('name', $layoutName)->first();

        if (!$layout) {
            return; // 레이아웃이 없으면 더 이상 검증 불필요
        }

        // 4. extends가 있으면 재귀적으로 검증
        if ($layout->extends) {
            $this->validateInheritanceChain(
                $layout->extends,
                array_merge($stack, [$layoutName]),
                $depth + 1
            );
        }
    }

    /**
     * 레이아웃 생성 전 검증
     */
    public function createLayout(array $data): TemplateLayout
    {
        // extends가 있으면 상속 체인 검증
        if (isset($data['extends'])) {
            $this->validateInheritanceChain($data['extends']);
        }

        $layout = TemplateLayout::create($data);

        // 캐시 무효화
        $this->cacheService->invalidateLayout($layout->name);

        return $layout;
    }

    /**
     * 레이아웃 수정 전 검증
     */
    public function updateLayout(int $id, array $data): TemplateLayout
    {
        $layout = TemplateLayout::findOrFail($id);
        $oldExtends = $layout->extends;

        // extends 변경 시 새로운 상속 체인 검증
        if (isset($data['extends']) && $data['extends'] !== $oldExtends) {
            // 자기 자신을 extends하는지 검사
            if ($data['extends'] === $layout->name) {
                throw new CircularReferenceException([], $layout->name);
            }

            // 새 상속 체인 검증
            $this->validateInheritanceChain($data['extends'], [$layout->name]);
        }

        $layout->update($data);

        // 캐시 무효화 (상속 체인 전체)
        $this->cacheService->invalidateLayoutChain($layout->name);

        return $layout->fresh();
    }
}
```

### Custom Exception 예시

```php
<?php

namespace App\Exceptions\Template;

use Exception;

/**
 * 레이아웃 순환 참조 예외
 */
class CircularReferenceException extends Exception
{
    private array $stack;

    public function __construct(array $stack, string $currentLayout)
    {
        $this->stack = $stack;

        $stackTrace = implode(' → ', $stack) . " → {$currentLayout}";
        $message = __('exceptions.template.circular_reference', ['trace' => $stackTrace]);

        parent::__construct($message);
    }

    public function getStack(): array
    {
        return $this->stack;
    }
}

/**
 * 레이아웃 최대 깊이 초과 예외
 */
class MaxDepthExceededException extends Exception
{
    public function __construct(int $currentDepth, int $maxDepth = 10)
    {
        $message = __('exceptions.template.max_depth_exceeded', [
            'current' => $currentDepth,
            'max' => $maxDepth
        ]);

        parent::__construct($message);
    }
}
```

### 검증 시점

```php
// StoreLayoutRequest.php - 레이아웃 생성 시
public function rules(): array
{
    return [
        'extends' => ['nullable', 'string', new ValidLayoutInheritance],
        // ... 기타 필드
    ];
}

// ValidLayoutInheritance Custom Rule
class ValidLayoutInheritance implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        try {
            $layoutService = app(LayoutService::class);
            $layoutService->validateInheritanceChain($value);
        } catch (CircularReferenceException $e) {
            $fail(__('validation.layout.circular_reference', ['trace' => $e->getMessage()]));
        } catch (MaxDepthExceededException $e) {
            $fail(__('validation.layout.max_depth_exceeded'));
        }
    }
}
```
