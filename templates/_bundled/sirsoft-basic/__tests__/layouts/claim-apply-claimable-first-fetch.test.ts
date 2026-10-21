/**
 * @file claim-apply-claimable-first-fetch.test.ts
 * @description 클레임 신청서 — 주문 ID 를 모르는 진입에서 claimable-options 를 0 으로 부르지 않는다 (7.1.1)
 *
 * 수정 주소·주소 직접 입력 진입은 `?order_id`(비회원은 `:order_number`)가 없어, claimable 의 첫 호출이
 * `orders/0/claimable-options` 404 로 한 번 더 나갔다. 같은 id 의 두 판을 배타 if 로 두고, 식별자가 없는 판은
 * auto_fetch false 로 order 응답의 refetch 를 기다린다.
 *
 * @scenario-manifest tests/scenarios/claim-apply-claimable-first-fetch.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

const load = (rel: string): any => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../', rel), 'utf-8'));

/** order 데이터소스 성공 처리 안의 refetchDataSource 대상 id 목록. */
function refetchTargets(order: any): string[] {
  const out: string[] = [];
  (function walk(o: unknown): void {
    if (Array.isArray(o)) { o.forEach(walk); return; }
    if (!o || typeof o !== 'object') return;
    const n = o as Record<string, any>;
    if (n.handler === 'refetchDataSource') out.push(String(n.params?.dataSourceId ?? n.target ?? ''));
    Object.values(n).forEach(walk);
  })(order.onSuccess);

  return out;
}

describe.each([
  ['member', 'layouts/mypage/claims/apply.json', '{{!!query.order_id}}', '{{!query.order_id}}'],
  ['guest', 'layouts/shop/guest_claim_apply.json', '{{!!route.order_number}}', '{{!route.order_number}}'],
])('%s 셸 claimable 첫 호출 (7.1.1)', (_shell, file, ifDirect, ifChain) => {
  const layout = load(file);
  const sources = (layout.data_sources ?? []) as any[];
  const claimables = sources.filter((s) => s.id === 'claimable');
  const order = sources.find((s) => s.id === 'order');

  // describe.each 가 회원·비회원 두 셸에서 같은 단언을 돈다 — 마커 파서는 주석 블록 하나에서 시나리오 조합을 하나만 읽으므로 셸마다 블록을 둔다.
  /**
   * @scenario shell=member, entry=id_in_address
   * @effects claimable_direct_fetch_when_id_in_address
   */
  /**
   * @scenario shell=guest, entry=id_in_address
   * @effects claimable_direct_fetch_when_id_in_address
   */
  it('claimable 은 두 판이고, 식별자가 주소에 있는 판(먼저)만 곧바로 부른다', () => {
    expect(claimables).toHaveLength(2);
    expect(claimables[0].if).toBe(ifDirect);
    expect(claimables[0].auto_fetch).toBe(true);
  });

  /**
   * 종전 판은 if 없이 auto_fetch true 하나라 식별자 없는 진입에서 `…/0/claimable-options` 404 가 나갔다.
   *
   * @scenario shell=member, entry=id_not_in_address
   * @effects claimable_waits_for_order_chain
   */
  /**
   * @scenario shell=guest, entry=id_not_in_address
   * @effects claimable_waits_for_order_chain
   */
  it('식별자가 없는 판은 auto_fetch false 이고 order 성공 처리가 claimable 을 다시 부른다', () => {
    expect(claimables[1].if).toBe(ifChain);
    expect(claimables[1].auto_fetch).toBe(false);
    expect(String(claimables[1].endpoint)).not.toMatch(/query\.order_id|route\.order_number/);
    expect(refetchTargets(order)).toContain('claimable');
  });

  /**
   * @scenario shell=member, entry=id_not_in_address
   * @effects claimable_variants_share_handlers
   */
  /**
   * @scenario shell=guest, entry=id_not_in_address
   * @effects claimable_variants_share_handlers
   */
  it('두 판의 성공·404 처리가 같다', () => {
    expect(claimables[1].onSuccess).toEqual(claimables[0].onSuccess);
    expect(claimables[1].errorHandling?.['404']?.handler).toBe(claimables[0].errorHandling?.['404']?.handler);
    expect(claimables[1].headers).toEqual(claimables[0].headers);
  });
});
