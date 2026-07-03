# Template Engine Changelog

> 이 문서는 그누보드7 템플릿 엔진의 내부 개발 버전 이력입니다.
> `engine-v1.x.x` 버전은 그누보드7 릴리스 버전과 독립적입니다.
>
> 형식: [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)

## [engine-v1.51.0] - 2026-07-03

### Changed

#### 레이아웃 편집기 번들 분리 (초기 접속 payload 축소)

- 코어 번들(`template-engine.min.js`)에서 레이아웃 편집기 코드를 분리해 별도 `layout-editor.min.js` 로 빌드한다. 편집기는 `/admin/layout-editor/*` 진입 시에만 런타임 `<script>` 주입으로 지연 로드된다. 일반 페이지 초기 접속 gzip payload 458KB → 261KB (약 43% 감소).
- `template-engine.ts` — `LayoutEditorChrome` 정적 import 제거. `renderTemplate()` 편집기 분기가 `loadLayoutEditorBundle()`(신규)로 번들을 지연 주입한 뒤 `window.G7Core.__LayoutEditorChrome` 를 기존과 동일한 provider 트리(Translation/Transition/Responsive/Slot) 안에서 렌더한다. 로드 실패 시 인라인 에러 화면 폴백. `checkLayoutEditorMode`(dep-free URL 파서)는 코어에 유지.
- `G7CoreGlobals.ts` — `initCoreRuntimeExports()`(신규)가 코어 런타임 표면(DynamicRenderer/엔진 싱글톤/컨텍스트/Logger/AuthManager)을 `window.G7Core.__runtime` 으로 노출. 편집기 번들은 빌드 시 alias-shim(`layout-editor/__runtime-shims/`)으로 이 전역을 빌려 써 코어 런타임을 0바이트 중복으로 재사용하고 싱글톤/React Context 동일성을 보장한다.
- `layout-editor-entry.ts` + `vite.config.editor.js`(신규) — 편집기 번들 엔트리/빌드 설정. React/ReactDOM/`react/jsx-runtime` 은 external → `window.React` 등 단일 인스턴스 재사용(자체 React 사본 미포함). 코어 런타임은 커스텀 `resolveId` 플러그인으로 shim 치환.
- `core:build`(`BuildCoreCommand.php`) — 엔진 번들 + 편집기 번들을 순차 빌드. `--watch` 는 양 번들을 `vite build --watch` 로 병렬 감시.
- 기존 편집기 확장점 stub/큐 핸드셰이크(`initLayoutEditorStub` → `exposeLayoutEditorGlobals`, engine-v1.50.0)가 번들 경계를 넘어 그대로 동작(편집기 로드 시 큐 flush).

#### DevTools 번들 분리 (디버그 전용 코드 초기 로드 제거)

- 개발자 진단도구(DevTools)의 디버그 전용 무거운 모듈(패널 UI/진단엔진/서버커넥터/스타일추적기)을 메인 코어 번들에서 분리해 별도 `devtools.min.js` 로 빌드한다. `initDevToolsAPI()` 가 디버그 모드(`isEnabled()`)일 때만 런타임 `<script>` 주입으로 로드한다. 디버그 꺼진 일반 사용자는 이 코드를 받지 않는다. 레이아웃 편집기 분리와 합쳐 초기 접속 gzip payload 458KB → 221KB (약 52% 감소).
- `G7CoreGlobals.ts` — `DiagnosticEngine`/`ServerConnector`/`StyleTracker`/`DevToolsPanel` 정적 import 제거. `initDevToolsAPI()` 는 디버그 OFF 시 최소 API 만 노출(번들 미로드), ON 시 `loadDevToolsBundle()`(신규)로 `window.G7Core.__devtools` 를 지연 로드한 뒤 진단/서버덤프/패널을 활성화. `G7DevToolsCore`(추적 코어, 디버그 무관 항상 필요)는 메인 번들에 상주하며 `G7Core.__runtime` 으로 공유.
- `devtools-entry.ts` + `vite.config.devtools.js`(신규) — DevTools 번들 엔트리/빌드. React external → `window.React` 단일 인스턴스, `G7DevToolsCore` 는 shim(`devtools/__runtime-shims/`)으로 메인 번들과 공유(재번들 0바이트, 싱글톤 동일성).
- `core:build` — 엔진 + 편집기 + DevTools 3개 번들 순차 빌드. `--watch` 는 3개 `vite build --watch` 병렬.

## [engine-v1.50.0] - 2026-06-29

### Added

#### 위지윅 레이아웃 편집기 — 인프라 / 진입

- `/admin/layout-editor/{identifier}` URL 분기 신설 — `template-engine.ts` `renderTemplate()` 가 코어 컨텍스트 래퍼(Translation/Transition/Responsive/Slot) 안에 `LayoutEditorChrome` 셸을 렌더한다. `LayoutEditorContext`(useReducer 도메인 상태) + `EditorToolbar` + `RouteTreePanel`(5그룹: 공통 레이아웃/템플릿/모듈/플러그인/모달, `source` 메타 기반) + `EditEntryFab`(운영 화면 좌측 하단 편집 진입) 구성.
- 진입 경로 — 템플릿 관리 화면에 `[코드 편집]`+`[레이아웃 편집]` 2버튼, 운영 화면 FAB(편집 권한자 한정, `?route={path}` 로 보던 화면 선택 진입), `window.G7Config.activeModules`/`activePlugins` 메타 키 신설.
- 비활성 템플릿 admin 자산 서빙 — `AdminTemplateAssetController`(getEditorAssets/serveComponents/serveRoutes/serveEditorSpec/serveLanguage) + `core.templates.layouts.edit` 가드 + 활성→`_bundled` 폴백. 비활성 템플릿도 편집/저장 가능.
- 편집기 셸 다국어 — `$t:layout_editor.*`(chrome/palette/insertion/overlay/save/preview/device/zoom/access_error 등) ko/en partial + g7-core-ja 번들 동기.
- URL ↔ selectedRoute 양방향 동기화(`buildEditorUrl`/`useEditorUrlSync`, popstate 복원), 좌측 패널 접힘 영속화(`localStorage`), 라우트 트리 검색 + 키워드 `<mark>` 강조(다국어 표시 라벨·path 양쪽 매칭).

#### 프리뷰 캔버스 / 샘플 데이터 / 격리 store

- `PreviewCanvas` — 백엔드 병합 응답(`with_source_meta=1`)을 `DynamicRenderer` 로 실제 렌더하고, 데이터소스는 네트워크 fetch 없이 `sampleDataProvider`(5단계 우선순위: spec_byId→spec_byEndpoint→core_preset→fallback→inferred, 6종 코어 프리셋)로 채운다. `__source` 출처 네임스페이스로 같은 data_source id 의 확장별 shape 충돌을 해소.
- 캔버스↔호스트 격리 store façade(`installPreviewCanvasStore`) — 마운트 동안 `window.__templateApp`/`G7Core.dispatch`/`modal`/`toast` 를 in-memory 격리 인스턴스로 swap, 언마운트 시 복원. `ComponentRegistry.createIsolatedInstance()` + `ActionDispatcher.getHandler()` public 노출로 호스트 싱글톤과 충돌 회피. 라우트 `:param` 토큰은 `deriveSampleRouteParams` 휴리스틱으로 자동 주입.
- 출처 메타 옵션 — `LayoutService::getLayout/loadAndMergeLayout` `withSourceMeta` 로 각 노드에 `__source:{kind:base|route|extension,...}` 부여, 편집 응답에만 자식 `lock_version` + `__editor.original`(저장 SSoT) 동봉. 일반 렌더는 응답 100% 동일. `DynamicRenderer` 가 `__` 접두 메타 키를 DOM 전달 직전 일괄 차단.
- 반응형 디바이스 미리보기 — 데스크톱/태블릿/모바일 토글 + 줌 슬라이더 + `custom` 폭 입력(320~1920px 클램프). 디바이스 폭이 캔버스보다 넓으면 `transform:scale()` 시각 축소.

#### 요소 추가 / 드래그앤드롭 / undo·redo

- 요소 추가 팔레트(`ComponentPalette`) — 카테고리 사이드바 + 검색(영문명·태그·다국어 표시 라벨 매칭) + 그리드 카드. `editorSpec.componentPalette.groups`(템플릿 소유) 정의대로 카테고리 렌더, 미제공 시 components.json type 폴백. `nesting.accepts` 로 부모가 받을 수 있는 컴포넌트만 노출. 신규 노드 골격은 `entries[name].defaultNode`(템플릿 SSoT, 두 번들 템플릿 전수 작성) 우선·`props.default` 폴백, 미정의 시 클릭 비활성 + 배지. audit `editor-spec-i18n-strings`(평문 차단) coverage 등록.
- 삽입 어포던스(`InsertionAffordances`/`useInsertionPoints`) — 선택 요소 외곽 4방향 + 버튼, 부모 computed display/flex/grid 로 활성 방향 결정. 잠긴 영역(확장 조각/공통 레이아웃/partial/데이터 반복)에서도 형제 삽입 anchor 기준으로 + 버튼을 노출(잠긴 노드 자체는 무변형).
- 드래그앤드롭 재배치(dnd-kit `PointerSensor`, `pointerWithin`) — 명시적 드롭 슬롯 방식(`buildDropSlots`, slot id 에 컨테이너 path+인덱스 인코딩 → 기하 추론 0). gap/nest 슬롯, 조상 체인 + 형제 컨테이너로 레벨 한정, `display:contents` 래퍼 시각 흐름 투명화, `DragOverlay` 고스트는 `cloneNode`+portal(scale 좌표 왜곡 회피). 컨테이너 밖으로 빼낸 요소를 다른/원래/깊은 컨테이너로 재이동, 선택 기준 드래그(자식 영역에서 시작해도 선택 부모 이동), `nestingRules`(canDrop/isDraggableNode/isContainerComponent, 폴백 없음).
- undo/redo(`useEditorHistory`) — 추가/삭제/이동/속성/인라인 텍스트 5종 push, Ctrl+Z / Ctrl+Shift+Z·Ctrl+Y(⌘ 동등), input 포커스 중 미가로채기, 툴바 ↶/↷ 동기. 다른 레이아웃으로 붙여넣기(`editorClipboard`, sessionStorage 직렬화).
- 단축키 보강 + 단축키 맵 모달 — 중앙 키맵 SSoT(`editorShortcuts.ts`) + 전역 디스패처(`useEditorShortcuts`, 입력칸/모달 포커스 가드), 복사·잘라내기·붙여넣기·속성·삭제·부모 선택·선택 해제·요소 추가·코드 편집·미리보기·다국어·저장·나가기·초기화 등 전수 결선. `ShortcutHelpModal` 플랫폼별 표기.

#### 선택 / 잠금 / 속성 편집

- hover/선택 오버레이(`ElementOverlay`/`useElementSelection`) — `data-editor-path` DOM↔노드 매핑, hover 점선·선택 실선, 컨텍스트 메뉴(속성/복사/삭제), 8방향 리사이즈 핸들, base/extension 잠금 어포던스, navigate 기반 "이 화면 편집" 네비. 선택 요소 컴포넌트 타입 식별 라벨(작은 요소는 박스 바깥), 잠금 영역 출처 식별 칩 3종(확장명·공통 레이아웃 파일명·데이터소스 id), 겹친 부모 선택(타입 칩 ↑ 버튼 + 키보드 ↑/Esc).
- 속성 편집 모달(`PropertyEditorModal`) — `[설정]`/`[속성]`/`[스타일]`/`[고급]`/`[동작]`/`[표시조건]`/`[번역]` 탭. 레시피 엔진(`recipeEngine`, apply 4종: classToken/styleProp/cssVar/propValue, CSS 프레임워크 비가정)이 컨트롤↔노드 패치를 양방향 변환, 위젯 레지스트리(segmented/slider/select/toggle/color/image/tag-input/dimension/icon-picker/options-list 등) + `reverseResolve` 역해석·고급 값 무손실 보존. 편집 중 캔버스 잠금/딤(선택 외 요소 클릭 차단, 모달 스택 파생 자동 해제), 모달 드래그 이동 + 선택 요소 자동 회피.
- 코어 제공 "요소 ID" 속성 일괄 부여(`coreProps.ts`/`CoreIdControl`) — 모든 draggable 컴포넌트의 [속성] 탭 최상단에 표준 `node.props.id` 편집(바인딩식 읽기전용, HTML 안전 문자 sanitize, `coreProps:false` opt-out). 컴포넌트 id passthrough 전수 적용.
- 캔버스 모서리/변 드래그 리사이즈(`useResizeHandles`, 경량 포인터, scale 보정) + 가로/세로 자유 입력 `dimension` 위젯(`320px`/`50%`/`auto`, editor-spec options=프리셋 칩) — 속성 모달과 양방향 동기.
- 공유 propControl 21종 + icon-picker/options-list 위젯, 신규 스타일 컨트롤 묶음(글자색·boxShadow·borderStyle/Color/Radius·opacity·overflow·fontItalic·textUnderline·whitespace·justify, editor-spec classToken SSoT), 여백 측별 독립 편집(일괄/개별, pt/pr/pb/pl 공존, `recipeEngine.groupPrefixes` + 다중 토큰 tokenTemplate), 정렬 박스(flex) 노드 파생 판정 + 해제 토글.

#### 색 모드 / 디바이스 분기 / 다크 프리뷰

- 스타일 탭 색 모드(라이트/다크) × 디바이스 직교 2축 편집 — 하나의 `StyleScope` 로 묶어 `recipeEngine` 이 base `props` 또는 `responsive.{preset|커스텀범위}.props`(다크는 `dark:` classToken)에 무손실 기록·역해석. 다크는 classToken만 편집(인라인 색은 읽기전용 보존), 디바이스 미오버라이드는 base 상속 placeholder, scope별 표시점(●) + "기본값으로 초기화", 디바이스 단일 토큰 편집 시 기본 className 시드로 기본 스타일 유실 방지. 묶음 상단 공용 색모드 탭(`ColorSchemeTabs`, per-control 복제 금지). 툴바 라이트/다크 프리뷰 토글.
- 색 컨트롤 템플릿 스타일 라이브러리 대응 — 프리셋 색은 고정 토큰(`apply.tokens`+swatch, 라이트/다크 모두), 자유 HEX는 `tokenTemplate`(`text-[{value}]`) 라이트 전용(다크 탭 자유입력 비활성 안내).
- 다크 프리뷰 색 모드 격리 — 템플릿 `darkMode` 스펙(strategy/ancestorSelector/previewIsolation)으로 코어 CSS 서빙 API 가 다크 조상 셀렉터를 프리뷰 마커(`.g7le-preview-dark`)로 치환(일반 페이지 CSS 무영향), `flattenLayers` 로 cascade-layer 평탄화, `usePreviewDarkIsolation`(MutationObserver)로 어드민 `html.dark` 침범 차단, 권한 가드 CSS 는 Bearer fetch→`<style>` 주입.

#### 디바이스별 별도 구성(반응형 자식 교체)

- `responsive.{키}.children` 자식 완전 교체형 편집 정합 — 모바일/태블릿 보기에서 보이는 구성 요소가 정확히 선택·수정되고(같은 자리 PC 구성 오선택 차단), 분기 출처 배지(모바일 구성), 구성 경계 이동 차단, ComponentPath 가 디바이스 분기를 1급 표현(`responsive.{키}.children.{N}`). 디바이스 전용 구성 분리/해제(기본 구성 복제 시작, 포함 관계 안내), 디바이스 목록 동적 수집(기본 4종 + portable + 사용자 폭 구간 다수), 다른 디바이스 구성 이동 버튼·전용 구성 안내 배지, portable 구간 스타일 표시, 더 좁은 디바이스 전용 분리.

#### 다국어 인라인 편집 / 데이터 칩 / 커스텀 키 관리

- 콘텐츠 로케일 전환(`LocaleSwitcher`, 캔버스 프리뷰만) + 더블클릭 인라인 편집(`useInlineEdit`/`InlineTextEditor`, 평문·기존 `$t:` 키 모두 `$t:custom.*` 자동 생성) + 인라인 서식 툴바(`InlineTextToolbar`, styleControls 선언분만) + 속성 모달 [번역] 탭(`TranslationField`, 전체 로케일 일괄 PUT). 낙관적 즉시 반영(키 생성 왕복 깜빡임 제거) + "모든 언어 편집" 바로가기.
- 텍스트(보간) 데이터 연결 — 평문+다국어+데이터 공존: 평문 부분 다국어 키화(param 정규화, 언어별 어순 보존), 데이터는 드래그 가능한 원자 칩(글자 사이 어디로든 이동, 언어별 독립 위치, 캐럿 미리보기), 인라인 `+데이터`(커서 위치 삽입), 삽입=즉시 키화([번역] 탭 활성), 칩 X 로 전 로케일 해제. 대상 컴포넌트 12종 자동 인식 + capability `textBinding` opt-in/out, [번역] 탭 자리표시 보호 가드.
- 데이터 칩 전면 확대 — 속성 창 텍스트 칸·선택지 라벨·목록 항목·표 셀·"다국어 키 관리" 화면·컴포넌트 속성 전반·페이지 설정 전 탭의 값 입력칸에서 데이터 칩/표현식을 동일하게 다룬다(값 전용 칸은 번역키 미생성). 칩 파서가 논리식(`||`)·파이프 서식(`| date`/`| 숫자`)·중괄호 객체 처리·다중 데이터를 정확 분리, 칩 이름은 핵심 데이터명만 표시. 설정참조(`$core_settings:`/`$module_settings:`/`$plugin_settings:`)도 친화 칩으로 시각화(공용 `inlineBindingUtils` SSoT).
- 커스텀 다국어 키 관리 모달(`CustomTranslationManager`) — 목록·필터(전체/사용중/미사용)·로케일별 일괄 편집(낙관락 PUT, 409 안내)·삭제. 좀비(고아) 키 자동 표시 — 저장 시점 백엔드 리스너(`MarkOrphanedCustomTranslations` + `CustomTranslationUsageScanner`)가 미참조 키를 `orphaned` 전이(런타임 병합 자동 제외), 캔버스 실시간 사용 여부 라이브 배지 병행.
- 텍스트 propControl 동적 다국어 인프라 — `widget:"i18n-text"`+`apply:propValue` 컨트롤을 `I18nTextField`(미리보기 + ko/en/ja 펼침 + 바인딩 읽기전용)로 전수 자동 승격. 공통 SSoT(`useCustomTranslation` `commitText`/`classifyCustomText`)로 인라인·목록·propControl·label_key 통일. children 항목 텍스트도 동일 위젯 승격(공용 `nodeTextPath`).

#### 데이터 바인딩 / 데이터 소스 편집기

- 데이터형 prop ↔ 데이터소스/상태 바인딩 — capability `dataProps`(shape scalar/array/object) 선언분만 [속성] 탭 "데이터 연결" 영역 노출(구조/수치/enum prop 비대상). 🔍 검색형 데이터 피커(친화 명칭·소스·경로·미리보기값 매칭, shape 일치 후보만) + 순수 후보 빌더(`bindingCandidates`, 편집기 샘플 데이터·`_global`·per-state local/query/route·`_computed` 평탄화) + 상태값 명칭 카탈로그(`stateLabels`, `$t:` 키, 로케일 동적 대응). `parseBindingExpression` 이 안전 바인딩(`?.`/`?? []`)을 정규화 인식, `buildBindingExpression` 이 shape별 안전 형태로 재기입. 양 템플릿 전 컴포넌트 dataProps 전수 선언, 입력/선택 계열 19종 보강, audit `editor-datapropspec-shape-valid`.
- 반복(iteration) 데이터 연결 공용 영역(`IterationBindingSection`) — `node.iteration.source` 를 가진 모든 노드의 [속성] 탭 최상단에 "반복 데이터 연결"(array shape 후보, item_var/index_var 읽기전용 힌트).
- 데이터 소스 편집기(`DataSourcesPanel`, 페이지 설정 [데이터] 탭) — 현재 레이아웃 data_sources CRUD(id/label_key/type/endpoint/method/auth/loading/params/fallback, JSON 검증). 자체/상속 분리 저장(`patchDocumentRaw`, `__editor.original` SSoT), 신규 소스 즉시 검색 후보 반영. 양 번들 템플릿 134 data_source + 확장 주입 118 data_source 전수 `label_key` 부여 + 확장 출처 배지(모듈/플러그인), audit `data-source-label-key-coverage`.

#### 노드 에디터(목록 / 표 / 배열)

- 범용 노드 에디터 슬롯(kind-agnostic) — capability `nodeEditor:{kind,params}`/`canvasOverlay:{kind,params}` + 레지스트리(`nodeEditorRegistry`/`canvasOverlayRegistry`) + `registerCoreEditors`. 코어는 kind 만 알고 디스패치(분기 0, 템플릿 재등록 가능), 미등록 안전 디그레이드. 템플릿 확장점 `G7Core.layoutEditor`(registerWidget/registerNodeEditor/registerCanvasOverlay) + ready 큐(`initLayoutEditorStub`, initTemplate 선등록 유실 방지).
- 목록 children 노드 에디터(`ChildrenListControl`) — Ul/Ol/Nav/Form/Li 자식 트리 추가/삭제/정렬, 항목 텍스트 다국어(직접 text + 중첩 텍스트 자손 탐색, 장식 노드 보존), [속성] 탭 배치(스타일 탭=CSS 전용). 폼 항목 라벨+입력칸 묶음 추가·`itemFields`/`childTemplate`/`childLabel` 스펙 선언.
- 표 노드 에디터(`TableEditor`) + 캔버스 인플레이스 오버레이(`TableInplaceOverlay`) — 트리↔논리 grid 어댑터(`tableGridModel`, 병합 고려), 행/열 추가·삭제·이동(밴드=병합 블록 단위, 섹션 경계 가드, 이동 불가 시 비활성+사유), 셀 병합/해제, 2단계 선택(표→셀)+Shift 영역 선택, 셀 테두리/배경색/내부 여백 시각 피커(프리셋 토큰+자유 HEX 인라인 SSoT, 라이트/다크 공용 탭, border-collapse 공유 변 보정), 셀 텍스트 다국어(복합 셀 구조 보존)+인라인 서식 툴바, 전용 거터 레일+빈 셀 최소 크기(편집기 전용 CSS), 속성 패널 미니 미리보기 실테두리 반영.
- 배열 노드 에디터 — `ArrayItemsEditor`(정적 배열 prop 항목 추가/삭제/정렬/필드편집, 위젯 text/i18n-text/select/boolean/icon/number/color/number-list, 원시 `string[]` 지원, 정적-바인딩 가드, `defaultItems` 시드)·`array-group`(다중 배열 prop, BarChart labels+datasets)·`array-cell-tree`(중첩 cellChildren, CardGrid cardColumns). 템플릿 인플레이스 레퍼런스(`registerCanvasOverlay`, `data-editor-item-path` 마커). TagInput 계열 5종 options 편집기 보완.

#### 표시조건 / 동작 / 페이지 상태

- 표시조건 편집(`ConditionBuilder`/`conditionRecipeEngine`) — 친화 조건 카테고리(로그인/권한/데이터 유무·로딩·실패/필드 값/화면 상태/수정·생성/입력 오류) 택1 + "그리고/또는" 결합(단일 표현식 합성). 변종 방어 인프라 4계층(recipe-local alias·specificity 우선 매칭·path-shape 가드+backreference·전역 정규화 위임), audit `condition-recipe-duplicate`.
- 동작(액션) 편집(`ActionRecipeEditor`/`actionRecipeEngine`) — 이벤트별 친화 명칭(이동/메시지/상태 바꾸기/새로고침/서버 호출), apiCall onSuccess/onError 중첩 재귀 조립, 올바른 핸들러 규칙(navigate/apiCall/setState/toast/refetchDataSource, top-level target) 생성. 동작 이벤트 친화 명칭 15종 보강(audit `editor-event-label-coverage`).
- 동적 핸들러 이름 디스패치 — 액션 `handler` 가 `{{...}}` 바인딩이면 `ActionDispatcher.executeAction` 이 컨텍스트로 먼저 해석한 뒤 라우팅한다(`resolveActionRef` 직후, 프리뷰 억제·switch 앞). 백엔드 응답이 호출할 핸들러 풀네임을 내려주는 provider-agnostic 디스패치(결제 진입 `handler: "{{response.data.pg_payment_handler}}"`)를 지원. 빌트인 26종은 리터럴이라 미진입(무영향), nested(conditions/sequence)도 동일 경유로 자동 적용, 프리뷰 억제 판정·미등록 graceful skip 도 해석 이름 기준. 편집기 "결제 진입" recipe(`requestPgPayment`)로 친화 입력(핸들러·결제 데이터 칩, `chipContext='response'`), `actionRecipeEngine.matchAction` 핸들러 비교를 placeholder-aware 로 보강(리터럴은 정확 일치, `{{key}}` 는 `extractValues` 위임).
- 중첩 액션 친화 편집 확대 — apiCall `onSuccess`/`onError` 와 `sequence`/`parallel` 의 actions 를 `advanced` 잠금에서 풀어 친화 중첩 액션 빌더(action-list)로 편집한다(응답 후속·다단 동작을 코드 없이 추가/순서/속성·데이터 칩 편집). 재귀 `ActionListBuilder` 가 동일 recipes·candidatePools 로 펼쳐지며 `summarizeAction` 은 action-list 트리 param 을 카드 요약에서 제외(`[N]` 토큰 누출 방지). `switch.cases` 는 객체맵 구조라 잠금 유지.
- `conditions` 핸들러 친화 recipe + `branch-list` 위젯 신설 — 조건 분기(`[{if, then}]`)를 분기별 실행조건(조건식 데이터 칩)·동작(중첩 액션 빌더, 단일/배열 both) + 분기 추가/삭제/순서로 친화 편집. recipe build 는 `conditions` 를 액션 최상위 키(`{handler:'conditions', conditions:'{{branches}}'}`)로 두어 `handleConditions` 와 정합, sole-binding 통째 캡처로 `then` 구조 무손실 왕복. 이 셋이 합쳐져 `sequence → apiCall.onSuccess → conditions → then` 깊이의 결제 진입까지 코드 없이 편집 가능(특정 템플릿/모듈 비의존, 코어 전역).
- 동작 입력칸 객체값·경로형 setState 표시 정합 — (1) apiCall `body` 위젯을 `data-chip`(스칼라/표현식)에서 `key-value` 로 바꿔 필드 맵(`{temp_order_id, orderer, ...}`)을 키별 데이터 칩 행으로 편집한다(객체를 단일 칩으로 받아 `[object Object]`·JSON 분해 깨짐이 생기던 문제 제거). (2) setState 의 경로형(`{target:'_local.X', value:V}`)을 인식해 `value` 를 상태 키 이름이 아니라 그 경로에 넣을 단일 값으로 표시(타입 보존 `InitialStateValueEditor`). (3) 컴포넌트 [동작] 탭이 `bindingCandidates` 를 동작 입력칸까지 전달하지 못해 데이터 칩 추가(🔍) 가 안 뜨던 회귀를 수정(`PropertyEditorModal`→`ActionRecipeEditor`).
- 페이지 상태 토글 + 시뮬레이션(`PageStateSwitcher`/`pageStateSimulator`) — `editor-spec.states` 의 sampleData 오버라이드·초기 `_local`/`_global`/`query`/`route` 패치·폼 검증 실패를 캔버스에 재시뮬레이션(`_localInit` 권위 주입으로 init_actions 이김). scope 매칭(route glob/base/modal, 실제 path 일치), 번들 템플릿 states.json 전수 작성(profile/edit·settings·users 등). 반복 항목 편집 모드 진입 골격.

#### 버전 히스토리 / 실데이터 미리보기 / 확장·모달 조각 편집

- 버전 히스토리 모달(`VersionHistoryModal`/`useLayoutVersions`) — 저장 버전 목록(번호·시각·저장자·변경량 +N/-N/char_diff·최신 배지) 조회·복원(`reload` 캐시 버스트+activeDocument 재로드, 낙관적 잠금 정합) + 버전 비교 diff(`VersionDiffView`/`lineDiff`, 자체 LCS·Unified diff·외부 라이브러리 0). 실데이터 미리보기(`useLayoutPreview`) — 미저장 문서를 저장 마스킹 후 `storePreview`(30분 TTL)→`/preview/{token}` 새 창, `mod+p` 결선.
- 확장(주입 조각) 시각 편집 — 호스트 레이아웃 전체를 백엔드 평가 상태 그대로 렌더 + 편집 조각만 역스포트라이트, 모달 안 주입 조각(약관/주소 검색/본인인증)도 모달 열어 편집, 화면 상태 전수 정의(배송지 직접 입력·모바일 드로어·쿠키 배너·이니시스 등), 시각 편집 불가 시 코드 편집 안내 디그레이드. 라우트↔모달/확장 연결 목록(정적 매칭 `host_layouts`, 트리 인라인). 확장 버전 기록·복원·트리 버전 배지(`useExtensionDocument`/`VersionTarget` 일반화 + 백엔드 LayoutExtensionVersion*).
- 공통 레이아웃 슬롯 가시화+잠금(점선 "콘텐츠 영역" 라벨, 선택/드롭 전체 잠금), 헤더/푸터 선택 가능(시각적 루트 editorAttrs 전달 + 핸들 클릭 최심 노드 위임).

#### 저장 / 동시성 / 접근 오류 / devtools

- 문서 저장·dirty·동시성(`useLayoutDocument`) — `patchLayout`/`save`(`expected_lock_version` PUT, 활성 확장 재검증, 200/409/422/network 분기), 저장 마스킹(`stripInheritedFromLayoutContent`, base/extension/partial+메타 제거), 라우트 전환 세션 캐시 복원 + 트리 dirty 배지(●) + beforeunload 가드, 레이아웃 초기화(`reload`). `SaveFeedbackBanner`(6종 SaveResult, concurrent 모달). 낙관적 잠금 인프라(`lock_version` 컬럼 + `ConcurrentModificationException` + 409 + 백엔드 `UpdateLayoutContentRequest::prepareForValidation` 마스킹 가드).
- 접근 오류 패널(`AccessErrorPanel`) — kind별(unauthorized/forbidden/not_found/server_error/network/unknown) 아이콘·톤·필요 권한 칩·액션, 401 자동 로그인 redirect(`AuthManager.getLoginRedirectUrl`), 자산/라우트 실패 통합. `access_error.*` 키 17건.
- devtools 트래커 다수 — editor-state/selection/dnd/document/history/sample-data/spec-merge/property-patch/i18n/page-state(메타 키만 적재, 노드 내용물·평문 미적재, 언마운트 clear).
- 공용 모달 인프라(`EditorModalContext`/`EditorModalRoot`/`useEditorModal`) — 코어 `_global.modal` 과 분리(격리 store 충돌 회피), 백드롭/ESC 닫기, depth 무제한 스택. 부유 드롭다운 공용화(`FloatingDropdown`, `position:fixed`+anchor rect 로 flip/clamp 자동 보정, 외부 pointerdown/ESC 닫기) — 데이터 검색 피커·검색 드롭다운 전반 적용.

#### 페이지 설정 모달(8탭)

- 페이지 설정 모달 — 기본 정보·검색엔진·화면 동작·로딩 화면·자동 계산·초기 상태·에러 처리·데이터 8탭, 탭별 고급 항목 배지, 즉시 반영(영속은 툴바 저장). 켬/끔 하위 설정은 숨기지 않고 비활성(회색) 표시, 공용 토글 스위치, 모달 최대 높이 화면 적응 + 내부 스크롤, ESC 닫힘 차단([닫기]/[✕]만).
- 기본 정보 — 페이지 이름·설명·편집기 트리 라벨(다국어+데이터 칩), 메뉴 아이콘 고르개, 접근 권한 태그.
- 검색엔진(SEO) — 노출 켬/끔·페이지 종류·연동 확장/데이터·sitemap 우선순위/주기·검색 제목/설명, 소셜 공유(OG/Twitter, 비운 칸 기본값 출처 배지+잠금, 고급 이미지 옵션), 구조화 데이터(직접 지정 토글, 확장 자동값→연결 칩 출발, JSON-LD 미리보기), 검색 변수(자동/값채움/직접 추가 3그룹), 봇 미리보기(서버 권위 계산). 확장 제공 SEO 자동값을 출처 연결 칩 + [다른 데이터로 바꾸기]로 표시(언어팩 키 기반 명칭).
- 화면 동작 — 핸들러 카탈로그(코어+확장)에서 골라 순서 배치(드래그 손잡이), 친화 요약+코드 보기+출처 배지+실행 조건, 부모 상속 동작 잠금(읽기 전용). 모든 동작 입력칸 데이터 칩·표현식 친화 입력(저장소 키·상태 값·setState 키–값·navigate query 등), 모달 선택 `modal-picker` 위젯(친화 명칭).
- 로딩 화면 — 켬/끔·덮을 범위(전체/특정 영역, 영역 고르개)·표시 방식 5종+옵션·기다릴 데이터, 상속 표시 + [이 화면만 바꾸기], 안내 문구 다국어, 별도 컴포넌트 선택 창.
- 자동 계산 — 친화 보기 + "직접 만들기" 3단계 + 샘플 데이터 결과/타입 미리보기, 부모 계산값 덮어쓰기/되돌리기, 모든 값·경로 칸 데이터 칩·표현식, firstOf 후보·배열 인덱스 평문 유지.
- 초기 상태 — 로컬/전역/격리 시작값(문자/숫자/예아니오/없음/목록/묶음+중첩 블럭 편집), 종류 선택 추가, [코드로]/[블럭으로] JSON 전환(문법 오류 저장 차단), 값 이름 검증(영문 시작), 부모 상속 표시·덮어쓰기.
- 에러 처리 — 상태 코드별 동작 행(표준 401~503 기본 표시, 출처: 이 페이지/상속/템플릿), 7종 동작+오류 정보 데이터 칩, 코드 미리보기, 상속 덮어쓰기 안내.
- 데이터 탭 — 데이터 소스 편집을 종류별 섹션(본문 타입·성공/실패 후속·코드별 오류·조건부 로딩·재진입 재요청·실시간 수신)으로 확장 + 전역 헤더·외부 스크립트 읽기전용.

#### 표현식 분해 트리

- 조건에 따라 달라지는 제목·설명·값을 조각별 분해 편집 — 조건 분기(`A ? B : C`)·기본값 폴백(`A ?? B`/`A || B`)·이어붙이기(`A + B`)를 트리로 풀어 각 분기를 다국어 입력칸(번역 탭·데이터 칩)으로 편집, 중첩 재귀 표현. 한 줄 미리보기+[수정]/[접기], [원본 식 보기] 토글, 친화 빌더 가능한 단순 조건만(복잡 조건 읽기전용으로 원본 무손상). 조각 손잡이 드래그 순서 변경·추가([+조각]/[+값이 없을 때 대신])·삭제, 일반 이름↔표현식 양방향([표현식으로 바꾸기]/[일반 이름으로], 되돌림 미리보기·경고, 첫 결과 데이터 칩 복구). 페이지 이름·설명에서 검색엔진 값 칸·컴포넌트 속성 전반으로 확대.

#### 본인인증(IDV) 인증 대상 선언

- `apiCall` 액션에 **`identity_target`** 선언 속성 신설 — IDV 428 인터셉트 시 인증 코드/링크를 보낼 대상(이메일·전화)을 흐름이 직접 선언한다. `auth_mode`/`errorHandling` 과 동일하게 apiCall 액션 최상위에 두며, `{ email?, phone? }` 형태로 표현식 바인딩을 지원한다. `ActionDispatcher` 가 428 인터셉트 지점에서 이 값을 평가해 `IdentityGuardInterceptor.handle(response, originalRequest, target)` 로 전달하고, launcher 가 `verification.target` 한 곳에서 읽는다. 비로그인(게스트) 흐름의 핵심 — 서버 428 payload 에는 target 이 없고(서버는 화면 입력값을 모름) 흐름이 선언한다. 로그인 사용자는 빈 값이어도 서버 세션이 도출하므로 무방하다. (배경: 비회원 주문 결제 시 본인인증 정책이 켜지면, 주문자·수취인에 이메일·전화를 모두 기재해도 launcher 가 주문자 입력값을 읽지 못해 "인증 대상이 필요합니다" 422 로 막히던 결함. 기존 launcher 는 회원가입/비밀번호 재설정 폼 경로만 하드코딩으로 알았다.)
- `G7Core.api`(axios) 호출 경로도 `config.identity_target` 로 동일하게 IDV 대상을 선언할 수 있다 — `apiCall`(fetch) 경로와 axios 경로 양쪽 모두에서 인증 대상 전달을 지원한다.
- `ensureIdentityVerified` 액션이 `params.target` 으로 선제 가드 시점의 인증 대상을 선언할 수 있다 (apiCall `identity_target` 과 동일 채널).
- 레이아웃 편집기 apiCall 레시피에 `identity_target` email/phone 입력칸 추가([고급] 영역) — 코드 편집 없이 인증 대상 선언을 편집할 수 있다.

#### 레이아웃 편집기 SEO 다국어 데이터 칩

- 레이아웃 편집기 검색엔진 탭(페이지 설정)의 데이터 칩 파서가 **SEO 다국어 추출 함수 `$localized(<경로>)`** 를 단일 바인딩으로 인식하도록 확장. 종전엔 `$localized(product.data.meta_title)` 같은 함수 호출이 `bindingCandidates.parseBindingExpression`·`expressionValueTree` 양쪽에서 복합식(raw)으로 떨어져, SEO 메타값을 입력해도 편집기가 친화 데이터 칩 대신 원시 식 문자열을 노출했다. 이제 `parseBindingExpression` 이 `$localized(<단순경로>)` 래핑(인자 1개·단순 경로)을 흡수해 인자 경로를 단일 바인딩으로 인지하고 `localeFn` 으로 함수명을 보존하며(`bindingChipLabel` 이 인자 경로를 친화 라벨로 표시), `expressionValueTree` 파서가 같은 형태를 단일 바인딩 리프(`{{$localized(path)}}`)로 환원해 `meta 우선 ?? name 폴백` 체인도 fallback 트리로 분해한다. `buildBindingExpression(sourceId, path, shape, localeFn)` 에 옵션 인자를 더해 데이터 교체 시에도 `$localized(<src>.<path>)`(옵셔널 체이닝/폴백 없음) 래핑을 보존한다(래핑이 빠지면 다국어 객체가 현재 로케일 문자열로 추출되지 않아 SEO 메타가 깨짐). 다인자·연산·리터럴 인자, 미등록 함수(`Math.max` 등)는 종전대로 복합식(raw) 폴백이라 손상 0. (배경: 상품·카테고리 SEO 제목/설명 다국어화 후, 사용자가 검색엔진 탭에서 설정한 SEO 메타 항목이 편집기 데이터 칩으로 표시되지 않던 결함.)

### Changed

- 동작 순서 변경 방식을 표현식 편집기와 통일 — 컴포넌트 [동작] 탭·페이지 설정 [화면 동작] 탭·데이터/에러 처리 동작 목록에서 손잡이 드래그 + 파란 삽입선으로 재배치(▲▼ 버튼 제거, 공통 레이아웃 상속 동작은 잠금).
- 코어 JSON 텍스트 편집 입력기를 공용 부품(`JsonBlockField`)으로 통일(데이터 소스 요청 파라미터·확장 주입 속성·초기 상태 [코드로] 동일 동작/오류 안내), 데이터 소스 [기본값]을 중첩 블럭 편집으로 일원화(raw JSON 모드 제거), [요청 파라미터]·[기본값] JSON 미리보기를 [미리보기 ▾] 토글로 전환.
- 데이터 검색 선택기를 모든 진입점에서 부유(`FloatingDropdown`) 상태로 통일(자동 위치 보정), 로딩 화면 기다릴 데이터·검색엔진 연동 데이터 목록을 [데이터] 탭과 동일 표기(표시 명칭+id+확장 출처 배지)로 직관화.
- 페이지 설정 항목 이름을 편집 중인 화면 단어장 우선 해석(관리자 단어장 폴백)으로 정정, `errors/{code}` layout_name 식별자 통일(`TemplateManager` 자동 부착 제거), `ActionDispatcher.handleOpenWindow`/navigate fallback 기본 `_self`(교차 이동 의도치 않은 새 탭 차단), `recipeEngine.applyRecipe`/`reverseResolve` optional scope 인자(기본 BASE_SCOPE 동일 동작), 편집 모드 nesting 컴포넌트 `editorAttrs` 패스스루(layout/composite 시각적 루트 spread, 사용자 페이지 no-op).
- `editorSpecLoader` 가 편집 대상 + 활성 모듈/플러그인 editor-spec 을 단일 병합본으로 합침(record key 병합·palette/states concat·nesting union·sampleData key 병합), `sampleGlobal` 코어 우선 deep merge 체인(충돌 시 코어 값+dev 경고), 라우트 트리 노드에 레이아웃 파일 경로 표시·툴바 템플릿 이름/버전+전환 드롭다운.
- `IdentityGuardInterceptor.handle()` 시그니처에 3번째 인자 `target?: { email?, phone? }` 추가(옵셔널, 하위호환). `VerificationPayload` 에 런타임 필드 `target` 추가, `IdentityVerificationTarget` 타입 export.

### Fixed

- 페이지 설정 [화면 동작] 탭이 기존 동작을 못 읽어 추가·저장 시 기존 동작이 통째로 사라지던 데이터 손실, "설정 저장하기" 저장할 값이 번역 문구용 읽기전용으로 잘못 배정, "화면 상태 바꾸기" 깊은 중첩 묶음이 `[object Object]` 로만 표시·편집 불가, 실행 조건(if) 칸이 평문이라 데이터·표현식 미연결, ≡/🔍/ƒx/?? 버튼 겹침·행 어긋남 등 동작 입력 결함.
- 평문+데이터 칩 혼합 값을 [표현식으로 바꾸기] 승격 시 중괄호 중첩(`{{...'{{route.id}}'...}}`)으로 식이 깨지던 문제 — 데이터 칩을 이어붙이기 식 데이터 항(`'...' + route.id`)으로 보존. [일반 이름으로] 되돌리기 시 첫 결과 데이터 연결이 빈 칸으로 사라지던 문제, 단일 데이터에 불필요한 표현식 편집기 펼침, 검색엔진 데이터 칸 [✓ 완료] 버튼 부재, [✎ 수정] 모드에서 데이터·설정 참조 raw 노출(칩 편집기 유지로 정정).
- 자동 계산 "먼저 있는 값 고르기" 후보 소실·깨진 식 저장·데이터 선택 칸 부재, 검색엔진 SEO 동적 변수 그룹 미분류(확장 정보 미전송), 미리보기 샘플 데이터 미반영·출처 표시 미갱신·상속 값 오표기, 로딩 화면 상속 표시·영역 고르개·구조화 데이터 자동 채움, 에러 처리 표준 상태 코드 행 누락·두 번째 입력칸 입력 불가·타이핑 소실(번들 errorRecipes.json setState 스프레드/openModal target/showErrorPage target 정정 포함), 모달 화면 높이 초과·탭 색 줄 잔존 등 페이지 설정 다수.
- 데이터가 든 다국어 문구 인라인 편집 시 계산식 일부(`|| []).length}}` 등)가 평문 노출·칩 이름 깨짐(관리자 사용자 관리·대시보드·본인인증·언어팩 등 개수/페이지 정보 화면 전반), 객체 형태 계산식·`{{error.errors ?? {}}}` 빈 객체 fallback 미평가, 모달 화면명 키 노출.
- 표 셀·목록 항목에 데이터 칩 끼운 직후 "데이터 영역" 잠금으로 인라인 편집 차단·표 미리보기 격자 키 문자열 노출, 데이터 칩 글자 사이 드래그 시 trailing click 으로 해제·`{p0}` 자리표시 잔존.
- 요소 이동 후 undo 시 복제(중복) 발생, 디바이스별 구성 안 요소 추가·이동·undo 미반영, portable 구간 라벨 "모바일" 오표기·스타일 미표시·전용 분리 불가·분리 해제 시 스타일 동반 삭제.
- 인라인 편집 중 글자색 classToken 실시간 미반영(폴백 인라인 color 가 토큰 색 가림 — 색 출처 없을 때만 적용), apiCall onSuccess 중첩 동작 추가 불가, 동작 카드 드래그 핸들 무동작, 영역 선택(🎯) 모드에서 편집 표식 미숨김·임시 ID 요소 선택 가능·딤 막 클릭 가로채기·고른 요소 잔류 선택.
- flex 노드 파생 판정 stale(DOM computed 고정), 캔버스 잠금 딤이 닫은 뒤 잔존(모달 스택 파생으로 자동 해제), 표시 권한 TagInput 후보 미주입(+추가 영구 비활성), 데이터 바인딩 요소 선택·이동 불가·네비 어포던스 오해 안내, 화살표 함수 `arguments` 부재로 인한 data_source 3번째 인자 판정 결함(단위 테스트 검출).
- 서버 응답 캐시 키와 무효화 경로 정규화 불일치로 저장 후 사용자 간 stale 노출(serve `?v` 정수화, 일반/편집기 `.meta` 키 동시 무효화), 편집기 후보 데이터를 admin 전역 broadcast 가 아닌 가드 엔드포인트 fetch 로 한정.
- `replace:true` navigate(탭 전환·필터 변경 등 같은 화면 내 URL 교체) 경로에서 데이터소스 `if` 조건이 재평가되지 않던 버그 수정. `updateQueryParams` 가 직전 진입 시점에 `if` 로 필터링된 `currentDataSources` 스냅샷을 그대로 refetch 하여, 탭을 클릭으로 전환하면 변경된 `query` 컨텍스트가 반영되지 않고 직전 탭의 데이터소스가 계속 선택되었다. 이제 원본(`if` 필터링 전) 데이터소스를 보존(`currentRawDataSources`)하고, `updateQueryParams` 에서 변경된 `query` + 최신 `_global` 로 `filterByCondition` 을 재평가하여 현재 탭에 맞는 데이터소스만 fetch 한다. (새로고침/URL 직접 진입 경로는 기존에도 정상 — 본 수정은 SPA 탭 클릭 전환 경로 전용.)
- iteration source / if 조건 / 반복 텍스트 바인딩에서 **배열·객체 리터럴**(`{{['a','b']}}`, `{{[{...}]}}`)이 렌더되지 않던 버그 수정. `isComplexExpression`(RenderHelpers.ts) 정규식이 `[`/`]`/`{`/`}` 를 인식하지 못해 리터럴이 "단순 경로"로 오판되어 `resolve()` 경로탐색 → `undefined` 가 되었다. 정규식에 `[]{}` 를 추가하여 리터럴이 `evaluateExpression`(원본 타입 유지) 경로로 라우팅된다. 순수 숫자 대괄호 인덱싱(`items[0]`, `entry[1]`)은 `resolve()`/`evaluateExpression()` 양 경로 결과가 동일함을 회귀 테스트로 입증 — 경로 이동에 따른 동작 변화 없음. (배경: 본인인증 이력 화면의 상태/발생위치 필터 체크박스가 배열 리터럴 source 로 정의되어 전혀 렌더되지 않던 결함.)
- `evaluateExpression`(DataBindingEngine.ts) 에서 `new Set(...)` / `new Map(...)` 가 `Set is not a constructor` 로 실패하던 버그 수정. `extractVariablesFromExpression` 의 `reserved` 화이트리스트에 `Set`/`Map`(및 `WeakSet`/`WeakMap`/`Symbol`/`Promise`/`BigInt`/`Error`)이 없어 이들이 컨텍스트 변수로 오인되어 `undefined` 로 가려졌고, `new Function` 본문에서 `new undefined()` 가 되었다. `Math`/`Date`/`Array` 등과 동일하게 표준 내장 객체로 화이트리스트에 추가한다. `eval`/`Function`/`globalThis`/`window` 등 위험 전역은 의도적으로 제외. (배경: 본인인증 이력 화면의 채널 필터가 `Array.from(new Set(...))` 로 후보를 도출하던 결함.)
- `ApiClient`(`G7Core.api`) response 인터셉터에 **HTTP 428 본인인증(IDV) 중앙 처리** 추가. 기존에는 `apiCall` 핸들러(`ActionDispatcher.handleApiCall`, native fetch) 경로만 `IdentityGuardInterceptor` 로 428 을 자동 인터셉트했고, `G7Core.api`(axios) 직접 호출 경로(모듈 JS 핸들러: 무통장 입금확인·주문 취소 등)는 401 만 처리해 428 이 와도 본인인증 모달이 뜨지 않았다. 그래서 각 모듈 핸들러가 `G7Core.identity.handle` 분기를 수동으로 복제해야 했고, 누락 시 본인인증 가드가 무력화됐다(주문 취소 핸들러 미노출 회귀). 이제 axios 인터셉터가 `428` + `identity_verification_required` 응답을 감지해 본인인증 모달 → verify → 원 요청(헤더/body 재사용) 자동 재실행을 중앙에서 수행한다. `apiCall`(fetch) 경로와는 별개 HTTP 클라이언트라 이중 처리되지 않는다.
- `createChangeEvent`(EventHelpers.ts) 가 checkbox/radio 이벤트의 `target.value` 를 `String(checked)`("true"/"false") 로 문자열화하던 버그 수정. Toggle/Checkbox 가 명시적 `value` 없이 `createChangeEvent({checked})` 로 만든 이벤트가 Form 자동바인딩의 **value 바인딩 경로**(currentValue 가 boolean 이 아닐 때 — 예: 폼 초기값이 아직 채워지지 않은 시점)로 처리되면, boolean 필드에 문자열 `"true"` 가 저장되어 백엔드 boolean 검증이 422 로 거부되었다(예: 이커머스 환경설정 "취소 시 재고 복구" 토글 ON 저장 실패). 이제 checkbox/radio 의 기본 `value` 를 boolean(`checked`) 으로 두어, checked 바인딩 경로(`target.checked`)는 기존과 동일하게 동작하고 value 바인딩 경로도 boolean 을 저장한다. 명시적 `value` 를 받는 호출(PermissionTree, HtmlEditor 의 textarea/multilingual)과 checked 만 읽는 호출(HtmlEditor isHtmlMode)은 영향 없음. (배경: troubleshooting-components-form.md 사례 5 — "엔진이 boolean 을 올바르게 처리, 문자열 변환 우회 불필요" 설계 의도와 정합.)
- 컴포넌트 `setState`(`target: "_local"`)가 canonical source(`_global._local`)에 동기화되지 않아, 검색(`navigate replace:true`) 직후 사용자가 선택한 필터가 풀리던 버그 수정. 목록 화면에서 필터(라디오/체크박스 등)를 선택하면 `ActionDispatcher.handleSetState` 의 COMPONENT path 가 컴포넌트 React 상태(저장소 A: `localDynamicState`)만 갱신하고 `_global._local`(저장소 B)은 갱신하지 않았다. 이후 검색 → `updateQueryParams` refetch → `updateTemplateData` 가 `currentDataContext._local` 을 stale 한 `_global._local`(init 기본값)로 되돌려, 선택한 필터 일부가 검색 직후 초기값으로 풀렸다(새로고침은 `handleRouteChange` 가 `query` 기반으로 `_global._local` 을 재구성하므로 정상). Form 자동바인딩은 이미 `setLocal(render:false)` 로 양쪽 저장소를 동기화하지만 명시적 `setState target:"_local"` 은 그렇지 않던 비대칭이 원인. 이제 COMPONENT path 도 GLOBAL STATE UPDATER path 와 동일하게 `globalStateUpdater({ _local }, { render: false })` 로 `_global._local` 을 동기화한다. `render: false` 이므로 추가 React 렌더는 발생하지 않으며(클릭당 렌더 횟수 불변), `scope: 'parent'|'root'` 및 isolated 타깃은 이 분기에 도달하기 전에 분리 처리되어 모달 상태 오염에 영향이 없다. (배경: 쿠폰·주문·배송정책 등 목록 화면 공통 결함.)
- `emitEvent` 액션의 결과(`_local._eventResult`)가 **같은 sequence 의 후속 액션에 전파되지 않던** 버그 수정. `handleSequence` 는 비-setState 핸들러 실행 후 `currentState` 를 `__g7SequenceLocalSync`(setLocal 이 설정)에서만 갱신하는데, `emitEvent` 는 `globalStateUpdater` 로만 `_local` 을 갱신해 `__g7SequenceLocalSync` 를 비워둔 채였다. 그 결과 emit 직후의 `setState`/`apiCall` 이 `_eventResult` 와 리스너가 갱신한 `_local`(예: 업로드된 `form.images`)을 보지 못하고 emit 이전 stale 스냅샷을 사용했다. 이제 `emitEvent` 가 `globalStateUpdater` 갱신과 동일한 시점에 `__g7SequenceLocalSync` 에 `_eventResult` 를 병합한 `_local` 스냅샷을 실어, `handleSequence` 가 이를 픽업한다. base 는 sequence 가 추적 중인 `context.state` 우선이라 in-flight `setState` 변경(예: `isSaving`)을 보존하며, sequence 밖(standalone emitEvent)에서는 글로벌 `_local` 로 폴백한다. 글로벌 `_local` 갱신 동작 자체는 종전과 동일하게 유지된다. (배경: 상품 수정 화면에서 이미지를 추가하고 저장하면 — 저장 sequence 가 `emitEvent(업로드)` → `apiCall(PUT)` 순서인데 — 업로드는 성공해도 PUT body 의 `form.images` 가 업로드 전 스냅샷이라 백엔드 `syncImages` 가 방금 올린 이미지를 삭제하던 회귀.)
- IME(한글·일본어·중국어) 조합 중 Enter 가 액션 키 필터에 매칭되어 **글자누락·이중제출** 이 발생하던 버그 수정. `ActionDispatcher` 의 key 필터(`action.key` 지정 keydown 액션)는 비교 전에 IME 조합 상태를 전혀 검사하지 않아, 한글 입력 후 Enter 로 조합을 확정하는 순간 그 Enter keydown 이 `key:"Enter"` 액션(검색·제출 등)을 조합 확정 *전* 값으로 발화시켰다. 결과적으로 (1) 조합 중 마지막 글자가 input 에 커밋되기 전 액션이 실행되어 글자가 누락되고, (2) 액션 실행 후 글자가 커밋되며 두 번째 keydown 으로 재실행되어 이중 제출이 일어났다. 이제 `isImeComposing` 헬퍼(`event.isComposing === true` 또는 legacy `event.keyCode === 229` 검사 — 브라우저별 조합 종료 keydown 차이 흡수)로 조합 중 keydown 은 key 필터 매칭에서 제외한다. 가드는 `action.key` 가 지정된 keydown 액션에만 적용되어, key 필터 없는 매 입력 setState 류 액션은 조합 중에도 종전대로 동작한다(회귀 0). 모달 오버레이 ESC 닫기 리스너에도 동일 가드를 적용해 조합 중 ESC 가 모달을 닫지 않도록 일관화했다. (배경: 상품·게시판 검색창에서 한글 검색어 입력 후 Enter 시 마지막 글자가 빠지거나 검색이 두 번 실행되던 결함 — gnuboard/g7 #54.)
- 본인인증(IDV) challenge 가 **부가 목적(성인인증 등) 미달**로 실패할 때 토스트가 **2개 중복 표출**되던 버그 수정. 본인확인 자체는 성공했으나 부가 조건(만 19세 이상)을 충족하지 못해 challenge 가 실패하면, provider 가 "성인 인증이 필요합니다" 같은 고유 사유를 토스트로 표출한다. 그 직후 코어가 원 428 응답을 onError 로 흘려보내, 원 요청(글쓰기 등)의 generic 가드 토스트("본인 확인이 필요합니다")가 한 번 더 떴다. 이제 `IdentityGuardInterceptor` 에 1회성 도메인 안내 신호(`markDomainNoticeShown()` / `consumeDomainNoticeShown()`)를 신설하고, provider 가 "본인확인 성공 + 부가목적 미달" 실패를 안내한 직후 이 신호를 남기면, 코어 `handleToast` 가 동일 사이클의 generic IDV 가드 토스트(error 타입 + `error_code='identity_verification_required'`) 1건을 skip 한다. 일반 본인인증 실패/취소(본인확인 자체 실패)는 신호가 없어 가드 토스트가 그대로 유지된다 — provider 무지식의 범용 신호이므로 부가 목적이 추가되어도 코어 수정 없이 동작한다. onError 정리 액션(`setState{isSaving:false}` 등)은 그대로 실행되어 버튼 잠금 해제 등 후속 처리는 보존된다. `handle()` 진입 시 이전 사이클의 미소비 신호를 정리해 stale skip 을 방지한다. `ErrorContext` 에 `error_code` 필드를 추가하고 onError 컨텍스트에 응답의 `error_code` 를 노출한다. (배경: 성인인증이 필요한 게시판 글쓰기에서 미성년자가 인증 시 "성인 인증이 필요한 서비스입니다"와 "본인 확인이 필요합니다"가 동시에 뜨던 결함.)
- 레이아웃 편집기 **공통(base) 레이아웃 편집 모드**에서 모듈이 슬롯에 주입한 UI(예: 헤더 통화·배송국가 셀렉터)가 **소비처(SlotContainer) 위치가 아니라 주입 앵커 원위치에 표시**되던 결함 수정. `slot` 노드는 SlotContext 활성 시 원위치에서 렌더되지 않고 SlotContainer 가 떙겨 렌더하는데, base 단독 편집 캔버스는 슬롯이 통째로 사라지는 것을 막으려 모든 `slot` 노드를 표시 마커(`__editorSlotName`)로 치환해 원위치에 렌더했다. 주입 앵커는 보통 헤더 밖 최상단 등 SlotContainer 와 다른 자리라, 통화 셀렉터가 헤더 위 별도 박스로 떠 운영 화면과 어긋났다. 이제 변환 로직(`buildBaseEditorComponents`)이 **같은 base 레이아웃 안에 소비처(`SlotContainer` props.slotId) 가 있는 슬롯은 치환하지 않고 `slot` 키를 보존**해 슬롯 메커니즘에 위임한다 → SlotContainer 가 실제 위치(헤더 안)에서 렌더한다. 소비처가 없는 슬롯(자식 라우트 레이아웃이 채우는 `content` 등)만 종전대로 원위치 마커로 치환해 점선 박스로 표시한다. 동일 슬롯을 쓰는 SlotContainer JSON 노드가 하나라도 있으면(데스크톱/모바일 페어 등) 통짜 TSX 컴포넌트 내부 SlotContainer 도 정상 수신하므로, 어떤 템플릿/모듈이 어떤 슬롯에 주입하든 코어 차원에서 동일하게 동작한다. route 편집 모드는 조기 반환이라 영향 0. (배경: 관리자/사용자 템플릿 공통 레이아웃 편집에서 통화·배송국가 선택기가 헤더 위 별도 박스로 분리 표시되던 결함.)
- iteration(반복 렌더링) 안의 노드 `id` 에 쓴 표현식(`id: "item_{{$idx}}"` 등)이 **보간되지 않고 리터럴 그대로 DOM 에 출력**되어, 반복 행마다 같은 HTML id 가 중복되던 결함 수정. 노드의 최상위 `id` 필드는 `props` 가 아니라 props 바인딩 경로를 타지 않아, `DynamicRenderer` 의 두 DOM 출력 지점(Fragment 컨테이너 div / 일반 컴포넌트 props)이 `effectiveComponentDef.id` 를 보간 없이 그대로 내보냈다. 이제 `id` 에 `{{` 가 포함될 때만 iteration 컨텍스트(`$idx`/`item_var` 포함)로 문자열 보간한 `resolvedComponentId` 를 두 DOM 출력 지점이 사용한다(정적 id 는 원본 그대로 — 무영향). slot 등록 키·React remount 키·DevTools 식별자 등 내부 식별에는 원본 `effectiveComponentDef.id` 를 계속 사용해 격리한다. 코드베이스 전역 68개 레이아웃이 id 표현식을 이미 쓰고 있었으나 보간 부재로 깨져 있었고, 본 수정으로 일괄 정상화된다. (배경: 관리자 대시보드의 활동 로그·모듈/플러그인/템플릿 카드 등 반복 목록에서 같은 HTML id 가 행마다 중복 마운트되던 결함.)
- 레이아웃 편집기 "요소 ID" 컨트롤(`CoreIdControl`)이 id 값에 데이터바인딩(`{{...}}`)이 있으면 **읽기전용으로 잠가** 편집할 수 없던 동작을, **데이터 칩 편집 허용**으로 변경. iteration 안에서 `id: "item_{{$idx}}"` 처럼 반복 인덱스/행 키를 붙여 항목별 고유 id 를 만들려는 정당한 용도를 막던 제약이었다. 이제 칩 포함 시 평문+칩 혼합 편집기(`BindingChipTextInput`)를 열고, 정적 id 면 후보가 있을 때 `[🔗 데이터]` 진입점을 노출한다. HTML id 안전 문자 제약은 평문 세그먼트에만 적용하고 `{{...}}` 칩 토큰은 보존한다. 데이터 칩 후보 풀(`buildBindingCandidates`)에 **iteration 변수**(`$idx` 등 반복 인덱스·행 데이터 필드)를 추가해, 반복 목록 안의 노드를 편집할 때 `{{$idx}}`/`{{row.id}}` 를 후보로 골라 고유 식별자를 만들 수 있다.
- 같은 슬롯 컴포넌트가 **여러 SlotContainer**(헤더 데스크톱/모바일 페어)에서 렌더될 때, 슬롯에 주입된 컴포넌트의 정적 root id 가 컨테이너마다 같은 값으로 중복 출력되어 HTML id 유일성을 위반하던 결함 수정. `SlotContainer` 가 주입 컴포넌트 root id 를 컨테이너 고유 id 로 스코프(`{id}__{containerId}`)해 고유화한다(컨테이너 id 또는 컴포넌트 id 가 없으면 무영향). 이로써 이커머스 헤더 통화·배송국가 셀렉터가 관리자/사용자 헤더의 데스크톱·모바일 SlotContainer 양쪽에 마운트되며 같은 id 로 중복되던 문제가 해소된다.
- **placeholder 핸들러 친화 동작(핸들러명을 데이터 칩으로 받는 동작)이 "+동작 추가"·핸들러 변경 시 "알 수 없는 동작"(고급)으로 강등**되던 결함 수정. 핸들러명 자체를 데이터(응답값 등)로 연결하는 동작 레시피(`build.handler` 가 `{{paramKey}}` placeholder)는 빈 값으로 추가하면 `buildAction` 이 그 토큰을 미입력으로 떨궈 `handler` 키 자체가 사라졌고, 핸들러 필드를 다른 데이터 칩으로 바꾸면 구조 식별용 필수 토큰(`params`)까지 함께 사라져 친화 카드 매칭(`matchAction`)이 깨졌다. 그 결과 추가 직후 또는 핸들러를 데이터로 연결한 직후 카드가 [고급]으로 떨어져 코드 편집으로만 수정 가능했다. 이제 `buildAction` 이 ① placeholder 핸들러가 미입력으로 사라지면 build 의 placeholder 토큰을 복원하고 ② placeholder 핸들러 레시피의 **필수(required) 입력 토큰**도 미입력 시 보존하며, `matchAction` 의 placeholder 구조 가드는 **핸들러가 build 의 placeholder 토큰과 글자 그대로 같은 빈 카드**도 인정한다 — 핸들러를 어떤 데이터 칩으로 바꿔도 친화 카드가 유지되고, 저장·새로고침 후에도 친화 카드로 복원된다. 임의의 동적 핸들러 동작(필수 토큰 부재)이 이 레시피로 잘못 흡수되는 것은 그대로 차단된다(greedy 방지 유지). 일반 레시피(리터럴 핸들러)는 무영향(미입력 키 그대로 정리). 데이터/오류/수신 동작 입력의 응답 데이터 칩 후보를 확장 editor-spec(`actionChipCandidates`)이 도메인 응답 필드까지 더할 수 있게 일반화해, apiCall 후속 동작 등에서 확장이 선언한 응답 필드를 데이터 칩으로 연결할 수 있다(코어는 도메인 무지·확장이 선언). (배경: 결제(PG) 진입 동작을 응답이 지정한 핸들러로 dispatch 하도록 만들면서, 핸들러명을 데이터로 연결하는 동작 일반이 편집기에서 친화 편집되지 않던 결함.)

### Removed

- MVP 위지윅 잔재 정리 — 캔버스 상단 페이지 제목 배너(더블클릭 인라인 편집·표현식 고급 배지 폐기, 페이지 설정 모달로 진입점 통합), 동작 카드 ▲▼ 순서 버튼(드래그 손잡이로 대체), 데이터 소스 [기본값] raw JSON 직접 입력 모드(블럭 편집으로 통일).
- 코어 빌트인 icon-picker 위젯·`IconPickerControl`(라이브러리 종속 → 템플릿 소유로 이양), 코어 색/아이콘 토큰 어휘(전부 템플릿 editor-spec 카탈로그 공급).

## [engine-v1.49.3] - 2026-05-22

### Removed

- (engine-v1.49.3) MVP 위지윅 편집기 제거 — `?mode=edit` 쿼리 기반 편집 모드 분기, `renderWithWysiwygEditor`·`checkEditMode`·`getTemplateIdFromUrl` 헬퍼, `G7Core.wysiwyg` 전역 API(`initWysiwygEditorAPI`) 삭제. 신규 레이아웃 편집기로 대체 예정 (template-engine.ts, G7CoreGlobals.ts)
  - `?mode=edit` URL 진입 시 일반 렌더로 폴백. `DynamicRenderer` 의 `componentPath`/`data-editor-path` 범용 편집 인프라는 보존

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

- `TemplateApp.showRouteError` 의 401 가드가 토큰 보유 여부 판정에서 `apiClient.getToken()` 만 사용해 토큰 만료 사용자에게 안내 토스트가 노출되지 않던 문제 수정 — `LayoutLoader` 가 401 시 토큰을 자동 제거하고 재시도하므로 가드 진입 시점에는 항상 토큰이 null. `LayoutLoader` 가 첫 401 시 토큰 보유 상태였음을 `LayoutLoaderError.details.hadToken=true` 로 마킹해 가드로 전달. 가드는 (현재 토큰 보유 OR `details.hadToken === true`) 일 때 `reason='session_expired'` 부여. 익명 방문자 진입은 마킹이 없어 reason 미부여 (정책 유지: 한 번도 로그인하지 않은 사용자에게 "세션 만료" 안내 차단) ( 후속, LayoutLoader · TemplateApp)
- `apiClient.setOnUnauthorized` 콜백이 데이터소스 401 시 로그인 페이지로 redirect 할 때 `reason` 인자를 전달하지 않아 안내 토스트 트리거가 누락되던 문제 수정 — 콜백 발동은 토큰이 서버에서 거부되었음을 의미하므로 항상 `reason='session_expired'` 부여. layout fetch 401 가드와 데이터소스 401 콜백 두 경로 모두 동일한 안내 흐름으로 통일 ( 후속, TemplateApp)

### Notes

- 본 패치 디버깅 과정에서 발견된 G7 표현식 컨텍스트 규약 명문화: URL query string 은 root 컨텍스트에 `query.xxx` / `query?.xxx` 로 **직접 노출**되며 `route.query` 경로는 존재하지 않음. `route.xxx` 는 path params 만 (예: `/users/:id` → `route.id`). docs/frontend/data-binding.md 에 회귀 차단 가이드 추가

### Notes

- 본 패치는 init_actions/액션의 `if` 표현식 작성 패턴을 명확히 하지는 않으나 관련 회귀 차단 회기에 함께 추가됨: `if` 값은 `ConditionEvaluator.evaluateStringCondition` 이 평가하며, `{{}}` 외부 텍스트는 보간되지 않고 그대로 문자열로 남아 `Boolean()` 판정 시 항상 truthy 가 된다. 비교/논리 연산이 포함된 식은 반드시 **전체를 `{{}}` 한 쌍으로 감싸야** 식으로 평가된다 (예: `"{{x === 'y'}}"`). 이는 docs/frontend/data-binding.md / layout-json-features.md 가이드에 명시되어 있으며, 본 릴리즈에서 문서 측 보강은 진행하지 않음 (audit 룰 후속 advisory)

## [engine-v1.47.0] - 2026-04-29

### Added

- 레이아웃 fetch 401 재시도 실패 시 코어가 로그인 페이지로 자동 리다이렉트 () — 토큰 만료/권한 부족으로 레이아웃을 받지 못한 사용자가 "페이지 로딩 실패" 에러 화면 대신 로그인 화면으로 이동하고 `?reason=session_expired` 쿼리로 안내 토스트가 트리거됨. 인증 타입은 `templateId` 또는 pathname 의 `/admin` prefix 로 결정 (TemplateApp.showRouteError)
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

## [engine-v1.25.1]

### Fixed

- blur_until_loaded 래퍼가 CSS Grid 레이아웃을 깨뜨리는 버그 수정 (DynamicRenderer)
  - blur_until_loaded가 그리드 아이템을 래퍼 div로 감싸면서 col-span-*, row-span-* 등 그리드 클래스가 그리드 컨테이너의 직접 자식이 아니게 되어 적용되지 않음
  - 그리드 아이템 관련 클래스(col-span-*, row-span-*, self-*, order-* 등)를 blur 래퍼로 자동 전달
  - 반응형 접두사(sm:, md:, lg:, xl:, 2xl:) 포함 패턴 지원

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
