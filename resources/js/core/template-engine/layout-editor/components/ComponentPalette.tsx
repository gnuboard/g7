/**
 * ComponentPalette.tsx — 요소 추가 팔레트
 *
 * '요소 추가' 버튼 또는 외곽 + 버튼 클릭 시 떠 있는 팝업으로 표시.
 *
 * 데이터 흐름:
 *  - 소스: 편집 대상 템플릿의 `components.json` (컴포넌트 매니페스트).
 *  - 필터: 삽입 대상 부모의 `nesting.accepts` 가 있으면 그 안에 든 컴포넌트만.
 *  - 그룹: editorSpec.componentPalette.groups 가 미제공이므로 본 Phase 는 추정
 *    규칙(`bindingType` 이나 컴포넌트 이름 휴리스틱)으로 디자인/DB 분류.
 *  - id 미부여 정책: 신규 노드에 id 자동 부여 금지.
 *
 * Phase 4 에서 `editorSpec.componentPalette.groups` / `entries[name].label` 이
 * 추가되면 그 우선순위가 본 컴포넌트의 추정 휴리스틱을 덮어쓴다.
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import { useTranslation } from '../../TranslationContext';
import type { EditorNode, ComponentPath } from '../utils/layoutTreeUtils';
import type {
  NestingSpec,
  ComponentPaletteSpec,
  ComponentPaletteGroupSpec,
} from '../spec/specTypes';
import { trackEditorDnd } from '../devtools/editorTrackers';

export interface ComponentManifestEntry {
  /** 컴포넌트 이름 (Div / Button / ...) */
  name: string;
  /** basic / composite / layout */
  type?: string;
  description?: string;
  /** 데이터 바인딩 기본 정보 — 'checkable'/'value' 등이 있으면 DB 그룹으로 가산점 */
  bindingType?: string;
  /** props 메타 — 기본 골격 생성에 사용 */
  props?: Record<
    string,
    {
      type?: string;
      required?: boolean;
      default?: unknown;
      description?: string;
    }
  >;
  /**
   * 집합 컴포넌트 설정 스펙 — 속성 편집 모달 `[설정]` 탭. 컴포넌트가
   * "유저가 제어할 수 있는 범위"를 선언한다. 그룹·필드·필드 타입·기본값으로
   * 구성. 데이터 없는 정적 컴포넌트(H1/Button 등)는 본 블록을 갖지 않는다.
   */
  settings?: CompositeSettingsSpec;
}

/** 집합 컴포넌트 설정 스펙 — `[설정]` 탭 폼  */
export interface CompositeSettingsSpec {
  groups?: CompositeSettingsGroup[];
}

export interface CompositeSettingsGroup {
  /** 그룹 라벨 — `$t:` 키 권장 */
  label?: string;
  fields?: CompositeSettingsField[];
}

export interface CompositeSettingsField {
  /** 인스턴스 props 의 키 */
  key: string;
  /** 필드 라벨 — `$t:` 키 권장 */
  label?: string;
  /** 필드 타입 — select/number/text/toggle/checkbox-group/color/board-select/datasource-select */
  type: string;
  /** 기본값 (설정 스펙이 기본값 SSoT) */
  default?: unknown;
  /** select/checkbox-group 옵션 */
  options?: Array<{ value: unknown; label?: string }>;
  /** number 필드 범위 */
  min?: number;
  max?: number;
}

export interface ComponentManifest {
  templateId?: string;
  components?: {
    basic?: ComponentManifestEntry[];
    composite?: ComponentManifestEntry[];
    layout?: ComponentManifestEntry[];
    [key: string]: ComponentManifestEntry[] | undefined;
  };
}

export interface ComponentPaletteProps {
  /** 컴포넌트 매니페스트 (components.json data) */
  manifest: ComponentManifest | null;
  /** editor-spec 의 nesting 블록 — 부모 accepts 필터에 사용 */
  nesting: NestingSpec | null | undefined;
  /**
   * editor-spec 의 componentPalette 블록 — 그룹 정의 + 친화 라벨.
   * 미제공 시 components.json 의 basic/composite/layout 평면 분류로 폴백.
   */
  componentPalette?: ComponentPaletteSpec | null;
  /** 삽입 대상 부모의 ComponentPath (없으면 루트) */
  targetParentPath: ComponentPath | null;
  /** 삽입 대상 부모 컴포넌트 이름 (예: 'Div'). null = 루트 — accepts 필터 없음 */
  targetContainerName: string | null;
  /** 사용자가 컴포넌트를 선택해 삽입할 때 호출되는 콜백 */
  onInsert: (newNode: EditorNode, parentPath: ComponentPath, index: number) => void;
  /** 삽입 인덱스 — 호출자가 컨텍스트 + 버튼 위치 또는 끝 삽입으로 지정 */
  insertionIndex: number;
  /** 팝업 닫기 콜백 */
  onClose: () => void;
  /**
   * 편집 대상 템플릿 식별자. PaletteCard 의 entry label / category label 등
   * 편집 대상 템플릿이 정의한 i18n 키를 해석할 때 사용된다.
   *
   * 편집기 chrome 자체는 호스트 admin 템플릿(예: sirsoft-admin_basic) 컨텍스트로
   * 렌더되지만, 사용자가 편집하는 대상은 임의의 다른 템플릿(sirsoft-basic 등)
   * 이므로 entry label 은 편집 대상 사전에서 풀어야 한다.
   *
   * 미제공 시 admin chrome 컨텍스트의 useTranslation().t 로 폴백 — 기존 동작 보존.
   */
  editorTemplateId?: string | null;
  /** 편집 대상 로케일 — editorTemplateId 와 짝. 미제공 시 chrome 로케일로 폴백. */
  editorLocale?: string | null;
}

/**
 * `components.json` props 메타에서 default 값을 추출해 신규 노드 골격 생성.
 *
 *  폴백 경로 — `editorSpec.componentPalette.entries[name].defaultNode`
 * 가 부재할 때만 호출된다. 코어는 시각화 보장 책임을 갖지 않으므로 minHeight/
 * minWidth/className 등 시각 단서를 임의 부여하지 않는다 (원칙 / memory
 * `feedback_layout_editor_no_css_lib_dependency`).
 *
 * - id 는 부여하지 않는다.
 * - default 값이 없는 prop 은 골격에 포함하지 않는다.
 * - children: ReactNode 같은 prop 은 제외.
 * - text/children 은 추가하지 않는다 — 시각 콘텐츠는 템플릿 defaultNode 책임.
 */
export function buildDefaultNode(entry: ComponentManifestEntry): EditorNode {
  const node: EditorNode = {
    type: entry.type ?? 'basic',
    name: entry.name,
  };

  const props: Record<string, unknown> = {};
  if (entry.props) {
    for (const [key, meta] of Object.entries(entry.props)) {
      if (key === 'children') continue;
      if (meta?.default !== undefined) {
        props[key] = meta.default;
      }
    }
  }

  if (Object.keys(props).length > 0) {
    node.props = props;
  }

  return node;
}

/**
 * 매니페스트에서 모든 컴포넌트 엔트리를 평면 배열로 추출.
 *
 * `components.json` 의 `components.basic` / `composite` / `layout` 등 모든
 * 키의 배열을 평탄화. 각 엔트리에 components.json 의 type 메타가 보존되므로
 * 호출자가 카드 뱃지로 사용할 수 있다.
 */
function flattenManifest(manifest: ComponentManifest | null): ComponentManifestEntry[] {
  if (!manifest?.components) return [];
  const out: ComponentManifestEntry[] = [];
  for (const [groupKey, list] of Object.entries(manifest.components)) {
    if (Array.isArray(list)) {
      for (const entry of list) {
        // type 메타가 누락된 엔트리는 groupKey(basic/composite/layout) 로 추론.
        // components.json 스키마는 이미 type 필드를 두지만 안전망.
        out.push({ ...entry, type: entry.type ?? groupKey });
      }
    }
  }
  return out;
}

/**
 * 부모 accepts 로 필터한 엔트리 + 검색어 적용.
 *
 * 그룹 분류는 본 함수가 하지 않는다 (그룹 정의는 템플릿 소유).
 * 호출자가 `componentPalette.groups` 또는 components.json type 폴백으로
 * 분류한다.
 *
 * 검색 매칭 대상:
 *   - 표시 라벨(다국어 해석) — entrySpec.label 을 현재 로케일로 해석한 "박스"/"컨테이너" 등.
 *     카드에 실제로 보이는 명칭이므로 사용자 기대와 일치(다국어 지원).
 *   - 컴포넌트 이름(`Div`/`Button` — `<Div>` 태그 형태 포함).
 *   - description(개발자 평문 — 보조 매칭).
 * resolveLabel 은 호출자가 entryT(편집 대상 사전) 기반으로 주입한다.
 */
function selectEntries(
  manifest: ComponentManifest | null,
  nesting: NestingSpec | null | undefined,
  targetContainerName: string | null,
  search: string,
  resolveLabel: (entry: ComponentManifestEntry) => string
): ComponentManifestEntry[] {
  const all = flattenManifest(manifest);

  // 부모 accepts 필터
  let accepts: Set<string> | null = null;
  if (targetContainerName && nesting?.containers) {
    const rule = nesting.containers[targetContainerName];
    if (rule && Array.isArray(rule.accepts)) {
      accepts = new Set(rule.accepts);
    } else {
      // accepts 정의 없음 → 어떤 자식도 받지 않음 (폴백 없음)
      accepts = new Set();
    }
  } else if (!targetContainerName && nesting?.draggable) {
    // 루트 삽입 — draggable 목록 안의 컴포넌트만 허용 (보수적)
    accepts = new Set(nesting.draggable);
  }

  return all.filter((entry) => {
    if (accepts && !accepts.has(entry.name)) return false;
    if (search) {
      const q = search.toLowerCase();
      const label = resolveLabel(entry).toLowerCase();
      const name = entry.name.toLowerCase();
      if (
        !label.includes(q) &&
        !name.includes(q) &&
        // `<Div>` 태그 형태 검색 허용 (`<` 입력 시)
        !`<${name}>`.includes(q) &&
        !(entry.description ?? '').toLowerCase().includes(q)
      ) {
        return false;
      }
    }
    return true;
  });
}

/**
 * 그룹 정의 + 엔트리 배열을 받아 사이드바 카테고리 + 각 카테고리의 엔트리
 * 매트릭스를 반환.
 *
 * 분기:
 *   (1) editorSpec.componentPalette.groups 가 있으면 → 그 정의대로 사용
 *  (그룹 정의는 템플릿 소유).
 *   (2) 미제공 폴백 → components.json type 메타 (basic/composite/layout)
 *       으로 카테고리 구성. 그룹의 의미적 분류 없음.
 *
 * 어느 경로든 결과 카테고리 배열의 첫 항목은 "전체" (모든 엔트리 노출).
 */
interface ResolvedCategory {
  key: string;
  label: string;
  /** 본 카테고리에 속하는 엔트리 (filtered 입력에서 그룹 정의에 따라 분류) */
  entries: ComponentManifestEntry[];
  /** 다국어 키 형태 라벨 여부 — 호출자가 t(label) 호출 여부 결정 */
  isI18nKey: boolean;
}

function resolveCategories(
  entries: ComponentManifestEntry[],
  componentPalette: ComponentPaletteSpec | null | undefined
): ResolvedCategory[] {
  const allCategory: ResolvedCategory = {
    key: 'all',
    label: 'layout_editor.palette.category.all',
    entries,
    isI18nKey: true,
  };

  // (1) 스펙 정의 우선
  if (componentPalette?.groups && componentPalette.groups.length > 0) {
    const byName = new Map(entries.map((e) => [e.name, e]));
    const seen = new Set<string>();
    const groupCategories: ResolvedCategory[] = [];

    for (const group of componentPalette.groups) {
      const groupEntries: ComponentManifestEntry[] = [];
      for (const name of group.components ?? []) {
        const entry = byName.get(name);
        if (!entry) continue; // 부모 accepts 필터로 빠진 경우 본 그룹에 미표시
        groupEntries.push(entry);
        seen.add(name);
      }
      if (groupEntries.length === 0) continue;
      groupCategories.push({
        key: group.kind ?? group.label,
        label: group.label,
        entries: groupEntries,
        isI18nKey: group.label.startsWith('$t:') || /^[a-z_.]+$/i.test(group.label),
      });
    }

    // 스펙 그룹에 속하지 않은 엔트리는 '기타' 폴백
    const leftover = entries.filter((e) => !seen.has(e.name));
    if (leftover.length > 0) {
      groupCategories.push({
        key: 'other',
        label: 'layout_editor.palette.category.other',
        entries: leftover,
        isI18nKey: true,
      });
    }

    return [allCategory, ...groupCategories];
  }

  // (2) components.json type 메타 폴백 
  // basic/composite/layout 그대로 노출. 사용자에게는 그룹 정의가 없다는
  // 사실을 카테고리 라벨이 그대로 보여줌 (Phase 4 에서 editorSpec 도입 시 덮어씌워짐).
  const buckets = new Map<string, ComponentManifestEntry[]>();
  for (const entry of entries) {
    const type = (entry.type ?? 'basic').toLowerCase();
    const list = buckets.get(type) ?? [];
    list.push(entry);
    buckets.set(type, list);
  }
  // 안정 순서: basic → composite → layout → 그 외
  const orderedKeys = ['basic', 'composite', 'layout'];
  const fallbackCategories: ResolvedCategory[] = [];
  for (const key of orderedKeys) {
    const list = buckets.get(key);
    if (list && list.length > 0) {
      fallbackCategories.push({
        key,
        label: `layout_editor.palette.category.fallback.${key}`,
        entries: list,
        isI18nKey: true,
      });
      buckets.delete(key);
    }
  }
  // 그 외 키(있다면)
  for (const [key, list] of buckets.entries()) {
    fallbackCategories.push({
      key,
      label: key,
      entries: list,
      isI18nKey: false,
    });
  }
  return [allCategory, ...fallbackCategories];
}

export function ComponentPalette(props: ComponentPaletteProps): React.ReactElement {
  const {
    manifest,
    nesting,
    componentPalette,
    targetParentPath,
    targetContainerName,
    onInsert,
    insertionIndex,
    onClose,
    editorTemplateId,
    editorLocale,
  } = props;
  const { t, translationEngine } = useTranslation();
  // entry label / category label 은 편집 대상 템플릿 사전에서 해석한다.
  // chrome 라벨(title / search_placeholder / empty 등) 은 호스트 admin chrome
  // 컨텍스트의 `t` 그대로 사용. (다른 템플릿 편집 시 entry 라벨이
  // admin partial 에 없어 키 그대로 노출되던 회귀를 차단)
  const entryT = useMemo<(key: string) => string>(() => {
    if (!editorTemplateId || !translationEngine) return t;
    const locale = editorLocale ?? null;
    return (key: string): string => {
      const editorCtx = { templateId: editorTemplateId, locale: locale ?? 'ko' };
      const res = translationEngine.translate(key, editorCtx);
      if (res !== key) return res;
      // 편집 대상 사전에 없으면 호스트 chrome 사전(useTranslation 의 t) 으로 폴백.
      return t(key);
    };
  }, [editorTemplateId, editorLocale, translationEngine, t]);
  const [search, setSearch] = useState('');
  const [activeCategoryKey, setActiveCategoryKey] = useState<string>('all');

  // entries 기반 룩업 — defaultNode/label 메타 보강
  const entriesMap = componentPalette?.entries ?? null;

  // 검색 매칭용 표시 라벨 해석기 — 카드에 보이는 명칭(다국어 해석)과 동일 우선순위
  // (entrySpec.label → description → name). PaletteCard 의 label 계산과 일치시켜
  // "보이는 이름으로 검색" 기대를 충족한다.
  const resolveEntryLabel = useMemo(
    () =>
      (entry: ComponentManifestEntry): string => {
        const spec = entriesMap?.[entry.name];
        if (spec?.label) return resolveI18nLabel(spec.label, entryT);
        return entry.description ?? entry.name;
      },
    [entriesMap, entryT]
  );

  // 부모 accepts + 검색으로 1차 필터링한 엔트리
  const filteredEntries = useMemo(
    () => selectEntries(manifest, nesting, targetContainerName, search, resolveEntryLabel),
    [manifest, nesting, targetContainerName, search, resolveEntryLabel]
  );

  // 그룹 정의 소비 또는 components.json type 폴백 —
  const categories = useMemo(
    () => resolveCategories(filteredEntries, componentPalette ?? null),
    [filteredEntries, componentPalette]
  );

  // active 카테고리가 결과에 없으면 'all' 로 강제 회귀 (예: 검색 결과로 그룹 빈 경우)
  const activeCategory =
    categories.find((c) => c.key === activeCategoryKey) ?? categories[0] ?? null;

  const isSpecMissing = !nesting || (!nesting.draggable && !nesting.containers);
  const isEmpty = filteredEntries.length === 0;
  const visibleEntries = activeCategory?.entries ?? [];

  const handleClick = (entry: ComponentManifestEntry): void => {
    const entrySpec = entriesMap?.[entry.name];
    const hasDefaultNode =
      !!entrySpec?.defaultNode && typeof entrySpec.defaultNode === 'object';
    // requiresDefaultNode: true 인데 defaultNode 미정의 → 추가 차단.
    if (entrySpec?.requiresDefaultNode && !hasDefaultNode) {
      return;
    }
    // entrySpec.defaultNode 가 있으면 그 골격 그대로 사용 (정식 발효),
    // 미제공 시 components.json props.default 기반 코어 폴백.
    const node = hasDefaultNode
      ? (JSON.parse(JSON.stringify(entrySpec!.defaultNode)) as EditorNode)
      : buildDefaultNode(entry);
    const parentPath: ComponentPath = targetParentPath ?? [];
    onInsert(node, parentPath, insertionIndex);
    trackEditorDnd({
      source: 'palette',
      draggedComponentName: entry.name,
      targetContainerName,
      targetContainerPath: parentPath.length === 0 ? null : parentPath.join('/'),
      decision: 'allowed',
      insertionIndex,
      result: 'completed',
      timestamp: Date.now(),
    });
    onClose();
  };

  return (
    <div
      className="g7le-palette"
      data-testid="g7le-palette"
      style={paletteFrameStyle}
    >
      <div style={paletteHeaderStyle}>
        <strong style={{ fontSize: 16 }}>{t('layout_editor.palette.title')}</strong>
        <button
          type="button"
          onClick={onClose}
          aria-label="close"
          data-testid="g7le-palette-close"
          style={closeButtonStyle}
        >
          ×
        </button>
      </div>

      <div style={paletteBodyStyle}>
        {/* 좌측 카테고리 사이드바 — 그룹 정의는 템플릿 소유  */}
        <aside style={sidebarStyle} data-testid="g7le-palette-sidebar">
          <nav>
            <ul style={sidebarListStyle}>
              {categories.map((cat) => {
                const active = activeCategory?.key === cat.key;
                const label = cat.isI18nKey ? resolveI18nLabel(cat.label, entryT) : cat.label;
                return (
                  <li key={cat.key}>
                    <button
                      type="button"
                      data-testid={`g7le-palette-category-${cat.key}`}
                      data-active={active ? 'true' : 'false'}
                      onClick={() => setActiveCategoryKey(cat.key)}
                      style={{
                        ...sidebarItemStyle,
                        background: active ? '#eff6ff' : 'transparent',
                        color: active ? '#1d4ed8' : '#334155',
                        fontWeight: active ? 600 : 500,
                      }}
                    >
                      <span>{label}</span>
                      <span style={categoryCountStyle}>{cat.entries.length}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </nav>
        </aside>

        {/* 우측 본문 — 검색 + 그리드 카드 */}
        <main style={mainStyle}>
          <input
            type="text"
            placeholder={t('layout_editor.palette.search_placeholder')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            data-testid="g7le-palette-search"
            style={searchStyle}
          />

          <div style={scrollAreaStyle}>
            {isSpecMissing ? (
              <div style={emptyStyle} data-testid="g7le-palette-spec-missing">
                {t('layout_editor.palette.spec_missing')}
              </div>
            ) : isEmpty || visibleEntries.length === 0 ? (
              <div style={emptyStyle} data-testid="g7le-palette-empty">
                {t('layout_editor.palette.empty')}
              </div>
            ) : (
              <div
                data-testid={`g7le-palette-group-${activeCategory?.key ?? 'all'}`}
                style={{ marginBottom: 16 }}
              >
                <div style={gridStyle}>
                  {visibleEntries.map((entry) => (
                    <PaletteCard
                      key={entry.name}
                      entry={entry}
                      entrySpec={entriesMap?.[entry.name] ?? null}
                      onClick={handleClick}
                      t={t}
                      entryT={entryT}
                    />
                  ))}
                </div>
              </div>
            )}
          </div>
        </main>
      </div>
    </div>
  );
}

interface PaletteCardProps {
  entry: ComponentManifestEntry;
  entrySpec: ComponentPaletteSpec['entries'] extends Record<string, infer V> | undefined
    ? V | null
    : never;
  onClick: (entry: ComponentManifestEntry) => void;
  /** chrome 라벨 (palette.requires_default_node_missing 등) 해석용 — 호스트 t */
  t: (key: string) => string;
  /** 편집 대상 템플릿 사전으로 해석되는 t — entrySpec.label 전용 */
  entryT: (key: string) => string;
}

function PaletteCard(props: PaletteCardProps): React.ReactElement {
  const { entry, entrySpec, onClick, t, entryT } = props;
  // 라벨 우선순위: entrySpec.label ($t: 키 해석) → description → name 
  const label =
    entrySpec?.label
      ? resolveI18nLabel(entrySpec.label, entryT)
      : entry.description ?? entry.name;
  const typeLabel = (entry.type ?? '').toLowerCase();
  const showBadge = typeLabel === 'basic' || typeLabel === 'composite' || typeLabel === 'layout';
  // requiresDefaultNode: true 인데 defaultNode 미정의 시 비활성 + 안내.
  const hasDefaultNode =
    !!entrySpec?.defaultNode && typeof entrySpec.defaultNode === 'object';
  const blockedByRequiresDefaultNode = !!entrySpec?.requiresDefaultNode && !hasDefaultNode;
  const missingDefaultNodeBadge = !hasDefaultNode;
  //  라벨 우선순위와 동일: entrySpec.label → entry.name. components.json
  // 의 description 은 개발자 가이드 평문이라 tooltip 으로 노출하면 en/ja 로케일에
  // 한국어/영어 평문이 그대로 보이는 i18n 결함을 유발.
  const titleText = blockedByRequiresDefaultNode
    ? t('layout_editor.palette.requires_default_node_missing')
    : entrySpec?.label
      ? resolveI18nLabel(entrySpec.label, entryT)
      : entry.name;
  return (
    <button
      type="button"
      onClick={() => onClick(entry)}
      disabled={blockedByRequiresDefaultNode}
      aria-disabled={blockedByRequiresDefaultNode || undefined}
      data-testid={`g7le-palette-item-${entry.name}`}
      data-component-type={typeLabel || undefined}
      data-blocked={blockedByRequiresDefaultNode ? 'true' : undefined}
      data-default-node-missing={missingDefaultNodeBadge ? 'true' : undefined}
      title={titleText}
      style={blockedByRequiresDefaultNode ? cardBlockedStyle : cardStyle}
    >
      <div style={cardIconStyle} aria-hidden="true">
        {pickIconGlyph(entry)}
      </div>
      <div style={cardLabelStyle}>{label}</div>
      {/* 실제 렌더 컴포넌트 태그 — React 컴포넌트명 형식. */}
      <div
        data-testid={`g7le-palette-item-${entry.name}-tag`}
        style={componentTagStyle}
      >
        {`<${entry.name}>`}
      </div>
      {showBadge && (
        <div
          data-testid={`g7le-palette-item-${entry.name}-badge`}
          style={typeBadgeStyle(typeLabel)}
        >
          {typeLabel}
        </div>
      )}
      {missingDefaultNodeBadge && (
        <div
          data-testid={`g7le-palette-item-${entry.name}-default-missing-badge`}
          style={missingBadgeStyle}
        >
          {t('layout_editor.palette.default_node_missing_badge')}
        </div>
      )}
    </button>
  );
}

/**
 * `$t:layout_editor.foo.bar` 또는 `layout_editor.foo.bar` 형태의 라벨을 t() 로
 * 해석. `$t:` 접두사가 있으면 제거 후 호출.
 */
function resolveI18nLabel(label: string, t: (key: string) => string): string {
  if (label.startsWith('$t:')) {
    return t(label.slice(3));
  }
  return t(label);
}

function typeBadgeStyle(type: string): React.CSSProperties {
  const palette: Record<string, { bg: string; fg: string }> = {
    basic: { bg: '#dbeafe', fg: '#1e40af' },
    composite: { bg: '#dcfce7', fg: '#14532d' },
    layout: { bg: '#fef3c7', fg: '#854d0e' },
  };
  const color = palette[type] ?? { bg: '#e2e8f0', fg: '#334155' };
  return {
    fontSize: 9,
    fontWeight: 600,
    color: color.fg,
    background: color.bg,
    padding: '1px 6px',
    borderRadius: 999,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
    lineHeight: 1.6,
  };
}

/**
 * 휴리스틱 아이콘 — Phase 4 에서 `editorSpec.componentPalette.entries[name].icon`
 * 가 도입되면 그 우선순위가 본 함수를 덮어쓴다. Phase 3 는 컴포넌트 이름
 * 기반 단순 매핑.
 */
function pickIconGlyph(entry: ComponentManifestEntry): string {
  const name = entry.name;
  const map: Record<string, string> = {
    Button: '🔘',
    A: '🔗',
    Img: '🖼',
    Icon: '✨',
    Hr: '➖',
    P: '📄',
    Span: 'T',
    H1: 'H₁',
    H2: 'H₂',
    H3: 'H₃',
    H4: 'H₄',
    Ul: '☰',
    Li: '•',
    Nav: '🧭',
    Div: '▢',
    Container: '▢',
    Flex: '⇆',
    Grid: '▦',
    SectionLayout: '▤',
    ThreeColumnLayout: '⫼',
    Form: '📝',
    Input: '⎕',
    Textarea: '▭',
    Select: '⏷',
    Checkbox: '☑',
    PasswordInput: '🔒',
    Label: '🏷',
    DataGrid: '▦',
    Pagination: '↔',
    SearchBar: '🔍',
    ProductCard: '🛍',
    ImageGallery: '🖼',
    Avatar: '👤',
    UserInfo: '👥',
    HtmlContent: '⟨/⟩',
    HtmlEditor: '✏',
    RichTextEditor: '✏',
    TabNavigation: '◰',
    QuantitySelector: '＃',
    ProductImageViewer: '🖼',
    FileUploader: '📎',
    AvatarUploader: '📷',
    SocialLoginButtons: '🌐',
    PostReactions: '❤',
  };
  return map[name] ?? '◻';
}

// 모달 본문 frame — 외곽 backdrop/그림자는 EditorModal 이 담당.
// height: 100% 로 모달 최대 높이까지 채우고, 내부 body 가 sidebar/main 분할.
const paletteFrameStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  width: '100%',
  height: '100%',
  minHeight: 0,
  fontFamily:
    '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Apple SD Gothic Neo", "Noto Sans KR", sans-serif',
};

const paletteHeaderStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'center',
  padding: '14px 20px',
  borderBottom: '1px solid #e2e8f0',
  flexShrink: 0,
};

const closeButtonStyle: React.CSSProperties = {
  background: 'transparent',
  border: 'none',
  fontSize: 24,
  cursor: 'pointer',
  lineHeight: 1,
  color: '#64748b',
  padding: '0 4px',
};

const paletteBodyStyle: React.CSSProperties = {
  display: 'flex',
  flex: 1,
  minHeight: 0,
};

const sidebarStyle: React.CSSProperties = {
  width: 180,
  flexShrink: 0,
  borderRight: '1px solid #e2e8f0',
  padding: '14px 8px',
  background: '#f8fafc',
  overflowY: 'auto',
};

const sidebarListStyle: React.CSSProperties = {
  listStyle: 'none',
  margin: 0,
  padding: 0,
  display: 'flex',
  flexDirection: 'column',
  gap: 4,
};

const sidebarItemStyle: React.CSSProperties = {
  display: 'flex',
  width: '100%',
  alignItems: 'center',
  justifyContent: 'space-between',
  textAlign: 'left',
  padding: '8px 12px',
  border: 'none',
  borderRadius: 6,
  fontSize: 13,
  cursor: 'pointer',
  transition: 'background-color 120ms',
};

const mainStyle: React.CSSProperties = {
  flex: 1,
  display: 'flex',
  flexDirection: 'column',
  minHeight: 0,
  padding: '14px 20px',
};

const searchStyle: React.CSSProperties = {
  width: '100%',
  padding: '8px 10px',
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  marginBottom: 12,
  fontSize: 13,
  flexShrink: 0,
  boxSizing: 'border-box',
};

const scrollAreaStyle: React.CSSProperties = {
  flex: 1,
  overflowY: 'auto',
  minHeight: 0,
};

const categoryCountStyle: React.CSSProperties = {
  fontSize: 11,
  color: '#94a3b8',
  marginLeft: 8,
  fontWeight: 500,
};

const gridStyle: React.CSSProperties = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fill, minmax(96px, 1fr))',
  gap: 10,
};

const cardStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 6,
  padding: '14px 6px',
  background: '#fff',
  border: '1px solid #e2e8f0',
  borderRadius: 8,
  cursor: 'pointer',
  fontSize: 12,
  minHeight: 84,
  transition: 'border-color 120ms, box-shadow 120ms',
};

const cardBlockedStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 6,
  padding: '14px 6px',
  background: '#f8fafc',
  border: '1px dashed #cbd5e1',
  borderRadius: 8,
  cursor: 'not-allowed',
  fontSize: 12,
  minHeight: 84,
  color: '#94a3b8',
  opacity: 0.65,
};

const missingBadgeStyle: React.CSSProperties = {
  fontSize: 9,
  fontWeight: 600,
  color: '#92400e',
  background: '#fef3c7',
  padding: '1px 6px',
  borderRadius: 999,
  textTransform: 'none',
  letterSpacing: 0,
  lineHeight: 1.6,
};

const cardIconStyle: React.CSSProperties = {
  fontSize: 22,
  lineHeight: 1,
  color: '#475569',
};

const cardLabelStyle: React.CSSProperties = {
  fontSize: 12,
  fontWeight: 500,
  color: '#0f172a',
  textAlign: 'center',
  wordBreak: 'break-word',
};

// 실제 렌더 컴포넌트 태그 배지 — React 컴포넌트명 형식.
const componentTagStyle: React.CSSProperties = {
  fontFamily:
    'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
  fontSize: 10,
  color: '#64748b',
  textAlign: 'center',
  wordBreak: 'break-all',
};

const emptyStyle: React.CSSProperties = {
  padding: 24,
  color: '#64748b',
  fontSize: 13,
  textAlign: 'center',
};
