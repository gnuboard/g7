/** 클레임 pending 회귀 — API 쓰기는 고정 응답으로 대체하고 실제 화면·모듈을 검증한다. */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';
const preview = { success: true, data: { claims: [{ id: 31, claim_number: 'RT-FEEDBACK', completable_item_ids: [55], completable_item_count: 1, has_stock_restore_target: true, stock_restore_count: 1, refund: { applicable: true, refund_amount_formatted: '1,000원' } }], totals: { claim_count: 1 }, skipped: [] } };

/** @effects pending_preview_reservation_keeps_execution_blocked */
test('새 미리보기 예약 중 이전 응답이 와도 완료 버튼은 잠겨 있다', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  let releaseOld!: () => void;
  let holdOld = false;
  let count = 0;
  await page.route(`**${API}/claims/completion-preview`, async route => {
    count++;
    if (holdOld) {
      holdOld = false;
      await new Promise<void>(resolve => { releaseOld = resolve; });
    }
    await route.fulfill({ json: preview });
  });
  await page.route(`**${API}/claims/bulk-status`, route => route.fulfill({ status: 500, json: { success: false } }));
  await page.goto('/admin/ecommerce/claims');
  await expect(page.locator('#claim_type_subtabs')).toBeVisible();
  await page.evaluate(async () => {
    await (window as any).G7Core.dispatch({ handler: 'sirsoft-ecommerce.openClaimCompleteModal', params: {
      claimType: 'return', targets: [{ id: 31, claim_number: 'RT-FEEDBACK' }],
      defaults: { restore_stock: true, create_refund_request: true },
      execute: { url: '/api/modules/sirsoft-ecommerce/admin/claims/bulk-status', method: 'POST', body: { type: 'return', ids: [31] } },
    } });
  });
  const submit = page.locator('#cc_022_submit_button');
  await expect(submit).toBeEnabled();
  await page.clock.install();
  await page.clock.pauseAt(new Date());
  const previousCount = count;
  holdOld = true;
  await page.evaluate(() => { void (window as any).G7Core.dispatch({ handler: 'sirsoft-ecommerce.toggleClaimCompleteStock', params: { claimId: 31, restore: false } }); });
  await page.clock.runFor(200);
  await expect.poll(() => count).toBe(previousCount + 1);
  await page.evaluate(() => { void (window as any).G7Core.dispatch({ handler: 'sirsoft-ecommerce.toggleClaimCompleteStock', params: { claimId: 31, restore: true } }); });
  const oldResponse = page.waitForResponse(r => r.url().endsWith('/completion-preview'));
  releaseOld();
  // 응답 처리는 기다리되 다음 debounce는 고정 시계로 아직 진행시키지 않는다.
  await oldResponse;
  await page.clock.runFor(16);
  await expect(submit).toBeDisabled();
  await expect(submit.locator('.spinner-sm')).toBeVisible();
  expect(await page.evaluate(() => (window as any).G7Core.state.get().claimCompleteRestore['31'])).toBe(true);
  await page.clock.runFor(200);
  await expect(submit).toBeEnabled();
  await expect(submit.locator('.spinner-sm')).toHaveCount(0);
});

/** @effects claim_pending_feedback_preserves_responsive_label */
test('모바일 상태 변경 버튼이 긴 라벨과 처리 스피너를 함께 표시한다', async ({ page, claimsManageToken }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await authenticatePage(page, claimsManageToken);
  await page.route(`**${API}/claims/EX-FEEDBACK`, route => route.fulfill({ json: { success: true, data: {
    claim: { id: 31, claim_number: 'EX-FEEDBACK', claim_type: 'exchange', path_segment: 'exchanges', status: 'requested', items: [{ id: 55, product_name: '검증 상품', quantity: 1, status: 'requested', is_splittable: false, transition_options: [{ value: 'approved', label: '교환승인' }] }] },
    claim_path_segment: 'exchanges', abilities: { can_update_status: true }, progress_steps: [], carriers: [], complete_defaults: {},
  } } }));
  await page.route(`**${API}/claims/EX-FEEDBACK/logs**`, route => route.fulfill({ json: { success: true, data: { data: [], pagination: { current_page: 1, last_page: 1, total: 0 } } } }));
  let release!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  let writes = 0;
  await page.route(`**${API}/exchanges/31/options/bulk-status`, async route => {
    writes++;
    await held;
    await route.fulfill({ json: { success: true, data: {} } });
  });
  await page.goto('/admin/ecommerce/claims/EX-FEEDBACK');
  const button = page.getByRole('button', { name: /선택 항목 일괄 적용$/ });
  await expect(button).toBeVisible();
  await page.getByRole('checkbox').first().check();
  // 선택지는 서버가 행마다 실어 준 어휘의 교집합이다 (§4-4) — 셀렉트 id 가 유형별로 갈리지 않는다.
  await page.locator('#ci_002_item_status button').first().click();
  await page.getByRole('option', { name: '교환승인', exact: true }).click();
  await button.click();

  // [적용] 은 곧바로 실행하지 않고 수량 확인 모달을 연다 (§4-6).
  const confirm = page.locator('#ciq_005_confirm');
  await expect(confirm).toBeVisible();
  await confirm.click();
  try {
    await expect.poll(() => writes).toBe(1);
    await expect(confirm).toBeDisabled();
    await expect(confirm.locator('.spinner-sm')).toBeVisible();
  } finally {
    release();
  }
  await expect(confirm.locator('.spinner-sm')).toHaveCount(0);
});
