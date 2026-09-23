/**
 * §8-1 Playwright — 환불 부분 승인 왕복 · 첨부 2장 → 개별 삭제
 *
 * 이 두 축은 **여러 번의 왕복이 끝나야** 드러난다. 서버 테스트는 한 요청의 계약을 잡고
 * 구조 테스트는 노드의 모양을 잡지만, 「부분 승인 상태에서 [환불하기]가 잠겨 있고 잔여를
 * 마저 승인하면 풀린다」는 화면·서버·상태가 함께 움직여야 성립한다.
 *
 * 첨부도 같다 — 올리고 지우는 것은 공용 업로더가 하고, 그 결과가 모듈이 소유한 출처
 * 스트립과 활동 로그에 닿는지는 왕복해 봐야 안다.
 *
 * @effects claim_refund_and_attachment_flow_live_measurement
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';

/** §9-0 절차로 만든 **소모성** 신청서 — 두 축 모두 상태를 바꾼다. */
const FIXTURES = {
  refundFlow: { claim: 'RF20260914-1134139668', segment: 'refunds' },
  attachment: { claim: 'RT20260914-1134483868', segment: 'returns' },
} as const;

/** 측정값을 한 줄 JSON 으로 남긴다. */
function record(axis: string, value: unknown): void {
  console.log(`[MEASURE] ${axis} ${JSON.stringify(value)}`);
}

// 두 축 모두 여러 왕복을 돈다 — 기본 30초로는 측정이 끝나기 전에 잘린다.
test.describe.configure({ timeout: 180_000 });

/**
 * 클레임 상세를 열고 상세 응답을 기다린다.
 *
 * @param page 페이지
 * @param claimNumber 클레임 번호
 * @return 상세 API 상태코드
 */
async function openDetail(page: any, claimNumber: string): Promise<number> {
  const waitDetail = page.waitForResponse(
    (r: any) => r.url().includes(`${API}/claims/${claimNumber}`) && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto(`/admin/ecommerce/claims/${claimNumber}`);
  const response = await waitDetail;
  await page.waitForSelector('[data-section-id="claim_info"]', { timeout: 30_000 });
  await page.waitForTimeout(800);

  return response.status();
}

/**
 * 체크된 행을 그 상태로 전이한다 (수량 확인 모달까지 실제로 거친다).
 *
 * @param page 페이지
 * @param segment 신청서 경로 조각
 * @param quantities 행 인덱스별 수량 (생략하면 전량)
 * @return 요청 결과
 */
async function transitionAll(
  page: any,
  segment: string,
  claimNumber: string,
  quantities?: Record<number, string>,
): Promise<{ status: number; body: string; chosen: string; picked: number[] } | null> {
  // **아직 승인되지 않은 행만** 고른다. 전부 고르면 이미 승인된 행이 교집합을 좁혀
  // `approved` 가 빠지고, 그러면 헬퍼가 엉뚱한 상태(`on_hold`)로 떨어뜨린다(1차 실행).
  const pending: number[] = await page.evaluate(
    async ([base, number]: any) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${number}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());

      return (detail?.data?.claim?.items ?? [])
        .map((item: any, index: number) => ({ index, status: item.status ?? item.option_status }))
        .filter((row: any) => row.status === 'requested')
        .map((row: any) => row.index);
    },
    [API, claimNumber],
  );
  if (pending.length === 0) return null;

  const rowCheckboxes = page.locator('tbody tr input[type="checkbox"]');
  for (const index of pending) {
    await rowCheckboxes.nth(index).check();
  }

  const options: string[] = await page.evaluate(
    () => ((window as any).G7Core?.state?.getLocal?.()?.itemTransitionOptions ?? []).map((o: any) => o.value),
  );
  const approved = options.find((value) => value === 'approved');
  if (!approved) return null;

  await page.evaluate((value: string) => {
    (window as any).G7Core?.state?.setLocal?.({ itemBulkStatus: value });
  }, approved);
  await page.waitForFunction(
    (value: string) => (window as any).G7Core?.state?.getLocal?.()?.itemBulkStatus === value,
    approved,
    { timeout: 10_000 },
  );
  await page.locator('#ci_004_item_bulk_apply').click();
  await page.locator('#ciq_005_confirm').waitFor({ state: 'visible', timeout: 10_000 });

  if (quantities) {
    const inputs = page.locator('#ciq_002_rows input[type="number"]');
    for (const [index, value] of Object.entries(quantities)) {
      await inputs.nth(Number(index)).fill(value);
      await inputs.nth(Number(index)).dispatchEvent('change');
    }
  }

  const waitPatch = page
    .waitForResponse(
      (r: any) => r.url().includes(`/${segment}/`) && r.url().includes('/options/bulk-status'),
      { timeout: 20_000 },
    )
    .catch(() => null);
  await page.locator('#ciq_005_confirm').click();
  const response = await waitPatch;
  await page.waitForTimeout(2_000);
  if (!response) return null;

  return { status: response.status(), body: (await response.text()).slice(0, 260), chosen: approved, picked: pending };
}

/**
 * [환불하기] 버튼의 상태와 그때의 헤더 상태를 함께 읽는다.
 *
 * 헤더 상태는 화면 state 경로를 짐작하지 않고 **상세 응답**에서 읽는다 — 경로를 잘못 짚으면
 * 빈 문자열이 나오고, 그러면 "버튼이 왜 잠겨 있는지" 를 말할 수 없다.
 *
 * @param page 페이지
 * @param claimNumber 클레임 번호
 * @return 버튼 상태와 헤더 상태
 */
async function refundButtonState(
  page: any,
  claimNumber: string,
): Promise<{ present: boolean; disabled: boolean | null; headerStatus: string; abilities: unknown }> {
  return page.evaluate(
    async ([base, number]: any) => {
      const button = document.getElementById('cre_014_execute_button') as HTMLButtonElement | null;
      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${number}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());

      return {
        present: button !== null,
        disabled: button?.disabled ?? null,
        headerStatus: detail?.data?.claim?.status ?? '',
        abilities: detail?.data?.abilities ?? null,
      };
    },
    [API, claimNumber],
  );
}

/** @effects claim_refund_and_attachment_flow_live_measurement */
test('환불 — 부분 승인 중에는 [환불하기]가 잠기고, 잔여를 마저 승인하면 풀린다', async ({
  page,
  claimsManageToken,
}) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.refundFlow;

  const status = await openDetail(page, fixture.claim);
  const atRequested = await refundButtonState(page, fixture.claim);

  // ① 한 행만 수량을 줄여 부분 승인 — 잔여가 '신청' 으로 남는다.
  const first = await transitionAll(page, fixture.segment, fixture.claim, { 0: '1' });
  await openDetail(page, fixture.claim);
  const afterPartial = await refundButtonState(page, fixture.claim);

  // ② 남은 것을 마저 승인 — 신청 잔여 0 → 헤더가 승인에 도달한다.
  const second = await transitionAll(page, fixture.segment, fixture.claim);
  await openDetail(page, fixture.claim);
  const afterAll = await refundButtonState(page, fixture.claim);

  record('환불/실행버튼', {
    status,
    atRequested,
    first,
    afterPartial,
    second,
    afterAll,
  });

  expect(status).toBe(200);
  // 접수 상태에서는 눌리면 안 된다 — 판단이 끝나기 전에 돈이 나간다(운영 결정 A4).
  expect(atRequested.disabled).toBe(true);
  // 부분 승인 중에도 잠겨 있어야 한다 — '신청' 잔여가 남아 있다.
  expect(afterPartial.disabled).toBe(true);
  // 전건 판단이 끝나면 헤더가 승인에 도달하고 버튼이 풀린다.
  expect(afterAll.headerStatus).toBe('approved');
  expect(afterAll.disabled).toBe(false);
});

/** @effects claim_refund_and_attachment_flow_live_measurement */
test('첨부 — 2장을 올리면 격자·출처 스트립이 2행이 되고, 1장을 지우면 1행이 된다', async ({
  page,
  claimsManageToken,
}) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.attachment;

  const status = await openDetail(page, fixture.claim);

  /** 격자·스트립의 행 수를 함께 읽는다. */
  const counts = () =>
    page.evaluate(async ([base, claimNumber]: any) => {
      const strip = document.getElementById('cr_007_images_origin_list');
      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${claimNumber}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());

      return {
        stripRows: strip ? strip.querySelectorAll('[id^="cr_007_image_origin_"]').length : 0,
        serverImages: (detail?.data?.claim?.images ?? []).length,
        uploaderTypes: (detail?.data?.claim?.images ?? []).map((i: any) => i.uploader_type),
      };
    }, [API, fixture.claim]);

  const before = await counts();

  // 파일 입력은 업로더 안에 숨어 있다 — 보이지 않아도 `setInputFiles` 는 붙는다.
  const fileInput = page.locator('#cr_006_images_uploader input[type="file"]').first();
  await fileInput.waitFor({ state: 'attached', timeout: 15_000 });

  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
  );
  await fileInput.setInputFiles([
    { name: 'evidence-a.png', mimeType: 'image/png', buffer: png },
    { name: 'evidence-b.png', mimeType: 'image/png', buffer: png },
  ]);
  await page.waitForTimeout(6_000);

  await openDetail(page, fixture.claim);
  const afterUpload = await counts();

  // 개별 삭제 — 운영자가 한 장만 지울 수 있어야 한다(C2).
  const deleted = await page.evaluate(
    async ([base, segment, claimNumber]: any) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${claimNumber}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());
      const data = detail?.data ?? {};
      const claimId = data.claim?.id ?? null;
      const image = (data.claim?.images ?? [])[0];
      if (!image) return { skipped: '지울 첨부가 없다' };

      const response = await fetch(`${base}/${segment}/${claimId}/images/${image.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      return { claimId, imageId: image.id, status: response.status, body: (await response.text()).slice(0, 200) };
    },
    [API, fixture.segment, fixture.claim],
  );

  await openDetail(page, fixture.claim);
  const afterDelete = await counts();

  record('첨부/왕복', { status, before, afterUpload, deleted, afterDelete });

  expect(status).toBe(200);
  expect(afterUpload.serverImages).toBe(before.serverImages + 2);
  expect(afterUpload.stripRows).toBe(afterUpload.serverImages);
  // 운영자가 상세에서 올린 것은 운영자 첨부로 기록된다(C3) — 분쟁 판정의 근거다.
  expect(afterUpload.uploaderTypes).toContain('admin');
  expect(afterDelete.serverImages).toBe(afterUpload.serverImages - 1);
  expect(afterDelete.stripRows).toBe(afterDelete.serverImages);
});
