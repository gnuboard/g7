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
 * @scenario terminal_path=manual_deposit_confirm, payment_method=dbank, option_mix=all_active, actor=admin
 * @effects manual_deposit_confirm_seeds_page_local_before_open, manual_deposit_confirm_rejects_amount_mismatch_422_inline_and_toast
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
        n.actions.some((a) => a.handler === 'sirsoft-ecommerce.openConfirmDepositModal')
    );
  }

  it('입금확인 버튼이 openConfirmDepositModal JS 핸들러를 호출한다 (선언적 openModal 아님 — scope 시드 보장)', () => {
    // 회귀 가드: openModal 직접 호출이면 모달 열기 전 페이지 _local 시드가 누락되어
    // 입금액/입금자명이 페이지 scope 와 단절된다. 반드시 JS 핸들러 경유여야 한다.
    expect(depositButton()).toBeDefined();
    const usesRawOpenModal = nodes.some(
      (n) =>
        n.name === 'Button' &&
        Array.isArray(n.actions) &&
        n.actions.some((a) => a.handler === 'openModal' && a.target === 'modal_confirm_deposit')
    );
    expect(usesRawOpenModal, '입금확인 버튼은 선언적 openModal 을 직접 호출하면 안 된다').toBe(false);
  });

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

  it('Modal 타입의 partial 이고 id=modal_confirm_deposit', () => {
    expect((modal as any).meta?.is_partial).toBe(true);
    expect(modal.type).toBe('composite');
    expect(modal.name).toBe('Modal');
    expect(modal.id).toBe('modal_confirm_deposit');
  });

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

  it('인라인 에러 표시가 _local.depositConfirmErrors 를 참조한다 (페이지 scope 에러 채널)', () => {
    const json = JSON.stringify(modal);
    expect(json).toContain('_local.depositConfirmErrors');
  });

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

  it('확인 버튼이 markOrderComplete params 를 confirmDeposit 으로 전달한다', () => {
    const allActions: Action[] = [];
    for (const n of nodes) collectActions(n.actions, allActions);
    const confirm = allActions.find((a) => a.handler === 'sirsoft-ecommerce.confirmDeposit');
    expect(confirm).toBeDefined();
    expect(String(confirm!.params?.markOrderComplete ?? '')).toContain('_local.markOrderComplete');
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
