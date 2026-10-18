/**
 * @file claimPaymentResultBanner.test.tsx
 * @description 클레임 추가결제 PG 콜백 결과 안내 — 신청서 상세 배너 (#599 S12 → S13 이관)
 *
 * PG 결제창에서 돌아온 구매자에게 결과를 알리는 자리다. 이 자리가 없으면 결제가 성공했는지
 * 실패했는지 화면에 남지 않는다 — 처리는 서버에서 정상 완료되므로 어긋난 사실이 어디에도
 * 드러나지 않고, 구매자만 돈이 나갔는지 모른 채 남는다.
 *
 * 2026-08-31 브라우저 실측이 찾은 결함의 회귀 가드다. 원래는 돌아갈 화면 자체가 없어
 * (신청서 상세는 뒤 세션 소유) 성공·실패·취소 전부 404 가 열렸다. S12 는 그 사이 주문
 * 상세를 임시 목적지로 삼았고, S13 이 신청서 상세를 세우면서 배너와 복귀 주소를 **함께**
 * 원래 자리로 옮겼다 — 한쪽만 옮기면 안내가 사라지거나 두 곳에 뜬다.
 *
 * 세 결과를 **구분해서** 그리는지 잰다 — 하나로 뭉뚱그리면 실패가 성공처럼 읽힌다.
 *
 * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
 * @effects claim_payment_result_banner_distinguishes_three_outcomes,
 *   claim_payment_result_banner_absent_without_the_query
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

/** 신청서 상세 셸에서 결과 배너 노드만 떼어 낸다 — 셸 전체는 이 파일의 관심사가 아니다. */
function bannerNode(): any {
  const shell = JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, '../../layouts/mypage/claims/show.json'),
      'utf-8'
    )
  );

  const found: any[] = [];
  const walk = (node: any) => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) return node.forEach(walk);
    // 바깥 게이트만 고른다 — 안쪽 줄(실패 안내 등)도 같은 변수를 읽으므로 세 값을 모두
    // 열거하는 노드가 배너 자신이다.
    if (
      typeof node.if === 'string'
      && node.if.includes('query.claim_payment')
      && node.if.includes('success')
      && node.if.includes('failed')
      && node.if.includes('cancelled')
    ) found.push(node);
    for (const v of Object.values(node)) walk(v);
  };
  walk(shell);

  expect(found, '결과 배너 노드가 신청서 상세에 없다').toHaveLength(1);

  return found[0];
}

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', ({ className, name }: any) =>
    React.createElement('i', { className, 'data-icon': name })
  );

  return registry;
}

async function renderWith(query: Record<string, string>) {
  const utils = createLayoutTest(
    { components: [bannerNode()] },
    {
      initialState: { _local: {} },
      locale: 'ko',
      translations: {
        claim: {
          payment_result: {
            success: '추가 결제가 완료되었습니다.',
            failed: '추가 결제가 완료되지 않았습니다.',
            cancelled: '결제를 취소하셨습니다. 신청은 그대로 남아 있습니다.',
            claim_number: '신청번호 {{number}}',
            error_code: '실패 코드 {{code}}',
            retry_hint: '신청 내역에서 다시 결제하실 수 있습니다.',
          },
        },
      },
      componentRegistry: setupRegistry(),
      queryParams: query,
    }
  );

  const { container } = await utils.render();

  return { utils, container };
}

describe('클레임 추가결제 결과 배너', () => {
  it('주문 상세에는 남아 있지 않다 — 이관은 옮기는 것이지 복제가 아니다', () => {
    const orderShell = fs.readFileSync(
      path.resolve(__dirname, '../../layouts/mypage/orders/show.json'),
      'utf-8'
    );

    expect(orderShell).not.toContain('claim_payment');
  });

  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  it('성공 — 완료 안내와 신청번호가 나오고 재시도 안내는 나오지 않는다', async () => {
    const { utils, container } = await renderWith({
      claim_payment: 'success',
      claim_number: 'RT20260831-0001',
    });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.payment_result.success');
    expect(text).not.toContain('claim.payment_result.failed');
    expect(text).not.toContain('claim.payment_result.cancelled');
    // 신청번호는 파라미터 치환까지 간다 — 번호가 실리지 않으면 어느 건의 결제인지 알 수 없다.
    expect(text).toContain('신청번호 RT20260831-0001');
    expect(text).not.toContain('다시 결제하실 수 있습니다');
    expect(container.querySelector('[data-icon="circle-check"]')).not.toBeNull();
  });

  it('실패 — 실패 안내와 재시도 안내가 함께 나온다 (성공과 구분된다)', async () => {
    const { utils, container } = await renderWith({
      claim_payment: 'failed',
      error: 'amount_mismatch',
      claim_number: 'RT20260831-0001',
    });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.payment_result.failed');
    expect(text).toContain('다시 결제하실 수 있습니다');
    expect(text).not.toContain('claim.payment_result.success');
    // 서버는 계약대로 실패 코드를 함께 싣는다(`?error={code}`) — 감추면 구매자가 고객센터에
    // 말할 것이 남지 않고 운영자도 어느 실패인지 알 수 없다.
    expect(text).toContain('실패 코드 amount_mismatch');
    expect(container.querySelector('[data-icon="circle-exclamation"]')).not.toBeNull();
  });

  it('실패 — 코드가 실리지 않은 실패에서는 코드 줄을 세우지 않는다 (빈 코드 표시 금지)', async () => {
    const { utils, container } = await renderWith({ claim_payment: 'failed' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.payment_result.failed');
    expect(text).toContain('다시 결제하실 수 있습니다');
    expect(text).not.toContain('실패 코드');
  });

  it('사용자 중단 — 실패가 아니라 중단으로 안내하고 재시도 안내를 붙이지 않는다', async () => {
    const { utils, container } = await renderWith({ claim_payment: 'cancelled' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.payment_result.cancelled');
    expect(text).not.toContain('claim.payment_result.failed');
    expect(text).not.toContain('다시 결제하실 수 있습니다');
  });

  it('신청번호가 없으면 그 줄만 빠지고 결과 안내는 남는다', async () => {
    const { utils, container } = await renderWith({ claim_payment: 'success' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.payment_result.success');
    expect(text).not.toContain('신청번호');
  });

  it('결제창을 거쳐 오지 않은 평소 진입에는 배너가 서지 않는다', async () => {
    const { utils, container } = await renderWith({});
    active = utils;

    expect((container.textContent ?? '').trim()).toBe('');
  });

  it('알 수 없는 값은 배너를 세우지 않는다 (임의의 쿼리로 안내를 띄울 수 없다)', async () => {
    const { utils, container } = await renderWith({ claim_payment: 'whatever' });
    active = utils;

    expect((container.textContent ?? '').trim()).toBe('');
  });
});
