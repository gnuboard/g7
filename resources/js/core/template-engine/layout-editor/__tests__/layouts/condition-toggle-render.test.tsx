/**
 * condition-toggle-render.test.tsx — 합성 if 식이 ConditionEvaluator 로 정확히 평가
 *
 *
 * ConditionBuilder 가 combineConditions 로 만든 단일 `{{ }}` 식이 실제 런타임
 * `evaluateStringCondition`(→ optional chaining 식이라 `evaluateExpression` 진입,
 * 코어 `if` 평가 규칙)으로 샘플 상태에 따라 표시/숨김 토글되는지 검증한다.
 *
 * 단위 시뮬레이션이 아닌 실제 평가 엔진 라운드트립 — 합성식의 문법 정합성을 잠근다.
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { evaluateStringCondition } from '../../../helpers/ConditionEvaluator';
import {
  normalizeConditionRecipes,
  combineConditions,
  type ConditionClause,
} from '../../spec/conditionRecipeEngine';

const ops = normalizeConditionRecipes({
  operators: [
    { value: 'isLoggedIn', label: 'l', expr: '_global?.currentUser?.uuid' },
    { value: 'dsHasData', label: 'd', params: [{ key: 'src' }], expr: '({src}?.data?.length ?? 0) > 0' },
    { value: 'fieldEquals', label: 'f', params: [{ key: 'field' }, { key: 'value' }], expr: "{field} === '{value}'" },
  ],
});

const engine = new DataBindingEngine();

/** 합성 → 평가 헬퍼 */
function evalSynthesized(clauses: ConditionClause[], combinator: 'and' | 'or', ctx: Record<string, unknown>): boolean {
  const expr = combineConditions(clauses, combinator, ops);
  return evaluateStringCondition(expr, ctx, engine);
}

describe('합성 if 식 ↔ ConditionEvaluator 평가', () => {
  it('로그인 상태 토글 → 회원 전용 영역 표시/숨김', () => {
    const clause: ConditionClause[] = [{ operator: 'isLoggedIn', params: {} }];
    expect(evalSynthesized(clause, 'and', { _global: { currentUser: { uuid: 'u-1' } } })).toBe(true);
    expect(evalSynthesized(clause, 'and', { _global: { currentUser: {} } })).toBe(false);
    // _global 자체 부재 — 옵셔널 체이닝으로 안전(에러 없이 false)
    expect(evalSynthesized(clause, 'and', {})).toBe(false);
  });

  it('데이터소스 빈 배열 → "데이터 없음" 분기 (length ?? 0 fallback)', () => {
    const clause: ConditionClause[] = [{ operator: 'dsHasData', params: { src: 'posts' } }];
    expect(evalSynthesized(clause, 'and', { posts: { data: [{ id: 1 }] } })).toBe(true);
    expect(evalSynthesized(clause, 'and', { posts: { data: [] } })).toBe(false);
    expect(evalSynthesized(clause, 'and', { posts: {} })).toBe(false);
  });

  it('필드 값 변경 → 조건부 영역', () => {
    const clause: ConditionClause[] = [{ operator: 'fieldEquals', params: { field: 'status', value: 'open' } }];
    expect(evalSynthesized(clause, 'and', { status: 'open' })).toBe(true);
    expect(evalSynthesized(clause, 'and', { status: 'closed' })).toBe(false);
  });

  it('AND 결합 — 둘 다 참일 때만', () => {
    const clauses: ConditionClause[] = [
      { operator: 'isLoggedIn', params: {} },
      { operator: 'dsHasData', params: { src: 'posts' } },
    ];
    expect(evalSynthesized(clauses, 'and', { _global: { currentUser: { uuid: 'x' } }, posts: { data: [1] } })).toBe(true);
    expect(evalSynthesized(clauses, 'and', { _global: { currentUser: { uuid: 'x' } }, posts: { data: [] } })).toBe(false);
  });

  it('OR 결합 — 하나만 참이어도', () => {
    const clauses: ConditionClause[] = [
      { operator: 'isLoggedIn', params: {} },
      { operator: 'dsHasData', params: { src: 'posts' } },
    ];
    expect(evalSynthesized(clauses, 'or', { _global: { currentUser: {} }, posts: { data: [1] } })).toBe(true);
    expect(evalSynthesized(clauses, 'or', { _global: { currentUser: {} }, posts: { data: [] } })).toBe(false);
  });

  it('합성식은 정확히 단일 {{ }} 한 쌍 (중첩 보간 없음)', () => {
    const expr = combineConditions(
      [{ operator: 'isLoggedIn', params: {} }, { operator: 'fieldEquals', params: { field: 'a', value: 'b' } }],
      'and',
      ops,
    );
    expect((expr.match(/\{\{/g) ?? []).length).toBe(1);
    expect((expr.match(/\}\}/g) ?? []).length).toBe(1);
  });
});

// ── 번들 2템플릿 conditionRecipes 전 operator 전수 평가 ──
//
// 실제 번들 editor-spec.json 의 operator 를 전부 로드해, 각 operator 의 합성식이
// (1) 단일 `{{ }}` 한 쌍이고 (2) 실제 ConditionEvaluator 로 truthy/falsy 가 의도대로
// 평가되는지 데이터 드리븐으로 검증한다. operator 추가/expr 변경 시 자동 커버.
// 번들 editor-spec 은 S7 에서 manifest + `$include` 블록으로 분할됨 — 합본 헬퍼로 단일
// spec 복원(PHP EditorSpecAssembler 와 동일 규칙). conditionRecipes 는 분할 블록에 있으므로
// 정적 JSON import 로는 비어 깨진다. fs 합본으로 읽는다.
import { resolve } from 'node:path';
import { assembleEditorSpec } from '../spec/assembleEditorSpecFixture';

const basicSpec = assembleEditorSpec(
  resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic/editor-spec.json'),
);
const adminSpec = assembleEditorSpec(
  resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec.json'),
);

function loadOps(spec: { conditionRecipes?: unknown }) {
  return normalizeConditionRecipes(spec.conditionRecipes as never);
}

/** operator value → { params, truthyCtx, falsyCtx } — 의도 평가 케이스 */
const CASES: Record<string, { params?: Record<string, string>; truthy: Record<string, unknown>; falsy: Record<string, unknown> }> = {
  isLoggedIn: { truthy: { _global: { currentUser: { uuid: 'u' } } }, falsy: { _global: { currentUser: {} } } },
  isGuest: { truthy: { _global: { currentUser: {} } }, falsy: { _global: { currentUser: { uuid: 'u' } } } },
  isAdmin: { truthy: { _global: { currentUser: { is_admin: true } } }, falsy: { _global: { currentUser: { is_admin: false } } } },
  isSaving: { truthy: { _local: { isSaving: true } }, falsy: { _local: { isSaving: false } } },
  notSaving: { truthy: { _local: { isSaving: false } }, falsy: { _local: { isSaving: true } } },
  hasChanges: { truthy: { _local: { hasChanges: true } }, falsy: { _local: { hasChanges: false } } },
  hasErrors: { truthy: { _local: { errors: { x: 'e' } } }, falsy: { _local: {} } },
  noErrors: { truthy: { _local: {} }, falsy: { _local: { errors: { x: 'e' } } } },
  dsHasData: { params: { src: 'posts' }, truthy: { posts: { data: [1] } }, falsy: { posts: { data: [] } } },
  dsEmpty: { params: { src: 'posts' }, truthy: { posts: { data: [] } }, falsy: { posts: { data: [1] } } },
  dsLoading: { params: { src: 'posts' }, truthy: { posts: { loading: true } }, falsy: { posts: { loading: false } } },
  dsError: { params: { src: 'posts' }, truthy: { posts: { error: 'x' } }, falsy: { posts: {} } },
  fieldEquals: { params: { field: 'status', value: 'open' }, truthy: { status: 'open' }, falsy: { status: 'closed' } },
  fieldNotEquals: { params: { field: 'status', value: 'open' }, truthy: { status: 'closed' }, falsy: { status: 'open' } },
  stateEquals: { params: { key: 'tab', value: 'detail' }, truthy: { tab: 'detail' }, falsy: { tab: 'list' } },
  isEditMode: { truthy: { route: { id: '5' } }, falsy: { route: {} } },
  isCreateMode: { truthy: { route: {} }, falsy: { route: { id: '5' } } },
  queryEquals: { params: { param: 'mode', value: 'edit' }, truthy: { query: { mode: 'edit' } }, falsy: { query: { mode: 'view' } } },
  fieldHasError: { params: { field: 'email' }, truthy: { _local: { errors: { email: 'e' } } }, falsy: { _local: { errors: {} } } },
  // admin 전용
  hasAbility: { params: { scope: 'me', value: '' as never, ability: 'edit' } as Record<string, string>, truthy: { me: { abilities: { edit: true } } }, falsy: { me: { abilities: { edit: false } } } },
  // P1 범용 — 임의 경로 truthy/falsy
  valueTruthy: { params: { path: '_global.isDeleting' }, truthy: { _global: { isDeleting: true } }, falsy: { _global: { isDeleting: false } } },
  valueFalsy: { params: { path: '_local.success' }, truthy: { _local: { success: false } }, falsy: { _local: { success: true } } },
  // P2/P3 — 배열길이 + 숫자비교
  listNonEmpty: { params: { path: 'items.data' }, truthy: { items: { data: [1] } }, falsy: { items: { data: [] } } },
  listEmpty: { params: { path: 'items.data' }, truthy: { items: { data: [] } }, falsy: { items: { data: [1] } } },
  numGt: { params: { path: 'cartCount', n: '0' }, truthy: { cartCount: 5 }, falsy: { cartCount: 0 } },
  numGte: { params: { path: 'cartCount', n: '3' }, truthy: { cartCount: 3 }, falsy: { cartCount: 2 } },
  numLt: { params: { path: 'cartCount', n: '3' }, truthy: { cartCount: 1 }, falsy: { cartCount: 5 } },
  numLte: { params: { path: 'cartCount', n: '3' }, truthy: { cartCount: 3 }, falsy: { cartCount: 4 } },
  // P5 — 명시 비교 === true / === false
  valueIsTrue: { params: { path: '_local.modalOpen' }, truthy: { _local: { modalOpen: true } }, falsy: { _local: { modalOpen: false } } },
  valueIsFalse: { params: { path: '_local.bulkApplyAll' }, truthy: { _local: { bulkApplyAll: false } }, falsy: { _local: { bulkApplyAll: true } } },
  // 비교 패턴 net-new (!==false/true, undefined/null 존재, 숫자동등, 경로 동등/부등)
  valueNotFalse: { params: { path: '_local.flag' }, truthy: { _local: { flag: true } }, falsy: { _local: { flag: false } } },
  valueNotTrue: { params: { path: '_local.flag' }, truthy: { _local: { flag: false } }, falsy: { _local: { flag: true } } },
  valueDefined: { params: { path: '_local.v' }, truthy: { _local: { v: 0 } }, falsy: { _local: {} } },
  valueUndefined: { params: { path: '_local.v' }, truthy: { _local: {} }, falsy: { _local: { v: 0 } } },
  valueNotNull: { params: { path: '_local.v' }, truthy: { _local: { v: 1 } }, falsy: { _local: { v: null } } },
  valueIsNull: { params: { path: '_local.v' }, truthy: { _local: { v: null } }, falsy: { _local: { v: 1 } } },
  fieldMatches: { params: { a: '_local.x', b: '_local.y' }, truthy: { _local: { x: 1, y: 1 } }, falsy: { _local: { x: 1, y: 2 } } },
  fieldDiffers: { params: { a: '_local.x', b: '_local.y' }, truthy: { _local: { x: 1, y: 2 } }, falsy: { _local: { x: 1, y: 1 } } },
};

describe.each([
  ['basic', basicSpec],
  ['admin', adminSpec],
])('번들 %s editor-spec 전 operator 평가', (_name, spec) => {
  const bundleOps = loadOps(spec as { conditionRecipes?: unknown });

  it('모든 operator 가 CASES 에 정의돼 있다 (커버리지 누락 차단)', () => {
    const missing = bundleOps.filter((o) => !CASES[o.value]).map((o) => o.value);
    expect(missing).toEqual([]);
  });

  it.each(bundleOps.map((o) => [o.value]))('operator %s — 단일 {{}} + truthy/falsy 평가', (value) => {
    const op = bundleOps.find((o) => o.value === value)!;
    const c = CASES[value];
    const params: Record<string, string> = {};
    for (const k of (op.params ?? []).map((p) => p.key)) params[k] = c.params?.[k] ?? '';
    const expr = combineConditions([{ operator: value, params }], 'and', bundleOps);
    // 단일 {{ }} 한 쌍
    expect((expr.match(/\{\{/g) ?? []).length).toBe(1);
    expect((expr.match(/\}\}/g) ?? []).length).toBe(1);
    // 실제 평가 — truthy/falsy 의도대로
    expect(evaluateStringCondition(expr, c.truthy, engine)).toBe(true);
    expect(evaluateStringCondition(expr, c.falsy, engine)).toBe(false);
  });

  // 다중 조건(AND/OR) — 두 operand 의 진리표 4조합 × 결합자 2종 전수.
  // isLoggedIn(_global.currentUser.uuid) + dsHasData(posts) 로 컨텍스트를 합성한다.
  const T = { _global: { currentUser: { uuid: 'u' } }, posts: { data: [1] } }; // 둘 다 참
  const FT = { _global: { currentUser: {} }, posts: { data: [1] } }; // 거짓/참
  const TF = { _global: { currentUser: { uuid: 'u' } }, posts: { data: [] } }; // 참/거짓
  const F = { _global: { currentUser: {} }, posts: { data: [] } }; // 둘 다 거짓
  const clauses: ConditionClause[] = [
    { operator: 'isLoggedIn', params: {} },
    { operator: 'dsHasData', params: { src: 'posts' } },
  ];

  it('AND 결합 — 둘 다 참일 때만 (진리표 4조합)', () => {
    const expr = combineConditions(clauses, 'and', bundleOps);
    expect(evaluateStringCondition(expr, T, engine)).toBe(true);
    expect(evaluateStringCondition(expr, FT, engine)).toBe(false);
    expect(evaluateStringCondition(expr, TF, engine)).toBe(false);
    expect(evaluateStringCondition(expr, F, engine)).toBe(false);
  });

  it('OR 결합 — 하나라도 참이면 (진리표 4조합)', () => {
    const expr = combineConditions(clauses, 'or', bundleOps);
    expect(evaluateStringCondition(expr, T, engine)).toBe(true);
    expect(evaluateStringCondition(expr, FT, engine)).toBe(true);
    expect(evaluateStringCondition(expr, TF, engine)).toBe(true);
    expect(evaluateStringCondition(expr, F, engine)).toBe(false);
  });

  it('3절 결합도 단일 {{}} + 평가 정합', () => {
    const three: ConditionClause[] = [
      { operator: 'isLoggedIn', params: {} },
      { operator: 'dsHasData', params: { src: 'posts' } },
      { operator: 'isEditMode', params: {} },
    ];
    const expr = combineConditions(three, 'and', bundleOps);
    expect((expr.match(/\{\{/g) ?? []).length).toBe(1);
    expect(evaluateStringCondition(expr, { ...T, route: { id: '7' } }, engine)).toBe(true);
    expect(evaluateStringCondition(expr, { ...T, route: {} }, engine)).toBe(false);
  });
});
