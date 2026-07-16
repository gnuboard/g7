# 레이아웃 확장 시스템 (Layout Extensions)

> 모듈/플러그인이 기존 레이아웃에 동적으로 UI를 주입하는 시스템입니다.

## 목차

- [개요](#개요)
- [핵심 원칙](#핵심-원칙)
- [확장 타입](#확장-타입)
- [Extension 파일 위치](#extension-파일-위치)
- [Overlay 확장](#overlay-확장)
- [Extension Point 확장](#extension-point-확장)
- [템플릿 오버라이드](#템플릿-오버라이드)
- [모듈 활성화/비활성화 동작](#모듈-활성화비활성화-동작)
- [우선순위](#우선순위)
- [데이터 소스 병합](#데이터-소스-병합)
- [모달 병합](#모달-병합)
- [Overlay 레이아웃 섹션 병합](#overlay-레이아웃-섹션-병합)
- [관련 파일](#관련-파일)
- [문제 해결](#문제-해결)

---

## 개요

Layout Extension 시스템은 모듈/플러그인이 코어 또는 다른 확장의 레이아웃에 UI 컴포넌트를 동적으로 주입할 수 있게 해줍니다.

**주요 특징**:
- 코어 레이아웃 수정 없이 UI 확장
- 모듈 비활성화 시 자동으로 확장 UI 숨김
- 템플릿에서 모듈 확장 오버라이드 가능
- 우선순위 기반 렌더링 순서 제어
- 관리자가 레이아웃 시각 편집기로 확장 조각을 직접 편집 (아래 "시각 편집" 참조)

### 시각 편집 (레이아웃 편집기)

레이아웃 시각 편집기(`/admin/layout-editor/{templateId}`)의 라우트 트리에 `[확장 주입]`
그룹(출처별 하위그룹)이 표시되며, 확장 항목을 선택하면 확장 편집 모드로 진입해 확장 조각
(extension_point=`content.components`, overlay=각 injection 의 `components`)을 일반 레이아웃과
동등하게 시각 편집한다. 저장은 해당 확장 행(layout-extensions API)으로 적재되어 버전 관리·수정
감지·`--layout-strategy=keep` 보존이 동작한다.

`inject_props` 주입은 편집할 컴포넌트 트리가 없으므로, 그 호스트 노드를 라우트/공통 레이아웃
편집 중 선택해 **속성 모달의 "확장이 주입한 속성" 섹션**(출처 배지)에서 편집한다 — 편집 결과는
호스트 레이아웃이 아니라 그 확장 행으로 교차 저장된다. 라우트/공통 레이아웃 편집 중 확장이 주입한
영역은 잠금 표시되며 "확장 편집" 어포던스로 확장 편집 모드에 진입할 수 있다.

#### 주입 kind × 편집기 대응

확장이 호스트에 주입하는 모든 kind 와 편집기의 대응 방식은 아래 표가 기준이다. 새 주입 방법을
추가할 때는 이 표에 편집기 대응을 함께 정의해야 한다.

| 주입 kind | 주입 경로 | 편집기 대응 |
| --- | --- | --- |
| extension_point `components` (mode: replace/append/prepend) | 호스트 EP 노드 자리 (components·modals 트리 모두 순회) | 확장 편집 모드 시각 편집. 게이트(`if`) 뒤면 editor-spec `states` 로 상태 시뮬레이션, 미정의 시 디그레이드 안내 |
| overlay `injections[].components` (position: prepend/append/prepend_child/append_child/replace) | 호스트 `target_id` 노드 기준 | 동일 — 확장 편집 모드 시각 편집 |
| overlay `injections[].position: inject_props` | 호스트 `target_id` 노드 props 병합 | 라우트/공통 편집 중 속성 모달 "확장이 주입한 속성" 섹션에서 편집 (확장 행으로 교차 저장) |
| 호스트 **모달 내부** EP 주입 (예: 회원가입 약관 모달의 `html_content`) | 호스트 `modals[]` 안 EP 노드 | 확장 편집 모드가 그 모달 노드를 표시용 `isOpen=true` 로 캔버스에 함께 렌더해 시각 편집 (모달 편집 모드와 동형) |
| `content.modals` (확장이 모달을 통째로 기여) | 호스트 `modals[]` 끝에 병합 | 시각 편집 비대상 — 트리에는 노출되고, 진입 시 "코드 편집 이용" 디그레이드 안내 |
| `content.data_sources` | 호스트 `data_sources` 병합 | 데이터 소스 모달에 출처 배지로 표시 (시각 편집 비대상) |
| `content.scripts` | 호스트 `scripts` 병합 (중복 제거) | 캔버스에 로드만 (CKEditor CDN 등) — 편집은 코드 편집 |
| `content.init_actions` (overlay) | 호스트 `init_actions` 끝에 병합 | 시각 편집 비대상 — 진입 시 "코드 편집 이용" 디그레이드 안내 |

트리 노출 판별(백엔드 `getExtensionHostLayouts`)은 "주입이 성립하는가"(overlay: 실재 `target_id`
injection 또는 modals/data_sources/scripts/init_actions 보유, EP: 병합할 payload 보유 + 확장점 실재)만 본다.
시각 편집 가능 여부의 정밀 판정은 진입 시 프론트(editability 판정 + 렌더 검증 + 폴백 안내)가
수행한다 — 시각 조각이 없는 확장도 숨기지 않고 디그레이드 안내로 코드 편집을 유도한다.

---

## 핵심 원칙

```text
필수: layout_extensions 통한 동적 주입 사용 (코어 레이아웃에 모듈 UI 하드코딩 금지)
필수: Layout Extension 시스템을 통한 동적 UI 주입
필수: 모듈 비활성화 시 관련 UI 자동 숨김
✅ 필수: 확장 컴포넌트에 ExtensionBadge 표시 (관리자 UI)
필수: 플러그인은 완전한 레이아웃 등록 불가 → 확장 지점(layout_extensions)만 사용
   (예외: settings.json 환경설정 레이아웃은 registerPluginLayouts()로 등록)
필수: 모듈만 완전한 레이아웃 등록 가능 (admin/user 모두)
```

---

## 확장 타입

| 타입 | 키 | 설명 | 사용 시점 |
|------|-----|------|----------|
| **Overlay** | `target_layout` | 기존 레이아웃의 특정 컴포넌트 ID를 찾아 주입 | 특정 위치에 삽입/추가 |
| **Extension Point** | `extension_point` | 레이아웃에 사전 정의된 확장 포인트에 주입 | 확장용 영역이 미리 정의된 경우 |

---

## Extension 파일 위치

### 모듈

```
modules/
└── vendor-module/
    └── resources/
        └── extensions/           ← 확장 정의 디렉토리
            └── *.json            ← 확장 JSON 파일
```

### 플러그인

```
plugins/
└── vendor-plugin/
    └── resources/
        └── extensions/
            └── *.json
```

### 템플릿 오버라이드

```
templates/
└── vendor-template/
    └── extensions/
        └── {module-identifier}/    ← 오버라이드 대상 모듈
            └── *.json              ← 오버라이드 JSON
```

---

## Overlay 확장

기존 레이아웃의 특정 컴포넌트를 찾아 UI를 주입합니다.

### JSON 스키마

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [...]
    }
  ],
  "data_sources": [...],
  "priority": 100
}
```

### 필드 설명

| 필드 | 필수 | 타입 | 설명 |
|------|------|------|------|
| `target_layout` | ✅ | string | 확장할 대상 레이아웃 이름 |
| `injections` | ✅ | array | 주입 정의 배열 |
| `injections[].target_id` | ✅ | string | 주입 대상 컴포넌트 ID |
| `injections[].position` | ✅ | string | 주입 위치 (아래 참조) |
| `injections[].components` | ✅ | array | 주입할 컴포넌트 배열 |
| `data_sources` | ❌ | array | 추가 데이터 소스 (선택) |
| `init_actions` | ❌ | array | 호스트 레이아웃 `init_actions` 뒤에 추가할 초기화 액션 배열 (선택). 모듈이 호스트가 모르는 `_global` 초기화(예: 모듈별 표시 통화 복원)를 기여할 때 사용. 호스트는 추가 단계의 존재/모듈을 모른다 |
| `priority` | ❌ | number | 우선순위 (기본값: 100, 낮을수록 먼저) |

> `init_actions` 병합은 **overlay(`target_layout`)** 확장에서 지원한다. 호스트 `init_actions` 배열 끝에 확장의 `init_actions` 가 순서대로 추가되므로, 호스트 초기화가 모두 끝난 뒤 확장 초기화가 실행된다. 모듈 비활성 시 그 확장 행 자체가 비활성화되어 추가 단계도 사라진다(호스트 레이아웃 수정 0). 모듈이 호출하는 핸들러는 모듈 소유 네임스페이스(`{module-id}.handlerName`)를 사용해 모듈 미설치 시 미등록 핸들러로 무동작하게 하는 것을 권장한다.

### 상속(extends) 체인 전파 — base 레이아웃 타겟 overlay

`target_layout` 이 다른 화면이 `extends` 로 상속하는 **공통/베이스 레이아웃**(예: `_user_base`, `_admin_base`)인 overlay 는, 그 base 를 상속하는 모든 자식 레이아웃(`home`, `shop/index` 등)을 서빙할 때도 적용된다.

서빙 파이프라인은 `extends` 를 해소해 자식 문서를 만들 때 상속한 base 이름들을 내부 메타로 보존하고, overlay 매칭 시 `[자식 레이아웃명] + 상속 체인` 전체를 대상으로 한다. 따라서 `_user_base` 를 `target_layout` 으로 하는 overlay(예: 헤더 슬롯 주입, `init_actions` 통화 복원)는 base 를 직접 서빙하지 않아도 자식 화면에 정상 주입된다. 다단계 상속(`A extends B extends _user_base`)도 체인 끝까지 전파된다.

```text
overlay(target_layout=_user_base) 주입
    ↓ (home extends _user_base 서빙 시)
home 화면에 _user_base 타겟 overlay 가 적용됨 (슬롯·init_actions 포함)
```

- Extension Point 는 병합된 컴포넌트 트리를 직접 스캔하므로 상속과 무관하게 항상 동작한다(이 전파는 overlay 매칭에만 해당).
- 같은 overlay 가 중복 적용되지 않도록 매칭 결과는 확장 ID 기준으로 합집합 후 `priority` 로 재정렬된다.
- 편집기에서 자식 라우트 레이아웃을 편집할 때, base 타겟 overlay 가 주입한 영역은 확장 출처 메타가 부여되어 확장 잠금 영역으로 표시된다(해당 overlay 자체의 편집 호스트는 base 레이아웃).

### position 옵션

| 값 | 설명 | 다이어그램 |
|-----|------|----------|
| `prepend` | 타겟 **앞에** 형제로 삽입 | `[NEW] [TARGET] [siblings...]` |
| `append` | 타겟 **뒤에** 형제로 삽입 | `[TARGET] [NEW] [siblings...]` |
| `prepend_child` | 타겟 children **맨 앞에** 삽입 | `TARGET { [NEW] [children...] }` |
| `append_child` | 타겟 children **맨 뒤에** 삽입 | `TARGET { [children...] [NEW] }` |
| `replace` | 타겟 **완전 교체** | `[NEW]` (기존 타겟 제거) |
| `inject_props` | 타겟 컴포넌트의 **props에 값 주입** | `TARGET.props ← injection.props` |

### inject_props 상세

`inject_props`는 기존 컴포넌트의 props에 값을 주입하는 특수 position입니다. `components` 필드 대신 `props` 필드를 사용합니다.

#### 병합 전략

| 전략 | 설명 | 예시 |
|------|------|------|
| `_append` | 배열 끝에 추가 | `tabs._append: [newTab]` → 기존 tabs 뒤에 추가 |
| `_prepend` | 배열 앞에 추가 | `tabs._prepend: [newTab]` → 기존 tabs 앞에 추가 |
| `_merge` | 객체 병합 (shallow) | `style._merge: {color: 'red'}` → 기존 style에 병합 |
| (직접 값) | 스칼라 덮어쓰기 | `disabled: true` → 기존 값 대체 |

#### inject_props 예시: 탭에 항목 추가

```json
{
  "target_layout": "admin_user_detail",
  "injections": [
    {
      "target_id": "user_detail_tabs",
      "position": "inject_props",
      "props": {
        "tabs": {
          "_append": [
            {
              "id": "ext_verification",
              "label": "$t:admin.users.form.sections.verification_info",
              "iconName": "shield"
            }
          ]
        }
      }
    },
    {
      "target_id": "extension_tab_content",
      "position": "append_child",
      "components": [
        {
          "id": "ext_verification_content",
          "type": "basic",
          "name": "Div",
          "if": "{{(_global.activeUserDetailTab || query.tab || 'basic') === 'ext_verification'}}",
          "children": [...]
        }
      ]
    }
  ],
  "priority": 100
}
```

#### 주의사항

- `inject_props`일 때 `components` 필드는 무시됩니다
- 대상 prop이 표현식 문자열인 경우 `_append`/`_prepend`/`_merge` 적용이 불가하며, 경고 로그가 기록됩니다
- `_append`와 `_merge` 등 여러 전략을 하나의 prop에 혼합 사용할 수 없습니다 (첫 번째 매칭 전략 우선)

### 예시: 사용자 폼에 알림 설정 추가

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [
        {
          "id": "section_notification_settings",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-6 mb-6"
          },
          "children": [
            {
              "type": "basic",
              "name": "Div",
              "props": {
                "className": "flex items-center justify-between mb-4"
              },
              "children": [
                {
                  "type": "basic",
                  "name": "H3",
                  "props": {
                    "className": "text-lg font-semibold text-gray-900 dark:text-white"
                  },
                  "text": "$t:sirsoft-board.admin.users.form.sections.notification_settings"
                },
                {
                  "type": "composite",
                  "name": "ExtensionBadge",
                  "props": {
                    "type": "module",
                    "identifier": "sirsoft-board",
                    "installedModules": "{{_global.installedModules}}"
                  }
                }
              ]
            },
            {
              "type": "basic",
              "name": "Input",
              "props": {
                "type": "checkbox",
                "name": "notify_post_complete",
                "checked": "{{_local.form?.notify_post_complete ?? false}}"
              }
            }
          ]
        }
      ]
    }
  ],
  "priority": 100
}
```

---

## Extension Point 확장

레이아웃에 미리 정의된 확장 포인트에 컴포넌트를 주입합니다.

### 지원 위치

Extension Point는 레이아웃의 다음 섹션에서 사용할 수 있습니다:

| 위치           | 지원 | 설명                                 |
|----------------|------|--------------------------------------|
| `components`   | ✅   | 메인 컴포넌트 트리 (기본)            |
| `modals`       | ✅   | 모달 내부 컴포넌트 트리 (v1.17.0+)   |

```text
v1.17.0 이전: modals 내부의 extension_point는 처리되지 않았음
✅ v1.17.0+: components와 modals 양쪽 모두 재귀적으로 extension_point 처리
```

### 레이아웃에서 Extension Point 정의

```json
{
  "layout_name": "admin_user_form",
  "components": [
    {
      "id": "user_form_container",
      "type": "basic",
      "name": "Div",
      "children": [
        {
          "type": "extension_point",
          "name": "user_form_additional_fields",
          "default": [],
          "props": {
            "readOnlyFields": ["zipcode", "address"]
          },
          "callbacks": {
            "onAddressSelect": { "handler": "setState", "params": { "target": "local", "form.zipcode": "{{$event.zipcode}}" } }
          }
        }
      ]
    }
  ]
}
```

#### Extension Point 데이터 전달 (engine-v1.28.0+)

| 필드 | 타입 | 설명 |
|------|------|------|
| `props` | object | 주입 컴포넌트에 전달할 데이터. 표현식 평가됨. 플러그인에서 `{{extensionPointProps.xxx}}`로 접근 |
| `callbacks` | object | 주입 컴포넌트에 전달할 액션 객체. 평가 없이 그대로 전달. 플러그인에서 `{{extensionPointCallbacks.xxx}}`로 접근 |

### 모듈에서 Extension Point에 주입

```json
{
  "extension_point": "user_form_additional_fields",
  "mode": "replace",
  "components": [
    {
      "id": "custom_field_section",
      "type": "basic",
      "name": "Div",
      "children": [...]
    }
  ],
  "data_sources": [...],
  "priority": 100
}
```

### JSON 스키마

| 필드 | 필수 | 타입 | 설명 |
|------|------|------|------|
| `extension_point` | ✅ | string | 확장 포인트 이름 |
| `mode` | ❌ | string | 주입 모드 (아래 참조, 기본값: `append`) |
| `components` | ❌ | array | 주입할 컴포넌트 배열 |
| `data_sources` | ❌ | array | 추가 데이터 소스 |
| `modals` | ❌ | array | 호스트 레이아웃에 병합할 모달 배열 |
| `scripts` | ❌ | array | 추가 스크립트 |
| `priority` | ❌ | number | 우선순위 (기본값: 100) |

### mode 옵션

| 값 | 설명 | 동작 |
| ----- | ------ | ------ |
| `append` | default **뒤에** 추가 (기본값) | `[default...] [NEW]` |
| `prepend` | default **앞에** 추가 | `[NEW] [default...]` |
| `replace` | default **완전 교체** | `[NEW]` (default 제거) |

---

## 템플릿 오버라이드

템플릿은 모듈/플러그인의 확장을 오버라이드하여 커스터마이징할 수 있습니다.

### 디렉토리 구조

```
templates/_bundled/sirsoft-admin_basic/
└── extensions/
    └── sirsoft-board/                    ← 오버라이드 대상 모듈
        └── user-notification-settings.json   ← 오버라이드 파일
```

### 오버라이드 파일명 규칙

모듈의 원본 확장 파일명과 **동일한 이름**을 사용합니다.

```
모듈 원본:    modules/_bundled/sirsoft-board/resources/extensions/user-notification-settings.json
템플릿 오버라이드: templates/_bundled/sirsoft-admin_basic/extensions/sirsoft-board/user-notification-settings.json
```

### 오버라이드 우선순위

```
1. 템플릿 오버라이드 (source_type = Template)  ← 가장 높은 우선순위
2. 플러그인 확장 (source_type = Plugin)
3. 모듈 확장 (source_type = Module)            ← 가장 낮은 우선순위
```

### 오버라이드 예시

모듈의 기본 확장을 템플릿에서 스타일 변경:

```json
{
  "target_layout": "admin_user_form",
  "injections": [
    {
      "target_id": "section_marketing_consent",
      "position": "append",
      "components": [
        {
          "id": "section_notification_settings",
          "type": "basic",
          "name": "Div",
          "props": {
            "className": "bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-6 mb-6"
          },
          "children": [...]
        }
      ]
    }
  ],
  "priority": 100
}
```

---

## 모듈 활성화/비활성화 동작

### 활성화 시

1. `resources/extensions/*.json` 파일 스캔
2. **대상 템플릿 적용 가능 여부 판별** (아래 "템플릿 대상 판정" 참조)
3. `template_layout_extensions` 테이블에 등록 (또는 기존 레코드 복원)
4. 레이아웃 캐시 무효화

### 템플릿 대상 판정

모듈/플러그인 확장은 admin·user 등 모든 활성 템플릿에 동기화되지만, 확장이 **대상으로 하는 레이아웃이 실제 존재하는 템플릿에만 등록**된다.

- **Overlay**: `target_layout` 이 그 템플릿에 레이아웃으로 존재해야 한다.
- **Extension Point**: `extension_point`(확장점명)이 그 템플릿의 어느 레이아웃 `content` 에 `type: extension_point` 노드로 정의되어 있어야 한다.

판정은 `RefreshesLayoutExtensions` trait 이 `LayoutExtensionService::isExtensionApplicableToTemplate()` 로 수행한다. 대상이 없는 템플릿(예: admin 레이아웃 대상 확장 ↔ user 템플릿)에는 등록하지 않으며, 과거 무차별 등록으로 잘못 생성된 행은 다음 동기화 시 `removeInapplicableExtension()` 으로 정리(soft delete)된다.

### 비활성화 시

1. 해당 모듈의 확장 레코드 Soft Delete
2. **렌더링 시 비활성화된 모듈의 확장 자동 스킵**
3. 레이아웃 캐시 무효화

### 템플릿 오버라이드와 모듈 비활성화

**중요**: 모듈이 비활성화되면, 해당 모듈을 오버라이드하는 템플릿 확장도 함께 숨김 처리됩니다.

```
sirsoft-board 모듈 비활성화
    ↓
sirsoft-board의 모든 확장 숨김
    ↓
sirsoft-board를 오버라이드하는 템플릿 확장도 숨김
```

---

## 우선순위

### priority 필드

- 기본값: 100
- 낮을수록 먼저 렌더링
- 같은 위치에 여러 확장이 있을 경우 순서 결정

```json
{
  "target_layout": "admin_user_form",
  "priority": 50,
  "injections": [...]
}
```

### 우선순위 가이드라인

| 범위 | 용도 | 예시 |
|------|------|------|
| 1-25 | 필수 정보 | 보안 경고, 중요 알림 |
| 50-75 | 주요 기능 | 핵심 모듈 UI |
| 100 | 기본값 | 일반 확장 |
| 150+ | 보조 정보 | 분석, 통계 |

---

## 데이터 소스 병합

확장에서 정의한 `data_sources`는 호스트 레이아웃의 데이터 소스에 병합됩니다.

```json
{
  "target_layout": "admin_user_form",
  "injections": [...],
  "data_sources": [
    {
      "id": "notification_settings",
      "type": "api",
      "method": "GET",
      "endpoint": "/api/modules/sirsoft-board/admin/users/{{route.id}}/notification-settings",
      "auto_fetch": true,
      "auth_required": true
    }
  ]
}
```

---

## 모달 병합

Extension Point 확장에서 정의한 `modals`는 호스트 레이아웃의 `modals` 배열에 자동 병합됩니다.

### 왜 modals 섹션을 사용해야 하는가?

Extension Point의 children으로 주입된 인라인 Modal(`show` prop 기반)은 정상 동작하지 않습니다. 그누보드7 Modal 시스템에서 안정적으로 동작하려면 `modals` 섹션에 등록하고 `openModal`/`closeModal` 핸들러로 제어해야 합니다.

> 상세 모달 규칙: [modal-usage.md](../frontend/modal-usage.md)

### Extension JSON에서 modals 정의

```json
{
  "extension_point": "shop_checkout_extensions",
  "modals": [
    {
      "id": "payment_error_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "$t:plugin.payment_error_title",
        "size": "small"
      },
      "children": [...]
    }
  ],
  "priority": 100
}
```

### 핸들러에서 모달 열기

Extension으로 병합된 모달은 커스텀 핸들러에서 `G7Core.modal.open()`으로 열 수 있습니다:

```typescript
const G7Core = (window as any).G7Core;
G7Core?.modal?.open?.('payment_error_modal');
```

또는 레이아웃 JSON 액션에서:

```json
{
  "type": "click",
  "handler": "openModal",
  "target": "payment_error_modal"
}
```

### 병합 동작

- 여러 확장의 `modals`가 하나의 호스트 레이아웃에 모두 병합됩니다
- 호스트 레이아웃에 기존 `modals`가 있으면 확장 모달이 뒤에 추가됩니다
- 확장에 `modals`가 없으면 호스트 레이아웃에 영향 없음 (하위 호환)

### 주의사항

```text
modals 섹션 모달에 show prop 사용 금지 (무시됨)
모달 ID는 호스트 레이아웃의 기존 모달과 중복되지 않도록 플러그인 prefix 사용 권장
✅ 모달 닫기 시 setState → closeModal 순서 유지 (순서 중요)
```

---

## Overlay 레이아웃 섹션 병합

Overlay 확장에서 `computed`, `state`, `modals` 섹션을 정의하면 호스트 레이아웃에 자동 병합됩니다.

### 지원 섹션

| 섹션 | Extension Point | Overlay | 설명 |
|------|:---:|:---:|------|
| `data_sources` | ✅ | ✅ | API 데이터 소스 추가 |
| `scripts` | ✅ | ✅ | 커스텀 핸들러 스크립트 추가 |
| `modals` | ✅ | ✅ | 모달 컴포넌트 추가 |
| `computed` | ✅ | ✅ | 계산된 값 추가 |
| `state` | ✅ | ✅ | 초기 상태 추가 |

### 예시: Overlay에서 computed와 state 추가

```json
{
  "target_layout": "admin_user_detail",
  "injections": [...],
  "computed": {
    "verificationStatus": "{{user?.data?.identity_verified ? 'verified' : 'pending'}}"
  },
  "state": {
    "showVerificationHistory": false
  },
  "modals": [
    {
      "id": "ext_verification_modal",
      "type": "composite",
      "name": "Modal",
      "props": { "title": "인증 이력" },
      "children": [...]
    }
  ],
  "priority": 100
}
```

### 병합 동작

- 여러 overlay의 같은 섹션은 priority 오름차순으로 순차 병합됩니다
- 동일 키 충돌 시 후순위 overlay가 덮어씁니다
- 해당 섹션이 없는 overlay는 기존 레이아웃에 영향 없음 (하위 호환)

---

## 레이아웃 오버라이드 vs 확장 오버라이드

| 구분 | 레이아웃 오버라이드 | 확장 오버라이드 |
|------|-------------------|---------------|
| 대상 | 모듈이 등록한 완전한 레이아웃 | 확장 지점/Overlay로 주입된 UI |
| 위치 | `templates/{t}/layouts/overrides/{module}/*.json` | `templates/{t}/extensions/{module}/*.json` |
| DB 테이블 | `template_layouts` | `template_layout_extensions` |
| 해석 서비스 | `LayoutResolverService` | `LayoutExtensionService` |
| version_constraint | 지원 | 지원 |

---

## 레이아웃 확장 편집 및 버전 관리

관리자는 모듈/플러그인이 주입한 레이아웃 확장을 일반 레이아웃과 동등한 수준으로 편집·버전관리·복구·미리보기할 수 있다.

### 관리자 편집

레이아웃 편집 화면(`admin_template_layout_edit`)의 좌측 트리에서 확장 항목을 선택하면 해당 확장의 `content` JSON 을 편집할 수 있다. 편집 권한은 일반 레이아웃과 동일한 `core.templates.layouts.edit` 를 사용한다.

확장 편집 API 는 `{extensionId}` 정수 PK 로 식별한다 — 동일 `target_name` 에 여러 source 의 확장이 존재할 수 있기 때문이다.

#### 좌측 트리는 오버라이드 해석 결과만 노출한다

편집 화면 좌측 트리(`GET .../layout-extensions`)는 화면 렌더링 경로와 동일한 오버라이드 해석을 적용한다. 동일 `(extension_type, target_name)` 범위에서 템플릿 오버라이드(`source_type = Template`)가 특정 모듈/플러그인을 오버라이드하면, 그 모듈/플러그인의 원본 확장은 화면에 적용되지 않으므로 트리에서도 제외된다.

이는 관리자가 "저장은 되지만 화면에 반영되지 않는" 가려진 확장 행을 편집하는 것을 막기 위함이다. 트리에는 항상 해당 템플릿에서 실제로 적용되는 확장(템플릿 오버라이드 + 오버라이드되지 않은 모듈/플러그인 확장)만 표시된다.

해석 로직은 `LayoutExtensionRepository::resolveOverrides()` 단일 출처를 사용하며, 렌더링 경로(`getResolvedExtensionPoints` / `getResolvedOverlays`)와 편집 트리(`getResolvedByTemplateId`)가 동일한 가시성 기준을 공유한다.

| 메서드 | 라우트 |
| ------ | ------ |
| `GET` | `/api/admin/templates/{templateName}/layout-extensions` (출처별 그룹핑) |
| `GET` | `.../layout-extensions/{extensionId}` |
| `PUT` | `.../layout-extensions/{extensionId}` |
| `GET` | `.../layout-extensions/{extensionId}/versions` |
| `GET` | `.../layout-extensions/{extensionId}/versions/{version}` |
| `POST` | `.../layout-extensions/{extensionId}/versions/{versionId}/restore` |
| `POST` | `.../layout-extensions/{extensionId}/preview` |

### 버전 이력

확장을 편집·저장하면 `template_layout_extension_versions` 테이블에 버전 스냅샷이 쌓인다. 일반 레이아웃의 `template_layout_versions` 와 동일한 구조이며, 이전 버전으로 복구할 수 있다. 버전 diff 계산 로직은 `App\Repositories\Concerns\CalculatesJsonContentDiff` trait 으로 일반 레이아웃과 공유한다.

### 사용자 수정 감지 (`original_content_hash`)

`template_layout_extensions` 의 `original_content_hash` / `original_content_size` 컬럼은 모듈/플러그인이 등록한 원본 확장 콘텐츠의 정규화 해시를 보존한다. 관리자가 확장을 편집해도 이 원본 해시는 변경되지 않는다 — 현재 `content` 해시와 원본 해시를 비교하여 "사용자가 수정한 확장"을 판별한다.

### 모듈/플러그인 업데이트 시 충돌 전략

`module:update` / `plugin:update` 의 `--layout-strategy` 옵션이 확장에도 적용된다 (일반 레이아웃과 동일한 수준):

- `overwrite` (기본): 파일 내용으로 확장 `content` 를 무조건 덮어쓴다. 관리자가 편집한 확장이 있으면 업데이트 전 경고를 출력한다.
- `keep`: 관리자가 편집한 확장(`original_content_hash` 불일치)은 `content`/`priority` 갱신을 건너뛰고 `is_active` 만 갱신한다. 사용자 수정분이 보존된다.

### 미리보기

확장 편집 중 미리보기는 편집 중인 `content` 를 대표 레이아웃에 임시 적용하여 렌더링한다.

- `overlay` 타입: `target_layout` 자체가 대표 레이아웃이므로 별도 선택이 불필요하다.
- `extension_point` 타입: 하나의 확장점이 여러 레이아웃에서 사용될 수 있으므로, 미리볼 대표 레이아웃을 선택하는 모달이 노출된다.

미리보기 URL 은 일반 레이아웃과 동일한 `/api/layouts/preview/{token}.json` 을 재사용하며, `preview_type` 컬럼으로 일반/확장 미리보기를 분기한다.

---

## 관련 파일

### 백엔드

| 파일 | 설명 |
|------|------|
| `app/Services/LayoutExtensionService.php` | 확장 적용·편집·버전관리 핵심 서비스 |
| `app/Models/LayoutExtension.php` | 확장 모델 (DB 테이블) |
| `app/Models/TemplateLayoutExtensionVersion.php` | 확장 버전 이력 모델 |
| `app/Repositories/LayoutExtensionRepository.php` | 확장 Repository |
| `app/Repositories/LayoutExtensionVersionRepository.php` | 확장 버전 Repository |
| `app/Repositories/Concerns/CalculatesJsonContentDiff.php` | JSON diff 계산 trait (일반 레이아웃과 공유) |
| `app/Http/Controllers/Api/Admin/LayoutExtensionController.php` | 확장 편집/버전관리 admin 컨트롤러 |
| `app/Enums/LayoutExtensionType.php` | 확장 타입 Enum (ExtensionPoint, Overlay) |
| `app/Enums/LayoutSourceType.php` | 출처 타입 Enum (Module, Plugin, Template) |

### 데이터베이스

| 테이블 | 설명 |
|--------|------|
| `template_layout_extensions` | 확장 등록 정보 저장 |

### 주요 컬럼

```
template_layout_extensions
├── template_id            # 템플릿 ID
├── extension_type         # ExtensionPoint | Overlay
├── target_name            # 확장 포인트명 또는 대상 레이아웃명
├── source_type            # Module | Plugin | Template
├── source_identifier      # 모듈/플러그인/템플릿 식별자
├── override_target        # 오버라이드 대상 (템플릿 오버라이드 시)
├── content                # 확장 JSON 내용
├── original_content_hash  # 원본 확장 콘텐츠 SHA-256 해시 (사용자 수정 감지용)
├── original_content_size  # 원본 확장 콘텐츠 바이트 크기
├── priority               # 우선순위
├── is_active              # 활성 상태
└── deleted_at             # Soft Delete

template_layout_extension_versions
├── extension_id     # 레이아웃 확장 ID (FK, cascadeOnDelete)
├── version          # 버전 번호 (자동 증가)
├── content          # 확장 정의 JSON 스냅샷
├── changes_summary  # 변경 요약 JSON (added/removed/modified/char_diff)
└── created_by       # 저장자 ID
```

---

## 문제 해결

### 확장이 표시되지 않음

**확인 사항**:
1. 모듈/플러그인이 활성화되어 있는지 확인
2. `target_layout` 또는 `extension_point` 이름이 정확한지 확인
3. `target_id`가 대상 레이아웃에 존재하는지 확인
4. JSON 문법 오류 확인

**캐시 초기화**:
```bash
php artisan cache:clear
```

### 오버라이드가 적용되지 않음

**확인 사항**:
1. 템플릿 오버라이드 파일 경로 확인
   - `templates/{template}/extensions/{module-identifier}/{filename}.json`
2. 파일명이 원본과 동일한지 확인
3. `template_layout_extensions` 테이블에서 `source_type = 'template'` 레코드 확인

### 모듈 비활성화 후에도 확장 표시됨

**원인**: 캐시 문제

**해결**:
```bash
php artisan cache:clear
```

### 여러 확장의 순서가 예상과 다름

**해결**: `priority` 값 조정 (낮을수록 먼저 렌더링)

---

## 관련 문서

- [module-layouts.md](module-layouts.md) - 모듈 레이아웃 등록
- [hooks.md](hooks.md) - 훅 시스템
- [layout-json.md](../frontend/layout-json.md) - 레이아웃 JSON 스키마
- [components.md](../frontend/components.md) - 컴포넌트 개발 규칙
