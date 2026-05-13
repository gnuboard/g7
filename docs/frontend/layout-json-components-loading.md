# 레이아웃 JSON - 데이터 로딩 및 생명주기

> **메인 문서**: [layout-json-components.md](layout-json-components.md)
> **관련 문서**: [data-sources.md](data-sources.md) | [state-management.md](state-management.md)

---

## 목차

1. [데이터 로딩 중 Blur 효과 (blur_until_loaded)](#데이터-로딩-중-blur-효과-blur_until_loaded)
2. [페이지 전환 오버레이 (transition_overlay)](#페이지-전환-오버레이-transition_overlay)
3. [컴포넌트 생명주기 (lifecycle)](#컴포넌트-생명주기-lifecycle)

---

## 데이터 로딩 중 Blur 효과 (blur_until_loaded)

**목적**: 데이터 로딩 중 또는 특정 상태에서 컴포넌트에 blur 효과를 적용하여 로딩 상태를 시각적으로 표시합니다.

### blur 핵심 원칙

```text
✅ 필수: 컴포넌트에 blur_until_loaded 설정
✅ 지원: boolean 또는 표현식 문자열 (engine-v1.5.0+)
✅ 자동: 페이지 전환 및 초기 로딩 시 blur 효과 적용 (boolean true인 경우)
✅ 자동: 표현식이 truthy일 때 blur 효과 적용 (표현식 문자열인 경우)
```

### 사용법

#### 1. Boolean 값 (기본)

데이터 소스 로딩 중 또는 페이지 전환 중 blur 효과 적용:

```json
{
  "id": "users_data_grid",
  "type": "composite",
  "name": "DataGrid",
  "blur_until_loaded": true,
  "props": {
    "data": "{{users.data.data}}"
  }
}
```

#### 2. 표현식 문자열 (engine-v1.5.0+)

특정 상태 값에 따라 blur 효과 적용:

```json
{
  "id": "menu_form",
  "type": "basic",
  "name": "Form",
  "blur_until_loaded": "{{_global.isSaving}}",
  "props": {
    "id": "menu_form"
  }
}
```

**사용 시나리오**:
- 폼 저장 중 blur 처리: `"{{_global.isSaving}}"`
- 데이터 처리 중 blur: `"{{_local.isProcessing}}"`
- 조건부 blur: `"{{_global.isLoading || _global.isSaving}}"`

#### 3. 객체 형태 (engine-v1.6.0+)

특정 데이터 소스에 따라 개별 컴포넌트의 blur 효과를 제어:

```json
{
  "id": "stats_widget",
  "type": "composite",
  "name": "StatCard",
  "blur_until_loaded": {
    "enabled": true,
    "data_sources": "dashboard_stats"
  },
  "props": {
    "data": "{{dashboard_stats.data}}"
  }
}
```

**객체 필드 설명**:

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `enabled` | boolean | ✅ | blur 효과 활성화 여부 |
| `data_sources` | string \| string[] | ❌ | blur 해제 조건으로 사용할 데이터 소스 ID |

**data_sources 사용 패턴**:

```json
// 단일 데이터 소스
"blur_until_loaded": {
  "enabled": true,
  "data_sources": "dashboard_stats"
}

// 다중 데이터 소스 (모든 소스가 로드되어야 blur 해제)
"blur_until_loaded": {
  "enabled": true,
  "data_sources": ["users", "roles"]
}
```

**실제 사용 예시 (대시보드 위젯)**:

```json
{
  "data_sources": [
    { "id": "dashboard_stats", "endpoint": "/api/admin/dashboard/stats", "loading_strategy": "progressive" },
    { "id": "dashboard_resources", "endpoint": "/api/admin/dashboard/resources", "loading_strategy": "progressive" },
    { "id": "dashboard_activities", "endpoint": "/api/admin/dashboard/activities", "loading_strategy": "progressive" }
  ],
  "components": [
    {
      "id": "stats_widget",
      "type": "composite",
      "name": "StatCard",
      "blur_until_loaded": { "enabled": true, "data_sources": "dashboard_stats" },
      "props": { "data": "{{dashboard_stats.data}}" }
    },
    {
      "id": "resources_widget",
      "type": "composite",
      "name": "ResourceCard",
      "blur_until_loaded": { "enabled": true, "data_sources": "dashboard_resources" },
      "props": { "data": "{{dashboard_resources.data}}" }
    },
    {
      "id": "activities_widget",
      "type": "composite",
      "name": "ActivityList",
      "blur_until_loaded": { "enabled": true, "data_sources": "dashboard_activities" },
      "props": { "data": "{{dashboard_activities.data}}" }
    }
  ]
}
```

이 패턴을 사용하면 각 위젯이 자신의 데이터 소스가 로드될 때 **개별적으로** blur가 해제됩니다.

### 값 타입별 동작

| 값 타입 | 예시 | 동작 |
|--------|------|------|
| `true` (boolean) | `blur_until_loaded: true` | 데이터 소스 로딩 중 또는 페이지 전환 중 blur |
| `false` (boolean) | `blur_until_loaded: false` | blur 미적용 |
| 표현식 문자열 | `"{{_global.isSaving}}"` | 표현식 결과가 truthy일 때 blur |
| 객체 (engine-v1.6.0+) | `{ enabled: true, data_sources: "users" }` | 지정된 데이터 소스가 undefined일 때 blur |

### Blur 적용 조건

#### Boolean `true`인 경우

다음 조건 중 **하나라도** 충족되면 blur 효과가 적용됩니다:

| 조건 | 설명 | 시나리오 |
|------|------|----------|
| `isTransitioning === true` | 페이지 전환 중 | 검색, 페이지네이션, 다른 페이지 이동 |
| 데이터 소스가 `undefined` | 초기 로딩 시 데이터 미로드 | 페이지 첫 접속, 새로고침 |

#### 표현식 문자열인 경우

표현식 결과가 **truthy**이면 blur 효과가 적용됩니다:

| 표현식 | 결과 | Blur 적용 |
|--------|------|----------|
| `"{{_global.isSaving}}"` (isSaving = true) | `true` | ✓ |
| `"{{_global.isSaving}}"` (isSaving = false) | `false` | ✗ |
| `"{{_global.isLoading \|\| _global.isSaving}}"` | 둘 중 하나라도 true면 true | 조건부 |

#### 객체 형태인 경우 (engine-v1.6.0+)

`enabled`가 `true`이고, 지정된 `data_sources` 중 **하나라도** `undefined`이면 blur 효과가 적용됩니다:

| 설정 | 조건 | Blur 적용 |
|------|------|----------|
| `{ enabled: true, data_sources: "users" }` | `users === undefined` | ✓ |
| `{ enabled: true, data_sources: "users" }` | `users !== undefined` | ✗ |
| `{ enabled: true, data_sources: ["users", "roles"] }` | 둘 중 하나라도 undefined | ✓ |
| `{ enabled: false, data_sources: "users" }` | - | ✗ (비활성화) |

**장점**: 여러 위젯이 각각 다른 데이터 소스를 참조할 때, 각 위젯이 **독립적으로** blur 해제됩니다.

### 동작 시나리오

```text
1. 초기 페이지 로딩
   - 데이터 소스(예: users)가 undefined
   - blur 효과 적용 ✓
   - API 응답 수신 후 blur 해제

2. 페이지네이션/검색 (navigate 호출)
   - isTransitioning = true
   - blur 효과 적용 ✓
   - 데이터 로드 완료 후 blur 해제

3. 다른 페이지에서 돌아옴
   - navigate 호출 → isTransitioning = true
   - blur 효과 적용 ✓
   - 데이터 로드 완료 후 blur 해제

4. 데이터 로드 완료 상태
   - isTransitioning = false
   - 모든 데이터 소스 로드됨
   - blur 효과 미적용
```

### 실제 사용 예시

```json
{
  "version": "1.0.0",
  "layout_name": "admin_user_list",
  "data_sources": [
    {
      "id": "users",
      "endpoint": "/api/admin/users",
      "loading_strategy": "progressive"
    }
  ],
  "components": [
    {
      "id": "users_data_grid",
      "type": "composite",
      "name": "DataGrid",
      "blur_until_loaded": true,
      "props": {
        "data": "{{users.data.data}}",
        "columns": [...]
      }
    }
  ]
}
```

### 적용되는 CSS

```css
/* blur 효과가 적용될 때 */
.opacity-50.blur-sm.transition-all.duration-300.pointer-events-none
```

- `opacity-50`: 50% 투명도
- `blur-sm`: 약간의 blur 효과
- `transition-all duration-300`: 0.3초 전환 애니메이션
- `pointer-events-none`: 클릭 이벤트 비활성화

### 주의사항

- ✅ `progressive` 또는 `background` loading_strategy와 함께 사용 권장
- ✅ 데이터 테이블, 그리드 등 데이터 의존 컴포넌트에 적합
- `blocking` loading_strategy는 렌더링 전에 데이터를 로드하므로 blur가 보이지 않을 수 있음
- 레이아웃 JSON 수정 후 DB 동기화 필요 (시더 재실행 또는 관리자 화면에서 업데이트)

---

## 페이지 전환 오버레이 (transition_overlay)

> **엔진 버전**: engine-v1.23.0+
> **레벨**: 레이아웃 (컴포넌트 레벨의 `blur_until_loaded`와 독립)

**목적**: 페이지 전환(라우트 변경) 시 순수 DOM 조작으로 오버레이를 표시하여, React 18 비동기 렌더링 중 이전 페이지의 DOM이 잠깐 보이는 stale flash를 방지합니다.

### 핵심 원칙

```text
✅ 레이아웃 레벨 설정 (베이스 레이아웃에서 한 번 설정하면 모든 하위 레이아웃에 적용)
✅ 순수 DOM 조작 — React 렌더 사이클과 독립적 (동기, 즉시 표시)
✅ 모든 경로(progressive/non-progressive/취소/에러)에서 오버레이 자동 정리
✅ 다크 모드 자동 감지
```

### transition_overlay 사용법

#### 1. 축약 (기본 opaque 스타일)

```json
{
    "transition_overlay": true,
    "components": [...]
}
```

#### 2. 상세 설정

```json
{
    "transition_overlay": {
        "enabled": true,
        "style": "blur"
    },
    "components": [...]
}
```

#### 3. 타겟 컨테이너 지정

특정 영역만 오버레이로 가리려면 `target`에 컴포넌트 ID를 지정합니다.

```json
{
    "transition_overlay": {
        "enabled": true,
        "target": "main_content_area"
    },
    "components": [...]
}
```

| 속성 | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `enabled` | `boolean` | `false` | 전환 오버레이 활성화 |
| `style` | `"opaque" \| "blur" \| "fade" \| "skeleton" \| "spinner"` | `"opaque"` | 오버레이 스타일 |
| `target` | `string` | - | 오버레이 대상 요소 ID. 미지정 시 전체 화면 |
| `wait_for` | `string[]` | - | 명시된 데이터소스 fetch 완료까지 오버레이 유지 (engine-v1.34.0+) |

### wait_for — 데이터소스 가드 (engine-v1.34.0+)

`blocking` 데이터소스가 없는 페이지에서도 명시된 progressive 데이터소스가 fetch 완료될 때까지 오버레이가 표시되도록 한다. 베이스 레이아웃에 spinner 설정을 두고, 자식 레이아웃이 `wait_for` 만 추가하는 패턴이 권장된다.

```json
// 베이스 레이아웃 (_admin_base.json)
{
    "transition_overlay": {
        "enabled": true,
        "style": "spinner",
        "target": "main_content",
        "spinner": { "component": "PageLoading" }
    }
}

// 자식 레이아웃 (admin_settings.json) — wait_for 만 명시
{
    "extends": "_admin_base",
    "transition_overlay": {
        "wait_for": ["settings"]
    }
}
```

`LayoutService` 가 `transition_overlay` 를 shallow merge 하므로 자식의 `wait_for` 만 추가되고 베이스의 `enabled/style/target/spinner` 설정이 보존된다.

#### 호환되는 loading_strategy

| loading_strategy | wait_for 동작 | 비고 |
| ---------------- | ------------- | ---- |
| `blocking` | step 2.5 에서 spinner 자동 트리거. wait_for 명시는 중복이지만 무해 | 명시 불필요 |
| `progressive` (default) | wait_for 의 핵심 사용처 — fetch 완료까지 오버레이 유지 | 권장 |
| `background` | **검증 단계에서 차단됨** — "사용자 차단 없음" 의도와 충돌 | UpdateLayoutContentRequest 422 |
| `websocket` | **검증 단계에서 차단됨** — fetch 완료 이벤트 없음 | UpdateLayoutContentRequest 422 |

#### 트리거/해제 시점

- **트리거 1 — 페이지 진입**: handleRouteChange step 2.5 에서 `wait_for` 에 명시된 ID 중 progressive/blocking 데이터소스가 1개 이상 존재하면 오버레이 표시
- **트리거 2 — 탭 전환/페이지네이션** (engine-v1.35.0+): `navigate replace:true` 로 `updateQueryParams` 경로에 진입할 때도 동일 조건으로 오버레이 표시. handleRouteChange 와 동일 정책으로 `wait_for` 검사 + 오버레이 호출
- **해제**: 모든 progressive 데이터소스 fetch 완료 시 (hideTransitionOverlay 호출)
- **존재하지 않는 ID**: 엔진이 자동 무시 (백엔드 검증과 별개로 런타임 안전 가드)

#### 동적 target override (engine-v1.36.0+)

`replace: true` navigate 의 `params.transition_overlay_target` 으로 호출별 target 을 override 할 수 있다. 탭 속 서브 탭(환경설정 > 알림 탭 > 채널 탭 등)에서 서브 탭 콘텐츠 영역에만 spinner 를 표시하고 싶을 때 사용.

```json
// 상위 탭 전환: 베이스 transition_overlay.target 사용 (settings_tab_content)
{ "handler": "navigate", "params": { "path": "/admin/settings", "replace": true, "query": { "tab": "notification_definitions" } } }

// 채널 서브 탭 전환: 채널 콘텐츠 영역에만 spinner
{
  "handler": "navigate",
  "params": {
    "path": "/admin/settings",
    "replace": true,
    "mergeQuery": true,
    "query": { "channel": "{{ch.id}}" },
    "transition_overlay_target": "notif_channel_content"
  }
}
```

`transition_overlay_target` 미존재 시 레이아웃의 `transition_overlay.target` 사용. 해당 ID DOM 요소 미발견 시 `fallback_target` → `#app` 3단계 폴백.

> 상세: [actions-handlers-navigation.md#transition_overlay_target-옵션-engine-v1360](actions-handlers-navigation.md#transition_overlay_target-옵션-engine-v1360)

#### refetchDataSource 와의 관계

`refetchDataSource` 핸들러로 단독 데이터를 다시 부르는 경우는 `wait_for` 트리거 대상이 아니다. 이 경우 컴포넌트 단위 `blur_until_loaded` 의 객체 형태(`{ enabled: true, data_sources: ["xxx"] }`)로 처리한다 — 페이지 단위 vs 컴포넌트 단위의 역할 분리를 유지한다.

`target` 지정 시 CSS `<style>` 태그를 `<head>`에 주입하여 타겟 요소에 `::after` 의사 요소로 오버레이를 생성합니다. 타겟 요소에 `position:relative; z-index:0`을 설정하여 명시적 stacking context를 생성하므로, `::after`가 타겟 내부에 갇히고 헤더/네비게이션 등 형제 요소를 절대 가리지 않습니다. `target` 미지정 시 `document.body`에 `position:fixed` div를 삽입하는 폴백 방식을 사용합니다.

> **핵심**: `<style>` 태그는 React 렌더 트리 외부(`<head>`)에 삽입되므로, `renderTemplate()` 시 DOM 교체에도 소멸하지 않습니다. CSS 규칙은 셀렉터 기반으로 새로 렌더된 DOM에도 자동 적용됩니다.

### 스타일 옵션

| style | 효과 | 설명 |
| ----- | ---- | ---- |
| `"opaque"` (기본) | 완전 불투명 배경 | 이전 페이지가 전혀 보이지 않음 (권장) |
| `"blur"` | `backdrop-blur` + 반투명 배경 | 이전 페이지가 흐리게 보임 |
| `"fade"` | 80% 반투명 배경 | 이전 페이지가 약간 보임 |
| `"skeleton"` | 동적 스켈레톤 UI | 레이아웃 구조 미리보기 (engine-v1.24.0+) |
| `"spinner"` | 커스텀 로딩 컴포넌트 | 스피너 등 간결한 로딩 표시 (engine-v1.29.0+) |

### blur_until_loaded와의 차이

| 구분 | `transition_overlay` | `blur_until_loaded` |
| ---- | -------------------- | ------------------- |
| 레벨 | 레이아웃 (전체 페이지) | 컴포넌트 (개별 위젯) |
| 메커니즘 | 순수 DOM 조작 | React Context + CSS |
| 트리거 | 레이아웃 전환 시 | 데이터 소스 로딩 / 표현식 평가 |
| 타이밍 | React 렌더 이전 (동기) | React 렌더 이후 (비동기) |
| 관계 | 상호 보완 (순차 동작) | 상호 보완 (순차 동작) |

### 동작 흐름

```text
사용자 클릭 → handleRouteChange()
  ① layout API fetch → layoutData.transition_overlay 확인
  ② blocking 데이터 로드
  ③ showTransitionOverlay() → DOM에 #g7-transition-overlay 삽입 (동기)
  ④ renderTemplate() → React 비동기 렌더 (오버레이가 stale DOM을 가림)
  ⑤-A progressive 데이터 있음 → 로드 완료 → hideTransitionOverlay()
  ⑤-B progressive 데이터 없음 → renderTemplate 리턴 후 즉시 hideTransitionOverlay()
```

### 주의사항

- `transition_overlay` 미설정 레이아웃에는 영향 없음 (`if` 가드)
- `target` 지정 시: `::after` 의사 요소의 `z-index: 2147483647`로 타겟 내부 콘텐츠만 덮음
- `target` 미지정 시: DOM 오버레이의 `z-index: 9999`로 전체 화면 덮음
- `pointer-events: none`으로 사용자 입력을 차단하지 않음
- DOM 오버레이 폴백에만 `aria-hidden="true"` 적용 (CSS 주입 방식은 DOM 요소 없음)

### skeleton 스타일 — 동적 스켈레톤 UI (engine-v1.24.0+)

`style: "skeleton"`을 지정하면, CSS 오버레이 대신 레이아웃 JSON의 컴포넌트 트리를 파싱하여 **동적 스켈레톤 UI**를 렌더링합니다.

#### 설정 예시

```json
{
    "transition_overlay": {
        "enabled": true,
        "style": "skeleton",
        "target": "main_content_area",
        "skeleton": {
            "component": "PageSkeleton",
            "animation": "pulse",
            "iteration_count": 5
        }
    }
}
```

#### skeleton 옵션

| 속성 | 타입 | 필수 | 기본값 | 설명 |
| ---- | ---- | ---- | ------ | ---- |
| `component` | string | ✅ | - | 스켈레톤 렌더러 컴포넌트 이름 (레지스트리에 등록 필수) |
| `animation` | string | - | `"pulse"` | 애니메이션 타입: `"pulse"` / `"wave"` / `"none"` |
| `iteration_count` | number | - | `5` | iteration 블록의 기본 반복 횟수 |

#### 동작 원리

```text
1. Navigation → Layout JSON fetch (캐시 or API)
2. style === "skeleton" 감지
3. ComponentRegistry에서 skeleton.component 조회
4. 레이아웃의 components 트리 + options를 props로 전달
5. 스켈레톤 컴포넌트가 target 영역 위에 React로 렌더링
6. Blocking data fetch 완료 → renderTemplate() 호출
7. hideTransitionOverlay() → 스켈레톤 컨테이너 제거
```

#### 스켈레톤 컴포넌트 Props 인터페이스

엔진이 스켈레톤 컴포넌트에 전달하는 props:

```typescript
interface SkeletonComponentProps {
    /** 레이아웃 JSON의 components 배열 (전체 컴포넌트 트리) */
    components: LayoutComponent[];
    /** 스켈레톤 옵션 */
    options: {
        animation: 'pulse' | 'wave' | 'none';
        iteration_count: number;
    };
}
```

#### 스켈레톤 미표시 조건

| 조건 | 처리 |
|------|------|
| `skeleton.component`가 레지스트리에 미등록 | opaque 스타일 자동 폴백 |
| `target` 요소 미존재 | opaque 스타일 자동 폴백 |
| 모든 blocking 데이터 캐시 히트 | 스켈레톤 표시 시간이 매우 짧음 (즉시 교체) |

#### 템플릿 개발자 가이드

스켈레톤 컴포넌트 구현 시 고려사항:

- props로 받은 컴포넌트 트리를 **재귀 순회**하여 스켈레톤 생성
- 레이아웃 컨테이너(Div, Flex, Grid 등)는 `className` 유지하며 자식 순회
- 콘텐츠 컴포넌트(H1, Input, Button 등)는 회색 플레이스홀더로 대체
- `iteration` 블록은 `iteration_count`만큼 반복 생성
- Modal, Toast 등 팝업 컴포넌트는 빈 Fragment로 처리
- 접근성: `role="status"`, `aria-busy="true"` 적용 권장
- 다크 모드: `dark:` variant 클래스 적용 필수

### spinner 스타일 — 커스텀 로딩 컴포넌트 (engine-v1.29.0+)

`style: "spinner"`를 지정하면, skeleton 대신 **커스텀 로딩 컴포넌트**(스피너 등)를 렌더링합니다. skeleton이 레이아웃 트리를 파싱하여 복잡한 스켈레톤 UI를 생성하는 반면, spinner는 단순한 로딩 인디케이터를 표시합니다.

#### 설정 예시

```json
{
    "transition_overlay": {
        "enabled": true,
        "style": "spinner",
        "target": "main_content_area",
        "spinner": {
            "component": "PageLoading"
        }
    }
}
```

#### 3단계 fallback chain (skeleton과 동일)

```json
{
    "transition_overlay": {
        "enabled": true,
        "style": "spinner",
        "target": "mypage_tab_content",
        "fallback_target": "main_content_area",
        "spinner": {
            "component": "PageLoading"
        }
    }
}
```

#### spinner 옵션

| 속성 | 타입 | 필수 | 기본값 | 설명 |
| ---- | ---- | ---- | ------ | ---- |
| `component` | string | - | - | 로딩 컴포넌트 이름 (레지스트리에 등록 필수). 미지정 시 기본 스피너 |
| `text` | string | - | - | 로딩 텍스트 (컴포넌트의 `options.text`로 전달) |

#### 로딩 컴포넌트 Props 인터페이스

엔진이 로딩 컴포넌트에 전달하는 props:

```typescript
interface LoadingComponentProps {
    options?: {
        text?: string;
    };
}
```

#### 동작 원리

```text
1. Navigation → Layout JSON fetch (캐시 or API)
2. style === "spinner" 감지
3. CSS ::after 주입으로 이전 콘텐츠 즉시 가림 (Step 1)
4-A. spinner.component 지정 시: ComponentRegistry에서 조회 → React flushSync 렌더링
4-B. spinner.component 미지정 시: 순수 DOM 기반 기본 CSS 스피너 생성
5. Blocking data fetch 완료 → renderTemplate() 호출
6. hideTransitionOverlay() → 스피너 컨테이너 + CSS 스타일 제거
```

#### spinner vs skeleton 비교

| 구분 | `spinner` | `skeleton` |
| ---- | --------- | ---------- |
| 용도 | 간결한 로딩 인디케이터 | 레이아웃 구조 미리보기 |
| 컴포넌트 트리 필요 | ❌ (옵션만 전달) | ✅ (components 배열 전달) |
| 컴포넌트 미지정 시 | 기본 CSS 스피너 폴백 | opaque 스타일 폴백 |
| 다국어 처리 | 컴포넌트 내부에서 `G7Core.t()` 사용 | 해당 없음 |

---

## 컴포넌트 생명주기 (lifecycle)

**목적**: 컴포넌트 마운트/언마운트 시점에 실행할 핸들러를 정의합니다.

### actions vs lifecycle

| 속성 | 역할 | 트리거 시점 | 예시 |
|------|------|------------|------|
| `actions` | 이벤트 기반 핸들러 | click, change, submit 등 | 버튼 클릭, 폼 제출 |
| `lifecycle` | 생명주기 핸들러 | 마운트/언마운트 | 초기 데이터 로드, 정리 작업 |

### 구조

```json
{
  "id": "user_form",
  "type": "composite",
  "name": "Form",
  "lifecycle": {
    "onMount": [
      {
        "type": "click",
        "handler": "loadSavedData",
        "target": "{{_local.formId}}"
      }
    ],
    "onUnmount": [
      {
        "type": "click",
        "handler": "cleanup"
      }
    ]
  },
  "actions": [
    {
      "type": "submit",
      "handler": "submitForm"
    }
  ]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `onMount` | array | ❌ | 컴포넌트 마운트 시 실행할 액션 목록 |
| `onUnmount` | array | ❌ | 컴포넌트 언마운트 시 실행할 액션 목록 |

각 액션은 `actions` 배열과 동일한 구조를 따릅니다.

### 사용 사례

- **onMount**: 초기 데이터 로드, 이벤트 리스너 등록, 분석 이벤트 전송
- **onUnmount**: 리소스 정리, 이벤트 리스너 해제, 타이머 정리

### 접근 가능한 데이터

lifecycle 핸들러에서는 다음 데이터에 접근할 수 있습니다:

- `{{route.id}}`, `{{route.slug}}` - URL 라우트 파라미터
- `{{query.page}}`, `{{query.filter}}` - URL 쿼리 파라미터
- `{{_global.xxx}}` - 전역 상태
- `{{_local.xxx}}` - 컴포넌트 로컬 상태
- 데이터 소스 결과

### 실제 사용 예시

```json
{
  "id": "analytics_wrapper",
  "type": "basic",
  "name": "Div",
  "lifecycle": {
    "onMount": [
      {
        "type": "click",
        "handler": "trackPageView",
        "params": {
          "page": "{{route.layout}}",
          "userId": "{{_global.user.id}}"
        }
      }
    ]
  },
  "children": [...]
}
```

### init_actions와의 차이점

| 구분 | init_actions | lifecycle.onMount |
|------|-------------|-------------------|
| 범위 | 레이아웃 레벨 | 컴포넌트 레벨 |
| 실행 시점 | 레이아웃 렌더링 직후 | 해당 컴포넌트 마운트 시 |
| 데이터 접근 | route, query, _global, blocking | route, query, _global, _local, 모든 데이터 |
| 사용 목적 | 테마 초기화, 전역 설정 | 컴포넌트별 초기화 |

### 주의사항

- ✅ lifecycle 핸들러는 템플릿의 `handlerMap`에 등록되어 있어야 함
- ✅ onMount 액션은 순차적으로 실행됨
- ✅ onUnmount는 컴포넌트가 DOM에서 제거될 때 실행됨
- ❌ React Strict Mode에서 onMount가 두 번 호출될 수 있음 (개발 환경)

---

## _seo 컨텍스트 네임스페이스

> **버전**: SeoRenderer에서 주입

SEO 변수 시스템이 활성화된 레이아웃에서, SeoRenderer가 확장 설정 SEO 템플릿을 해석한 결과를 `_seo` 네임스페이스에 주입합니다.

### 구조

```text
_seo.{page_type}.title       — 해석된 SEO 제목
_seo.{page_type}.description — 해석된 SEO 설명
```

### 사용법

`_seo` 컨텍스트는 `og`, `structured_data`, 그리고 모든 표현식 영역에서 참조할 수 있습니다.

```json
"og": {
    "title": "{{_seo.product.title ?? product.data.name ?? ''}}",
    "description": "{{_seo.product.description ?? product.data.short_description ?? ''}}"
},
"structured_data": {
    "@type": "Product",
    "name": "{{_seo.product.title ?? product.data.name ?? ''}}"
}
```

### 동작 조건

- `meta.seo.extensions`에 확장이 선언되어 있어야 함
- 해당 확장의 `seoVariables()`에서 변수가 정의되어 있어야 함
- 확장 설정에 `seo.meta_{page_type}_title` / `seo.meta_{page_type}_description` 템플릿이 존재해야 함
- 조건 미충족 시 `_seo.{page_type}` 값이 `undefined` → fallback 표현식(예: `?? product.data.name ?? ''`) 사용 권장

> 상세: [seo-system.md](../backend/seo-system.md) "SEO 변수 시스템" 참조

---

## 관련 문서

- [레이아웃 JSON 컴포넌트 인덱스](layout-json-components.md)
- [조건부/반복 렌더링](layout-json-components-rendering.md)
- [슬롯 시스템](layout-json-components-slots.md)
- [데이터 소스](data-sources.md)
