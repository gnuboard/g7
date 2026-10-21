// e2e:allow 엔진 렌더 경로 단위 잠금 — 화면 검증은 7.1.1 출시 전 실측(장바구니 선택 체크박스 이름)이 한다.
/**
 * 레이아웃 문자열 번역 `$t:키|이름={{값}}` 의 값 자리 보호 회귀 테스트 (7.1.1 출시 전 실측)
 *
 * 렌더러는 prop·text 문자열의 `{{…}}` 를 먼저 값으로 바꾼 뒤(1단계) `$t:` 를 번역했다(2단계). 그래서
 * 번역 엔진이 인자를 나눌 때는 이미 값이 글자로 들어가 있어, 값에 든 `&`·`|`·`=` 를 인자 구분자로 읽었다 —
 * 상품명 「Tom & Jerry 머그컵」의 장바구니 선택 체크박스 이름이 「Tom 선택」이 됐다. 컴포넌트 경로
 * (`G7Core.t(키, 인자)`)는 engine-v1.80.1 에서 고쳤지만, 레이아웃 문자열 경로가 남아 있었다.
 *
 * 1단계가 `$t:` 인자 자리에 넣은 값을 보호 표식으로 감싸고, 번역 엔진이 인자를 나눈 뒤 표식 안 글자를
 * 그대로 치환한다. 표식은 화면에 남지 않는다.
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, type TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { renderItemChildren } from '../helpers/RenderHelpers';

const T = 'sirsoft-basic';
const ko: TranslationContext = { templateId: T, locale: 'ko' };

/** 보호 표식(U+FDD3·U+FDD4)이 결과에 남았는지 */
const hasMarker = (s: unknown): boolean => typeof s === 'string' && /[﷓﷔]/.test(s);

const TestCheckbox: React.FC<{ 'aria-label'?: string; title?: string; children?: React.ReactNode }> = ({
  'aria-label': ariaLabel,
  title,
  children,
}) => (
  <label data-testid="box" title={title}>
    <input type="checkbox" aria-label={ariaLabel} />
    {children}
  </label>
);

const TestSpan: React.FC<any> = ({ children, ...props }) => React.createElement('span', props, children);

const TRICKY = 'Tom & Jerry | a=b 머그컵 /';

function seed(engine: TranslationEngine): void {
  (engine as any).translations.set(`${T}:ko`, {
    common: { select_item: '{{name}} 선택', row_cell: '{{row}} {{column}}', count_unit: '{{count}}건' },
    enums: { status: { active: '사용 & 판매' } },
    shop: { badge: '{{status}} 상태' },
  });
}

describe('레이아웃 문자열 번역의 값 자리 보호 — DynamicRenderer', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    seed(translationEngine);
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: Record<string, any>) =>
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={ko}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />,
    );

  // @scenario render_path=prop, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('prop 값의 `$t:키|이름={{값}}` — 값의 `&`·`|`·`=`·끝 ` /` 가 잘리지 않는다(장바구니 선택 체크박스)', () => {
    renderDef(
      {
        id: 'cb',
        type: 'basic',
        name: 'Checkbox',
        props: { 'aria-label': "$t:common.select_item|name={{item.product.name ?? ''}}" },
      },
      { item: { product: { name: TRICKY } } },
    );

    const input = screen.getByRole('checkbox');
    // 종전: 「Tom 선택」
    expect(input.getAttribute('aria-label')).toBe(`${TRICKY} 선택`);
    expect(hasMarker(input.getAttribute('aria-label'))).toBe(false);
  });

  // @scenario render_path=text, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('text 의 `$t:키|이름={{값}}` 도 값이 잘리지 않는다', () => {
    renderDef(
      { id: 'cb2', type: 'basic', name: 'Checkbox', props: {}, text: '$t:common.select_item|name={{name}}' },
      { name: 'A&B=C|D' },
    );

    expect(screen.getByTestId('box').textContent).toBe('A&B=C|D 선택');
  });

  // @scenario render_path=prop, value_chars=plain
  // @effects multi_param_and_outside_bindings_unchanged
  it('인자 여럿·`$t:` 밖 바인딩·문장 꼬리는 종전과 같다', () => {
    renderDef(
      {
        id: 'cb3',
        type: 'basic',
        name: 'Checkbox',
        props: {
          'aria-label': '{{prefix}} · $t:common.row_cell|row={{row}}|column={{col}} / ',
          title: '$t:common.count_unit|count={{n}}',
        },
      },
      { prefix: 'P&Q', row: '3번째 행', col: '판매가 & 할인가', n: 5 },
    );

    const input = screen.getByRole('checkbox');
    // `$t:` 밖의 `{{prefix}}` 값은 번역과 무관하게 글자 그대로, 꼬리 ` / ` 는 문장으로 남는다
    expect(input.getAttribute('aria-label')).toBe('P&Q · 3번째 행 판매가 & 할인가 / ');
    expect(screen.getByTestId('box').getAttribute('title')).toBe('5건');
  });

  // @scenario render_path=prop, value_chars=translation_token
  // @effects bound_translation_token_value_translated
  it('값이 `$t:키` 하나면 종전처럼 그 키를 번역해 넣는다(중첩 번역)', () => {
    renderDef(
      { id: 'cb4', type: 'basic', name: 'Checkbox', props: { 'aria-label': '$t:shop.badge|status={{row.status_label}}' } },
      { row: { status_label: '$t:enums.status.active' } },
    );

    expect(screen.getByRole('checkbox').getAttribute('aria-label')).toBe('사용 & 판매 상태');
  });
});

describe('레이아웃 문자열 번역의 값 자리 보호 — 반복 렌더(renderItemChildren)', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    seed(translationEngine);
  });

  // @scenario render_path=iteration_interpolation, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('문자열 중간의 `$t:키|이름={{row.값}}` 도 값이 잘리지 않는다', () => {
    const nodes = renderItemChildren(
      [{ type: 'basic', name: 'Span', props: { 'aria-label': '({{row.n}}) $t:common.select_item|name={{row.name}}' } } as any],
      { row: { n: 1, name: TRICKY } },
      { Span: TestSpan },
      'k',
      { bindingEngine, translationEngine, translationContext: ko },
    ) as any[];

    expect(nodes[0].props['aria-label']).toBe(`(1) ${TRICKY} 선택`);
    expect(hasMarker(nodes[0].props['aria-label'])).toBe(false);
  });

  // @scenario render_path=iteration_leading, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('`$t:` 로 시작하는 값은 종전부터 인자를 먼저 나눈다 — 그대로 통과', () => {
    const nodes = renderItemChildren(
      [{ type: 'basic', name: 'Span', props: { 'aria-label': '$t:common.select_item|name={{row.name}}' } } as any],
      { row: { name: TRICKY } },
      { Span: TestSpan },
      'k',
      { bindingEngine, translationEngine, translationContext: ko },
    ) as any[];

    expect(nodes[0].props['aria-label']).toBe(`${TRICKY} 선택`);
  });
});

describe('레이아웃 문자열 번역의 값 자리 보호 — 액션(확인 창)', () => {
  let confirmSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
  });

  afterEach(() => {
    confirmSpy.mockRestore();
  });

  // @scenario render_path=action_confirm, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('confirm 의 `$t:키|이름={{값}}` 도 값이 잘리지 않는다', async () => {
    const translationEngine = new TranslationEngine();
    seed(translationEngine);
    const dispatcher = new ActionDispatcher({}, translationEngine, ko);

    await dispatcher.dispatchAction(
      { handler: 'setState', confirm: '$t:common.select_item|name={{target.name}}', params: { target: 'global', x: 1 } } as any,
      { data: { target: { name: TRICKY } } } as any,
    );

    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(confirmSpy.mock.calls[0][0]).toBe(`${TRICKY} 선택`);
  });
});

describe('레이아웃 문자열 번역의 값 자리 보호 — 액션 인자(toast·setState)', () => {
  afterEach(() => {
    (window as any).__g7PendingLocalState = undefined;
  });

  // @scenario render_path=action_toast, value_chars=separators
  // @effects separator_chars_in_bound_value_kept
  it('toast message 의 `$t:키|이름={{값}}` 는 보호하고, setState 인자에는 표식을 넣지 않는다', async () => {
    const translationEngine = new TranslationEngine();
    seed(translationEngine);
    const dispatcher = new ActionDispatcher({}, translationEngine, ko);
    const updates: any[] = [];
    dispatcher.setGlobalStateUpdater((u: any) => {
      updates.push(u);
    });

    await dispatcher.dispatchAction(
      { handler: 'toast', params: { type: 'success', message: '$t:common.select_item|name={{target.name}}' } } as any,
      { data: { target: { name: TRICKY } } } as any,
    );
    const toastUpdate = updates.find((u) => Array.isArray(u?.toasts));
    expect(toastUpdate.toasts[toastUpdate.toasts.length - 1].message).toBe(`${TRICKY} 선택`);

    // 상태·요청 본문으로 나가는 인자는 종전 그대로(표식 없음) — 나중에 그릴 때 번역된다
    await dispatcher.dispatchAction(
      { handler: 'setState', params: { target: 'global', saved: '$t:common.select_item|name={{target.name}}' } } as any,
      { data: { target: { name: 'X&Y' } } } as any,
    );
    const saved = updates.find((u) => 'saved' in (u ?? {}))?.saved;
    expect(saved).toBe('$t:common.select_item|name=X&Y');
    expect(hasMarker(saved)).toBe(false);
  });
});

describe('빈 값은 종전처럼 인자가 빠진다(자리표시자 유지)', () => {
  // @scenario render_path=engine_direct, value_chars=empty
  // @effects empty_bound_value_unchanged
  it('바인딩 값이 비면 감싸지 않는다 — 번역 결과가 종전과 같다', () => {
    const translationEngine = new TranslationEngine();
    seed(translationEngine);
    const binding = new DataBindingEngine();
    const tpl = "$t:common.select_item|name={{v ?? ''}}";

    const before = translationEngine.resolveTranslations(new DataBindingEngine().resolveBindings(tpl, { v: null }), ko, {});
    const after = translationEngine.resolveTranslations(
      binding.resolveBindings(tpl, { v: null }, { protectTranslationParams: true } as any),
      ko,
      {},
    );
    expect(after).toBe(before);
    expect(after).toBe('{{name}} 선택');
  });
});

describe('보호 표식은 번역 엔진 밖으로 새지 않는다', () => {
  // @scenario render_path=element_props, value_chars=marker
  // @effects protection_marker_never_leaks
  it('엘리먼트 직전 안전망(stripRawDeep)이 보호 표식도 걷는다', async () => {
    const { stripRawDeep } = await import('../rawMarkers');
    expect(stripRawDeep('a﷓b&c﷔d')).toBe('ab&cd');
    expect(stripRawDeep({ label: '﷐x﷓y﷔﷑' })).toEqual({ label: 'xy' });
  });

  // @scenario render_path=engine_direct, value_chars=separators
  // @effects protection_marker_never_leaks
  it('번역 키가 없거나 `$t:` 토큰이 인자 자리 밖에서 끝나도 표식이 남지 않는다', () => {
    const translationEngine = new TranslationEngine();
    seed(translationEngine);
    const binding = new DataBindingEngine();

    const resolved = binding.resolveBindings('$t:missing.key|name={{v}}', { v: TRICKY }, { protectTranslationParams: true } as any);
    const out = translationEngine.resolveTranslations(resolved, ko, {});
    expect(hasMarker(out)).toBe(false);
    // 없는 키는 키를 그대로 낸다(종전과 같음)
    expect(out).toBe('missing.key');

    // 보호를 요청하지 않은 호출(일반 보간)은 종전 그대로 — 표식을 넣지 않는다
    expect(new DataBindingEngine().resolveBindings('$t:common.select_item|name={{v}}', { v: 'X&Y' })).toBe(
      '$t:common.select_item|name=X&Y',
    );
  });
});
