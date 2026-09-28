/**
 * §9 실측 — 상품·수량 단위 전이 화면의 라이브 계측 (T1·T1-d·T2·T3·T5·T6·T7·T-D5)
 *
 * Chrome MCP 브라우저가 이미 점유돼 있어(프로필 락) 그 도구를 쓸 수 없다. 계획 §9-4 의
 * 전환 규칙에 따라 저장소 자신의 Playwright 로 잰다 — 여기서 찍는 값은 콘솔 로그로 나가고
 * 이력 문서의 「증거」 칸에 그대로 옮긴다.
 *
 * 이 spec 은 **실측 도구**다. 픽스처는 §9-0 절차로 미리 만든 다품목·부분수량 신청서를 쓰며,
 * 신청서를 못 찾으면 단정하지 않고 skip 사유를 찍는다.
 *
 * @effects claim_item_quantity_live_measurement
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';

/** §9-0 으로 만든 실측 픽스처 (다품목 · 부분수량 · 열린 상태) */
const FIXTURES = {
  refund: 'RF20260914-0815359912',
  return: 'RT20260914-0810358144',
  exchange: 'EX20260914-0810431104',
} as const;

/**
 * 측정값을 한 줄 JSON 으로 남긴다 — 이력 문서의 「증거」 칸에 그대로 옮기기 위해서다.
 *
 * @param axis 매트릭스 축 이름
 * @param value 측정값
 */
function record(axis: string, value: unknown): void {
  console.log(`[MEASURE] ${axis} ${JSON.stringify(value)}`);
}

/**
 * 클레임 상세를 열고 상세 응답이 도착할 때까지 기다린다.
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

  return response.status();
}

/** @effects claim_item_quantity_live_measurement */
test('T1 진입/표시 — 4유형의 섹션 수·일괄 바 존재·콘솔 오류', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);

  const errors: string[] = [];
  page.on('console', (msg: any) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });

  for (const [type, claimNumber] of Object.entries(FIXTURES)) {
    const status = await openDetail(page, claimNumber);

    const measured = await page.evaluate(() => ({
      sections: document.querySelectorAll('[data-section-id]').length,
      bulkBar: document.getElementById('claim_item_bulk_bar') !== null,
      selectPresent: document.getElementById('ci_002_item_status') !== null,
      applyButton: document.getElementById('ci_004_item_bulk_apply') !== null,
      gridRows: document.querySelectorAll('tbody tr').length,
      checkboxes: document.querySelectorAll('input[type="checkbox"]').length,
    }));

    record(`T1/${type}`, { claimNumber, status, ...measured, consoleErrors: errors.length });
    expect(status).toBe(200);
  }

  record('T1/console_errors', errors);
});

/** @effects claim_item_quantity_live_measurement */
test('T1-d 비활성 — 읽기 권한만 가진 운영자의 [적용]', async ({ page, claimsReadToken }) => {
  await authenticatePage(page, claimsReadToken);
  const status = await openDetail(page, FIXTURES.return);

  const measured = await page.evaluate(() => {
    const button = document.getElementById('ci_004_item_bulk_apply') as HTMLButtonElement | null;
    if (!button) return { present: false };
    const style = getComputedStyle(button);

    return {
      present: true,
      disabled: button.disabled,
      opacity: style.opacity,
      cursor: style.cursor,
    };
  });

  record('T1-d/read_only', { status, ...measured });
});

/** @effects claim_item_quantity_live_measurement */
test('T2·T3 단일/다중 — 체크 → 상태 선택 → 수량 확인 모달 → 적용', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  const status = await openDetail(page, FIXTURES.return);

  // **두 행 모두** 체크한다 — 확인 모달이 행마다 한 줄을 그리는지(반복 선언이 실제로 도는지)
  // 한 행만으로는 가릴 수 없다. 문맥 행 수와 렌더된 입력 수가 같아야 한다.
  const rowCheckboxes = page.locator('tbody tr input[type="checkbox"]');
  const rowCount = await rowCheckboxes.count();
  for (let index = 0; index < rowCount; index++) {
    await rowCheckboxes.nth(index).check();
  }

  const afterSelect = await page.evaluate(() => {
    const G7Core = (window as any).G7Core;
    const local = G7Core?.state?.getLocal?.() ?? {};

    return {
      selected: (local.selectedClaimItems ?? []).length,
      options: (local.itemTransitionOptions ?? []).map((o: any) => o.value),
      bulkStatus: local.itemBulkStatus ?? '',
      noCommonNotice: document.getElementById('ci_003_no_common_transition') !== null,
    };
  });
  record('T2/selection', { status, ...afterSelect });

  // 교집합에서 첫 선택지를 고른다 — 화면이 리터럴을 들지 않으므로 서버 어휘 그대로다.
  const chosen = afterSelect.options[0];
  if (!chosen) {
    record('T2/skipped', '교집합이 비어 상태를 고를 수 없다');

    return;
  }

  await page.evaluate((value: string) => {
    (window as any).G7Core?.state?.setLocal?.({ itemBulkStatus: value });
  }, chosen);

  // 상태가 로컬 상태에 실제로 반영된 뒤에 누른다 — setLocal 은 배치라 곧바로 읽히지 않는다.
  await page.waitForFunction(
    (value: string) => (window as any).G7Core?.state?.getLocal?.()?.itemBulkStatus === value,
    chosen,
    { timeout: 10_000 },
  );
  await page.locator('#ci_004_item_bulk_apply').click();

  const modalOpened = await page
    .locator('#ciq_005_confirm')
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);

  const modalState = await page.evaluate(() => {
    const context = (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext ?? null;
    const input = document.querySelector('#ciq_002_rows input[type="number"]') as HTMLInputElement | null;

    const rows = document.getElementById('ciq_002_rows');
    const description = document.getElementById('ciq_001_description');

    return {
      context: context ? { status: context.status, rows: context.rows, quantities: context.quantities } : null,
      inputMax: input?.max ?? null,
      inputValue: input?.value ?? null,
      inputDisabled: input?.disabled ?? null,
      descriptionText: description?.textContent ?? null,
      rowCount: rows?.querySelectorAll('input[type="number"]').length ?? 0,
      rowsHtml: (rows?.innerHTML ?? '').slice(0, 600),
    };
  });
  record('T2/quantity_modal', { modalOpened, ...modalState });

  // T3 — 클램핑 경계값. 0 · 최대+1 · 정상값 순으로 넣고 문맥 값을 읽는다.
  const clamped: Array<{ input: string; stored: unknown }> = [];
  for (const value of ['0', '99', '1']) {
    await page.locator('#ciq_002_rows input[type="number"]').first().fill(value);
    await page.locator('#ciq_002_rows input[type="number"]').first().dispatchEvent('change');
    const stored = await page.evaluate(() => {
      const context = (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext;

      return context ? Object.values(context.quantities)[0] : null;
    });
    clamped.push({ input: value, stored });
  }
  record('T3/clamping', clamped);
});

/** @effects claim_item_quantity_live_measurement */
test('T-D5 관계 드롭다운 — 선언이 있는 정의와 없는 정의', async ({ page, settingsToken }) => {
  await authenticatePage(page, settingsToken);
  await page.goto('/admin/ecommerce/settings?tab=notification_definitions');
  await page.waitForLoadState('networkidle');

  const measured = await page.evaluate(async () => {
    const response = await fetch('/api/admin/notification-definitions?per_page=100', {
      headers: {
        Accept: 'application/json',
        // 이 앱은 세션 쿠키가 아니라 Sanctum 베어러로 인증한다 — 헤더를 빼면 401 이다.
        Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}`,
      },
    });
    const body = await response.json();
    const rows = body?.data?.data ?? [];

    return {
      status: response.status,
      total: rows.length,
      withRelations: rows.filter((d: any) => (d.relations ?? []).length > 0).map((d: any) => ({ type: d.type, relations: d.relations })),
    };
  });

  record('T-D5/relations', measured);
});

/** @effects claim_item_quantity_live_measurement */
test('T-D3 채널별 편집기 — 채널 메타의 template_editor', async ({ page, settingsToken }) => {
  await authenticatePage(page, settingsToken);
  await page.goto('/admin/settings');
  await page.waitForLoadState('networkidle');

  const measured = await page.evaluate(async () => {
    const response = await fetch('/api/admin/notification-channels', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('auth_token') ?? ''}`,
      },
    });
    const body = await response.json();
    const channels = body?.data?.channels ?? body?.data ?? [];

    return {
      status: response.status,
      channels: (Array.isArray(channels) ? channels : []).map((c: any) => ({ id: c.id, editor: c.template_editor })),
    };
  });

  record('T-D3/channel_editors', measured);
});
