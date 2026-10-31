/**
 * computed 스코프 물려받기 회귀 테스트 — 공개 이슈 140
 *
 * 수정 전에는 `_computedDefinitions` 가 렌더되는 **모든** DynamicRenderer 인스턴스에서, 재렌더마다
 * 전량 재평가됐다(부모 1 + 자식 20 이면 computed 1개가 21회). 자식의 computed 입력은 부모와 같으므로
 * (자식 dataContext = 부모 extendedDataContext, 자식 dynamicState = 부모 `_local` 사본) 부모 값을 그대로
 * 쓴다. 입력이 달라지는 경계(루트급·iteration 항목·격리 상태·`$parent`·extensionPointProps·
 * 미적용 `_localInit`·편집 모드·뒤쪽 키 참조 정의)에서만 계산한다.
 *
 * 횟수 세는 법: `DataBindingEngine.prototype.evaluateExpression` spy(원 구현 유지)로 computed 식의
 * 고유 표식 리터럴이 든 호출만 센다. 소비 prop 은 `{{_computed.X}}` 경로 해석이라 집계에 섞이지 않는다.
 *
 * @since engine-v1.74.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, act, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { __resetExpressionCompileCacheForTests } from '../ExpressionCompileCache';
import { __resetEngineFlagsForTests, setEngineFlags } from '../EngineFlags';

const Box: React.FC<{ tid?: string; children?: React.ReactNode }> = ({ tid, children }) => (
  <div data-testid={tid}>{children}</div>
);
const Probe: React.FC<{ tid?: string; value?: unknown }> = ({ tid, value }) => (
  <span data-testid={tid}>{String(value)}</span>
);

const M1 = '_local.n + 9000001';
const M2 = "(_local.n ?? 0) * 2 + 9000002";

const box = (id: string, children: ComponentDefinition[], extra: Partial<ComponentDefinition> = {}): ComponentDefinition => ({
  id, type: 'basic', name: 'Box', props: { tid: id }, children, ...extra,
});
const probe = (id: string, expr: string): ComponentDefinition => ({
  id, type: 'basic', name: 'Probe', props: { tid: id, value: expr },
});

describe('[case:cache-23] DynamicRenderer — computed 스코프 물려받기', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;
  let spy: ReturnType<typeof vi.spyOn>;

  const count = (marker: string) => spy.mock.calls.filter((c: unknown[]) => c[0] === marker).length;

  const el = (def: ComponentDefinition, dataContext: any, extra: Record<string, any> = {}) => (
    <DynamicRenderer
      componentDef={def}
      dataContext={dataContext}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
      {...extra}
    />
  );

  beforeEach(() => {
    delete (window as any).__g7ForcedLocalFields;
    __resetExpressionCompileCacheForTests();
    __resetEngineFlagsForTests();
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Box: { component: Box, metadata: { name: 'Box', type: 'basic' } },
      Probe: { component: Probe, metadata: { name: 'Probe', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
    spy = vi.spyOn(DataBindingEngine.prototype, 'evaluateExpression');
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    __resetEngineFlagsForTests();
    delete (window as any).__g7IsolatedStates;
  });

  const defs2 = { c0: `{{${M1}}}`, c1: `{{${M2}}}` };
  const tree20 = () => box('root', Array.from({ length: 20 }, (_, i) => probe(`p${i}`, '{{_computed.c0}}')));

  /**

   * @scenario layer=render_scope

   * @effects computed_evaluated_once_per_scope

   */
  it('① 부모 1 + 자식 20: _local 변경 1회에 computed 가 스코프당 1회만 평가된다', () => {
    const ctx = { _local: { n: 1 }, _computedDefinitions: defs2, _computed: {} };
    const { rerender } = render(el(tree20(), ctx));
    expect(screen.getByTestId('p19')).toHaveTextContent('9000002');

    spy.mockClear();
    rerender(el(tree20(), { ...ctx, _local: { n: 2 } }));

    expect(count(M1)).toBe(1); // 수정 전: 21
    expect(count(M2)).toBe(1);
    for (let i = 0; i < 20; i++) expect(screen.getByTestId(`p${i}`)).toHaveTextContent('9000003');
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('② iteration 5항목 × 자식 3: 루트 1 + 항목 루트 5 에서만 계산, 항목 변수를 읽는 computed 는 항목마다 다른 값', () => {
    const MI = "(row.label ?? 'none') + ':' + _local.n + '#9000004'";
    const def = box('root', [
      {
        id: 'item', type: 'basic', name: 'Box', props: { tid: 'item' },
        iteration: { source: '_local.items', item_var: 'row' },
        children: [probe('ia', '{{_computed.ci}}'), probe('ib', '{{_computed.ci}}'), probe('ic', '{{row.label}}')],
      } as any,
    ]);
    const items = ['r1', 'r2', 'r3', 'r4', 'r5'].map((label) => ({ label }));
    const ctx = { _local: { n: 1, items }, _computedDefinitions: { ci: `{{${MI}}}` }, _computed: {} };
    const { rerender } = render(el(def, ctx));

    spy.mockClear();
    rerender(el(def, { ...ctx, _local: { n: 2, items } }));

    expect(count(MI)).toBe(6); // 루트 1 + 항목 루트 5 (수정 전: 루트·호스트·항목 5·항목 자식 15 = 22)
    const ia = screen.getAllByTestId('ia').map((e) => e.textContent);
    expect(ia).toEqual(['r1:2#9000004', 'r2:2#9000004', 'r3:2#9000004', 'r4:2#9000004', 'r5:2#9000004']);
    expect(screen.getAllByTestId('ib').map((e) => e.textContent)).toEqual(ia);
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('③ 격리 상태 변경은 그 영역의 경계에서만 재평가된다', () => {
    const MX = '(_isolated.x ?? 0) + 9000005';
    const def = box('root', [
      probe('outside', '{{_computed.cx}}'),
      box('iso', [box('mid', [probe('in1', '{{_computed.cx}}'), probe('in2', '{{_computed.cx}}')])], {
        isolatedState: { x: 1 }, isolatedScopeId: 'u4',
      } as any),
    ]);
    render(el(def, { _local: {}, _computedDefinitions: { cx: `{{${MX}}}` }, _computed: {} }));
    expect(screen.getByTestId('outside')).toHaveTextContent('9000005');
    expect(screen.getByTestId('in1')).toHaveTextContent('9000006');

    spy.mockClear();
    act(() => {
      (window as any).__g7IsolatedStates.u4.mergeState({ x: 2 });
    });

    expect(screen.getByTestId('in1')).toHaveTextContent('9000007');
    expect(screen.getByTestId('in2')).toHaveTextContent('9000007');
    expect(screen.getByTestId('outside')).toHaveTextContent('9000005');
    expect(count(MX)).toBe(1); // 경계(mid) 1회 — 수정 전: mid·in1·in2 = 3
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('④ 서로 다른 루트급 렌더러는 각자 계산해 서로 다른 _computed 를 유지한다(사례 13)', () => {
    const defA = box('ra', [probe('pa', '{{_computed.c0}}')]);
    const defB = box('rb', [probe('pb', '{{_computed.c0}}')]);
    const ctxA = { _local: { n: 10 }, _computedDefinitions: defs2, _computed: {} };
    const ctxB = { _local: { n: 20 }, _computedDefinitions: defs2, _computed: {} };
    const tree = (a: any, b: any) => (<>{el(defA, a)}{el(defB, b)}</>);
    const { rerender } = render(tree(ctxA, ctxB));
    expect(screen.getByTestId('pa')).toHaveTextContent('9000011');
    expect(screen.getByTestId('pb')).toHaveTextContent('9000021');

    rerender(tree({ ...ctxA, _local: { n: 11 } }, ctxB));
    expect(screen.getByTestId('pa')).toHaveTextContent('9000012');
    expect(screen.getByTestId('pb')).toHaveTextContent('9000021');
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('⑤ 편집 모드(isEditMode)는 항상 모든 렌더러가 계산한다', () => {
    const ctx = { _local: { n: 1 }, _computedDefinitions: defs2, _computed: {} };
    const { rerender } = render(el(tree20(), ctx, { isEditMode: true }));
    spy.mockClear();
    rerender(el(tree20(), { ...ctx, _local: { n: 2 } }, { isEditMode: true }));
    expect(count(M1)).toBe(21);
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_inheritance_disabled_by_back_reference

   */
  it('⑥ 뒤쪽 키를 참조하는 정의면 물려받기가 꺼지고 자식이 최신 값을 표시한다(D12)', () => {
    const MT = '($computed.sub ?? 0) + 3000';
    const MS = '_local.qty * 1000';
    const d = { total: `{{${MT}}}`, sub: `{{${MS}}}` };
    const def = box('root', [box('n1', [box('n2', [probe('deep', '{{_computed.total}}')])])]);
    const ctx = { _local: { qty: 1 }, _computedDefinitions: d, _computed: { total: 4000, sub: 1000 } };
    const { rerender } = render(el(def, ctx));
    spy.mockClear();
    rerender(el(def, { ...ctx, _local: { qty: 2 } }));
    expect(count(MT)).toBe(4); // 인스턴스 4 모두 계산
    expect(screen.getByTestId('deep')).toHaveTextContent('5000'); // 진입값 4000 에 고정되지 않는다
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('⑦ parentDataContext 를 받은 루트: 직계 자식이 $parent 포함 컨텍스트로 계산하고 손자부터 물려받는다', () => {
    const MP = "($parent._local.x ?? 'noparent') + '#9000008'";
    const def = box('mroot', [
      box('c1', [probe('g1', '{{_computed.cp}}'), probe('g2', '{{_computed.cp}}')]),
      box('c2', [probe('g3', '{{_computed.cp}}')]),
    ]);
    const ctx = { _local: {}, _computedDefinitions: { cp: `{{${MP}}}` }, _computed: {} };
    const parent = { _local: { x: 'PX' } };
    const { rerender } = render(el(def, ctx, { parentDataContext: parent }));
    expect(screen.getByTestId('g1')).toHaveTextContent('PX#9000008');
    expect(screen.getByTestId('g3')).toHaveTextContent('PX#9000008');

    spy.mockClear();
    rerender(el(def, { ...ctx, _local: { y: 1 } }, { parentDataContext: parent }));
    expect(count(MP)).toBe(3); // 루트 1 + 직계 자식 2 (수정 전: 6)
    expect(screen.getByTestId('g2')).toHaveTextContent('PX#9000008');
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('⑧ extensionPointProps 를 가진 컴포넌트의 자식이 extensionPointProps 를 읽는 computed 를 올바로 표시한다', () => {
    const ME = "(extensionPointProps.x ?? 'none') + '#9000009'";
    const def = box('root', [box('ep', [probe('epc', '{{_computed.ce}}')], { extensionPointProps: { x: 'EP' } } as any)]);
    render(el(def, { _local: {}, _computedDefinitions: { ce: `{{${ME}}}` }, _computed: {} }));
    expect(screen.getByTestId('epc')).toHaveTextContent('EP#9000009');
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_boundaries_recompute

   */
  it('⑨ _localInit 이 있으면 인스턴스 첫 렌더는 전원 계산, 반영 후 렌더부터 스코프당 1회', () => {
    const init = { n: 1 };
    const ctx = { _local: { n: 1 }, _localInit: init, _computedDefinitions: defs2, _computed: {} };
    const { rerender } = render(el(tree20(), ctx));
    spy.mockClear();
    rerender(el(tree20(), { ...ctx, _local: { n: 3 } }));
    expect(count(M1)).toBe(1);
    expect(screen.getByTestId('p0')).toHaveTextContent('9000004');
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_shadow_verification_reports_mismatch

   */
  it('⑩ 그림자 검증: 비결정 식이 어긋나면 [computed-scope-mismatch] 를 찍고 계산값을 쓴다', () => {
    setEngineFlags({ verifyComputedInheritance: true });
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    const def = box('root', [probe('r1', '{{_computed.cr}}'), box('mid', [probe('r2', '{{_computed.cr}}')])]);
    render(el(def, { _local: {}, _computedDefinitions: { cr: '{{Math.random()}}' }, _computed: {} }));
    const mismatch = err.mock.calls.filter((c) => c[0] === '[computed-scope-mismatch]');
    expect(mismatch.length).toBeGreaterThan(0);
    expect(mismatch[0][1]).toMatchObject({ keys: ['cr'] });
    expect(screen.getByTestId('r1').textContent).not.toBe(screen.getByTestId('r2').textContent);
  });

  /**

   * @scenario layer=render_scope

   * @effects computed_shadow_verification_reports_mismatch

   */
  it('그림자 검증: 결정적 식이면 불일치 0 이고 값이 같다', () => {
    setEngineFlags({ verifyComputedInheritance: true });
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    const ctx = { _local: { n: 1, list: [{ a: 1 }] }, _computedDefinitions: { ...defs2, obj: '{{ {k: _local.list, f: (x) => x} }}' }, _computed: {} };
    const { rerender } = render(el(tree20(), ctx));
    rerender(el(tree20(), { ...ctx, _local: { n: 5, list: [{ a: 2 }] } }));
    expect(err.mock.calls.filter((c) => c[0] === '[computed-scope-mismatch]')).toEqual([]);
  });

  /**

   * @scenario layer=render_scope

   * @effects engine_flags_off_restores_prior_path

   */
  it('U11: 스위치 3종을 끄면 수정 전과 같이 인스턴스마다 계산하고 표시값은 같다', () => {
    setEngineFlags({ expressionCompileCache: false, astCache: false, computedScopeInheritance: false });
    const ctx = { _local: { n: 1 }, _computedDefinitions: defs2, _computed: {} };
    const { rerender } = render(el(tree20(), ctx));
    spy.mockClear();
    rerender(el(tree20(), { ...ctx, _local: { n: 2 } }));
    expect(count(M1)).toBe(21);
    expect(count(M2)).toBe(21);
    expect(screen.getByTestId('p7')).toHaveTextContent('9000003');
  });

  /**

   * @scenario layer=render_scope

   * @effects engine_flags_off_restores_prior_path

   */
  it('U11: 스위치를 켜고 끄는 사이 캐시 무효화 없이 값이 정상이다', () => {
    const ctx = { _local: { n: 1 }, _computedDefinitions: defs2, _computed: {} };
    const { rerender } = render(el(tree20(), ctx));
    setEngineFlags({ computedScopeInheritance: false, astCache: false });
    rerender(el(tree20(), { ...ctx, _local: { n: 4 } }));
    expect(screen.getByTestId('p3')).toHaveTextContent('9000005');
    setEngineFlags({ computedScopeInheritance: true, astCache: true });
    rerender(el(tree20(), { ...ctx, _local: { n: 6 } }));
    expect(screen.getByTestId('p3')).toHaveTextContent('9000007');
  });
});
