// e2e:allow conditions 분기 편집(branch-list) RTL — 카드/분기 if·then/추가·삭제, Chrome MCP 매트릭스로 실측 보강.
/**
 * ConditionsBranchList.test.tsx — conditions recipe + branch-list 위젯 RTL
 *
 * 검증: conditions 액션이 친화 카드(preset)로 매칭되고 [편집] 시 분기별 실행조건(if)·동작(then)이
 * 친화 편집되며(분기 추가/삭제), 편집 결과가 conditions 최상위 키 배열로 무손실 반영되는지.
 * 결제 진입(requestPgPayment placeholder 핸들러) then 이 분기 안에서 보존되는지까지.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import { ActionListBuilder } from '../../../components/page-settings/ActionListBuilder';
import { CORE_ACTION_RECIPES } from '../../../spec/coreActionRecipes';

const t = (k: string) => k;

// 결제 진입(requestPgPayment)은 코어가 아닌 ecommerce 모듈 소유다(결제=커머스 도메인). 실제
// 런타임은 editorSpecLoader 가 코어 시드 위에 모듈 actionRecipes 를 병합한다 — 그 병합본을
// 여기서 시뮬레이션한다(코어 카탈로그 + 모듈 requestPgPayment recipe). 라벨은 모듈 네임스페이스.
const ECOMMERCE_REQUEST_PG_PAYMENT = {
  label: '$t:sirsoft-ecommerce.editor.action.request_pg_payment.label',
  params: [
    { key: 'paymentHandler', label: '$t:sirsoft-ecommerce.editor.action.request_pg_payment.param_handler', widget: 'data-chip', required: true },
    { key: 'pgPaymentData', label: '$t:sirsoft-ecommerce.editor.action.request_pg_payment.param_data', widget: 'data-chip', required: true },
  ],
  build: { handler: '{{paymentHandler}}', params: { pgPaymentData: '{{pgPaymentData}}' } },
  __source: { kind: 'module', id: 'sirsoft-ecommerce' },
};
// 코어 시드 + 모듈 병합본 — 실제 화면이 받는 recipes 와 동형.
const RECIPES = { ...CORE_ACTION_RECIPES, requestPgPayment: ECOMMERCE_REQUEST_PG_PAYMENT } as Record<string, unknown>;

beforeEach(() => cleanup());

/** checkout 결제 분기와 동형: PG 분기(결제 진입) + else(navigate). */
const conditionsAction = {
  handler: 'conditions',
  conditions: [
    {
      if: '{{response.data.requires_pg_payment && response.data.pg_payment_handler}}',
      then: { handler: '{{response.data.pg_payment_handler}}', params: { pgPaymentData: '{{response.data.pg_payment_data}}' } },
    },
    { then: { handler: 'navigate', params: { path: '/complete' } } },
  ],
};

describe('conditions recipe + branch-list 위젯', () => {
  it('conditions 액션이 친화 카드(preset)로 매칭되어 [편집] 가능 — advanced 아님', () => {
    render(<ActionListBuilder actions={[conditionsAction]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    // advanced(고급)면 편집 버튼이 없다. preset 이면 편집 토글이 있다.
    expect(screen.getByTestId('g7le-action-list-edit-0')).toBeInTheDocument();
  });

  it('[편집] 시 분기별 실행조건(if)·동작(then) 입력칸 + 분기 추가 버튼이 렌더된다', () => {
    render(<ActionListBuilder actions={[conditionsAction]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    // branch-list 가 두 분기 박스를 렌더.
    expect(screen.getByTestId('g7le-action-list-edit-branches-branch-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-action-list-edit-branches-branch-1')).toBeInTheDocument();
    // 분기 추가 버튼.
    expect(screen.getByTestId('g7le-action-list-edit-branches-add-branch')).toBeInTheDocument();
  });

  it('분기 추가 → conditions 배열에 빈 분기 push', () => {
    const onChange = vi.fn();
    render(<ActionListBuilder actions={[conditionsAction]} onChange={onChange} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-branches-add-branch'));
    const last = onChange.mock.calls.at(-1)![0];
    expect(last[0].conditions).toHaveLength(3);
    expect(last[0].conditions[2]).toEqual({ if: '', then: undefined });
  });

  it('분기 삭제 → conditions 배열에서 제거', () => {
    const onChange = vi.fn();
    render(<ActionListBuilder actions={[conditionsAction]} onChange={onChange} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-branches-branch-1-remove'));
    const last = onChange.mock.calls.at(-1)![0];
    expect(last[0].conditions).toHaveLength(1);
    expect(last[0].conditions[0].if).toContain('requires_pg_payment');
  });

  it('PG 분기의 then(결제 진입, 동적 핸들러)이 분기 안 친화 카드로 노출된다', () => {
    render(<ActionListBuilder actions={[conditionsAction]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    // 분기0의 then 은 중첩 ActionListBuilder — 그 안에 결제 진입(requestPgPayment) 카드가 1건.
    const branch0 = screen.getByTestId('g7le-action-list-edit-branches-branch-0');
    // then 영역의 중첩 빌더 요약에 "결제 진입" 라벨 키가 노출(requestPgPayment preset).
    const summary = within(branch0).getByTestId('g7le-action-list-edit-branches-branch-0-then-summary-0');
    expect(summary.textContent).toContain('request_pg_payment');
  });
});

describe('checkout 결제하기 버튼 전체 액션 트리 — 4단계 깊이 친화 도달', () => {
  // 실제 checkout 구조: sequence(onClick) > [setState, apiCall] ; apiCall.onSuccess > [conditions] ;
  // conditions[0].then = 결제 진입. F1(apiCall.onSuccess·sequence.actions 친화) + F2(conditions recipe)로
  // 모든 단계가 친화 카드로 펼쳐져 결제 진입까지 코드 없이 도달함을 검증.
  const checkoutOnClick = {
    handler: 'sequence',
    params: {
      actions: [
        { handler: 'setState', params: { target: '_local.isSubmittingOrder', value: true } },
        {
          handler: 'apiCall',
          target: '/api/modules/sirsoft-ecommerce/user/orders',
          params: { method: 'POST' },
          onSuccess: [
            {
              handler: 'conditions',
              conditions: [
                { if: '{{response.data.requires_pg_payment && response.data.pg_payment_handler}}', then: { handler: '{{response.data.pg_payment_handler}}', params: { pgPaymentData: '{{response.data.pg_payment_data}}' } } },
                { then: { handler: 'navigate', params: { path: '/complete' } } },
              ],
            },
          ],
        },
      ],
    },
  };

  it('① sequence(onClick) 친화 카드 — [고급] 아님', () => {
    render(<ActionListBuilder actions={[checkoutOnClick]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    expect(screen.getByTestId('g7le-action-list-edit-0')).toBeInTheDocument();
  });

  it('② sequence 펼침 → 안의 apiCall 카드가 친화 노출 (sequence.actions advanced 해제 확인)', () => {
    render(<ActionListBuilder actions={[checkoutOnClick]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    // sequence.actions 중첩 빌더에 setState(0), apiCall(1) 카드 노출.
    expect(screen.getByTestId('g7le-action-list-edit-actions-summary-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-action-list-edit-actions-summary-1').textContent).toContain('api_call');
  });

  it('③④ apiCall 펼침 → onSuccess 친화 → conditions 친화 → 결제 진입 카드까지 도달', () => {
    render(<ActionListBuilder actions={[checkoutOnClick]} onChange={vi.fn()} t={t} recipes={RECIPES} />);
    // sequence 편집 (ParamFieldList prefix = g7le-action-list-edit, sequence.actions 빌더 = ...-edit-actions)
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-0'));
    // apiCall(actions[1]) 편집 (그 ParamFieldList prefix = ...-edit-actions-edit, onSuccess 빌더 = ...-edit-actions-edit-onSuccess)
    fireEvent.click(screen.getByTestId('g7le-action-list-edit-actions-edit-1'));
    // apiCall.onSuccess 안의 conditions 카드(0) 편집 — onSuccess advanced 해제 확인
    const onSuccessConditionsEdit = screen.getByTestId('g7le-action-list-edit-actions-edit-onSuccess-edit-0');
    expect(onSuccessConditionsEdit).toBeInTheDocument();
    fireEvent.click(onSuccessConditionsEdit);
    // conditions 분기0의 then 결제 진입 카드 노출 (4단계 깊이 친화 도달).
    const prefix = 'g7le-action-list-edit-actions-edit-onSuccess-edit-branches-branch-0';
    const branch0 = screen.getByTestId(prefix);
    const summary = within(branch0).getByTestId(`${prefix}-then-summary-0`);
    expect(summary.textContent).toContain('request_pg_payment');
  });
});
