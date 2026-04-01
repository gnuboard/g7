# 컴포넌트 Props 레퍼런스

> **중요**: 레이아웃 JSON 작성 시 반드시 이 문서에서 지원되는 props를 확인하세요.

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [Select 컴포넌트](#select-컴포넌트)
3. [Input 컴포넌트](#input-컴포넌트)
4. [Button 컴포넌트](#button-컴포넌트)
5. [DataGrid 컴포넌트](#datagrid-컴포넌트)
6. [HtmlEditor 컴포넌트](#htmleditor-컴포넌트)
7. [CodeEditor 컴포넌트](#codeeditor-컴포넌트)
8. [Modal 컴포넌트](#modal-컴포넌트)
9. [LoadingSpinner 컴포넌트](#loadingspinner-컴포넌트)
10. [관련 문서](#관련-문서)

---

## 핵심 원칙

```
필수: 레이아웃 JSON 작성 전 이 문서에서 props 확인 (문서에 없는 props 사용 금지)
필수: 작성 후 /validate-frontend 스킬로 검증
```

### props 사용 전 확인 체크리스트

레이아웃 JSON에서 컴포넌트 props 작성 시:

1. **이 문서에서 해당 컴포넌트의 지원 props 확인**
2. **props 타입과 기본값 확인**
3. **레이아웃 JSON 작성**
4. **`/validate-frontend` 스킬로 검증**

---

## Select 컴포넌트

**타입**: `basic` | **템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

**파일**: `templates/*/src/components/basic/Select.tsx`

### Props 인터페이스

```typescript
export interface SelectOption {
  value: string | number;      // 옵션 값 (필수)
  label: string;               // 표시될 라벨 (필수)
  disabled?: boolean;          // 옵션 비활성화 여부
}

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  // 커스텀 Props
  options?: SelectOption[] | string[];  // 옵션 배열
  label?: string;                        // 레이블 텍스트 (상위 컴포넌트에서 처리)
  error?: string;                        // 에러 메시지 (상위 컴포넌트에서 처리)

  // HTML Select 표준 Props (모두 지원)
  value?: string | number;              // 선택된 값 (제어 컴포넌트)
  defaultValue?: string | number;       // 기본 선택값 (비제어 컴포넌트)
  name?: string;                        // 폼 필드명
  id?: string;                          // 요소 ID
  disabled?: boolean;                   // 비활성화
  required?: boolean;                   // 필수 입력
  className?: string;                   // CSS 클래스
  multiple?: boolean;                   // 다중 선택 (children 모드만)
  size?: number;                        // 표시 행 수 (children 모드만)
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `options` | `SelectOption[]` \| `string[]` | ❌ | - | 드롭다운 옵션 배열 |
| `value` | `string` \| `number` | ❌ | - | 선택된 값 (제어 컴포넌트) |
| `defaultValue` | `string` \| `number` | ❌ | - | 기본 선택값 (비제어 컴포넌트) |
| `name` | `string` | ❌ | - | 폼 필드명 |
| `disabled` | `boolean` | ❌ | `false` | 비활성화 |
| `required` | `boolean` | ❌ | `false` | 필수 입력 |
| `className` | `string` | ❌ | - | CSS 클래스 |
| `placeholder` | `string` | ❌ | - | 플레이스홀더 (옵션 배열 첫 항목) |

### 렌더링 모드

Select 컴포넌트는 두 가지 모드로 동작합니다:

1. **Options 모드** (권장): `options` prop 제공 시 커스텀 드롭다운
2. **Children 모드**: `options` 없이 children으로 `<option>` 제공 시 기본 HTML select

### 레이아웃 JSON 사용 예시

#### 기본 사용 (제어 컴포넌트)

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "name": "category",
    "value": "{{_local.category}}",
    "className": "w-full",
    "options": [
      { "value": "", "label": "선택하세요" },
      { "value": "1", "label": "카테고리 1" },
      { "value": "2", "label": "카테고리 2" }
    ]
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "_local",
        "category": "{{$event.target.value}}"
      }
    }
  ]
}
```

#### 기본값 설정 (defaultValue 사용)

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "name": "status",
    "defaultValue": "active",
    "options": [
      { "value": "active", "label": "활성" },
      { "value": "inactive", "label": "비활성" }
    ]
  }
}
```

#### 공란 방지 패턴 (권장)

**방법 1: state 초기값 설정** (권장)

```json
{
  "state": {
    "salesStatus": "on_sale"
  }
}
```

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "name": "salesStatus",
    "value": "{{_local.salesStatus}}",
    "options": [
      { "value": "on_sale", "label": "판매중" },
      { "value": "sold_out", "label": "품절" }
    ]
  }
}
```

**방법 2: defaultValue 사용**

```json
{
  "type": "basic",
  "name": "Select",
  "props": {
    "name": "status",
    "value": "{{_local.status}}",
    "defaultValue": "on_sale",
    "options": [
      { "value": "on_sale", "label": "판매중" },
      { "value": "sold_out", "label": "품절" }
    ]
  }
}
```

### 주의사항

```
value 타입 일치: value와 option.value 타입을 맞춰야 함
공란 방지: state 초기값 또는 defaultValue 설정 필수
✅ options 배열: 첫 번째 항목이 기본 선택됨 (value가 일치할 때)
```

---

## Input 컴포넌트

**타입**: `basic` | **템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

**파일**: `templates/*/src/components/basic/Input.tsx`

### Props 인터페이스

```typescript
export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;           // 레이블 텍스트
  error?: string;           // 에러 메시지
  helperText?: string;      // 도움말 텍스트
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `type` | `string` | ❌ | `"text"` | input 타입 (text, email, password, number, date 등) |
| `value` | `string` \| `number` | ❌ | - | 입력값 (제어 컴포넌트) |
| `defaultValue` | `string` \| `number` | ❌ | - | 기본값 (비제어 컴포넌트) |
| `name` | `string` | ❌ | - | 폼 필드명 |
| `placeholder` | `string` | ❌ | - | 플레이스홀더 |
| `disabled` | `boolean` | ❌ | `false` | 비활성화 |
| `required` | `boolean` | ❌ | `false` | 필수 입력 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 |
| `className` | `string` | ❌ | - | CSS 클래스 |
| `min` | `string` \| `number` | ❌ | - | 최소값 (number, date) |
| `max` | `string` \| `number` | ❌ | - | 최대값 (number, date) |
| `step` | `string` \| `number` | ❌ | - | 증가 단위 (number) |
| `maxLength` | `number` | ❌ | - | 최대 길이 |
| `pattern` | `string` | ❌ | - | 정규식 패턴 |

### IME 처리 (한글/CJK 입력)

Input 컴포넌트는 한글 등 IME 조합 입력을 올바르게 처리합니다:

- IME 조합 중에는 외부 `onChange`를 호출하지 않음 (내부 `localValue`로 즉시 시각적 피드백 제공)
- 조합 완료 후 최종 값으로 `onChange` 호출
- 조합 중에는 `onKeyPress` 이벤트도 발생하지 않음

**디바운스와 IME 조합 상호작용**:

```
디바운스가 설정된 Input에서 한글 입력 시:
- 조합 중에는 디바운스 타이머가 시작되지 않음
- 조합 완료 시점에 onChange 호출 → 디바운스 타이머 시작
- 빠른 타이핑 시 조합 완료 + 디바운스 지연이 합산됨

✅ localValue 패턴:
- Input은 내부 localValue 상태로 즉시 화면에 반영 (시각적 지연 없음)
- 외부 상태(_local)와 내부 상태(localValue)가 일시적으로 불일치 가능
- 디바운스 완료 시 외부 상태와 동기화됨
```

### 레이아웃 JSON 사용 예시

```json
{
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "text",
    "name": "searchKeyword",
    "placeholder": "$t:common.search_placeholder",
    "className": "input w-full",
    "value": "{{_local.searchKeyword}}"
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "_local",
        "searchKeyword": "{{$event.target.value}}"
      }
    },
    {
      "type": "keypress",
      "key": "Enter",
      "handler": "refetchDataSource",
      "params": {
        "dataSourceId": "products"
      }
    }
  ]
}
```

---

## Button 컴포넌트

**타입**: `basic` | **템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

**파일**: `templates/*/src/components/basic/Button.tsx`

### Props 인터페이스

```typescript
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'outline' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  loading?: boolean;
  icon?: string;
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `type` | `"button"` \| `"submit"` \| `"reset"` | ❌ | `"button"` | 버튼 타입 |
| `disabled` | `boolean` | ❌ | `false` | 비활성화 |
| `className` | `string` | ❌ | - | CSS 클래스 |
| `variant` | `string` | ❌ | `"primary"` | 버튼 스타일 |
| `size` | `string` | ❌ | `"md"` | 버튼 크기 |
| `loading` | `boolean` | ❌ | `false` | 로딩 상태 |

### 레이아웃 JSON 사용 예시

```json
{
  "type": "basic",
  "name": "Button",
  "props": {
    "type": "button",
    "className": "btn btn-primary"
  },
  "text": "$t:common.search",
  "actions": [
    {
      "type": "click",
      "handler": "refetchDataSource",
      "params": {
        "dataSourceId": "products"
      }
    }
  ]
}
```

---

## DataGrid 컴포넌트

**타입**: `composite` | **템플릿**: `sirsoft-admin_basic`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/DataGrid.tsx`

### Props 인터페이스

```typescript
export interface DataGridColumn {
  field: string;                    // 필드명 (필수)
  header: string;                   // 컬럼 헤더 텍스트 (필수)
  width?: string;                   // 컬럼 너비 (예: "100px", "auto")
  sortable?: boolean;               // 정렬 가능 여부
  template?: ComponentDefinition;   // 셀 커스텀 템플릿
}

export interface DataGridProps {
  data: any[];                      // 데이터 배열 (필수)
  columns: DataGridColumn[];        // 컬럼 정의 (필수)
  rowKey?: string;                  // 행 고유 키 필드 (기본: 'id')
  selectable?: boolean;             // 행 선택 기능
  selectedRows?: any[];             // 선택된 행 배열
  loading?: boolean;                // 로딩 상태
  className?: string;               // CSS 클래스

  // SubRow Props (engine-v1.6.0+)
  subRowChildren?: ComponentDefinition[];  // 서브 행 컴포넌트 배열
  subRowCondition?: string;                // 서브 행 표시 조건 표현식
  subRowClassName?: string;                // 서브 행 CSS 클래스

  // Footer Row Props
  footerCells?: DataGridFooterCell[];   // 합계 행 셀 정의 (field별 매핑)
  footerClassName?: string;             // 합계 행 CSS 클래스
  footerCardChildren?: ComponentDefinition[];  // 모바일 카드 뷰 합계 커스텀

  // Responsive Props
  responsiveBreakpoint?: number;    // 반응형 전환점 (기본: 768)
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `data` | `any[]` | ✅ | - | 테이블에 표시할 데이터 배열 |
| `columns` | `DataGridColumn[]` | ✅ | - | 컬럼 정의 배열 |
| `rowKey` | `string` | ❌ | `'id'` | 행 고유 키로 사용할 필드명 |
| `selectable` | `boolean` | ❌ | `false` | 체크박스 선택 기능 활성화 |
| `selectedRows` | `any[]` | ❌ | `[]` | 선택된 행 배열 (ID 배열) |
| `loading` | `boolean` | ❌ | `false` | 로딩 상태 표시 |
| `className` | `string` | ❌ | - | CSS 클래스 |

### SubRow Props (engine-v1.6.0+)

행 아래에 추가 정보를 표시하는 서브 행 기능입니다.

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `subRowChildren` | `ComponentDefinition[]` | ❌ | - | 서브 행에 렌더링할 컴포넌트 배열 |
| `subRowCondition` | `string` | ❌ | - | 서브 행 표시 조건 (예: `"{{row.status !== 'free'}}"`) |
| `subRowClassName` | `string` | ❌ | - | 서브 행 CSS 클래스 |

**subRowCondition 컨텍스트 변수**:
- `row`: 현재 행 데이터 객체
- `$index`: 현재 행 인덱스

### Footer Row Props

테이블 하단에 합계 행을 표시하는 기능입니다.

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `footerCells` | `DataGridFooterCell[]` | ❌ | - | 합계 행 셀 정의 (컬럼 field별 매핑) |
| `footerClassName` | `string` | ❌ | - | 합계 행 CSS 클래스 |
| `footerCardChildren` | `ComponentDefinition[]` | ❌ | - | 모바일 카드 뷰 합계 커스텀 렌더링 |

**FooterCell 구조**:
- `field`: 매핑할 컬럼 field (해당 컬럼 위치에 렌더링)
- `children`: 셀 내부 컴포넌트 정의 (`condition` 속성으로 조건부 표시 가능)

### 레이아웃 JSON 사용 예시

#### 기본 사용

```json
{
  "type": "composite",
  "name": "DataGrid",
  "props": {
    "data": "{{products?.data?.data || []}}",
    "rowKey": "id",
    "selectable": true,
    "selectedRows": "{{_local.selectedItems || []}}",
    "loading": "{{products?.loading}}",
    "columns": [
      {
        "field": "name",
        "header": "$t:product.table.name",
        "sortable": true,
        "width": "200px"
      },
      {
        "field": "price",
        "header": "$t:product.table.price",
        "sortable": true,
        "width": "100px"
      }
    ]
  },
  "actions": [
    {
      "event": "onSelectionChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "_local",
        "selectedItems": "{{$args[0]}}"
      }
    }
  ]
}
```

#### SubRow 사용 (배송비 요약 표시)

```json
{
  "type": "composite",
  "name": "DataGrid",
  "props": {
    "data": "{{shipping_policies?.data?.data || []}}",
    "rowKey": "id",
    "subRowCondition": "{{row.charge_policy !== 'free'}}",
    "subRowClassName": "px-6 py-2 bg-gray-50 dark:bg-gray-800/50 text-sm text-gray-600 dark:text-gray-400",
    "columns": [...],
    "subRowChildren": [
      {
        "type": "layout",
        "name": "Div",
        "props": {
          "className": "flex items-center gap-2"
        },
        "children": [
          {
            "type": "basic",
            "name": "Icon",
            "props": {
              "name": "truck",
              "className": "w-4 h-4 text-gray-400"
            }
          },
          {
            "type": "basic",
            "name": "Span",
            "props": {
              "className": "text-gray-500 dark:text-gray-400"
            },
            "text": "$t:module::admin/entity.fee_summary.label"
          },
          {
            "type": "basic",
            "name": "Span",
            "props": {
              "className": "font-medium text-gray-700 dark:text-gray-300"
            },
            "text": "{{row.fee_summary}}"
          }
        ]
      }
    ]
  }
}
```

#### 컬럼 템플릿 사용

```json
{
  "columns": [
    {
      "field": "status",
      "header": "$t:common.status",
      "width": "100px",
      "template": {
        "type": "basic",
        "name": "Span",
        "props": {
          "className": "{{$row.status === 'active' ? 'text-green-600' : 'text-gray-400'}}"
        },
        "text": "{{$row.status_label}}"
      }
    }
  ]
}
```

**컬럼 템플릿 컨텍스트 변수**:
- `$row`: 현재 행 데이터 객체
- `$index`: 현재 행 인덱스
- `$value`: 현재 셀 값 (field에 해당하는 값)

### 주의사항

```
subRowChildren vs subRowCondition
- subRowChildren만 설정: 모든 행에 서브 행 표시
- subRowCondition 함께 설정: 조건 만족하는 행만 서브 행 표시

컨텍스트 변수 차이
- columns.template 내부: $row, $index, $value 사용
- subRowChildren 내부: row, $index 사용 ($ 없이 row)

✅ 다크 모드: subRowClassName에 dark: 클래스 쌍으로 지정
```

### cellChildren — 셀 내 커스텀 컴포넌트 (engine-v1.8.0+)

컬럼 `template` 대신 셀 단위로 커스텀 컴포넌트를 렌더링합니다. 조건부 렌더링과 다중 컴포넌트 배치를 지원합니다.

```typescript
interface DataGridCellChild {
  column: string;                    // 대상 컬럼 field명 (필수)
  condition?: string;                // 표시 조건 (예: "{{row.status === 'active'}}")
  children: ComponentDefinition[];   // 셀에 렌더링할 컴포넌트 배열
}
```

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `cellChildren` | `DataGridCellChild[]` | ❌ | - | 셀 내 커스텀 컴포넌트 배열 |

**cellChildren 컨텍스트 변수**:
- `row`: 현재 행 데이터 객체
- `value` / `$value`: 현재 셀 값 (column.field에 해당)
- `$index`: 현재 행 인덱스

```json
{
  "cellChildren": [
    {
      "column": "actions",
      "children": [
        {
          "type": "basic",
          "name": "Button",
          "props": {
            "type": "button",
            "className": "btn btn-sm btn-outline"
          },
          "text": "$t:common.edit",
          "actions": [
            {
              "type": "click",
              "handler": "navigate",
              "params": { "path": "/admin/products/{{row.id}}/edit" }
            }
          ]
        }
      ]
    },
    {
      "column": "status",
      "condition": "{{row.status === 'draft'}}",
      "children": [
        {
          "type": "basic",
          "name": "Span",
          "props": { "className": "text-yellow-600 dark:text-yellow-400" },
          "text": "$t:common.draft"
        }
      ]
    }
  ]
}
```

### expandChildren — 확장 가능한 행 (engine-v1.10.0+)

행을 클릭하여 추가 콘텐츠를 펼치는 확장 행 기능입니다.

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `expandable` | `boolean` | ❌ | `false` | 확장 기능 활성화 |
| `expandedRowIds` | `any[]` | ❌ | `[]` | 현재 펼쳐진 행 ID 배열 |
| `expandChildren` | `ComponentDefinition[]` | ❌ | - | 확장 영역에 렌더링할 컴포넌트 배열 |
| `expandContext` | `object` | ❌ | - | 확장 영역에 추가 전달할 컨텍스트 |

**expandChildren 컨텍스트 변수**:
- `__componentContext`: 확장 행에 자동 전달되는 컨텍스트 (현재 행 데이터 포함)
- `row`: 현재 행 데이터 객체
- iteration 지원: expandChildren 내에서 `iteration` 속성으로 하위 데이터 반복 렌더링 가능

```json
{
  "type": "composite",
  "name": "DataGrid",
  "props": {
    "data": "{{orders?.data?.data || []}}",
    "rowKey": "id",
    "expandable": true,
    "expandedRowIds": "{{_local.expandedRows ?? []}}",
    "columns": [...],
    "expandChildren": [
      {
        "type": "layout",
        "name": "Div",
        "props": { "className": "p-4 bg-gray-50 dark:bg-gray-800" },
        "children": [
          {
            "type": "basic",
            "name": "H3",
            "props": { "className": "text-sm font-medium mb-2" },
            "text": "$t:order.items"
          },
          {
            "type": "basic",
            "name": "Span",
            "text": "{{row.item_count}} $t:common.items"
          }
        ]
      }
    ]
  },
  "actions": [
    {
      "event": "onExpandChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "_local",
        "expandedRows": "{{$args[0]}}"
      }
    }
  ]
}
```

### disabledField — 행별 액션 비활성화 (engine-v1.14.0+)

`rowActions`의 개별 버튼을 행 데이터 기반으로 비활성화합니다. 권한(abilities) 연동에 주로 사용됩니다.

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `disabledField` | `string` | ❌ | - | 행 데이터 내 비활성화 판단 경로 (dot notation). falsy 값이면 비활성화 |

```json
{
  "columns": [...],
  "rowActions": [
    {
      "label": "$t:common.edit",
      "handler": "navigate",
      "params": { "path": "/admin/items/{{row.id}}/edit" },
      "disabledField": "abilities.can_update"
    },
    {
      "label": "$t:common.delete",
      "handler": "apiCall",
      "params": { "endpoint": "/api/admin/items/{{row.id}}", "method": "DELETE" },
      "disabledField": "abilities.can_delete",
      "confirm": "$t:common.confirm_delete"
    }
  ]
}
```

```
disabledField 동작
- disabledField 경로의 값이 falsy (false, null, undefined, 0, '') → 버튼 비활성화
- truthy → 버튼 활성화
- 경로가 존재하지 않으면 undefined → 비활성화

✅ abilities 패턴: API Resource에서 abilities 객체 반환 → disabledField로 참조
```

---

## HtmlEditor 컴포넌트

**타입**: `composite` | **템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/HtmlEditor.tsx`

### Props 인터페이스

```typescript
export interface HtmlEditorProps {
  content?: string;                    // 콘텐츠 값
  onChange?: (event: { target: { name: string; value: string } }) => void;
  isHtml?: boolean;                    // HTML 모드 여부
  onIsHtmlChange?: (event: { target: { name: string; checked: boolean } }) => void;
  rows?: number;                       // Textarea 행 수 (기본: 15)
  placeholder?: string;
  label?: string;
  showHtmlModeToggle?: boolean;        // HTML 모드 체크박스 표시 (기본: true)
  contentClassName?: string;           // HtmlContent 미리보기 클래스
  purifyConfig?: any;                  // DOMPurify 설정
  className?: string;
  name?: string;                       // 콘텐츠 필드명 (기본: 'content')
  htmlFieldName?: string;              // HTML 모드 필드명 (기본: 'content_mode')
  readOnly?: boolean;
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `content` | `string` | ❌ | `''` | 콘텐츠 값 |
| `onChange` | `function` | ❌ | - | 콘텐츠 변경 콜백 |
| `isHtml` | `boolean` | ❌ | `false` | HTML 모드 여부 |
| `onIsHtmlChange` | `function` | ❌ | - | HTML 모드 변경 콜백 |
| `rows` | `number` | ❌ | `15` | Textarea 행 수 |
| `placeholder` | `string` | ❌ | `''` | 플레이스홀더 |
| `label` | `string` | ❌ | - | 라벨 텍스트 |
| `showHtmlModeToggle` | `boolean` | ❌ | `true` | HTML 모드 체크박스 표시 |
| `name` | `string` | ❌ | `'content'` | 콘텐츠 필드명 |
| `htmlFieldName` | `string` | ❌ | `'content_mode'` | HTML 모드 필드명 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 |

### 레이아웃 JSON 사용 예시

```json
{
  "type": "composite",
  "name": "HtmlEditor",
  "props": {
    "content": "{{_local.form?.content ?? ''}}",
    "isHtml": "{{(_local.form?.content_mode ?? 'text') === 'html'}}",
    "rows": 15,
    "placeholder": "$t:board.form.content_placeholder",
    "label": "$t:board.form.content",
    "name": "content",
    "htmlFieldName": "content_mode"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": { "content": "{{$args[0].target.value}}" }
      }
    },
    {
      "event": "onIsHtmlChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": { "content_mode": "{{$args[0].target.checked ? 'html' : 'text'}}" }
      }
    }
  ]
}
```

---

## CodeEditor 컴포넌트

**타입**: `composite` | **템플릿**: `sirsoft-admin_basic`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/CodeEditor.tsx`

### Props 인터페이스

```typescript
export interface CodeEditorProps {
  value: string;                       // 에디터 값 (필수)
  onChange?: (value: string) => void;  // 값 변경 콜백
  language?: string;                   // 언어 (기본: 'json')
  height?: string;                     // 높이 (기본: '100%')
  readOnly?: boolean;
  theme?: 'vs-dark' | 'vs-light';      // 테마 (기본: 'vs-dark')
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `value` | `string` | ✅ | - | 에디터 값 |
| `onChange` | `function` | ❌ | - | 값 변경 콜백 |
| `language` | `string` | ❌ | `'json'` | 언어 (json, javascript, html, css 등) |
| `height` | `string` | ❌ | `'100%'` | 에디터 높이 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 |
| `theme` | `string` | ❌ | `'vs-dark'` | 에디터 테마 |

### 레이아웃 JSON 사용 예시

```json
{
  "type": "composite",
  "name": "CodeEditor",
  "props": {
    "value": "{{_local.jsonContent ?? '{}'}}",
    "language": "json",
    "height": "400px",
    "theme": "vs-dark"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "jsonContent": "{{$args[0]}}"
      }
    }
  ]
}
```

### 주의사항

```
HtmlEditor vs CodeEditor onChange 차이
- HtmlEditor: { target: { name, value } } 이벤트 객체 전달
- CodeEditor: 문자열 직접 전달 ($args[0]가 문자열)
```

---

## Modal 컴포넌트

**타입**: `composite` | **템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

**파일**: `templates/*/src/components/composite/Modal.tsx`

### Props 인터페이스

```typescript
export interface ModalProps {
  isOpen: boolean;                    // 모달 열림 상태 (템플릿 엔진이 자동 주입)
  onClose: () => void;                // 모달 닫기 콜백 (템플릿 엔진이 자동 주입)
  title?: string;                     // 모달 타이틀
  icon?: string;                      // 타이틀 옆 아이콘 (sirsoft-basic만)
  iconClassName?: string;             // 아이콘 색상 클래스 (sirsoft-basic만)
  closeOnBackdropClick?: boolean;     // 배경 클릭 시 닫기 (기본: true)
  closeOnOverlayClick?: boolean;      // closeOnBackdropClick 별칭 (레이아웃 JSON 호환)
  showCloseButton?: boolean;          // 닫기 버튼 표시 (기본: true)
  closeOnEscape?: boolean;            // ESC 키로 닫기 (기본: true)
  width?: string;                     // 모달 너비 (기본: '500px')
  className?: string;                 // CSS 클래스
  style?: React.CSSProperties;        // 인라인 스타일
  children?: React.ReactNode;         // 모달 내용
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `isOpen` | `boolean` | ✅ | - | 모달 열림 상태 (템플릿 엔진 자동 주입) |
| `onClose` | `function` | ✅ | - | 모달 닫기 콜백 (템플릿 엔진 자동 주입) |
| `title` | `string` | ❌ | - | 모달 타이틀 |
| `icon` | `string` | ❌ | - | 타이틀 옆 아이콘 (sirsoft-basic) |
| `iconClassName` | `string` | ❌ | `'text-gray-500'` | 아이콘 색상 클래스 |
| `closeOnBackdropClick` | `boolean` | ❌ | `true` | 배경 클릭 시 닫기 |
| `closeOnOverlayClick` | `boolean` | ❌ | `true` | closeOnBackdropClick 별칭 |
| `showCloseButton` | `boolean` | ❌ | `true` | 헤더 닫기 버튼 표시 |
| `closeOnEscape` | `boolean` | ❌ | `true` | ESC 키로 닫기 허용 |
| `width` | `string` | ❌ | `'500px'` | 모달 너비 |
| `className` | `string` | ❌ | - | CSS 클래스 |

### 회피 불가 모달 설정

사용자가 닫기 버튼, 배경 클릭, ESC 키로 모달을 회피할 수 없도록 설정:

```json
{
  "type": "composite",
  "name": "Modal",
  "props": {
    "title": "$t:common.error",
    "showCloseButton": false,
    "closeOnOverlayClick": false,
    "closeOnEscape": false
  },
  "children": [...]
}
```

### 레이아웃 JSON 사용 예시

#### modals 섹션에 정의 (권장)

```json
{
  "modals": [
    {
      "id": "confirm_modal",
      "type": "composite",
      "name": "Modal",
      "props": {
        "title": "$t:common.confirm",
        "width": "400px"
      },
      "children": [
        {
          "type": "basic",
          "name": "P",
          "text": "$t:common.confirm_message"
        },
        {
          "type": "basic",
          "name": "Button",
          "props": { "type": "button" },
          "text": "$t:common.ok",
          "actions": [
            {
              "type": "click",
              "handler": "closeModal"
            }
          ]
        }
      ]
    }
  ]
}
```

#### 모달 열기/닫기

```json
// 모달 열기
{
  "handler": "openModal",
  "target": "confirm_modal"
}

// 모달 닫기
{
  "handler": "closeModal"
}
```

### 주의사항

```
isOpen/onClose는 템플릿 엔진이 자동 주입 - 직접 설정 불필요
modals 섹션은 반드시 배열 형식 (객체 형식 사용 시 렌더링 안 됨)
각 모달에 고유한 id 필수
✅ 회피 불가 모달: showCloseButton, closeOnOverlayClick, closeOnEscape 모두 false
```

---

## LoadingSpinner 컴포넌트

**타입**: `composite` | **템플릿**: `sirsoft-admin_basic`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/LoadingSpinner.tsx`

### Props 인터페이스

```typescript
export type SpinnerSize = 'sm' | 'md' | 'lg' | 'xl';

export interface LoadingSpinnerProps {
  id?: string;               // 요소 ID (engine-v1.11.0+)
  size?: SpinnerSize;        // 스피너 크기 (기본: 'md')
  color?: string;            // 색상 클래스 (기본: 'text-blue-600')
  fullscreen?: boolean;      // 전체 화면 모드 (기본: false)
  text?: string;             // 로딩 텍스트
  className?: string;        // CSS 클래스
}
```

### 지원 Props 요약

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `id` | `string` | ❌ | - | 요소 ID (DOM selector 사용 시 필요) |
| `size` | `'sm'` \| `'md'` \| `'lg'` \| `'xl'` | ❌ | `'md'` | 스피너 크기 |
| `color` | `string` | ❌ | `'text-blue-600'` | Tailwind 색상 클래스 |
| `fullscreen` | `boolean` | ❌ | `false` | 전체 화면 모드 |
| `text` | `string` | ❌ | - | 로딩 텍스트 ($t: 다국어 지원) |
| `className` | `string` | ❌ | `''` | 추가 CSS 클래스 |

### size별 스피너 크기

| Size | 스피너 | 텍스트 |
|------|--------|--------|
| `sm` | `w-4 h-4 border-2` | `text-xs` |
| `md` | `w-8 h-8 border-2` | `text-sm` |
| `lg` | `w-12 h-12 border-3` | `text-base` |
| `xl` | `w-16 h-16 border-4` | `text-lg` |

### 레이아웃 JSON 사용 예시

#### 기본 사용

```json
{
  "type": "composite",
  "name": "LoadingSpinner",
  "props": {
    "size": "md",
    "text": "$t:common.loading"
  }
}
```

#### id 지정 (scrollIntoView 등 DOM selector 사용 시)

```json
{
  "id": "loading_indicator",
  "type": "composite",
  "name": "LoadingSpinner",
  "if": "{{_global.infiniteScroll.isLoadingMore}}",
  "props": {
    "size": "sm",
    "text": "$t:common.loading",
    "color": "text-blue-600",
    "className": "py-3"
  }
}
```

렌더링 결과:

```html
<div id="loading_indicator" class="flex items-center justify-center p-4" aria-live="polite" aria-busy="true">
  <div class="flex flex-col items-center justify-center gap-3 py-3">
    <div class="w-4 h-4 border-2 border-gray-200 dark:border-gray-700 border-t-current rounded-full animate-spin text-blue-600" role="status"></div>
    <span class="text-xs text-blue-600 font-medium">로딩 중...</span>
  </div>
</div>
```

#### 전체 화면 모드

```json
{
  "type": "composite",
  "name": "LoadingSpinner",
  "if": "{{_global.isLoading}}",
  "props": {
    "fullscreen": true,
    "size": "lg",
    "text": "$t:common.loading"
  }
}
```

### 주의사항

```
id prop 사용: scrollIntoView 등 DOM selector로 접근해야 할 때 필수 지정
✅ 다크 모드: 자동 지원 (border-gray-200 dark:border-gray-700)
✅ 접근성: role="status", aria-label, aria-live="polite" 자동 설정
```

---

## 관련 문서

- [컴포넌트 개발 규칙](components.md) - basic, composite, layout 컴포넌트
- [레이아웃 JSON 스키마](layout-json.md) - 컴포넌트를 레이아웃 JSON에서 사용하는 방법
- [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md) - Admin 컴포넌트 목록 (111개)
- [sirsoft-basic 컴포넌트](templates/sirsoft-basic/components.md) - User 컴포넌트 목록 (58개)
- [에디터 컴포넌트](editors.md) - HtmlEditor, CodeEditor 상세 가이드
- [데이터 바인딩](data-binding.md) - `{{}}` 표현식, `$t:` 다국어
