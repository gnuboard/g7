/**
 * @file validateP7RefundAccountMask.test.tsx
 * @description #599 validate P7-B — 신청서 환불 계좌 칸과 가린 등록 계좌 프리필
 *
 * 등록 계좌 프리필은 가린 모양(`********7890` · `홍**`)으로 온다. 서버는 세 칸이 모두 가린 모양 그대로일
 * 때만 원문으로 되돌리므로, 화면의 세 칸은 한 핸들러(`setClaimRefundAccountField`)를 거쳐 「한 칸을 고치면
 * 나머지 가린 칸을 비우는」 규칙을 탄다. 칸이 값을 직접 `setState` 로 적으면 그 규칙을 건너뛴다.
 * 가린 값이 채워져 있는 동안에는 그 사실을 칸 옆에 알린다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const PARTIAL = path.resolve(__dirname, '../../layouts/partials/mypage/claims/_apply_refund_account.json');

function loadPartial(): any {
  return JSON.parse(fs.readFileSync(PARTIAL, 'utf-8'));
}

function collect(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (!node || typeof node !== 'object') return out;
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) collect(value, predicate, out);
  return out;
}

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', ({ className }: any) => React.createElement('i', { className }));
  return registry;
}

describe('환불 계좌 칸 (_apply_refund_account)', () => {
  let active: { cleanup: () => void } | null = null;
  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * 세 칸(은행·계좌번호·예금주)의 변경은 모두 같은 핸들러를 거친다 — 칸이 값을 직접 적으면 가린 값과
   * 새 값이 섞여 저장되는 경로가 생긴다.
   *
   * @scenario prefill=masked, edit=account_partial
   * @effects claim_refund_account_fields_route_through_mask_guard
   */
  it('은행·계좌번호·예금주 변경이 모두 setClaimRefundAccountField 를 거친다', () => {
    const fields = collect(loadPartial(), (n) => n?.handler === 'sirsoft-ecommerce.setClaimRefundAccountField')
      .map((a) => a.params?.field)
      .sort();

    expect(fields).toEqual(['account_number', 'bank_code', 'holder']);

    const direct = collect(
      loadPartial(),
      (n) => n?.handler === 'setState' && JSON.stringify(n.params ?? {}).match(/claimRefundBank(Code|Account|Holder)/)
    );
    expect(direct).toEqual([]);
  });

  /**
   * 가린 값이 채워져 있는 동안에는 「가려진 등록 계좌 — 바꾸려면 모두 새로 입력」 을 알린다.
   *
   * @scenario prefill=masked, edit=untouched
   * @effects claim_refund_account_masked_notice_shown
   */
  it('가린 프리필이면 안내를 띄우고, 풀리면 감춘다', async () => {
    const base = {
      claimType: 'refund',
      claimableSnapshot: { refund_account: { required: true, banks: [] } },
      claimRefundBankCode: '004',
      claimRefundBankAccount: '********7890',
      claimRefundBankHolder: '홍**',
    };

    let utils = createLayoutTest({ components: [loadPartial()] }, {
      initialState: { _local: { ...base, claimRefundAccountMasked: true } },
      locale: 'ko',
      componentRegistry: setupRegistry(),
    });
    let { container } = await utils.render();
    active = utils;
    expect(container.textContent ?? '').toContain('claim.apply.refund_account_masked_hint');
    utils.cleanup();

    utils = createLayoutTest({ components: [loadPartial()] }, {
      initialState: { _local: { ...base, claimRefundAccountMasked: false } },
      locale: 'ko',
      componentRegistry: setupRegistry(),
    });
    ({ container } = await utils.render());
    active = utils;
    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_masked_hint');
  });
});
