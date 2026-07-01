/**
 * ActionAddPicker.tsx — 핸들러 스펙 추가 목록
 *
 * [화면 동작] 탭과 컴포넌트 속성 [동작] 탭이 공유하는 "동작 추가" 피커. 코어 핸들러 스펙
 * (coreActionRecipes) + 활성 확장 제공 스펙(`__source`)을 그룹별로 펼치고, 검색·
 * 모든 항목 출처 배지(코어=〔코어〕/확장=제공자명)를 제공한다. 선택 시 `buildAction`(빈값)
 * 으로 빈 액션을 만들어 호출자에 넘긴다(호출자가 배열에 push).
 *
 * `context`('init'/'component') 는 **그룹 정렬만** 컨텍스트별로 달리한다(스펙 카탈로그·
 * 출처 배지·검색은 동일). 페이지 init 은 상태/데이터 그룹을, 컴포넌트는 이동/모달
 * 그룹을 상단에 둔다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import {
  normalizeActionRecipes,
  buildAction,
  type NormalizedActionRecipe,
} from '../../spec/actionRecipeEngine';
import type { ActionRecipeSpec } from '../../spec/specTypes';
import type { RecipeSource } from '../../spec/editorSpecLoader';

export interface ActionAddPickerProps {
  /** 핸들러 스펙 맵(코어 시드 + 확장 병합본, `__source` 부착) */
  recipes?: Record<string, ActionRecipeSpec | string>;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 선택 시 빈 액션 1건 반환 */
  onAdd: (action: Record<string, unknown>) => void;
  /** 컨텍스트 — 그룹 정렬만 달리함(init=페이지 / component=컴포넌트) */
  context?: 'init' | 'component';
  /** testid 접두 */
  testIdPrefix?: string;
}

/** 그룹 정의 — 핸들러 id → 그룹 키 (코어 카탈로그) */
const GROUP_OF: Record<string, string> = {
  setState: 'state',
  loadFromLocalStorage: 'state',
  saveToLocalStorage: 'state',
  navigate: 'nav',
  openWindow: 'nav',
  navigateBack: 'nav',
  navigateForward: 'nav',
  replaceUrl: 'nav',
  toast: 'notify',
  openModal: 'notify',
  closeModal: 'notify',
  showAlert: 'notify',
  setError: 'notify',
  refetchDataSource: 'data',
  appendDataSource: 'data',
  updateDataSource: 'data',
  apiCall: 'data',
  scrollIntoView: 'etc',
  login: 'etc',
  logout: 'etc',
  setLocale: 'locale',
  emitEvent: 'etc',
  conditions: 'flow',
  sequence: 'flow',
  parallel: 'flow',
  switch: 'flow',
  suppress: 'flow',
  showErrorPage: 'error',
};

/** 그룹 키 → 다국어 라벨 키 */
const GROUP_LABEL: Record<string, string> = {
  state: 'layout_editor.action.group.state',
  nav: 'layout_editor.action.group.nav',
  notify: 'layout_editor.action.group.notify',
  data: 'layout_editor.action.group.data',
  locale: 'layout_editor.action.group.locale',
  etc: 'layout_editor.action.group.etc',
  flow: 'layout_editor.action.group.flow',
  error: 'layout_editor.action.group.error',
  extension: 'layout_editor.action.group.extension',
};

/** 컨텍스트별 그룹 정렬(상단 우선) */
const GROUP_ORDER: Record<'init' | 'component', string[]> = {
  init: ['state', 'data', 'notify', 'nav', 'locale', 'etc', 'flow', 'error', 'extension'],
  component: ['nav', 'notify', 'data', 'state', 'locale', 'etc', 'flow', 'error', 'extension'],
};

/** 레시피의 출처 메타 추출(__source) */
function sourceOf(recipes: Record<string, ActionRecipeSpec | string> | undefined, id: string): RecipeSource | null {
  const raw = recipes?.[id];
  if (raw && typeof raw === 'object') {
    const s = (raw as Record<string, unknown>).__source;
    if (s && typeof s === 'object') return s as RecipeSource;
  }
  return null;
}

/** 출처 배지 라벨 — 코어=〔코어〕 / 확장=식별자 */
function sourceBadge(source: RecipeSource | null, t: ActionAddPickerProps['t']): string {
  if (!source) return t('layout_editor.action.source_core');
  if (source.kind === 'core') return t('layout_editor.action.source_core');
  return source.id ?? t(`layout_editor.action.source_${source.kind}`);
}

/** `$t:` 라벨 해석 */
function label(raw: string | undefined, t: ActionAddPickerProps['t'], fallback: string): string {
  if (typeof raw !== 'string') return fallback;
  return raw.startsWith('$t:') ? t(raw.slice(3)) : raw;
}

/**
 * 핸들러 스펙 추가 목록.
 *
 * @param props ActionAddPickerProps
 * @return 동작 추가 피커 엘리먼트
 */
export function ActionAddPicker({
  recipes,
  t,
  onAdd,
  context = 'init',
  testIdPrefix = 'g7le-action-add',
}: ActionAddPickerProps): React.ReactElement {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');

  const normalized = useMemo(() => normalizeActionRecipes(recipes), [recipes]);

  // id → 그룹/출처 인덱스.
  const grouped = useMemo(() => {
    const order = GROUP_ORDER[context];
    const byGroup = new Map<string, Array<{ recipe: NormalizedActionRecipe; source: RecipeSource | null }>>();
    for (const recipe of normalized) {
      const source = sourceOf(recipes, recipe.id);
      // 확장 제공(core 아님)은 'extension' 그룹으로 묶는다.
      const isExtension = source != null && source.kind !== 'core';
      const groupKey = isExtension ? 'extension' : (GROUP_OF[recipe.id] ?? 'etc');
      const list = byGroup.get(groupKey) ?? [];
      list.push({ recipe, source });
      byGroup.set(groupKey, list);
    }
    const q = query.trim().toLowerCase();
    return order
      .map((groupKey) => {
        let items = byGroup.get(groupKey) ?? [];
        if (q) {
          items = items.filter(({ recipe }) => {
            const lbl = label(recipe.label, t, recipe.id).toLowerCase();
            return lbl.includes(q) || recipe.id.toLowerCase().includes(q) || recipe.build.handler.toLowerCase().includes(q);
          });
        }
        return { groupKey, items };
      })
      .filter((g) => g.items.length > 0);
  }, [normalized, recipes, context, query, t]);

  if (normalized.length === 0) {
    return (
      <div data-testid={`${testIdPrefix}-empty`} style={emptyNotice}>
        {t('layout_editor.action.no_recipes')}
      </div>
    );
  }

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix} style={{ minWidth: 0 }}>
      <button type="button" data-testid={`${testIdPrefix}-toggle`} onClick={() => setOpen((v) => !v)} style={addBtn}>
        {t('layout_editor.action.add_action')} ▾
      </button>
      {open ? (
        <div data-testid={`${testIdPrefix}-list`} style={listBox}>
          <input
            type="text"
            data-testid={`${testIdPrefix}-search`}
            value={query}
            placeholder={t('layout_editor.action.add_search')}
            onChange={(e) => setQuery(e.target.value)}
            style={searchInput}
          />
          {grouped.map(({ groupKey, items }) => (
            <div key={groupKey} data-testid={`${testIdPrefix}-group-${groupKey}`}>
              <div style={groupTitle}>━━ {t(GROUP_LABEL[groupKey] ?? groupKey)} ━━</div>
              {items.map(({ recipe, source }) => (
                <button
                  key={recipe.id}
                  type="button"
                  data-testid={`g7le-init-action-spec-${recipe.id}`}
                  onClick={() => {
                    onAdd(buildAction(recipe, {}));
                    setOpen(false);
                    setQuery('');
                  }}
                  style={specItem}
                >
                  <span style={specLabel}>{label(recipe.label, t, recipe.id)}</span>
                  <span data-testid={`g7le-init-action-spec-source-${recipe.id}`} style={badge}>
                    〔{sourceBadge(source, t)}〕
                  </span>
                  <code style={handlerHint}>{recipe.build.handler}</code>
                </button>
              ))}
            </div>
          ))}
          {grouped.length === 0 ? (
            <div data-testid={`${testIdPrefix}-no-match`} style={noMatch}>
              {t('layout_editor.action.add_no_match')}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

const addBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const listBox: React.CSSProperties = { marginTop: 6, border: '1px solid #cbd5e1', borderRadius: 8, background: '#fff', maxHeight: 320, overflowY: 'auto', padding: 8 };
const searchInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, marginBottom: 6, boxSizing: 'border-box' };
const groupTitle: React.CSSProperties = { fontSize: 11, fontWeight: 700, color: '#94a3b8', padding: '6px 4px 2px' };
const specItem: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', textAlign: 'left', padding: '6px 8px', fontSize: 12, border: 'none', background: 'transparent', cursor: 'pointer', borderRadius: 6 };
const specLabel: React.CSSProperties = { flex: 1, minWidth: 0, color: '#0f172a' };
const badge: React.CSSProperties = { fontSize: 10, color: '#64748b', whiteSpace: 'nowrap' };
const handlerHint: React.CSSProperties = { fontSize: 10, color: '#cbd5e1', fontFamily: 'monospace', whiteSpace: 'nowrap' };
const noMatch: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '8px 4px' };
const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '8px 0' };
