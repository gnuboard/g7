# Template Engine Changelog

> 이 문서는 그누보드7 템플릿 엔진의 내부 개발 버전 이력입니다.
> `engine-v1.x.x` 버전은 그누보드7 릴리스 버전과 독립적입니다.
>
> 형식: [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)

## [engine-v1.49.2] - 2026-05-07

### Fixed

- (engine-v1.49.2) `_localInit useLayoutEffect` 의 `currentPending` baseline 이 비어있을 때 globalState 의 직전 setState 결과(예: init_actions 가 박은 `tempKey`)를 흡수하지 못해 후속 setLocal mergedPending 의 `pendingState || baseLocal` 분기에서 stale pending 우선 사용 → globalState._local 통째 교체 시 손실 키 영구 누락되던 회귀 수정 (DynamicRenderer)
  - 증상: 게시판 글쓰기 화면(board/form) 직접 URL 진입 또는 강제 새로고침 시 init_actions setState 가 globalState._local 에 박은 `tempKey` 가 사라져 첨부파일 업로드용 `temp_key` 빈 값 평가. 목록 → 글쓰기 navigate 진입은 정상. 3회 중 1회 빈도로 간헐 발생
  - 원인: setState_3 (init_actions tempKey) → globalStateUpdater 경로 → `globalState._local = {form, tempKey}` 정상. 그러나 React 마운트 후 useLayoutEffect 클리어로 `__g7PendingLocalState = null` → _localInit useLayoutEffect 가 발화 시 `currentPending = null || {} = {}` (빈 baseline) → pendingNext = `{form, hasChanges:false}` (tempKey 미포함, stale). 이후 performStateUpdate → setLocal({render:false}) → mergedPending 의 `pendingState || baseLocal` 분기로 stale pending 우선 사용 → globalState._local 통째 교체 시 tempKey 손실
  - 수정: `currentPending` baseline 을 `__g7PendingLocalState || {}` → `{...G7Core.state.get()._local, ...(__g7PendingLocalState || {})}` 로 변경. fresh globalState._local 위에 기존 pending 머지로 baseline 이 진짜 fresh 보장. engine-v1.27.0 의 useLayoutEffect 사전 동기화 의도 보존 + 강화
  - 회귀 테스트: `troubleshooting-state-setstate.test.ts` `[사례 36]` (8개)

## [engine-v1.49.1] - 2026-05-06

### Fixed

- 인라인 `$t:defer:key|...` 가 다른 텍스트와 혼용될 때 (예: `{{id}} — $t:defer:key|x=y`) 키 문자 클래스가 콜론을 미포함해 `$t:defer` 만 매칭되고 나머지가 raw 로 노출되던 회귀 수정 — `TRANSLATION_PATTERN` 에 옵셔널 `(?:defer:)?` 추가 (TranslationEngine)

## [engine-v1.49.0] - 2026-05-05

### Changed

- `TemplateApp.reloadExtensionState()` 가 활성 로케일 목록(`_global.appConfig.supportedLocales`)도 `/api/locales/active` 응답으로 갱신하도록 확장 — 언어팩 설치/활성화/제거 시 `reloadExtensions` 핸들러 호출만으로 사용자 언어 셀렉터(UserProfile 등)가 새로고침 없이 즉시 반영. 기존 cache_version + routes + layout + translations 재로드 단계 뒤에 추가 (TemplateApp)
- `refresh` 핸들러에 `params.delayMs` 옵션 추가 — 선행 토스트/모달 닫힘 애니메이션이 인지된 후 reload 되도록 지연 가능. 기본값 0 으로 기존 호출처 동작 동일 (ActionDispatcher)

### Added

- 신규 공개 API `GET /api/locales/active` — 활성 코어 언어팩 + `config('app.supported_locales')` 합집합 반환 (LocaleController, LanguagePackService::getActiveLocales)

## [engine-v1.48.0] - 2026-04-30

### Changed

- `$locales` 전역 변수가 시스템 활성 언어팩(`_global.appConfig.supportedLocales`)을 우선 반영하도록 의미 변경 — 언어팩 설치/제거 시 사용자 정보 수정·헤더 언어 선택·다국어 입력 탭 등 모든 picker UI 가 즉시 반영. 미초기화 시에만 템플릿 정적 메타데이터(template.json `locales`)로 폴백. 백엔드 `Rule::in(config('app.supported_locales'))` 검증과 정합 (template-engine, G7CoreGlobals)

### Added

- `$templateLocales` 전역 변수 신설 — 현재 템플릿이 자체 번역을 제공하는 언어 목록(template.json `locales`). 템플릿 정적 메타데이터가 필요한 경우 사용 (template-engine)

## [engine-v1.47.1] - 2026-04-30

### Fixed

- `TemplateApp.showRouteError` 의 401 가드가 토큰 보유 여부 판정에서 `apiClient.getToken()` 만 사용해 토큰 만료 사용자에게 안내 토스트가 노출되지 않던 문제 수정 — `LayoutLoader` 가 401 시 토큰을 자동 제거하고 재시도하므로 가드 진입 시점에는 항상 토큰이 null. `LayoutLoader` 가 첫 401 시 토큰 보유 상태였음을 `LayoutLoaderError.details.hadToken=true` 로 마킹해 가드로 전달. 가드는 (현재 토큰 보유 OR `details.hadToken === true`) 일 때 `reason='session_expired'` 부여. 익명 방문자 진입은 마킹이 없어 reason 미부여 (정책 유지: 한 번도 로그인하지 않은 사용자에게 "세션 만료" 안내 차단) (Issue #301 후속, LayoutLoader · TemplateApp)
- `apiClient.setOnUnauthorized` 콜백이 데이터소스 401 시 로그인 페이지로 redirect 할 때 `reason` 인자를 전달하지 않아 안내 토스트 트리거가 누락되던 문제 수정 — 콜백 발동은 토큰이 서버에서 거부되었음을 의미하므로 항상 `reason='session_expired'` 부여. layout fetch 401 가드와 데이터소스 401 콜백 두 경로 모두 동일한 안내 흐름으로 통일 (Issue #301 후속, TemplateApp)

### Notes

- 본 패치 디버깅 과정에서 발견된 G7 표현식 컨텍스트 규약 명문화: URL query string 은 root 컨텍스트에 `query.xxx` / `query?.xxx` 로 **직접 노출**되며 `route.query` 경로는 존재하지 않음. `route.xxx` 는 path params 만 (예: `/users/:id` → `route.id`). docs/frontend/data-binding.md 에 회귀 차단 가이드 추가

### Notes

- 본 패치는 init_actions/액션의 `if` 표현식 작성 패턴을 명확히 하지는 않으나 관련 회귀 차단 회기에 함께 추가됨: `if` 값은 `ConditionEvaluator.evaluateStringCondition` 이 평가하며, `{{}}` 외부 텍스트는 보간되지 않고 그대로 문자열로 남아 `Boolean()` 판정 시 항상 truthy 가 된다. 비교/논리 연산이 포함된 식은 반드시 **전체를 `{{}}` 한 쌍으로 감싸야** 식으로 평가된다 (예: `"{{x === 'y'}}"`). 이는 docs/frontend/data-binding.md / layout-json-features.md 가이드에 명시되어 있으며, 본 릴리즈에서 문서 측 보강은 진행하지 않음 (audit 룰 후속 advisory)

## [engine-v1.47.0] - 2026-04-29

### Added

- 레이아웃 fetch 401 재시도 실패 시 코어가 로그인 페이지로 자동 리다이렉트 (Issue #301) — 토큰 만료/권한 부족으로 레이아웃을 받지 못한 사용자가 "페이지 로딩 실패" 에러 화면 대신 로그인 화면으로 이동하고 `?reason=session_expired` 쿼리로 안내 토스트가 트리거됨. 인증 타입은 `templateId` 또는 pathname 의 `/admin` prefix 로 결정 (TemplateApp.showRouteError)
- `AuthManager.getLoginRedirectUrl(type, returnUrl, reason?)` 세 번째 옵셔널 인자 — `reason='session_expired'` 등 사유를 쿼리 파라미터로 결합. 기존 호출처는 인자 미지정으로 하위호환 (AuthManager)
- `AuthManager.updateConfig(type, partial)` public setter — 템플릿 부트스트랩에서 `loginPath` 등 인증 설정을 사이트 단위로 커스터마이즈 가능. 보안: `loginPath` 는 동일 origin path-only(`/`로 시작, `//` 금지)만 허용 (open redirect 차단 — 외부 origin/protocol-relative URL 은 throw). 모듈/플러그인 호출은 다른 템플릿 침범 위험으로 가이드 문서에서 금지 (AuthManager)

## [engine-v1.46.1] - 2026-04-28

### Fixed

- (engine-v1.46.1) `IdentityGuardInterceptor.handle()` retry fetch 가 원 요청의 body/headers/credentials 를 보존하도록 수정 — 두 번째 인자 `originalRequest` 추가. 과거 retry 가 빈 body 로 호출되어 회원가입 등 모든 POST 흐름이 IDV verify 통과 후 422 (필수 필드 누락) 로 실패하던 회귀 차단. ActionDispatcher.handleApiCall 가 `options.body/headers/credentials` 를 그대로 전달 (IdentityGuardInterceptor, ActionDispatcher)

## [engine-v1.46.0] - 2026-04-27

### Added

- IDV 공용 타입 모듈 `resources/js/core/identity/types.ts` — `VerificationPayload`, `IdentityResponse428`, `VerificationResult` (4-상태: `verified | pending | cancelled | failed`), `ModalLauncher`, `ResolveIdentityChallengeParams`, `IdentityRedirectStash`, `IDENTITY_REDIRECT_STASH_KEY` 정리. 외부 IDV provider 플러그인이 동일 타입을 import 해 사용
- `resolveIdentityChallenge` 액션 핸들러 — 본인인증 모달 / 풀페이지 / 외부 SDK callback 이 launcher 의 deferred Promise 에 verify 결과를 통보하는 표준 진입점. params 의 `result` 가 `verified|pending|cancelled|failed` 4-상태이며 누락/오타는 안전한 기본값으로 강등 처리 (ActionDispatcher)
- `IdentityGuardInterceptor.createDeferred()` / `resolveDeferred()` — launcher 가 모달 결과를 await 하기 위한 deferred resolver API. 두 launcher 가 동시 활성화되면 이전 resolver 는 자동으로 `cancelled` 처리 (IdentityGuardInterceptor)
- `IdentityGuardInterceptor.redirectExternally()` 헬퍼 — `external_redirect` 흐름에서 sessionStorage 에 stash 후 `window.location.href` 로 이동. stash 키는 코어 상수 `IDENTITY_REDIRECT_STASH_KEY` 로 통일 (IdentityGuardInterceptor)
- `defaultLauncher` — launcher 미등록 외부 템플릿용 폴백. 토스트 발행("본인 확인이 필요합니다") + `/identity/challenge?return=...` navigate. G7Core 미초기화 시 `console.error` + `failed/G7_NOT_READY` 로 강등 (IdentityGuardInterceptor)

### Changed

- `ModalLauncher` 반환 타입을 `Promise<VerificationResult>` 로 확장 (기존 `Promise<boolean>` → 4-상태 객체). PortOne/Stripe Identity 같은 외부 SDK 가 검증 데이터를 함께 돌려주는 케이스 + Stripe webhook 모델 같은 비동기 검증 인터페이스 예약 (IdentityGuardInterceptor)
- `IdentityGuardInterceptor.handle()` 가 verify 성공 시 `return_request.url` 에 `verification_token` 을 query string 으로 자동 부착해 재실행. 회원가입 폼이 이미 사용 중인 `query.verification_token` 패턴과 호환 (IdentityGuardInterceptor)

## [engine-v1.45.0] - 2026-04-24

### Added

- startInterval / stopInterval 액션 핸들러 — `params.id` 기반 setInterval 등록/중단. 카운트다운 타이머 등 주기적 UI 업데이트에 사용. 같은 id 재등록 시 기존 타이머 자동 정리, `stopAllIntervals()` 로 일괄 중단 가능 (ActionDispatcher)

## [engine-v1.44.0] - 2026-04-24

### Added

- IdentityGuardInterceptor — HTTP 428 `identity_verification_required` 응답을 감지해 모달 launcher 호출 후 return_request 자동 재실행. launcher 는 S8 공통 모달 레이아웃이 제공. ActionDispatcher.handleApiCall 응답 후처리 한 곳에 정적 메서드로 직접 위임 — 별도 디스패처 인프라 없이 모든 apiCall 의 choke point 효과 (IdentityGuardInterceptor)

<!-- ⚠️ 버전 재번호 부여 검토 필요 (정책 결정 대기): 아래 v1.43.1 은 시간상 v1.45.0/v1.44.0 보다 늦지만(2026-04-25), develop 브랜치에서 별도 발행되어 버전 번호 모순 발생. v1.45.1 또는 v1.46.0 으로 재번호 검토 필요. -->

## [engine-v1.43.1] - 2026-04-25

### Fixed

- `responsive.{breakpoint}.iteration` 오버라이드 케이스에서 무한 재귀로 worker OOM 발생 — `renderIteration`이 자식 wrapper 렌더 시 `componentDefWithoutIteration` 에 `iteration: undefined` 만 적용하고 `responsive` 는 유지하여, 자식의 `effectiveComponentDef` 머지에서 `responsive.{bp}.iteration` 이 다시 적용되어 iteration 이 부활하는 무한 루프. `responsive: undefined` 도 함께 제거하여 머지 1회로 한정 (DynamicRenderer)

## [engine-v1.43.0] - 2026-04-22

### Fixed

- Form 자동바인딩 값이 `globalState._local`에 동기화되지 않아 CKEditor5 등 `setLocal({render:false})` 플러그인과 공존하는 폼에서 자동바인딩 값이 누락되던 문제 수정. `performStateUpdate`가 기존 React `localDynamicState` 쓰기와 함께 `G7Core.state.setLocal(..., {render:false})`로 globalState._local에 동기화 기록. 이중 저장소 구조는 성능상 의도적으로 유지하며, 자세한 배경은 DynamicRenderer.tsx `performStateUpdate` 상단 주석 참조 (DynamicRenderer)

### Added

- 자동바인딩 경로 레지스트리 `__g7AutoBindingPaths` — Input 마운트 시 `fullPath`를 reference count 기반으로 등록/해제. iteration 내 중복·React Strict Mode 이중 마운트 대응 (DynamicRenderer)
- `G7Core.state.setLocal(..., {render:false})` 호출이 자동바인딩 경로와 겹치면 엔진이 자동으로 render:true로 승격 — 미래 플러그인이 자동바인딩 대상 필드를 render:false로 쓰더라도 저장소 A↔B 정합성 구조적 보장 (G7CoreGlobals)
- `G7Core.state.setLocal` 옵션 `selfManaged: true` 신설 — 플러그인이 자체 DOM 관리를 의도적으로 선언하는 opt-out 마커. 명시 시 자동 승격 제외하여 render:false 유지(성능 보존). CKEditor5처럼 React 밖에서 DOM을 관리하는 플러그인 전용. 기본값 undefined(=false)는 safe-by-default (G7CoreGlobals)
- SPA 네비게이션 시 `__g7AutoBindingPaths`를 빈 `Map`으로 재초기화 — 이전 페이지 컴포넌트 언마운트와 라우트 전환 경쟁으로 인한 stale 경로 잔존 방지 (TemplateApp)
- `G7DevToolsCore.getDualStorageMismatch()` 진단 메서드 — 저장소 A(localDynamicState) / B(globalState._local) 불일치 leaf 경로 감지. Phase 1 이후 유지보수 중 쓰기 경로 누락 조기 발견을 위한 보조 안전망 (G7DevToolsCore)

## [engine-v1.42.0] - 2026-04-16

### Added

- `render: false` 선택적 리렌더 제어 — 상태 값은 저장하되 React 리렌더를 건너뛰는 옵션. CKEditor 등 자체 DOM을 관리하는 플러그인에서 타이핑 중 전체 폼 리렌더(37,000+ 바인딩 재평가) 방지. `flushPendingDebounceTimers` 실행 시 항상 render: true 강제로 저장 직전 데이터 정합성 보장 (TemplateApp, G7CoreGlobals, ActionDispatcher)
  - `G7Core.state.setLocal(updates, { render: false })` — 로컬 상태 사일런트 업데이트
  - `G7Core.state.set(updates, { render: false })` — 전역 상태 사일런트 업데이트
  - `setState` 핸들러 `render: false` — 레이아웃 JSON 액션 레벨 옵션: `{ "handler": "setState", "render": false }`

## [engine-v1.41.0] - 2026-04-16

### Added

- `G7Core.state.setLocal()` `debounce`/`debounceKey` 옵션 — 프로그래매틱 호출에서 G7 표준 디바운스 사용 가능. ActionDispatcher의 기존 타이머 인프라(자동 정리, flushPendingDebounceTimers 연동)를 활용 (G7CoreGlobals)
- `G7Core.dispatch()` `debounce`/`debounceKey` 옵션 — 액션 시스템 진입점에서도 표준 디바운스 지원 (G7CoreGlobals)
- `ActionDispatcher.debouncedCall()` 공개 메서드 — 프로그래매틱 debounce용 타이머 관리 (ActionDispatcher)

### Fixed

- `setLocal()` 내부 `baseLocal` 계산 시 stale `actionContext.state`의 빈 배열이 `globalLocal`의 정상 API 데이터를 `deepMerge`로 교체하는 오염 경로 차단 — `deepMerge` → `addMissingLeafKeys` 전략으로 변경하여 `globalLocal`(committed 상태)의 기존 값을 보존하면서 `dynamicLocal`(setState 전용 키)만 안전하게 추가. CKEditor 등 플러그인 onMount에서 `setLocal` 호출 시 init_actions 기본값(빈 배열)이 API 데이터를 덮어쓰는 문제 해소 (G7CoreGlobals)
- SPA 네비게이션 시 `_localInit` 미적용 상태에서 stale `dynamicState` 병합 방지 — `lastProcessedInitRef` ref 기반 감지로 `extendedDataContext` 병합에서 stale `dynamicState` 건너뛰기 (DynamicRenderer)

## [engine-v1.40.0] - 2026-04-15

### Added

- `navigate` 핸들러 `params.fallback` 옵션 — 대상 경로가 현재 템플릿의 `routes.json`에 매칭되지 않을 때 대체 핸들러로 분기. **기본값은 `openWindow`**(새 창 열기)로, 관리자 ↔ 사용자 템플릿 간 경로 교차 이동 시 404 페이지 대신 새 창으로 열림. `fallback: false` 로 비활성화 가능, `fallback: "핸들러명"` 또는 `fallback: { handler, params }` 로 커스텀 지정 가능. `replace: true` 분기(쿼리 갱신 전용)에는 미적용. 알림센터에서 알림 클릭 시 교차 템플릿 경로 접근 문제 해결 (ActionDispatcher)

## [engine-v1.39.0] - 2026-04-15

### Changed

- 확장 에셋 로딩 병렬화 — `ModuleAssetLoader.loadActiveExtensionAssets()` 가 JS 번들을 `for...await` 로 직렬 fetch 하던 것을 `Promise.all(map(loadJS))` 병렬 fetch 로 전환. 실행 순서는 `script.async = false` + priority 정렬 순서로 DOM append 되어 HTML 사양에 따라 그대로 보장됨. 사용자 화면 진입 시 5개 IIFE 확장 기준 staircase 로딩이 ~1.3초 → ~350ms 로 단축. 코드베이스 감사 결과 cross-extension 참조·priority 차등·등록 순서 의존성 모두 없음을 확인한 후 적용 (ModuleAssetLoader)

## [engine-v1.38.2] - 2026-04-15

### Fixed

- (engine-v1.38.2) `{{$event.target.checked ? '$t:admin.modules.activate_success' : '$t:admin.modules.deactivate_success'}}` 같은 **조건부 표현식 안의 따옴표 `$t:` 패턴이 raw key 를 토스트로 노출하던 버그** — `DataBindingEngine.preprocessTranslationTokens()` 가 따옴표 안의 `'$t:KEY'` 를 `$t('KEY')` 함수 호출로 변환하고, `$t()` 헬퍼가 `context.$templateId` 를 templateId 로 사용하는데, ActionDispatcher 의 `createHandler()` 가 빌드한 action data context 가 항상 `$templateId` 를 포함하지는 않아 빈 templateId 로 lookup 이 실패하던 문제. `$t()` 가 이제 `context.$templateId` 가 없으면 `window.__templateApp.getConfig()` 로부터 templateId/locale 을 회수한다 (DataBindingEngine). admin_module_list / admin_plugin_list / admin_role_list 등 onSuccess 토스트 메시지가 다시 정상 번역됨

## [engine-v1.38.1] - 2026-04-15

### Fixed

- (engine-v1.38.1) `reloadExtensions` 병렬 블록에서 동시에 실행되는 `toast($t:...)` 가 raw 다국어 키(예: `admin.modules.activate_success`)를 그대로 노출하던 경합 — `TranslationEngine.setCacheVersion()` 내부의 `clearCache()` 호출이 활성 `this.translations` 맵을 비워서, 새 `loadTranslations()` 가 fetch를 완료하기 전에 실행되는 `translate()` 호출이 빈 사전을 만나 폴백 규칙(3번째 단계)에 따라 원본 키를 반환하던 문제. `setCacheVersion()` 이 이제 TTL 캐시(`this.cache`)만 비우고 활성 사전(`this.translations`)은 그대로 유지 — `loadTranslations()` 완료 시점에 `translations.set(cacheKey, dictionary)` 로 원자 교체되므로 레이스 윈도우가 제거됨. `TemplateApp.reloadExtensionState()` 도 명시적 `clearCache()` 호출을 제거. 유사 사례 전수 적용(레이아웃 수정 불요) — 모든 `$t:` 해석 경로가 동일 보호를 받음 (TranslationEngine, TemplateApp)

## [engine-v1.38.0] - 2026-04-15

### Added

- `reloadExtensions` 통합 핸들러 — 확장(모듈/플러그인/템플릿)의 install/activate/deactivate/uninstall 직후 onSuccess 체인에서 호출하여 페이지 전체 새로고침 없이 확장 상태를 원자적으로 재동기화. 내부적으로 `TemplateApp.reloadExtensionState()` 를 호출하여 최신 `cache_version` 획득 → localStorage 반영 → Router routes 재fetch → LayoutLoader 캐시 클리어 → TranslationEngine 재로드까지 일괄 수행. 선택 파라미터 `{ moduleInfo, pluginInfo, action: "add"|"remove" }` 전달 시 `reloadModuleHandlers` / `reloadPluginHandlers` 로직도 통합 실행하여 JS/CSS 에셋을 동적으로 로드/제거 (ActionDispatcher)
- `TemplateApp.reloadExtensionState()` public 메서드 — config.json 에서 새 cache_version 획득 후 Router/LayoutLoader/TranslationEngine 을 순차 갱신. 각 단계는 try/catch 로 격리되어 한 단계 실패가 다른 단계를 막지 않음 (TemplateApp)
- `Router.loadRoutes(cacheVersion?: number)` 파라미터 — 전달 시 `?v=${version}` 쿼리로 부착되어 백엔드 `PublicTemplateController::getRoutes` 의 응답 캐시 버전 키를 일치시킴. 미전달 시 구 버전 동작 유지 (Router)

### Fixed

- 확장 활성화/비활성화 후 전체 새로고침 없이는 새 라우트가 반영되지 않던 버그 — `Router.loadRoutes()` 가 캐시 버전 쿼리 없이 `/api/templates/{id}/routes.json` 을 요청하여 백엔드의 `template.routes.{id}.v0` 캐시 키에 과거 응답이 고정되거나 TTL(1시간) 동안 오염된 상태가 재사용되던 문제. `reloadExtensions` 핸들러가 최신 `cache_version` 으로 fetch 하도록 수정하여 activate API 완료 시점에 즉시 routes 가 반영됨 (Router, TemplateApp, ActionDispatcher)

### Deprecated

- `reloadRoutes` 핸들러 — `reloadExtensions` 로 대체 예정. 현재는 `TemplateApp.reloadExtensionState()` 로 위임하여 하위 호환 유지 (ActionDispatcher)
- `reloadTranslations` 핸들러 — `reloadExtensions` 로 대체 예정. 현재는 `TemplateApp.reloadExtensionState()` 로 위임 (ActionDispatcher)

## [engine-v1.37.0] - 2026-04-14

### Added

- `navigate` 핸들러 `params.scroll` / `params.scrollBehavior` 옵션 — 페이지 이동 후 스크롤 위치를 선언적으로 제어. 지원 값: `"top"` (기본) / `"preserve"` / 숫자 / `{x, y}` / `"#selector"`. `requestAnimationFrame`으로 다음 tick에 적용하여 새 레이아웃 DOM 반영 후 정확한 위치로 이동. `replace: true` 분기(`updateQueryParams`)에도 동일하게 적용. `scrollBehavior` 기본값은 `"instant"` — 템플릿 CSS의 `scroll-behavior: smooth` 전역 설정을 무시하고 페이지 전환 시 즉시 이동하도록 강제. `"smooth"` 명시 시에만 부드러운 스크롤 (ActionDispatcher)
- `replaceUrl` 핸들러 `params.scroll` / `params.scrollBehavior` 옵션 — 기본값은 `"preserve"` (URL만 교체하는 용도이므로 스크롤 유지). 명시적으로 `"top"` 등 지정 가능 (ActionDispatcher)
- `scroll: "top"` 동작 시 `window` 뿐 아니라 `#app` 내부의 모든 스크롤 컨테이너(`overflow-y: auto|scroll` 또는 `overflow-x: auto|scroll`)를 함께 상단으로 리셋 — 관리자 템플릿처럼 `html/body`가 `overflow: hidden`이고 내부 div가 실제 스크롤 컨테이너인 구조에서도 `window.scrollTo`만으로는 효과가 없어 스크롤이 이동하지 않던 문제 해결 (ActionDispatcher)
- `scroll` 확장 객체 문법 — `{ container?, to?, block?, offset? }` 형태로 특정 스크롤 컨테이너 지정, sticky 헤더 오프셋, `scrollIntoView`의 `block` 위치(`start`/`center`/`end`/`nearest`) 선택을 지원. 관리자 템플릿의 `#right_content_area` 같은 내부 스크롤 컨테이너에 대해 Y 좌표 이동, 엘리먼트로 이동(중앙/상단/하단 정렬), 오프셋 보정이 가능 (ActionDispatcher)

### Changed

- **Breaking**: `navigate` 핸들러 기본 스크롤 동작 변경 — 기존에는 페이지 이동 후 이전 스크롤 위치가 그대로 유지되어 일반적인 하이퍼링크 이동 UX와 어긋났음. 이제 기본값 `"top"`으로 이동 시 최상단에서 새 페이지가 시작됨. 스크롤 유지가 필요한 케이스(검색 필터, 페이지네이션 등)는 `scroll: "preserve"` 명시 필요 (ActionDispatcher)

## [engine-v1.36.0] - 2026-04-13

### Added

- `navigate` 핸들러 `params.transition_overlay_target` 옵션 — `replace: true` 로 `updateQueryParams` 경로 진입 시 `transition_overlay.target` 을 호출별로 동적 override. 환경설정 알림 탭 안의 채널 탭 등 **탭 속 서브 탭** 클릭 시 서브 탭 콘텐츠 영역에만 spinner 가 표시되도록 한다. 미지정 시 `transition_overlay.target` (베이스 또는 자식 merge 결과) 사용 (ActionDispatcher, G7CoreGlobals, TemplateApp)
- `G7Core.updateQueryParams(newPath, options?)` — 두 번째 인자 `options.transitionOverlayTarget` 로 호출별 target override 지원 (G7CoreGlobals, TemplateApp)

## [engine-v1.35.0] - 2026-04-13

### Added

- `updateQueryParams` 경로에서 `transition_overlay` spinner/skeleton 자동 트리거 — `navigate replace:true` 로 탭 전환/검색/페이지네이션 시 handleRouteChange 대신 updateQueryParams 경로에 진입할 때도 `blocking` 또는 `wait_for` 에 명시된 progressive 데이터소스가 1개 이상 refetch 대상이면 오버레이 표시. fetch 완료/에러 시 자동 hide. handleRouteChange step 2.5 와 동일 정책 (TemplateApp)

## [engine-v1.34.0] - 2026-04-13

### Added

- `transition_overlay.wait_for` 옵션 — blocking 데이터소스가 없는 페이지에서도 명시된 progressive 데이터소스가 fetch 완료될 때까지 spinner/skeleton 오버레이가 표시되도록 하는 명시적 가드. background/websocket 데이터소스는 의도상 사용자 차단 불가하므로 자동 무시되며 백엔드 검증(UpdateLayoutContentRequest)에서 사전 차단됨 (TemplateApp, LayoutLoader 타입)

### Changed

- `LayoutService::mergeLayouts` 의 `transition_overlay` 병합을 shallow merge 로 변경 — 자식 레이아웃이 `wait_for` 만 명시해도 부모(베이스)의 `enabled/style/target/spinner` 설정이 보존되어 자식이 부분 override 가능. boolean/비배열 케이스는 기존 자식 우선/부모 폴백 유지 (LayoutService)

## [engine-v1.33.1] - 2026-04-10

### Fixed

- WebSocket 리스너 중복 누적 버그 수정 — `WebSocketManager.unsubscribe()`가 `subscriptions` Map에서만 엔트리를 제거할 뿐 Echo 채널의 `listen()` 리스너를 해제하지 않아, route 변경 시 재구독 시 Echo가 동일 채널 인스턴스를 재사용하며 listener가 누적됨. 결과: 단일 route에서 알림 폼 저장 등으로 route가 여러 번 전환되면 N번 중복 수신 (예: 수정 창에서 비밀번호 변경 알림 토스트 8번 실행). `channelInstance.stopListening('.${event}')` 명시 호출로 수정 (WebSocketManager.ts)

## [engine-v1.33.0] - 2026-04-10

### Added

- WebSocket 데이터소스에 `onReceive` 액션 배열 지원 — 메시지 수신 시 정의된 액션들을 순차 실행. `$args[0]` 또는 `$event`로 페이로드 접근 가능. refetchDataSource/toast 등 모든 핸들러 사용 가능. 사용 예시: 알림 수신 시 `notification_unread_count` 자동 refetch + 토스트 표시 (DataSourceManager.ts, TemplateApp.ts)

## [engine-v1.32.5] - 2026-04-09

### Fixed

- `updateQueryParams` 데이터소스 refetch 인덱스 매핑 어긋남 — WebSocket 소스가 `currentDataSources`에 포함된 레이아웃(예: `_admin_base.json`의 `notification_ws`)에서 `navigate replace:true`(탭 전환 등) 호출 시 `fetchDataSourcesWithResults`가 WebSocket을 내부 필터링하여 results 배열이 짧아지는데 호출자는 `autoFetchDataSources[i]`로 매핑해 데이터가 잘못된 키에 기록됨. 증상: 환경설정/알림설정 탭 클릭 시 `_local.form`이 다른 데이터소스 응답으로 초기화되어 탭 내용이 빈 화면 (TemplateApp)
- 원인 1: `updateQueryParams` 가 인덱스 기반 `for (let i = 0; i < results.length; i++) { autoFetchDataSources[i] }` 매핑 사용 → `handleRouteChange` blocking 경로(`results.forEach((r) => blockingData[r.id] = r.data)`)와 일관성 없음
- 수정 1: `updateQueryParams` 를 `result.id` 기반 Map 조회로 변경하여 handleRouteChange 패턴과 통일 (TemplateApp)
- 원인 2: `updateQueryParams` 가 WebSocket 소스를 사전 제외하지 않아 `fetchDataSourcesWithResults` 내부 silent filter와 계약 불일치
- 수정 2: `updateQueryParams` 에서 WebSocket 사전 제외 추가 — handleRouteChange progressive 경로(engine-v1.32.2)와 동일 정책 (TemplateApp)
- 원인 3: `fetchDataSourcesWithResults` 의 내부 WebSocket 필터가 silent — 호출자가 WebSocket을 넘겨도 경고 없이 조용히 제거되어 인덱스 매핑 어긋남 버그가 숨겨짐
- 수정 3: `fetchDataSourcesWithResults` 에서 WebSocket 소스 수신 시 경고 로그 추가 — 내부 필터는 safety net으로 유지하되 호출자 필터 누락을 조기 발견 가능 (DataSourceManager)

## [engine-v1.32.4] - 2026-04-09

### Fixed

- WebSocket 채널 빈 세그먼트 검증 강화 — 표현식이 undefined로 평가되어 빈 문자열로 치환되면 trailing dot/연속 dot이 발생해도 미평가 마커(`{{`)가 없어 방어 로직을 우회. `isInvalidChannel` 헬퍼 추가하여 trailing/leading/연속 빈 세그먼트(`.`, `:`)도 감지 후 구독 건너뜀. 진단을 위해 컨텍스트 키 목록을 경고 로그에 포함 (DataSourceManager)
- WebSocket 구독 직전 컨텍스트 키 디버그 로그 추가 — 표현식 평가 실패 진단용 (TemplateApp)

## [engine-v1.32.3] - 2026-04-09

### Fixed

- WebSocket 채널/이벤트 표현식 평가 누락 — `core.user.notifications.{{current_user.data.id}}` 같은 표현식이 평가 없이 그대로 구독되어 백엔드 채널 패턴 매칭 실패 → broadcasting/auth가 AccessDeniedHttpException 발생. `subscribeWebSockets`에 `bindingContext` 파라미터 추가, channel/event 모두 `resolveExpressionString`으로 평가 (DataSourceManager)
- WebSocket 구독 시점이 progressive fetch 이전이라 fetched 데이터 참조 표현식 평가 불가 — Step 6 WebSocket 구독을 progressive fetch 완료 후(Step 7)로 이동하여 모든 데이터소스가 평가 컨텍스트에 포함되도록 함 (TemplateApp)
- WebSocket 미평가 표현식 방어 로직 — 평가 후에도 `{{` 마커가 남아있으면 구독 건너뛰고 경고 로그 (DataSourceManager)

## [engine-v1.32.2] - 2026-04-09

### Fixed

- WebSocket 데이터소스가 progressive 목록에 포함되어 blur_until_loaded 영구 블러 — WebSocket은 이벤트 리스너이지 데이터 제공자가 아니므로 progressive 초기화에서 제외 (TemplateApp)

## [engine-v1.32.1] - 2026-04-09

### Fixed

- auth_mode: 'required' 데이터소스에서 토큰 없을 때 API 요청 스킵 — 비로그인 상태에서 인증 필수 API 호출 → 401 → onUnauthorized 로그인 리다이렉트 무한 루프 방지 (DataSourceManager)

## [engine-v1.32.0] - 2026-04-08

### Added

- extensionPointCallbacks — extension_point에서 콜백 액션 객체를 props와 분리하여 전달하는 기능 추가 (DynamicRenderer, LayoutExtensionService)
  - `props`: 데이터 전달용 — `resolveObject()`로 표현식 재귀 평가 (일반 props와 동일 수준)
  - `callbacks`: 액션 객체 전달용 — 평가 없이 그대로 전달 (ActionDispatcher 실행 시점 평가)

### Fixed

- extensionPointProps 표현식 미평가 버그 수정 — 호스트가 `"{{route.id}}"` 같은 표현식을 넘기면 평가 없이 raw 문자열로 전달되던 문제 해결 (DynamicRenderer)

## [engine-v1.31.0] - 2026-04-02

### Added

- resolvedProps 참조 안정화 — 바인딩 해석 결과의 값이 이전과 동일하면 이전 객체 참조를 반환하여 하위 컴포넌트의 React.memo가 실제로 작동하도록 개선 (DynamicRenderer)
- ComponentRegistry에서 컴포넌트 등록 시 React.memo 자동 래핑 — props 변경이 없는 컴포넌트의 불필요한 리렌더링 방지 (ComponentRegistry)

### Fixed

- DevTools 캐시 탭 Hit Rate 카드와 프로그레스 바에서 hitRate(0~1)를 백분율 변환하지 않아 0.9%로 표시되던 버그 수정 (CacheTab)

## [engine-v1.30.0] - 2026-04-02

### Added

- SortableItemWrapper에 `as` prop 추가 — wrapper 요소를 지정 가능 (기본: `div`, Table 내부: `tr`)
- DynamicRenderer에서 sortable 설정의 `wrapperElement` 옵션을 SortableItemWrapper에 전달

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
