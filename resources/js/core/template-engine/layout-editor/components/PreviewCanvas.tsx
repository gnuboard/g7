/**
 * PreviewCanvas.tsx — 편집기 중앙 프리뷰 캔버스
 *
 * `useLayoutDocument` 가 로드한 레이아웃 JSON 을 `DynamicRenderer` 로 실제 렌더.
 * `ResponsiveProvider overrideWidth` 로 디바이스 미리보기 폭을 강제하고,
 * `useSampleData` 가 만든 `SampleDataProvider` 를 자체 `DataSourceManager`
 * 인스턴스에 주입해 fetch 없이 샘플 데이터로 렌더한다.
 *
 * Phase 2 범위:
 * - 라우트 선택 시 캔버스에 렌더 (clean preview, isEditMode=true 로 readonly)
 * - 디바이스 폭/scale 적용
 * - 샘플 데이터 모드 활성
 * - 데이터 결정 노드는 점선 + "샘플 데이터" 배지 (오버레이 표시는 Phase 3 에서 onComponentHover 와 통합 — Phase 2 는 시각 placeholder 만)
 *
 * Phase 3 에서 선택/hover/오버레이 콜백 + 잠금 표시 추가.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useMemo, useRef, useState } from 'react';
import DynamicRenderer, { type ComponentDefinition } from '../../DynamicRenderer';
import { ResponsiveProvider } from '../../ResponsiveContext';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine } from '../../TranslationEngine';
import { ActionDispatcher } from '../../ActionDispatcher';
import { DataSourceManager, type DataSource } from '../../DataSourceManager';
import { useLayoutEditor } from '../LayoutEditorContext';
import { useLayoutDocument } from '../hooks/useLayoutDocument';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { EditorCanvasOverlay } from './EditorCanvasOverlay';
import type { ComponentManifest } from './ComponentPalette';
import type { NestingSpec, ComponentPaletteSpec, EditorSpec } from '../spec/specTypes';
import { useDevicePreview } from '../hooks/useDevicePreview';
import { useSampleData } from '../hooks/useSampleData';
import { useEditorTemplateAssets } from '../hooks/useEditorTemplateAssets';
import { useTranslation } from '../../TranslationContext';
import { AccessErrorPanel } from './AccessErrorPanel';
import {
  installPreviewCanvasStore,
  type PreviewCanvasStoreHandle,
} from '../utils/previewCanvasStore';
import { deriveSampleRouteParams } from '../utils/sampleRouteParams';
import { EDITOR_TRANSLATIONS_REFRESHED_EVENT } from '../hooks/useInlineEdit';
import { buildSampleGlobalSeed } from '../sample-data/sampleGlobalChain';
import { extractDollarGlobals, stripDollarGlobals } from '../sample-data/dollarGlobals';
import { findNodeByPath, type EditorNode } from '../utils/layoutTreeUtils';
import { limitIterationSourceToOne } from '../utils/iterationSampleLimit';
import { buildBaseEditorComponents } from '../utils/baseEditorSlotMarkers';
import type { SampleGlobalSource } from '../spec/editorSpecLoader';
import {
  applyInitialPatch,
  resolveSampleOverride,
  getFormErrors,
} from '../state/pageStateSimulator';
import { trackPageState } from '../devtools/editorTrackers';
import type { EditorStateItemSpec } from '../spec/specTypes';

export interface PreviewCanvasProps {
  /** components.json 매니페스트 — Chrome 에서 fetch 해 주입 */
  manifest?: ComponentManifest | null;
  /** editor-spec.nesting — Chrome 에서 fetch 해 주입 */
  nesting?: NestingSpec | null | undefined;
  /** editor-spec.componentPalette — Chrome 에서 fetch 해 주입  */
  componentPalette?: ComponentPaletteSpec | null;
  /**
   * 병합 editor-spec 전체 — 속성 편집 모달이 componentCapabilities/controls
   * 를 조회한다. Chrome 이 loadEditorSpecBundle 결과를 주입. 미제공 시 속성 편집 비활성.
   */
  spec?: EditorSpec | null;
  /** 권한 키 후보 (속성 모달 고급 탭 permissions TagInput — a-2) */
  permissionCandidates?: Array<{ value: string; label: string }>;
  /**
   * sampleGlobal deep merge 소스 (모듈 → 플러그인 → 템플릿 순) — Chrome 이
   * loadEditorSpecBundle 결과로 주입. 코어 시드와 합성해 격리 store baseline 을
   * 만든다. 미제공 시 코어 시드만 사용(디그레이드).
   */
  sampleGlobalSources?: SampleGlobalSource[];
  /** 라우트 매칭 함수 — useRouteTree 기반 */
  resolveRouteMatch?: (path: string) => 'route_in_tree' | 'route_not_in_tree';
  /** 링크 어포던스 클릭 시 호출 — Chrome 이 SELECT_ROUTE + dirty 가드 처리 */
  onNavigateToDestination?: (destinationPath: string) => void;
  /** 세션 누적 path 변경 — Chrome 의 save 가드에 입력으로 사용 */
  onSessionAddedPathsChange?: (paths: string[]) => void;
}

/**
 * 편집 대상 사전 fallback 이 전역 `G7Core.t` 에 설치 완료됐음을 알리는 이벤트.
 * 편집기 chrome 의 전역 t 의존 위젯(PageStateSwitcher 의 `$t:` 라벨)이 구독해
 * 첫 렌더(swap 전) 의 raw 키 표시를 재해석한다.
 *
 * @since engine-v1.50.0
 */
export const EDITOR_T_READY_EVENT = 'g7le:editor-t-ready';

/** VH_EXPAND 마킹 attribute — 펼친 풀스크린 요소 식별. */
const VH_EXPAND_ATTR = 'data-g7le-vh-expanded';

/**
 * 편집 캔버스의 풀스크린(`100vh`) 골격을 자연 높이로 펼친다.
 *
 * admin 류 풀스크린 레이아웃의 루트(`height:100vh` + `overflow-y-auto/hidden`, 예 Tailwind
 * `h-screen overflow-hidden`)는 편집기 캔버스에서 콘텐츠를 뷰포트 높이에 가둬 이중 스크롤/
 * 잘림을 만든다. computed height 가 뷰포트에 (거의) 고정 + 콘텐츠를 가두는(overflow≠visible)
 * 요소만 `height:auto`/`overflow:visible` 로 무력화한다(클래스 비종속 — CSS 라이브러리 토큰
 * 미사용). 저장 데이터/사용자 페이지 렌더에는 영향 없다(편집 캔버스 DOM 표면일 뿐).
 *
 * `min-height:100vh` 만이고 콘텐츠를 가두지 않는 센터링 컨테이너(`min-h-screen flex
 * items-center`, overflow:visible)는 **건드리지 않는다** — 그 위아래 여백이 유저 페이지의
 * 정상 모습이라 0 으로 죽이면 위지윅↔유저 동일성이 깨진다.
 *
 * **idempotent** — 이미 펼친 요소를 다시 호출해도 안전하다(재렌더로 인라인 스타일이 소실된
 * 뒤 MutationObserver 가 재호출해 자동 복구). 본 함수는
 * 순수 DOM 조작이라 단위 테스트 가능(`PreviewCanvas.vhExpand.test.ts`).
 *
 * @param frame 편집 캔버스 frame DOM
 * @param viewportHeight 기준 뷰포트 높이(px). 0/누락 시 no-op
 */
export function expandVhLockedElements(frame: HTMLElement, viewportHeight: number): void {
  if (!frame || !viewportHeight) return;
  const vh = viewportHeight;
  const all = frame.querySelectorAll<HTMLElement>('*');
  all.forEach((el) => {
    const cs = window.getComputedStyle(el);
    const hPx = parseFloat(cs.height);
    const lockedToVh = Number.isFinite(hPx) && Math.abs(hPx - vh) <= 2;
    const minHPx = parseFloat(cs.minHeight);
    const minLockedToVh = Number.isFinite(minHPx) && Math.abs(minHPx - vh) <= 2;
    const clipsContent =
      cs.overflowY === 'auto' || cs.overflowY === 'scroll' || cs.overflowY === 'hidden' ||
      cs.overflow === 'auto' || cs.overflow === 'scroll' || cs.overflow === 'hidden';
    // `height:100vh` + 콘텐츠를 가두는 요소(풀스크린 골격)만 펼친다. 펼침 + min-height:100vh
    // 동반분도 함께 무력화. min-height-only 센터링 컨테이너는 보존(위지윅↔유저 동일성).
    if (lockedToVh && clipsContent) {
      el.style.setProperty('height', 'auto', 'important');
      if (minLockedToVh) el.style.setProperty('min-height', '0', 'important');
      el.style.setProperty('overflow', 'visible', 'important');
      el.setAttribute(VH_EXPAND_ATTR, '1');
    } else if (
      // 내부 메인 콘텐츠 스크롤러(자체 스크롤 중)도 펼친다 — 위 vh 컨테이너가 풀려도 그 자식
      // 스크롤러가 자체 overflow 로 갇혀 있으면 캔버스 안 스크롤이 남는다. 자식이 부모보다
      // 길어 스크롤 중인 경우만(jsdom 은 scrollHeight 0 이라 단위 테스트는 위 분기로 커버).
      (cs.overflowY === 'auto' || cs.overflowY === 'scroll') &&
      el.scrollHeight > el.clientHeight + 4 &&
      el.clientHeight > 120
    ) {
      el.style.setProperty('overflow', 'visible', 'important');
      el.style.setProperty('height', 'auto', 'important');
      el.setAttribute(VH_EXPAND_ATTR, '1');
    }
  });
}

/**
 * 안정 빈 배열 — sampleGlobalSources 미전달 시 기본값. 매 렌더 새 `[]` 를 만들면
 * globalSeed useMemo 의 dep 식별자가 매번 바뀌어 store 재설치 effect 가 무한 재발화
 * (캔버스 무한 re-render) 하므로 모듈 레벨 상수로 식별자를 고정한다.
 */
const EMPTY_SAMPLE_GLOBAL_SOURCES: SampleGlobalSource[] = [];

export function PreviewCanvas(props: PreviewCanvasProps = {}): React.ReactElement {
  const {
    manifest = null,
    nesting = null,
    componentPalette = null,
    spec = null,
    permissionCandidates,
    sampleGlobalSources = EMPTY_SAMPLE_GLOBAL_SOURCES,
    resolveRouteMatch,
    onNavigateToDestination,
    onSessionAddedPathsChange,
  } = props;
  const { state } = useLayoutEditor();

  // Provider 가 있으면 그 인스턴스, 없으면 자체 hook 호출(레거시 호환 — Chrome 미연결 시).
  const docCtxValue = useLayoutDocumentContext();
  const standalone = useLayoutDocument();
  const { document, isLoading, error } = docCtxValue ?? standalone;

  const frameRef = useRef<HTMLDivElement | null>(null);
  const [frameEl, setFrameEl] = useState<HTMLDivElement | null>(null);
  const { deviceWidth, scale, colorScheme } = useDevicePreview();
  // 병합 editor-spec 의 sampleData(byDataSourceId / bySource / byEndpointPattern)를
  // 샘플 프로바이더에 연결한다. Chrome 이 loadEditorSpecBundle 결과를 `spec` prop 으로
  // 주입하므로 이를 그대로 전달 — 미전달(standalone/테스트)이면 undefined 로 폴백(코어
  // 프리셋·generic 으로 디그레이드). 과거 Phase 2 골격은 `editorSpec: undefined` 로
  // 하드코딩되어 Phase 4 에서 도입한 번들 sampleData 가 캔버스 렌더에 전혀 반영되지
  // 않는 회귀를 유발했다.
  // 활성 페이지 상태 item — availableStates 에서 activeStateId 로 도출.
  // 미선언/미매칭이면 null → 시뮬레이터 어댑터가 모두 no-op(디그레이드).
  const activeStateItem: EditorStateItemSpec | null = useMemo(() => {
    if (!state.activeStateId) return null;
    return state.availableStates.find((s) => s.id === state.activeStateId) ?? null;
  }, [state.activeStateId, state.availableStates]);

  // sampleData 오버라이드 어댑터 — 활성 상태의 sampleDataOverrides 를
  // sampleProvider 우선순위 0/1/2 단계로 끼워 통째 교체. useSampleData 가 sampleOverride
  // 식별자 변경 시 provider 를 재생성 → DataSourceManager 재생성 → 캔버스 재fetch.
  const sampleOverride = useMemo(
    () => resolveSampleOverride(activeStateItem),
    [activeStateItem],
  );
  const sampleProvider = useSampleData({
    isEditMode: true,
    editorSpec: spec ?? undefined,
    sampleOverride,
  });
  const { t } = useTranslation();

  // 편집 대상 템플릿 자산 (IIFE 번들 + lang) 격리 로드
  // — 호스트 페이지(admin)의 ComponentRegistry 싱글톤과 충돌 회피
  const {
    componentRegistry,
    translationEngine,
    isReady: assetsReady,
    error: assetsError,
  } = useEditorTemplateAssets(state.templateIdentifier, state.locale);

  // 편집기 캔버스 전용 코어 엔진 인스턴스 — 일반 사이트 렌더와 격리
  const bindingEngineRef = useRef<DataBindingEngine | null>(null);
  if (bindingEngineRef.current === null) {
    bindingEngineRef.current = new DataBindingEngine();
  }
  const actionDispatcherRef = useRef<ActionDispatcher | null>(null);
  if (actionDispatcherRef.current === null) {
    // 편집 모드에서는 액션이 실제 발동되지 않도록 no-op navigate 주입
    actionDispatcherRef.current = new ActionDispatcher({ navigate: () => {} });
    // 프리뷰 모드 활성화 — PREVIEW_SUPPRESSED_HANDLERS 차단 + 미등록 커스텀
    // 핸들러를 throw 대신 silent skip 으로 처리. (Phase 2 결함 카테고리 A:
    // ckeditor5 등 외부 플러그인 핸들러가 격리 dispatcher 에 미등록 시
    // lifecycle onMount → setState(500) → 캔버스 unmount 방지)
    actionDispatcherRef.current.setPreviewMode(true);

    // 활성 플러그인/모듈의 커스텀 핸들러를 호스트 dispatcher 에서 복제 등록 —
    // ckeditor5 같은 위지윅 에디터 컴포넌트가 캔버스에 정상 마운트되어 사용자가
    // 플러그인 활성 상태의 실제 화면을 그대로 미리볼 수 있게 한다. 호스트 dispatcher
    // 함수를 그대로 호출하지만 ActionDispatcher 인스턴스는 격리이므로 상태 변경은
    // 격리 store 가 받는다(installPreviewCanvasStore + globalStateUpdater 연결).
    // 호스트 dispatcher 접근: `window.__templateApp` 이 격리 façade 로 swap 되기
    // 전 — 즉 PreviewCanvas 마운트 직후 첫 ref 초기화 시점에는 아직 호스트 인스턴스.
    // 따라서 본 if 분기 안에서만 시도 (이후 swap 후에는 호스트 핸들러 접근 불가).
    // @since engine-v1.50.0
    try {
      const hostTemplateApp = (window as any).__templateApp;
      const hostDispatcher = hostTemplateApp?.getActionDispatcher?.();
      if (hostDispatcher && typeof hostDispatcher.getRegisteredHandlers === 'function') {
        const handlerNames: string[] = hostDispatcher.getRegisteredHandlers();
        for (const name of handlerNames) {
          // 모듈/플러그인 커스텀 핸들러만 복제 — built-in (setState/navigate/apiCall 등)은
          // 격리 dispatcher 가 자체 가지므로 덮어쓰면 안 됨. `<vendor>-<name>.<method>` 패턴
          // (점 포함) 만 복제.
          if (!name.includes('.')) continue;
          const fn = typeof hostDispatcher.getHandler === 'function'
            ? hostDispatcher.getHandler(name)
            : undefined;
          if (typeof fn === 'function') {
            actionDispatcherRef.current.registerHandler(name, fn, {
              category: 'plugin',
              source: 'preview-canvas-cloned-from-host',
            });
          }
        }
      }
    } catch {
      // 호스트 핸들러 복제 실패는 격리 dispatcher 의 silent skip 폴백으로 처리
    }
  }

  // 확장 핸들러 재복제 — IIFE 로드 완료 후. 위 ref 초기화 시점의 1회 복제는
  // PreviewCanvas 마운트 직후라, 편집 대상 템플릿의 확장 IIFE(useEditorTemplateAssets 의
  // 비동기 주입 — initEditor 등 lifecycle/커스텀 핸들러를 전역 dispatcher 에 등록)가 아직
  // 로드 전이다. 그 결과 격리 dispatcher 에 확장 핸들러가 누락돼 lifecycle.onMount(예:
  // ckeditor5.initEditor)가 "미등록 핸들러" 로 silent skip 되어 CKEditor 가 캔버스에 영구
  // 미생성된다. assetsReady(IIFE 주입 완료) 후 전역 dispatcher 의
  // 확장 핸들러(`vendor-name.method` — `.` 포함)를 격리 dispatcher 로 재복제해 누락을 메운다.
  // 일반 사이트 렌더와 무관(편집기 캔버스 전용).
  useEffect(() => {
    if (!assetsReady) return;
    const disp = actionDispatcherRef.current;
    if (!disp) return;
    try {
      const globalDisp = (window as any).G7Core?.getActionDispatcher?.();
      if (!globalDisp || typeof globalDisp.getRegisteredHandlers !== 'function') return;
      const names: string[] = globalDisp.getRegisteredHandlers();
      for (const name of names) {
        // 확장(모듈/플러그인) 커스텀 핸들러만 — built-in(점 없음)은 격리 dispatcher 자체 보유.
        if (!name.includes('.')) continue;
        // 이미 복제된 핸들러는 건너뜀(ref 초기화 시 복제분 + 중복 등록 방지). ActionDispatcher 에
        // hasHandler 는 없으므로 getHandler 결과 유무로 판정.
        if (typeof disp.getHandler === 'function' && disp.getHandler(name)) continue;
        const fn = typeof globalDisp.getHandler === 'function' ? globalDisp.getHandler(name) : undefined;
        if (typeof fn === 'function') {
          disp.registerHandler(name, fn, { category: 'plugin', source: 'preview-canvas-iife-reclone' });
        }
      }
    } catch {
      // 재복제 실패는 silent skip 폴백(미등록 핸들러는 preview 모드에서 무시되므로 크래시 없음).
    }
  }, [assetsReady]);

  // 샘플 모드 DataSourceManager 인스턴스 — 일반 렌더의 싱글톤 미사용
  // sampleProvider 가 변경되면 인스턴스도 재생성하여 옵션 일관성 보장.
  // actionDispatcher 옵션 주입 — onSuccess 핸들러가 호스트 ActionDispatcher 대신
  // 격리 dispatcher 를 사용하도록 강제 (라우트 10/12 글쓰기/
  // 글 수정 등 onSuccess 에 setState 가 있는 layout 의 호스트 #app unmount 결함 차단).
  const dataSourceManager = useMemo(() => {
    return new DataSourceManager({
      sampleProvider,
      actionDispatcher: actionDispatcherRef.current!,
    });
  }, [sampleProvider]);

  // 격리 store swap — 캔버스 내부 G7Core.state/dispatch/modal/toast 호출이
  // 호스트 #app 의 globalState 를 변경하지 않도록 `window.__templateApp` 및
  // `window.G7Core.dispatch` 등을 격리 façade 로 임시 교체. 언마운트 시 원복.
  // (Phase 2 결함 카테고리 H: 회원가입 체크박스 클릭 시 호스트 #app unmount 회피)
  // 격리 store 상태 — DynamicRenderer 의 `dataContext._global` 로 흘려보내야
  // `{{_global.mobileMenuOpen}}` 같은 표현식이 토글에 반응한다. 단순 forceRender
  // 카운터만 두면 dataContext._global 객체는 마운트 시점의 빈 값으로 고정되어
  // 햄버거 토글 → setState → 격리 store 변경은 일어나도 DynamicRenderer 의
  // 바인딩이 갱신되지 않는다.
  // 첫 렌더부터 베이스 레이아웃의 `_global.currentUser` / `_global.settings.site_name`
  // 등이 채워져 있도록 코어 시드로 초기화. installPreviewCanvasStore 가
  // initialGlobalState 로 같은 시드를 받아 격리 store 초기값도 동일하다.
  // `meta.guest_only: true` 레이아웃(로그인/회원가입/비밀번호 찾기 등)은 비로그인
  // 상태를 전제로 한다. 로그인된 currentUser 를 시드하면 `_redirect_if_logged_in`
  // 류의 가드 partial 이 onMount 에서 "이미 로그인되어 있습니다" 토스트 + 홈
  // 리다이렉트를 발화해 편집기 진입 자체가 오염된다. guest_only 면
  // currentUser 를 제외한 시드를 사용해 비로그인 분기로 평가되게 한다.
  const isGuestOnlyLayout = useMemo(
    () => (document?.raw?.meta as { guest_only?: unknown } | undefined)?.guest_only === true,
    [document],
  );
  // sampleGlobal deep merge 체인: 코어 시드 → 활성 모듈/플러그인 →
  // 템플릿 순으로 deep merge. 코어 keyspace leaf 충돌 시 코어 우선 + dev 경고
  // guest_only 면 코어 keyspace `currentUser` 시드 제외 (
  // 이전 임시 분기 `coreSampleGuestGlobalSeed` 를 체인 레벨로 흡수).
  const baselineSeed = useMemo(
    () =>
      buildSampleGlobalSeed({
        sources: sampleGlobalSources,
        isGuestOnly: isGuestOnlyLayout,
      }),
    [sampleGlobalSources, isGuestOnlyLayout],
  );

  // 페이지 상태 패치 합성 ((3)) — baseline 위에 활성 상태의
  // initialState(local/global) + formErrors(경로 주입)를 얹는다. applyInitialPatch 가
  // 불변 baseline 으로부터 매번 재계산하므로 상태 전환 시 이전 패치가 자동 되돌려진다.
  // formErrors 메시지의 `$t:` 키는 편집 대상 사전(translationEngine)으로 미리 해석해
  // 일반 상태 값으로 주입한다(레이아웃 표현식 `{{_local.errors?.email?.[0]}}` 가 그대로
  // 읽도록 — 사용자 페이지 parity). isolatedGlobal 은 `{ ...global, _local }` 형태로
  // 흐르므로 local 패치는 `_local` 키 아래에 병합한다.
  const globalSeed = useMemo(() => {
    const formErrors = resolveFormErrorMessages(getFormErrors(activeStateItem), translationEngine, {
      templateId: state.templateIdentifier,
      locale: state.locale,
    });
    const baselineLocal = (baselineSeed as { _local?: Record<string, unknown> })._local;
    const { global, local } = applyInitialPatch({
      globalBaseline: baselineSeed,
      localBaseline: (baselineLocal as Record<string, unknown>) ?? {},
      patch: activeStateItem?.initialState ?? null,
      formErrors,
      isEditMode: true,
    });
    return { ...global, _local: local };
  }, [baselineSeed, activeStateItem, translationEngine, state.templateIdentifier, state.locale]);

  // 활성 페이지 상태의 `_local` 초기화 패치 ((3)) — DynamicRenderer 의
  // `_localInit`(force replace)으로 주입해 레이아웃 자체 init_actions(`setState target:local`)
  // 가 깔아 두는 기본값을 이긴다. 예: profile-edit 의 init_actions 가
  // `isPasswordVerified:false` 를 강제하므로, actual_edit 상태(true)가 화면 분기에 반영되려면
  // store 시드(마운트 전)만으로는 부족하고 마운트 시 _localInit 으로 권위 주입해야 한다.
  // 활성 상태가 없으면(기본/미선언) 전달하지 않아 기존 동작(init_actions 기반)을 보존한다.
  const stateLocalInit = useMemo<Record<string, unknown> | null>(() => {
    if (!activeStateItem) return null;
    const hasLocalPatch = !!activeStateItem.initialState?.local || !!getFormErrors(activeStateItem);
    if (!hasLocalPatch) return null;
    const local = (globalSeed as { _local?: Record<string, unknown> })._local ?? {};
    // _forceLocalInit: 동일 syncKey 재설정 가드를 우회해 상태 전환마다 재적용.
    // _merge: 'deep' — baseline _local 위에 패치 키만 덮고 나머지(loadingActions 등)는 보존.
    return { ...local, _forceLocalInit: activeStateItem.id, _merge: 'deep' };
  }, [activeStateItem, globalSeed]);

  // 활성 페이지 상태의 query/route 패치 (전수 조사로 발굴한 미커버 영역,
  // ). 편집기는 평소 query 가 비어 있어 `{{query.error}}`/`{{query.tab}}`
  // 진입 맥락 변종을 미리볼 수 없고, route 는 sampleRouteParams 로 항상 채워져 신규 작성
  // 모드(`{{!route.id}}`)를 볼 수 없다. 활성 상태가 query/route 패치를 가지면 dataContext 의
  // query/route 에 머지(route 의 null 값 키는 토큰 제거)한다. baseline 은 dataContext 구성
  // 시점에 합치므로 여기선 패치 분량만 보관(없으면 null → 기존 동작 보존).
  const isPatchObj = (v: unknown): v is Record<string, unknown> =>
    typeof v === 'object' && v !== null && !Array.isArray(v);
  const stateQueryPatch = useMemo<Record<string, unknown> | null>(
    () => (isPatchObj(activeStateItem?.initialState?.query) ? activeStateItem!.initialState!.query! : null),
    [activeStateItem],
  );
  const stateRoutePatch = useMemo<Record<string, unknown> | null>(
    () => (isPatchObj(activeStateItem?.initialState?.route) ? activeStateItem!.initialState!.route! : null),
    [activeStateItem],
  );

  const [isolatedGlobal, setIsolatedGlobal] = useState<Record<string, unknown>>(() =>
    buildSampleGlobalSeed({
      sources: [],
      isGuestOnly: false,
    }),
  );
  const storeHandleRef = useRef<PreviewCanvasStoreHandle | null>(null);
  useEffect(() => {
    // guest_only 레이아웃은 currentUser 없는 시드를 사용해 비로그인 분기로 평가.
    // document 로드/라우트 전환으로 guest_only 판정이 바뀌면 globalSeed 변경 →
    // store 재설치로 currentUser 가 시드에서 제외/복원된다.
    const seed = { ...globalSeed };
    const handle = installPreviewCanvasStore({
      actionDispatcher: actionDispatcherRef.current!,
      locale: state.locale,
      // 격리 store 초기 _global — 베이스 레이아웃의 `current_user` / `app_settings`
      // 등 데이터소스가 sampleProvider 로 우회되어 비어 있는 _global 을 코어 시드로
      // 채운다. 헤더/푸터/Welcome 영역이 폴백("Site") 로 노출되는 결함 차단.
      initialGlobalState: seed,
      onChange: (snapshot) => {
        // 격리 store 상태 변경 → dataContext._global 에 새 참조로 흘려보내
        // 표현식/responsive className 등이 재평가되도록 한다.
        setIsolatedGlobal(snapshot.globalState);
      },
    });
    storeHandleRef.current = handle;

    // 격리 dispatcher 의 globalStateUpdater 를 격리 store 의 setGlobalState 로 연결
    // — `handleSetState` 가 componentContext 없이 호출될 때 (init_actions / onSuccess /
    //   lifecycle 등) 호스트 setGlobalState 대신 격리 store 가 받도록 보장.
    const isolatedTemplateApp = (window as any).__templateApp;
    if (isolatedTemplateApp?.setGlobalState) {
      actionDispatcherRef.current!.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => {
        isolatedTemplateApp.setGlobalState(updates, opts);
      });
    }

    // 재설치 시 격리 store 의 새 초기 시드를 dataContext._global 에 즉시 반영해
    // 첫 렌더와 onChange 사이 한 프레임의 stale currentUser 잔존을 방지.
    setIsolatedGlobal(seed);

    // devtools — 페이지 상태 패치 적용 트래킹. 활성 상태 id + scope +
    // 패치/오버라이드/폼오류 유무를 적재해 "어떤 상태에서 어떤 결과를 봤는지" 디버깅.
    trackPageState({
      kind: 'applyPatch',
      activeStateId: state.activeStateId,
      routePath: state.selectedRoute?.path ?? null,
      hasInitialPatch: !!activeStateItem?.initialState,
      hasFormErrors: !!getFormErrors(activeStateItem),
      hasSampleOverride: !!resolveSampleOverride(activeStateItem),
    });

    return () => {
      handle.restore();
      storeHandleRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.locale, globalSeed]);

  // `window.G7Core.t()` 를 편집 대상 → 호스트 사전 fallback 체인으로 임시 교체.
  // 편집 대상 템플릿 컴포넌트(Header/Footer 등) 가 React Context 가 아닌
  // 전역 `G7Core.t()` 로 i18n 키를 해석하기 때문에, 편집 대상 사전(격리
  // TranslationEngine 인스턴스)을 **우선** 시도해야 한다.
  // 우선순위 = 편집 대상 우선:
  // 캔버스는 편집 대상 템플릿(예: sirsoft-basic)을 그리고, editorAwareT 도 이 swap 을
  // 거치므로 편집 대상 화면의 `$t:` 는 편집 대상 라벨로 풀려야 한다. 종전 "호스트 우선"
  // 은 admin·basic 양쪽에 같은 키가 다른 값으로 있을 때(예: editor.computed.filter_default
  // = admin "필터 기본값 정하기" vs basic "기본값 채우기") basic 화면에서도 admin 라벨이
  // 떠 와 어긋났다. 편집 대상에 없는 코어 키(`layout_editor.*`/`common.*` 등)는
  // 호스트로 폴백하므로 chrome·코어 키 해석엔 영향 0.
  // PreviewCanvas 언마운트 시 원본 `G7Core.t` 를 복원해 admin 화면의 t() 호출에
  // 사이드이펙트 0.
  useEffect(() => {
    if (!translationEngine) return;
    const G7Core = (window as any).G7Core;
    if (!G7Core || typeof G7Core.t !== 'function') return;

    const originalT = G7Core.t;
    const editorTemplateId = state.templateIdentifier;
    const editorLocale = state.locale;

    G7Core.t = (key: string, params?: Record<string, string | number>): string => {
      // 1) 편집 대상(격리 엔진) 우선. 미해석 시 translate 는 키를 그대로 반환(TranslationEngine:374).
      try {
        const paramsStr = params
          ? '|' + Object.entries(params).map(([k, v]) => `${k}=${v}`).join('|')
          : undefined;
        const editorResult = translationEngine.translate(
          key,
          { templateId: editorTemplateId, locale: editorLocale },
          paramsStr,
        );
        // 풀린 경우만 채택 — 미해석(키 그대로/`$t:` 잔존)이면 호스트로 폴백.
        // 빈 문자열은 의도된 번역으로 간주해 채택(fallback 미적용).
        if (editorResult !== key && !editorResult.startsWith('$t:')) {
          return editorResult;
        }
      } catch {
        // 편집 대상 해석 실패 → 호스트 폴백
      }
      // 2) 호스트(admin) 사전 폴백 — 편집 대상에 없는 코어/chrome 키(`layout_editor.*` 등).
      return originalT(key, params);
    };

    // G7Core.t 가 편집 대상 사전 fallback 으로 교체됨을 알린다 — 편집기 chrome 의
    // 전역 t 의존 위젯(PageStateSwitcher 의 `$t:` 상태 라벨 등)이 첫 렌더 시점에
    // swap 전 admin t 로 해석해 raw 키가 표시되던 타이밍 결함을 해소하기 위해, swap
    // 직후 이벤트를 발화해 그 위젯들이 재렌더하도록 한다(라벨 재해석).
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent(EDITOR_T_READY_EVENT));
    }

    return () => {
      // 다른 코드가 같은 시간에 t 를 다시 교체하지 않았다면 복원
      if ((window as any).G7Core?.t && (window as any).G7Core.t !== originalT) {
        (window as any).G7Core.t = originalT;
      }
    };
  }, [translationEngine, state.templateIdentifier, state.locale]);

  // 렌더링용 컴포넌트 배열 추출. (라이브 드래그 프리뷰는 폐기. 드롭 위치는
  // DndCanvasLayer 의 슬롯 인디케이터가 표시하며, 캔버스 트리는 commit 시에만 변형.)
  //
  // 공통(base) 편집 모드 한정 슬롯 표시 변환. 슬롯 노드(`slot: "X"`)는 SlotContext 활성 시
  // 원위치에서 `return null`(SlotContainer 이관)이라 base 단독 편집 캔버스에서 통째로 사라진다.
  // 처리 방식을 슬롯 종류별로 나눈다 (어떤 템플릿/모듈이 어떤 슬롯에 주입하든 동일 적용 — 코어 범용):
  //
  //  1) **소비처(SlotContainer)가 같은 base 레이아웃 안에 있는 슬롯**: 치환하지 않고 `slot` 키를
  //     그대로 둔다 → 슬롯 메커니즘이 정상 동작해 SlotContainer 가 실제 위치(예: 헤더 안)에서
  //     떙겨 렌더한다. 주입 앵커(`slot` 노드의 원래 위치)는 보통 헤더 밖 최상단 등 SlotContainer 와
  //     다른 자리라, 치환해 원위치에 표시하면 운영 화면과 어긋나 보인다(헤더 위 별도 박스 등).
  //     소비처 위치에 마운트되어야 "헤더에 마운트된 형태"가 된다. 모듈이 주입한 셀렉터가 대표 예.
  //  2) **소비처가 base 레이아웃에 없는 슬롯**(예: 자식 라우트 레이아웃이 채우는 `content`):
  //     base 단독 편집 캔버스엔 떙겨 갈 SlotContainer 가 없어 사라진다 → 어디가 그 자리인지
  //     알 수 없고 선택도 불가. 이때만 `slot` 키를 표시 마커(`__editorSlotName`)로 치환해
  //     원위치에 일반 컨테이너로 렌더(DynamicRenderer 가 data-editor-slot 부여 → 점선 박스+라벨).
  //
  // 소비처 존재 판정 = base 레이아웃 컴포넌트 트리에 `SlotContainer`(props.slotId === 슬롯) 노드가
  // 있는가. 통짜 TSX 컴포넌트(예: 사용자 Header) 내부 SlotContainer 는 JSON 트리에 안 보이나,
  // 동일 슬롯을 쓰는 SlotContainer JSON 노드가 같은 레이아웃에 하나라도 있으면(데스크톱/모바일
  // 페어 등) 그 슬롯은 소비처 보유로 간주해 치환 스킵 → 통짜 컴포넌트 내부 SlotContainer 도 정상 수신.
  //
  // 표시용 사본 — document.raw/패치/저장 경로는 원본 그대로(운영 content 무오염). 노드
  // 구조(개수/순서)는 불변이라 data-editor-path ↔ raw 좌표 정합도 유지된다.
  // @since engine-v1.50.0 — 변환 로직은 buildBaseEditorComponents 로 추출(단위 테스트 잠금).
  const components: ComponentDefinition[] = useMemo(() => {
    const raw = (document?.raw?.components ?? []) as unknown[];
    if (state.editMode !== 'base') return raw as ComponentDefinition[];
    return buildBaseEditorComponents<ComponentDefinition>(raw);
  }, [document, state.editMode]);

  // 확장 편집 폴백 식별. useExtensionDocument → adaptExtensionToLayoutDocument 가
  // document.raw.__editability 로 1단계 판정('ok'|'no-injection')을 실어 온다. 일반 레이아웃
  // 문서엔 없는 키라 undefined → 폴백 비활성(영향 0).
  const extensionEditability = (document?.raw as any)?.__editability as
    | 'ok'
    | 'no-injection'
    | 'pending'
    | undefined;
  const extensionIdForRenderCheck = (document?.raw as any)?.__extensionId as number | undefined;

  // 렌더 검증 대상 — 현재 확장 조각이 주입된 노드들의 source path. editability
  // 가 'ok'(머지 트리에 주입 노드 존재)일 때, 렌더 후 이 path 들 중 하나라도 캔버스 DOM 에
  // `data-editor-path` 로 존재하는지 검사한다. 모두 부재면 게이트/모달 뒤라 미렌더된 것
  // ('gated-no-state' → 폴백). 셀렉터는 원문 path 사용([[feedback_editor_dom_selector_use_raw_path_not_parsed]]).
  const extensionSourcePaths = useMemo<string[]>(() => {
    if (state.editMode !== 'extension' || extensionEditability !== 'ok') return [];
    if (typeof extensionIdForRenderCheck !== 'number') return [];
    return collectExtensionSourcePaths(components as unknown as EditorNode[], extensionIdForRenderCheck);
  }, [state.editMode, extensionEditability, extensionIdForRenderCheck, components]);

  // 렌더 검증 결과. null=미검증/비대상, true=조각 시각 노출됨, false=게이트
  // 뒤라 미렌더(폴백). 확장 모드 + editability 'ok' + source path 존재 시에만 검사.
  const [extensionRenderVisible, setExtensionRenderVisible] = useState<boolean | null>(null);

  // layout.scripts 동적 로드 — 위지윅(ckeditor5) 등 외부 라이브러리 UMD/IIFE 가
  // 활성 플러그인의 lifecycle.onMount 핸들러가 호출하기 전에 window 에 있어야
  // 캔버스에 실제 에디터 DOM 이 그려진다. 호스트 페이지가 이미 로드한 동일
  // src 는 중복 주입 회피. 모든 스크립트 로드 완료 후에야 컴포넌트가 마운트
  // 되도록 `scriptsReady` 게이트.
  // @since engine-v1.50.0
  // scriptsReady — layout 변경 시 false 로 리셋되어 새 layout 의 scripts 가 모두
  // 로드되기 전 컴포넌트(=lifecycle.onMount)가 렌더되지 않도록 차단.
  // 핵심: useEffect 의 setState(true) 가 발화하기 전 컴포넌트가 렌더되면 ckeditor5
  // wrapper 의 lifecycle.onMount 가 `window.CKEDITOR` 부재 시점에 호출되어 init
  // 실패. document 변경 즉시 false 로 리셋해 React 가 게이트 placeholder 렌더로
  // 머무르도록 한다. 같은 src 가 호스트 페이지에 이미 로드된 경우 loaders 가 빈
  // 배열이라 즉시 true 로 전환되어 정상 라우트는 추가 지연 없음.
  const [scriptsReady, setScriptsReady] = useState(false);
  const layoutName = document?.layoutName ?? null;
  // scripts src 목록을 안정적 키로 — layoutName 만으로는 확장 편집 모드의 게이트 재평가가
  // 안 된다. 확장 모드는 layoutName 이 `extension:{id}` 로 고정인데 호스트가 나중에
  // 확정되며 raw.scripts 에 CKEditor CDN 이 추가된다. layoutName 만 dep 이면 scriptsReady 가
  // true 로 남아 스크립트 로드 완료 전 컴포넌트가 마운트되고 onMount(initEditor)가 CKEDITOR
  // 부재 시점에 호출돼 영구 실패(재마운트 없음)한다 — CKEditor 캔버스 미렌더 결함.
  // scripts 내용이 바뀌면 게이트를 다시 차단해 로드 완료 후 마운트되도록 한다.
  const scriptsKey = useMemo(() => {
    const scripts = (document?.raw?.scripts ?? []) as Array<{ src?: string }>;
    if (!Array.isArray(scripts)) return '';
    return scripts.map((s) => (s && typeof s.src === 'string' ? s.src : '')).join('|');
  }, [document]);
  useEffect(() => {
    // 라우트(=layoutName) 또는 scripts 목록 변경 시 새 scripts 평가 전까지 게이트 차단
    setScriptsReady(false);
  }, [layoutName, scriptsKey]);
  useEffect(() => {
    let cancelled = false;
    const scripts = (document?.raw?.scripts ?? []) as Array<{ src: string; id?: string }>;
    if (!Array.isArray(scripts) || scripts.length === 0) {
      setScriptsReady(true);
      return;
    }
    const loaders: Promise<void>[] = [];
    for (const entry of scripts) {
      if (!entry?.src || typeof entry.src !== 'string') continue;
      const sel = entry.id
        ? `script#${CSS.escape(entry.id)}, script[data-g7le-canvas-script="${entry.src}"]`
        : `script[data-g7le-canvas-script="${entry.src}"], script[src="${entry.src}"]`;
      const existing = window.document.querySelector(sel) as HTMLScriptElement | null;
      if (existing) {
        // 태그가 DOM 에 있어도 **로드 완료를 의미하지 않는다**. 본 effect 가
        // React 재실행으로 거의 동시에 두 번 돌면, 첫 실행이 append 한 태그를 둘째 실행이
        // 여기서 "existing" 으로 보고 즉시 ready 처리 → 스크립트 load 전(window.CKEDITOR
        // undefined)에 컴포넌트가 마운트되어 lifecycle.onMount(initEditor)가 실패한다.
        // 따라서 캔버스가 주입한 태그(`data-g7le-canvas-script`)는 **완료 플래그
        // (`data-g7le-script-loaded`)가 있을 때만** skip 하고, 미완료면 그 태그의 load 를
        // 기다린다. 호스트 페이지가 원래 로드한 태그(우리 마커 없음)는 종전대로 완료 간주.
        const isCanvasInjected = existing.hasAttribute('data-g7le-canvas-script');
        const isLoaded = existing.hasAttribute('data-g7le-script-loaded') || !isCanvasInjected;
        if (isLoaded) {
          continue;
        }
        // 캔버스 주입 태그이나 아직 미완료 — 그 태그의 load 를 기다린다.
        loaders.push(
          new Promise<void>((resolve) => {
            if (existing.hasAttribute('data-g7le-script-loaded')) {
              resolve();
              return;
            }
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => resolve());
          }),
        );
        continue;
      }
      loaders.push(
        new Promise<void>((resolve) => {
          const tag = window.document.createElement('script');
          tag.src = entry.src;
          tag.async = false;
          if (entry.id) tag.id = entry.id;
          tag.setAttribute('data-g7le-canvas-script', entry.src);
          const done = () => {
            // 완료 플래그를 달아 이후 effect 재실행 시 "로드됨"을 정확히 식별(경합 방지).
            tag.setAttribute('data-g7le-script-loaded', '1');
            resolve();
          };
          tag.addEventListener('load', done);
          tag.addEventListener('error', done); // 실패해도 다음 진행
          window.document.head.appendChild(tag);
        }),
      );
    }
    if (loaders.length === 0) {
      setScriptsReady(true);
      return;
    }
    Promise.all(loaders).then(() => {
      // UMD 스크립트의 module 평가가 끝나 window 전역 등록까지 완료되도록
      // 더블 RAF 후 게이트 해제 — load 이벤트는 fire 되지만 그 직후 microtask
      // 에서 `window.CKEDITOR` 같은 등록이 일어나는 라이브러리가 있다.
      if (cancelled) return;
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          if (!cancelled) setScriptsReady(true);
        });
      });
    });
    return () => {
      cancelled = true;
    };
  }, [document]);

  // 데이터소스 로드 — 편집 모드는 sampleProvider 가 fetch 를 대체.
  // 본 Phase 의 단순 렌더는 모든 데이터소스에 대해 sampleProvider 로 한 번씩
  // 해석한 결과를 dataContext.{id} 로 주입 (data_sources 정의가 없으면 빈 컨텍스트).
  const dataSourcesDef = useMemo(() => {
    const raw = (document?.raw?.data_sources ?? []) as unknown[];
    return raw as DataSource[];
  }, [document]);

  // 레이아웃의 `computed` 블록 — 런타임 앱(TemplateApp)은 이를 dataContext._computedDefinitions
  // 로 주입해 DynamicRenderer 가 `_local` 변경마다 `_computed` 를 재계산한다. 편집기 미리보기도
  // 동일하게 주입하지 않으면 `_computed.*` 가 항상 undefined → `if: {{_computed.xxx}}` 로 게이트된
  // 본체(배송정책 국가별 설정, 상품폼 조건부 섹션 등)가 캔버스에서 통째로 비어 렌더된다.
  const computedDefinitions = useMemo(() => {
    return (document?.raw?.computed ?? {}) as Record<string, unknown>;
  }, [document]);
  const [dataContext, setDataContext] = useState<Record<string, any>>({});

  // 반복 항목 편집(iteration_item) 샘플 데이터 1개 제한. 이 모드는 iteration 1개 항목
  // 템플릿을 편집하므로 캔버스에 항목이 하나만 보여야 한다. 편집 대상 iteration 원본
  // 노드의 `iteration.source` 표현식이 가리키는 데이터 배열을 1개로 잘라 dataContext 를 가공한다.
  // 그 외 모드는 dataContext 원본 그대로.
  const dataContextForRender = useMemo<Record<string, any>>(() => {
    if (state.editMode !== 'iteration_item') return dataContext;
    const ctx = document?.iterationContext;
    if (!ctx) return dataContext;
    const hostComponents = (document?.raw?.components ?? []) as EditorNode[];
    const sourceNode = findNodeByPath(
      { children: hostComponents } as EditorNode,
      ctx.sourceIndexPath,
    );
    const sourceExpr =
      sourceNode && (sourceNode as any).iteration && typeof (sourceNode as any).iteration.source === 'string'
        ? ((sourceNode as any).iteration.source as string)
        : null;
    if (!sourceExpr) return dataContext;
    return limitIterationSourceToOne(dataContext, sourceExpr);
  }, [state.editMode, dataContext, document]);

  // 인라인 편집 키 CRUD 후 캔버스 재렌더 신호.
  // useInlineEdit.bustTranslationCache 가 서버 lang 재fetch 완료 후 발화하는 이벤트를
  // 구독해 tick 을 증가 → DynamicRenderer 재렌더 → `$t:custom.*` 가 raw 키가 아닌
  // 새 값으로 해석된다(싱글톤 TranslationEngine 사전이 그 시점엔 이미 최신).
  const [translationsTick, setTranslationsTick] = useState(0);
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const onRefreshed = (): void => setTranslationsTick((n) => n + 1);
    window.addEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, onRefreshed);
    return () => window.removeEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, onRefreshed);
  }, []);

  // 라우트 path 의 `:param` 토큰에 샘플 값 자동 주입 — 게시글/상품/페이지 등
  // path param 의존 라우트가 편집기 캔버스에서 본문 미렌더 되는 회귀 방지.
  // (Phase 2 결함 카테고리 C)
  const sampleRouteParams = useMemo(
    () => deriveSampleRouteParams(state.selectedRoute?.path ?? null),
    [state.selectedRoute?.path],
  );

  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (dataSourcesDef.length === 0) {
        if (!cancelled) {
          setDataContext({});
        }
        return;
      }
      try {
        const result = await dataSourceManager.fetchDataSources(
          dataSourcesDef,
          sampleRouteParams,
          new URLSearchParams(),
          undefined,
          undefined,
        );
        if (!cancelled) {
          setDataContext(result);
        }
      } catch {
        if (!cancelled) {
          setDataContext({});
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [dataSourcesDef, dataSourceManager, sampleRouteParams]);

  // 편집 모드 — 뷰포트 높이(`100vh`/`100dvh`) 고정 + overflow 제약 요소 펼침.
  // admin 류 풀스크린 레이아웃은 루트가 `height: 100vh`(예: Tailwind `h-screen`) +
  // 메인 콘텐츠 `overflow-y-auto` 로 설계된다 — 실제 페이지는 "사이드바 고정 + 콘텐츠만
  // 스크롤". 그러나 편집기 캔버스에서는 그 `100vh` 가 frame 이 아니라 브라우저 뷰포트를
  // 참조해 콘텐츠가 뷰포트 높이에 갇히고 캔버스 안에서 또 스크롤된다("편집 시
  // 별도 스크롤 없이 전체를 한 번에 보고 싶다 / 주입되는 화면 높이를 크게 잡으면 될 일").
  // CSS 만으로는 `100vh` 요소를 일반 선택할 수 없으므로(클래스 비종속 원칙 — CSS
  // 라이브러리 토큰에 의존하지 않는다), computed height 가 뷰포트에 고정된 요소를 JS 로
  // 식별해 편집 모드에서만 자연 높이로 펼친다(인라인 오버라이드 + `data-g7le-vh-expanded`
  // 마킹). 저장 데이터/사용자 페이지 렌더에는 영향이 없다 — 편집 캔버스 DOM 표면일 뿐.
  useEffect(() => {
    const frame = frameEl;
    if (!frame || typeof window === 'undefined') return;
    let raf1 = 0, raf2 = 0, moRaf = 0;
    const runExpand = (): void => expandVhLockedElements(frame, window.innerHeight);
    // React 가 DOM 을 커밋하고 레이아웃이 확정된 다음 측정·펼침(더블 RAF).
    raf1 = window.requestAnimationFrame(() => {
      raf2 = window.requestAnimationFrame(runExpand);
    });
    // frame 내부 DOM 변경 시 재펼침. 인라인 편집
    // 확정/취소는 `bustTranslationCache` 로 캔버스를 재렌더해 `h-screen overflow-hidden`
    // 루트를 재생성하는데, 그러면 VH_EXPAND 인라인 스타일이 소실된다. 그런데 본 effect 의
    // dep(components/scriptsReady/deviceWidth/dataContext)는 인라인 편집 이탈로 변하지 않아
    // 재실행되지 않았고, 100vh 클립이 복귀해 캔버스가 수축·잘렸다(stale 핸들 수정과 동형 —
    // DOM 재렌더 시 재계산 트리거 누락). MutationObserver(childList/subtree, rAF debounce)로
    // DOM 변경 시 재펼침해 자동 복구한다. expandVhLockedElements 는 이미 펼친 요소도 안전하게
    // 재적용(idempotent)하므로 반복 호출 무해. 펼침이 일으키는 inline-style 변경은 attribute
    // mutation 이라(childList/subtree 아님) observer 를 재발화하지 않는다 → 무한 루프 없음.
    let mo: MutationObserver | null = null;
    if (typeof MutationObserver !== 'undefined') {
      mo = new MutationObserver(() => {
        if (moRaf) return;
        moRaf = window.requestAnimationFrame(() => {
          moRaf = 0;
          runExpand();
        });
      });
      mo.observe(frame, { childList: true, subtree: true });
    }
    return () => {
      window.cancelAnimationFrame(raf1);
      window.cancelAnimationFrame(raf2);
      if (moRaf) window.cancelAnimationFrame(moRaf);
      if (mo) mo.disconnect();
    };
    // components/layoutName/scriptsReady/deviceWidth 변경 시 재펼침 (새 콘텐츠/디바이스 폭 반영).
    // + MutationObserver 가 그 외 DOM 재렌더(인라인 편집 이탈 등)도 커버.
  }, [frameEl, components, scriptsReady, deviceWidth, dataContext]);

  // 확장 편집 렌더 검증. editability 'ok'(머지 트리에 주입 노드 존재)인데도
  // 렌더 후 캔버스 DOM 에 확장 조각 source path 가 하나도 없으면, 그 확장은 게이트/모달 뒤라
  // 미렌더된 것 → extensionRenderVisible=false 로 폴백 안내를 띄운다. 활성 상태(state) 토글로
  // 게이트를 열면 path 가 나타나 true 로 회복. 비대상(일반/route/base/modal, no-injection,
  // path 없음)은 검사하지 않고 null(폴백 비활성). isEditMode 캔버스 전용 — 호스트 영향 0.
  useEffect(() => {
    const frame = frameEl;
    if (
      !frame ||
      typeof window === 'undefined' ||
      state.editMode !== 'extension' ||
      extensionEditability !== 'ok' ||
      extensionSourcePaths.length === 0
    ) {
      setExtensionRenderVisible(null);
      return;
    }
    let raf1 = 0,
      raf2 = 0;
    // 한 source path 가 캔버스에 **시각 렌더**됐는지 — 다음 중 하나라도 가시(box w/h>0)면 노출:
    //  (1) 그 path 노드 자체.
    //  (2) 그 path 를 prefix 로 갖는 자손 노드(self-managed 컴포넌트(CKEditor 등)가 원본 노드
    //      안에 자체 DOM 을 그리는 경우 — source path 노드는 컨테이너로 남고 그 자손에 가시
    //      콘텐츠가 생긴다).
    // **조상(path prefix 상위) 으로의 확장은 하지 않는다** — 그 확장이 주입될 자리가 없는
    // 상태(새 글/글 수정 등 게이트 false)에서는 source path 노드가 트리에서 통째로 빠져 (1)(2)
    // 모두 매칭되지 않아야 한다. 조상 폴백을 넣으면 그 상태에서도 호스트 폼 컨테이너 같은
    // 무관한 조상이 가시라 폴백이 잘못 풀린다(렌더 검증 완화 오류). 단순 0-size/
    // hidden(CSS injector 등)은 가시 박스 요구로 제외.
    const isVisibleEl = (el: Element | null): boolean => {
      if (!el) return false;
      const r = (el as HTMLElement).getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    };
    const pathRendered = (p: string): boolean => {
      // (1) 정확 매칭
      const exact = frame.querySelector(`[data-editor-path="${p}"]`);
      if (isVisibleEl(exact)) return true;
      // (2) 자손(path prefix) 중 가시 — source path 노드가 컨테이너로 남고 콘텐츠가 자손인 경우.
      const descendants = frame.querySelectorAll(`[data-editor-path^="${p}.children."]`);
      for (const d of Array.from(descendants)) if (isVisibleEl(d)) return true;
      return false;
    };
    const check = (): void => {
      setExtensionRenderVisible(extensionSourcePaths.some(pathRendered));
    };
    // 펼침 effect 와 동일하게 더블 RAF 후 DOM 안정 시점에 검사. + CKEditor 등 비동기 onMount
    // 렌더는 RAF 2회 뒤 늦게 뜨므로 MutationObserver 로 frame DOM 변경 시 재검사(가시 회복).
    raf1 = window.requestAnimationFrame(() => {
      raf2 = window.requestAnimationFrame(check);
    });
    let mo: MutationObserver | null = null;
    let moRaf = 0;
    if (typeof MutationObserver !== 'undefined') {
      mo = new MutationObserver(() => {
        if (moRaf) return;
        moRaf = window.requestAnimationFrame(() => {
          moRaf = 0;
          check();
        });
      });
      mo.observe(frame, { childList: true, subtree: true });
    }
    return () => {
      window.cancelAnimationFrame(raf1);
      window.cancelAnimationFrame(raf2);
      if (moRaf) window.cancelAnimationFrame(moRaf);
      if (mo) mo.disconnect();
    };
  }, [frameEl, state.editMode, extensionEditability, extensionSourcePaths, scriptsReady, dataContext]);

  // 슬롯 영역 가시화 — 공통(base) 레이아웃 편집 모드에서 자식 콘텐츠가 삽입될
  // 슬롯 위치(`slot: "content"` 등, DynamicRenderer 가 `data-editor-slot` 으로 마킹)를 점선
  // 박스 + 라벨로 표시한다. base 단독 렌더에서 슬롯 컨테이너는 children 이 비어 0 높이로
  // 찌부러져 위치를 알 수 없으므로 최소 높이도 함께 부여한다. 편집기 전용 <style> 주입 —
  // 저장 content·운영 렌더 영향 0. g7le-* 네임스페이스 + 표준 CSS 만 사용(라이브러리 비종속).
  useEffect(() => {
    // 주의: 이 컴포넌트 스코프의 `document` 는 레이아웃 문서 변수(섀도잉)다 — DOM 접근은
    // 반드시 window.document 로 한다(로딩 중 layout document 가 null 이라 크래시).
    if (typeof window === 'undefined' || state.editMode !== 'base') return;
    const dom = window.document;
    const STYLE_ID = 'g7le-base-slot-style';
    if (dom.getElementById(STYLE_ID)) return;
    const el = dom.createElement('style');
    el.id = STYLE_ID;
    const label = t('layout_editor.preview.slot_area_label');
    el.textContent =
      '.g7le-preview-frame [data-editor-slot]{position:relative;min-height:96px;outline:2px dashed #94a3b8;outline-offset:-2px;}' +
      `.g7le-preview-frame [data-editor-slot]::after{content:'${label.replace(/'/g, "\\'")} · ' attr(data-editor-slot);` +
      'position:absolute;top:8px;left:50%;transform:translateX(-50%);font-size:12px;line-height:1;color:#64748b;' +
      'background:rgba(241,245,249,0.92);border:1px solid #cbd5e1;border-radius:9999px;padding:4px 12px;pointer-events:none;white-space:nowrap;}';
    dom.head.appendChild(el);
    return () => {
      dom.getElementById(STYLE_ID)?.remove();
    };
  }, [state.editMode, t]);

  // 로딩/에러/빈 라우트 상태 분기.
  // 확장/반복항목 편집 모드는 selectedRoute.layoutName 이 null 이지만
  // 문서는 가상 path 기반 컨텍스트(useExtensionDocument 어댑트 등)에서 공급되므로, 이 모드
  // 들에서는 layoutName=null 만으로 "빈 라우트" 처리하지 않고 아래 components 체크에 위임한다.
  const isVirtualDocMode =
    state.editMode === 'extension' || state.editMode === 'iteration_item';
  if (
    !isVirtualDocMode &&
    (state.selectedRoute?.layoutName === null || state.selectedRoute === null)
  ) {
    return (
      <div
        className="g7le-preview-canvas g7le-preview-canvas--empty"
        data-testid="g7le-preview-empty"
        style={emptyStateStyle}
      >
        {t('layout_editor.preview.empty')}
      </div>
    );
  }

  if (isLoading) {
    return (
      <div
        className="g7le-preview-canvas g7le-preview-canvas--loading"
        data-testid="g7le-preview-loading"
        style={emptyStateStyle}
      >
        {t('layout_editor.preview.loading')}
      </div>
    );
  }

  if (error) {
    // 401/403/404/5xx/네트워크 등 분기별 안내 + 401 자동 redirect 는 AccessErrorPanel 이 처리.
    // PreviewCanvas 는 분기 판정/표시 책임을 위임한다 — UI 풍성화는 한 곳에서만.
    return <AccessErrorPanel error={error} />;
  }

  if (assetsError) {
    // 자산 로드 실패(매니페스트 fetch 401/403, IIFE/CSS 다운로드 실패 등)도
    // 레이아웃 로드 실패와 동일한 AccessErrorPanel UI 로 통합 — kind 별 안내·
    // 401 자동 redirect 등 일관된 처리(useEditorTemplateAssets 가 이미 구조화된
    // EditorAccessError 를 반환).
    return <AccessErrorPanel error={assetsError} />;
  }

  if (!assetsReady || componentRegistry === null || translationEngine === null) {
    return (
      <div
        className="g7le-preview-canvas g7le-preview-canvas--assets-loading"
        data-testid="g7le-preview-assets-loading"
        style={emptyStateStyle}
      >
        {t('layout_editor.preview.assets_loading')}
      </div>
    );
  }

  if (components.length === 0) {
    return (
      <div
        className="g7le-preview-canvas g7le-preview-canvas--empty-layout"
        data-testid="g7le-preview-empty-layout"
        style={emptyStateStyle}
      >
        {t('layout_editor.preview.empty_layout')}
      </div>
    );
  }

  // 확장 편집 폴백 판정.
  //  - no-injection: 이 확장이 호스트에 편집할 시각 요소를 주입하지 않음(진짜 0건/호스트 미병합).
  //  - gated-no-state: 머지 트리엔 주입 노드가 있으나 렌더 검증 결과 캔버스에 미노출(게이트/모달
  //    뒤 — 그 상황을 여는 states 가 editor-spec 에 정의되지 않음).
  // 폴백은 **early return 으로 캔버스를 교체하지 않는다** — 그러면 frame 이 언마운트→렌더 검증
  // effect 가 null 리셋→재렌더→다시 false 로 무한 진동(글쓰기↔안내 무한 깜빡임 결함).
  // 대신 캔버스(frame)는 항상 마운트한 채 **그 위에 불투명 오버레이로 안내를 덮어** frame DOM 을
  // 유지한다(검증 안정 + 깜빡임 0). 검증 중(null)에도 오버레이로 덮어 호스트 라이브 노출을 가린다.
  const extensionFallbackReason: 'no-injection' | 'gated-no-state' | null =
    state.editMode === 'extension'
      ? extensionEditability === 'no-injection'
        ? 'no-injection'
        : extensionEditability === 'ok' && extensionSourcePaths.length > 0 && extensionRenderVisible !== true
          ? 'gated-no-state'
          : null
      : null;

  // layout.scripts 로드 완료 게이트 — 위지윅(ckeditor5) 등 외부 UMD 라이브러리가
  // window 전역에 등록되기 전에 lifecycle.onMount 가 호출되면 핸들러가
  // `window.CKEDITOR 를 찾을 수 없습니다` 같은 에러로 실패. 모든 scripts 가 로드
  // 완료될 때까지 DynamicRenderer 마운트 지연.
  if (!scriptsReady) {
    return (
      <div
        className="g7le-preview-canvas g7le-preview-canvas--scripts-loading"
        data-testid="g7le-preview-scripts-loading"
        style={emptyStateStyle}
      >
        {t('layout_editor.preview.loading')}
      </div>
    );
  }

  return (
    <div
      className="g7le-preview-canvas"
      data-testid="g7le-preview-canvas"
      style={{
        // 캔버스 패널 자체는 스크롤하지 않는다 — 레이아웃(frame)이 길면 패널이 콘텐츠
        // 높이만큼 늘어나고, 세로 스크롤은 편집기 셸(문서 흐름) → 브라우저 창에서 한 번만
        // 일어난다. 종전 `flex:1 + overflow:auto` 는 패널을 뷰포트 잔여 높이에 가두어
        // 캔버스 영역에 별도 스크롤바를 만들었다("별도 스크롤 없이 전체 화면을
        // 한번에 보며 편집"). overflow:visible 로 두어 frame 전체가 한 흐름에 노출된다.
        overflow: 'visible',
        background: '#f1f5f9',
        padding: 24,
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'flex-start',
        // 콘텐츠가 짧아도 패널이 헤더 아래 가시영역을 가득 채우도록 최소 높이 = 뷰포트에서
        // sticky 헤더를 뺀 값. frame 이 더 길면 자연 확장(브라우저 스크롤).
        minHeight: 'calc(100vh - var(--g7le-header-h, 0px))',
        boxSizing: 'border-box',
      }}
    >
      {/* frame 래퍼 — frame(overflow:hidden) 과 오버레이 레이어(overflow:visible)를
          같은 좌표 원점/transform 아래 형제로 둔다. 오버레이가 frame 안에 있으면
          frame 의 hidden 클리핑으로 박스 옆 어포던스 버튼(작은 요소 outside 배치)이
 잘리므로, 오버레이를 frame 밖 형제 레이어로 분리한다.
          래퍼에 transform/width 를 두면 frame·오버레이가 동일 위치·스케일을 공유한다. */}
      <div
        className="g7le-preview-frame-wrapper"
        data-testid="g7le-preview-frame-wrapper"
        style={{
          position: 'relative',
          width: deviceWidth,
          transform: `scale(${scale})`,
          transformOrigin: 'top center',
        }}
      >
        <div
          // 프리뷰 색상 테마 — dark 면 프레임에 `.g7le-preview-dark` 마커 부여.
          // 종전 `.dark` 는 관리자 admin 환경의 조상 `html.dark` 와 충돌해 프리뷰 라이트 격리가
          // 깨졌다(조상 어디든 `.dark` 면 Tailwind `dark:` 활성). 코어 CSS 서빙 API(serveEditorCss)
          // 가 편집기용 CSS 의 `.dark` 셀렉터를 이 마커로 치환해 서빙하므로, 프리뷰 프레임의 이
          // 마커만으로 다크가 켜지고 조상 html.dark 와 독립된다(라이트 토글 시 확실히 라이트).
          // document.documentElement 가 아니라 프리뷰 프레임에만 적용 → 편집기 chrome 비다크.
          className={colorScheme === 'dark' ? 'g7le-preview-frame g7le-preview-dark' : 'g7le-preview-frame'}
          data-testid="g7le-preview-frame"
          data-color-scheme={colorScheme}
          ref={(el) => {
            frameRef.current = el;
            setFrameEl(el);
          }}
          style={{
            width: deviceWidth,
            background: '#fff',
            border: '1px solid #cbd5e1',
            boxShadow: '0 1px 3px rgba(0,0,0,0.05)',
            minHeight: 400,
            position: 'relative',
            // frame 자체에 identity transform 을 부여해 `position: fixed` 자손
            // (예: 닫힌 모바일 드로어 `mobile_nav_drawer` 의 `fixed translate-x-full`,
            // 토스트 등)의 containing block 이 frame 이 되도록 한다. transform 이 없으면
            // fixed 자손의 containing block 이 transform 보유 상위 조상(g7le-preview-frame-wrapper)
            // 으로 올라가, 아래 `overflow: hidden` 이 fixed 자손을 클리핑하지 못한다.
            // (어포던스 오버레이 분리 시 transform 이 wrapper 로 이동하면서
            //  frame 의 클리핑 컨텍스트가 깨져, 모바일 프리뷰 우측에 닫힌 메뉴 드로어가
            //  다시 노출되던 결함 재수정. 최초 수정 engine-v1.50.0 에서는 transform·overflow
            //  가 frame 한 요소에 함께 있어 동작했다.)
            transform: 'translateZ(0)',
            // frame 외부로 빠진 fixed/absolute 자손이 편집기 회색 배경에 노출되지 않도록
            // 클리핑. 위 transform 이 fixed 자손의 containing block 을 frame 으로 고정하므로
            // 이 hidden 이 닫힌 드로어(`translate-x-full`)를 잘라낸다.
            // 오버레이는 본 frame 의 형제(아래 레이어)라 이 hidden 의 영향을 받지 않는다.ㅇㄹ
            overflow: 'hidden',
          }}
        >
          <ResponsiveProvider overrideWidth={deviceWidth}>
            {components.map((component, index) => (
              <DynamicRenderer
                // translationsTick 을 key 에 포함 — 인라인 편집 키 CRUD 후 사전 재로드가
                // 끝나면 tick 이 증가해 이 서브트리가 재마운트되며 `$t:custom.*` 가 새 값으로
                // 재해석된다(raw 키 표시 결함 D 해소). 일반 편집 흐름엔 tick 불변이라 영향 없음.
                //
                // 확장 편집 모드: 호스트가 picker 로 나중에 확정되며 scripts(CKEditor
                // CDN)가 추가 로드되는데, 최초 마운트가 scripts 로드 전이면 lifecycle.onMount
                // (ckeditor5.initEditor)가 CKEDITOR 부재 시점에 1회 실패하고 React key 가 그대로라
                // 재마운트되지 않아 영구 미생성된다. 확장 모드에 한해 scriptsReady 신호를 key 에
                // 포함해, scripts 로드 완료(false→true) 시점에 이 서브트리를 1회 깨끗이 재마운트
                // → onMount 가 CKEDITOR 존재 시점에 실행된다. 다른 모드/일반 렌더(PreviewCanvas
                // 미사용)는 영향 없음 — scriptsReady 가 안정적이라 key 가 바뀌지 않는다.
                key={`${component.id ?? `preview-${index}`}:t${translationsTick}${state.editMode === 'extension' ? `:s${scriptsReady ? 1 : 0}` : ''}`}
                componentDef={component}
                dataContext={{
                  // 반복 항목 편집 모드는 source 배열을 1개로 제한한 컨텍스트. 그 외 모드는
                  // dataContext 원본과 동일(dataContextForRender 가 폴백).
                  ...dataContextForRender,
                  // `$`-prefixed 엔진 전역($locale/$locales 등)을 렌더 컨텍스트 최상위로
                  // 끌어올린다(lift). 런타임 createGlobalVariables() 와 동일 위치 — 레이아웃은
                  // `options="{{$locales}}"` 처럼 최상위 전역을 직접 바인딩한다. 시드(editor-spec
                  // sampleGlobal)에 선언된 `$`-키를 PreviewCanvas 가 여기서 노출하지 않으면
                  // 로케일 선택 드롭다운 등이 빈 채로 렌더된다. dataContext 의 다른
                  // 명시 키(route/query 등)보다 먼저 펴서 충돌 시 그쪽이 이기게 둔다.
                  ...extractDollarGlobals(isolatedGlobal),
                  // `_global` 에서는 `$`-키를 제거(혼동 방지 — `_global.$locales` 는 어떤
                  // 바인딩도 읽지 않는 사구역). 일반 `_global.*` 키스페이스만 흘려보낸다.
                  _global: stripDollarGlobals(isolatedGlobal),
                  // `computed` 블록을 주입해 DynamicRenderer 가 `_local`/데이터소스 기반으로
                  // `_computed.*` 를 재계산하게 한다(런타임 TemplateApp 과 동일 — 미주입 시
                  // `_computed` 게이트 본체가 빈 렌더). 데이터소스(dataContext 의 id 키)·_local·
                  // _global 이 모두 computedContext 에 들어가므로 활성 상태 시드가 반영된다.
                  _computedDefinitions: computedDefinitions,
                  // 활성 페이지 상태의 `_local` 패치를 if 평가 컨텍스트에 반영한다.
                  // _localInit(force) 로 root 렌더러 초기 _local 을 설정하고, 동시에 dataContext._local
                  // 로도 흘려 표현식/if 가 그 값을 즉시 읽게 한다(예: profile-edit 의
                  // `{{_local?.isPasswordVerified}}` 분기). 활성 상태 없으면 키를 두지 않아 기존
                  // 동작(init_actions 기반 _local)을 보존한다.
                  ...(stateLocalInit
                    ? {
                        _localInit: stateLocalInit,
                        _local: {
                          ...((dataContext as { _local?: Record<string, unknown> })._local ?? {}),
                          ...((isolatedGlobal as { _local?: Record<string, unknown> })._local ?? {}),
                        },
                      }
                    : {}),
                  // route — 기본 sampleRouteParams 위에 활성 상태의 route 패치 적용
                  // null 값 키는 토큰 제거(신규 작성 모드 — `{{!route.id}}`).
                  route: applyRoutePatch(
                    {
                      ...(dataContext.route as Record<string, unknown> | undefined ?? {}),
                      ...sampleRouteParams,
                      path: state.selectedRoute?.path ?? '',
                    },
                    stateRoutePatch,
                  ),
                  // query — 편집기 평소 빈 객체 위에 활성 상태의 query 패치 머지(진입 맥락 변종).
                  query: {
                    ...((dataContext.query as Record<string, unknown> | undefined) ?? {}),
                    ...(stateQueryPatch ?? {}),
                  },
                }}
                translationContext={{ templateId: state.templateIdentifier, locale: state.locale }}
                registry={componentRegistry}
                bindingEngine={bindingEngineRef.current!}
                translationEngine={TranslationEngine.getInstance()}
                actionDispatcher={actionDispatcherRef.current!}
                isEditMode={true}
                isRootRenderer={index === 0}
                componentPath={`${index}`}
                onComponentSelect={(_id, e) => {
                  // selection state 는 EditorCanvasOverlay 내부 hook 이 dataset 으로 직접 읽음.
                  // 본 콜백은 그 dispatch trigger 역할 — 본 함수가 빈 함수면 DynamicRenderer 가
                  // 클릭 시 핸들러를 등록하지 않으므로 항상 전달한다.
                  void _id;
                  void e;
                }}
                onComponentHover={(_id, e) => {
                  void _id;
                  void e;
                }}
              />
            ))}
          </ResponsiveProvider>
        </div>
        {/* EditorCanvasOverlay — frame 의 형제 레이어. frame 과 동일 좌표 원점(래퍼 기준
            inset:0)·동일 width 에 절대배치되어 measureOverlay(frame 기준 상대좌표)와
            정렬이 일치한다. overflow:visible 이라 박스 옆 outside 어포던스가 잘리지 않는다. */}
        <div
          className="g7le-overlay-layer"
          data-testid="g7le-overlay-layer"
          style={{
            position: 'absolute',
            left: 0,
            top: 0,
            width: deviceWidth,
            height: frameEl ? frameEl.getBoundingClientRect().height / (scale || 1) : '100%',
            overflow: 'visible',
            pointerEvents: 'none',
          }}
        >
          <EditorCanvasOverlay
            frameEl={frameEl}
            manifest={manifest}
            nesting={nesting}
            componentPalette={componentPalette}
            spec={spec}
            permissionCandidates={permissionCandidates}
            resolveRouteMatch={resolveRouteMatch}
            onNavigateToDestination={onNavigateToDestination}
            onSessionAddedPathsChange={onSessionAddedPathsChange}
          />
        </div>
        {/* 확장 편집 폴백 오버레이. frame 을 언마운트하지 않고 위를 불투명하게
            덮어 안내를 띄운다 — frame DOM 이 유지되어야 렌더 검증(extensionRenderVisible)이
 안정되고 글쓰기↔안내 무한 깜빡임이 발생하지 않는다. 검증 중
            (extensionRenderVisible=null)에도 'gated-no-state' 로 덮어 호스트 라이브 노출을 가린다. */}
        {extensionFallbackReason !== null && (
          <div
            className="g7le-preview-canvas--extension-fallback"
            data-testid="g7le-preview-extension-fallback"
            data-fallback-reason={extensionFallbackReason}
            style={{
              position: 'absolute',
              left: 0,
              top: 0,
              right: 0,
              // 오버레이는 frame 전체 높이를 덮어 호스트 라이브 노출을 가린다(긴 폼도 전부 가림).
              bottom: 0,
              minHeight: frameEl ? frameEl.getBoundingClientRect().height / (scale || 1) : 400,
              background: '#f1f5f9',
              display: 'flex',
              // 안내 문구는 frame 중앙(아래쪽)이 아니라 **뷰포트 가시영역 상단 기준 중앙**에
              // 둔다 — 긴 폼이면 frame 높이가 화면보다 길어 alignItems:center 가 문구를 화면
              // 밖 아래로 밀어낸다. align:flex-start + sticky 카드로 가시영역 중앙 유지.
              alignItems: 'flex-start',
              justifyContent: 'center',
              padding: 24,
              boxSizing: 'border-box',
              pointerEvents: 'auto',
              zIndex: 50,
            }}
          >
            <div
              className="g7le-extension-fallback-card"
              style={{
                maxWidth: 440,
                textAlign: 'center',
                // 긴 frame 에서도 안내가 화면 가시영역 중앙에 머물도록 sticky. 헤더 높이만큼
                // 내려 가시영역 세로 중앙 부근에 위치(40vh 여백). frame 이 짧으면 그대로 상단.
                position: 'sticky',
                top: 'calc(40vh - var(--g7le-header-h, 0px))',
              }}
            >
              <p className="g7le-extension-fallback-title" style={{ fontWeight: 600, marginBottom: 8, color: '#0f172a' }}>
                {extensionFallbackReason === 'no-injection'
                  ? t('layout_editor.preview.extension_no_injection_title')
                  : t('layout_editor.preview.extension_gated_title')}
              </p>
              <p className="g7le-extension-fallback-desc" style={{ color: '#475569', lineHeight: 1.6 }}>
                {extensionFallbackReason === 'no-injection'
                  ? t('layout_editor.preview.extension_no_injection_desc')
                  : state.availableStates.length > 1
                    ? t('layout_editor.preview.extension_gated_desc_with_states')
                    : t('layout_editor.preview.extension_gated_desc')}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * formErrors 맵의 메시지 값(`$t:...` 키)을 편집 대상 사전으로 미리 해석한다.
 *
 * 값이 `$t:` 접두 문자열이면 translationEngine 으로 번역, 배열이면 각 원소를 해석,
 * 그 외(평문/객체)는 그대로 둔다. 키(상태 경로)는 변경하지 않는다. translationEngine
 * 부재(테스트/standalone)면 입력을 그대로 반환(디그레이드).
 *
 * @param map getFormErrors 결과 (또는 undefined)
 * @param engine 편집 대상 TranslationEngine 인스턴스
 * @param ctx 번역 컨텍스트 (templateId/locale)
 * @return 메시지가 해석된 새 맵 또는 undefined
 */
export function resolveFormErrorMessages(
  map: Record<string, unknown> | undefined,
  engine: { translate: (key: string, ctx: { templateId: string; locale: string }, params?: string) => string } | null,
  ctx: { templateId: string; locale: string },
): Record<string, unknown> | undefined {
  if (!map) return undefined;
  if (!engine) return map;

  const translateValue = (val: unknown): unknown => {
    if (typeof val === 'string' && val.startsWith('$t:')) {
      try {
        // `$t:key|count=8|name=foo` — 파이프 파라미터 접미사를 분리해 translate 의
        // params 인자로 전달한다(엔진 표준 `|k=v` 보간 — 미분리 시 키에 파이프가 붙어
        // 해석 실패 → raw 키 노출). 파라미터 없으면 키만 넘긴다.
        const body = val.slice(3);
        const pipe = body.indexOf('|');
        if (pipe === -1) return engine.translate(body, ctx);
        const key = body.slice(0, pipe);
        const params = body.slice(pipe); // 선행 `|` 포함 — 엔진 paramsStr 형식과 동일
        return engine.translate(key, ctx, params);
      } catch {
        return val;
      }
    }
    if (Array.isArray(val)) return val.map(translateValue);
    return val;
  };

  const out: Record<string, unknown> = {};
  for (const [path, val] of Object.entries(map)) {
    out[path] = translateValue(val);
  }
  return out;
}

/**
 * route 컨텍스트에 활성 페이지 상태의 route 패치를 적용한다.
 *
 * 패치 값이 `null` 인 키는 base 에서 **제거**한다 — `{{!route.id}}`(신규 작성 모드) 변종을
 * 미리보기 위해 sampleRouteParams 가 자동 채운 토큰을 무력화하는 용도. 그 외 값은 덮어쓴다.
 * 패치가 없으면 base 를 그대로 반환(기존 동작 보존).
 *
 * @param base sampleRouteParams + path 가 채워진 route 컨텍스트
 * @param patch 활성 상태의 route 패치 (또는 null)
 * @return 패치가 적용된 route 컨텍스트
 */
/**
 * 머지 트리에서 현재 확장(`extensionId`)이 주입한 노드들의 source path 를 수집한다
 * `__source.extensionId` 가 일치하는 노드의 진입점 path 만
 * 모은다(자식은 진입점에 포함되므로 내려가지 않음). path 는 DynamicRenderer 가 부여하는
 * `data-editor-path` 와 같은 형식(`{i}.children.{j}...`) — 원문 그대로 셀렉터에 사용
 * ([[feedback_editor_dom_selector_use_raw_path_not_parsed]]).
 *
 * @param components 머지 트리 components
 * @param extensionId 현재 편집 중 확장 PK
 * @return source path 배열(원문)
 */
export function collectExtensionSourcePaths(
  components: EditorNode[] | undefined,
  extensionId: number,
): string[] {
  const paths: string[] = [];
  const walk = (nodes: EditorNode[] | undefined, prefix: string): void => {
    if (!Array.isArray(nodes)) return;
    nodes.forEach((node, i) => {
      if (!node || typeof node !== 'object') return;
      const path = prefix ? `${prefix}.children.${i}` : `${i}`;
      const src = (node as any).__source;
      if (src?.kind === 'extension' && src.extensionId === extensionId) {
        paths.push(path);
        return; // 진입점 — 자식 안 내려감
      }
      if (Array.isArray((node as any).children)) {
        walk((node as any).children as EditorNode[], path);
      }
    });
  };
  walk(components, '');
  return paths;
}

function applyRoutePatch(
  base: Record<string, unknown>,
  patch: Record<string, unknown> | null,
): Record<string, unknown> {
  if (!patch) return base;
  const out = { ...base };
  for (const [key, val] of Object.entries(patch)) {
    if (val === null) delete out[key];
    else out[key] = val;
  }
  return out;
}

const emptyStateStyle: React.CSSProperties = {
  flex: 1,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  padding: 24,
  background: '#f1f5f9',
  color: '#64748b',
  fontSize: 13,
};
