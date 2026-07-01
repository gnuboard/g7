/**
 * ActionListBuilder.tsx — 다중 액션 빌더
 *
 * 액션 배열(init_actions·sequence/parallel·onSuccess/onError·onReceive·컴포넌트 이벤트 슬롯)을
 * 친화 카드 목록으로 편집하는 **공용 동작 빌더**. 종전 세 빌더([화면 동작] InitActionsForm ·
 * 컴포넌트 [동작] ActionRecipeEditor · 본 빌더)가 카드/드래그/추가/편집을 각자 복제했는데
 * (② "공통화 안 됨"), 본 빌더가 그 기능 전체(친화 카드 + 추가 picker + params/if 편집 +
 * 출처/조건 배지 + 드래그 + 상속 잠금)를 흡수해 단일 SSoT 가 된다.
 *
 * 각 카드 = 친화 라벨 + 출처 배지(옵션) + 친화 요약/코드(ActionPreview) + [편집](params 폼 +
 * if 토글) + 순서 드래그 + 삭제. 차이(상속 그룹 분리·이벤트 슬롯 루프)는 호출자가 처리하고,
 * 본 빌더는 단일 배열 카드 책임만 갖는다(호출자가 슬롯/그룹별로 본 빌더를 여러 번 마운트).
 *
 *  - `recipes` 전달 시 기본 동작: 추가 picker(ActionAddPicker)·편집 폼(ParamFieldList +
 *    ActionIfToggle)·친화 요약(ActionPreview)·출처 배지를 자동 제공한다(`renderAddPicker`/
 *    `renderEditor` 미주입 시). 데이터소스 onSuccess/onError 가 종전엔 picker/editor 미주입이라
 *  동작을 추가조차 못 했는데(②), 본 기본 동작으로 해소된다.
 *  - `renderAddPicker`/`renderEditor` 를 주입하면 그 렌더가 우선한다(역호환 — 종전 호출 보존).
 *  - `canDrag`/`canDropAt` 으로 상속 base 잠금/경계 불가침을 흡수(InitActionsForm 상속 모드).
 *
 * @since engine-v1.50.0 · 공용화 engine-v1.50.0
 */

import React, { useState, useCallback } from 'react';
import {
  normalizeActionRecipes,
  resolveActionCard,
  summarizeAction,
  extractActionIf,
  type NormalizedActionRecipe,
} from '../../spec/actionRecipeEngine';
import type { ActionRecipeSpec, EditorActionChipCandidatesSpec } from '../../spec/specTypes';
import type { RecipeSource } from '../../spec/editorSpecLoader';
import { buildActionContextCandidates, type BindingCandidate } from '../../spec/bindingCandidates';
import { DropLine } from '../DropLine';
import { useListDragReorder } from '../../hooks/useListDragReorder';
import { ActionAddPicker } from './ActionAddPicker';
import { ActionPreview } from './ActionPreview';
import { ParamFieldList, ActionIfToggle, type ActionParamCandidatePools } from './ActionParamFields';

/** 액션 1건(JSON) */
export type ActionItem = Record<string, unknown>;

/** ActionListBuilder props */
export interface ActionListBuilderProps {
  /** 편집 대상 액션 배열 */
  actions: ActionItem[];
  /** 배열 변경 콜백 */
  onChange: (next: ActionItem[]) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 친화 요약/카드 해석용 레시피 맵(코어 시드 + 확장 병합본) */
  recipes?: Record<string, ActionRecipeSpec | string>;
  /**
   * 데이터칩 컨텍스트 — response/error/payload/init 별. response/error/payload 면 그 컨텍스트
   * 칩(`response.*`/`error.*`/`message.*`)을 candidatePools.bindingCandidates 앞에 합쳐 편집 폼·
   * 중첩 빌더에 흘린다(apiCall onSuccess 안에서도 응답 칩 유지). init/미지정이면 컨텍스트 칩 없음.
   */
  chipContext?: 'response' | 'error' | 'payload' | 'init';
  /**
   * 확장 응답 칩 후보 — 병합 editor-spec 의 `actionChipCandidates`. chipContext 가
   * response/error/payload 일 때 코어 기본 칩 뒤에 도메인 응답 필드(PG 결제 응답 등)를 더한다.
   * 코어는 도메인을 모르고, 확장이 자기 editor-spec 으로 선언한 후보만 노출한다.
   */
  actionChipCandidates?: EditorActionChipCandidatesSpec | null;
  /** 추가 picker 컨텍스트(기본 ActionAddPicker 정렬 — init=페이지/component=컴포넌트). 기본 init. */
  addContext?: 'init' | 'component';
  /** 추가 picker 렌더 프롭(주입 시 우선). 미주입 시 recipes 있으면 기본 ActionAddPicker. */
  renderAddPicker?: (onAdd: (action: ActionItem) => void) => React.ReactNode;
  /** 인스턴스 편집 폼 렌더 프롭(주입 시 우선). 미주입 시 기본 params 폼 + if 토글([편집] 토글). */
  renderEditor?: (action: ActionItem, onPatch: (next: ActionItem) => void) => React.ReactNode;
  /** params 위젯 후보 풀(기본 편집 폼용). */
  candidatePools?: ActionParamCandidatePools;
  /** 출처 배지 표시(기본 false — 데이터소스 onSuccess 등은 미표시, 화면 동작/컴포넌트 동작은 표시). */
  showSourceBadge?: boolean;
  /** 항목 index 가 드래그 가능한지(상속 base 잠금). 미전달 시 전부 가능. */
  canDrag?: (index: number) => boolean;
  /** 끌던 항목(from)을 삽입 지점(to)으로 떨굴 수 있는지(경계 불가침). 미전달 시 가능. */
  canDropAt?: (from: number, to: number) => boolean;
  /** 항목 index 가 잠금(읽기전용 — 상속 base)인지. 미전달 시 전부 편집 가능. */
  isLocked?: (index: number) => boolean;
  /**
   * 추가 picker 숨김(읽기전용 그룹 — 상속 base). 켜면 "동작 추가" 버튼을 그리지 않는다.
   * base 그룹은 부모 레이아웃 소관이라 자식 페이지 설정에서 동작을 추가할 수 없다.
   */
  hideAddPicker?: boolean;
  /** testid 접두사 */
  testIdPrefix?: string;
}

/** 출처 배지 라벨 */
function sourceBadgeLabel(
  recipes: Record<string, ActionRecipeSpec | string> | undefined,
  recipeId: string | null,
  t: ActionListBuilderProps['t'],
): string {
  if (!recipeId) return t('layout_editor.action.source_core');
  const raw = recipes?.[recipeId];
  const src =
    raw && typeof raw === 'object' && (raw as Record<string, unknown>).__source
      ? ((raw as Record<string, unknown>).__source as RecipeSource)
      : null;
  if (!src || src.kind === 'core') return t('layout_editor.action.source_core');
  return src.id ?? t(`layout_editor.action.source_${src.kind}`);
}

/** 액션 1건의 친화 요약 — 카드 매칭 시 스펙 라벨, 미매칭 시 핸들러명 폴백 */
function summarize(
  action: ActionItem,
  recipes: NormalizedActionRecipe[],
  t: ActionListBuilderProps['t'],
): string {
  const card = resolveActionCard(action, recipes);
  if (card.kind === 'preset') {
    const recipe = recipes.find((r) => r.id === card.recipeId);
    return summarizeAction(recipe ?? null, card.values, (k) => t(k.startsWith('$t:') ? k.slice(3) : k));
  }
  // 미매칭(advanced) — 핸들러명 폴백(코드 편집기에서 작성됨).
  return typeof action.handler === 'string' ? action.handler : t('layout_editor.action_list.unknown');
}

/**
 * 다중 액션 빌더.
 *
 * @param props ActionListBuilderProps
 * @return 액션 카드 목록 엘리먼트
 */
export function ActionListBuilder({
  actions,
  onChange,
  t,
  recipes,
  chipContext,
  actionChipCandidates,
  addContext = 'init',
  renderAddPicker,
  renderEditor,
  candidatePools,
  showSourceBadge = false,
  canDrag,
  canDropAt,
  isLocked,
  hideAddPicker = false,
  testIdPrefix = 'g7le-action-list',
}: ActionListBuilderProps): React.ReactElement {
  const normalized = React.useMemo(() => normalizeActionRecipes(recipes), [recipes]);

  // 컨텍스트 칩 주입 — chipContext 가 response/error/payload 면 그 컨텍스트 변수 칩
  // (`response.*`/`error.*`/`message.*` + 확장 도메인 응답 필드)을 candidatePools.bindingCandidates
  // 앞에 합친다. 데이터칩 입력칸(DataChipValueInput)이 이 후보로 응답값을 칩으로 연결한다.
  // init/미지정이면 컨텍스트 칩 없이 원본 pools 그대로(컨텍스트 없는 동작 — 화면 동작 탭 등).
  //
  // 이미 base.bindingCandidates 에 같은 expression 이 있으면(예: ErrorHandlingForm 이 errorCands 를
  // pools 에 직접 주입한 경우) 그 칩은 건너뛴다 — 컨텍스트 칩 이중 합산 방지(중복 후보 노출 회귀).
  const effectivePools = React.useMemo<ActionParamCandidatePools | undefined>(() => {
    if (chipContext == null || chipContext === 'init') return candidatePools;
    const ctxCands = buildActionContextCandidates(chipContext, t, actionChipCandidates ?? null);
    if (ctxCands.length === 0) return candidatePools;
    const base = candidatePools ?? {};
    const baseExprs = new Set((base.bindingCandidates ?? []).map((c) => c.expression));
    const freshCtx = ctxCands.filter((c) => !baseExprs.has(c.expression));
    if (freshCtx.length === 0) return candidatePools;
    return { ...base, bindingCandidates: [...freshCtx, ...(base.bindingCandidates ?? [])] };
  }, [chipContext, actionChipCandidates, candidatePools, t]);
  const [codeOpen, setCodeOpen] = useState<number | null>(null);
  const [editOpen, setEditOpen] = useState<number | null>(null);

  // 드래그 재배치 + 드롭 위치 표시 — `⠿` 핸들 HTML5 drag. 캔버스 DnD 와 동일한 삽입선(DropLine).
  // 상속 잠금/경계 불가침은 canDrag/canDropAt 으로(InitActionsForm 상속 모드 흡수).
  const moveTo = useCallback(
    (from: number, to: number): void => {
      if (from === to || from < 0 || to < 0 || from >= actions.length || to >= actions.length) return;
      const next = actions.slice();
      const [moved] = next.splice(from, 1);
      next.splice(to, 0, moved);
      onChange(next);
    },
    [actions, onChange],
  );
  const dnd = useListDragReorder({ length: actions.length, onMove: moveTo, canDrag, canDropAt });

  const remove = useCallback(
    (index: number): void => {
      onChange(actions.filter((_, i) => i !== index));
    },
    [actions, onChange],
  );

  const patch = useCallback(
    (index: number, nextAction: ActionItem): void => {
      const next = actions.slice();
      next[index] = nextAction;
      onChange(next);
    },
    [actions, onChange],
  );

  const add = useCallback(
    (action: ActionItem): void => {
      onChange([...actions, action]);
    },
    [actions, onChange],
  );

  // 기본 편집 폼(renderEditor 미주입 + recipes 보유 시) — params 폼 + if 토글.
  const defaultEditor = useCallback(
    (action: ActionItem, onPatch: (next: ActionItem) => void): React.ReactNode => {
      const card = resolveActionCard(action, normalized);
      const recipe = card.kind === 'preset' ? normalized.find((r) => r.id === card.recipeId) ?? null : null;
      if (!recipe) return null; // 고급(미매칭) — 코드 보기만.
      const values = card.kind === 'preset' ? card.values : {};
      return (
        <div style={editBody}>
          <ParamFieldList
            raw={action}
            recipe={recipe}
            values={values}
            t={t}
            pools={effectivePools ?? {}}
            onChange={onPatch}
            testIdPrefix={`${testIdPrefix}-edit`}
            // onSuccess/onError 등 중첩 action-list 위젯 — 공용 빌더 자기 자신을 재귀 주입(무한
            // 의존 회피: 렌더프롭). 중첩 빌더도 같은 recipes/기본 picker·editor 를 쓴다.
            //
            // 데이터칩 컨텍스트 전환: onSuccess/onError 는 새 컨텍스트 진입점이라 그 빌더에
            // chipContext(response/error)를 주고 base 는 원본 candidatePools 로 둔다(빌더가 컨텍스트
            // 칩을 합산 — effectivePools 를 base 로 주면 부모 컨텍스트 칩이 이중 합산된다). 그 외
            // (sequence/parallel 의 actions, conditions then 등)는 전환점이 아니라 부모 컨텍스트를
            // 그대로 이어받으므로 이미 칩이 합쳐진 effectivePools 를 흘리고 chipContext 는 주지 않는다.
            renderActionList={(nested, onNestedChange, nestedPrefix, paramKey) => {
              const nestedContext: ActionListBuilderProps['chipContext'] | undefined =
                paramKey === 'onSuccess' ? 'response' : paramKey === 'onError' ? 'error' : undefined;
              return (
                <ActionListBuilder
                  actions={nested}
                  onChange={onNestedChange}
                  t={t}
                  recipes={recipes}
                  chipContext={nestedContext}
                  actionChipCandidates={nestedContext ? actionChipCandidates : undefined}
                  candidatePools={nestedContext ? candidatePools : effectivePools}
                  addContext={addContext}
                  showSourceBadge={showSourceBadge}
                  testIdPrefix={nestedPrefix}
                />
              );
            }}
          />
          <ActionIfToggle raw={action} t={t} onChange={onPatch} testIdPrefix={`${testIdPrefix}-edit`} candidates={effectivePools?.bindingCandidates} />
        </div>
      );
    },
    [normalized, t, effectivePools, candidatePools, actionChipCandidates, testIdPrefix, recipes, addContext, showSourceBadge],
  );

  const editorFor = renderEditor ?? (recipes ? defaultEditor : undefined);
  // 추가 picker — 주입 우선, 미주입 시 recipes 있으면 기본 ActionAddPicker. hideAddPicker(읽기전용
  // base 그룹)면 그리지 않는다 — 자식 페이지 설정에서 부모 동작 추가 불가.
  const addPicker = hideAddPicker
    ? null
    : renderAddPicker
      ? renderAddPicker(add)
      : recipes
        ? <ActionAddPicker recipes={recipes} t={t} onAdd={add} context={addContext} testIdPrefix={`${testIdPrefix}-add-picker`} />
        : null;

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix}>
      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
        {actions.map((action, index) => {
          const card = resolveActionCard(action, normalized);
          const recipeId = card.kind === 'preset' ? card.recipeId : null;
          const recipe = recipeId ? normalized.find((r) => r.id === recipeId) ?? null : null;
          const advanced = recipe === null;
          const locked = isLocked?.(index) ?? false;
          const hasIf = extractActionIf(action) !== null;
          const editorNode = editorFor ? editorFor(action, (next) => patch(index, next)) : null;
          return (
            <React.Fragment key={index}>
              {/* 삽입선 — 잠금(base) 항목 앞에는 그리지 않음(드롭 불가 구간). */}
              {!locked ? (
                <li aria-hidden="true" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                  <DropLine active={dnd.isDropTarget(index)} testid={`${testIdPrefix}-dropline-${index}`} />
                </li>
              ) : null}
              <li
                data-testid={`${testIdPrefix}-item-${index}`}
                data-drag-source={dnd.dragIndex === index ? 'true' : undefined}
                style={{
                  border: '1px solid #e2e8f0',
                  borderRadius: 8,
                  padding: '8px 10px',
                  ...(dnd.dragIndex === index ? { opacity: 0.5 } : {}),
                }}
                onDragOver={
                  dnd.dragging && !locked
                    ? (e) => {
                        e.preventDefault();
                        const rect = e.currentTarget.getBoundingClientRect();
                        dnd.onDragOverItem(index, e.clientY < rect.top + rect.height / 2 ? 'before' : 'after');
                      }
                    : undefined
                }
                onDrop={
                  dnd.dragging && !locked
                    ? (e) => {
                        e.preventDefault();
                        dnd.onDrop();
                      }
                    : undefined
                }
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  {!locked ? (
                    <span
                      data-testid={`${testIdPrefix}-drag-${index}`}
                      style={{ color: '#94a3b8', cursor: 'grab', fontSize: 14, userSelect: 'none' }}
                      draggable
                      onDragStart={() => dnd.onDragStart(index)}
                      onDragEnd={dnd.onDragEnd}
                      aria-label={t('layout_editor.action_list.drag_reorder')}
                      role="button"
                    >
                      ⠿
                    </span>
                  ) : (
                    <span style={{ fontSize: 12 }}>🔒</span>
                  )}
                  <span style={{ flex: 1, minWidth: 0, fontSize: 13 }} data-testid={`${testIdPrefix}-summary-${index}`}>
                    {summarize(action, normalized, t)}
                  </span>
                  {showSourceBadge ? (
                    <span data-testid={`${testIdPrefix}-source-${index}`} style={badge}>
                      〔{sourceBadgeLabel(recipes, recipeId, t)}〕
                    </span>
                  ) : null}
                  {hasIf ? (
                    <span data-testid={`${testIdPrefix}-cond-badge-${index}`} style={condBadge}>
                      〔{t('layout_editor.init_actions.cond_badge')}〕
                    </span>
                  ) : null}
                  {advanced ? (
                    <span data-testid={`${testIdPrefix}-advanced-${index}`} style={advBadge}>
                      [{t('layout_editor.action.advanced')}]
                    </span>
                  ) : null}
                  {!locked && editorNode ? (
                    <button
                      type="button"
                      data-testid={`${testIdPrefix}-edit-${index}`}
                      onClick={() => setEditOpen(editOpen === index ? null : index)}
                      aria-label={t('layout_editor.init_actions.edit')}
                      title={t('layout_editor.init_actions.edit')}
                      style={editOpen === index ? cardBtnActive : cardBtn}
                    >
                      ✎ {t('layout_editor.init_actions.edit')}
                    </button>
                  ) : null}
                  <button
                    type="button"
                    data-testid={`${testIdPrefix}-code-${index}`}
                    onClick={() => setCodeOpen(codeOpen === index ? null : index)}
                    aria-label={t('layout_editor.action_list.view_code')}
                    title={t('layout_editor.action_list.view_code')}
                    style={codeOpen === index ? iconBtnActive : iconBtn}
                  >
                    {'</>'}
                  </button>
                  {!locked ? (
                    <button
                      type="button"
                      data-testid={`${testIdPrefix}-remove-${index}`}
                      onClick={() => remove(index)}
                      aria-label={t('layout_editor.action_list.remove')}
                      title={t('layout_editor.action_list.remove')}
                      style={iconBtnDanger}
                    >
                      ✕
                    </button>
                  ) : null}
                </div>
                {/* ActionPreview — 친화 요약/누락 표시(recipe 매칭 시). */}
                {recipe ? (
                  <ActionPreview
                    action={action}
                    recipe={recipe}
                    values={card.kind === 'preset' ? card.values : {}}
                    t={t}
                    // 카드 우측에 이미 `</>` 코드 버튼이 있으므로 미리보기는 요약만(코드 버튼 중복
                    // 제거). 코드 펼침은 카드 단일 `</>`(code-view)가 담당.
                    showCodeButton={false}
                    testIdPrefix={`${testIdPrefix}-preview-${index}`}
                  />
                ) : null}
                {/* 편집 폼(렌더프롭 우선, 미주입 시 기본 params/if). 토글로 열기. */}
                {editOpen === index && !locked && editorNode ? (
                  <div data-testid={`${testIdPrefix}-edit-body-${index}`}>{editorNode}</div>
                ) : null}
                {/* 렌더프롭 editor 가 토글 없이 항상 표시되던 종전 호환 — renderEditor 주입 시 인라인 노출. */}
                {renderEditor && editOpen !== index ? renderEditor(action, (next) => patch(index, next)) : null}
                {codeOpen === index ? (
                  <pre
                    data-testid={`${testIdPrefix}-code-view-${index}`}
                    style={{
                      margin: '8px 0 0',
                      padding: 8,
                      background: '#0f172a',
                      color: '#e2e8f0',
                      borderRadius: 6,
                      fontSize: 12,
                      overflow: 'auto',
                    }}
                  >
                    {JSON.stringify(action, null, 2)}
                  </pre>
                ) : null}
              </li>
            </React.Fragment>
          );
        })}
        {/* 마지막 삽입선 — 리스트 끝(length)에 떨어뜨릴 때. */}
        <li aria-hidden="true" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
          <DropLine active={dnd.isDropTarget(actions.length)} testid={`${testIdPrefix}-dropline-end`} />
        </li>
      </ul>
      {addPicker ? <div style={{ marginTop: 8 }}>{addPicker}</div> : null}
    </div>
  );
}

const badge: React.CSSProperties = { fontSize: 10, color: '#64748b', whiteSpace: 'nowrap' };
const condBadge: React.CSSProperties = { fontSize: 10, color: '#7c3aed', whiteSpace: 'nowrap' };
const advBadge: React.CSSProperties = { fontSize: 10, color: '#92400e', whiteSpace: 'nowrap' };
const editBody: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, paddingTop: 6, borderTop: '1px dashed #e2e8f0', marginTop: 6 };

// 카드 우측 액션 버튼 — 종전엔 style 미지정으로 브라우저 기본 버튼(조악)이었다.
// [편집]=텍스트 버튼, [</>]·[✕]=아이콘 버튼. 활성 상태(펼침)는 강조색, 삭제는 위험색.
const cardBtn: React.CSSProperties = { flex: '0 0 auto', padding: '3px 8px', fontSize: 11, fontWeight: 500, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap', lineHeight: 1.4 };
const cardBtnActive: React.CSSProperties = { ...cardBtn, background: '#eff6ff', borderColor: '#bfdbfe', color: '#2563eb' };
const iconBtn: React.CSSProperties = { flex: '0 0 auto', width: 26, height: 24, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer', fontFamily: 'ui-monospace, monospace' };
const iconBtnActive: React.CSSProperties = { ...iconBtn, background: '#0f172a', borderColor: '#0f172a', color: '#e2e8f0' };
const iconBtnDanger: React.CSSProperties = { ...iconBtn, color: '#dc2626', borderColor: '#fecaca', fontFamily: 'inherit' };
