// e2e:allow 권한 게이트(입력·버튼 잠금) 레이아웃 계약 검증 — 브라우저 실측은 W1·W2·W3 통합 뒤 총괄 세션이 권한 회수 계정으로 함께 한다(이력 20261010_0208_710_abilities수정_W3)
/**
 * @file plugin-abilities-gate.test.ts
 * @description sirsoft-pay_nhnkcp 화면 abilities 게이트 (7.1.0 abilities 게이트 plugins·G04)
 */

import { describe, it, expect } from 'vitest';
import ext from '../../../extensions/admin_order_payment_query.json';
import settings from '../../../layouts/admin/plugin_settings.json';

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
});

describe('시스템 점검 카드 (N4)', () => {
  /**
   * 점검 API 가 403 이면 빈 카드 대신 이유를 보인다.
   *
   * @scenario case=health_forbidden
   *
   * @effects health_forbidden_reason_shown
   */
  it('403 안내', () => {
    const notice = collect(settings, (n) => n.id === 'health_check_forbidden')[0];
    expect(evaluate(notice.if, { _local: { healthForbidden: true } })).toBe(true);
    expect(JSON.stringify(settings)).toContain('healthForbidden');
  });
});
