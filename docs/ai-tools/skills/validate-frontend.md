# 프론트엔드 검증 (validate-frontend)

프론트엔드 레이아웃 JSON, 컴포넌트, **작업계획서 내 코드 블록**이 그누보드7 규정을 준수하는지 검증합니다.

> **단일 영역 검증 금지**: 한 작업 단위에서 백엔드와 프론트가 함께 변경되었다면 본 스킬만 실행하고 검증 완료를 선언하지 않는다. `/validate-backend` 도 함께 실행하거나 통합 메타 스킬 `/validate-branch` 를 사용한다.

## 0단계: 검증 대상 유형 판별 (CRITICAL)

```text
⚠️ CRITICAL: 검증 대상이 소스코드인지 작업계획서인지 먼저 판별
```

### 판별 기준

| 파일 확장자 | 유형             | 검증 방식                                |
| ----------- | ---------------- | ---------------------------------------- |
| `*.json`    | 소스코드         | 파일 전체를 레이아웃 JSON으로 검증       |
| `*.tsx`     | 소스코드         | 컴포넌트 코드로 검증 + 테스트 실행       |
| `*.md`      | **작업계획서**   | 마크다운 내 코드 블록 추출 후 검증       |

### 작업계획서 검증 시 추가 단계

작업계획서(`.md`) 파일인 경우:

1. **코드 블록 추출**: 마크다운에서 \`\`\`json, \`\`\`typescript, \`\`\`tsx 코드 블록 추출
2. **컨텍스트 파악**: 코드 블록 앞뒤 텍스트로 해당 코드의 용도 파악
3. **유형별 검증**: JSON → 레이아웃 검증, TypeScript → 핸들러/컴포넌트 검증

```text
⚠️ 작업계획서 검증 시 주의사항:
- 코드 블록이 예시/설명 목적인지, 실제 구현 계획인지 구분
- "예시:", "Example:", "// 예시" 등이 포함된 코드는 참고용으로 처리
- 실제 구현 계획 코드만 엄격하게 검증
```

## 0.1단계: 그누보드7 DevTools MCP 활용 (소스코드 검증 시 권장)

브라우저 상태 덤프가 가능한 경우, MCP 도구를 먼저 활용하여 런타임 상태를 확인합니다.

| 도구 | 검증 내용 |
|------|----------|
| `g7-state` | 현재 _global, _local 상태 값 |
| `g7-expressions` | 표현식 평가 경고, 잘못된 바인딩 |
| `g7-form` | Form 바인딩 상태, 누락된 name prop |
| `g7-actions` | 핸들러 실행 오류 |

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/frontend/components.md` (인덱스) - 컴포넌트 규칙
  - `components-types.md` - 기본/집합/레이아웃 컴포넌트
  - `components-patterns.md` - 순환 의존성, 다국어, skipBindingKeys
  - `components-advanced.md` - 이벤트 통신, 아이콘, 개발 체크리스트
- Font Awesome Free 6.4.x 아이콘 (Solid / Regular / Brands)
- `docs/frontend/layout-json.md` - 레이아웃 JSON 스키마
- `docs/frontend/layout-json-inheritance.md` - 레이아웃 상속
- `docs/frontend/data-binding.md` - 데이터 바인딩
- `docs/frontend/data-binding-i18n.md` - 다국어 바인딩
- `docs/frontend/data-sources.md` - 데이터 소스
- `docs/frontend/dark-mode.md` - 다크 모드 규칙
- `docs/frontend/responsive-layout.md` - 반응형 레이아웃
- `docs/frontend/state-management.md` - 상태 관리
- `docs/frontend/actions.md` - 액션 시스템
- `docs/frontend/actions-handlers.md` - 핸들러별 params, onSuccess/onError
- `docs/frontend/security.md` - 보안 규칙
- `docs/frontend/modal-usage.md` - 모달 사용 규칙
- `docs/frontend/layout-json-features-error.md` - 에러 핸들링
- `docs/frontend/component-props-composite.md` - Composite 컴포넌트 Props
- `docs/frontend/layout-json-components-loading.md` - 데이터 로딩 및 생명주기
- `docs/frontend/layout-json-components-slots.md` - 슬롯 시스템

## 2단계: 검증 대상 파일 읽기

$ARGUMENTS 경로의 파일을 읽습니다.

경로가 지정되지 않은 경우, 다음 파일들을 대상으로 합니다:

- `resources/layouts/**/*.json` - 코어 레이아웃
- `templates/**/layouts/**/*.json` - 템플릿 레이아웃
- `modules/**/resources/layouts/**/*.json` - 모듈 레이아웃
- `templates/**/src/components/**/*.tsx` - 컴포넌트 파일

## 3단계: 규정 기반 자동 검증 (CRITICAL)

```
⚠️ CRITICAL: 1단계에서 읽은 규정 문서의 모든 규칙을 검증 항목으로 사용합니다.
스킬에 하드코딩된 검증 항목이 아닌, 규정 문서가 Single Source of Truth입니다.
```

### ⭐ 검증 완료 전 필수 체크리스트 (MANDATORY)

```text
⚠️ CRITICAL: 아래 체크리스트를 모두 완료하기 전까지 검증 완료 선언 금지

□ 1. 규정 문서 읽기 완료 (섹션 1)
□ 2. 검증 대상 파일 읽기 완료 (섹션 2)
□ 3. 규정 기반 금지/필수 패턴 검증 완료 (섹션 3.1~3.4)
□ 4. ⭐⭐⭐ 규정 문서 기반 속성 검증 완료 - 필수 ⭐⭐⭐
   □ layout-json.md의 컴포넌트 정의 필드와 대조 완료
   □ actions.md의 내장 핸들러 목록과 대조 완료
   □ 작업계획서의 모든 속성이 규정에 정의된 것인지 확인 완료
□ 5. Font Awesome 아이콘 검증 완료 (섹션 3.6)
□ 6. 테스트 실행 검증 (해당 시) (섹션 4)
□ 7. 결과 보고서 작성 (섹션 5)

❌ 절대 금지: 규정 문서 대조 없이 검증 완료 선언
✅ 필수: 작업계획서의 모든 속성/핸들러가 규정 문서에 정의되어 있는지 대조
```

### 3.1 규정에서 검증 패턴 추출

1단계에서 읽은 모든 규정 문서에서 다음 패턴을 추출합니다:

| 추출 대상 | 검증 유형 |
|----------|----------|
| `❌` 또는 `잘못된` 키워드가 포함된 코드 블록 | **금지 패턴** - 발견 시 에러 |
| `✅` 또는 `올바른` 키워드가 포함된 코드 블록 | **필수 패턴** - 누락 시 경고 |
| `⚠️ 절대 금지`, `CRITICAL` 등 강조된 규칙 | **필수 검증 항목** |
| `TL;DR` 섹션의 핵심 포인트 | **우선 검증 항목** |

### 3.2 검증 방법

**금지 패턴 검증**:
```bash
# 규정 문서의 ❌ 예시에서 추출한 패턴을 grep으로 검사
grep -rn "[금지 패턴]" [대상 파일/디렉토리]
```

발견 시: 에러로 보고하고 규정 문서의 해당 섹션 참조 안내

**필수 패턴 검증**:
- 해당 컨텍스트에서 필수 패턴이 사용되어야 하는 경우, 누락 여부 확인
- 누락 시: 경고로 보고하고 규정 문서의 올바른 예시 안내

### 3.3 컴포넌트 소스코드 검증 (TSX 파일)

컴포넌트 파일(.tsx)이 검증 대상인 경우, 규정 문서에서 추출한 패턴으로 추가 검증:

1. **컴포넌트 소스코드 위치**:
   ```
   templates/[vendor-template]/src/components/
   ├── basic/          # 기본 컴포넌트
   ├── composite/      # 집합 컴포넌트
   └── layout/         # 레이아웃 컴포넌트
   ```

2. **컴포넌트 등록 검증** (규정: components.md "10. 컴포넌트 개발 체크리스트"):
   - `index.ts`에 export 등록 여부
   - `components.json`에 등록 여부
   - 테스트 파일 존재 여부

### 3.4 레이아웃 JSON Props 검증

레이아웃 JSON에서 사용된 컴포넌트의 props가 실제로 지원되는지 **컴포넌트 소스코드를 직접 분석**하여 검증합니다.

1. 레이아웃 JSON에서 사용된 컴포넌트명 추출
2. 해당 컴포넌트의 소스코드에서 Props 인터페이스 분석
3. 지원되지 않는 props 사용 시 에러 보고

### 3.5 규정 문서 기반 속성 검증

작업계획서의 모든 레이아웃 속성/핸들러가 **규정 문서에 정의**되어 있는지 대조 검증합니다.

```text
⚠️ CRITICAL: 규정 문서가 Single Source of Truth
✅ 규정 문서만으로 검증 가능 (소스코드 Read 불필요)
✅ 토큰 효율적, 컨텍스트 절약
```

| 검증 대상 | 규정 문서 | 확인 항목 |
|-----------|-----------|-----------|
| 레이아웃 최상위 필드 | `layout-json.md` > 필수 필드 | version, layout_name, meta, data_sources, components 등 |
| 컴포넌트 정의 필드 | `layout-json.md` > 컴포넌트 정의 | id, type, name, props, children, actions, text, iteration, if 등 |
| 고급 기능 | `layout-json-features.md` | classMap, computed, errorHandling, init_actions, modals, scripts |
| 반복/조건부 렌더링 | `layout-json-components.md` | iteration, blur_until_loaded, lifecycle, responsive |
| 액션 핸들러 | `actions.md` > 내장 핸들러 목록 | navigate, apiCall, setState, openModal 등 21개 |
| 액션 정의 필드 | `actions.md` > 액션 정의 구조 | type, event, handler, params, onSuccess, onError, confirm, key, debounce |
| 데이터 바인딩 | `data-binding.md` | {{expression}}, $t:key, $event, $args |
| 데이터 소스 | `data-sources.md` | id, endpoint, method, params, loading_strategy 등 |

### 3.6 빈번 실수 패턴 검증

레이아웃 작성 시 자주 발생하는 절대 금지 패턴을 검증합니다.

```text
⚠️ CRITICAL: 아래 항목은 빈번한 실수이므로 반드시 검증
```

#### 3.6.1 레이아웃 구조 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | type: "conditional" 사용 금지 | `"type": "conditional"` | `"if": "{{expression}}"` 속성 사용 |
| 2 | type: "iterator" 사용 금지 | `"type": "iterator"` | 실제 컴포넌트에 `"iteration": {...}` 속성 사용 |
| 3 | Partial 파일 최상위 속성 금지 | Partial에 computed, data_sources, modals, state 정의 | Partial은 컴포넌트 치환만 가능 |
| 4 | 부모-자식 data_sources ID 중복 | 동일 ID가 부모/자식 레이아웃에 존재 | 고유 ID 사용 필수 |
| 5 | globalHeaders 객체 형식 | `"globalHeaders": { "X-Key": "value" }` | `"globalHeaders": [{ "pattern": "*", "headers": {...} }]` 배열 형식 |
| 6 | 레이아웃 JSON 새 최상위 속성 | 새 속성 추가 후 백엔드 미동기화 | UpdateLayoutContentRequest 필수 수정 |
| 7 | children 문자열 직접 사용 | `"children": "텍스트"` | `"text": "텍스트"` 속성 사용 |

#### 3.6.2 핸들러/액션 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | navigate + replace:true | `handler: "navigate"` + `replace: true` (URL만 변경 시) | `handler: "replaceUrl"` |
| 2 | 잘못된 핸들러명 | `handler: "api"`, `handler: "nav"`, `handler: "setLocalState"` | `handler: "apiCall"`, `handler: "navigate"`, `handler: "setState"` + `target: "local"` |
| 3 | 표현식에서 핸들러 호출 | `{{handler()}}` | `actions: [{ handler: "xxx" }]` |
| 4 | openModal/closeModal params | `{ "handler": "openModal", "params": { "id": "xxx" } }` | `{ "handler": "openModal", "target": "xxx" }` |

#### 3.6.3 데이터 바인딩 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | onSuccess $response | `{{$response.xxx}}` | `{{response.xxx}}` ($ 접두사 없음) |
| 2 | 이벤트 값 접근 | `$value` | `$event.target.value` |
| 3 | Partial에서 props 접근 | `{{props.xxx}}` | data_sources ID 직접 참조 |
| 4 | 에러 데이터 접근 | `{{error.data}}` | `{{error.errors}}` |
| 5 | 배열 데이터 경로 | `{{products.data}}` | `{{products?.data?.data}}` (경로 확인) |
| 6 | fallback 없는 바인딩 | `{{value}}` | `{{value ?? ''}}` |

#### 3.6.4 반복 렌더링(iteration) 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | 변수명 | `"item"`, `"index"` | `"item_var"`, `"index_var"` |
| 2 | if와 iteration 순서 | if 순서 무시 | if가 iteration보다 먼저 평가됨 |

#### 3.6.5 상태 관리 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | setState params 키에 {{}} | `"params": { "{{key}}": "value" }` | 키는 정적 경로만 사용 |
| 2 | closeModal 후 setState | closeModal → setState 순서 | setState → closeModal 순서 |
| 3 | sortable 내 폼 자동바인딩 | sortable 내 폼 자동바인딩 그대로 | `parentFormContextProp={undefined}` |
| 4 | await 후 캡처된 상태 | await 후 캡처된 _local 사용 | `G7Core.state.getLocal()` 재조회 |

#### 3.6.6 컴포넌트 Props 금지 패턴

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | Icon size 설정 | `Icon className="w-4 h-4"` | `Icon size="sm"` 또는 `className="text-sm"` |
| 2 | Select 옵션 fallback | `options={{options}}` | `options={{options ?? []}}` |
| 3 | Form 내 Button type 누락 | Form 내 `Button` type 없음 | `type="button"` 명시 (submit 방지) |
| 4 | Select valueKey/labelKey | `valueKey`, `labelKey` props 사용 | computed로 `{ value, label }` 변환 |

#### 3.6.7 모듈 레이아웃 다국어 검증

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
|---|----------|----------|------------|
| 1 | 모듈 식별자 누락 | `$t:common.xxx` (모듈 레이아웃에서) | `$t:sirsoft-ecommerce.common.xxx` |

### 3.7 Font Awesome 아이콘 검증 (CRITICAL)

레이아웃 JSON 및 TSX 파일에서 사용된 아이콘이 **Font Awesome Free 버전**에 포함되어 있는지 검증합니다.

**검증 기준**: Font Awesome Free 6.4.x 공식 아이콘 목록 (Pro 라이선스 미사용)

#### 아이콘 추출 패턴

레이아웃 JSON에서:

```json
// Icon 컴포넌트의 name prop에서 아이콘명 추출
{
  "name": "Icon",
  "props": {
    "name": "fa-solid fa-user"  // ← 이 값 추출
  }
}
```

TSX 파일에서:

```typescript
// IconName enum 사용 또는 직접 문자열
<Icon name={IconName.User} />
<Icon name="fa-solid fa-check" />
```

#### 검증 규칙

1. **Pro 전용 스타일 사용 금지** (에러):
   - `fa-light`, `fal` - Light 스타일
   - `fa-thin`, `fat` - Thin 스타일
   - `fa-duotone`, `fad` - Duotone 스타일
   - `fa-sharp`, `fass`, `fasr`, `fasl` - Sharp 스타일

2. **Free 아이콘 목록 검증** (에러):
   - `fontawesome-free-icons.md`의 Solid/Regular/Brands 목록에 없는 아이콘 사용 시 에러
   - Regular 스타일은 163개만 Free (나머지는 Solid 사용 필요)

#### 아이콘명 추출 및 검증 방법 (CRITICAL)

```
⚠️ CRITICAL: Pro 스타일 검사만으로 충분하지 않음
✅ MANDATORY: 실제 아이콘명이 Free 목록에 존재하는지 반드시 검증
```

##### Step 1: 레이아웃 JSON에서 아이콘명 추출

```bash
# Icon 컴포넌트의 name prop에서 아이콘명 추출
grep -rhoP '"name":\s*"Icon"[^}]*"name":\s*"\K[^"]+' templates/[template]/layouts/**/*.json | sort -u
```

또는 JSON 구조를 분석하여 Icon 컴포넌트의 props.name 값을 추출합니다.

##### Step 2: TSX 파일에서 아이콘명 추출

```bash
# Icon 컴포넌트 사용 패턴에서 아이콘명 추출
grep -rhoP '<Icon[^>]*name=["'\'']\K[^"'\''"]+' templates/[template]/src/components/**/*.tsx | sort -u
grep -rhoP 'name:\s*["'\'']\K[^"'\''"]+' templates/[template]/src/components/**/*.tsx | sort -u
```

##### Step 3: 추출된 아이콘명을 Free 목록과 대조

1. `fontawesome-free-icons.md`에서 아이콘 목록 추출:
   - Solid 아이콘: `## Solid 아이콘 목록` 섹션의 `fa-*` 패턴
   - Regular 아이콘: `## Regular 아이콘 목록` 섹션의 `fa-*` 패턴
   - Brands 아이콘: `## Brands 아이콘 목록` 섹션의 `fa-*` 패턴

2. 추출된 각 아이콘명에 대해:
   - 아이콘명 파싱: `fa-solid fa-user` → 스타일=`solid`, 아이콘=`fa-user`
   - 아이콘명 파싱: `user` (단축형) → 스타일=`solid`(기본), 아이콘=`fa-user`
   - 해당 스타일의 Free 목록에 아이콘이 존재하는지 확인

3. 존재하지 않는 아이콘 발견 시:
   - 에러로 보고
   - 유사한 Free 아이콘 추천 (가능한 경우)

##### Step 4: iconNameMap 매핑 확인

Icon 컴포넌트에서 `iconNameMap`을 사용하는 경우, 매핑된 실제 아이콘명도 검증:

```typescript
// 예: "shopping-cart" → "fa-cart-shopping" 매핑 확인
// 매핑된 결과가 Free 목록에 있는지 검증
```

##### 검증 결과 보고 형식

```text
### Font Awesome 아이콘 검증
- ❌ Pro 전용 스타일 사용: "fa-light fa-user" (파일:라인)
  - 수정: "fa-solid fa-user" 또는 "fa-regular fa-user" 사용
- ❌ Free에 없는 아이콘: "fa-solid fa-plate-utensils" (파일:라인)
  - fontawesome-free-icons.md에서 대체 아이콘 검색 필요
- ✅ 모든 아이콘이 Free 버전에 포함됨
```

## 4단계: 테스트 실행 검증

컴포넌트 파일이 검증 대상인 경우, 테스트를 실행하여 통과 여부를 확인합니다.

```
⚠️ CRITICAL: Windows 환경에서 프론트엔드 테스트는 반드시 PowerShell 래퍼 사용
✅ 프로젝트 루트에서: powershell -Command "npm run test:run -- ComponentName"
✅ 템플릿 디렉토리에서: cd templates/sirsoft-admin_basic && powershell -Command "npm run test:run"
✅ _bundled 모듈에서: cd modules/_bundled/sirsoft-ecommerce && powershell -Command "npm run test:run"
```

## 5단계: 결과 보고

검증 결과를 다음 형식으로 보고합니다:

```
## 프론트엔드 검증 결과

### 검증 파일
- [파일 경로]

### [규정 문서명] - [섹션명] 검증 결과

#### 금지 패턴 검사
- ❌ 금지 패턴 발견: [패턴] (파일:라인)
  - 규정: [규정 문서명] > [섹션명]
  - 수정 방법: [올바른 패턴으로 변경]
- ✅ 금지 패턴 없음

#### 필수 패턴 검사
- ❌ 필수 패턴 누락: [컨텍스트]에서 [패턴] 필요
  - 규정: [규정 문서명] > [섹션명]
- ✅ 필수 패턴 준수

### 컴포넌트 등록 검증 (TSX 파일인 경우)
- ✅/❌ index.ts export 등록
- ✅/❌ components.json 등록
- ✅/❌ 테스트 파일 존재

### 테스트 실행 결과 (TSX 파일인 경우)
- ✅ 모든 테스트 통과 (X passed)
- ❌ 테스트 실패: [실패 메시지]
```

## 6단계: 레이아웃 기능 연동 검증 (신규 기능 추가 시)

새로운 레이아웃 JSON 기능(속성, 핸들러 등)이 추가된 경우, 다음 연동 항목도 반영되었는지 검증합니다.

### 6.1 검증 대상

템플릿 엔진에 새로운 기능이 추가된 경우:
- 새 액션 핸들러 (예: `debounce`, `filter` 등)
- 새 컴포넌트 속성 (예: `classMap`, `computed` 등)
- 새 데이터 바인딩 문법 (예: `$switch`, `$get`, 파이프 등)
- 새 데이터소스 옵션 (예: `if` 조건부 로딩 등)

### 6.2 연동 검증 항목

| 검증 항목 | 확인 위치 | 확인 방법 |
|----------|----------|----------|
| **DevTools 연동** | `resources/js/core/template-engine/devtools/G7DevToolsCore.ts` | 새 기능의 추적/디버깅 지원 여부 |
| **WYSIWYG 에디터 연동** | `resources/js/core/template-engine/wysiwyg/components/PropertyPanel/*.tsx` | 새 기능의 UI 편집기 지원 여부 |
| **규정 문서 업데이트** | `docs/frontend/*.md` | 새 기능 문서화 여부 |

### 6.3 연동 확인 방법

**DevTools 연동 확인**:
```bash
# 새 기능명으로 DevTools 코드 검색
grep -rn "[기능명]" resources/js/core/template-engine/devtools/
```

**WYSIWYG 에디터 연동 확인**:
```bash
# 새 기능명으로 WYSIWYG 코드 검색
grep -rn "[기능명]" resources/js/core/template-engine/wysiwyg/
```

### 6.4 보고 형식

```text
### 레이아웃 기능 연동 검증

#### 신규 기능: [기능명]
- ✅/❌ DevTools 연동: [추적 지원 여부]
- ✅/❌ WYSIWYG 에디터 연동: [UI 편집기 지원 여부]
- ✅/❌ 규정 문서 업데이트: [문서화 여부]

#### 누락 항목
- DevTools: [trackAction에 기능 정보 추가 필요]
- WYSIWYG: [PropertyPanel에 편집기 추가 필요]
```

---

## 7단계: 작업계획서 전용 검증 (마크다운 파일인 경우)

검증 대상이 작업계획서(`.md`)인 경우, 다음 추가 검증을 수행합니다.

### 7.1 코드 블록 추출 및 분류

마크다운 파일에서 코드 블록을 추출하고 유형별로 분류합니다:

| 코드 블록 언어         | 검증 유형                    |
| ---------------------- | ---------------------------- |
| \`\`\`json             | 레이아웃 JSON 검증           |
| \`\`\`typescript, ts   | 핸들러/타입 정의 검증        |
| \`\`\`tsx              | 컴포넌트 코드 검증           |
| \`\`\`php              | 백엔드 코드 검증 (필요시)    |

### 7.2 레이아웃 JSON 코드 블록 검증

작업계획서 내 JSON 코드 블록에 대해 다음을 검증합니다:

#### 7.2.1 컴포넌트 정의 구조 검증

소스코드 기반으로 `ComponentDefinition` 인터페이스와 일치하는지 확인:

```typescript
// resources/js/core/template-engine/DynamicRenderer.tsx
interface ComponentDefinition {
  id?: string;
  type: 'basic' | 'composite' | 'layout';
  name: string;
  props?: Record<string, any>;
  children?: ComponentDefinition[];
  actions?: ActionDefinition[];
  if?: string;
  iteration?: IterationConfig;
  responsive?: ResponsiveConfig;
  text?: string;
  slots?: Record<string, ComponentDefinition[]>;
}
```

**검증 항목**:
- ❌ `type`이 `basic`, `composite`, `layout` 외의 값인 경우
- ❌ `name`이 누락된 경우
- ❌ 지원되지 않는 최상위 속성 사용 (예: `class` 대신 `props.className`)

#### 7.2.2 컴포넌트 Props 검증

사용된 컴포넌트의 props가 실제 컴포넌트에서 지원되는지 검증:

1. 계획서에서 사용된 컴포넌트명 목록 추출
2. 각 컴포넌트의 소스코드에서 `Props` 인터페이스 확인
3. 지원되지 않는 props 사용 시 에러 보고

```text
검증 경로: templates/[vendor-template]/src/components/{type}/{Name}.tsx
```

#### 7.2.3 액션 정의 구조 검증

소스코드 기반으로 `ActionDefinition` 인터페이스와 일치하는지 확인:

```typescript
// resources/js/core/template-engine/ActionDispatcher.ts
interface ActionDefinition {
  type: string;  // 이벤트 타입 (click, change, submit 등)
  handler: string;  // 핸들러명
  params?: Record<string, any>;
  onSuccess?: ActionDefinition | ActionDefinition[];
  onError?: ActionDefinition | ActionDefinition[];
  actions?: ActionDefinition[];  // sequence 핸들러용
}
```

**검증 항목**:
- ❌ `type`이 누락된 경우
- ❌ `handler`가 누락된 경우
- ❌ 지원되지 않는 핸들러 사용 (actions.md 및 등록된 핸들러 확인)

#### 7.2.4 반응형 레이아웃 검증 (CRITICAL)

```text
⚠️ CRITICAL: Tailwind 반응형 클래스 대신 그누보드7 responsive 속성 사용 권장
```

**금지 패턴 검사**:
```json
// ❌ Tailwind 반응형 클래스로 show/hide
"props": { "className": "hidden md:block" }
"props": { "className": "block md:hidden" }

// ❌ Tailwind 반응형 클래스로 그리드 변경
"props": { "className": "grid grid-cols-1 md:grid-cols-2" }
```

**권장 패턴 안내**:
```json
// ✅ 그누보드7 responsive로 show/hide
"responsive": {
  "portable": { "if": "{{false}}" }
}

// ✅ 그누보드7 responsive로 그리드 변경
"props": { "className": "grid grid-cols-2 gap-4" },
"responsive": {
  "portable": {
    "props": { "className": "grid grid-cols-1 gap-4" }
  }
}
```

#### 7.2.5 데이터 바인딩 문법 검증

```text
✅ 지원되는 문법:
- {{expression}} - 데이터 바인딩
- {{obj?.prop}} - Optional chaining
- {{value ?? default}} - Nullish coalescing
- $t:key - 다국어 (즉시 평가)
- $t:defer:key - 다국어 (지연 평가)
- $event - 이벤트 데이터

❌ 지원되지 않는 문법 사용 시 에러
```

### 7.3 TypeScript/핸들러 코드 블록 검증

작업계획서 내 TypeScript 코드 블록에 대해 다음을 검증합니다:

#### 7.3.1 핸들러 등록 패턴 검증

```typescript
// ✅ 올바른 패턴
G7Core.handlers.register(`sirsoft-ecommerce.${name}`, handler);

// ❌ 잘못된 패턴
G7Core.handlers.register(name, handler);  // 네임스페이스 누락
```

#### 7.3.2 상태 관리 패턴 검증

```typescript
// ✅ 올바른 패턴
G7Core.state.setLocal({ form: {...form, field: value}, hasChanges: true });

// ❌ 잘못된 패턴 (직접 변경)
_local.form.field = value;
```

#### 7.3.3 API 호출 패턴 검증

```typescript
// ✅ G7Core.api 사용
const response = await G7Core.api.post('/api/...', data);

// ❌ fetch 직접 사용
const response = await fetch('/api/...');
```

### 7.4 작업계획서 검증 결과 보고 형식

```text
## 작업계획서 프론트엔드 검증 결과

### 검증 파일
- [마크다운 파일 경로]

### 코드 블록 요약
- JSON 코드 블록: X개
- TypeScript 코드 블록: Y개
- 총 검증 대상: Z개

### 레이아웃 JSON 검증

#### ComponentDefinition 구조 검증
- ✅ 모든 컴포넌트 정의가 유효함
- ❌ 잘못된 구조: [위치] - [문제 설명]

#### 컴포넌트 Props 검증
- ✅ 모든 props가 지원됨
- ❌ 지원되지 않는 prop: [컴포넌트명].[prop명] (코드 블록 위치)
  - 컴포넌트 소스: [소스 파일 경로]
  - 지원되는 props: [목록]

#### 액션 정의 검증
- ✅ 모든 핸들러가 유효함
- ❌ 지원되지 않는 핸들러: [핸들러명] (코드 블록 위치)
  - 참조: actions.md 또는 등록된 커스텀 핸들러 확인

#### 반응형 레이아웃 검증
- ✅ 그누보드7 responsive 패턴 준수
- ⚠️ Tailwind 반응형 클래스 사용: [위치]
  - 현재: "className": "[Tailwind 클래스]"
  - 권장: responsive 속성 사용

#### 데이터 바인딩 검증
- ✅ 모든 바인딩 문법 유효
- ❌ 지원되지 않는 문법: [위치] - [문법]

### TypeScript 핸들러 검증
- ✅ 핸들러 등록 패턴 준수
- ❌ 네임스페이스 누락: [위치]
- ✅ 상태 관리 패턴 준수
- ❌ 직접 상태 변경: [위치]
```

---

## 핵심 원칙

```text
⚠️ CRITICAL:
- 규정 문서가 Single Source of Truth - 스킬에 검증 항목 하드코딩 금지
- 규정 문서의 ❌/✅ 예시를 자동으로 검증 패턴으로 사용
- 규정이 변경되면 검증도 자동으로 변경됨
- 소스코드 인터페이스 분석으로 실제 지원 여부 확인
- 레이아웃 기능 추가 시 DevTools와 WYSIWYG 에디터 연동도 함께 검증
- 작업계획서(.md) 검증 시 코드 블록 추출 후 동일한 규정 적용
```
