// e2e:allow [화면 동작] 폼 단위(RTL) — 카드/순서/추가/편집/상속 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * InitActionsForm.test.tsx — [화면 동작] 탭 폼 RTL
 *
 *  ② 공통화 후: 카드/순서/추가/편집/출처 배지는 공용 ActionListBuilder 에 위임된다. 본 폼은
 * 상속 그룹 분리(base/route)만 담당한다. testid 는 ActionListBuilder testIdPrefix 기반:
 *  - 비상속: prefix `g7le-init-action`
 *  - 상속 base: `g7le-init-action-base`, route(self): `g7le-init-action-self`
 *
 * 검증:
 *  ① 인스턴스 카드 + 출처 배지(모든 항목)
 *  ② 순서 ▲▼ 버튼 부재(드래그 전용) + ⠿ 핸들 드래그 재배치 + 드롭 위치 표시
 *  ③ 삭제 → 배열에서 제거
 *  ④ if 동반 항목 〔조건〕 배지
 *  ⑤ [편집] → params 인라인 폼(스펙 위젯)
 *  ⑥ 고급 보존(matchAction null) 코드 열람·드래그, 편집 비활성
 *  ⑦ 추가(ActionAddPicker) → 배열 push
 *  ⑧ 상속 — base/route 그룹 분리, 부모 항목 🔒 잠금, 실행 순서 안내
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitActionsForm } from '../../../components/page-settings/InitActionsForm';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';

const t = (k: string) => k;

const RECIPES = {
  setState: { label: '$t:화면 상태', build: { handler: 'setState' }, __source: { kind: 'core' } },
  toast: {
    label: '$t:안내 메시지',
    params: [
      { key: 'message', label: '$t:메시지', widget: 'text', required: true },
      { key: 'type', label: '$t:종류', widget: 'select', options: [{ value: 'info' }, { value: 'warning' }] },
    ],
    build: { handler: 'toast', params: { message: '{{message}}', type: '{{type}}' } },
    __source: { kind: 'core' },
  },
  filterLoad: {
    label: '$t:필터 불러오기',
    build: { handler: 'filterLoad' },
    __source: { kind: 'module', id: 'sirsoft-ecommerce' },
  },
} as const;

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  registerWidget('text', ({ value, onChange }) => (
    <input data-testid="w-text" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
  registerWidget('select', ({ value, onChange, control }) => (
    <select data-testid="w-select" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)}>
      <option value="" />
      {(control.options ?? []).map((o) => (
        <option key={String((o as { value: unknown }).value)} value={String((o as { value: unknown }).value)}>
          {String((o as { value: unknown }).value)}
        </option>
      ))}
    </select>
  ));
});

describe('InitActionsForm — 기본 (공통화 위임)', () => {
  it('인스턴스 카드 + 모든 항목 출처 배지를 렌더한다', () => {
    const actions = [
      { handler: 'setState', __source: { kind: 'core' } },
      { handler: 'filterLoad', __source: { kind: 'module', id: 'sirsoft-ecommerce' } },
    ];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-item-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-source-0').textContent).toContain('source_core');
    expect(screen.getByTestId('g7le-init-action-source-1').textContent).toContain('sirsoft-ecommerce');
  });

  it('순서 변경 ▲▼ 버튼은 노출되지 않는다(드래그 전용)', () => {
    const actions = [{ handler: 'setState' }, { handler: 'toast', params: { message: 'hi' } }];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.queryByTestId('g7le-init-action-up-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-down-0')).not.toBeInTheDocument();
  });

  it('⠿ 핸들 HTML5 드래그로 임의 위치 재배치', () => {
    const onChange = vi.fn();
    const actions = [
      { handler: 'setState' },
      { handler: 'toast', params: { message: 'a' } },
      { handler: 'toast', params: { message: 'b' } },
    ];
    render(<InitActionsForm actions={actions} onChange={onChange} recipes={RECIPES} t={t} />);
    const handle0 = screen.getByTestId('g7le-init-action-drag-0');
    expect(handle0).toHaveAttribute('draggable', 'true');
    fireEvent.dragStart(handle0);
    const card2 = screen.getByTestId('g7le-init-action-item-2');
    fireEvent.dragOver(card2, { clientY: 10 });
    fireEvent.drop(card2);
    expect(onChange).toHaveBeenLastCalledWith([actions[1], actions[2], actions[0]]);
  });

  it('드래그 중 드롭 예정 지점의 삽입선이 활성화된다(드롭 위치 표시)', () => {
    const actions = [
      { handler: 'setState' },
      { handler: 'toast', params: { message: 'a' } },
    ];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-dropline-0').getAttribute('data-active')).toBe('false');
    expect(screen.getByTestId('g7le-init-action-dropline-end').getAttribute('data-active')).toBe('false');
    fireEvent.dragStart(screen.getByTestId('g7le-init-action-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-init-action-item-1'), { clientY: 10 });
    expect(screen.getByTestId('g7le-init-action-dropline-end').getAttribute('data-active')).toBe('true');
    fireEvent.dragEnd(screen.getByTestId('g7le-init-action-drag-0'));
    expect(screen.getByTestId('g7le-init-action-dropline-end').getAttribute('data-active')).toBe('false');
  });

  it('삭제 시 배열에서 제거한다', () => {
    const onChange = vi.fn();
    const actions = [{ handler: 'setState' }, { handler: 'toast', params: { message: 'hi' } }];
    render(<InitActionsForm actions={actions} onChange={onChange} recipes={RECIPES} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-init-action-remove-1'));
    expect(onChange).toHaveBeenLastCalledWith([actions[0]]);
  });

  it('if 동반 항목은 〔조건〕 배지를 표시한다', () => {
    const actions = [{ handler: 'toast', params: { message: 'hi' }, if: '{{query.error}}' }];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-cond-badge-0')).toBeInTheDocument();
  });

  it('[편집] 펼치면 스펙 params 위젯을 디스패치하고 값 변경이 buildAction 재생성', () => {
    const onChange = vi.fn();
    const actions = [{ handler: 'toast', params: { message: 'hi' } }];
    render(<InitActionsForm actions={actions} onChange={onChange} recipes={RECIPES} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-init-action-edit-0'));
    // ParamFieldList testIdPrefix = `${prefix}-edit` → 파라미터 testid.
    expect(screen.getByTestId('g7le-init-action-edit-param-message')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-edit-param-type')).toBeInTheDocument();
    // message(widget:text)는 데이터칩 입력기(DataChipValueInput)로 디스패치된다(평문 입력 아님 —
    // ). 평문 분기 input testid = `${prefix}-chip-message-input`.
    fireEvent.change(screen.getByTestId('g7le-init-action-edit-chip-message-input'), { target: { value: '새 안내' } });
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0][0];
    expect(next.params.message).toBe('새 안내');
  });

  it('고급 보존 항목은 [편집] 부재 + 코드 열람 + 드래그 순서이동 가능', () => {
    const actions = [
      { handler: 'apiCall', onSuccess: [{ handler: 'toast' }] },
      { handler: 'setState' },
    ];
    render(<InitActionsForm actions={actions} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // apiCall 은 recipes 미정의 → 고급(편집 버튼 부재).
    expect(screen.queryByTestId('g7le-init-action-edit-0')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-advanced-0')).toBeInTheDocument();
    // 코드 보기 버튼 존재 + 드래그 핸들 draggable.
    expect(screen.getByTestId('g7le-init-action-code-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-drag-0')).toHaveAttribute('draggable', 'true');
  });

  it('추가(ActionAddPicker) 로 배열에 push 한다', () => {
    const onChange = vi.fn();
    render(<InitActionsForm actions={[]} onChange={onChange} recipes={RECIPES} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-init-action-add-picker-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-setState'));
    expect(onChange).toHaveBeenCalledWith([{ handler: 'setState' }]);
  });
});

describe('InitActionsForm — 상속', () => {
  const inherited = [
    { handler: 'setState', __source: { kind: 'base', layout: '_user_base' } },
    { handler: 'toast', params: { message: 'hi' }, __source: { kind: 'route', layout: 'home' } },
  ];

  it('base/route 그룹을 분리하고 실행 순서를 안내한다', () => {
    render(<InitActionsForm actions={inherited} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    expect(screen.getByTestId('g7le-init-action-group-base')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-group-self')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-merge-order')).toBeInTheDocument();
  });

  it('부모(base) 항목은 🔒 잠금 — [편집]/[✕]/드래그 핸들 부재, 자식만 편집 가능', () => {
    render(<InitActionsForm actions={inherited} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // base 그룹 항목(인덱스 0) — 잠금(편집/삭제/드래그 핸들 부재).
    expect(screen.getByTestId('g7le-init-action-base-item-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-edit-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-remove-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-drag-0')).not.toBeInTheDocument();
    // self 그룹 항목(인덱스 0 = route toast) — 편집/드래그 가능.
    expect(screen.getByTestId('g7le-init-action-self-remove-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-init-action-self-drag-0')).toHaveAttribute('draggable', 'true');
  });

  it('base 그룹은 읽기전용 — "[공통에서 수정]" 버튼 부재 + 안내문 표시 + 추가 picker 부재', () => {
    render(<InitActionsForm actions={inherited} onChange={vi.fn()} recipes={RECIPES} t={t} />);
    // "[공통에서 수정]" 버튼 제거.
    expect(screen.queryByTestId('g7le-init-action-edit-in-base')).not.toBeInTheDocument();
    // base 그룹 안내문 + base 그룹 추가(동작 추가) picker 부재(읽기전용).
    expect(screen.getByTestId('g7le-init-action-base-hint')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-init-action-base-add-picker-toggle')).not.toBeInTheDocument();
    // self 그룹에는 추가 picker 가 있다(자식 동작 추가 가능).
    expect(screen.getByTestId('g7le-init-action-self-add-picker-toggle')).toBeInTheDocument();
  });

  it('자식(self) 삭제 시 base 보존하고 self 만 제거한 병합 배열로 onChange', () => {
    const onChange = vi.fn();
    render(<InitActionsForm actions={inherited} onChange={onChange} recipes={RECIPES} t={t} />);
    // self 그룹 첫 항목(route toast) 삭제 → base 만 남는다.
    fireEvent.click(screen.getByTestId('g7le-init-action-self-remove-0'));
    expect(onChange).toHaveBeenCalledWith([inherited[0]]);
  });
});
