// e2e:allow 다중 액션 빌더 단위(RTL) — 카드/순서/추가/chipContext, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ActionListBuilder.test.tsx — 다중 액션 빌더 RTL
 *
 * 검증:
 *  ① 배열 in/out(1건도 배열 유지)
 *  ② renderAddPicker 로 추가 → 카드 push
 *  ③ 순서 ▲▼ → 배열 순서 정합
 *  ④ 카드 삭제
 *  ⑤ 친화 요약 + 코드 보기
 *  ⑥ chipContext prop 보존(분기)
 *  ⑦ 빈 배열 + 추가
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ActionListBuilder } from '../../../components/page-settings/ActionListBuilder';

const t = (k: string) => k;

const RECIPES = {
  toast: { label: '$t:안내 메시지', params: [{ key: 'message' }], build: { handler: 'toast', params: { message: '{{message}}' } } },
};

beforeEach(() => cleanup());

describe('ActionListBuilder', () => {
  it('친화 요약 + 드래그 순서/삭제 + chipContext 분기', () => {
    const onChange = vi.fn();
    const actions = [
      { handler: 'toast', params: { message: '안녕' } },
      { handler: 'navigate', params: { path: '/shop' } },
    ];
    render(<ActionListBuilder actions={actions} onChange={onChange} t={t} recipes={RECIPES} chipContext="response" />);
    expect(screen.getByTestId('g7le-action-list-summary-0').textContent).toContain('안내 메시지');
    expect(screen.getByTestId('g7le-action-list-summary-1').textContent).toContain('navigate');

    // 순서 변경 ▲▼ 버튼 제거 — ⠿ 핸들 드래그로만.
    expect(screen.queryByTestId('g7le-action-list-up-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-action-list-down-0')).not.toBeInTheDocument();
    // 카드0을 카드1 아래 절반으로 드래그&드롭 → 삽입 지점 2 → 순서 뒤집힘.
    fireEvent.dragStart(screen.getByTestId('g7le-action-list-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-action-list-item-1'), { clientY: 10 });
    fireEvent.drop(screen.getByTestId('g7le-action-list-item-1'));
    expect(onChange).toHaveBeenLastCalledWith([actions[1], actions[0]]);

    fireEvent.click(screen.getByTestId('g7le-action-list-remove-1'));
    expect(onChange).toHaveBeenLastCalledWith([actions[0]]);
  });

  // 드롭 위치 표시 — 드래그 중 포인터가 가리키는 삽입 지점의 삽입선만 활성.
  it('드래그 중 드롭 예정 지점의 삽입선이 활성화된다(드롭 위치 표시)', () => {
    const actions = [
      { handler: 'toast', params: { message: '안녕' } },
      { handler: 'navigate', params: { path: '/shop' } },
    ];
    render(<ActionListBuilder actions={actions} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-action-list-dropline-0').getAttribute('data-active')).toBe('false');
    expect(screen.getByTestId('g7le-action-list-dropline-end').getAttribute('data-active')).toBe('false');
    fireEvent.dragStart(screen.getByTestId('g7le-action-list-drag-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-action-list-item-1'), { clientY: 10 });
    expect(screen.getByTestId('g7le-action-list-dropline-end').getAttribute('data-active')).toBe('true');
    fireEvent.dragEnd(screen.getByTestId('g7le-action-list-drag-0'));
    expect(screen.getByTestId('g7le-action-list-dropline-end').getAttribute('data-active')).toBe('false');
  });

  it('renderAddPicker 로 추가 → 배열 push(1건도 배열 유지)', () => {
    const onChange = vi.fn();
    render(
      <ActionListBuilder
        actions={[]}
        onChange={onChange}
        t={t}
        recipes={RECIPES}
        renderAddPicker={(onAdd) => <button data-testid="add" onClick={() => onAdd({ handler: 'closeModal' })}>add</button>}
      />,
    );
    fireEvent.click(screen.getByTestId('add'));
    expect(onChange).toHaveBeenCalledWith([{ handler: 'closeModal' }]);
  });

  it('코드 보기 토글 → 실제 JSON', () => {
    render(<ActionListBuilder actions={[{ handler: 'toast', params: { message: 'hi' } }]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-code-0'));
    expect(screen.getByTestId('g7le-action-list-code-view-0').textContent).toContain('"handler": "toast"');
  });

  it('빈 배열 + renderAddPicker 미주입 시 추가 버튼 부재', () => {
    render(<ActionListBuilder actions={[]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    expect(screen.queryByTestId('g7le-action-list-item-0')).not.toBeInTheDocument();
  });

  // base(읽기전용) 그룹은 동작 추가 불가.
  it('hideAddPicker — 동작 추가 picker 미렌더(읽기전용 그룹)', () => {
    render(
      <ActionListBuilder
        actions={[{ handler: 'toast', params: { message: 'hi' } }]}
        onChange={vi.fn()}
        t={t}
        recipes={RECIPES}
        hideAddPicker
      />,
    );
    expect(screen.queryByTestId('g7le-action-list-add-picker-toggle')).not.toBeInTheDocument();
    // hideAddPicker 미지정(기본) 시에는 추가 picker 가 보인다.
    cleanup();
    render(<ActionListBuilder actions={[]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-action-list-add-picker-toggle')).toBeInTheDocument();
  });

  // 카드당 코드 보기 `</>` 버튼은 1개만(미리보기 중복 제거).
  it('카드당 코드 보기 버튼은 1개만 — 미리보기(ActionPreview) 코드 버튼 중복 없음', () => {
    render(<ActionListBuilder actions={[{ handler: 'toast', params: { message: 'hi' } }]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    // 카드 우측 단일 코드 버튼.
    expect(screen.getByTestId('g7le-action-list-code-0')).toBeInTheDocument();
    // 미리보기(ActionPreview)는 코드 토글 버튼을 그리지 않는다(showCodeButton=false).
    expect(screen.queryByTestId('g7le-action-list-preview-0-code-toggle')).not.toBeInTheDocument();
  });
});

// chipContext → 컨텍스트 칩 주입 (죽은 prop 부활). data-chip param 입력칸이 그 컨텍스트
// (response/error/payload)의 변수 칩 + 확장 도메인 응답 칩을 검색 피커 후보로 노출하는지.
describe('ActionListBuilder — chipContext 컨텍스트 칩 주입', () => {
  // data-chip param 을 가진 recipe — 결제 진입(모듈)과 동형. placeholder 핸들러 매칭을 위해
  // build.params 에 sole-binding 키(pgPaymentData)를 둔다(placeholderRecipeStructureMatches).
  const CHIP_RECIPES = {
    requestPgPayment: {
      label: '$t:결제 진입',
      params: [
        { key: 'paymentHandler', label: '$t:결제 핸들러', widget: 'data-chip' },
        { key: 'pgPaymentData', label: '$t:결제 데이터', widget: 'data-chip' },
      ],
      build: { handler: '{{paymentHandler}}', params: { pgPaymentData: '{{pgPaymentData}}' } },
    },
  };
  const PG_EXTRA = {
    response: [
      { path: 'data.pg_payment_handler', labelKey: 'mod.pg_handler', shape: 'scalar' as const },
    ],
  };

  it('chipContext="response" + actionChipCandidates → data-chip 입력칸이 응답 칩(코어+확장 PG)을 후보로 노출', () => {
    // paymentHandler 빈 값 → DataChipValueInput 평문 분기(검색 토글 노출). pgPaymentData 는
    // placeholder 매칭용 sole-binding 키 유지(매칭 성립 → 친화 편집 가능).
    const action = { handler: '', params: { pgPaymentData: '{{response.data.pg_payment_data}}' } };
    render(
      <ActionListBuilder
        actions={[action]}
        onChange={vi.fn()}
        t={t}
        recipes={CHIP_RECIPES}
        chipContext="response"
        actionChipCandidates={PG_EXTRA}
      />,
    );
    // 카드 편집 펼침 → paymentHandler data-chip 입력칸의 검색 토글(평문 분기).
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    const toggles = screen.queryAllByTestId(/^g7le-inline-binding-search-toggle-/);
    expect(toggles.length).toBeGreaterThan(0);
    // 모든 검색 토글을 열어(paymentHandler/pgPaymentData) 후보를 노출.
    toggles.forEach((tg) => fireEvent.click(tg));
    const candidates = screen
      .queryAllByTestId(/^g7le-inline-binding-candidate-/)
      .map((el) => el.getAttribute('data-testid'));
    // 핸들러 입력칸은 scalar 칩 피커(InlineBindingScalarPicker) — scalar 후보만 노출한다.
    // 코어 응답 scalar 칩(response.message) + 확장 PG scalar 응답 칩(response.data.pg_payment_handler)이
    // 함께 도달함을 검증(부수의무 핵심: 확장 도메인 응답 필드가 컴포넌트 동작 onSuccess 안 칩 후보로 노출).
    // response.data 는 object shape 라 scalar 피커에서 제외되는 게 정상(별도 object 칩 입력칸에서 노출).
    expect(candidates).toContain('g7le-inline-binding-candidate-{{response.message}}');
    expect(candidates).toContain('g7le-inline-binding-candidate-{{response.data.pg_payment_handler}}');
  });

  it('chipContext 미지정(컨텍스트 없음) → 응답 칩 후보 미노출(컨텍스트 칩 안 합쳐짐)', () => {
    const action = { handler: '{{x}}', params: { pgPaymentData: '{{y}}' } };
    render(
      <ActionListBuilder
        actions={[action]}
        onChange={vi.fn()}
        t={t}
        recipes={CHIP_RECIPES}
        actionChipCandidates={PG_EXTRA}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    // candidatePools 미주입 + chipContext 없음 → 검색 토글(후보)이 없다.
    expect(screen.queryByTestId('g7le-inline-binding-candidate-{{response.data}}')).not.toBeInTheDocument();
  });

  it('회귀 — candidatePools 에 이미 같은 컨텍스트 칩이 있으면 이중 합산 안 함(ErrorHandlingForm 패턴)', () => {
    // ErrorHandlingForm 은 error 칩(errorCands)을 pools.bindingCandidates 에 직접 주입한 뒤
    // chipContext="error" 도 준다. 빌더가 또 error 칩을 합치면 중복 노출 → expression 기준 dedup.
    const errorChip = {
      expression: '{{error.message}}',
      source: '_local' as const,
      sourceId: 'error',
      path: 'message',
      shape: 'scalar' as const,
      labelKey: 'x',
      preview: 'err msg',
    };
    const action = { handler: '', params: { pgPaymentData: '{{error.data}}' } };
    render(
      <ActionListBuilder
        actions={[action]}
        onChange={vi.fn()}
        t={t}
        recipes={CHIP_RECIPES}
        chipContext="error"
        candidatePools={{ bindingCandidates: [errorChip] }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    screen.queryAllByTestId(/^g7le-inline-binding-search-toggle-/).forEach((tg) => fireEvent.click(tg));
    // error.message 칩은 정확히 1개만(pools 의 것 + 컨텍스트 칩 중복 제거).
    const messageChips = screen.queryAllByTestId('g7le-inline-binding-candidate-{{error.message}}');
    expect(messageChips).toHaveLength(1);
  });
});
