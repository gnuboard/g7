// e2e:allow [화면 동작] 상속 매트릭스 단위(RTL) — I1~I15 전수, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * init-actions-inheritance.test.tsx — [화면 동작] 부모/자식 상속 매트릭스 RTL
 *
 *  매트릭스 I1~I15 의 `시작상태 × 조작 × 검증` cross product 를 전수 검증한다.
 * 정책(init_actions 고유): 부모(base) 항목 = 🔒 읽기전용·concat(array_merge). 자식(route)
 * 항목만 편집/추가/삭제/순서.
 *
 *  ② 공통화 후: 카드/드래그/추가/편집은 공용 ActionListBuilder 에 위임. base 그룹은 별도
 * 빌더(prefix `g7le-init-action-base`, isLocked 전부 잠금), self 그룹은 별도 빌더(prefix
 * `g7le-init-action-self`). 비상속 모드는 prefix `g7le-init-action`. 인덱스는 **그룹 내 상대**.
 *
 * @since engine-v1.50.0 · 공통화 engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitActionsForm } from '../../../components/page-settings/InitActionsForm';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';
import type { ActionRecipeSpec } from '../../../spec/specTypes';

const t = (k: string) => k;

const RECIPES: Record<string, ActionRecipeSpec> = {
  setState: { label: '$t:화면 상태', build: { handler: 'setState' }, __source: { kind: 'core' } } as ActionRecipeSpec,
  toast: {
    label: '$t:안내 메시지',
    params: [{ key: 'message', label: '$t:메시지', widget: 'text', required: true }],
    build: { handler: 'toast', params: { message: '{{message}}' } },
    __source: { kind: 'core' },
  } as ActionRecipeSpec,
  filterLoad: {
    label: '$t:필터 불러오기',
    build: { handler: 'filterLoad' },
    __source: { kind: 'module', id: 'sirsoft-ecommerce' },
  } as ActionRecipeSpec,
};

/** 부모(base) 항목 — `__source.kind='base'` */
const baseAction = (action: Record<string, unknown>, layout = '_user_base') => ({
  ...action,
  __source: { kind: 'base', layout },
});
/** 자식(route) 항목 — `__source.kind='route'` */
const routeAction = (action: Record<string, unknown>, layout = 'auth/login') => ({
  ...action,
  __source: { kind: 'route', layout },
});

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  registerWidget('text', ({ value, onChange }) => (
    <input data-testid="w-text" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
});

describe('init-actions-inheritance — 부모/자식 상속 매트릭스 (I1~I15)', () => {
  it('I1: 부모만 init_actions(자식 0) → 부모 그룹만(전부 🔒), 자식 그룹 비어 추가 picker 만', () => {
    const actions = [baseAction({ handler: 'setState' }), baseAction({ handler: 'toast', params: { message: 'hi' } })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-group-base')).toBeInTheDocument();
    // base 그룹 항목은 잠금 — 편집/삭제/드래그 핸들 부재.
    expect(screen.getByTestId('g7le-init-action-base-item-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    // 자식 그룹 존재하나 항목 0 — 추가 picker 만.
    expect(screen.getByTestId('g7le-init-action-group-self')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-self-item-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-add-picker-toggle')).toBeInTheDocument();
  });

  it('I2: 자식만 init_actions(부모 0) → 비상속 모드(그룹 박스 없음, 전부 편집)', () => {
    const actions = [routeAction({ handler: 'setState' }), routeAction({ handler: 'toast', params: { message: 'hi' } })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.queryByTestId('g7le-init-action-group-base')).not.toBeInTheDocument();
    // 비상속 prefix — 전부 편집 가능.
    expect(screen.getByTestId('g7le-init-action-edit-1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-remove-0')).toBeInTheDocument();
  });

  it('I3: 부모+자식 둘 다 → 두 그룹 분리, 부모=🔒/자식=편집, 실행순서 안내', () => {
    const actions = [baseAction({ handler: 'setState' }), routeAction({ handler: 'toast', params: { message: 'hi' } })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-group-base')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-group-self')).toBeInTheDocument();
    // base(상대 idx 0) 잠금, self(상대 idx 0 = route toast) 편집 가능.
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-remove-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-merge-order')).toBeInTheDocument();
  });

  it('I4: 부모 항목 [편집]/[✕]/드래그 핸들 DOM 부재', () => {
    const actions = [baseAction({ handler: 'toast', params: { message: 'hi' } }), routeAction({ handler: 'setState' })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-drag-0')).not.toBeInTheDocument();
  });

  it('I5: 부모 항목 드래그 핸들 부재(이동 불가)', () => {
    const actions = [baseAction({ handler: 'setState' }), routeAction({ handler: 'toast', params: { message: 'hi' } })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // base 그룹 항목 드래그 핸들 부재(잠금).
    expect(screen.queryByTestId('g7le-init-action-base-drag-0')).not.toBeInTheDocument();
  });

  it('I6: 자식 항목 삭제 → 자식 구간만 patch(부모 보존, 병합 배열)', () => {
    const onChange = vi.fn();
    const base0 = baseAction({ handler: 'setState' });
    const route1 = routeAction({ handler: 'toast', params: { message: 'hi' } });
    const route2 = routeAction({ handler: 'filterLoad' });
    render(<InitActionsForm actions={[base0, route1, route2]} onChange={onChange} recipes={RECIPES} t={t} />);
    // self 첫 항목(route1) 삭제 → base 보존 + route2 남김.
    fireEvent.click(screen.getByTestId('g7le-init-action-self-remove-0'));
    expect(onChange).toHaveBeenLastCalledWith([base0, route2]);
  });

  it('I7: 자식 항목 드래그 순서이동 → 자식 구간 내 재배치(부모 뒤 고정)', () => {
    const onChange = vi.fn();
    const base0 = baseAction({ handler: 'setState' });
    const route1 = routeAction({ handler: 'toast', params: { message: 'a' } });
    const route2 = routeAction({ handler: 'filterLoad' });
    render(<InitActionsForm actions={[base0, route1, route2]} onChange={onChange} recipes={RECIPES} t={t} />);
    // self 첫(상대 0 = route1)을 self 둘째(상대 1 = route2) 아래로 → [base0, route2, route1].
    fireEvent.dragStart(screen.getByTestId('g7le-init-action-self-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-init-action-self-item-1'), { clientY: 10 });
    fireEvent.drop(screen.getByTestId('g7le-init-action-self-item-1'));
    expect(onChange).toHaveBeenLastCalledWith([base0, route2, route1]);
  });

  it('I8: base 그룹 읽기전용 — "[공통 레이아웃에서 수정]" 버튼·동작 추가 picker 부재 + 안내문', () => {
    const actions = [baseAction({ handler: 'setState' }), routeAction({ handler: 'toast', params: { message: 'hi' } })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // "[공통 레이아웃에서 수정]" 버튼 제거.
    expect(screen.queryByTestId('g7le-init-action-edit-in-base')).not.toBeInTheDocument();
    // base 그룹: 동작 추가 picker 부재(읽기전용) + 안내문 표시.
    expect(screen.queryByTestId('g7le-init-action-base-add-picker-toggle')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-base-hint')).toBeInTheDocument();
  });

  it('I9: 자식 추가 → 부모·__source 보존(복제 0), 새 자식은 __source 부재', () => {
    const onChange = vi.fn();
    const base0 = baseAction({ handler: 'setState' });
    const route1 = routeAction({ handler: 'toast', params: { message: 'hi' } });
    render(<InitActionsForm actions={[base0, route1]} onChange={onChange} recipes={RECIPES} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-init-action-self-add-picker-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-setState'));
    const next = onChange.mock.calls.at(-1)![0] as Array<Record<string, unknown>>;
    const baseItems = next.filter((a) => (a.__source as { kind?: string })?.kind === 'base');
    expect(baseItems).toHaveLength(1);
    expect(baseItems[0]).toBe(base0);
    expect(next.at(-1)).toEqual({ handler: 'setState' });
  });

  it('I10: 자기 레이어(route)로만 주입 → 상속모드 아님(전부 편집)', () => {
    const asSelfLayer = [routeAction({ handler: 'setState' }, '_user_base'), routeAction({ handler: 'toast', params: { message: 'hi' } }, '_user_base')];
    render(<InitActionsForm actions={asSelfLayer} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.queryByTestId('g7le-init-action-group-base')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-edit-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-remove-1')).toBeInTheDocument();
  });

  it('I11: 부모(고급 보존)+자식 → 부모 고급 항목도 🔒 + 코드 보기만(자식 편집 불가)', () => {
    const actions = [baseAction({ handler: 'apiCall', onSuccess: [{ handler: 'toast' }] }), routeAction({ handler: 'setState' })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // base 고급 항목 — 잠금(편집/삭제 부재) + 고급 배지 + 코드 보기.
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-base-advanced-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-base-code-0')).toBeInTheDocument();
  });

  it('I12: 부모(확장 제공)+자식 → base 우선 잠금(확장 제공이어도)', () => {
    const actions = [baseAction({ handler: 'filterLoad' }), routeAction({ handler: 'setState' })];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // base 메타이므로 잠금(편집 불가).
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    // 출처 배지는 base 항목에도 표시.
    expect(screen.getByTestId('g7le-init-action-base-source-0')).toBeInTheDocument();
  });

  it('I13: 3단 상속(조부모→부모→자식) → 상위 전부 🔒(base), 자식만 편집', () => {
    const actions = [
      baseAction({ handler: 'setState' }, '_root_base'),
      baseAction({ handler: 'toast', params: { message: 'g' } }, '_user_base'),
      routeAction({ handler: 'filterLoad' }),
    ];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // base 그룹 2개 전부 잠금, self 그룹(상대 0) 만 편집.
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-remove-1')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-remove-0')).toBeInTheDocument();
  });

  it('I14: 조건(if) 동반 부모/자식 → 부모 if 〔조건〕 배지(잠금), 자식 if 편집 가능', () => {
    const actions = [
      baseAction({ handler: 'toast', params: { message: 'a' }, if: '{{query.error}}' }),
      routeAction({ handler: 'toast', params: { message: 'b' }, if: '{{query.welcome}}' }),
    ];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // 부모(base) 조건 배지 + self 조건 배지.
    expect(screen.getByTestId('g7le-init-action-base-cond-badge-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-cond-badge-0')).toBeInTheDocument();
    // 부모 if 항목 잠금(편집 부재), 자식 if 항목 편집 가능.
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-edit-0')).toBeInTheDocument();
  });

  it('I15: 모달 호스트 + 부모 → 호스트 자체 init_actions 편집(상속 그룹 정상), 자식 삭제 시 호스트 배열만 patch', () => {
    const hostActions = [baseAction({ handler: 'setState' }), routeAction({ handler: 'toast', params: { message: 'host' } })];
    const onChange = vi.fn();
    render(<InitActionsForm actions={hostActions} onChange={onChange} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-group-base')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-remove-0')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-init-action-self-remove-0'));
    expect(onChange).toHaveBeenLastCalledWith([hostActions[0]]);
  });
});
