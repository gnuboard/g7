/**
 * §9 실측 — 관리자 클레임 첨부의 상한 안내·업로드 일시 문구 (§6-3 · §6-4)
 *
 * 계획 §6-3 은 상한 안내를 **설정에서 읽어** 그리라고 했다. 출처는 옳게 붙었지만 그 값이
 * 문장에 들어가는지는 화면에서만 드러난다 — 치환이 실패하면 예외도 로그도 없이 자리표시자가
 * 글자 그대로 남는다.
 *
 * @effects claim_attachment_hint_live_measurement
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';

/** §9-0 절차로 만든 실측 신청서 (첨부 축은 읽기만 하므로 소모되지 않는다). */
const CLAIM = 'RT20260914-1012484644';

/** 측정값을 한 줄 JSON 으로 남긴다. */
function record(axis: string, value: unknown): void {
  console.log(`[MEASURE] ${axis} ${JSON.stringify(value)}`);
}

/** @effects claim_attachment_hint_live_measurement */
test('첨부 상한 안내가 설정값으로 치환되어 보인다', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);

  const waitDetail = page.waitForResponse(
    (r: any) => r.url().includes(`${API}/claims/${CLAIM}`) && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto(`/admin/ecommerce/claims/${CLAIM}`);
  const response = await waitDetail;
  await page.waitForSelector('[data-section-id="claim_info"]', { timeout: 30_000 });
  await page.waitForTimeout(1_500);

  const measured = await page.evaluate(
    async ([base, claimNumber]: any) => {
      const hint = document.getElementById('cr_005b_images_hint');
      const text = (hint?.textContent ?? '').trim();

      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${claimNumber}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());

      return {
        present: hint !== null,
        text,
        // 치환이 실패하면 자리표시자가 글자 그대로 남는다 — 그것이 이 축의 판정이다.
        leftoverTokens: text.match(/:[a-z_]+/g) ?? [],
        settings: detail?.data?.attachment_settings ?? null,
      };
    },
    [API, CLAIM],
  );

  record('첨부/상한안내', { status: response.status(), ...measured });

  expect(measured.present).toBe(true);
  // 설정값이 문장에 들어가야 한다. 자리표시자가 남아 있으면 운영자는 숫자 대신 `:max` 를 본다.
  expect(measured.leftoverTokens).toEqual([]);
  expect(measured.text).toContain(String(measured.settings?.max_images ?? '∅'));
});
