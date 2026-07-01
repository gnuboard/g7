// e2e:allow(기반 인프라) — spec/엔진/훅만 변경, 브라우저 가시 UI 무변경.
// 페이지 설정 탭·셸·진입점 + Playwright E2E 는 세션 D 에서 추가(계획서).
// (세션 D 가 page-settings E2E spec 을 추가하면 본 면제 주석은 제거 대상.)
/**
 * LayoutEditorChrome.tsx
 *
 * 레이아웃 편집기 최상위 셸 — Phase 1 골격.
 *
 * 본 컴포넌트는 코어 React tree 의 한 컴포넌트로, `template-engine.ts` 의
 * `renderTemplate()` 분기에서 `state.reactRoot` 에 같은 코어 컨텍스트 래퍼
 * (TranslationProvider/TransitionProvider/ResponsiveProvider/SlotProvider) 안에서
 * 마운트된다. 별도 `ReactDOM.createRoot` 호출 금지. 별도 DOM
 * 컨테이너 신설 금지.
 *
 * 시각적으로는 `position: fixed` 전면 레이어로 화면 전체를 점유한다(시각적
 * 오버레이) — "별도 시스템" 의미 아님, 같은 React tree 안의 레이어 형태.
 *
 * 모든 문자열은 `$t:layout_editor.*` 키 — 코어 TranslationEngine 이 자동 해석.
 * `translate` prop 받는 구조 금지.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from '../TranslationContext';
import { LayoutEditorProvider, useLayoutEditor } from './LayoutEditorContext';
import { LayoutDocumentProvider } from './LayoutDocumentContext';
import { EditorModalProvider, EditorModalRoot, useEditorModal } from './EditorModalContext';
import { EditorToolbar, type EditorTemplateOption } from './components/EditorToolbar';
import { LayoutAttachmentManager } from './components/property-controls/LayoutAttachmentManager';
import { CustomTranslationManager } from './components/property-controls/CustomTranslationManager';
import { PageSettingsModal } from './components/page-settings/PageSettingsModal';
import { VersionHistoryModal } from './components/VersionHistoryModal';
import type { VersionTarget } from './hooks/useLayoutVersions';
import { ShortcutHelpModal } from './components/ShortcutHelpModal';
import { collectReferencedCustomKeys } from './utils/customTranslations';
import { useEditorShortcuts, type ShortcutHandlers } from './hooks/useEditorShortcuts';
import { SaveFeedbackBanner } from './components/SaveFeedbackBanner';
import type { SaveResult, UseLayoutDocumentResult } from './hooks/useLayoutDocument';
import { RouteTreePanel } from './components/RouteTreePanel';
import { PreviewCanvas } from './components/PreviewCanvas';
import { DevicePreviewToolbar } from './components/DevicePreviewToolbar';
import { PageStateSwitcher } from './components/PageStateSwitcher';
import { AccessErrorPanel } from './components/AccessErrorPanel';
import { buildCodeEditorUrl, buildEditorUrl } from './hooks/useEditorMode';
import {
  clearEditorDndData,
  clearEditorDocumentData,
  clearEditorHistoryData,
  clearEditorSampleData,
  clearEditorSelectionData,
  clearEditorSpecMergeData,
  clearEditorStateData,
  clearEditorI18nData,
  clearPageStateData,
  trackEditorSpecMerge,
} from './devtools/editorTrackers';
import {
  deriveCurrentScope,
  matchStateItems,
  resolveDefaultStateId,
} from './utils/matchStateScope';
import { buildAuthHeaders } from './utils/authToken';
import { buildSampleGlobalSeed } from './sample-data/sampleGlobalChain';
import { useEditorRoutes } from './hooks/useEditorRoutes';
import { useEditorUrlSync } from './hooks/useEditorUrlSync';
import { usePreviewDarkIsolation } from './hooks/usePreviewDarkIsolation';
import { usePreviewBodyScrollIsolation } from './hooks/usePreviewBodyScrollIsolation';
import { useLayoutDocument } from './hooks/useLayoutDocument';
import { useExtensionDocument } from './hooks/useExtensionDocument';
import { adaptExtensionToLayoutDocument } from './hooks/adaptExtensionToLayoutDocument';
import { ExtensionHostPickerModal } from './components/ExtensionHostPickerModal';
import { useLayoutPreview } from './hooks/useLayoutPreview';
import type { ComponentManifest } from './components/ComponentPalette';
import type { NestingSpec, EditorSpec } from './spec/specTypes';
import { loadEditorSpecBundle, type SampleGlobalSource } from './spec/editorSpecLoader';
import { buildCoreActionRecipeSeed } from './spec/coreActionRecipes';
import { registerCoreWidgets } from './spec/registerCoreWidgets';
import { registerCoreEditors } from './spec/registerCoreEditors';
import { exposeLayoutEditorGlobals } from './spec/exposeLayoutEditorGlobals';

// 코어 위젯 등록 — 셸 모듈 로드 시 1회(멱등). 속성 편집 모달의 widgetRegistry
// 디스패치가 동작하려면 segmented/slider/select/toggle/color/image/tag-input 위젯이
// 등록돼 있어야 한다.
registerCoreWidgets();
// 코어 빌트인 노드 에디터/캔버스 오버레이 등록 — 셸 모듈 로드 시 1회(멱등).
// 단계 2 = children 노드 에디터(Ul/Ol/Nav/Form/Li). 단계 3 에서 table 빌트인 추가.
registerCoreEditors();
// 편집기 확장점(G7Core.layoutEditor.*) 노출 — 템플릿이 커스텀 위젯/노드에디터/오버레이를
// 등록할 수 있게 한다. 편집기 셸 로드 시 노출(메인 번들 비대화 회피).
exposeLayoutEditorGlobals();

/**
 * 권한 키 후보 fetch — 속성 모달 고급 탭 permissions TagInput (a-2).
 *
 * 편집 권한(`core.templates.layouts.edit`) 가드된 편집기 전용 엔드포인트에서
 * 코어 + 활성 확장 권한 목록을 받아온다. 응답은 `{ permissions: [{key, name}] }`.
 * 종전엔 `window.G7Config.permissions` 를 모든 admin 페이지에 상시 주입했으나,
 * 권한 카탈로그를 편집기 밖 전 페이지에 broadcast 하는 노출 범위 과다 문제로
 * 편집기 전용 fetch 로 전환. 실패/빈 응답 시 빈 배열 → TagInput 디그레이드.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자 (라우트 일관성용)
 * @return 권한 후보 ({ value: key, label: name }) 목록
 */
async function fetchPermissionCandidates(
  templateIdentifier: string,
): Promise<Array<{ value: string; label: string }>> {
  if (typeof fetch !== 'function') return [];
  try {
    const response = await fetch(
      `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/editor/permission-candidates.json`,
      { credentials: 'same-origin', headers: buildAuthHeaders() },
    );
    if (!response.ok) return [];
    const body = await response.json().catch(() => null);
    const list = body?.data?.permissions;
    if (!Array.isArray(list)) return [];
    const out: Array<{ value: string; label: string }> = [];
    for (const item of list) {
      if (item && typeof item === 'object') {
        const key = (item as { key?: unknown }).key;
        const name = (item as { name?: unknown }).name;
        if (typeof key === 'string' && key.length > 0) {
          out.push({ value: key, label: typeof name === 'string' && name ? name : key });
        }
      }
    }
    return out;
  } catch {
    return [];
  }
}

export interface LayoutEditorChromeProps {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 초기 콘텐츠 로케일 */
  initialLocale: string;
}

/**
 * 편집기 셸 — 같은 코어 React tree 의 최상위 노드.
 *
 * 레이아웃:
 * - 상단 툴바 행
 * - 좌측 라우트 트리 패널 (접기 가능)
 * - 캔버스 영역 (Phase 2 에서 렌더 채움)
 */
/**
 * Provider 내부에서 마운트되는 본체 — useEditorRoutes 등 LayoutEditorContext 의존
 * hook 을 여기서 호출한다.
 */
function LayoutEditorChromeBody({
  templateIdentifier,
}: {
  templateIdentifier: string;
}): React.ReactElement {
  // 셸 마운트 시 편집 대상 템플릿 routes fetch + RouteTree dispatch
  useEditorRoutes({ templateIdentifier });

  // URL ↔ selectedRoute 양방향 동기화
  // - 트리 → URL: RouteTreePanel.handleSelect 가 pushState
  // - URL → 트리: 초기 진입 시 ?route= 복원 + 브라우저 뒤로/앞으로 시 popstate 반영
  useEditorUrlSync(templateIdentifier);

  const { state, dispatch } = useLayoutEditor();
  const { t } = useTranslation();
  const modal = useEditorModal();

  // 단일 useLayoutDocument 인스턴스 — Context 로 PreviewCanvas/Overlay 들에 공급
  const layoutDocument = useLayoutDocument();
  // 확장 편집 모드 문서 — editMode==='extension' 일 때 layoutDocument
  // 대신 이 문서를 Context 로 공급한다(layout-extensions API 로드/저장).
  const extensionDocument = useExtensionDocument();

  // 실데이터 미리보기 — 현재 레이아웃 이름 기준. extension 모드는 layoutName 이
  // null 이라 hook 이 no_document 로 디그레이드(툴바 버튼도 비활성).
  const currentLayoutName = state.selectedRoute?.layoutName ?? null;
  const layoutPreview = useLayoutPreview(templateIdentifier, currentLayoutName);

  // 현재 편집 모드의 활성 문서 — 확장 편집 모드는 확장 문서를
  // 어댑트해 공급하고, 그 외(route/base/modal/iteration)는 일반 레이아웃 문서를 공급한다.
  // 저장/dirty/reload 는 반드시 이 활성 문서로 분기한다 — 확장 모드에서 layoutDocument 직결
  // 시 layout-extensions API 가 아닌 일반 레이아웃 API 로 저장돼 결함이 된다(저장 경로).
  const isExtensionMode = state.editMode === 'extension';
  const activeDocument = useMemo<UseLayoutDocumentResult>(
    () => (isExtensionMode ? adaptExtensionToLayoutDocument(extensionDocument) : layoutDocument),
    [isExtensionMode, extensionDocument, layoutDocument],
  );

  // 편집 대상 템플릿의 components.json 매니페스트 + editor-spec.json 로드
  // 추가. 두 자산은 PreviewCanvas → EditorCanvasOverlay 가 팔레트/nesting 평가에 사용.
  const [manifest, setManifest] = useState<ComponentManifest | null>(null);
  const [editorSpec, setEditorSpec] = useState<EditorSpec | null>(null);
  // sampleGlobal deep merge 체인 소스 (모듈 → 플러그인 → 템플릿 순) — PreviewCanvas
  // 가 코어 시드와 합성해 격리 store baseline 을 만든다.
  const [sampleGlobalSources, setSampleGlobalSources] = useState<SampleGlobalSource[]>([]);

  // 편집 대상 템플릿 타입(admin/user) — 나가기 목적지(`/admin/templates/{type}`)
  // 분기에 사용. 미확정 시 'user' 로 fallback(템플릿 목록 기본 탭).
  const [templateType, setTemplateType] = useState<'admin' | 'user'>('user');

  // 편집 대상 템플릿 표시 이름/버전 — 상단 툴바 식별자 영역에 노출.
  const [templateName, setTemplateName] = useState<string | null>(null);
  const [templateVersion, setTemplateVersion] = useState<string | null>(null);

  // 전환 가능한 템플릿 목록 — 상단 툴바 드롭다운 채움.
  const [templateList, setTemplateList] = useState<EditorTemplateOption[]>([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(
          `/api/templates/${encodeURIComponent(templateIdentifier)}/components.json`,
          { credentials: 'same-origin', headers: { Accept: 'application/json' } }
        );
        if (!response.ok) return;
        const data = await response.json().catch(() => null);
        if (!cancelled && data && typeof data === 'object') {
          setManifest(data as ComponentManifest);
        }
      } catch {
        // 매니페스트 미존재 → 팔레트가 빈 상태로 렌더됨 (spec_missing 안내)
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      // 활성 모듈/플러그인 식별자는 loader 가 window.G7Config 에서 추출.
      // 코어 핸들러 스펙 카탈로그(C1~C27)를 병합 base 로 주입.
      const { spec, sampleGlobalSources: sources } = await loadEditorSpecBundle({
        templateIdentifier,
        coreSeed: buildCoreActionRecipeSeed(),
      });
      if (!cancelled) {
        setEditorSpec(spec);
        setSampleGlobalSources(sources);
        emitSpecMergeTracker(templateIdentifier, spec, sources);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (typeof fetch !== 'function') return;
      try {
        // admin 템플릿 API 는 Sanctum 토큰 인증(Bearer) — buildAuthHeaders 로 토큰 첨부.
        // 종전 plain Accept 헤더만 보내 401 로 떨어지면 type/name/version 미수신 → 식별자 폴백.
        const response = await fetch(
          `/api/admin/templates/${encodeURIComponent(templateIdentifier)}`,
          { credentials: 'same-origin', headers: buildAuthHeaders() }
        );
        if (!response.ok) return;
        const body = await response.json().catch(() => null);
        const data = body?.data;
        const type = data?.type;
        if (!cancelled) {
          if (type === 'admin' || type === 'user') setTemplateType(type);
          if (typeof data?.name === 'string' && data.name) setTemplateName(data.name);
          if (typeof data?.version === 'string' && data.version) setTemplateVersion(data.version);
        }
      } catch {
        // 타입 조회 실패 → 'user' fallback 유지 (나가기 시 템플릿 목록 기본 탭)
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier]);

  // 전환 가능한 템플릿 목록 fetch — 상단 툴바 드롭다운.
  // `/api/admin/templates/` index 에서 설치된 템플릿을 받아, 비활성/pending 등을 제외하고
  // 전환 후보만 추린다. 실패 시 빈 목록 → 드롭다운 비활성(정적 라벨 디그레이드).
  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (typeof fetch !== 'function') return;
      try {
        // 충분한 per_page 로 1페이지에 전체 수신(설치 템플릿은 소수). include_hidden=false.
        const response = await fetch(
          `/api/admin/templates/?per_page=100&page=1`,
          { credentials: 'same-origin', headers: buildAuthHeaders() }
        );
        if (!response.ok) return;
        const body = await response.json().catch(() => null);
        const list = body?.data?.data;
        if (!cancelled && Array.isArray(list)) {
          const options: EditorTemplateOption[] = [];
          for (const item of list) {
            if (!item || typeof item !== 'object') continue;
            const identifier = (item as { identifier?: unknown }).identifier;
            if (typeof identifier !== 'string' || !identifier) continue;
            // pending(미설치 대기) 항목은 편집 대상이 될 수 없으므로 제외.
            if ((item as { is_pending?: unknown }).is_pending === true) continue;
            const rawType = (item as { type?: unknown }).type;
            const type: 'admin' | 'user' = rawType === 'admin' ? 'admin' : 'user';
            const name = (item as { name?: unknown }).name;
            const version = (item as { version?: unknown }).version;
            options.push({
              identifier,
              name: typeof name === 'string' ? name : identifier,
              version: typeof version === 'string' ? version : '',
              type,
            });
          }
          setTemplateList(options);
        }
      } catch {
        // 목록 조회 실패 → 빈 목록 유지 (드롭다운 비활성)
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier]);

  const nesting: NestingSpec | null | undefined = editorSpec?.nesting ?? null;

  // 페이지 상태 availableStates 노출 — editor-spec.states + 현재 편집
  // 대상 scope 로 매칭 항목을 도출해 SET_AVAILABLE_STATES 로 셋팅. selectedRoute/
  // editMode 전환 시 reducer 가 availableStates 를 비우고(scope 재매칭 리셋), 본 effect
  // 가 새 대상에 매칭되는 항목 + 기본 상태로 다시 채운다. 매칭 없음 → 빈 배열(토글 미표시).
  const selectedRoutePath = state.selectedRoute?.path ?? null;
  const editMode = state.editMode;
  // 확장 편집 모드: 호스트 layoutName 으로부터 호스트의 라우트 path 를 도출해
  // deriveCurrentScope 에 넘긴다 → 호스트 route/base scope states 적용. 호스트 출처 2가지:
  //  1) 트리 하위 진입 → reducer 가 selectedRoute.layoutName 에 담음(preConfirmedHost).
  //  2) picker 선택/단일 호스트 → useExtensionDocument 가 document.hostLayoutName 으로 확정
  //     (selectedRoute.layoutName 은 null 로 남음 — picker 는 reducer 가 아닌 훅 로컬 state).
  // 둘 중 확정된 것을 쓴다(picker 경로에서도 states 토글이 뜨도록 — 토글 미표시 결함 수정).
  // 호스트가 라우트 트리에 없으면(공통 base 레이아웃 등) `__base__/{layoutName}` 으로 폴백.
  const selectedHostLayout =
    state.selectedRoute?.layoutName ?? (isExtensionMode ? extensionDocument.document?.hostLayoutName ?? null : null);
  const routeTree = state.routeTree;
  const extensionHostRoutePath = useMemo<string | null>(() => {
    if (editMode !== 'extension' || !selectedHostLayout) return null;
    const routePath = findRoutePathByLayoutName(routeTree, selectedHostLayout);
    if (routePath) return routePath;
    // 라우트 노드가 없는 호스트(예: `_user_base`) → base scope 로 매칭.
    return `__base__/${selectedHostLayout}`;
  }, [editMode, selectedHostLayout, routeTree]);
  // host 미지정 확장 URL 직접 진입(`?edit=__extension__/{id}` 단독)의 종료 복귀 보강.
  // 진입 시점에는 호스트 미확정이라 returnRoute 합성이 불가(useEditorUrlSync 의 D-32 합성은
  // `?host=` 가 있을 때만 동작). 호스트가 확정되는 시점(picker 선택 → selectHost / 단일 호스트
  // 자동 확정 → document.hostLayoutName)에 그 호스트의 라우트 노드를 찾아 returnRoute 를 후행
  // 합성한다. reducer 가 기존 returnRoute(클릭 진입)는 덮지 않으므로 회귀 없음. 호스트가 라우트
  // 트리에 없으면(공통 base 레이아웃 호스트) 합성하지 않음 — 기존 동작(라우트 선택 화면) 유지.
  const extensionReturnRoutePending =
    isExtensionMode && state.returnRoute === null && !!selectedHostLayout;
  useEffect(() => {
    if (!extensionReturnRoutePending || !selectedHostLayout) return;
    const routePath = findRoutePathByLayoutName(routeTree, selectedHostLayout);
    if (!routePath) return;
    dispatch({
      type: 'SET_RETURN_ROUTE',
      route: { path: routePath, layoutName: selectedHostLayout },
    });
  }, [extensionReturnRoutePending, selectedHostLayout, routeTree, dispatch]);

  useEffect(() => {
    const scope = deriveCurrentScope(editMode, selectedRoutePath, extensionHostRoutePath);
    const items = matchStateItems(editorSpec?.states?.groups, scope);
    dispatch({
      type: 'SET_AVAILABLE_STATES',
      states: items,
      activeStateId: resolveDefaultStateId(items),
    });
  }, [editorSpec, editMode, selectedRoutePath, extensionHostRoutePath, dispatch]);

  // 권한 키 후보 — 속성 모달 고급 탭 permissions TagInput (a-2).
  // 편집 권한 가드된 편집기 전용 엔드포인트에서 1회 fetch (코어 + 활성 확장 권한).
  // 종전 G7Config 상시 노출 방식은 모든 admin 페이지에 권한 카탈로그를 broadcast 해
  // 폐기(노출 범위를 편집기로 한정). 미수신 시 빈 배열 → "+ 추가" 디그레이드.
  const [permissionCandidates, setPermissionCandidates] = useState<Array<{ value: string; label: string }>>([]);
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const candidates = await fetchPermissionCandidates(templateIdentifier);
      if (!cancelled) setPermissionCandidates(candidates);
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier]);

  // 다크 프리뷰 격리 — 편집기 마운트 동안 `<html class="dark">` 조상 제거.
  // 어드민 호스트 CSS 의 `.dark <desc>` 다크 cascade 가 프리뷰 콘텐츠를 침범하는 것을 차단한다
  // (프리뷰 다크는 프레임 마커 `.g7le-preview-dark` + rewrite CSS 로만 구동). usePreviewDarkIsolation 참조.
  usePreviewDarkIsolation();

  // body 스크롤 락 격리 — 프리뷰가 동일 문서 렌더라, 모달 편집 모드에서
  // Modal composite(isOpen=true 강제)가 `document.body.style.overflow='hidden'` 락을 걸어
  // 캔버스(브라우저 단일 스크롤) 스크롤바가 사라진다. 편집기 생존 동안 인라인 락을 무력화.
  usePreviewBodyScrollIsolation();

  // 라우트 매칭 — selectedNode 의 navigate.path/A.href 가 라우트 트리에 있는지 판정
  const resolveRouteMatch = useCallback(
    (path: string): 'route_in_tree' | 'route_not_in_tree' => {
      const tree = state.routeTree;
      const stripped = path.split('?')[0]?.split('#')[0] ?? path;
      const found = matchPathInTree(tree, stripped);
      return found ? 'route_in_tree' : 'route_not_in_tree';
    },
    [state.routeTree]
  );

  // 링크 어포던스 클릭 → SELECT_ROUTE + 트리에서 layoutName 보강
  const handleNavigateToDestination = useCallback(
    (destinationPath: string): void => {
      const stripped = destinationPath.split('?')[0]?.split('#')[0] ?? destinationPath;
      const node = findRouteNodeByPath(state.routeTree, stripped);
      if (!node) return;
      // dirty 가드 — 변경 사항이 있으면 사용자에게 확인 (단순 confirm 으로 1차)
      if (activeDocument.isDirty) {
        const proceed =
          typeof window !== 'undefined'
            ? window.confirm(t('layout_editor.chrome.dirty_guard.message'))
            : true;
        if (!proceed) return;
      }
      dispatch({
        type: 'SELECT_ROUTE',
        route: { path: node.path, layoutName: node.layoutName },
      });
    },
    [state.routeTree, activeDocument.isDirty, dispatch]
  );

  // 세션 누적 path 추적 — save 가드의 sessionAddedPaths 로 사용
  const sessionAddedPathsRef = React.useRef<string[]>([]);
  const handleSessionAddedPathsChange = useCallback((paths: string[]) => {
    sessionAddedPathsRef.current = paths;
  }, []);

  // SaveFeedbackBanner — 저장 결과를 사용자에게 노출.
  // PreviewCanvas 격리 store swap 영향 없이 chrome 직속 React state.
  const [saveResult, setSaveResult] = useState<SaveResult | null>(null);
  const handleSaveBannerDismiss = useCallback(() => setSaveResult(null), []);

  // sticky 헤더(툴바+배너+디바이스바) 실측 높이를 CSS 변수 `--g7le-header-h` 로 노출 —
  // 라우트 트리가 `top: var(--g7le-header-h)` / `maxHeight: calc(100vh - …)` 로 헤더 바로
  // 아래에 고정되도록 한다(셸이 문서 흐름이라 헤더 높이가 가변: 저장 배너 표시/숨김 등).
  const shellRef = useRef<HTMLDivElement | null>(null);
  const headerRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    const header = headerRef.current;
    const shell = shellRef.current;
    if (!header || !shell || typeof ResizeObserver === 'undefined') return;
    const apply = (): void => {
      shell.style.setProperty('--g7le-header-h', `${Math.round(header.getBoundingClientRect().height)}px`);
    };
    apply();
    const ro = new ResizeObserver(apply);
    ro.observe(header);
    return () => ro.disconnect();
  }, []);
  const handleConcurrentLoadLatest = useCallback(() => {
    void activeDocument.reload();
  }, [activeDocument]);

  // 편집기 종료(나가기) — 템플릿 관리 화면으로 복귀. dirty 면 확인 후 이탈.
  // 편집기는 전체화면 오버레이로 React Router 컨텍스트 밖이라 SPA navigate 가
  // 동작하지 않는다(라우트 미매칭 → URL 미변경). 실제 페이지 이동을 일으키는
  // openWindow + target:'_self'(window.location.assign) 로 처리한다.
  const handleExit = useCallback(() => {
    if (activeDocument.isDirty) {
      const proceed =
        typeof window !== 'undefined'
          ? window.confirm(t('layout_editor.chrome.dirty_guard.message'))
          : true;
      if (!proceed) return;
    }
    // 편집 대상이 admin 템플릿이면 admin 탭, user 템플릿이면 user 탭으로 복귀.
    (window as any).G7Core?.dispatch?.({
      handler: 'openWindow',
      params: { path: `/admin/templates/${templateType}`, target: '_self' },
    });
  }, [activeDocument.isDirty, t, templateType]);

  // 템플릿 전환 — 상단 툴바 드롭다운에서 다른 템플릿을 고르면 그 템플릿의 편집기로 이동.
  // 나가기와 동일하게 dirty 면 확인 후 이탈한다. 편집기는 React Router 밖
  // 전체화면 오버레이라 openWindow + target:'_self'(window.location.assign)로 실제 이동한다.
  const handleSwitchTemplate = useCallback(
    (nextIdentifier: string) => {
      if (!nextIdentifier || nextIdentifier === templateIdentifier) return;
      if (activeDocument.isDirty) {
        const proceed =
          typeof window !== 'undefined'
            ? window.confirm(t('layout_editor.chrome.dirty_guard.message'))
            : true;
        if (!proceed) return;
      }
      const path = buildEditorUrl(nextIdentifier);
      (window as any).G7Core?.dispatch?.({
        handler: 'openWindow',
        params: { path, target: '_self' },
      });
    },
    [templateIdentifier, activeDocument.isDirty, t],
  );

  // 코드편집 — 기존 텍스트 레이아웃 편집기 화면을 새 창으로 연다(위지윅 편집은
  // 유지한 채 코드 편집을 별도 창에서). 본체는 별도 라우트로 이미 존재
  // (admin_template_layout_edit). 위지윅에서 선택 중이던 라우트 path 를 `?route=` 쿼리로
  // 전달해 코드편집기가 동일 레이아웃을 선택하게 한다(위지윅 URL 모델과 일관).
  // openWindow 는 target 미지정 시 `_blank`(새 창) — dirty 가드 불필요(현재 편집 화면 유지).
  const handleEditCode = useCallback(() => {
    // 위지윅 selectedRoute.path 는 routes.json 원본(admin 은 선행 와일드카드 `*/admin/...`)
    // 을 보존하지만, 코드 편집기는 서버 route_path(선행 `*` 제거됨)와 `?route=` 를 `===`
    // 매칭한다. buildCodeEditorUrl 이 선행 `*` 를 제거해 양쪽 표현을 일치시킨다.
    // base/modal/extension 가상 path(__ prefix)는 자동으로 쿼리 생략(코드편집기 첫 레이아웃 선택).
    const path = buildCodeEditorUrl(templateIdentifier, state.selectedRoute?.path ?? null);
    (window as any).G7Core?.dispatch?.({
      handler: 'openWindow',
      params: { path },
    });
  }, [templateIdentifier, state.selectedRoute]);

  // 툴바 🖼 이미지 — 이미지 관리 모달(현재 레이아웃 첨부). 툴바 진입은 선택 노드
  // 무관이므로 onSelect 미전달("배경으로 사용" 버튼 숨김). 삭제/업로드/조회만.
  const handleManageImages = useCallback(() => {
    const layoutName = state.selectedRoute?.layoutName ?? '';
    if (!layoutName) return;
    const id = modal.open({
      ariaLabel: t('layout_editor.attachment_manager.title'),
      width: 720,
      maxHeightRatio: 0.82,
      content: React.createElement(LayoutAttachmentManager, {
        templateIdentifier,
        layoutName,
        t,
        onClose: () => modal.close(id),
      }),
    });
  }, [modal, templateIdentifier, state.selectedRoute, t]);

  // 툴바 🌐 다국어 — 커스텀 다국어 키 관리 모달(현재 레이아웃). 인라인 편집으로
  // 생성된 `$t:custom.*` 키를 목록/번역편집/삭제하고, 화면 이탈로 끊긴 좀비(orphaned)
  // 키를 식별·정리한다. 🖼 이미지 관리와 동형 진입.
  const handleManageTranslations = useCallback(() => {
    const layoutName = state.selectedRoute?.layoutName ?? '';
    if (!layoutName) return;
    // 현재 편집 중인(저장 전 포함) 캔버스 content 를 실시간 스캔 → 참조 키 집합.
    // 관리 모달이 저장된 status 와 별개로 "현재 캔버스 사용중/미사용"을 함께
    // 표시한다.
    const referencedKeys = collectReferencedCustomKeys(layoutDocument.document?.raw ?? {});
    const id = modal.open({
      ariaLabel: t('layout_editor.translation_manager.title'),
      width: 720,
      maxHeightRatio: 0.82,
      content: React.createElement(CustomTranslationManager, {
        templateIdentifier,
        layoutName,
        t,
        referencedKeys,
        onClose: () => modal.close(id),
      }),
    });
  }, [modal, templateIdentifier, state.selectedRoute, t, layoutDocument.document]);

  // 편집 대상 템플릿 사전 우선 해석 — data_source label_key 는 편집 대상 템플릿의
  // `editor.data_source.*` 키라 chrome `t`(admin 컨텍스트)로는 미해석. PreviewCanvas 가
  // `window.G7Core.t` 를 편집 대상 사전 fallback 체인으로 swap 해 두므로 그것을 우선
  // 사용하고 미해석 시 chrome t 로 폴백한다(EditorCanvasOverlay editorAwareT 와 동형).
  const editorAwareT = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      const g7 = (window as { G7Core?: { t?: (k: string, p?: Record<string, string | number>) => string } }).G7Core;
      if (g7 && typeof g7.t === 'function') {
        const resolved = g7.t(key, params);
        if (resolved && resolved !== key && !resolved.startsWith('$t:')) return resolved;
      }
      return t(key, params);
    },
    [t],
  );

  // 툴바 ⚙페이지 설정 — 현재 레이아웃의 8탭 페이지 설정 모달.
  // 종전 ⚙데이터(DataSourcesPanel CRUD)는 이 모달의 [데이터] 탭으로 흡수됐다(진입점 단일화 —
  // 무손실: DataSourceTab 이 DataSourcesPanel 을 그대로 임베드). 셸은 prop 주도이며, 자기
  // 로컬 state(editorSpec/permissionCandidates) 를 주입한다. 후보 풀·patch 결선은 셸 내부의
  // usePageSettings/binding hooks 가 담당(Provider 트리 안). 🖼이미지·🌐다국어와 동형 진입.
  const handlePageSettings = useCallback(() => {
    if (!state.selectedRoute) return;
    if (state.editMode === 'extension' || state.editMode === 'iteration_item') return;
    const id = modal.open({
      ariaLabel: t('layout_editor.page_settings.title', { name: state.selectedRoute.path ?? '' }),
      width: 1080,
      maxHeightRatio: 0.88,
      // ESC 로 모달을 닫지 않는다 — 페이지 설정은 탭마다 입력이 많아 ESC 오타로
      // 통째 닫히면 작업이 날아간다. EditorModalRoot 의 capture-phase 핸들러는 closeOnEscape=false
      // 면 preventDefault/stopPropagation 없이 일찍 빠져, ESC 가 하위로 정상 전파된다 → 모달 안의
      // 플로팅 드롭다운·인라인 입력 모드 등은 각자의 ESC 로 빠져나갈 수 있다(닫기는 [✕]/[닫기] 버튼).
      closeOnEscape: false,
      // EditorModalRoot 가 LayoutDocumentProvider **안**에 마운트되므로(아래 Body JSX), 모달 content
      // 안의 usePageSettings → useLayoutDocumentContext 가 **라이브** 문서 컨텍스트를 읽는다.
      // content 를 스냅샷 provider 로 감싸면 patch 후 갱신이 안 보이므로(스냅샷 stale) 감싸지 않는다.
      content: React.createElement(PageSettingsModal, {
        templateIdentifier,
        spec: editorSpec,
        t,
        resolveLabel: editorAwareT,
        permissionCandidates,
        onClose: () => modal.close(id),
      }),
    });
  }, [modal, state.selectedRoute, state.editMode, t, editorAwareT, templateIdentifier, editorSpec, permissionCandidates]);

  // 툴바 👁 미리보기 — 현재 편집 중(미저장 포함) 문서를 실데이터로 새 창 미리보기.
  // storePreview 가 임시 미리보기 레코드(template_layout_previews, 30분 TTL)를 만들고
  // 응답 토큰으로 `/preview/{token}` 을 새 창에 연다. extension 모드(layoutName null)는
  // 툴바 버튼이 비활성이라 호출되지 않는다.
  const handlePreview = useCallback(async () => {
    const result = await layoutPreview.createPreview(layoutDocument.document?.raw ?? null);
    if (result.kind !== 'success') {
      // 실패 시 저장 배너 대신 간단 안내(미리보기는 부가 기능 — 캔버스 샘플 렌더가 1차).
      // network_error 면 메시지, no_document 면 라우트 미선택 — 둘 다 토스트 대신 alert 로 최소 노출.
      const message =
        result.kind === 'network_error'
          ? result.message
          : t('layout_editor.preview_action.no_document');
      if (typeof window !== 'undefined') window.alert(message);
    }
  }, [layoutPreview, layoutDocument.document, t]);

  // 툴바 🕘 버전 히스토리 — 현재 레이아웃 저장 버전 목록·복원 모달.
  // 복원 성공 시 onRestored 로 **activeDocument**.reload() 수행(캔버스 재로드 + dirty/lock_version
  // 재동기화). 🖼이미지·🌐다국어·⚙데이터 관리 모달과 동형 진입.
  //
  // activeDocument 사용 이유 — 종전엔 layoutDocument 를
  // 직접 reload 했는데, 확장 편집 모드에서는 activeDocument 가 **확장 문서**(adaptExtension…)이고
  // layoutDocument 가 아니라, 호스트 route 문서만 reload 되고 확장 캔버스는 미갱신이었다. 모달/
  // 이터레이션 모드는 activeDocument === layoutDocument 라 동일 객체지만, reload 가 캐시-버스트
  // nonce 를 올리도록 함께 수정해(useLayoutDocument.reload) 모달/이터레이션/route 의 HTTP 캐시
  // stale 미갱신도 해소된다. 셋 다 한 경로(activeDocument.reload)로 일관 처리.
  const handleShowVersions = useCallback(() => {
    // 대상 결정 — 확장 편집 모드는 확장 전용 버전 API 를 쓰는
    // extension 타겟, 그 외(route/base/modal/iteration)는 layout 타겟(호스트 레이아웃).
    const layoutName = state.selectedRoute?.layoutName ?? '';
    const selectedPath = state.selectedRoute?.path ?? '';
    const extensionId =
      state.editMode === 'extension' && selectedPath.startsWith('__extension__/')
        ? selectedPath.slice('__extension__/'.length)
        : null;
    const target: VersionTarget | null = extensionId
      ? { kind: 'extension', extensionId }
      : layoutName
        ? { kind: 'layout', layoutName }
        : null;
    if (!target) return;
    const id = modal.open({
      ariaLabel: t('layout_editor.version_history.title'),
      width: 640,
      maxHeightRatio: 0.82,
      content: React.createElement(VersionHistoryModal, {
        templateIdentifier,
        target,
        t,
        onRestored: async (newVersion?: number) => {
          // 트리 버전 배지 동기화 — 복원도 새 버전을 적재하므로 배지를 올린다.
          // 확장 복원은 확장 배지(extensionVersions), 레이아웃 복원은 레이아웃 배지.
          if (typeof newVersion === 'number') {
            if (target.kind === 'extension') {
              dispatch({ type: 'SET_EXTENSION_VERSION', extensionId: target.extensionId, version: newVersion });
            } else {
              dispatch({ type: 'SET_LAYOUT_VERSION', layoutName: target.layoutName, version: newVersion });
            }
          }
          await activeDocument.reload();
        },
        onClose: () => modal.close(id),
      }),
    });
  }, [modal, templateIdentifier, state.selectedRoute, state.editMode, t, activeDocument, dispatch]);

  // beforeunload 가드 — 미저장 변경(세션 캐시 dirty 키 또는 현재 dirty)
  // 이 하나라도 있으면 새로고침/탭 닫기 시 브라우저 기본 경고를 띄운다. 항목4 세션
  // 캐시가 여러 라우트의 dirty 를 누적하므로 dirtyKeys 우선, 미반영 환경은 isDirty 폴백.
  const hasUnsavedChanges =
    activeDocument.dirtyKeys.size > 0 || activeDocument.isDirty;
  useEffect(() => {
    if (!hasUnsavedChanges) return;
    const handler = (e: BeforeUnloadEvent): void => {
      e.preventDefault();
      // 레거시 브라우저 호환 — returnValue 설정 시 경고 다이얼로그 표시.
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [hasUnsavedChanges]);

  // 레이아웃 초기화 — 현재 편집 중인 레이아웃을 마지막 저장 상태로 되돌림.
  // dirty 일 때만 활성(툴바). confirm 후 reload() — reload 가 (1) 세션 캐시 엔트리
  // 제거 + dirty 키 해제(트리 배지 사라짐), (2) reloadCounter +1(EditorCanvasOverlay
  // history baseline 재설정), (3) 서버 최신 재fetch 를 일괄 수행한다.
  const handleReset = useCallback(() => {
    if (!activeDocument.isDirty) return;
    const proceed =
      typeof window !== 'undefined'
        ? window.confirm(t('layout_editor.chrome.reset_guard.message'))
        : true;
    if (!proceed) return;
    void activeDocument.reload();
  }, [activeDocument, t]);

  // 저장 — 툴바 onSave 와 동일 경로(단축키 Ctrl+S 재사용). 확장 편집 모드는 활성 문서가
  // 확장 문서이므로 layout-extensions API 로 저장된다.
  const handleSaveAction = useCallback(async () => {
    const result = await activeDocument.save({ sessionAddedPaths: sessionAddedPathsRef.current });
    setSaveResult(result);
    // 라우트 트리 버전 배지 동기화 — 저장 성공 응답의 현재 버전 번호를
    // 컨텍스트에 반영한다. 레이아웃 저장(모달/반복 항목 포함)은 savedLayoutName 키,
    // 확장 저장(useExtensionDocument)은 savedExtensionId 키. 구버전 백엔드는 자연 생략.
    if (result.kind === 'success' && typeof result.newContentVersion === 'number') {
      if (result.savedExtensionId) {
        dispatch({
          type: 'SET_EXTENSION_VERSION',
          extensionId: result.savedExtensionId,
          version: result.newContentVersion,
        });
      } else if (result.savedLayoutName) {
        dispatch({
          type: 'SET_LAYOUT_VERSION',
          layoutName: result.savedLayoutName,
          version: result.newContentVersion,
        });
      }
    }
  }, [activeDocument, dispatch]);

  // 단축키 맵 모달 — editorShortcuts SSoT 를 표로 표시(`?` 또는 툴바 ⌨ 버튼).
  const handleShowShortcuts = useCallback(() => {
    const id = modal.open({
      ariaLabel: t('layout_editor.shortcuts.title'),
      width: 560,
      maxHeightRatio: 0.85,
      content: React.createElement(ShortcutHelpModal, { t, onClose: () => modal.close(id) }),
    });
  }, [modal, t]);

  // 문서/보기 단축키 — 캔버스가 소유하지 않는 액션만 결선(요소/클립보드/deselect 는
  // EditorCanvasOverlay 가 결선). Escape 우선순위: 선택 있으면 캔버스가 deselect 처리하므로
  // chrome 의 exit 는 선택 없을 때만 동작하도록 hasSelection 을 window 플래그로 읽는다.
  const chromeShortcutHandlers = useMemo<ShortcutHandlers>(
    () => ({
      save: () => { void handleSaveAction(); },
      exit: handleExit,
      editCode: handleEditCode,
      reset: handleReset,
      translations: handleManageTranslations,
      addElement: () => { if (state.selectedRoute) dispatch({ type: 'TOGGLE_PALETTE' }); },
      help: handleShowShortcuts,
      // 미리보기 — layoutName 있을 때만(extension 모드 등 제외). 단축키 맵에도 표시.
      preview: () => { if (state.selectedRoute?.layoutName) void handlePreview(); },
    }),
    [handleSaveAction, handleExit, handleEditCode, handleReset, handleManageTranslations, state.selectedRoute, dispatch, handleShowShortcuts, handlePreview],
  );
  // chrome 훅의 hasSelection 은 캔버스가 노출한 window 플래그(__g7LayoutEditorHasSelection)를
  // 폴링해 읽는다 — 선택 있으면 Escape 를 캔버스 deselect 에 양보(chrome exit 억제), 없으면
  // chrome 이 exit. (요소/클립보드 액션은 캔버스 훅 소유라 chrome 핸들러에 없어 무영향.)
  const [chromeHasSelection, setChromeHasSelection] = useState(false);
  useEffect(() => {
    const idv = window.setInterval(() => {
      setChromeHasSelection(!!(window as { __g7LayoutEditorHasSelection?: boolean }).__g7LayoutEditorHasSelection);
    }, 200);
    return () => window.clearInterval(idv);
  }, []);
  useEditorShortcuts({ handlers: chromeShortcutHandlers, hasSelection: chromeHasSelection });

  // memo 로 Provider value 안정화 — 매 렌더 새 객체로 인한 자식 reflow 회피.
  // 확장 편집 모드에서는 layout-extensions 문서를 어댑트해 공급하고, 그 외에는
  // 일반 레이아웃 문서를 공급한다(base/modal 은 가상 path 로 layoutDocument 가 처리).
  const documentProviderValue = activeDocument;

  // routes 로드 실패 시 전체 화면 AccessErrorPanel — 트리·캔버스 없이 안내만.
  // 401/403 이면 자동 로그인 redirect 도 동일 메커니즘으로 동작.
  // 트리가 없으면 라우트 선택 자체가 불가하므로 셸 본문 자리에 카드만 띄운다.
  if (state.routesError) {
    return (
      <div
        className="g7le-chrome g7le-chrome--routes-error"
        data-testid="g7le-chrome-routes-error"
        style={{
          position: 'fixed',
          inset: 0,
          display: 'flex',
          background: '#f8fafc',
          color: '#0f172a',
          fontFamily:
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif',
          fontSize: 13,
          lineHeight: 1.5,
          zIndex: 9000,
        }}
      >
        <AccessErrorPanel error={state.routesError} />
      </div>
    );
  }

  return (
    <div
      className="g7le-chrome"
      data-testid="g7le-chrome"
      ref={shellRef}
      style={{
        // 편집기 셸을 문서 흐름(static)에 두어, 레이아웃(페이지)이 길면 캔버스 영역이
        // 아니라 브라우저 창(html)의 단일 스크롤로 전체가 늘어나 한 번에 보며 편집한다
        // 종전 `position:fixed; inset:0 +
        // grid 1fr` 는 셸을 뷰포트에 못박아 캔버스 영역에 별도 스크롤바가 생길 수밖에 없었다.
        // static 이라야 내부 sticky 헤더·트리가 viewport(html 스크롤) 기준으로 고정된다
        // (absolute/fixed 면 sticky 기준이 셸 자신이 되어 window 스크롤과 어긋남).
        // 편집기 라우트에서는 `#app` 의 유일한 자식이라 호스트 콘텐츠와 겹치지 않는다.
        position: 'static',
        minHeight: '100vh',
        background: '#f8fafc',
        color: '#0f172a',
        fontFamily:
          '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif',
        fontSize: 13,
        lineHeight: 1.5,
        zIndex: 9000,
      }}
    >
      <LayoutDocumentProvider value={documentProviderValue}>
        {/* 확장 편집 대표 호스트 선택 모달 — extension_point 가 복수 호스트에 주입돼
            호스트가 미확정일 때만 렌더. 선택 시 useExtensionDocument 가 그 호스트를 병합 렌더. */}
        {isExtensionMode && extensionDocument.needsHostPicker && extensionDocument.document && (
          <ExtensionHostPickerModal
            hostLayouts={extensionDocument.document.hostLayouts}
            extensionLabel={null}
            onSelect={extensionDocument.selectHost}
          />
        )}
        {/* sticky 헤더 — 브라우저 스크롤 시 툴바/디바이스바가 화면 상단에 고정 */}
        <div
          className="g7le-chrome__header"
          data-testid="g7le-chrome-header"
          ref={headerRef}
          style={{
            position: 'sticky',
            top: 0,
            zIndex: 20,
            background: '#f8fafc',
          }}
        >
          <EditorToolbar
            onSave={() => {
              // 단축키(Ctrl+S)와 동일 경로 — 저장 + 배너 + 트리 버전 배지 동기화 일원화.
              void handleSaveAction();
            }}
            isDirty={activeDocument.isDirty}
            onExit={handleExit}
            onEditCode={handleEditCode}
            onManageImages={handleManageImages}
            onManageTranslations={handleManageTranslations}
            onPageSettings={handlePageSettings}
            onReset={handleReset}
            onShowShortcuts={handleShowShortcuts}
            onPreview={handlePreview}
            onShowVersions={handleShowVersions}
            templateName={templateName}
            templateVersion={templateVersion}
            templateList={templateList}
            onSwitchTemplate={handleSwitchTemplate}
          />
          <SaveFeedbackBanner
            result={saveResult}
            onDismiss={handleSaveBannerDismiss}
            onLoadLatest={handleConcurrentLoadLatest}
          />
          {/* 캔버스 제목 배너 제거 — 제목/설명 편집은 [페이지 설정] 모달 단일
              진입(툴바 ⚙ 페이지 설정)으로 일원화. 배너의 인라인 편집/표현식 고급 배지 폐기. */}
          <div
            className="g7le-chrome__device-toolbar"
            data-testid="g7le-chrome-device-toolbar"
            style={{
              display: 'flex',
              justifyContent: 'center',
              padding: '6px 12px',
              background: '#f1f5f9',
              borderBottom: '1px solid #e2e8f0',
            }}
          >
            <DevicePreviewToolbar />
            <PageStateSwitcher />
          </div>
        </div>
        <div
          className="g7le-chrome__body"
          style={{
            display: 'grid',
            gridTemplateColumns: 'auto 1fr',
            // body 는 캔버스 콘텐츠 전체 높이만큼 자연 확장한다(고정 높이/overflow 없음).
            // 세로 스크롤은 셸 전체 → 브라우저 창에서 한 번만 일어난다.
            // 트리 셀은 stretch(기본)로 body 전체 높이를 차지해야 그 안에서 트리 aside 가
            // sticky(top: 헤더높이)로 끝까지 따라온다. `align-items:start` 면 트리 셀이
            // 트리 높이로 축소돼 sticky 이동 범위가 그만큼만 되어 스크롤 시 함께 밀린다.
            alignItems: 'stretch',
          }}
        >
          <RouteTreePanel />
          <PreviewCanvas
            manifest={manifest}
            nesting={nesting}
            componentPalette={editorSpec?.componentPalette ?? null}
            spec={editorSpec}
            permissionCandidates={permissionCandidates}
            sampleGlobalSources={sampleGlobalSources}
            resolveRouteMatch={resolveRouteMatch}
            onNavigateToDestination={handleNavigateToDestination}
            onSessionAddedPathsChange={handleSessionAddedPathsChange}
          />
        </div>
        {/* EditorModalRoot — LayoutDocumentProvider 안에 마운트(single mount per editor tree).
            모달 content(페이지 설정 등)가 usePageSettings → useLayoutDocumentContext 로 **라이브** 문서를
            읽고 patch 가 즉시 라운드트립되도록 provider 안에 둔다(D-ROOT). position:fixed 라 DOM 중첩이
            시각 스택에 영향 없음. 캔버스 모달(팔레트/속성/409/dirty)도 docCtx 직결로 정합↑(회귀 0). */}
        <EditorModalRoot />
      </LayoutDocumentProvider>
    </div>
  );
}

/**
 * RouteTreeNode 평면 탐색 — path 매칭. dynamic segment(:param) 지원.
 *
 * 본 매처는 보수적: 정적 path 가 트리에 있으면 매칭, 동적 path 는 패턴 ↔ 패턴
 * 비교만 (호출자가 dynamic_path 분류로 사전 차단). 본 함수는 외부 호출 없이
 * LayoutEditorChromeBody 내부에서만 사용.
 */
function findRouteNodeByPath(
  tree: import('./LayoutEditorContext').RouteTreeNode[],
  path: string,
): import('./LayoutEditorContext').RouteTreeNode | null {
  for (const node of tree) {
    if (node.kind === 'route' && node.path === path) return node;
    if (node.children && node.children.length > 0) {
      const found = findRouteNodeByPath(node.children, path);
      if (found) return found;
    }
  }
  return null;
}

function matchPathInTree(
  tree: import('./LayoutEditorContext').RouteTreeNode[],
  path: string,
): boolean {
  return findRouteNodeByPath(tree, path) !== null;
}

/**
 * RouteTreeNode 평면 탐색 — layoutName 매칭(라우트 노드만). 확장 편집 모드의 호스트
 * layoutName(예: `board/form`)으로부터 그 호스트의 라우트 path 를 도출하는 데 쓴다
 * 못 찾으면 null.
 */
function findRoutePathByLayoutName(
  tree: import('./LayoutEditorContext').RouteTreeNode[],
  layoutName: string,
): string | null {
  for (const node of tree) {
    if (node.kind === 'route' && node.layoutName === layoutName && typeof node.path === 'string') {
      return node.path;
    }
    if (node.children && node.children.length > 0) {
      const found = findRoutePathByLayoutName(node.children, layoutName);
      if (found) return found;
    }
  }
  return null;
}

/**
 * editor-spec 병합 + sampleGlobal 체인 결과를 devtools 에 적재.
 *
 * 충돌 경로는 비-guest dry-run 으로 산출한다(guest 분기는 currentUser 만 제외하므로
 * 충돌 집합의 상위 집합 — 진단 목적엔 충분). devtools 비활성 시 no-op.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자
 * @param spec 병합 EditorSpec (또는 null)
 * @param sources sampleGlobal 소스 목록
 */
function emitSpecMergeTracker(
  templateIdentifier: string,
  spec: EditorSpec | null,
  sources: SampleGlobalSource[],
): void {
  const conflicts: string[] = [];
  // dry-run — 코어 우선 충돌을 수집 (warn 콜백 주입). 시드 결과는 사용하지 않는다.
  buildSampleGlobalSeed({
    sources,
    isGuestOnly: false,
    warn: (message) => {
      // 메시지 형식: "...extension '<id>' override of core key '<path>' ignored"
      const m = /extension '([^']+)' override of core key '([^']+)'/.exec(message);
      if (m) conflicts.push(`${m[1]}:${m[2]}`);
    },
  });

  const sampleDataIds = spec?.sampleData?.byDataSourceId
    ? Object.keys(spec.sampleData.byDataSourceId).length
    : 0;

  trackEditorSpecMerge({
    templateIdentifier,
    mergedSources: [
      ...sources.map((s) => ({ kind: s.kind, id: s.id })),
    ],
    blockCounts: {
      controls: spec?.controls ? Object.keys(spec.controls).length : 0,
      componentCapabilities: spec?.componentCapabilities
        ? Object.keys(spec.componentCapabilities).length
        : 0,
      actionRecipes: spec?.actionRecipes ? Object.keys(spec.actionRecipes).length : 0,
      conditionRecipes: spec?.conditionRecipes ? Object.keys(spec.conditionRecipes).length : 0,
      paletteGroups: spec?.componentPalette?.groups?.length ?? 0,
      stateGroups: spec?.states?.groups?.length ?? 0,
      sampleDataIds,
    },
    sampleGlobalSourceCount: sources.length,
    sampleGlobalConflicts: conflicts,
    timestamp: Date.now(),
  });
}

export function LayoutEditorChrome({
  templateIdentifier,
  initialLocale,
}: LayoutEditorChromeProps): React.ReactElement {
  // 셸 언마운트 시 devtools 의 editor-state / editor-sample-data 데이터 정리 —
  // 노드 메타·샘플 매칭 기록 미누수 회귀 가드 
  useEffect(() => {
    return () => {
      // Phase 1/2 트래커 + Phase 3 S5a-2 신규 트래커 4개 — 노드 메타·이력 미누수
      // 회귀 가드 
      clearEditorStateData();
      clearEditorSampleData();
      clearEditorHistoryData();
      clearEditorSelectionData();
      clearEditorDndData();
      clearEditorDocumentData();
      clearEditorSpecMergeData();
      clearEditorI18nData();
      clearPageStateData();
    };
  }, []);

  return (
    <LayoutEditorProvider templateIdentifier={templateIdentifier} initialLocale={initialLocale}>
      <EditorModalProvider>
        {/* EditorModalRoot 는 Body 내부 LayoutDocumentProvider 안에 마운트(single mount per
            editor tree). 모달 content 가 라이브 문서 컨텍스트를 읽도록 provider 안으로 이동(D-ROOT). */}
        <LayoutEditorChromeBody templateIdentifier={templateIdentifier} />
      </EditorModalProvider>
    </LayoutEditorProvider>
  );
}
