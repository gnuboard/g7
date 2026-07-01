/**
 * candidatePools.ts — 편집기 후보 풀 도출 순수 함수
 *
 * 페이지 설정 모달(셸)과 캔버스 오버레이(EditorCanvasOverlay)가 **같은 후보 풀**(page/
 * dataSource/stateKey/modal 후보)을 쓴다. 종전엔 EditorCanvasOverlay 내부 useMemo 에
 * 인라인돼 있어 별도 modal 컨텍스트인 셸이 재사용할 수 없었다. 도출 로직을 순수 함수로
 * 추출해 양쪽이 공유한다(명세 1곳 — 캔버스/모달 후보 불일치 0).
 *
 * **회귀 0 제약**: EditorCanvasOverlay 의 기존 useMemo 입출력과 동치여야 한다(캔버스 동작
 * 무변경). 단위 테스트(candidatePools.test.ts)가 입출력을 고정한다.
 *
 * @since engine-v1.50.0
 */

import type { RouteTreeNode } from '../LayoutEditorContext';
import type { EditorSpec } from './specTypes';

/** value/label 한 쌍 — page-picker/datasource-picker/state-key-picker 선택지 */
export interface ValueLabelCandidate {
  value: string;
  label: string;
}

/** `$t:` 키를 친화 명칭으로 해석하는 함수(미해석 시 키 원문 반환) */
export type LabelResolver = (key: string, params?: Record<string, string | number>) => string;

/**
 * 페이지(라우트) 후보 — 라우트 트리에서 실제 라우트만(base/modal/extension 가상 노드·
 * 리다이렉트 제외) 평탄 수집. 라벨은 편집 대상 템플릿 사전 키(`$t:user.*` 등)를
 * `resolveLabel` 로 해석하되, 해석 실패(키 원문/`$t:` 잔존) 시 라벨 없이 path 만 노출
 * (키 노출 회피 — path 는 항상 보여 모호성 없음). EditorCanvasOverlay pageCandidates 와 동치.
 *
 * @param routeTree 편집기 라우트 트리(중첩)
 * @param resolveLabel `$t:` 키 해석(editorAwareT)
 * @return value=path, label=`친화명 (path)` 또는 path
 */
export function buildPageCandidates(
  routeTree: RouteTreeNode[] | undefined,
  resolveLabel: LabelResolver,
): ValueLabelCandidate[] {
  const out: ValueLabelCandidate[] = [];
  const seen = new Set<string>();
  const walk = (nodes: RouteTreeNode[] | undefined): void => {
    for (const n of nodes ?? []) {
      if (
        n.kind === 'route' &&
        !n.isRedirect &&
        typeof n.path === 'string' &&
        n.path.startsWith('/') &&
        !seen.has(n.path)
      ) {
        seen.add(n.path);
        const raw = n.label.startsWith('$t:') ? n.label.slice(3) : n.label;
        const resolved = n.label.startsWith('$t:') ? resolveLabel(raw) : raw;
        const friendly = resolved && resolved !== raw && !resolved.startsWith('$t:') ? resolved : '';
        out.push({ value: n.path, label: friendly ? `${friendly} (${n.path})` : n.path });
      }
      walk(n.children);
    }
  };
  walk(routeTree);
  return out;
}

/**
 * 데이터소스 후보 — 레이아웃 raw.data_sources 의 각 항목 id. EditorCanvasOverlay
 * dataSourceCandidates 와 동치(value=label=id).
 *
 * @param raw 편집 중 레이아웃 raw(병합본)
 * @return {value:id, label:id}[]
 */
export function buildDataSourceCandidates(
  raw: Record<string, unknown> | undefined | null,
): ValueLabelCandidate[] {
  const list = Array.isArray((raw as { data_sources?: unknown } | undefined)?.data_sources)
    ? ((raw as { data_sources?: unknown[] }).data_sources as Array<Record<string, unknown>>)
    : [];
  const out: ValueLabelCandidate[] = [];
  for (const ds of list) {
    const id = ds?.id;
    if (typeof id === 'string' && id.length > 0) out.push({ value: id, label: id });
  }
  return out;
}

/**
 * data_source 의 `label_key`($t: 키)를 친화 명칭으로 해석한다. 미해석/미지정 시 null.
 * label_key 는 `$t:editor.data_source.<id>` 형태 — `$t:` 접두를 떼고 resolve 한다.
 * [데이터] 탭(DataSourcesPanel)·멀티선택 칩(wait_for / SEO 연동 데이터)이 같은 정의를 공유한다.
 *
 * @param entry data_source 한 건
 * @param resolve `$t:` 키 해석(editorAwareT)
 * @return 해석된 친화 명칭, 실패 시 null(폴백=id 표시)
 */
export function friendlyDataSourceName(
  entry: Record<string, unknown>,
  resolve: LabelResolver,
): string | null {
  const id = typeof entry.id === 'string' ? entry.id : '';
  const labelKey = typeof entry.label_key === 'string' ? entry.label_key : '';
  if (!labelKey) return null;
  const key = labelKey.startsWith('$t:') ? labelKey.slice(3) : labelKey;
  const resolved = resolve(key);
  if (resolved && resolved !== key && !resolved.startsWith('$t:') && resolved !== id) {
    return resolved;
  }
  return null;
}

/**
 * data_source 의 확장 출처 배지 텍스트를 구성한다(확장 주입 소스 표시).
 *
 * 백엔드(LayoutExtensionService)가 확장 주입 data_source 에 `__source` 메타
 * (`{kind:'extension', extensionSourceType:'module'|'plugin', extensionIdentifier, extensionName}`)를
 * 부여한다. 그 메타가 있으면 "모듈: {표시명} ({식별자})" / "플러그인: ..." 배지를 만든다.
 * 비-확장(템플릿/route) 출처는 null. [데이터] 탭과 멀티선택 칩이 공유.
 *
 * @param entry data_source 한 건
 * @param t 편집기 chrome 다국어 해석
 * @return 배지 텍스트, 비-확장 출처면 null
 */
export function dataSourceExtensionBadge(
  entry: Record<string, unknown>,
  t: LabelResolver,
): string | null {
  const src = entry.__source;
  if (!src || typeof src !== 'object' || Array.isArray(src)) return null;
  const meta = src as Record<string, unknown>;
  if (meta.kind !== 'extension') return null;
  const type =
    meta.extensionSourceType === 'plugin'
      ? t('layout_editor.data_sources.source.plugin')
      : t('layout_editor.data_sources.source.module');
  const ident = typeof meta.extensionIdentifier === 'string' ? meta.extensionIdentifier : '';
  const name = typeof meta.extensionName === 'string' ? meta.extensionName : '';
  const who = name && name !== ident ? `${name} (${ident})` : ident;
  return who ? `${type}: ${who}` : type;
}

/** 멀티선택(체크박스 칩) UI 용 데이터소스 옵션 — id + 친화명 + 출처 배지 동반 */
export interface DataSourceOption {
  /** data_source id(값) */
  id: string;
  /** 친화 명칭(label_key 해석). 없으면 null → UI 는 id 를 제목으로 */
  friendly: string | null;
  /** 확장 출처 배지("모듈: …"/"플러그인: …"). 비-확장이면 null */
  source: string | null;
}

/**
 * 멀티선택 데이터소스 옵션 — [로딩 화면] wait_for·[검색엔진] SEO 연동 데이터가 공유한다.
 * 종전엔 셸이 id 만 흘려(`{id}`) 칩이 raw id 만 노출했다([데이터] 탭은 친화명·출처를 보여
 * 주는데 불일치). [데이터] 탭(DataSourcesPanel)과 같은 도출(friendlyDataSourceName/
 * dataSourceExtensionBadge)로 친화명·출처를 동반시켜 직관성을 맞춘다.
 *
 * @param raw 편집 중 레이아웃 raw(병합본 — 상속/확장 주입 data_sources 포함)
 * @param resolveLabel `$t:` 친화명 해석(editorAwareT)
 * @param t 편집기 chrome 다국어 해석(출처 배지 라벨)
 * @return 각 data_source 의 {id, friendly, source}
 */
export function buildDataSourceOptions(
  raw: Record<string, unknown> | undefined | null,
  resolveLabel: LabelResolver,
  t: LabelResolver,
): DataSourceOption[] {
  const list = Array.isArray((raw as { data_sources?: unknown } | undefined)?.data_sources)
    ? ((raw as { data_sources?: unknown[] }).data_sources as Array<Record<string, unknown>>)
    : [];
  const out: DataSourceOption[] = [];
  const seen = new Set<string>();
  for (const ds of list) {
    const id = ds?.id;
    if (typeof id !== 'string' || id.length === 0 || seen.has(id)) continue;
    seen.add(id);
    out.push({
      id,
      friendly: friendlyDataSourceName(ds, resolveLabel),
      source: dataSourceExtensionBadge(ds, t),
    });
  }
  return out;
}

/**
 * 상태 키 후보 — spec.states 의 각 페이지 상태 initialState(local/global) 키 합집합.
 * EditorCanvasOverlay stateKeyCandidates 와 동치.
 *
 * @param spec 병합 editor-spec
 * @return {value:key, label:key}[]
 */
export function buildStateKeyCandidates(
  spec: EditorSpec | null | undefined,
): ValueLabelCandidate[] {
  const groups = spec?.states?.groups ?? [];
  const keys = new Set<string>();
  for (const g of groups) {
    for (const item of g.items ?? []) {
      for (const k of Object.keys(item.initialState?.local ?? {})) keys.add(k);
      for (const k of Object.keys(item.initialState?.global ?? {})) keys.add(k);
    }
  }
  return Array.from(keys).map((k) => ({ value: k, label: k }));
}

/**
 * 모달 후보 — 레이아웃 raw.modals 의 각 항목(openModal 핸들러 대상 선택용). [에러 처리]·[화면 동작]
 * 탭의 modal-picker 입력.
 *
 * value = 모달 id(런타임 openModal target SSoT), label = 좌측 라우트 트리 `[모달]` 그룹과 **동일한
 * 친화 명칭**. 라벨 우선순위는 백엔드 `collectEditorBaseAndModals`(좌측 트리 출처)와 동형:
 * `meta.editor_label`(`$t:` 키) → `title` → `id`. `resolveLabel` 을 주면 `$t:` 키를 해석해 한글/영문
 * 등 사용자 언어로 노출한다(미주입 시 키 원문 폴백). 종전엔 `label:id`(기술 ID 그대로)라 좌측 트리와
 * 불일치했다.
 *
 * @param raw 편집 중 레이아웃 raw(병합본)
 * @param resolveLabel `$t:` 키 해석기(미주입 시 키 원문). 좌측 트리와 동일 t() 를 흘려보낸다.
 * @return {value:id, label:친화명칭}[]
 */
export function buildModalCandidates(
  raw: Record<string, unknown> | undefined | null,
  resolveLabel?: LabelResolver,
): ValueLabelCandidate[] {
  const list = Array.isArray((raw as { modals?: unknown } | undefined)?.modals)
    ? ((raw as { modals?: unknown[] }).modals as Array<Record<string, unknown>>)
    : [];
  const out: ValueLabelCandidate[] = [];
  const seen = new Set<string>();
  for (const m of list) {
    const id = m?.id;
    if (typeof id !== 'string' || id.length === 0 || seen.has(id)) continue;
    seen.add(id);
    out.push({ value: id, label: resolveModalLabel(m, id, resolveLabel) });
  }
  return out;
}

/**
 * 모달 한 항목의 친화 라벨 도출 — `meta.editor_label`(`$t:` 키, resolveLabel 로 해석) → `title`
 * (`$t:` 키면 해석) → `id`(폴백). 백엔드 `resolveEditorTreeLabel`(좌측 트리)와 동형 우선순위.
 *
 * @param modal 모달 raw 항목
 * @param id 모달 id(폴백 라벨)
 * @param resolveLabel `$t:` 키 해석기
 * @return 친화 라벨
 */
function resolveModalLabel(
  modal: Record<string, unknown>,
  id: string,
  resolveLabel?: LabelResolver,
): string {
  const meta = modal?.meta as Record<string, unknown> | undefined;
  const editorLabel = meta?.editor_label;
  if (typeof editorLabel === 'string' && editorLabel.length > 0) {
    return resolveLabelKey(editorLabel, resolveLabel);
  }
  const title = modal?.title;
  if (typeof title === 'string' && title.length > 0) {
    return resolveLabelKey(title, resolveLabel);
  }
  return id;
}

/** `$t:` 접두 키면 resolveLabel 로 해석(미해석/미주입 시 키 원문), 평문이면 그대로. */
function resolveLabelKey(value: string, resolveLabel?: LabelResolver): string {
  if (value.startsWith('$t:')) {
    const key = value.slice(3);
    const resolved = resolveLabel ? resolveLabel(key) : key;
    return resolved && resolved !== key ? resolved : key;
  }
  return value;
}
