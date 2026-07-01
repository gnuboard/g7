/**
 * PageSettingsModal.tsx — 페이지 설정 모달 공통 셸
 *
 * 편집기 툴바 ⚙페이지 설정(또는 제목 배너 ⚙)으로 열리는 8탭 모달의 셸. 각 탭 폼
 * (MetaForm/SeoForm/InitActionsForm/TransitionOverlayForm/ComputedForm/InitialStateForm/
 * ErrorHandlingForm/DataSourceTab)을 마운트하고, 공통 인프라(usePageSettings + 후보 풀)를
 * 도출해 prop 으로 흘린다. **저장 버튼 없음** — 패치는 즉시 patchDocumentRaw(dirty), 영속은
 * 툴바 💾 가 일임(속성 모달과 동일 라이브 패치).
 *
 * prop 주도 — LayoutEditorChrome 가 자기 로컬 state(editorSpec/permissionCandidates/
 * templateIdentifier/templateType)를 주입한다. 셸은 Provider 트리 안(EditorModalRoot)이라
 * usePageSettings/useBindingCandidates/useSeoBindingCandidates 를 직접 호출한다.
 *
 * 후보 풀 SSoT(핸드오프 확정):
 *  - page/dataSource/stateKey/modal = spec/candidatePools.ts
 *  - binding = useBindingCandidates({raw,spec,evaluateComputed})
 *  - SEO binding = useSeoBindingCandidates({raw,spec,pageType})
 *  - permission = LayoutEditorChrome permissionCandidates(prop)
 *  - SEO extensions = seo-candidates.json `extensions`(D-4, 본 셸이 fetch 후 SeoForm 결선)
 *  - 컴퓨티드 출처맵 = raw.__computedSource(백엔드 buildComputedSourceMap, D-2a)
 *
 * 탭별 고급 개수 배지 = 그 탭의 레시피 미환원 항목 수(resolveActionCard/resolveComputedCard
 * 가 'advanced' 로 판정한 항목). N>0 일 때만 배지, 전체 합계>0 일 때만 요약 줄.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import type { EditorSpec } from '../../spec/specTypes';
import { usePageSettings } from '../../hooks/usePageSettings';
import { useBindingCandidates, buildPageSampleContext } from '../../hooks/useBindingCandidates';
import { useSeoBindingCandidates } from '../../hooks/useSeoBindingCandidates';
import { useLayoutEditor } from '../../LayoutEditorContext';
import { buildAuthHeaders } from '../../utils/authToken';
import {
  buildPageCandidates,
  buildDataSourceCandidates,
  buildStateKeyCandidates,
  buildModalCandidates,
  buildDataSourceOptions,
  type ValueLabelCandidate,
  type LabelResolver,
} from '../../spec/candidatePools';
import {
  getInitActionRecipes,
  getComputedRecipes,
  getErrorRecipes,
  getLoadingComponents,
} from '../../spec/editorSpecLoader';
import { normalizeActionRecipes, resolveActionCard } from '../../spec/actionRecipeEngine';
import { normalizeComputedRecipes, resolveComputedCard } from '../../spec/computedRecipeEngine';
import { MetaForm } from './MetaForm';
import { SeoForm, type SeoExtensionRef } from './SeoForm';
import { InitActionsForm } from './InitActionsForm';
import { TransitionOverlayForm } from './TransitionOverlayForm';
import { ComputedForm } from './ComputedForm';
import { InitialStateForm } from './InitialStateForm';
import { ErrorHandlingForm } from './ErrorHandlingForm';
import { DataSourceTab } from './DataSourceTab';

/** 8탭 키 — 헤더 와이어프레임 순서 */
export type PageSettingsTabKey =
  | 'meta'
  | 'seo'
  | 'init'
  | 'overlay'
  | 'computed'
  | 'state'
  | 'error'
  | 'data';

const TAB_ORDER: PageSettingsTabKey[] = [
  'meta',
  'seo',
  'init',
  'overlay',
  'computed',
  'state',
  'error',
  'data',
];

/**
 * [에러 처리] 탭에 항상 노출하는 표준 HTTP 에러 코드(D-N). ErrorHandlingForm.buildRowCodes 가
 * 여기에 `default` 행을 덧붙인다. 레이아웃이 추가로 선언한 코드는 templateCodes 로 합쳐진다.
 */
const STANDARD_ERROR_CODES = ['401', '403', '404', '500', '503'];

export interface PageSettingsModalProps {
  /** 편집 대상 템플릿 식별자(SEO 미리보기·extensions fetch) */
  templateIdentifier: string;
  /** 병합 editor-spec — 후보 풀/레시피 도출 */
  spec: EditorSpec | null;
  /** 다국어 해석 t (LayoutEditorChrome useTranslation) */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** `$t:` 키 친화 해석(editorAwareT) — page 후보 라벨 */
  resolveLabel: LabelResolver;
  /** 권한 키 후보(LayoutEditorChrome state) */
  permissionCandidates?: ValueLabelCandidate[];
  /** 모달 닫기 — modal.close(id) */
  onClose: () => void;
  /** 초기 활성 탭(보조 진입점에서 특정 탭 직접 열기). 기본 meta */
  initialTab?: PageSettingsTabKey;
  /** SEO extensions fetch 주입(테스트). 미전달 시 내부 Bearer fetch */
  extensionsFetcher?: (templateIdentifier: string) => Promise<SeoExtensionRef[]>;
}

/** 탭 라벨 키(약칭 — 8탭 한 줄) */
const TAB_LABEL_KEY: Record<PageSettingsTabKey, string> = {
  meta: 'layout_editor.page_settings.tab.meta',
  seo: 'layout_editor.page_settings.tab.seo',
  init: 'layout_editor.page_settings.tab.init',
  overlay: 'layout_editor.page_settings.tab.overlay',
  computed: 'layout_editor.page_settings.tab.computed',
  state: 'layout_editor.page_settings.tab.state',
  error: 'layout_editor.page_settings.tab.error',
  data: 'layout_editor.page_settings.tab.data',
};

/** 활성 모듈/플러그인 extensions 후보를 seo-candidates.json 에서 fetch(내부 Bearer) */
async function fetchSeoExtensions(templateIdentifier: string): Promise<SeoExtensionRef[]> {
  if (typeof fetch !== 'function' || !templateIdentifier) return [];
  try {
    const response = await fetch(
      `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/editor/seo-candidates.json`,
      { credentials: 'same-origin', headers: buildAuthHeaders() },
    );
    if (!response.ok) return [];
    const body = await response.json().catch(() => null);
    const list = body?.data?.extensions;
    if (!Array.isArray(list)) return [];
    const out: SeoExtensionRef[] = [];
    for (const item of list) {
      if (item && typeof item === 'object') {
        const type = (item as { type?: unknown }).type;
        const id = (item as { id?: unknown }).id;
        const lbl = (item as { label?: unknown }).label;
        if (typeof type === 'string' && typeof id === 'string' && id.length > 0) {
          out.push({ type, id, name: typeof lbl === 'string' ? lbl : id });
        }
      }
    }
    return out;
  } catch {
    return [];
  }
}

/** init_actions 미환원(고급) 항목 수 — resolveActionCard 'advanced' */
function countAdvancedActions(
  actions: Array<Record<string, unknown>>,
  recipes: ReturnType<typeof normalizeActionRecipes>,
): number {
  let n = 0;
  for (const raw of actions) {
    if (resolveActionCard(raw, recipes).kind === 'advanced') n += 1;
  }
  return n;
}

/** computed 미환원(고급) 키 수 — resolveComputedCard 'advanced' */
function countAdvancedComputed(
  computed: Record<string, string>,
  recipes: ReturnType<typeof normalizeComputedRecipes>,
): number {
  let n = 0;
  for (const key of Object.keys(computed)) {
    if (resolveComputedCard(computed[key], recipes).kind === 'advanced') n += 1;
  }
  return n;
}

/** 편집 대상 표시 이름 — meta.title 해석값 ?? editor_label ?? path */
function resolveTargetName(
  getValue: ReturnType<typeof usePageSettings>['getValue'],
  selectedPath: string,
  t: PageSettingsModalProps['t'],
): string {
  const meta = getValue<Record<string, unknown>>('meta', {}) ?? {};
  const title = meta.title;
  if (typeof title === 'string' && title.length > 0) {
    if (title.startsWith('$t:')) {
      const resolved = t(title.slice(3));
      if (resolved && resolved !== title.slice(3)) return resolved;
    } else if (!/\{\{[\s\S]*\}\}/.test(title)) {
      return title;
    }
  }
  const editorLabel = meta.editor_label;
  if (typeof editorLabel === 'string' && editorLabel.length > 0) {
    return editorLabel.startsWith('$t:') ? t(editorLabel.slice(3)) : editorLabel;
  }
  return selectedPath || '';
}

/**
 * 페이지 설정 모달 셸.
 *
 * @param props PageSettingsModalProps
 * @return 모달 본체 엘리먼트
 */
export function PageSettingsModal({
  templateIdentifier,
  spec,
  t,
  resolveLabel,
  permissionCandidates,
  onClose,
  initialTab = 'meta',
  extensionsFetcher,
}: PageSettingsModalProps): React.ReactElement {
  const { state } = useLayoutEditor();
  const { raw, getValue, patch } = usePageSettings();
  const [activeTab, setActiveTab] = useState<PageSettingsTabKey>(initialTab);

  const selectedPath = state.selectedRoute?.path ?? '';

  // 후보 풀(SSoT) — candidatePools 4종 + binding 2종.
  const pageCandidates = useMemo(
    () => buildPageCandidates(state.routeTree, resolveLabel),
    [state.routeTree, resolveLabel],
  );
  const dataSourceCandidates = useMemo(() => buildDataSourceCandidates(raw), [raw]);
  const stateKeyCandidates = useMemo(() => buildStateKeyCandidates(spec), [spec]);
  // 모달 후보 라벨은 좌측 라우트 트리 [모달] 그룹과 동일 친화 명칭(meta.editor_label → title → id).
  // resolveLabel(편집 대상 사전 우선 → chrome 폴백)로 `$t:` 키를 사용자 언어로 해석.
  const modalCandidates = useMemo(() => buildModalCandidates(raw, resolveLabel), [raw, resolveLabel]);
  const bindingCandidates = useBindingCandidates({ raw, spec });

  // 레시피 맵.
  const initRecipes = useMemo(() => getInitActionRecipes(spec), [spec]);
  const computedRecipes = useMemo(() => getComputedRecipes(spec), [spec]);
  const errorRecipes = useMemo(() => getErrorRecipes(spec), [spec]);
  const loadingComponents = useMemo(() => getLoadingComponents(spec), [spec]);

  // 미환원(고급) 카운트용 정규화.
  const normalizedInitRecipes = useMemo(() => normalizeActionRecipes(initRecipes), [initRecipes]);
  const normalizedComputedRecipes = useMemo(
    () => normalizeComputedRecipes(computedRecipes),
    [computedRecipes],
  );

  // 현재 최상위 값.
  // init_actions 키 표기 불일치 흡수 — 서버 레이아웃 응답은 병합된 액션을 `initActions`
  // (camelCase)로 출력하나(LayoutService.php:205), 페이지 설정 저장(patch)·서버 저장 검증
  // (UpdateLayoutContentRequest content.init_actions)은 `init_actions`(snake_case)다. snake
  // (이번 세션 패치값) 우선 → camel(서버 응답값) 폴백으로 둘 다 읽는다. 종전 snake 단독
  // 조회는 새 로드 시 서버 camel 응답을 못 읽어 [화면 동작] 탭이 항상 빈 목록으로 보였고,
  // 동작을 추가·저장하면 기존 init_actions 를 통째로 덮어써 삭제하던 결함.
  const initActions =
    getValue<Array<Record<string, unknown>> | undefined>('init_actions', undefined) ??
    getValue<Array<Record<string, unknown>>>('initActions', []);
  const computed = getValue<Record<string, string>>('computed', {});
  const computedSource = getValue<Record<string, string>>('__computedSource', {});
  const errorHandling = getValue<Record<string, unknown>>('errorHandling', {});
  const transitionOverlay = getValue('transition_overlay', undefined);

  // 자기 선언분(__editor.original) — InitialStateForm own / ErrorHandlingForm ownCodes.
  const editorOriginal = (getValue<Record<string, unknown>>('__editor', {}) ?? {}).original as
    | Record<string, unknown>
    | undefined;

  // [로딩 화면] 상속 표기 — 병합본(transitionOverlay) − own(__editor.original.transition_overlay)
  // = base 상속 키. 종전엔 셸이 baseValue 를 안 넘겨 〔상속됨〕·[이 화면만 바꾸기] 가 화면에
  // 전혀 안 떴다(부모 base 가 transition_overlay 를 정의해도 미표시).
  const overlayOwn = useMemo<Record<string, unknown> | undefined>(() => {
    // 편집 모드 own 출처(__editor.original)가 아예 없으면 undefined(독립 RTL 폴백 — 병합본을 own 으로).
    // 있으면 transition_overlay 키 부재 = "이 화면은 로딩 화면을 직접 선언 안 함" → 빈 객체로(전부 상속).
    // 종전엔 부재 시 undefined 를 흘려 폼이 병합본(value)을 own 으로 폴백 → 상속 키가 〔재정의〕로 오판.
    if (editorOriginal === undefined) return undefined;
    const own = editorOriginal.transition_overlay;
    return own && typeof own === 'object' && !Array.isArray(own) ? (own as Record<string, unknown>) : {};
  }, [editorOriginal]);
  const overlayBase = useMemo<Record<string, unknown> | undefined>(() => {
    const merged = transitionOverlay;
    if (!merged || typeof merged !== 'object' || Array.isArray(merged)) return undefined;
    const own = overlayOwn ?? {};
    // 병합본에는 있으나 own 에는 없는 키 = base 상속. 그 키의 base 값 = 병합본 값(자식 미덮음).
    const base: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(merged as Record<string, unknown>)) {
      if (!(k in own)) base[k] = v;
    }
    return Object.keys(base).length > 0 ? base : undefined;
  }, [transitionOverlay, overlayOwn]);

  // SEO extensions 후보.
  const [seoExtensions, setSeoExtensions] = useState<SeoExtensionRef[]>([]);
  useEffect(() => {
    let cancelled = false;
    const fetcher = extensionsFetcher ?? fetchSeoExtensions;
    (async () => {
      const list = await fetcher(templateIdentifier);
      if (!cancelled) setSeoExtensions(list);
    })();
    return () => {
      cancelled = true;
    };
  }, [templateIdentifier, extensionsFetcher]);

  const pageType =
    ((getValue<Record<string, unknown>>('meta', {}) ?? {}).seo as Record<string, unknown> | undefined)
      ?.page_type;
  const seoCandidates = useSeoBindingCandidates({
    raw,
    spec,
    pageType: typeof pageType === 'string' ? pageType : null,
  });

  // [자동 계산] 미리보기 평가 컨텍스트 — 후보 풀과 같은 입력에서 도출(불일치 0).
  const sampleContext = useMemo(() => buildPageSampleContext(raw, spec), [raw, spec]);

  // 탭별 고급 개수.
  const advancedCount = useMemo<Record<PageSettingsTabKey, number>>(() => {
    const counts: Record<PageSettingsTabKey, number> = {
      meta: 0,
      seo: 0,
      init: countAdvancedActions(initActions, normalizedInitRecipes),
      overlay: 0,
      computed: countAdvancedComputed(computed, normalizedComputedRecipes),
      state: 0,
      error: 0,
      data: 0,
    };
    return counts;
  }, [initActions, normalizedInitRecipes, computed, normalizedComputedRecipes]);

  const advancedTotal = useMemo(
    () => Object.values(advancedCount).reduce((a, b) => a + b, 0),
    [advancedCount],
  );

  // 멀티선택(체크박스 칩) 옵션 — id + 친화명(label_key 해석) + 확장 출처 배지. [로딩 화면]
  // wait_for·[검색엔진] SEO 연동 데이터가 [데이터] 탭과 같은 표기를 쓰도록 enriched 옵션을 흘린다.
  const dataSourceOptions = useMemo(
    () => buildDataSourceOptions(raw, resolveLabel, t),
    [raw, resolveLabel, t],
  );

  // computed 되돌리기 — 'route-override' 키의 자식 정의만 제거(부모 값 재노출).
  // __editor.original.computed 에서 그 키만 빼고 병합본은 부모 식으로 복원해야 하지만, 셸은
  // 자식 original 만 갱신 → patchDocumentRaw 가 raw.computed(병합본) 도 함께 줄이므로, 부모
  // 식 복원은 reload(서버 재병합)에 위임할 수 없다(라이브). 대신 부모 식을 computedSource 가
  // 'route-override' 로 표기한 키에 대해 raw 병합본에서 자식만 제거하고 onChange 로 patch.
  const handleComputedRevert = useCallback(
    (key: string): void => {
      // 자식 선언분 제거.
      const ownComputed = (editorOriginal?.computed as Record<string, string> | undefined) ?? {};
      const nextOwn = { ...ownComputed };
      delete nextOwn[key];
      // 병합본에서도 자식 식 제거 → 부모 식이 남아 있으면 부모 값 재노출. 부모 식은 raw 의
      // computed 에 자식이 덮기 전 값이 없으므로, 자식 제거 후 병합본에서 그 키를 제거하면
      // 다음 reload 시 부모가 재병합된다. 라이브 즉시 복원을 위해 병합본에서 키 제거.
      const merged = { ...computed };
      delete merged[key];
      patch('computed', merged, Object.keys(nextOwn).length === 0 ? undefined : nextOwn);
    },
    [editorOriginal, computed, patch],
  );

  const targetName = resolveTargetName(getValue, selectedPath, t);

  const renderPanel = (): React.ReactElement => {
    switch (activeTab) {
      case 'meta':
        return (
          <MetaForm
            getValue={getValue}
            patch={patch}
            t={t}
            // 값 필드(제목/설명)는 런타임 앱 lang 키(`$t:board.edit_post` 등)를 해석해야 한다 —
            // 편집기 전용 t 는 `layout_editor.*` 만 알아 분기 키를 못 풀어 칩 추가 시 텍스트가
            // 빈 base 로 소실됐다. resolveLabel(editorAwareT, G7Core.t
            // 폴백)이 편집기·앱 키 모두 해석하므로 값 필드 t 로 주입한다.
            fieldT={resolveLabel}
            spec={spec}
            permissionCandidates={permissionCandidates}
            bindingCandidates={bindingCandidates}
          />
        );
      case 'seo':
        return (
          <SeoForm
            getValue={getValue}
            patch={patch}
            t={t}
            templateIdentifier={templateIdentifier}
            candidates={seoCandidates}
            availableExtensions={seoExtensions}
            dataSourceOptions={dataSourceOptions}
            previewUrl={selectedPath || '/'}
            // 편집기 샘플 컨텍스트(data_source 샘플 응답 + 상태 트리)를 SEO 미리보기에 흘린다.
            // 종전엔 미전달이라 og/구조화/봇 미리보기가 seed_context:{} 로 호출돼, 모듈
            // seoOgDefaults/seoStructuredData($pageType, $context=[]) 가 샘플 상품 없이 빈 결과를
            // 돌려줬다(구조화 자동 미리보기 "데이터 없음" 결함). buildPageSampleContext 가 상품 샘플을 채운다.
            seedContext={sampleContext}
          />
        );
      case 'init':
        return (
          <InitActionsForm
            actions={initActions}
            onChange={(next) => patch('init_actions', next)}
            recipes={initRecipes}
            // 동작 레시피 라벨(`$t:editor.action.*`)·param 값 키(`$t:board.*`)는 편집 대상
            // 템플릿 사전 키라 chrome t(admin 컨텍스트)로는 미해석 → raw 키 노출. resolveLabel
            // (editorAwareT: 편집 대상 우선 → chrome 폴백)이 편집기·앱·코어 키를 모두 해석한다
            // (computed/error/overlay 탭과 동형).
            t={resolveLabel}
            bindingCandidates={bindingCandidates}
            pageCandidates={pageCandidates}
            dataSourceCandidates={dataSourceCandidates}
            stateKeyCandidates={stateKeyCandidates}
            modalCandidates={modalCandidates}
          />
        );
      case 'overlay':
        return (
          <TransitionOverlayForm
            value={transitionOverlay as never}
            ownValue={overlayOwn}
            baseValue={overlayBase}
            patch={(next) => patch('transition_overlay', next)}
            // loadingComponents 라벨(`$t:editor.*`)은 편집 대상 템플릿 사전 키 — resolveLabel
            // (편집 대상 우선 → chrome 폴백)로 해석(다른 레시피 탭과 동형).
            t={resolveLabel}
            loadingComponents={loadingComponents}
            progressiveDataSources={dataSourceOptions}
            bindingCandidates={bindingCandidates}
          />
        );
      case 'computed':
        return (
          <ComputedForm
            computed={computed}
            onChange={(next) => patch('computed', next)}
            recipes={computedRecipes}
            // 프리셋 라벨(`$t:editor.computed.*`)은 편집 대상 템플릿 소유 키 — chrome t(admin
            // 컨텍스트)로는 호스트 사전에 없는 basic 전용 프리셋(first_of/group_items 등)이 raw
            // 키로 노출됐다. resolveLabel(편집 대상 우선 → chrome 폴백)이
            // 편집기·코어 키를 모두 해석한다.
            t={resolveLabel}
            sampleContext={sampleContext}
            dataSourceCandidates={dataSourceCandidates}
            bindingCandidates={bindingCandidates}
            computedSource={computedSource}
            onRevert={handleComputedRevert}
          />
        );
      case 'state':
        return (
          <InitialStateForm
            raw={raw}
            own={
              editorOriginal
                ? {
                    initLocal: editorOriginal.initLocal as Record<string, unknown> | undefined,
                    initGlobal: editorOriginal.initGlobal as Record<string, unknown> | undefined,
                    initIsolated: editorOriginal.initIsolated as Record<string, unknown> | undefined,
                    // legacy state(initLocal 옛 이름) 자기 선언분 — 누락 시 legacy state 화면의
                    // 자기 키가 부모 상속(🔗)으로 오분류되고 정규화 안내도 안 뜬다.
                    state: editorOriginal.state as Record<string, unknown> | undefined,
                  }
                : undefined
            }
            patch={patch}
            t={t}
          />
        );
      case 'error':
        return (
          <ErrorHandlingForm
            value={errorHandling as never}
            onChange={(next) => patch('errorHandling', next)}
            // 에러 동작 레시피 라벨(`$t:editor.error.*`)·param 값 키는 편집 대상 템플릿 사전
            // 키 — resolveLabel(편집 대상 우선 → chrome 폴백)로 해석(다른 레시피 탭과 동형,
            // ).
            t={resolveLabel}
            // 표준 HTTP 에러 코드 행을 항상 노출(D-N). 종전엔 errorConfigCodes 미전달로
            // buildRowCodes 가 ['default'] 만 만들어 401/403/404/500/503 행이 없었다.
            errorConfigCodes={STANDARD_ERROR_CODES}
            // 이미 errorHandling 에 선언된 코드도 행으로 노출(병합본 키 합집합).
            templateCodes={Object.keys(errorHandling)}
            ownCodes={Object.keys((editorOriginal?.errorHandling as Record<string, unknown>) ?? {})}
            recipes={errorRecipes}
            pageCandidates={pageCandidates}
            modalCandidates={modalCandidates}
            dataSourceCandidates={dataSourceCandidates}
            stateKeyCandidates={stateKeyCandidates}
          />
        );
      case 'data':
        return (
          <DataSourceTab
            raw={raw}
            t={t}
            resolveLabel={resolveLabel}
            onChange={(merged, own) => patch('data_sources', merged, own)}
            onClose={onClose}
            // 동작(onSuccess/onError/onReceive) 친화 레시피 = 화면 동작 탭과 동일 init 핸들러 스펙.
            // 에러 동작 레시피 = 에러처리 탭과 동일. 불러오기 조건(if) 빌더 spec = 표시조건과 동일.
            // 종전엔 이 셋이 미전달이라 동작 추가 picker 가 비고, 불러오기 조건이 항상 "표시 조건 없음"
            // 안내로 가렸다(③ 결함). 데이터칩 후보 풀도 흘려 params/fallback·동작 param 칩 해석.
            actionRecipes={initRecipes}
            errorRecipes={errorRecipes}
            conditionSpec={spec}
            bindingCandidates={bindingCandidates}
            pageCandidates={pageCandidates}
            dataSourceCandidates={dataSourceCandidates}
            stateKeyCandidates={stateKeyCandidates}
          />
        );
      default:
        return <div />;
    }
  };

  return (
    <div className="g7le-page-settings" data-testid="g7le-page-settings" style={shell}>
      <div style={headerRow}>
        <span data-testid="g7le-page-settings-title" style={modalTitle}>
          {t('layout_editor.page_settings.title', { name: targetName })}
        </span>
        <button
          type="button"
          data-testid="g7le-page-settings-close-x"
          onClick={onClose}
          aria-label={t('layout_editor.page_settings.close')}
          style={closeXBtn}
        >
          ✕
        </button>
      </div>

      <div role="tablist" style={tabRow}>
        {TAB_ORDER.map((key) => {
          const n = advancedCount[key];
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={activeTab === key}
              data-testid={`g7le-page-settings-tab-${key}`}
              data-active={activeTab === key ? 'true' : 'false'}
              onClick={() => setActiveTab(key)}
              style={activeTab === key ? tabBtnActive : tabBtn}
            >
              {t(TAB_LABEL_KEY[key])}
              {n > 0 ? (
                <span data-testid={`g7le-page-settings-tab-badge-${key}`} style={tabBadge}>
                  ({n})
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      {advancedTotal > 0 ? (
        <p data-testid="g7le-page-settings-advanced-summary" style={advancedSummary}>
          ⚠ {t('layout_editor.page_settings.advanced_summary', { count: advancedTotal })} ⓘ
        </p>
      ) : null}

      <div data-testid="g7le-page-settings-panel" style={panel}>
        {renderPanel()}
      </div>

      <div style={footerRow}>
        <button type="button" data-testid="g7le-page-settings-close" onClick={onClose} style={closeBtn}>
          {t('layout_editor.page_settings.close')}
        </button>
      </div>
    </div>
  );
}

const shell: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  minWidth: 0,
  maxWidth: '100%',
  // 모달 프레임(g7le-modal)은 padding 0 이라 셸이 자체 여백을 갖는다(D-A — 조악/여백 부재 정정).
  // 좌우 24 / 상하 20 으로 헤더·탭·본문·푸터가 모달 가장자리에 붙지 않게 한다.
  padding: '20px 24px',
  boxSizing: 'border-box',
  // 모달 프레임(flex column, maxHeight, overflow:hidden)의 자식으로서 프레임 높이를 넘지 않게
  // flex:1 + minHeight:0 으로 바운드 → 본문(panel)의 overflowY:auto 가 실제 스크롤되게 한다.
  // (height:100% 단독은 콘텐츠 높이로 부풀어 모달 밖으로 넘쳐 스크롤 부재 — D-K 회귀 정정.)
  flex: 1,
  minHeight: 0,
  maxHeight: '100%',
  overflow: 'hidden',
  fontFamily: 'system-ui, -apple-system, sans-serif',
};
const headerRow: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  paddingBottom: 8,
  borderBottom: '1px solid #e2e8f0',
};
const modalTitle: React.CSSProperties = { flex: 1, fontSize: 15, fontWeight: 700, color: '#0f172a', minWidth: 0 };
const closeXBtn: React.CSSProperties = {
  border: 'none',
  background: 'transparent',
  color: '#64748b',
  cursor: 'pointer',
  fontSize: 16,
  lineHeight: 1,
  padding: 4,
};
const tabRow: React.CSSProperties = {
  display: 'flex',
  gap: 2,
  paddingTop: 8,
  borderBottom: '1px solid #e2e8f0',
  flexWrap: 'nowrap',
  // 가로 넘침만 스크롤. overflowX:auto 단독이면 CSS 명세상 overflowY 가 auto 로 컴퓨트돼
  // 탭 버튼이 컨테이너보다 1~2px 크면 세로 스크롤바(▲▼)가 뜨고, 그 스크롤로 탭바가 위로
  // 밀려 사라진다. overflowY:hidden 명시로 차단.
  overflowX: 'auto',
  overflowY: 'hidden',
  flexShrink: 0,
  minWidth: 0,
};
// 탭 밑줄은 longhand(borderBottomWidth/Style/Color)로만 둔다 — shorthand(borderBottom)와
// longhand 를 섞으면 활성→비활성 전환 시 React 가 직전 인라인 borderBottomColor 를 못 지워
// 비활성 탭에 색 잔존(검은 줄) 회귀가 난다(D-H). 활성/비활성이 같은 longhand 키만
// 덮어쓰므로 잔존이 없다.
const tabBtn: React.CSSProperties = {
  border: 'none',
  borderBottomWidth: '2px',
  borderBottomStyle: 'solid',
  borderBottomColor: 'transparent',
  background: 'transparent',
  color: '#64748b',
  cursor: 'pointer',
  fontSize: 12,
  padding: '6px 10px',
  whiteSpace: 'nowrap',
};
const tabBtnActive: React.CSSProperties = {
  ...tabBtn,
  color: '#2563eb',
  borderBottomColor: '#2563eb',
  fontWeight: 600,
};
const tabBadge: React.CSSProperties = { marginLeft: 4, fontSize: 10, color: '#b45309' };
const advancedSummary: React.CSSProperties = {
  margin: 0,
  marginTop: 8,
  fontSize: 11,
  color: '#b45309',
  background: '#fffbeb',
  padding: '6px 8px',
  borderRadius: 6,
};
const panel: React.CSSProperties = {
  flex: 1,
  overflowY: 'auto',
  paddingTop: 12,
  minWidth: 0,
  // flex column 안에서 자식이 실제 스크롤되려면 minHeight:0 필요(없으면 콘텐츠 높이로 늘어나 스크롤 부재 — D-K).
  minHeight: 0,
};
const footerRow: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'flex-end',
  paddingTop: 8,
  borderTop: '1px solid #e2e8f0',
};
const closeBtn: React.CSSProperties = {
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  background: '#fff',
  color: '#475569',
  cursor: 'pointer',
  fontSize: 13,
  padding: '6px 16px',
};
