// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-abilities-gate.test.ts
 * @description sirsoft-pay_kginicis 화면 abilities 게이트 (7.1.0 abilities 게이트 plugins·G04)
 */

import { describe, it, expect } from 'vitest';
import ext from '../../../extensions/admin_order_payment_query.json';
import settings from '../../../layouts/admin/plugin_settings.json';
import receipt from '../../../extensions/user_order_show.json';

type Node = Record<string, any>;

/** 트리에서 조건에 맞는 노드를 모두 모은다 */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, pred, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: unknown, scope: Record<string, unknown>): any {
  if (typeof raw === 'boolean') return raw;
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
/** 쓰기 apiCall 을 품은 버튼 */
function writeButtons(tree: unknown, exclude: (target: string) => boolean = () => false): Node[] {
  return collect(tree, (n) => n.name === 'Button' && collect(n, (a) => a.handler === 'apiCall' && WRITE.includes(String(a.params?.method ?? '')) && !exclude(String(a.target ?? ''))).length > 0);
}

describe('관리자 주문 상세 결제 주입 (G04)', () => {
  /**
   * 호스트 주문 응답 abilities.can_update 가 true 가 아니면 쓰기 버튼·입력이 잠긴다(거래 조회는 그대로).
   *
   * @scenario case=order_update_denied
   *
   * @effects payment_injection_write_locked
   */
  it('쓰기 버튼·입력이 can_update 로 잠긴다', () => {
    const scope = { order: { data: { abilities: { can_update: false, can_execute_refund: false } } }, _local: {}, _global: {}, route: {} };
    const buttons = writeButtons(ext, (t) => t.includes('transaction/query'));
    expect(buttons.length).toBeGreaterThan(0);
    buttons.forEach((b) => expect(evaluate(b.props?.disabled ?? false, scope), String(b.id ?? '')).toBe(true));
    const inputs = collect(ext, (n) => ['Input', 'Select', 'Textarea'].includes(n.name));
    inputs.forEach((i) => expect(evaluate(i.props?.disabled ?? false, scope)).toBe(true));
    const open = { order: { data: { abilities: { can_update: true, can_execute_refund: true } } }, _local: {}, _global: {}, route: {} };
    buttons.forEach((b) => expect(String(b.props?.disabled ?? '')).toContain('order?.data?.abilities?.can_'));
    expect(open).toBeTruthy();
  });

  /**
   * 환불 재시도는 「환불 실행」 권한(can_execute_refund)으로만 열린다(R1).
   *
   * @scenario case=refund_retry_without_execute_refund
   *
   * @effects refund_retry_requires_execute_refund
   */
  it('환불 재시도는 can_execute_refund 를 본다', () => {
    const retry = writeButtons(ext).find((b) => JSON.stringify(b).includes('refund-retry'))!;
    expect(retry, '환불 재시도 버튼이 없습니다').toBeTruthy();
    expect(evaluate(retry.props.disabled, { order: { data: { abilities: { can_update: true, can_execute_refund: false } } }, _local: {} })).toBe(true);
    expect(evaluate(retry.props.disabled, { order: { data: { abilities: { can_update: false, can_execute_refund: true } } }, _local: {} })).toBeFalsy();
  });
});

describe('설정 화면 CBT 테스트 상품·사용자 은행코드', () => {
  /**
   * CBT 테스트 상품 만들기는 상품 등록 권한 판정(vbank_info.abilities)으로 잠긴다.
   *
   * @scenario case=cbt_product_without_create
   *
   * @effects cbt_product_button_locked
   */
  it('CBT 테스트 상품 버튼', () => {
    const button = writeButtons(settings).find((b) => JSON.stringify(b).includes('cbt-test-product'))!;
    const scope = { _computed: { isPluginReadOnly: false }, _local: {}, vbank_info: { data: { abilities: { can_create_cbt_test_product: false } } } };
    expect(evaluate(button.props.disabled, scope)).toBe(true);
    scope.vbank_info.data.abilities.can_create_cbt_test_product = true;
    expect(evaluate(button.props.disabled, scope)).toBeFalsy();
  });

  /**
   * 은행코드 줄은 화면의 is_admin 이 아니라 서버가 내려준 값 유무로 그린다.
   *
   * @scenario case=bank_code_by_server
   *
   * @effects bank_code_not_gated_by_is_admin
   */
  it('은행코드 줄', () => {
    expect(JSON.stringify(receipt)).not.toContain('currentUser?.is_admin === true && !!_local._kgReceipt?.vbank_bank_code');
  });
});
