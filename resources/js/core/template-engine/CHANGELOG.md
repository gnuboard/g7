# Template Engine Changelog

> 이 문서는 그누보드7 템플릿 엔진의 내부 개발 버전 이력입니다.
> `engine-v1.x.x` 버전은 그누보드7 릴리스 버전과 독립적입니다.
>
> 형식: [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)

## [engine-v1.29.2]

### Fixed

- (engine-v1.29.2) `if` 조건에서 `{{true}}`, `{{false}}`, `{{null}}`, `{{undefined}}` 리터럴이 컨텍스트 경로로 해석되어 항상 undefined 반환되는 버그 수정 (ConditionEvaluator, RenderHelpers)
  - 원인: `isComplexExpression` 체크에서 연산자 없는 단순 문자열로 판정 → `resolve("true")` → `context["true"]` → `undefined`
  - 영향 범위: `evaluateStringCondition` (ConditionEvaluator) + `evaluateIfCondition` (RenderHelpers) 양쪽에 동일 버그 존재
  - 수정: ConditionEvaluator에 `resolveLiteral()`, RenderHelpers에 `LITERALS` Map 추가 — `resolve()` 호출 전에 JS 리터럴을 직접 반환
  - SEO 엔진(`ExpressionEvaluator.php`)은 이미 리터럴 체크가 `resolvePath()` 전에 있어 수정 불필요

## [engine-v1.29.1]

### Fixed

- (engine-v1.29.1) 에러 페이지(404 등)에서 로그인 상태가 표시되지 않는 버그 수정 (ErrorPageHandler)
  - 원인: `renderError()`가 data_sources fetch 후 `initGlobal`/`initLocal` 처리를 하지 않아 `_global.currentUser`가 undefined로 남음
  - 수정: `processInitOptions()` 메서드 추가 — fetch된 데이터의 `initGlobal`/`initLocal` 옵션을 처리하여 `_global`/`_local` 상태에 매핑
  - 지원 형태: 문자열(`"currentUser"`), 배열(`["user", "profile"]`), 객체(`{ key, path }`)

## [engine-v1.29.0]

### Added

- transition_overlay `spinner` 스타일 — 커스텀 로딩 컴포넌트 지정 가능 (TemplateApp)
  - 엔진은 빈 컨테이너 + position:relative CSS만 제공 — 비주얼 스타일은 컴포넌트가 전적으로 결정
  - 3단계 fallback chain: target → fallback_target → #app (fullpage)
  - `spinner.component`: 컴포넌트 레지스트리에서 로딩 컴포넌트 조회 (예: PageLoading)
  - 컴포넌트 미지정 시 CSS 커스텀 속성 기반 기본 스피너 폴백
  - renderTemplate 후 `reattachSpinnerOverlay()`로 새 DOM 타겟에 재마운트
- LayoutLoader 타입 정의에 `spinner` 스타일 및 spinner config 타입 추가 (LayoutLoader)
- UpdateLayoutContentRequest에 `spinner` validation 규칙 추가

### Fixed

- (engine-v1.29.0) spinner overlay 번역 미동작 수정 — renderSpinnerOverlay에서 G7Core.t() 사전 해석 후 컴포넌트에 전달 (TemplateApp)
- (engine-v1.29.0) spinner overlay 하드코딩 스타일 제거 — 배경색/z-index/포지셔닝을 엔진에서 제거하고 컴포넌트 책임으로 이전 (TemplateApp)

## [engine-v1.28.1]

### Fixed

- cellChildren 표현식 평가 결과 `$t:` 번역 후처리 누락 수정 (RenderHelpers)
  - 증상: `{{row.published ? '$t:sirsoft-page.admin.page.published_status.published' : '$t:...'}}` 표현식 결과가 번역되지 않고 `$t:key` 문자열 그대로 노출
  - 원인: `renderItemChildren.resolveValue`에서 표현식 평가 후 결과가 `$t:` 접두사 문자열인 경우 `translationEngine.resolveTranslations()` 호출이 없었음 (DynamicRenderer의 `resolveTranslationsDeep`와 달리)
  - 수정: 단일 바인딩 표현식 및 문자열 보간 결과에 `$t:` 패턴 감지 시 번역 처리 추가 (`raw:` 바인딩은 면제)
- `preprocessTranslationTokens` 정규식에서 하이픈(`-`) 포함 모듈키 미인식 수정 (DataBindingEngine)
  - 증상: `$t:sirsoft-page.admin.key` 패턴에서 `sirsoft` 이후 `-page`가 키의 일부로 인식되지 않음
  - 원인: 문자 클래스 `[a-zA-Z0-9_.]`에 하이픈 누락
  - 수정: Step 1(따옴표 내), Step 2(따옴표 외), `preprocessOptionalChaining` $t: 토큰 정규식 3곳에 `\-` 추가

## [engine-v1.28.0]

### Added

- `_changedKeys` 디바운스 병합 프로토콜 — 객체 값 이벤트의 stale closure 키 유실 방지 (ActionDispatcher)
  - 디바운스 대기 중 동일 debounceKey로 들어오는 객체 값의 변경 키만 누적 병합
  - `debounceAccumulatedValues` Map으로 누적, 타이머 fire 시 자동 정리
  - `_changedKeys` 미포함 이벤트는 기존 동작 유지 (하위 호환)
  - `extractEventData`에서 커스텀 이벤트의 `_changedKeys` 메타데이터 보존

### Fixed

- 커스텀 컴포넌트 이벤트(plain object) → synthetic event 변환 시 `_changedKeys` 메타데이터 누락 수정 (ActionDispatcher)
  - `isCustomComponentEvent` 분기에서 synthetic event 생성 시 `_changedKeys`를 복사하지 않아, MultilingualInput의 `_changedKeys` 프로토콜이 실제 환경에서 동작하지 않았음
  - DOM 이벤트(`preventDefault` 포함) 경로에서는 정상이었으나, React 컴포넌트의 plain object 이벤트 경로에서 유실

## [engine-v1.27.0]

### Fixed

- `_localInit` 처리 전 자식 컴포넌트 usgeEffect의 setState가 API 데이터를 stale 기본값으로 덮어쓰는 레이스 컨디션 수정 (DynamicRenderer)
  - 증상: 직접 URL 접근 시 상품 수정 저장 → 422 검증 오류 (category_ids, options 필수 위반). SPA 네비게이션에서는 정상
  - 원인: (1) `_localInit`(useEffect)이 실행되기 전 자식 useEffect(FileUploader `onFilesChange`)가 먼저 발동 → ActionDispatcher가 stale `context.state`(init_actions 빈 배열) 기반 `__g7PendingLocalState` 생성 (2) `handleLocalSetState`에서 `__g7SetLocalOverrideKeys`(init_actions가 설정한 stale 값)가 `__g7PendingLocalState`보다 우선 → `effectivePrev`에 stale 빈 배열 적용
  - 수정: `_localInit` 데이터가 준비되면 useLayoutEffect(모든 useEffect보다 먼저 실행)에서 `__g7PendingLocalState`와 `__7SetLocalOverrideKeys`를 API 데이터로 사전 동기화 — 후속 자식 useEffect의 ActionDispatcher가 정확한 base state 참조

### Added

- `{{raw:expression}}` 바인딩 문법 — 번역 면제 마커 시스템 (DataBindingEngine, DynamicRenderer, RenderHelpers)
  - `raw:` 접두사가 붙은 바인딩 결과는 `resolveTranslationsDeep`에서 번역을 건너뜀
  - 사용자 입력 데이터(게시판 제목, 댓글 등)에 `$t:` 패턴이 포함되어도 원본 보존
  - Unicode Noncharacter (`\uFDD0`, `\uFDD1`) 기반 내부 마커 — 사용자 입력과 충돌 불가
  - 파이프, 복잡한 표현식, 객체/배열 결과, 혼합 보간 모두 지원
- `rawMarkers.ts` 공유 유틸리티 모듈 — `wrapRaw`, `unwrapRaw`, `wrapRawDeep`, `isRawWrapped`, `containsRawMarker`

## [engine-v1.26.1]

### Added

- 프리뷰 모드 지원: `PREVIEW_SUPPRESSED_HANDLERS` 상수 기반 핸들러 억제 — navigate, navigateBack, navigateForward, replaceUrl, refresh, logout (ActionDispatcher)

### Fixed

- JSON 구조 문자열 내부의 `$t:` 번역 토큰이 `resolveTranslationsDeep`에 의해 번역되어 원본 데이터가 손상되는 버그 수정 (DynamicRenderer)
  - 증상: CodeEditor에서 레이아웃 JSON 편집 후 프리뷰 시 `$t:` 토큰이 사라져 다국어가 깨짐
  - 원인: `resolveTranslationsDeep`가 모든 string prop에 적용되어, JSON 문자열 내부의 `$t:` 토큰까지 번역
  - 수정: `{`/`[`로 시작하고 `}`/`]`로 끝나는 JSON 구조 문자열은 번역 건너뛰기
- 프리뷰 모드 레이아웃 기능 억제: `PREVIEW_SUPPRESSED_LAYOUT_FEATURES` 상수 — redirect 등 페이지 이탈 유발 레이아웃 기능 정의 (ActionDispatcher)
- `ActionDispatcher.setPreviewMode()` / `isPreviewMode()` API 추가 — 프리뷰 모드 활성화/비활성화 및 조회
- `ActionDispatcher.getPreviewSuppressedHandlers()` / `getPreviewSuppressedLayoutFeatures()` static 메서드 — 억제 대상 목록 외부 조회용
- `_global.__isPreview` 전역 상태 플래그 추가 — 프리뷰 모드 시 레이아웃 JSON에서 조건부 렌더링에 사용 가능 (TemplateApp)
- 프리뷰 안내 배너: 프리뷰 모드 시 최상단에 고정 배너 표시 — 페이지 이동 비활성화 안내 (SystemBannerManager)
- `SystemBannerManager` 신규 모듈: 범용 시스템 배너 관리자 — show/hide/hideAll API, 다국어 메시지, order 기반 정렬, #app paddingTop 자동 조정
- 프리뷰 모드에서 `blur_until_loaded` 비활성화 — 시각적 확인이 목적이므로 데이터소스 로딩 상태와 무관하게 블러 미적용 (DynamicRenderer)

## [engine-v1.26.0]

### Added

- 시스템 레이아웃 분기 지원: `__preview__` 예약 레이아웃 이름을 감지하여 별도 API 엔드포인트(`/api/layouts/preview/{token}.json`)로 라우팅 (LayoutLoader, TemplateApp)
  - LayoutLoader: `fetchLayout()`에서 `__preview__/` 접두사 감지 시 프리뷰 전용 API URL 구성
  - TemplateApp: `__preview__` 레이아웃 감지 시 route params에서 token을 추출하여 layoutPath에 포함

## [engine-v1.25.0]

### Added

- $t: 파라미터 값 내 중첩 번역 토큰 지원 (TranslationEngine)
  - `$t:key1|status=$t:key2` 형태에서 파라미터 값 위치의 `$t:key2`를 메인 해석 전에 사전 번역
  - 다중 중첩 대응: 해석 결과에 다시 `=$t:`가 포함되면 변화 없을 때까지 반복 (깊이 제한 5)
  - `=$t:` 패턴이 없으면 사전 해석 단계 자체를 skip (기존 동작 무영향)

## [engine-v1.24.8]

### Fixed

- extends 기반 레이아웃에서 SPA 네비게이션 시 base 컴포넌트(사이드바, 헤더, 로고 등) 불필요 remount로 깜빡이는 버그 수정 (template-engine, DynamicRenderer, LayoutService)
  - engine-v1.24.5의 layout_name key 추가가 모든 최상위 컴포넌트에 적용되어 base 컴포넌트까지 강제 remount
  - LayoutService.replaceSlots()에서 base 컴포넌트에 `_fromBase: true` 자동 마킹
  - `_fromBase` 컴포넌트는 stable key(componentDef.id) 사용 → 페이지 전환 시 보존(update)
  - 슬롯 래퍼(slot 매칭 컴포넌트)는 `_fromBase` 미마킹 → remount 보장 (localDynamicState 초기화)
  - 슬롯 children(페이지 고유 컴포넌트)은 `_fromBase` 미마킹 → 기존 layout_name key → remount 보장
  - non-extends 레이아웃은 replaceSlots 미호출 → `_fromBase` 없음 → 기존 동작 100% 유지
- `_fromBase` 보존 컴포넌트의 localDynamicState가 SPA 네비게이션 시 초기화되지 않아 이전 페이지 상태(visibleFilters 등)가 하위 트리에 전파되는 회귀 수정 (DynamicRenderer)
  - 원인: stable key → React가 컴포넌트 보존 → localDynamicState 잔존 → componentContext.state를 통해 모든 children에 cascading 전파
  - 증상: 주문목록 → 주문상세 이동 시 DataGrid에 컬럼/데이터 미표시 (이전 페이지의 visibleFilters 오염)
  - 수정: layoutKey(= layout_name) 변경 감지 시 `_fromBase` 컴포넌트의 localDynamicState를 `{ loadingActions: {} }`로 초기화
  - sidebar 메뉴 open/close 등 React 컴포넌트 내부 useState는 localDynamicState와 독립 → 보존됨

## [engine-v1.24.7]

### Fixed

- 모달 내부 setLocal() 호출 시 모달 localDynamicState가 globalState._local에 오염되어 페이지 DataGrid 깨지는 버그 수정 (G7CoreGlobals)
  - engine-v1.22.1에서 도입된 actionContext.state 병합이 모달 컨텍스트에서도 적용되어 모달 전용 상태(cancelItems, refundLoading 등)가 페이지 _local에 유입
  - __g7LayoutContextStack 기반 모달 감지: 모달 내부에서는 actionContext.state 병합 제외, 페이지에서는 기존 동작 유지
- init_actions에서 conditions 핸들러 사용 시 conditions 배열이 전달되지 않아 조건 분기가 무시되던 버그 수정 (TemplateApp, LayoutLoader)
  - executeInitActions에서 actionDef 구성 시 conditions 프로퍼티 누락 → handleConditions에서 undefined로 평가 → 경고만 출력하고 리턴
  - InitActionDefinition 타입에 conditions 프로퍼티 추가, actionDef에 conditions 전달 추가

## [engine-v1.24.6]

### Fixed

- 주문 취소 모달 닫기 후 주문상세 DataGrid 깨지는 버그 수정 — _local 상태 교차 오염 방지 (TemplateApp, ActionDispatcher)
  - handleRouteChange에서 `__g7LastSetLocalSnapshot`, `__g7SetLocalOverrideKeys`, `__g7SequenceLocalSync` 3개 전역 변수 미정리 → 이전 페이지 상태 잔존하여 다음 페이지 _local에 축적
  - handleSetState isRealComponentContext 경로에서 deep 모드 시 전체 _local 스냅샷을 context.setState에 전달 → localDynamicState에 전체 상태 축적 → dataContext._local override → SPA 이동 후 stale 필드 잔존
  - 수정: context.setState에는 변경 필드만 전달, __g7PendingLocalState에만 전체 병합 상태 유지

## [engine-v1.24.5]

### Fixed

- SPA 네비게이션 시 DynamicRenderer 내부 useState가 이전 페이지 _local 잔존하여 DataGrid 데이터 깨지는 버그 수정 (template-engine)
  - 동일 base layout 공유 페이지 간 루트 컴포넌트 ID 동일 → engine-v1.24.4 트리 구조 통일 후 React가 컴포넌트 보존(remount 안 함)
  - DynamicRenderer key에 layout_name 포함하여 레이아웃 변경 시 React 강제 remount
  - renderTemplate(), updateTemplateData() 양쪽 모두 적용
  - layout_name 부재 시 기존 동작(componentDef.id만 사용) 유지 — 하위 호환

## [engine-v1.24.4]

### Fixed

- renderTemplate()/updateTemplateData() React 트리 구조 통일 — 이중 렌더링(깜빡임) 해소 (template-engine, DynamicRenderer)
  - `renderTemplate()`은 `ResponsiveProvider → SlotProvider → [children]` 구조로, `updateTemplateData()`는 `ResponsiveProvider → [children]` 구조로 달랐음
  - 데이터소스 완료 시 `updateTemplateData()` 호출 → React가 트리 구조 변경 감지 → 전체 서브트리 언마운트/리마운트
  - `updateTemplateData()`에 외부 SlotProvider 추가하여 양쪽 트리 구조 통일
  - `DynamicRenderer`의 `isRootRenderer` SlotProvider 래핑 제거 (외부에서 제공하므로 이중 래핑 방지)
- SlotProvider `window.__slotContextValue` 전역 변수 설정을 `useEffect` → `useLayoutEffect`로 변경 (SlotContext)
  - 이중 렌더링 해소 후 슬롯 등록 타이밍 이슈 발생: 자식 `useEffect`(슬롯 등록)가 부모 `useEffect`(전역 변수 설정)보다 먼저 실행
  - `useLayoutEffect`는 모든 `useEffect`보다 먼저 실행되므로 전역 변수가 슬롯 등록 시점에 항상 사용 가능

## [engine-v1.24.3]

### Fixed

- 다국어 파이프 파라미터에서 산술 연산자(+, -, *, /, %) 포함 표현식이 단순 경로로 오인되어 빈 문자열 반환되던 버그 수정 (TranslationEngine)
  - `isComplexExpression` 정규식에 산술/비교 연산자 및 공백 패턴 추가
  - 영향 예시: `$t:key|count={{row.options_count - 1}}` → 기존에 count 빈 문자열, 수정 후 정상 평가

## [engine-v1.24.2]

### Added

- `transition_overlay.fallback_target` — 3단계 스켈레톤 타겟팅 지원 (TemplateApp)
  - target DOM 존재 → 해당 영역만 스켈레톤 (페이지 내부 전환, 예: 마이페이지 탭)
  - target 미존재 + fallback_target 존재 → 해당 영역만 스켈레톤 (페이지 전환)
  - 둘 다 미존재 → 전체 페이지 스켈레톤 (초기 로드, `position:fixed; inset:0`)
  - CSS `::after` 가림막도 scope에 따라 적절한 selector 적용
  - fullpage scope 시 전체 컴포넌트 트리를 스켈레톤으로 렌더
  - `LayoutLoader.ts` 타입 정의 + `UpdateLayoutContentRequest` 검증 규칙 추가

## [engine-v1.24.1]

### Fixed

- 초기 페이지 로드 시 스켈레톤 미표시 — target DOM(`#main_content_area` 등)이 `renderTemplate()` 전에 존재하지 않아 opaque fallback 후 시각적 효과 없음 (TemplateApp)
  - target DOM 부재 시 `#app`을 fallback 타겟으로 사용하여 스켈레톤 렌더
  - CSS `::after` 주입도 `#app` selector로 적용
  - 페이지 전환 시에는 기존대로 지정된 target 사용

## [engine-v1.24.0]

### Added

- `transition_overlay` skeleton 스타일 — 레이아웃 JSON 컴포넌트 트리 기반 동적 스켈레톤 UI 렌더링 (TemplateApp)
  - `style: "skeleton"` + `skeleton.component`: 레이아웃에서 단일 스켈레톤 렌더러 컴포넌트 지정
  - 엔진이 `components` 트리 + `options(animation, iteration_count)`를 props로 전달
  - 컴포넌트가 트리를 재귀 순회하여 스켈레톤 플레이스홀더 자동 생성
  - 데이터 로드 완료 후 `renderTemplate()` 호출 시 React reconciliation으로 자동 교체
  - 컴포넌트 미등록/target 미존재 시 opaque 스타일 자동 폴백
  - `skeleton.animation`: pulse / wave / none 선택
  - `skeleton.iteration_count`: iteration 블록 기본 반복 횟수
  - 백엔드 `UpdateLayoutContentRequest` 검증 규칙 동기화

## [engine-v1.23.0]

### Added

- `transition_overlay` 레이아웃 옵션 — 페이지 전환 시 오버레이로 stale flash 방지 (TemplateApp)
  - `true` (축약): opaque 스타일 (document.body에 fixed div 폴백)
  - `{ enabled, style, target }`: opaque / blur / fade 선택 + 타겟 컨테이너 지정 가능
  - `target` 지정 시: CSS `<style>` 태그를 `<head>`에 주입 → `::after` 의사 요소로 해당 영역만 덮음 (React 렌더 트리 외부, 형제 요소 미영향)
  - `target` 미지정 시: `document.body`에 `position:fixed` div 삽입 (폴백)
  - React 렌더 사이클과 독립적 — 동기 DOM/CSS 조작으로 즉시 적용
  - 모든 경로(progressive/non-progressive/취소/에러)에서 오버레이 정리 보장

## [engine-v1.22.1]

### Fixed

- setLocal() 후 openModal 시 dynamicState 값 누락 — actionContext.state를 globalLocal과 deepMerge하여 __g7PendingLocalState에 전체 _local 반영 (G7CoreGlobals)
  - setState(target: "_local")로 설정된 값은 localDynamicState에만 존재하고 globalLocal에 없음
  - setLocal → openModal 시퀀스에서 $parent._local 스냅샷에 dynamicState 값이 포함되지 않는 문제 수정

## [engine-v1.22.0]

### Added

- DevTools 모달 정의 교차 검증 — modalStack에 열린 모달 ID가 레이아웃 modals 섹션에 미정의 시 `missing-definition` 이슈 기록 (G7DevToolsCore)

## [engine-v1.21.2]

### Fixed

- getLocal() await 후 stale 반환 근본 수정 — `__g7LastSetLocalSnapshot` fallback 도입 (G7CoreGlobals)
  - `__g7PendingLocalState`가 useLayoutEffect에서 클리어된 후에도 최신 setLocal 값 반환
  - `dataContext._local` 갱신 시점에 queueMicrotask로 자동 클리어 (DynamicRenderer)
  - `handleLocalSetState`에서는 참조하지 않으므로 기존 stale 오염 방지 로직과 충돌 없음

## [engine-v1.21.1]

### Added

- `condition` 속성을 `if`의 별칭으로 네이티브 지원 — 컴포넌트 정의, renderItemChildren, renderWithIteration 모든 경로에서 동작 (RenderHelpers, DynamicRenderer)
- evaluateIfCondition에서 boolean 타입 직접 처리 — 엔진 prop 사전 해석으로 조건이 boolean이 된 경우 대응 (RenderHelpers)
- ComponentDefinition 인터페이스에 `condition?: string | boolean` 속성 추가 (DynamicRenderer)

## [engine-v1.21.0]

### Added

- suppress 에러 핸들러 — 에러 전파를 의도적으로 방지하는 no-op 핸들러 (ActionDispatcher)
- multipart/form-data contentType 자동 감지 및 FormData 변환 (DataSourceManager)
- errorCondition 기능 — API 200 응답이어도 조건부 에러 처리 (DataSourceManager)
- replaceUrl 핸들러 — refetch 없이 URL만 변경

### Fixed

- SPA 네비게이션 시 _global._local에 이전 페이지 _local 상태 잔존 — 스냅샷 순서 수정 (TemplateApp)
- Form 자동 바인딩 setState 경합 — pendingLocal || context.state 우선 사용 (ActionDispatcher)
- Form 자동 바인딩 bindingType 메타데이터 기반 boolean 바인딩 수정 (DynamicRenderer)
- onChange raw value fallback 과도 적용 회귀 제거 (ActionDispatcher)
- DataSourceManager isMultipart 변수 TDZ(Temporal Dead Zone) — 선언 순서 수정
- errorHandling 레이아웃 병합 누락 및 showErrorPage 안정성 개선
- blocking 데이터소스 + errorHandling 데드락 — fallback 동기 적용, 에러핸들러 비동기 실행
- DataSourceManager fallback/errorHandling 우선순위 — errorHandling 먼저, fallback 후적용
- resolveObject 복잡 표현식 미지원 수정 (DataBindingEngine)
- dispatch 경로 context.data._local stale 버그 수정 (G7CoreGlobals)
- refetchDataSource stale localState 버그 수정 (ActionDispatcher)
- DynamicRenderer _localInit shallow merge — deep merge 적용
- 복수 root DynamicRenderer 전역 플래그 경쟁 조건 수정 (__g7ForcedLocalFields 등)
- sequence 내 커스텀 핸들러 상태 동기화 수정 (ActionDispatcher, G7CoreGlobals)
- setState params 키에 {{}} 표현식 사용 시 경고 출력

## [engine-v1.20.0]

### Added

- debounceFlush 기능 - 대기 중인 디바운스 핸들러 즉시 실행

### Fixed

- 디바운스 액션 레이스 컨디션 — 연속 호출 시 이전 결과 누락 방지 (flush 메커니즘)

## [engine-v1.19.1]

### Fixed

- DataGrid footerCells/footerCardChildren 내부 iteration 패턴 미작동 — 3가지 근본 원인 수정 (DataBindingEngine, DynamicRenderer, RenderHelpers)
  - `resolveObject`가 iteration이 있는 ComponentDefinition을 사전 해석하여 iteration 변수(`currency` 등)가 미존재 상태에서 에러 발생 → iteration 객체 감지 시 선평가 건너뛰기
  - `componentContext`에 데이터소스(`order`, `active_carriers` 등)가 포함되지 않아 `renderItemChildren` 컨텍스트에서 데이터소스 표현식이 빈 값으로 평가 → `parentDataContext` 필드 추가
  - `getEffectiveContext`가 `componentContext.state`(= `_local`)만 병합하고 데이터소스 키 미병합 → `parentDataContext` 키를 최상위 컨텍스트에 병합 (기존 키 우선)

## [engine-v1.19.0]

### Added

- actionRef - named_actions 참조 시스템
- named_actions - 명명된 액션 정의 및 재사용
- sequence 내 setState 후 refetchDataSource 호출 시 마이크로태스크 기반 배칭

## [engine-v1.18.0]

### Added

- DataSource onSuccess 콜백에서 response 객체로 API 응답 데이터 접근
- ErrorHandling 에러 핸들링 시스템

### Fixed

- (engine-v1.18.1) __g7SetLocalOverrideKeys 처리를 별도 useLayoutEffect([dataContext._local])로 이동
- (engine-v1.18.2) __g7ForcedLocalFields 조건부 클리어 (불필요한 클리어 방지)
- (engine-v1.18.3) 전역 플래그 클리어를 queueMicrotask로 지연 처리

## [engine-v1.17.0]

### Fixed

#### 상태 동기화 (setLocal/dispatch)

- 커스텀 핸들러에서 setLocal 후 dispatch 호출 시 최신 로컬 상태 참조
- setLocal 후 즉시 dispatch 호출 시 최신 로컬 상태 참조 지원 (G7CoreGlobals)
- (engine-v1.17.1) 커스텀 핸들러에서 G7Core.state.getLocal() 호출 시 최신 상태 참조 지원
- (engine-v1.17.2) _isDispatchFallbackContext가 true면 전역 폴백(setGlobalState) 처리
- (engine-v1.17.2) sequence 내 연속 setState 시 이전 setState 결과 참조 지원
- (engine-v1.17.3) componentContext + context.data 저장하여 $parent 바인딩 지원
- (engine-v1.17.4) 비동기 콜백에서 setLocal 호출 시 dynamicState stale 값 방지
- (engine-v1.17.4) __g7ForcedLocalFields 처리로 최신 필드 값 우선 적용
- (engine-v1.17.5) dataKey 자동 바인딩 컴포넌트에서 setState 핸들러 호출 시 stale 값 방지
- (engine-v1.17.6) globalLocal + pendingState 2단계 병합
- (engine-v1.17.7) globalLocal 사용 (actionContext.state 사용 안 함)
- (engine-v1.17.7) actionContext 유무와 관계없이 항상 globalLocal 업데이트
- (engine-v1.17.8) isRootRenderer=false일 때 클리어 안 되는 버그 수정
- (engine-v1.17.8) setLocal이 업데이트한 키를 기록하여 ROOT의 localDynamicState에서 제거
- (engine-v1.17.9) resolvedPayload(변경된 필드만) 사용 — finalPayload(전체 상태 스냅샷) 사용 방지
- (engine-v1.17.10) pendingLocal 우선 사용 (Form 자동 바인딩 경합 방지)
- (engine-v1.17.10) __g7PendingLocalState 클리어
- (engine-v1.17.11) 모든 루트급 컴포넌트에서 __g7ForcedLocalFields, __g7PendingLocalState 클리어
- (engine-v1.17.12) 전역 _local 상태도 업데이트 (setLocal과 동일)

#### setState 옵션 및 동작

- setState 얕은 병합이 중첩 객체 덮어쓰기 — merge: "deep" / "replace" 옵션 추가 (ActionDispatcher)
- (engine-v1.17.1) setState 배열 조작 미지원 — arrayMethod 파라미터 추가 (push, filter, splice, map)
- (engine-v1.17.2) setState 동일 값 불필요 리렌더 방지 — shallow equality 검사 (G7CoreGlobals)
- (engine-v1.17.3) setState 동적 키 경로 표현식 미평가 — params key 표현식 평가 추가 (ActionDispatcher)
- (engine-v1.17.3) setState onSuccess 컨텍스트에서 {{response.xxx}} 표현식 미평가 수정
- (engine-v1.17.5) setState `_local` + `_global` 동시 수정 시 배치 업데이트 (sequence 내)
- (engine-v1.17.6) setState 순환 업데이트 감지 — 깊이 10 초과 시 에러 (G7CoreGlobals)
- (engine-v1.17.7) setState _isolated 스코프 지원 (target: "isolated")
- (engine-v1.17.9) setState undefined 값 처리 — no-op (removeKey 별도 액션으로 명시적 삭제)
- (engine-v1.17.9) setState 깊은 중첩 경로(4+ 레벨) 중간 객체/배열 자동 생성 (ActionDispatcher)
- (engine-v1.17.11) setState _local 전역 핸들러에서 target 컴포넌트 ID 스코프 지원
- setState dot notation — 멀티 키 병합 시 이전 키 변경 유실 방지 (ActionDispatcher)

#### initGlobal / initLocal

- initGlobal SPA 네비게이션 시 이미 초기화된 키 덮어쓰기 방지 (TemplateApp)
- (engine-v1.17.1) initGlobal 데이터소스 로드 전 실행 — DS 참조 표현식 지연 평가
- (engine-v1.17.2) initGlobal 배열 deep merge 파괴 방지 — replace 전략 적용
- (engine-v1.17.2) initGlobal 조건 표현식 (route.id ? 'edit' : 'create') 평가 지원
- (engine-v1.17.3) initGlobal dot 경로("settings.display.mode") 중첩 객체 확장
- (engine-v1.17.3) initLocal partial/extends 레이아웃에서 무시되는 문제 수정 (DynamicRenderer)
- (engine-v1.17.4) initGlobal 레이아웃 변경 시 이전 키 클린업 (TemplateApp)
- (engine-v1.17.4) initLocal Form 자동 바인딩 우선순위 충돌 해결 — initLocal 후 적용

#### init_actions 타이밍

- init_actions 컴포넌트 마운트 전 실행 — 첫 렌더 사이클 후 지연 (TemplateApp)
- (engine-v1.17.1) init_actions 조건 토글 시 재실행 — 컴포넌트 ID 기반 run-once 가드 (DynamicRenderer)
- (engine-v1.17.1) init_actions replaceUrl가 history.pushState 사용 — replaceState로 수정
- init_actions sequence 내 apiCall 비동기 대기 미지원 수정 (ActionDispatcher)
- (engine-v1.17.2) init_actions route.id 등 라우트 파라미터 선처리 후 실행 (TemplateApp)
- (engine-v1.17.3) init_actions 모달 내부 실행 미지원 — 모달 마운트 시 처리 추가 (DynamicRenderer)
- (engine-v1.17.4) init_actions HMR 중복 실행 방지 (TemplateApp)
- (engine-v1.17.5) init_actions 부모-자식 실행 순서 미보장 — 부모 완료 후 자식 마운트 (DynamicRenderer)
- (engine-v1.17.7) init_actions blocking 데이터소스 완료 대기 후 실행 (TemplateApp)
- (engine-v1.17.8) init_actions setState `_global` 첫 렌더 전 동기 초기화 경로 (G7CoreGlobals)
- (engine-v1.17.9) init_actions onSuccess 중첩 비동기 액션 대기 수정 (ActionDispatcher)

#### dataKey / Form 자동 바인딩

- (engine-v1.17.3) dataKey 자동 바인딩이 명시적 setState 덮어쓰기 — __g7ForcedLocalFields 추적 (FormContext)
- (engine-v1.17.3) Form 자동 바인딩 명시적 value prop 감지 시 스킵 (DynamicRenderer)
- (engine-v1.17.4) dataKey 중첩 객체 경로 (dot-notation name) 생성 지원 (FormContext)
- (engine-v1.17.4) Form 자동 바인딩 sortable 내 컨텍스트 차단 — parentFormContextProp={undefined} 지원
- (engine-v1.17.5) Form 제출 이중 클릭 방지 — 로딩 상태 기반 차단 (ActionDispatcher)
- (engine-v1.17.6) dataKey 데이터소스 로드 전 빈 구조 생성 방지 — waitForData 대기 (DynamicRenderer)
- (engine-v1.17.7) 동일 페이지 복수 폼 dataKey 스코프 분리 — 폼 ID 기반 컨텍스트 (FormContext)
- (engine-v1.17.8) dataKey iteration 내 인덱스 스코프 적용 (FormContext, DynamicRenderer)
- (engine-v1.17.8) FileUploader File/Blob 타입 감지 — 자동 직렬화 제외 (FormContext)

#### Stale Closure

- (engine-v1.17.5) 비동기 콜백(onSuccess/onError)에서 캡처된 상태 대신 현재 상태 재조회 (ActionDispatcher)
- (engine-v1.17.6) componentContext ref 기반 접근으로 마운트 시점 캡처 방지 (DynamicRenderer)
- (engine-v1.17.6) useCallback 과도한 메모이제이션 제거 — 인라인 함수 전환 (DynamicRenderer)
- (engine-v1.17.6) 이벤트 핸들러 라이브 상태 조회 래핑 (DynamicRenderer)
- (engine-v1.17.7) computed 콜백 스냅샷 대신 라이브 상태 getter 사용 (G7CoreGlobals)
- (engine-v1.17.8) _global setState 레이스 컨디션 — 함수형 업데이터 병합 패턴 (G7CoreGlobals)
- (engine-v1.17.8) _global subscribe 콜백 ref 기반 등록 (G7CoreGlobals)
- (engine-v1.17.9) useControllableState prop 동기화 효과 추가 (useControllableState)

#### 캐시

- (engine-v1.17.1) iteration 표현식 캐시 — 첫 아이템 값만 표시되는 문제 (skipCache 적용, DynamicRenderer)
- (engine-v1.17.2) 상태 의존 경로(_local/_global) 30초 영구 캐시 stale — skipCache 적용 (DataBindingEngine)
- (engine-v1.17.2) if 조건 표현식 캐시 — 상태 변경 후 조건 미토글 (skipCache 적용, DynamicRenderer)
- (engine-v1.17.3) 렌더 캐시 컴포넌트 인스턴스 ID 스코프 분리 (DynamicRenderer)
- (engine-v1.17.4) SPA 네비게이션 시 이전 페이지 캐시 잔존 — 레이아웃 변경 시 전체 캐시 클리어
- (engine-v1.17.4) 데이터소스 캐시 키에 직렬화된 params 해시 포함 (DataSourceManager)
- (engine-v1.17.5) computed 의존성 추적 기반 캐시 무효화 (DataBindingEngine)
- (engine-v1.17.5) 모달 데이터소스 refreshOnOpen 시 캐시 클리어 (ModalDataSourceWrapper)
- (engine-v1.17.6) apiCall 기본 cache: false 설정 — 명시적 opt-in 방식 (ActionDispatcher)
- (engine-v1.17.7) subscribe 알림 캐시 우회 — 라이브 상태 직접 조회 (G7CoreGlobals)
- (engine-v1.17.8) 데이터소스 refreshOn 트리거 시 캐시 버스트 (DataSourceManager)
- (engine-v1.17.9) 프리컴파일 표현식 캐시 키에 컨텍스트 변수명 포함 (RenderHelpers)
- (engine-v1.17.11) _computed prop 캐시 버그 — getComputedAwareOptions() skipCache 적용 (DynamicRenderer)

#### 기타

- (engine-v1.17.1) Form validation 에러 응답 경로 정규화 — error.errors / error.message 통일 (ActionDispatcher)
- (engine-v1.17.2) Button이 Form 내에서 기본 type="submit" — type="button" 기본값 적용 (ComponentRegistry)
- (engine-v1.17.5) iteration item_var 스코프 격리 — 부모 루프 변수 자식에 누출 방지 (DynamicRenderer)
- (engine-v1.17.6) 데이터소스 refreshOn 무한루프 — 리프레시 순환 감지 및 디바운스 (ActionDispatcher)
- (engine-v1.17.6) cellChildren 부모 데이터 변경 시 stale props — 재평가 추가 (DynamicRenderer)
- (engine-v1.17.6) 커스텀 핸들러 등록 타이밍 — 등록 대기 큐, 지연 액션 대기 (ActionDispatcher)
- (engine-v1.17.6) 커스텀 핸들러 async 반환값 sequence 내 자동 await (ActionDispatcher)
- (engine-v1.17.7) computed 순환 의존성 감지 — visited 세트 기반 에러 발생 (DataBindingEngine)
- (engine-v1.17.7) blocking 데이터소스 에러 시 무한 로딩 — fallback/에러 핸들러 트리거
- (engine-v1.17.8) 언마운트된 컴포넌트 setLocal 무시 — no-op 처리 (G7CoreGlobals)
- (engine-v1.17.8) 데이터소스 의존성 체이닝 — dependsOn 실행 순서 보장 (ActionDispatcher)
- (engine-v1.17.9) 데이터소스 params 표현식 캐시 — 상태 변경 후 re-fetch 미실행 방지 (G7CoreGlobals)
- (engine-v1.17.10) __g7PendingLocalState 컴포넌트 마운트 시 flush (DynamicRenderer)
- (engine-v1.17.10) __g7SetLocalOverrideKeys 레이아웃 변경 시 클리어 (G7CoreGlobals)
- (engine-v1.17.10) Input controlled/uncontrolled 전환 방지 — 빈 문자열 fallback
- (engine-v1.17.11) errorHandling + blocking 데드락 — fallback 정의 시 에러 핸들러 비동기 실행

## [engine-v1.16.0]

### Added

- globalHeaders - API 호출 시 패턴 매칭 기반 전역 HTTP 헤더 추가
- setGlobalHeaders, matchesPattern, getMatchingGlobalHeaders 메서드 (ActionDispatcher)
- DataSource globalHeaders - API 데이터 소스 헤더 추가
- parentDataContext - {{$parent._local.xxx}} 바인딩 지원 (DynamicRenderer)
- getParent, setParentLocal, setParentGlobal - 부모 컨텍스트 API (G7CoreGlobals)
- handleParentScopeSetState - $parent._global/._local setState 처리
- 레이아웃 레벨 globalHeaders 설정 (LayoutLoader)
- $parent 바인딩 컨텍스트 (모달 및 중첩 레이아웃)

### Fixed

- DataSourceManager onSuccess에서 openModal 시 $parent._local 접근 불가 수정
- ActionDispatcher 복합 표현식 (삼항 연산자 등) 미평가 수정
- setLocal()이 expandedRows 등 배열 상태를 초기값으로 덮어쓰기 수정 (DynamicRenderer)
- _computed stale closure 및 deepMerge sparse array 수정 (ActionDispatcher, DynamicRenderer, G7CoreGlobals)
- setParentLocal 후 getLocal() stale 데이터 반환 수정 (DynamicRenderer, G7CoreGlobals)
- deepMergeState 배열→객체 잘못된 병합 수정
- 콜백 prop 내 액션 정의 선평가 방지 및 비동기 콜백 상태 참조 수정 (DataBindingEngine, G7CoreGlobals)
- 레이아웃 전환 시 _global._local cleanup 미실행 수정 (TemplateApp)

## [engine-v1.15.0]

### Added

- permissions - 레이아웃 접근 권한 식별자 배열 (401/403 응답)
- extensionPointProps - 확장 영역에서 전달 가능한 props
- isInsideIteration - iteration 캐시 버그 방지 플래그

### Fixed

- expandChildren _computed 상태 동기화 — computedRef 패턴 적용 (DynamicRenderer, G7CoreGlobals)
- `_local` 변경 시 `_computed` 재계산 미발생 수정 (TemplateApp, DynamicRenderer)
- expandChildren 상태 동기화 버그 — stateRef 패턴 적용 (DynamicRenderer, G7CoreGlobals)
- cellChildren 글로벌 상태 접근 불가 수정 (G7CoreGlobals)
- $computed 별칭 미지원 수정 (DataBindingEngine)
- 언어 전환 시 모듈 핸들러 재등록 누락 수정

## [engine-v1.14.0]

### Added

- sortable - @dnd-kit 기반 네이티브 드래그앤드롭 정렬 기능
- SortableContainer, SortableItemWrapper 컴포넌트
- itemTemplate - sortable 내 아이템 렌더링 템플릿
- _isolated - 격리된 상태 바인딩 (성능 최적화)
- isolatedStateId - 격리된 상태 식별자 (DevTools용)
- IsolatedStateContext 시스템

### Fixed

- DataGrid expandChildren 상태 동기화 버그 수정 (DynamicRenderer)
- $event 표현식 캐시로 조건 잘못 평가 수정 (ActionDispatcher)
- _local 경로 캐시로 상태 변경 미반영 수정 (DataBindingEngine)
- DynamicRenderer text prop 파이프 표현식 미처리 수정
- 언어 전환 시 템플릿 핸들러 재등록 누락 수정 (TemplateApp)

## [engine-v1.13.0]

### Added

- classMap - 조건부 CSS 클래스 매핑 (중첩 삼항 연산자 대체)
- componentPath - 경로 기반 컴포넌트 식별 (ID 없는 컴포넌트 편집용)
- WYSIWYG 에디터 지원 (ensureComponentId, moveComponentByPaths, updateComponentByPath)

### Fixed

- onSuccess 배열 내 복수 setState 순차 실행 시 상태 동기화 수정 (ActionDispatcher)
- DataBindingEngine $t: 토큰 처리 개선
- init_actions setGlobalState 비동기 에러 수정 (TemplateApp)
- Form 자동 바인딩 debounce stale 상태 수정 (DynamicRenderer, FormContext, G7CoreGlobals)
- cellChildren remount 리렌더 및 _remountKeys 경로 수정 (ActionDispatcher, DynamicRenderer)
- remount 핸들러 cellChildren ID 표현식 해석 버그 수정 (G7CoreGlobals, RenderHelpers)
- sequence 내 closeModal 후 setState 시 모달 재오픈 수정 (ActionDispatcher)
- setState dot notation 멀티 키 병합 시 이전 키 변경 유실 수정 (ActionDispatcher)

## [engine-v1.12.0]

### Added

- expandChildren - 확장 영역 내 액션에서 부모 상태 업데이트
- _isolated 컨텍스트 시스템 (IsolatedStateContext)
- onDragStart, onDragEnd - 편집기에서 컴포넌트 드래그 지원

### Fixed

- actions 객체 형식 호환성 에러 수정 (DynamicRenderer)
- LayoutLoader 401 에러 시 토큰 삭제 및 재시도 미동작 수정
- SPA navigate 시 DataGrid 빈 화면 렌더링 수정 (TemplateApp)
- setState _global dot notation 시 기존 상태 유실 수정 (ActionDispatcher)
- 커스텀 컴포넌트 change 이벤트 핸들링 버그 수정 (ActionDispatcher)
- style prop CSS 문자열 자동 객체 변환 미지원 수정 (DynamicRenderer)

## [engine-v1.11.0]

### Added

- onComponentEvent - 컴포넌트 이벤트 구독 및 핸들러 실행
- extensionPointProps - 확장 영역 props 전달
- isEditMode, onComponentSelect, onComponentHover - 편집기 모드 지원
- initActions - 동적 표현식 기반 초기값 설정
- initGlobal, initLocal, initComputed - 데이터 바인딩 병합 (LayoutLoader)
- scrollIntoView 핸들러
- 위지윅 레이아웃 편집기 관련 전역 API (G7CoreGlobals)

### Fixed

- navigate/back 재진입 시 init_actions 데이터소스 유실 수정 (TemplateApp)
- sequence 핸들러 _local 상태 동기화 수정
- 배열 쿼리 파라미터 처리 버그 수정 (TemplateApp, ApiClient, Router, ActionDispatcher, DataSourceManager)
- dataKey 바인딩 버그 수정 (TemplateApp)

## [engine-v1.10.0]

### Added

- conditions 속성 - AND/OR 그룹 및 if-else 체인 조건부 렌더링 (DynamicRenderer)
- conditions 액션 - 조건부 액션 실행 (ActionDispatcher)
- conditions 속성 - 조건부 데이터 소스 로딩 (DataSourceManager)
- conditions 속성 - 조건부 레이아웃 로드 (LayoutLoader)
- slotId 동적 결정 - 표현식 지원으로 동적 슬롯 결정
- zIndex 속성 - 렌더링 순서 제어 (기본값: 0)
- SlotContext 시스템 모듈
- 배열 쿼리 파라미터 (navigate 핸들러)

### Fixed

- setState deep merge 및 배열 쿼리스트링 처리 수정
- Slot 컴포넌트 에러 및 검색 편집 모드 에러 수정
- 쿼리 스트링 기반 검색 필터 미설정 수정

## [engine-v1.9.0]

### Added

- _defines - 컴포넌트에서 {{_defines.xxx}}로 접근 가능한 정의 변수
- 파이프 함수 (`|uppercase`, `|lowercase` 등)
- $get 헬퍼 함수 - 안전한 중첩 속성 접근
- $switch 표현식 - 다중 분기 값 선택
- classMap - 조건부 CSS 클래스 (key → variants 매핑)
- computed - 계산된 값 시스템
- switch params.value 지원, default 케이스 지원

### Fixed

- 레이아웃 서빙 시 defines/computed 속성 미포함 수정
- validation 에러 성공 후 미클리어 수정
- 액션 핸들러 캐시 문제 수정 (ActionDispatcher)
- resolveValue 호출 캐시 문제 수정 (ActionDispatcher)

## [engine-v1.8.0]

### Added

- 동적 레이아웃 로드 시스템 (LayoutLoader)
- 외부 스크립트 동적 로드 (scripts 속성)
- if 조건을 사용한 조건부 로드

### Fixed

- dataKey 자동바인딩 버그 수정 (DynamicRenderer)
- data_source refetchOnMount 속성 누락 수정
- iterator 타입 지원 및 에러 수정 (DynamicRenderer, RenderHelpers)
- 에러 객체 deep merge 처리 수정 (ActionDispatcher)

## [engine-v1.7.0]

### Added

- loadScript 핸들러 - 외부 JavaScript 동적 로드
- callExternal 핸들러 - 외부 함수 호출

### Fixed

- 설정 탭 모듈 목록 iteration 버그 수정 (DynamicRenderer)
- 점진적 콘텐츠 에러 핸들링 실패 수정 (TemplateApp, ActionDispatcher, DynamicRenderer)
- refetchDataSource 시 blur_until_loaded 미적용 수정 (TemplateApp)
- blur_until_loaded 개별 DOM 요소 처리 수정

## [engine-v1.6.0]

### Added

- errorHandling - 레이아웃 레벨 에러 핸들링 설정
- showErrorPage 핸들러 - 에러 페이지 표시

## [engine-v1.5.0]

### Added

- blur_until_loaded 표현식 지원 (`{{_global.isSaving}}` 등 동적 조건)

### Fixed

- 복잡한 조건식이 포함된 다국어 문자열 처리 오류 수정 (TranslationEngine)
- 번역 파라미터로 공백 문자열 전달 시 파싱 오류 수정 (TranslationEngine)
- apiCall 액션에 auth_required 옵션 누락 수정 (ActionDispatcher)
- data_source.endpoint 표현식 미처리 수정 (DataSourceManager)

## [engine-v1.4.0]

### Added

- 조건부 데이터 소스 로딩 (if 속성) - 생성/수정 모드 분기

### Fixed

- 데이터그리드 표현식 렌더링 오류 수정
- validation error 미표시 수정

## [engine-v1.2.0]

### Added

- 모달 스택 - 중첩 모달 지원 (_global.modalStack)
- 멀티 모달 지원
- 반복 컨텍스트 바인딩 (iteration 내 표현식)
- text 속성 복합 바인딩

### Fixed

- ModalWrapper 데이터 바인딩 시 캐시 문제 수정 (ModalDataSourceWrapper)
- ModalWrapper 렌더링 시 iteration 미작동 수정 (DynamicRenderer)
- 모달 오픈 시 API 기반 바인딩 미동작 수정 (ModalDataSourceWrapper)
- 모달 클릭 시 API 응답 에러 처리 미개선 수정
- 모달 렌더링 오류 수정 (DynamicRenderer)
- 반복 컨텍스트에서 파라미터가 포함된 다국어 번역 미작동 수정 (DynamicRenderer, RenderHelpers)
- 다국어 파라미터로 데이터 미전달 수정

## [engine-v1.1.0]

### Added

- 다크 모드 지원 (Tailwind dark: variant)
- 반응형 레이아웃 (responsive 속성)
- 전역 상태 관리 (_global, _local)
- 데이터 바인딩 및 표현식 ({{user.name}}, {{route.id}})
- Optional Chaining & Nullish Coalescing 지원

### Fixed

- 언어 변경 후 navigate 핸들러 미작동 수정 (TemplateApp)
- 페이지네이션 오류, DataGrid 렌더링 오류 수정
- 검색어 입력 후 엔터 키 미반응 수정
- 검색 미작동, queryString 미반영 수정
- 체크박스 동작 오류 — preventDefault 누락 수정
- 로그인 시 ActionDispatcher actions 미실행 수정 (TemplateApp, Router)
- 토큰 만료 후 refresh 토큰 / 로그인 리다이렉션 미작동 수정
- 렌더링 오류 수정 (DataBindingEngine)
- 템플릿 엔진 로더/렌더링 오류 (hotfix)
