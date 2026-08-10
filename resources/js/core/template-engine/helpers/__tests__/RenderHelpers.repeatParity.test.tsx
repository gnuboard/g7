/**
 * 반복 렌더 경로 기능 격차 회귀 테스트 (engine-v1.56.0)
 *
 * 일반 컴포넌트 경로(DynamicRenderer)에는 있고 반복 렌더 경로(renderItemChildren)에는
 * 없던 기능, 그리고 그 반대 방향의 비대칭을 고정한다.
 *
 * 순서 의존 회귀선: `$t:defer:` 는 DynamicRenderer 가 건너뛰고 이 경로가 항목 컨텍스트와
 * 함께 처리한다. 저장소 실사용 72건이 그 분담에 의존하므로, 새로 추가한 "문자열 중간 $t:"
 * 처리가 `$t:defer:` 보다 앞서면 안 된다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { renderItemChildren } from '../RenderHelpers';
import { ComponentRegistry } from '../../ComponentRegistry';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine } from '../../TranslationEngine';
import { RAW_MARKER_START, RAW_MARKER_END } from '../../rawMarkers';

const Probe: React.FC<{ value?: any; children?: React.ReactNode }> = ({ value, children }) => (
  <span data-testid="probe" data-json={JSON.stringify(value ?? null)}>
    {children}
  </span>
);

describe('renderItemChildren — 반복 렌더 경로 기능 격차', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Probe: { component: Probe, metadata: { name: 'Probe', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    (translationEngine as any).translations.set('test-template:ko', {
      shop: { unit: '개', published: '발행', draft: '비공개' },
      admin: { count_suffix: '건', vendor: '제공: {vendor}', paid: '{amount} 결제' },
    });
  });

  const renderItems = (children: any[], item: Record<string, any>) => {
    const nodes = renderItemChildren(children, item, { Probe }, 'test', {
      translationContext: { templateId: 'test-template', locale: 'ko' },
      bindingEngine,
      translationEngine,
      actionDispatcher: { createHandler: () => vi.fn() } as any,
    });
    return render(<>{nodes}</>);
  };

  it('문자열 중간의 $t: 토큰이 번역된다 (종전에는 원본 키가 그대로 노출)', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', text: '27$t:admin.count_suffix' }],
      { row: {} },
    );
    expect(screen.getByTestId('probe').textContent).toBe('27건');
    view.unmount();
  });

  it('$t:defer: 는 종전대로 이 경로에서 번역된다 (반복 서브트리 실사용 회귀선)', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', text: '$t:defer:shop.unit' }],
      { row: {} },
    );
    expect(screen.getByTestId('probe').textContent).toBe('개');
    view.unmount();
  });

  /**
   * 대표 케이스 회귀선 — 저장소 실사용 `$t:defer:` 32건(고유 24종)은 **전부**
   * `$t:defer:key|param={{row.x}}` 파라미터 형태다. `$t:defer:` 를 이 경로에 남겨 둔
   * 설계 근거 자체가 "파라미터의 `row` 는 DynamicRenderer 시점에 존재하지 않는다" 이므로,
   * 파라미터 없는 형태만 검사하면 순서 회귀를 놓친다 — 파라미터 없는 defer 는 뒤이은
   * `startsWith('$t:')` 분기로도 우연히 통과하기 때문이다.
   */
  it('$t:defer: 파라미터가 항목 컨텍스트로 해소된다 (실사용 형태 대표 케이스)', () => {
    const view = renderItems(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          text: '$t:defer:admin.vendor|vendor={{row.vendor}}',
        },
      ],
      { row: { vendor: '시르소프트' } },
    );
    expect(screen.getByTestId('probe').textContent).toBe('제공: 시르소프트');
    view.unmount();
  });

  it('$t:defer: 파라미터가 문자열 중간에 있어도 항목 값으로 해소된다', () => {
    const view = renderItems(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          text: '합계 $t:defer:admin.paid|amount={{row.total}}',
        },
      ],
      { row: { total: '12,000원' } },
    );
    // 중간 `$t:` 처리가 `$t:defer:` 분기보다 앞서면 파라미터가 해소되지 않은 채 번역돼
    // 값이 사라진다 — 이 단언이 그 순서를 고정한다.
    expect(screen.getByTestId('probe').textContent).toBe('합계 12,000원 결제');
    view.unmount();
  });

  it('$t:defer: 파라미터는 항목마다 다른 값으로 해소된다', () => {
    const nodes = renderItemChildren(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          text: '$t:defer:admin.vendor|vendor={{row.vendor}}',
        },
      ],
      { row: { vendor: 'A사' } },
      { Probe },
      'first',
      {
        translationContext: { templateId: 'test-template', locale: 'ko' },
        bindingEngine,
        translationEngine,
        actionDispatcher: { createHandler: () => vi.fn() } as any,
      },
    );
    const second = renderItemChildren(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          text: '$t:defer:admin.vendor|vendor={{row.vendor}}',
        },
      ],
      { row: { vendor: 'B사' } },
      { Probe },
      'second',
      {
        translationContext: { templateId: 'test-template', locale: 'ko' },
        bindingEngine,
        translationEngine,
        actionDispatcher: { createHandler: () => vi.fn() } as any,
      },
    );

    const first = render(<>{nodes}</>);
    expect(screen.getByTestId('probe').textContent).toBe('제공: A사');
    first.unmount();

    const secondView = render(<>{second}</>);
    expect(screen.getByTestId('probe').textContent).toBe('제공: B사');
    secondView.unmount();
  });

  it('JSON 구조 문자열 안의 $t: 는 번역하지 않는다 (데이터의 일부)', () => {
    const view = renderItems(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          props: { value: '{"text":"$t:shop.unit"}' },
        },
      ],
      { row: {} },
    );
    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toBe(
      '{"text":"$t:shop.unit"}',
    );
    view.unmount();
  });

  it('표현식 결과가 배열이면 리프의 $t: 까지 번역된다', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: '{{row.badges}}' } }],
      { row: { badges: ['$t:shop.published', '$t:shop.draft'] } },
    );
    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toEqual([
      '발행',
      '비공개',
    ]);
    view.unmount();
  });

  it('$switch 객체가 값으로 해석된다 (종전에는 객체 그대로 전달)', () => {
    const view = renderItems(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          props: {
            value: {
              $switch: '{{row.status}}',
              $cases: { active: 'text-green-500', inactive: 'text-gray-500' },
              $default: 'text-gray-400',
            },
          },
        },
      ],
      { row: { status: 'active' } },
    );
    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toBe(
      'text-green-500',
    );
    view.unmount();
  });

  it('액션 정의 객체는 선평가하지 않는다 (실행 시점 최신 상태 보장)', () => {
    const view = renderItems(
      [
        {
          id: 'p',
          type: 'basic',
          name: 'Probe',
          props: {
            value: {
              handler: 'apiCall',
              target: '/api/x',
              params: { id: '{{_local.selectedId}}' },
            },
          },
        },
      ],
      { row: {}, _local: { selectedId: 1 } },
    );
    const passed = JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!);
    expect(passed.params.id).toBe('{{_local.selectedId}}');
    view.unmount();
  });

  // ── raw 마커 최종 제거 (engine-v1.56.3) ─────────────────────────────
  //
  // 마커(﷐ ﷑)는 번역 패스를 건너뛰게 하려는 내부 표식이다. 단발 렌더 경로는
  // resolveTranslationsDeep 안에서 벗기지만 이 경로에는 그 패스가 없어, 마커가 붙은 채
  // React 로 넘어가 Unicode Noncharacter 두 글자가 그대로 DOM 에 실렸다.

  it('raw: 바인딩의 text 에 raw 마커가 남지 않는다', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', text: '{{raw:row.label}}' }],
      { row: { label: '$t:shop.unit' } },
    );
    const text = screen.getByTestId('probe').textContent!;
    // raw: 이므로 번역은 되지 않고
    expect(text).toBe('$t:shop.unit');
    // 마커는 화면으로 새지 않는다
    expect(text).not.toContain(RAW_MARKER_START);
    expect(text).not.toContain(RAW_MARKER_END);
    view.unmount();
  });

  it('raw: 바인딩의 props 값에도 raw 마커가 남지 않는다', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: '{{raw:row.label}}' } }],
      { row: { label: '$t:shop.unit' } },
    );
    const passed = JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!);
    expect(passed).toBe('$t:shop.unit');
    expect(JSON.stringify(passed)).not.toContain(RAW_MARKER_START);
    view.unmount();
  });

  it('raw: 결과가 배열/객체여도 리프까지 마커가 남지 않는다', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: '{{raw:row.meta}}' } }],
      { row: { meta: { a: '$t:shop.unit', b: ['$t:shop.draft'] } } },
    );
    const passed = JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!);
    expect(passed).toEqual({ a: '$t:shop.unit', b: ['$t:shop.draft'] });
    expect(JSON.stringify(passed)).not.toContain(RAW_MARKER_START);
    expect(JSON.stringify(passed)).not.toContain(RAW_MARKER_END);
    view.unmount();
  });

  it('중첩 자식 안의 raw: 도 마커 없이 렌더된다', () => {
    const view = renderItems(
      [
        {
          id: 'outer',
          type: 'basic',
          name: 'Probe',
          children: [{ id: 'inner', type: 'basic', name: 'Probe', text: '{{raw:row.label}}' }],
        },
      ],
      { row: { label: '$t:shop.unit' } },
    );
    const all = screen.getAllByTestId('probe');
    const text = all[all.length - 1].textContent!;
    expect(text).toBe('$t:shop.unit');
    expect(text).not.toContain(RAW_MARKER_START);
    view.unmount();
  });

  it('일반 바인딩은 종전대로 즉시 해석된다 (회귀 보호)', () => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: '{{row.id}}' } }],
      { row: { id: 42 } },
    );
    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toBe(42);
    view.unmount();
  });

  /**
   * 리터럴 단일 바인딩 (engine-v1.56.4)
   *
   * 판정 통일(engine-v1.55.0)에서 리터럴을 `BindingShape` 한 곳으로 모았지만, 이 경로만
   * `hasPipes → isComplexExpression → 경로 탐색` 3분기를 직접 갈라 리터럴을 몰랐다.
   * 그래서 `{{true}}` 가 조건 자리에서는 `true`, 반복 렌더 prop 자리에서는 `undefined` 로
   * 갈렸다 — 통일이 없애려던 바로 그 비대칭이 이 경로에만 남아 있었다.
   */
  it.each([
    ['{{true}}', true],
    ['{{false}}', false],
    ['{{null}}', null],
  ])('리터럴 %s 이 prop 자리에서 경로 탐색으로 새지 않는다', (expr, expected) => {
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: expr } }],
      { row: { id: 1 } },
    );

    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toBe(expected);
    view.unmount();
  });

  it('리터럴과 같은 이름의 컨텍스트 경로보다 리터럴이 우선한다', () => {
    // `true` 라는 이름의 컨텍스트 키가 있어도 `{{true}}` 는 리터럴이다 —
    // 조건 평가 경로와 같은 규칙.
    const view = renderItems(
      [{ id: 'p', type: 'basic', name: 'Probe', props: { value: '{{true}}' } }],
      { true: '경로값', row: { id: 1 } },
    );

    expect(JSON.parse(screen.getByTestId('probe').getAttribute('data-json')!)).toBe(true);
    view.unmount();
  });
});
