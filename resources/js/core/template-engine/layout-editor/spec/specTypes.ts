// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * specTypes.ts — EditorSpec 타입 정의
 *
 * Phase 1 에서 형식 골격을, Phase 3 가 `nesting` 블록을 채웠고, Phase 4
 * 이 나머지 블록(`controls` / `componentCapabilities` / `actionRecipes` /
 * `conditionRecipes` / `sampleGlobal` / `states`) 의 로딩·병합 대상 타입을
 * 정의한다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

/** 스펙 한 그릇 — 활성 확장 네임스페이스 병합 결과 (5.2 데이터 흐름) */
export interface EditorSpec {
  /** 스펙 스키마 버전 (`specVersion`) */
  version?: string;
  /**
   * 재사용 스타일 컨트롤 정의 — Phase 4. 위젯/현재값 역해석/apply
   * 동작을 한 곳에서 선언하고 componentCapabilities 가 참조한다. 코어는 본
   * 객체를 읽어 위젯을 디스패치만 한다(원칙 4.8). S6-1 은 로딩/병합 대상으로만
   * 다루고 컨트롤 렌더는
   */
  controls?: Record<string, EditorControlSpec>;
  /** 컴포넌트별 편집 역량 — Phase 4 에서 도입 */
  componentCapabilities?: Record<string, ComponentCapabilitySpec>;
  /** 드래그/추가 nesting 규칙 — Phase 3 에서 도입 */
  nesting?: NestingSpec;
  /**
   * 요소 추가 팔레트 — 그룹 정의 + 친화 라벨 + defaultNode.
   *
   * 그룹은 템플릿 소유 (2026-05-26 합의). 코어는 본 스펙을 읽어
   * 사이드바 카테고리 + 그리드 카드로 렌더만 한다. 미제공 시 코어 폴백은
   * components.json 의 basic/composite/layout 평면 분류.
   *
   * Phase 3 S5a-2 는 본 스펙 소비 로직만, 번들 템플릿 editor-spec.json 의
   * componentPalette 작성은 Phase 4 의 정식 발효 시점.
   */
  componentPalette?: ComponentPaletteSpec;
  /**
   * 친화 명칭 → 핸들러 JSON 레시피 — Phase 5 정식 발효.
   *
   * 각 레시피는 친화 입력란(`params`)과 핸들러 JSON 생성 틀(`build`)을 가진다.
   * `comment` 키는 작성자 메모로 레시피가 아니다(엔진이 무시). 그 외 키는
   * 레시피 id → `ActionRecipeSpec`. S6-1 은 로딩/병합만, S7 이 엔진/UI 발효.
   */
  actionRecipes?: Record<string, ActionRecipeSpec | string>;
  /**
   * 친화 조건 → `if` 표현식 레시피 — Phase 5 정식 발효.
   *
   * `operators` 배열에 조건 후보를 둔다(12.4.3 A~H). `comment` 는 작성자 메모.
   * S6-1 은 로딩/병합만, S7 이 엔진/UI 발효.
   */
  conditionRecipes?: ConditionRecipesSpec | Record<string, unknown>;
  /** 페이지 설정 레시피 — Phase 8 에서 도입 */
  pageSettings?: Record<string, unknown>;
  /**
   * 페이지 설정 [화면 동작] 탭 — `init_actions` 친화 핸들러 스펙.
   *
   * `actionRecipes` 와 동형(`ActionRecipeSpec | string`). 코어 핸들러 스펙은
   * `coreActionRecipes` 가 시드로 제공하고, 템플릿/모듈/플러그인이 자기 화면용 핸들러
   * 스펙을 더한다(editorSpecLoader 병합 — 코어 시드 → module → plugin → template).
   * 각 레시피는 병합 시 `__source`(어느 확장이 줬는지)를 달고, [화면 동작] 추가 목록의
   * 제공자 배지(-42)에 쓰인다. 레이아웃은 새 핸들러를 선언할 수 없으므로
   * 본 스펙은 "이미 등록된 핸들러를 친화 명칭으로 고르는" 카탈로그다.
   */
  initActionRecipes?: Record<string, ActionRecipeSpec | string>;
  /**
   * 페이지 설정 [에러 처리] 탭 — 오류 상황 친화 동작 스펙.
   *
   * `actionRecipes` 와 동형. 403/404/500 등에서 고를 수 있는 친화 동작(안내 페이지
   * 표시·다른 화면 이동 등)을 선언한다. 병합·`__source` 규칙은 `initActionRecipes` 와 동일.
   */
  errorRecipes?: Record<string, ActionRecipeSpec | string>;
  /**
   * 페이지 설정 [자동 계산] 탭 — `computed` 친화 보기 스펙.
   *
   * 9종 친화 보기(권한 readonly·옵션 변환·필터 등). 각 보기는 입력칸(`params`)을
   * 받아 `expr` 의 `{paramKey}` 를 치환해 `{{ 식 }}` 한 쌍을 만든다(computedRecipeEngine).
   * "직접 만들기"(3단계 고정 틀)는 코어 제공이라 본 스펙과 무관하게 항상 동작한다.
   * 병합·`__source` 규칙은 `initActionRecipes` 와 동일.
   */
  computedRecipes?: Record<string, ComputedRecipeSpec>;
  /**
   * 페이지 설정 [로딩 화면] 탭 — 로딩 표시 컴포넌트 후보.
   *
   * `transition_overlay.spinner/skeleton.component` 가 참조하는 레지스트리 이름의 후보
   * 목록. 각 템플릿이 자기 로딩 역할 컴포넌트를 선언한다(요소 추가 팔레트와 별개 경로 —
   * 캔버스 배치 대상이 아니므로 nesting 미참여). 비면 엔진 기본 CSS 스피너로
   * 디그레이드. 병합·`__source` 규칙은 다른 레시피 블록과 동일.
   */
  loadingComponents?: Record<string, LoadingComponentSpec>;
  /**
   * 샘플 데이터 오버라이드 —
   *
   * 편집 모드 캔버스 렌더 시 `DataSourceManager` 의 샘플 모드 분기가 본 스펙을
   * 사용해 데이터소스 응답을 결정한다. `byDataSourceId` 가 가장 우선, 그 다음
   * `byEndpointPattern` 매칭, 마지막으로 코어 프리셋 → fallback.
   */
  sampleData?: EditorSampleDataSpec;
  /**
   * `_global.*` baseline 시드 —.
   *
   * 편집 모드 격리 store 의 `_global.*` baseline 을 다룬다. 활성 확장의
   * sampleGlobal 은 코어 시드 → 모듈 → 플러그인 → 템플릿 순으로 deep merge
   * 되며, 코어 keyspace leaf 충돌 시 코어가 이기고 dev 콘솔에 경고를 출력한다
   * 배열은 통째 교체.
   */
  sampleGlobal?: Record<string, unknown>;
  /**
   * 페이지 상태 스펙 —
   *
   * 한 라우트/모달이 진입 맥락·사용자·검증 결과에 따라 가질 수 있는 변종
   * 화면을 선언한다. S6-1 은 본 블록의 로딩·병합(groups concat) + 합성
   * 유틸(`applyInitialPatch`) 만 다룬다. 캔버스 툴바 상태 토글 UI 는 후속.
   */
  states?: EditorStatesSpec;
  /**
   * 다크 모드 표현 선언 — (편집기 프리뷰 다크 격리).
   *
   * 템플릿이 어떤 방식으로 다크 모드를 표현하는지 선언한다(라이브러리 중립).
   * 편집기 프리뷰는 관리자 admin 환경의 `html.dark` 조상과 독립적으로 라이트/다크를
   * 보여줘야 하는데(조상 `.dark` 가 프리뷰 라이트 격리를 깸), 코어 CSS 서빙 API 가
   * 이 선언대로 편집기용 CSS 의 다크 셀렉터를 프리뷰 전용 마커로 치환해 서빙한다.
   * 일반 사용자 페이지 CSS 는 원본 그대로(무영향). 미선언/`none` 이면 다크 탭 비노출.
   */
  darkMode?: EditorDarkModeSpec;
  /**
   * 상태값 명칭 카탈로그 — / 6-a.
   *
   * `_global.currentUser`·`query.q` 등 **템플릿 공통 상태값**의 로케일별 친화 명칭을
   * 한 곳에서 선언한다(라이브러리·도메인 중립 — 코어는 카탈로그를 읽어 해석만 한다).
   * 데이터 연결 검색 피커가 후보를 표시할 때 raw 상태 키 대신 친화 명칭을 보여 주기
   * 위함이다. 명칭은 `$t:` 다국어 키라 언어팩으로 로케일이 동적 추가되면 그 로케일
   * 명칭도 자동 대응한다(고정 ko/en/ja 하드코딩 0). `label_key` 가 커버하지 못하는
   * 상태값(레이아웃마다 의미가 다른 `_local.*` 등)은 키를 그대로 폴백 표시한다 —
   * 마지막 세그먼트 가공·추측 명칭 생성은 하지 않는다.
   *
   * 네임스페이스 병합 시 `stateLabels` 는 concat 된다(편집기 로더 책임).
   */
  stateLabels?: EditorStateLabelSpec[];
  /**
   * 동작 데이터칩 컨텍스트 후보 — 확장 도메인 응답/오류/수신 필드 칩.
   *
   * 데이터소스 onSuccess(`response.*`)·onError(`error.*`)·onReceive(`message.*`)·컴포넌트
   * 동작 onSuccess 의 data-chip 입력칸이 표현식 칩으로 고를 수 있는 후보 목록을 컨텍스트별로
   * 선언한다. 코어는 컨텍스트 루트(`response.data`/`error.status` 등 도메인 중립 필드)만 안다 —
   * 확장이 내려주는 도메인 응답 필드(예: PG 결제 응답의 `data.pg_payment_handler`)는 그 확장이
   * 본 블록으로 선언해 코어가 도메인을 모른 채 병합·노출하게 한다.
   *
   * 네임스페이스 병합 시 컨텍스트별 배열은 concat 된다(편집기 로더 책임 — 코어 기본 후보 뒤에
   * 확장 후보가 붙는다). 라벨(`labelKey`)은 그 확장의 lang 네임스페이스를 명시한다.
   */
  actionChipCandidates?: EditorActionChipCandidatesSpec;
}

/**
 * 동작 데이터칩 컨텍스트 후보 — 컨텍스트별(response/error/payload) 후보 배열.
 *
 * 각 후보는 컨텍스트 루트 이하 점 경로(`path`) + 친화 라벨 다국어 키(`labelKey`) +
 * shape(scalar/object/array — 위젯 필터). `buildActionContextCandidates` 가 코어 기본
 * 후보 뒤에 이어 붙여 표현식 칩 후보로 변환한다(expression = `{{root.path}}`).
 */
export interface EditorActionChipCandidatesSpec {
  response?: EditorActionChipCandidateSpec[];
  error?: EditorActionChipCandidateSpec[];
  payload?: EditorActionChipCandidateSpec[];
}

/** 동작 데이터칩 후보 1건 — 컨텍스트 루트 이하 경로 + 친화 라벨 키 + shape */
export interface EditorActionChipCandidateSpec {
  /** 컨텍스트 루트(response/error/message) 이하 점 경로 — 예 `data.pg_payment_handler` */
  path: string;
  /** 친화 라벨 다국어 키(`$t:` 접두 없이 키만) — 확장 lang 네임스페이스 명시 */
  labelKey: string;
  /** 위젯 필터용 shape(scalar/object/array). 미지정 시 scalar */
  shape?: 'scalar' | 'object' | 'array';
}

/**
 * 상태값 명칭 카탈로그 항목 1건 — / 6-a.
 *
 * 데이터 연결 검색 피커가 상태값 후보(`_global`/`_local`/`route`/`query`/`_computed`)를
 * 표시할 때 친화 명칭을 결선하는 선언. `key`+`scope` 로 후보를 식별하고 `label_key`
 * 다국어 키로 명칭을 해석한다(편집 대상 템플릿 사전 — `feedback_editor_*` 격리 정합).
 */
export interface EditorStateLabelSpec {
  /**
   * 상태 키 — scope 루트 이하 점 경로(예 `currentUser.data.name`, `q`). 후보 풀의
   * 평탄 경로와 정확 일치로 매칭한다(접두사 매칭 아님 — 명시한 leaf 만 명명).
   */
  key: string;
  /** 상태 스코프 — 어느 상태 트리의 키인가 */
  scope: BindingSourceKind;
  /** 친화 명칭 — `$t:` 다국어 키. 미해석 시 raw 키 폴백(키 노출 회피 책임은 피커) */
  label_key: string;
}

/**
 * 데이터 바인딩 소스 종류 — / 6-a.
 *
 * 데이터 prop 이 바라볼 수 있는 상태/데이터 출처. `data_source` 는 레이아웃의
 * `data_sources[]` 정의(런타임 fetch), 나머지는 런타임 바인딩 컨텍스트
 * (`DataBindingEngine` 노출 루트)와 1:1 대응한다.
 */
export type BindingSourceKind =
  | 'data_source'
  | '_global'
  | '_local'
  | 'route'
  | 'query'
  | '_computed'
  | 'iteration';

/**
 * 데이터형 prop 선언 1건 — / 6-a.
 *
 * 컴포넌트의 어떤 prop 이 "데이터를 바라보는" prop 인지를 명시 선언한다. [속성] 탭
 * "데이터 연결" 전용 영역이 본 선언을 행으로 렌더하고, 각 행은 **항상 바인딩 모드**다
 * (정적↔바인딩 토글 없음 — 1차 결함 근절). 선택 시 `{{<source>.<path>}}` 가
 * `props[propKey]` 에 기입된다(런타임 `DataBindingEngine` 동일). 구조/수치(열 수·간격·
 * 페이지크기)·enum 선택·boolean config 는 **dataProps 비대상**(propControls 정적 편집).
 *
 * `propControls` 와 직교한다 — 한 prop 이 둘 다에 오지 않는다.
 */
export interface DataPropSpec {
  /** 바인딩 대상 prop 경로 (단일 키, 예 `data`/`items`/`options`/`value`/`text`) */
  propKey: string;
  /**
   * 데이터 shape — 단일값 바인딩(`scalar`)·배열 바인딩(`array`)·단일 객체 바인딩(`object`).
   * scalar 행은 스칼라 leaf 후보만, array 행은 배열 leaf 후보만, object 행은 객체 노드
   * 후보만 검색 피커에 노출한다. object 는 컴포넌트가 객체 1건을 통째로 바라보는 prop
   * (예 ProductCard.product, Avatar.author, UserProfile.user — 단일 도메인 객체) 용도다.
   */
  shape: 'scalar' | 'array' | 'object';
  /** 친화 라벨 — `$t:` 키. 미지정 시 propKey 그대로 */
  label?: string;
  /**
   * (array 한정) 항목 객체의 주요 필드 목록 — 검색 피커 항목 미리보기 + 경로
   * 자동완성 힌트. 예 `["name","price","imageUrl"]`.
   */
  itemFields?: string[];
  /** 데이터 prop 누락 시 캔버스 빈 렌더 경고 표시(⚠필수 배지) */
  required?: boolean;
  /** 허용 소스 종류 제한 — 미지정 = 전체. 예 `["data_source"]`(상태 바인딩 불가) */
  sources?: BindingSourceKind[];
}

/**
 * 다크 모드 표현 스펙 —
 *
 * `strategy`:
 *  - `ancestor-class`: 조상 클래스로 다크 활성(Tailwind `.dark`, Bootstrap `[data-bs-theme=dark]`).
 *  - `media-query`: `@media (prefers-color-scheme: dark)`.
 *  - `none`: 다크 미지원 — 편집기 다크 탭 비노출(안전 디그레이드).
 *
 * `ancestorSelector`: 다크를 켜는 조상 셀렉터(예 `.dark`). `ancestor-class` 전략에서 사용.
 * `previewIsolation`: 편집기 프리뷰 격리용 셀렉터 치환 규칙. CSS 서빙 API 가
 *   `rewriteSelector`(원본 다크 셀렉터)를 `replaceWith`(프리뷰 전용 마커)로 바꿔 서빙한다.
 */
export interface EditorDarkModeSpec {
  strategy?: 'ancestor-class' | 'media-query' | 'none' | string;
  ancestorSelector?: string;
  previewIsolation?: {
    /** 원본 CSS 의 다크 셀렉터 토큰(예 `.dark`) */
    rewriteSelector?: string;
    /** 프리뷰 전용으로 치환할 마커(예 `.g7le-preview-dark`) */
    replaceWith?: string;
    /**
     * CSS cascade-layer(`@layer`)를 쓰는 라이브러리(Tailwind v4 등)에서, 편집기 CSS 가
     * 어드민 호스트 CSS 와 같은 `@layer` 이름을 공유해 cross-build 우선순위 충돌이 나는 경우
     * `true` 로 두면 코어 CSS 서빙 API 가 편집기 CSS 의 `@layer` 래퍼를 평탄화(unlayered)해
     * 프리뷰 규칙이 호스트 레이어드 규칙을 확실히 이기게 한다. `@layer` 비사용 라이브러리는
     * 미선언/false (평탄화 불필요).
     */
    flattenLayers?: boolean;
  };
}

/**
 * 재사용 스타일 컨트롤 정의
 *
 * S6-1 은 로딩/병합 대상 타입으로만 정의한다. 위젯 디스패치·apply 동작·현재값
 * 역해석(reverseResolve) 의 정식 구현은
 */
export interface EditorControlSpec {
  /** 위젯 종류 — `color-picker`/`spacing`/`numeric-with-unit`/`select`/`text` 등  */
  widget?: string;
  /** 친화 라벨 — `$t:...` 다국어 키 */
  label?: string;
  /** apply 타입 — `classToken`/`styleProp`/`cssVar`/`propValue` (원칙 4.8) */
  apply?: string;
  /** apply 대상 키(스타일 속성명/클래스 토큰/CSS 변수명/prop 경로 등) */
  target?: string | string[];
  /** select 위젯 등의 선택지 */
  options?: unknown[];
  /** 같은 group 의 기존 토큰을 교체하는 키 (classToken 중복 방지) */
  group?: string;
  /**
   * 토큰 합성 위젯(spacing 등)의 group 충돌 prefix 목록 — 예 여백 `["p-","px-",...]`.
   * 이 prefix 로 시작하는 기존 토큰을 같은 group 으로 보고 교체한다.
   */
  groupPrefixes?: string[];
  /**
   * 이 group 에 속하는 **상호배타 토큰 전체 목록**. 옵션에 없는 기본 className
   * 토큰(예: 옵션은 normal/semibold/bold 인데 노드 기본이 `font-medium`)도 같은 group 으로
   * 인식해 교체되게 한다. 어떤 토큰이 한 group 인지는 **템플릿의 스타일 라이브러리 지식**
   * 이므로 템플릿 editor-spec 이 선언한다(코어는 목록을 읽어 적용만 — 라이브러리 중립).
   */
  groupTokens?: string[];
  /**
   * 아이콘 카탈로그 — `icon-picker` 위젯이 그리드로 표시할 후보 목록.
   *
   * **카탈로그는 템플릿 소유**(폰트어썸/테일윈드 등 특정 라이브러리 지식). 코어 icon-picker
   * 위젯은 본 배열이 있으면 그리드, 없으면 자유입력(text) 폴백 — 코어에 라이브러리 목록 0
   * (완전 중립). value = 아이콘명 문자열(propValue 로 `node.props[propKey]` 에 기록).
   */
  icons?: IconCatalogEntry[];
  /**
   * 라이브 프리뷰용 템플릿 컴포넌트명 — icon-picker 가 `ComponentRegistry.getComponent(iconComponent)`
   * 로 그 컴포넌트를 `{[iconProp ?? propKey]: entry.value}` 로 렌더해 실제 아이콘을 미리본다.
   * 미제공 시 `entry.preview.html`(템플릿 공급) → `preview.className` → raw value 폴백.
   */
  iconComponent?: string;
  /** 프리뷰 컴포넌트에 아이콘명을 넘길 prop 키 (미제공 시 본 컨트롤의 propKey 사용) */
  iconProp?: string;
  /** 그리드 열 수 (기본 코어 폴백) */
  iconColumns?: number;
  /** 검색 입력 placeholder — `$t:...` 키 */
  iconSearchPlaceholder?: string;
  /** 작성자 자유 필드 — 코어는 widget/apply 외 필드를 그대로 보존만 한다 */
  [key: string]: unknown;
}

/**
 * 아이콘 카탈로그 항목 1건 — icon-picker 위젯 그리드의 한 칸.
 *
 * 카탈로그 항목은 템플릿의 아이콘 라이브러리 지식이므로 **템플릿 editor-spec 이
 * 선언**한다(코어는 라이브러리 토큰 0 — 라이브러리 중립). 프리뷰는 `iconComponent`
 * 라이브 렌더 → `preview.html` → `preview.className` → raw value 순으로 폴백한다.
 */
export interface IconCatalogEntry {
  /** 아이콘 식별값 — propValue 로 `node.props[propKey]` 에 기록 */
  value: string;
  /** 그리드 라벨/툴팁 — `$t:...` 키 또는 평문 (선택) */
  label?: string;
  /** 검색 매칭용 키워드 (선택) */
  keywords?: string[];
  /** 그리드 그룹핑 키 (선택) */
  group?: string;
  /**
   * 라이브러리 중립 프리뷰 폴백 — 템플릿이 공급. `iconComponent` 미제공/렌더 실패 시 사용.
   * 코어는 html/className 을 해석하지 않고 그대로 렌더만 한다(라이브러리 토큰 비종속).
   */
  preview?: {
    /** dangerouslySetInnerHTML 로 렌더할 마크업(예: `<i class="fa fa-star"></i>`) */
    html?: string;
    /** 프리뷰 span 에 부여할 className(예: 아이콘 폰트 클래스) */
    className?: string;
  };
}

/**
 * 편집기 샘플 데이터 스펙 —
 */
export interface EditorSampleDataSpec {
  comment?: string;
  byDataSourceId?: Record<string, unknown>;
  byEndpointPattern?: Record<string, unknown>;
  /**
   * 출처별 byDataSourceId 보존 맵.
   *
   * 키 = `"{kind}:{identifier}"` (module/plugin) 또는 `"template"` (편집 대상 템플릿).
   * `editorSpecLoader` 가 각 출처 스펙의 `byDataSourceId` 를 이 맵에 출처별로 보존한다.
   * 같은 데이터소스 `id` 를 여러 확장이 서로 다른 shape 로 정의해도, 해소 시점에
   * 그 데이터소스의 `__source` 출처로 분기해 올바른 샘플을 고른다(전역 id 충돌 해소).
   * 평탄 `byDataSourceId` 는 출처 미상(`__source` 부재) 폴백으로 유지된다.
   */
  bySource?: Record<string, Record<string, unknown>>;
  /** 상태별 오버라이드는 states 블록이 별도 — 본 인터페이스는 기본값만 */
  states?: unknown;
}

/** 컴포넌트 편집 역량 — Phase 4 상세 (16.4) */
export interface ComponentCapabilitySpec {
  /** 친화 명칭 ($t: 키) */
  label?: string;
  /** 편집 가능한 속성 그룹 */
  tabs?: unknown[];
  /**
   * 속성 탭에 노출할 컨트롤 키 화이트리스트.
   *
   * 각 키는 `controls` 의 `apply:{type:"propValue",propKey:...}` 컨트롤을 참조하며,
   * 컴포넌트의 비-스타일 prop(Icon name/Img src/A href/Input type 등)을 편집한다.
   * styleControls 와 평행하지만 별개 탭([속성])에 렌더되고, scope 비종속(BASE_SCOPE
   * 고정 — propValue 는 다크/디바이스 분기 없음)이다. 코어는 본 목록을 읽어
   * ControlRenderer 로 디스패치만 한다(원칙 4.8). 컨트롤 렌더 발효는
   */
  propControls?: string[];
  /**
   * 코어 제공 속성 opt-out/부분선택.
   *
   * 코어는 모든 draggable 의 [속성] 탭 최상단에 일괄 속성 컨트롤(현재 = 요소 `id`)을
   * 제공한다. 본 필드로 템플릿이 그 동작을 조정한다:
   *  - 미선언(undefined) → 코어 기본 전체 제공(`['id']`).
   *  - `false` → 코어 컨트롤 전무(opt-out).
   *  - `string[]` → 그 중 알려진 코어 키만 노출(부분집합).
   *
   * 코어 컨트롤은 표준 `node.props.{propKey}` 로만 값을 흘려보낸다(강제 DOM 주입 X).
   * 확장 `propControls` 가 같은 propKey 를 또 선언하면 코어가 우선(중복 제거). 코어 키
   * 목록/매핑은 엔진 `spec/coreProps.ts` 가 SSoT.
   */
  coreProps?: false | string[];
  /**
   * 일반 노드 에디터 슬롯 — 속성/스타일 슬롯에 직접 렌더할 구조 에디터.
   *
   * `kind` 로 `nodeEditorRegistry` 를 조회해 그 핸들러 컴포넌트를 모달 본체에 렌더한다.
   * 코어는 kind 가 무엇인지 모르고(table/children/신규 종류 모두 동일 경로), 레지스트리가
   * 해석한다. `params` 는 그 kind 에디터 소유의 불투명 객체(코어는 전달만):
   *  - table: `{ rowContainer, row, cell, headerCell, colSpanProp, rowSpanProp, cellBorder, cellBackground, cellPadding }`
   *    (`cellBackground`/`cellPadding` 는 셀 배경색/내부 여백 시각 피커 카탈로그 — 인라인 style SSoT)
   *  - children: `{ childComponent, childLabel?, childTemplate?, itemFields? }` — childTemplate=
   *    추가 골격 노드(라벨+입력칸 묶음 등, childComponent 골격보다 우선), itemFields=항목 행
   *    편집 필드 선언(`{kind:"text"|"prop",prop?,label?}`, 미선언 시 text 1필드). 상세:
   *    docs/extension/editor-spec.md "children kind params"
   * **종류별 고정 키(tableEditor/childrenEditor)를 capability 에 두지 않는다** — 새 kind
   * 추가가 코어 capability 타입 변경 없이 가능해진다. 미등록 kind 는 안전 디그레이드(no-op).
   */
  nodeEditor?: NodeEditorSlotSpec;
  /**
   * 일반 캔버스 인플레이스 오버레이 슬롯.
   *
   * `kind` 로 `canvasOverlayRegistry` 를 조회해 캔버스 오버레이 레이어에 마운트한다.
   * `nodeEditor` 와 같은 일반 슬롯 모델 — kind/params 구조 동일, 디스패치만 캔버스 경로.
   * 미등록 kind 는 기존 코어 선택/삽입 오버레이로 디그레이드.
   */
  canvasOverlay?: CanvasOverlaySlotSpec;
  /** 스타일 탭에 노출할 컨트롤 키 화이트리스트  */
  styleControls?: string[];
  /** 고급 탭에 노출할 컴포넌트 레벨 고급 속성 화이트리스트  */
  advanced?: string[];
  /**
   * 액션 편집 가능 이벤트 — `onClick`/`onHover` 등.
   * 미선언 시 동작 탭을 숨긴다.
   */
  events?: string[];
  /**
   * "정렬 박스"(flex) 편집 역할 — `container`|`item`|`auto`.
   * 미선언 시 flex 편집 컨트롤을 숨긴다.
   */
  flexEditor?: 'container' | 'item' | 'auto';
  /**
   * 표시 조건 편집 허용 여부. `false` 면 표시조건 탭을 숨긴다.
   * 미선언/그 외 값은 허용(기본 노출).
   */
  visibilityCondition?: boolean;
  /**
   * 텍스트(보간) 데이터 연결 대상 여부. `text` prop 의 `{{...}}` 보간 조각을
   * [속성] 탭 "텍스트 데이터 연결" 영역에서 조각 단위로 교체/해제/추가할 수 있게 한다.
   *
   *  - 미선언(undefined) → 코어 SSoT 텍스트 컴포넌트 집합(`textComponents.CORE_TEXT_COMPONENTS`)
   *    또는 string `text` 보유 노드면 자동 노출(폴백). 코어가 보편 텍스트 집합을 안다.
   *  - `true` → 명시 opt-in(코어 집합 밖의 템플릿 자체 텍스트 컴포넌트).
   *  - `false` → 명시 opt-out(텍스트처럼 보여도 보간 편집 비대상).
   *
   * iteration 노드는 어느 경우든 제외(반복 소스는 IterationBindingSection 축). 판정 SSoT 는
   * 엔진 `spec/textComponents.ts`(`isTextBindableNode`).
   */
  textBinding?: boolean;
  /** 작성자 자유 필드 — 코어는 알려진 필드 외 그대로 보존 */
  [key: string]: unknown;
}

/**
 * 일반 구조 에디터 슬롯.
 *
 * 코어는 종류를 모른다(kind-agnostic). `kind` 로 레지스트리(nodeEditor 또는
 * canvasOverlay)를 조회해 핸들러를 디스패치하고, `params` 는 그 핸들러가 해석하는
 * 불투명 객체로 전달만 한다. **역할 매핑이 specTypes 고정 타입이 아니라 각 에디터
 * 소유의 params 스키마로 이동** → 새 kind 추가가 코어 타입 변경 없이 가능.
 *
 * `NodeEditorSlotSpec` 와 `CanvasOverlaySlotSpec` 는 현재 동일 구조지만, 두 슬롯의
 * 의미(속성탭 본체 vs 캔버스 오버레이)가 달라 별도 타입명으로 둔다(향후 분기 여지).
 */
export interface EditorSlotSpec {
  /** 핸들러 kind — 레지스트리 조회 키(table/children/신규 종류). 코어는 해석 안 함 */
  kind: string;
  /** 그 kind 에디터 소유의 불투명 파라미터 객체 — 코어는 전달만 */
  params?: Record<string, unknown>;
}

/** 속성/스타일 슬롯에 직접 렌더할 노드 에디터 슬롯 (nodeEditorRegistry 조회) */
export type NodeEditorSlotSpec = EditorSlotSpec;

/** 캔버스 인플레이스 오버레이 슬롯 (canvasOverlayRegistry 조회) */
export type CanvasOverlaySlotSpec = EditorSlotSpec;

/**
 * 드래그/추가 nesting 규칙
 *
 * - `draggable`: 편집기에서 드래그/팔레트 배치 가능한 컴포넌트 이름 목록.
 *   목록에 없는 컴포넌트는 드래그 핸들이 부여되지 않고, 팔레트에서 선택해도
 *   삽입되지 않는다.
 * - `containers`: 컨테이너 컴포넌트별로 자식으로 받을 수 있는 컴포넌트 이름.
 *   엔트리가 없는 컴포넌트는 어떤 자식도 받지 않는다(폴백 없음 — 결정 3.3).
 *   `accepts: []` 인 컴포넌트는 명시적으로 자식 거부(leaf 또는 composite).
 * - `comment`: 작성자 메모 — 평가에는 사용되지 않음.
 *
 * `editor-spec.json` 미제공 템플릿은 nesting 이 비어 있고, 편집기는 드래그/
 * 요소 추가가 전부 비활성(EmptySpecNotice)이다.
 */
export interface NestingSpec {
  /** 작성자 메모 — 평가에는 사용되지 않음 */
  comment?: string;
  /** 드래그/팔레트 배치 가능한 컴포넌트 이름 목록 */
  draggable?: string[];
  /** 컨테이너별 자식 허용 목록 */
  containers?: Record<string, NestingContainerRule>;
}

export interface NestingContainerRule {
  /** 자식으로 받을 수 있는 컴포넌트 이름 목록. `[]` = 명시적 자식 거부 */
  accepts: string[];
}

/**
 * 요소 추가 팔레트 — 그룹 + 친화 라벨 + defaultNode.
 *
 * 본 스펙의 주체는 템플릿. 코어는 본 객체를 읽어 사이드바 카테고리 + 그리드
 * 카드로 렌더만 한다.
 *
 * Phase 3 S5a-2 는 소비 로직만 도입한다. 번들 템플릿 editor-spec.json 의
 * componentPalette 작성은 Phase 4 의 정식 발효 시점.
 */
export interface ComponentPaletteSpec {
  /**
   * 좌측 사이드바 카테고리 목록 + 각 카테고리에 속하는 컴포넌트 이름.
   *
   * 순서대로 사이드바에 표시. 본 배열이 비었거나 미정의면 코어 폴백
   * (components.json 의 basic/composite/layout 평면 분류) 사용.
   */
  groups?: ComponentPaletteGroupSpec[];
  /**
   * 컴포넌트별 친화 라벨 + 신규 노드 골격(defaultNode).
   *
   * `entries` 에 명시되지 않은 컴포넌트도 팔레트에 노출될 수 있다(`groups`
   * 에 포함되어 있는 한). 라벨 fallback 순서: `entries[name].label` →
   * components.json description → name. 
   */
  entries?: Record<string, ComponentPaletteEntrySpec>;
}

export interface ComponentPaletteGroupSpec {
  /** 사이드바에 표시할 라벨 — `$t:...` 다국어 키 권장  */
  label: string;
  /** 본 그룹의 의미 종류 (선택). 코어 표시에는 영향 없음, 진단/문서용. */
  kind?: 'design' | 'data' | string;
  /** 본 그룹에 속하는 컴포넌트 이름 목록 (components.json 의 name 기준) */
  components: string[];
}

export interface ComponentPaletteEntrySpec {
  /** 친화 라벨 — `$t:...` 다국어 키 권장 */
  label?: string;
  /** defaultNode 부재 시 클릭 비활성 + 안내. 본 Phase 미사용 */
  requiresDefaultNode?: boolean;
  /**
   * 신규 노드 골격 — 컴포넌트 트리 노드와 동일 구조 (`type`/`name`/`props`/
   * `text`/`children`). 정식 발효는 S5a-3. Phase 3 S5a-2 는 본
   * 필드를 읽기만 하고, 미제공 시 `components.json` props.default 폴백.
   */
  defaultNode?: Record<string, unknown>;
}

/**
 * 페이지 상태 스펙
 *
 * 라우트/base/modal 별로 그룹을 두고, 각 그룹은 한 진입 단위가 가질 수 있는
 * 변종 상태(item)들을 선언한다. 네임스페이스 병합 시 `groups` 는 concat 된다
 * (라인 10853). 각 상태는 sampleData 오버라이드 + 초기 상태 패치 +
 * 폼 검증 시뮬레이션의 결합이다.
 */
export interface EditorStatesSpec {
  /** 진입 단위(라우트/base/modal) 별 상태 그룹 목록 */
  groups?: EditorStateGroupSpec[];
}

export interface EditorStateGroupSpec {
  /** 매칭 범위 — 어떤 라우트/base/modal 에 본 상태 세트를 적용하는가 */
  scope?: EditorStateScopeSpec;
  /** 본 그룹의 변종 상태 목록 */
  items?: EditorStateItemSpec[];
}

export interface EditorStateScopeSpec {
  /** 매칭 종류 — route(라우트 path) / base(베이스 식별자) / modal(modals[].id) */
  kind?: 'route' | 'base' | 'modal' | string;
  /** 매칭 키 — kind 에 따라 path / base 식별자 / modal id */
  match?: string;
}

/**
 * 페이지 상태 1건 — 3 갈래 시뮬레이션(샘플데이터 / 초기상태 / 폼 검증).
 *
 * `initialState.global` 은 의 `sampleGlobal` baseline 위에 얹는
 * state-specific patch 로 해석된다. 합성은
 * `pageStateSimulator.applyInitialPatch` 가 수행한다.
 */
export interface EditorStateItemSpec {
  /** 상태 식별자 — 그룹 내 고유 */
  id: string;
  /** 친화 라벨 — `$t:...` 다국어 키 */
  label?: string;
  /** 토글 항목 우측 회색 설명 — `$t:...` (선택) */
  description?: string;
  /** 그룹당 1개. 누락 시 첫 항목이 기본  */
  default?: boolean;
  /** sampleData 와 동일 구조의 상태별 오버라이드 (통째 교체) */
  sampleDataOverrides?: EditorSampleDataSpec;
  /**
   * 초기 상태 패치 — 진입 맥락/사용자/검증 결과에 따른 화면 변종을 시뮬레이션한다.
   * - `local`/`global`: `_local`/`_global` baseline 위 부분 머지.
   * - `query`: URL 쿼리 컨텍스트 오버라이드 (`{{query.error}}`/`{{query.tab}}` 등 진입
   *   맥락 분기 재현 — 편집기는 평소 query 가 비어 있어 이 패치 없이는 query 분기 변종을
   *   미리볼 수 없다). 통째 교체가 아니라 baseline(빈 객체) 위 머지.
   * - `route`: path param 컨텍스트 오버라이드 (`{{route.id}}` 있음↔없음 = 수정↔신규 작성
   *   모드 변종). `null` 값을 주면 해당 토큰 제거(신규 작성 모드 — sampleRouteParams 자동
   *  채움을 무력화).
   */
  initialState?: {
    local?: Record<string, unknown>;
    global?: Record<string, unknown>;
    query?: Record<string, unknown>;
    route?: Record<string, unknown>;
  };
  /**
   * 폼 검증 실패 시뮬레이션 — **화면이 실제로 읽는 상태 경로** → 그 경로가 기대하는 값.
   *
   * 키는 `_local.`/`_global.` 접두 + dot-path(예 `_local.errors.email` /
   * `_global.loginErrors.email`). 점은 칸막이로 해석되지만, **키 자체에 점이 박힌
   * 필드**는 대괄호 표기로 감싸 리터럴 leaf 로 지정한다 — 예 `/shop/checkout` 의
   * 주문자 입력칸은 `_local.errors?.['orderer.name']` 을 읽으므로 키를
   * `_local.errors['orderer.name']` 로 적는다.
   * 값은 그 경로가 기대하는 형태 — 대개 표시 메시지 문자열 또는 `[message]` 배열
   * (화면이 `?.[0]` 으로 읽으면 배열). `$t:` 키는 편집 대상 사전으로 해석된 뒤 주입된다.
   */
  formErrors?: Record<string, string | string[]>;
}

// ── 액션 레시피 ─────────────────────────────────

/**
 * 액션 레시피 1건 — 친화 명칭 → 핸들러 JSON.
 *
 * `params` 의 친화 입력값으로 `build` 틀을 채워 `actions` JSON 1건을 만든다.
 * 핸들러명/파라미터 키는 유저에게 노출하지 않는다(12.2.1) — 유저는 `label`
 * 친화 명칭과 `params[].label` 친화 입력란만 본다.
 *
 * S6-2 의 단축형(`{ label, handler }`)도 하위호환으로 받는다 — `params` 부재 시
 * 핸들러만 가진 빈 액션으로 빌드된다(엔진이 `build` 폴백 합성).
 */
export interface ActionRecipeSpec {
  /** 친화 명칭 — `$t:...` 키 또는 평문  */
  label?: string;
  /** 친화 입력란 정의 — 순서대로 위젯 렌더 */
  params?: ActionRecipeParamSpec[];
  /** 핸들러 JSON 생성 틀 — `{{paramKey}}` 플레이스홀더가 입력값으로 치환 */
  build?: ActionBuildTemplate;
  /**
   * 하위호환 단축형 — `build` 부재 시 이 핸들러로 빈 액션을 만든다.
   * 신규 스펙은 `build.handler` 를 쓴다.
   */
  handler?: string;
  /** 작성자 자유 필드 — 엔진은 보존만 */
  [key: string]: unknown;
}

/**
 * 액션 레시피 친화 입력란 1건.
 *
 * `widget` 은 `page-picker`/`datasource-picker`/`state-key-picker`/`i18n-text`/
 * `select`/`text`/`action-list` 등. `action-list` 는 중첩 후속 액션 목록
 * (onSuccess/onError) 을 조립한다(12.2.3).
 */
export interface ActionRecipeParamSpec {
  /** 파라미터 키 — `build` 틀의 `{{key}}` 와 매칭 */
  key: string;
  /** 친화 라벨 — `$t:...` 키 또는 평문 */
  label?: string;
  /** 입력 위젯 종류 */
  widget?: string;
  /** select 위젯 등의 선택지 */
  options?: Array<{ value: unknown; label?: string }>;
  /** 작성자 자유 필드 */
  [key: string]: unknown;
}

/**
 * 핸들러 JSON 생성 틀. `handler` 와 임의 키를 갖는다.
 *
 * 값에 `{{paramKey}}`(중괄호 2개) 가 있으면 그 파라미터 입력값으로 치환된다.
 * `onSuccess`/`onError` 값이 `{{key}}` 이고 그 파라미터가 `action-list` 면,
 * 치환 결과는 중첩 액션 **배열**이 된다(문자열 보간이 아니라 값 주입).
 */
export interface ActionBuildTemplate {
  /** 핸들러명 — 코어 핸들러 규칙의 올바른 핸들러 (navigate/apiCall/...) */
  handler: string;
  /** setState 등의 target (top-level — params 안에 두지 않음, 코어 규칙) */
  target?: unknown;
  /** apiCall 성공 후속 — `{{paramKey}}`(action-list) 또는 정적 배열 */
  onSuccess?: unknown;
  /** apiCall 실패 후속 — `{{paramKey}}`(action-list) 또는 정적 배열 */
  onError?: unknown;
  /** 핸들러 파라미터 — 값에 `{{paramKey}}` 치환 */
  params?: Record<string, unknown>;
  /** 작성자 자유 필드 */
  [key: string]: unknown;
}

// ── 자동 계산 레시피 ──────────────────────────

/**
 * 자동 계산 친화 보기 1건 —·
 *
 * `computed` 의 한 키를 만드는 친화 보기. `params` 로 입력칸을 받고, `expr` 의
 * `{paramKey}`(중괄호 1개) 플레이스홀더를 입력값으로 치환해 최종적으로 `{{ 식 }}`
 * 한 쌍을 만든다(conditionRecipeEngine 의 `{key}` 치환 패턴 차용 — 중첩 보간 회피).
 *
 * `expr` 은 조건 레시피와 동일하게 `{{}}` **없이** 식 본문만 적는다. 엔진
 * (`computedRecipeEngine`)이 치환 후 한 쌍으로 감싼다. 매칭(역해석)은
 * `matchComputed` 가 `expr` 구조로 시도한다.
 */
export interface ComputedRecipeSpec {
  /** 친화 명칭 — `$t:...` 키 또는 평문 */
  label?: string;
  /** 친화 입력란 — `expr` 의 `{key}` 와 매칭 (조건 레시피와 동형) */
  params?: ConditionParamSpec[];
  /** 식 본문 — `{{}}` 없이, `{paramKey}` 플레이스홀더 사용 */
  expr: string;
  /**
   * 분류 그룹 — [자동 계산] 보기 목록의 "자주 쓰는"/"그 밖에" 구분(-33).
   * 미지정 시 "그 밖에"로 분류. UI 표기 전용(엔진 무관).
   */
  group?: 'common' | 'more' | string;
  /** 작성자 자유 필드 — 엔진은 보존만 */
  [key: string]: unknown;
}

/**
 * 로딩 표시 컴포넌트 후보 1건 —-4.
 *
 * [로딩 화면] 탭의 로딩 컴포넌트 "요소 선택" 모달이 보여줄 후보. `name` 은
 * 컴포넌트 레지스트리 이름(=`transition_overlay.spinner/skeleton.component` 에 기록될
 * 문자열), `role` 은 어느 스타일에서 노출할지(style=spinner→spinner/page, skeleton→
 * skeleton) 필터링 기준, `label` 은 친화 명칭(`$t:` 다국어 키)이다.
 */
export interface LoadingComponentSpec {
  /** 컴포넌트 레지스트리 이름 — overlay.spinner/skeleton.component 참조값 */
  name: string;
  /** 로딩 역할 — style 별 후보 필터 (role 필터) */
  role: 'spinner' | 'skeleton' | 'page' | string;
  /** 친화 명칭 — `$t:...` 다국어 키 또는 평문 */
  label?: string;
  /** 작성자 자유 필드 — 엔진은 보존만 */
  [key: string]: unknown;
}

// ── 조건 레시피 ─────────────────────────────────

/**
 * 조건 레시피 블록.
 *
 * `operators` 배열에 친화 조건 후보(12.4.3 A~H)를 둔다. 각 항목은 `value`
 * (식별자) + `label` + 선택적 `params` + `expr`(식 본문, `{{}}` 없이). S6-2 의
 * 평탄 맵 형태(`{ id: { label, expr } }`)도 로더가 받지만, S7 엔진은 `operators`
 * 배열을 표준으로 한다 — `normalizeConditionRecipes` 가 둘 다 흡수한다.
 */
export interface ConditionRecipesSpec {
  /** 작성자 메모 */
  comment?: string;
  /** 친화 조건 후보 목록 (12.4.3) */
  operators?: ConditionOperatorSpec[];
  /** 작성자 자유 필드 */
  [key: string]: unknown;
}

/**
 * 조건 후보 1건 (12.4.3).
 *
 * `expr` 은 `{{ }}` 없이 식 본문만 적고, 파라미터 자리는 레시피 전용
 * 플레이스홀더 `{paramKey}`(중괄호 1개)를 쓴다(12.4.2). `ConditionBuilder`
 * 가 파라미터를 치환하고 여러 조건을 `&&`/`||` 로 결합한 뒤 **최종 결과 전체를
 * 단일 `{{ }}` 한 쌍**으로 감싼다 — 중첩 보간 `{{ {{x}} }}` 미발생.
 */
export interface ConditionOperatorSpec {
  /** 조건 식별자 — 레시피 블록 내 고유 */
  value: string;
  /** 친화 명칭 — `$t:...` 키 또는 평문 */
  label?: string;
  /** 파라미터 입력란 — `expr` 의 `{key}` 와 매칭 */
  params?: ConditionParamSpec[];
  /** 식 본문(canonical) — `{{}}` 없이, `{paramKey}` 플레이스홀더 사용. **생성(프리셋 선택) 시 항상 이 값**으로 `if` 가 합성된다(결정적). */
  expr: string;
  /**
   * 매칭 전용 구조 변종(L2 recipe-local alias).
   *
   * 역해석(인식)은 `[expr, ...aliases]` 를 모두 시도해 어떤 준동치 구조 변종이 와도
   * 그 프리셋으로 표시한다(식 원문은 불변 — 인식만, 저장값 미변경). **생성에는 쓰지
   * 않는다** — 생성은 항상 `expr`(canonical). 전역 엔진 정규화(L1)가 흡수하지 못하는
   * **준동치형**(`X && X.length>0` ↔ `(X??[]).length>0` 등 런타임 미세차) 구조 변종을
   * 그 템플릿 범위에서만 옵트인 흡수한다(제3자 템플릿/엔진 전역 침범 0).
   *
   * 각 alias 도 `expr` 과 동일한 `{paramKey}` 플레이스홀더 문법을 따른다.
   */
  aliases?: string[];
  /** 작성자 자유 필드 */
  [key: string]: unknown;
}

export interface ConditionParamSpec {
  /** 파라미터 키 — `expr` 의 `{key}` 와 매칭 */
  key: string;
  /** 친화 라벨 — `$t:...` 키 또는 평문 */
  label?: string;
  /** 입력 위젯 — `datasource-picker`/`state-key-picker`/`text`/`select` 등 */
  widget?: string;
  /** select 위젯 등의 선택지 */
  options?: Array<{ value: unknown; label?: string }>;
  /** 작성자 자유 필드 */
  [key: string]: unknown;
}
