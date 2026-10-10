// e2e:allow 레이아웃 JSON 판독을 모양(actions 배열 첨자)에서 뜻(layoutShapeUtils.actionsOf)으로 옮긴 테스트 전용 리팩토링 — 대상 레이아웃·핸들러·런타임 동작은 바뀌지 않았고, 단언이 보는 명제도 동일하다 (U-SHAPEASSERT 판정 ⒜ · D67 ⓐ 미전환 잔여분 해소).
/**
 * @file adminOrderConfirmDeposit.test.tsx
 * @description 관리자 주문상세 무통장 입금확인 버튼+모달 구조 + 핸들러 회귀 테스트 (B2 / D7 / 입금UI#2 scope)
 *
 * 테스트 대상:
 * - layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json (입금확인 버튼)
 * - layouts/admin/partials/admin_ecommerce_order_detail/_modal_confirm_deposit.json (모달)
 * - resources/js/handlers/orderDetailHandlers.ts (openConfirmDepositModalHandler / confirmDepositHandler)
 *
 * 회귀 배경 (입금UI#2 scope 단절):
 *   modals 섹션 모달은 격리 scope 라, 모달 input 이 쓴 $parent._local 값이 페이지 globalLocal 에
 *   도달하지 않는다. 선언적 apiCall + onError(sequence 내 setState + toast) 패턴은 422 토스트/인라인
 *   에러가 글로벌 상태 경합으로 소실된다. 이를 취소모달 패턴(모달 열기 전 setLocal 시드 + JS 핸들러
 *   직접 처리)으로 전환했다. 본 테스트는 그 전환이 깨지지 않도록 구조+핸들러 양쪽을 고정한다.
 *
 * 축 요약(마커 아님, 평문): 관리자 무통장 입금확인 경로(terminal_path, payment_method, actor).
 * 테스트별 시나리오와 효과 매핑은 각 it 바로 위 주석에 둔다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import paymentInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import depositModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_confirm_deposit.json';
import {
  openConfirmDepositModalHandler,
  confirmDepositHandler,
} from '../../handlers/orderDetailHandlers';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

interface Action {
  type?: string;
  handler: string;
  target?: string;
  params?: Record<string, any>;
  actions?: Action[];
  onSuccess?: Action[];
  onError?: Action[];
}

interface Node {
  type?: string;
  name?: string;
  id?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  actions?: Action[];
}

function flatten(node: any, acc: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return acc;
  acc.push(node);
  if (Array.isArray(node.children)) for (const c of node.children) flatten(c, acc);
  return acc;
}

function collectActions(actions: Action[] | undefined, acc: Action[] = []): Action[] {
  if (!Array.isArray(actions)) return acc;
  for (const a of actions) {
    acc.push(a);
    if (a.actions) collectActions(a.actions, acc);
    if (Array.isArray(a.params?.actions)) collectActions(a.params!.actions as Action[], acc);
    if (a.onSuccess) collectActions(a.onSuccess, acc);
    if (a.onError) collectActions(a.onError, acc);
  }
  return acc;
}

describe('_partial_payment_info.json — 입금확인 버튼 (B2 / 입금UI#2)', () => {
  const nodes = flatten(paymentInfo);

  function depositButton(): Node | undefined {
    return nodes.find(
      (n) =>
        n.name === 'Button' &&
        Array.isArray(n.actions) &&
        actionsOf(n).some((a) => a.handler === 'sirsoft-ecommerce.openConfirmDepositModal')
    );
  }

  /**
   * 입금확인 버튼은 선언적 openModal 이 아니라 페이지 _local 을 시드하는 JS 핸들러로 모달을 연다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects manual_deposit_confirm_seeds_page_local_before_open
   */
  it('입금확인 버튼이 openConfirmDepositModal JS 핸들러를 호출한다 (선언적 openModal 아님 — scope 시드 보장)', () => {
    // 회귀 가드: openModal 직접 호출이면 모달 열기 전 페이지 _local 시드가 누락되어
    // 입금액/입금자명이 페이지 scope 와 단절된다. 반드시 JS 핸들러 경유여야 한다.
    expect(depositButton()).toBeDefined();
    const usesRawOpenModal = nodes.some(
      (n) =>
        n.name === 'Button' &&
        Array.isArray(n.actions) &&
        actionsOf(n).some((a) => a.handler === 'openModal' && a.target === 'modal_confirm_deposit')
    );
    expect(usesRawOpenModal, '입금확인 버튼은 선언적 openModal 을 직접 호출하면 안 된다').toBe(false);
  });

  /**
   * 입금확인 버튼 노출은 order_status 가 아니라 결제 단위 payment_status(ready, waiting_deposit)로 판정한다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_button_visible_by_payment_status_not_order_status
   */
  it('입금확인 버튼은 무통장(dbank) + 결제정보 자체가 미입금(payment_status=ready/waiting_deposit)인 결제건에서만 노출된다', () => {
    // 회귀 가드: 노출 판정을 부모 order_status 가 아닌 payment 단위 payment_status 로 한다.
    // 버튼은 payments 반복 내 결제카드 단위로 그려지므로, order_status 가 다른 경로로 먼저
    // payment_complete 로 전이돼도 미입금 결제는 입금확인이 필요하다(order_status 기준이면 버튼 소실).
    const btn = depositButton()!;
    const container = nodes.find(
      (n) => Array.isArray(n.children) && n.children.includes(btn as any)
    );
    const cond = String(container?.if ?? '');
    expect(cond).toContain("payment.payment_method === 'dbank'");
    expect(cond).toContain("payment.payment_status === 'ready'");
    expect(cond).toContain("payment.payment_status === 'waiting_deposit'");
    // order_status 의존 제거 확인 (버그 회귀 방지)
    expect(cond).not.toContain('order.data?.order_status');
  });
});

describe('_modal_confirm_deposit.json — 입금확인 모달 (B2 / D7 / 입금UI#2)', () => {
  const modal = depositModal as Node;
  const nodes = flatten(modal);

  /**
   * 입금확인 모달은 id 가 modal_confirm_deposit 인 Modal partial 이다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_modal_is_partial_with_fixed_id
   */
  it('Modal 타입의 partial 이고 id=modal_confirm_deposit', () => {
    expect((modal as any).meta?.is_partial).toBe(true);
    expect(modal.type).toBe('composite');
    expect(modal.name).toBe('Modal');
    expect(modal.id).toBe('modal_confirm_deposit');
  });

  /**
   * 입금자명 입력은 _local 바인딩과 target local setState 로 페이지 scope 를 공유한다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_modal_inputs_share_page_local_scope
   */
  it('입금자명 입력 필드(depositor_name)가 _local 바인딩 + target:"local" setState 로 페이지 scope 를 공유한다', () => {
    const input = nodes.find((n) => n.name === 'Input' && n.props?.name === 'depositor_name');
    expect(input).toBeDefined();
    // value 가 _local 바인딩 ($parent._local 아님 — 모달이 페이지 _local 을 상속)
    expect(String(input!.props?.value ?? '')).toContain('_local.depositorName');
    const setStates = collectActions(input!.actions);
    const local = setStates.find(
      (a) => a.handler === 'setState' && a.params?.target === 'local' && 'depositorName' in (a.params ?? {})
    );
    expect(local, 'depositor_name input 은 target:"local" setState 여야 함').toBeDefined();
  });

  /**
   * 입금액 입력은 number 타입이며 _local 바인딩과 target local setState 로 페이지 scope 를 공유한다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_modal_inputs_share_page_local_scope
   */
  it('입금액 입력 필드(amount, number)가 _local.depositAmount 바인딩 + target:"local" setState', () => {
    const input = nodes.find((n) => n.name === 'Input' && n.props?.name === 'amount');
    expect(input).toBeDefined();
    expect(input!.props?.type).toBe('number');
    expect(String(input!.props?.value ?? '')).toContain('_local.depositAmount');
    const setStates = collectActions(input!.actions);
    const local = setStates.find(
      (a) => a.handler === 'setState' && a.params?.target === 'local' && 'depositAmount' in (a.params ?? {})
    );
    expect(local, 'amount input 은 target:"local" setState 여야 함').toBeDefined();
  });

  /**
   * 확인 버튼은 선언적 apiCall 대신 confirmDeposit 핸들러에 입금액과 입금자명을 넘긴다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_submit_uses_js_handler_with_modal_values
   */
  it('확인 버튼이 confirmDeposit JS 핸들러를 호출하고 amount/depositorName params 를 전달한다 (선언적 apiCall 아님)', () => {
    const allActions: Action[] = [];
    for (const n of nodes) collectActions(n.actions, allActions);

    // 회귀 가드: apiCall(target=confirm-deposit) 선언적 경로는 422 토스트/인라인 경합 소실 결함.
    const declarativeApiCall = allActions.find(
      (a) => a.handler === 'apiCall' && typeof a.target === 'string' && a.target.includes('/confirm-deposit')
    );
    expect(declarativeApiCall, '확인 버튼은 선언적 apiCall 을 쓰면 안 된다').toBeUndefined();

    const confirm = allActions.find((a) => a.handler === 'sirsoft-ecommerce.confirmDeposit');
    expect(confirm, 'confirmDeposit JS 핸들러 호출 존재').toBeDefined();
    expect(confirm!.params?.orderId).toBeDefined();
    // 모달 표현식이 amount/depositorName 을 params 로 박아 넣어야 페이지 scope 단절을 우회한다.
    expect(String(confirm!.params?.amount ?? '')).toContain('_local.depositAmount');
    expect(String(confirm!.params?.depositorName ?? '')).toContain('_local.depositorName');
  });

  /**
   * 인라인 에러 표시는 페이지 scope 의 _local.depositConfirmErrors 를 읽는다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_modal_inline_errors_read_page_local
   */
  it('인라인 에러 표시가 _local.depositConfirmErrors 를 참조한다 (페이지 scope 에러 채널)', () => {
    const json = JSON.stringify(modal);
    expect(json).toContain('_local.depositConfirmErrors');
  });

  /**
   * 결제완료 처리 체크박스는 _local.markOrderComplete 를 target local setState 로 토글한다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_mark_order_complete_flows_from_checkbox_to_handler
   */
  it("'원 주문서 결제완료 처리' 체크박스가 있고 _local.markOrderComplete 를 토글한다", () => {
    const checkbox = nodes.find(
      (n) => n.name === 'Input' && n.props?.name === 'mark_order_complete'
    );
    expect(checkbox, 'mark_order_complete 체크박스 존재').toBeDefined();
    expect(checkbox!.props?.type).toBe('checkbox');
    expect(String(checkbox!.props?.checked ?? '')).toContain('_local.markOrderComplete');
    const setStates = collectActions(checkbox!.actions);
    const local = setStates.find(
      (a) => a.handler === 'setState' && a.params?.target === 'local' && 'markOrderComplete' in (a.params ?? {})
    );
    expect(local, '체크박스는 target:"local" markOrderComplete setState 여야 함').toBeDefined();
  });

  /**
   * 확인 버튼은 _local.markOrderComplete 값을 confirmDeposit 핸들러에 넘긴다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_mark_order_complete_flows_from_checkbox_to_handler
   */
  it('확인 버튼이 markOrderComplete params 를 confirmDeposit 으로 전달한다', () => {
    const allActions: Action[] = [];
    for (const n of nodes) collectActions(n.actions, allActions);
    const confirm = allActions.find((a) => a.handler === 'sirsoft-ecommerce.confirmDeposit');
    expect(confirm).toBeDefined();
    expect(String(confirm!.params?.markOrderComplete ?? '')).toContain('_local.markOrderComplete');
  });

  /**
   * 운영자가 상태를 먼저 올려 둔 주문(상품준비중 등)은 서버가 체크와 무관하게 입금과 결제금액만
   * 기록하고 상태를 둔다. 그 주문에 「결제완료로 처리」 체크를 내보이면 체크한 대로 되지 않는다(실측 2026-10-03).
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects manual_deposit_does_not_regress_order_already_past_payment
   */
  it('결제 전 주문에만 「결제완료로 처리」 체크가 서고, 결제 이후 주문에는 상태를 두고 기록만 한다는 안내가 선다', () => {
    const checkbox = nodes.find((n) => n.name === 'Input' && n.props?.name === 'mark_order_complete');
    const checkboxBox = nodes.find(
      (n) => Array.isArray(n.children) && flatten({ children: n.children }).includes(checkbox as any) && typeof n.if === 'string'
    );
    const hint = nodes.find((n) => n.props?.['data-testid'] === 'confirm-deposit-past-payment-hint');
    expect(checkboxBox?.if, '체크박스 상자에 결제 전 조건이 있어야 한다').toBeDefined();
    expect(hint?.if, '결제 이후 안내에 조건이 있어야 한다').toBeDefined();
    expect(JSON.stringify(hint)).toContain('confirm_deposit.past_payment_hint');

    const evaluate = (expr: string, status: string): boolean =>
      // eslint-disable-next-line no-new-func
      Boolean(new Function('order', `return (${expr.replace(/^\{\{|\}\}$/g, '')});`)({
        // 서버 리소스 is_past_payment(OrderStatusEnum::isPastPayment)와 같은 판정
        data: { order_status: status, is_past_payment: !['pending_order', 'pending_payment', 'cancelled'].includes(status) },
      }));

    // 결제 단계 판정은 서버가 준 값으로 한다 — 레이아웃이 상태 문자열을 직접 비교하지 않는다
    expect(checkboxBox!.if).toContain('is_past_payment');
    expect(hint!.if).toContain('is_past_payment');
    expect(`${checkboxBox!.if}${hint!.if}`).not.toMatch(/pending_order|pending_payment/);

    for (const status of ['pending_order', 'pending_payment']) {
      expect(evaluate(checkboxBox!.if!, status), `${status} 는 체크를 고를 수 있다`).toBe(true);
      expect(evaluate(hint!.if!, status), `${status} 에 안내가 서면 안 된다`).toBe(false);
    }
    for (const status of ['payment_complete', 'preparing', 'shipping', 'delivered']) {
      expect(evaluate(checkboxBox!.if!, status), `${status} 에 체크가 서면 안 된다`).toBe(false);
      expect(evaluate(hint!.if!, status), `${status} 는 안내가 선다`).toBe(true);
    }
  });
});

describe('openConfirmDepositModalHandler — 모달 열기 전 페이지 _local 시드 (입금UI#2 scope)', () => {
  let setLocalMock: ReturnType<typeof vi.fn>;
  let modalOpenMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    setLocalMock = vi.fn();
    modalOpenMock = vi.fn();
    (window as any).G7Core = {
      state: { setLocal: setLocalMock, getLocal: () => ({}) },
      modal: { open: modalOpenMock },
      dataSource: {
        get: (id: string) =>
          id === 'order'
            ? { data: { depositor_name: '홍길동', total_due_amount: 23000 } }
            : undefined,
      },
    };
  });

  /**
   * 모달 열기 핸들러는 order 데이터소스의 입금자명과 결제예정금액으로 페이지 _local 을 먼저 시드한 뒤 모달을 연다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects manual_deposit_confirm_seeds_page_local_before_open
   */
  it('order 데이터소스의 depositor_name/total_due_amount 로 페이지 _local 을 시드한 뒤 모달을 연다', () => {
    openConfirmDepositModalHandler({ handler: 'sirsoft-ecommerce.openConfirmDepositModal' }, {} as any);

    expect(setLocalMock).toHaveBeenCalledTimes(1);
    const seeded = setLocalMock.mock.calls[0][0];
    expect(seeded.depositorName).toBe('홍길동');
    expect(seeded.depositAmount).toBe(23000);
    expect(seeded.depositConfirmErrors).toBeNull();
    expect(seeded.isConfirmingDeposit).toBe(false);

    // 시드가 모달 열기보다 먼저 호출되어야 모달이 시드된 _local 을 상속한다.
    expect(modalOpenMock).toHaveBeenCalledWith('modal_confirm_deposit');
    expect(setLocalMock.mock.invocationCallOrder[0]).toBeLessThan(
      modalOpenMock.mock.invocationCallOrder[0]
    );
  });
});

describe('confirmDepositHandler — 422 인라인 에러 + 토스트 동시 표시 (입금UI#2 scope)', () => {
  let setLocalMock: ReturnType<typeof vi.fn>;
  let toastErrorMock: ReturnType<typeof vi.fn>;
  let toastSuccessMock: ReturnType<typeof vi.fn>;
  let patchMock: ReturnType<typeof vi.fn>;
  let modalCloseMock: ReturnType<typeof vi.fn>;
  let dispatchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    setLocalMock = vi.fn();
    toastErrorMock = vi.fn();
    toastSuccessMock = vi.fn();
    patchMock = vi.fn();
    modalCloseMock = vi.fn();
    dispatchMock = vi.fn();
    (window as any).G7Core = {
      state: { setLocal: setLocalMock, getLocal: () => ({ depositAmount: 23000, depositorName: '홍길동' }) },
      toast: { error: toastErrorMock, success: toastSuccessMock },
      modal: { close: modalCloseMock },
      dispatch: dispatchMock,
      api: { patch: patchMock },
      identity: { isIdentityRequired: () => false },
      t: () => undefined,
    };
  });

  /**
   * 금액 불일치 422 응답은 depositConfirmErrors.amount 인라인 에러와 오류 토스트를 함께 띄운다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects manual_deposit_confirm_rejects_amount_mismatch_422_inline_and_toast
   */
  it('금액 불일치(422) 시 depositConfirmErrors.amount 인라인 에러 + toast.error 를 모두 표시한다', async () => {
    const err: any = new Error('mismatch');
    err.response = {
      status: 422,
      data: { errors: { detail: '결제 금액이 일치하지 않습니다. (예상: 20,000원, 실제: 23,000원)' } },
    };
    patchMock.mockRejectedValueOnce(err);

    await confirmDepositHandler(
      { handler: 'sirsoft-ecommerce.confirmDeposit', params: { orderId: 'ORD-1', amount: 20000, depositorName: '홍길동' } },
      {} as any
    );

    // 인라인 에러 채널(_local.depositConfirmErrors.amount) 설정
    const errorCall = setLocalMock.mock.calls
      .map((c) => c[0])
      .find((p) => p && p.depositConfirmErrors?.amount);
    expect(errorCall, 'depositConfirmErrors.amount 인라인 에러 설정').toBeDefined();
    expect(errorCall.depositConfirmErrors.amount[0]).toContain('일치하지 않습니다');

    // 토스트도 함께 표시 (선언적 경합 소실 결함의 회귀 가드)
    expect(toastErrorMock).toHaveBeenCalledTimes(1);
    expect(String(toastErrorMock.mock.calls[0][0])).toContain('일치하지 않습니다');
  });

  /**
   * 성공 응답이면 모달을 닫고 order, order_logs 를 다시 읽고 성공 토스트를 띄운다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects manual_deposit_confirm_success_closes_modal_and_refetches
   */
  it('성공(200) 시 모달 닫기 + order/order_logs refetch + 성공 토스트', async () => {
    patchMock.mockResolvedValueOnce({ success: true });

    await confirmDepositHandler(
      { handler: 'sirsoft-ecommerce.confirmDeposit', params: { orderId: 'ORD-1', amount: 23000, depositorName: '홍길동' } },
      {} as any
    );

    expect(toastSuccessMock).toHaveBeenCalledTimes(1);
    expect(modalCloseMock).toHaveBeenCalledWith('modal_confirm_deposit');
    const refetched = dispatchMock.mock.calls
      .map((c) => c[0])
      .filter((a) => a?.handler === 'refetchDataSource')
      .map((a) => a.params?.dataSourceId);
    expect(refetched).toContain('order');
    expect(refetched).toContain('order_logs');
  });

  /**
   * 체크된 mark_order_complete 는 PATCH 본문에 boolean true 로 실린다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_sends_mark_order_complete_as_boolean
   */
  it('mark_order_complete 를 PATCH body 에 boolean 으로 전달한다 (체크 시 true)', async () => {
    patchMock.mockResolvedValueOnce({ success: true });

    await confirmDepositHandler(
      {
        handler: 'sirsoft-ecommerce.confirmDeposit',
        params: { orderId: 'ORD-1', amount: 23000, depositorName: '홍길동', markOrderComplete: true },
      },
      {} as any
    );

    const body = patchMock.mock.calls[0][1];
    expect(body.mark_order_complete).toBe(true);
  });

  /**
   * 문자열로 온 mark_order_complete 도 PATCH 본문에는 boolean 으로 실린다.
   *
   * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, actor=admin
   * @effects deposit_confirm_sends_mark_order_complete_as_boolean
   */
  it('문자열 "true"/"false" 로 와도 boolean 으로 정규화한다 (체크박스 표현식 호환)', async () => {
    patchMock.mockResolvedValueOnce({ success: true });

    await confirmDepositHandler(
      {
        handler: 'sirsoft-ecommerce.confirmDeposit',
        params: { orderId: 'ORD-1', amount: 23000, markOrderComplete: 'false' as any },
      },
      {} as any
    );

    const body = patchMock.mock.calls[0][1];
    expect(body.mark_order_complete).toBe(false);
  });
});
