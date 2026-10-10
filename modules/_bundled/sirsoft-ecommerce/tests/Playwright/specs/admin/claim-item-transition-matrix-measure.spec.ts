/**
 * §9 실측 잔여 축 — 상품·수량 단위 전이의 T4~T10 과 회귀(T-R)
 *
 * 앞선 세션의 계측 spec(`claim-item-quantity-measure.spec.ts`)은 T1·T1-d·T2·T3 까지만 덮었다.
 * 그 축들은 화면을 **읽기만** 해서 한 신청서로 반복해도 됐지만, 여기부터는 전이를 실제로
 * **실행**한다 — 부분 승인은 되돌릴 수 없으므로 축마다 자기 신청서를 쓴다(§9-0 절차로 미리
 * 만든 소모성 픽스처).
 *
 * Chrome MCP·Playwright MCP 브라우저가 모두 프로필 점유 상태라(계획 §9-4: 프로세스를 죽이지
 * 않는다) 저장소 자신의 Playwright 로 잰다.
 *
 * 이 파일은 **계측 도구**다. 통과/실패를 단정하기보다 계획 §9-2 가 요구한 측정값을
 * `[MEASURE]` 줄로 남기고, 이력 문서의 「증거」 칸이 그 값을 그대로 인용한다.
 *
 * @effects claim_item_transition_matrix_live_measurement
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';

// 축 하나가 체크→상태→모달→실행→새로고침까지 돌므로 기본 30초로는 측정이 끝나기 전에 잘린다.
// (1차 실행에서 T4·T5·T-R 이 실제로 그렇게 잘렸다.)
test.describe.configure({ timeout: 180_000 });

/**
 * §9-0 절차로 만든 소모성 실측 신청서 (축마다 하나 — 앞 축이 소모해도 뒤 축이 성립한다).
 *
 * 만든 뒤 되돌리지 않는다. 번호와 최종 상태는 이력 문서에 남긴다.
 */
const FIXTURES = {
  T4: { claim: 'RT20260914-1010557657', segment: 'returns', type: 'return', items: [133, 134] },
  T5: { claim: 'RT20260914-1011231024', segment: 'returns', type: 'return', items: [135, 136] },
  T6: { claim: 'RT20260914-1011437164', segment: 'returns', type: 'return', items: [137, 138] },
  T7: { claim: 'EX20260914-1012045718', segment: 'exchanges', type: 'exchange', items: [134, 135] },
  T8: { claim: 'EX20260914-1012244432', segment: 'exchanges', type: 'exchange', items: [136, 137] },
  T9: { claim: 'RT20260914-1012484644', segment: 'returns', type: 'return', items: [139, 140] },
  T10: { claim: 'RF20260914-1013077598', segment: 'refunds', type: 'refund', items: [79, 80] },
  T10_other: { claim: 'RF20260914-1013266461', segment: 'refunds', type: 'refund', items: [81, 82] },
  TR_refund: { claim: 'RF20260914-1013451300', segment: 'refunds', type: 'refund', items: [83, 84] },
  TR_exchange: { claim: 'EX20260914-1014032133', segment: 'exchanges', type: 'exchange', items: [138, 139] },
} as const;

/** 측정값을 한 줄 JSON 으로 남긴다 — 이력 문서의 「증거」 칸에 그대로 옮기기 위해서다. */
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

/**
 * 신청 상품 행을 모두 체크하고 서버가 준 전이 어휘의 교집합을 읽는다.
 *
 * @param page 페이지
 * @return 선택 수와 전이 선택지
 */
async function selectAllRows(page: any): Promise<{ selected: number; options: string[] }> {
  const rowCheckboxes = page.locator('tbody tr input[type="checkbox"]');
  const rowCount = await rowCheckboxes.count();
  for (let index = 0; index < rowCount; index += 1) {
    await rowCheckboxes.nth(index).check();
  }

  return page.evaluate(() => {
    const local = (window as any).G7Core?.state?.getLocal?.() ?? {};

    return {
      selected: (local.selectedClaimItems ?? []).length,
      options: (local.itemTransitionOptions ?? []).map((o: any) => o.value),
    };
  });
}

/**
 * 일괄 바에서 상태를 고르고 [적용] 을 눌러 수량 확인 모달을 띄운다.
 *
 * `setLocal` 은 배치라 곧바로 읽히지 않는다 — 상태가 반영된 것을 확인한 뒤에 누른다.
 *
 * @param page 페이지
 * @param status 고를 상태 값
 * @return 모달이 떴는지
 */
async function openQuantityModal(page: any, status: string): Promise<boolean> {
  await page.evaluate((value: string) => {
    (window as any).G7Core?.state?.setLocal?.({ itemBulkStatus: value });
  }, status);
  await page.waitForFunction(
    (value: string) => (window as any).G7Core?.state?.getLocal?.()?.itemBulkStatus === value,
    status,
    { timeout: 10_000 },
  );
  await page.locator('#ci_004_item_bulk_apply').click();

  return page
    .locator('#ciq_005_confirm')
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
}

/**
 * 확인 모달의 [적용] 을 눌러 전이를 **실제로 실행**하고 응답을 잡는다.
 *
 * @param page 페이지
 * @param segment 신청서 경로 조각
 * @return 요청 결과 (상태코드·본문 앞머리)
 */
async function confirmAndCapture(
  page: any,
  segment: string,
): Promise<{ status: number; requestBody: string; body: string } | null> {
  const waitPatch = page
    .waitForResponse(
      (r: any) => r.url().includes(`/${segment}/`) && r.url().includes('/options/bulk-status'),
      { timeout: 20_000 },
    )
    .catch(() => null);

  await page.locator('#ciq_005_confirm').click();
  const response = await waitPatch;
  if (!response) return null;

  let body = '';
  try {
    body = (await response.text()).slice(0, 400);
  } catch {
    body = '(본문 읽기 실패)';
  }

  // 요청 본문이 있어야 "수량이 실렸는가" 를 말할 수 있다 — 응답만 보면 분할이 왜 없었는지
  // 화면 탓인지 서버 탓인지 가릴 수 없다(1차 실행에서 `split:[]` 의 원인을 가릴 수 없었다).
  let requestBody = '';
  try {
    requestBody = (response.request().postData() ?? '').slice(0, 300);
  } catch {
    requestBody = '(요청 본문 읽기 실패)';
  }

  return { status: response.status(), requestBody, body };
}

/**
 * 신청서의 내부 ID 를 해석한다 (본문에 실릴 경로 조각).
 *
 * 1차 실행에서 환불 축만 `null` 이 나와 `/refunds/null/...` 로 404 를 받았다 — 응답 모양을
 * 한 경로로만 짚었기 때문이다. 여기서는 **화면이 실제로 쓰는 값**(상세 데이터소스)을 먼저
 * 보고, 없으면 API 를 다시 읽는다. 무엇을 봤는지도 함께 남겨 증거가 스스로 설명하게 한다.
 *
 * @param page 페이지
 * @param claimNumber 클레임 번호
 * @return 내부 ID 와 그 출처
 */
async function resolveClaimId(page: any, claimNumber: string): Promise<{ id: number | null; from: string; keys: string[] }> {
  return page.evaluate(
    async ([base, number]: any) => {
      const state = (window as any).G7Core?.state?.get?.() ?? {};
      const fromState =
        state?.claim?.data?.id ?? state?.claimDetail?.data?.id ?? state?.claim?.id ?? null;
      if (typeof fromState === 'number') return { id: fromState, from: 'state', keys: Object.keys(state).slice(0, 12) };

      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${number}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());
      const data = detail?.data ?? {};

      return {
        id: data?.id ?? data?.claim?.id ?? null,
        from: 'api',
        keys: Object.keys(data).slice(0, 12),
      };
    },
    [API, claimNumber],
  );
}

/** 화면에 떠 있는 안내(토스트·인라인)를 모은다 — 같은 문구가 두 번 뜨는지까지 본다. */
async function collectAlerts(page: any): Promise<string[]> {
  return page.evaluate(() =>
    Array.from(document.querySelectorAll('[role="alert"], .toast, [class*="toast"]'))
      .map((el) => (el.textContent ?? '').trim())
      .filter((text) => text.length > 0)
      .slice(0, 8)
  );
}

/** @effects claim_item_transition_matrix_live_measurement */
test('T4 제출/피드백 — 200 · 422(어휘 밖·타 신청서 행) · network error 의 화면 반응', async ({
  page,
  claimsManageToken,
}) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.T4;
  const status = await openDetail(page, fixture.claim);

  const alerts: string[] = [];
  await page.evaluate(() => {
    (window as any).__alerts = [];
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((m) => {
        m.addedNodes.forEach((node: any) => {
          if (node.nodeType !== 1) return;
          const text = (node.textContent ?? '').trim();
          if (text) (window as any).__alerts.push(text.slice(0, 160));
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });

  // ── 200 축: 화면이 실제로 거치는 경로 그대로 (체크 → 상태 → 모달 → 확인)
  const selection = await selectAllRows(page);
  const chosen = selection.options[0];
  record('T4/selection', { status, ...selection });

  if (!chosen) {
    record('T4/blocked', '교집합이 비어 200 축을 실행할 수 없다');

    return;
  }

  const modalOpened = await openQuantityModal(page, chosen);
  const ok = await confirmAndCapture(page, fixture.segment);
  record('T4/200', { modalOpened, chosen, response: ok });
  record('T4/200_alerts', await page.evaluate(() => (window as any).__alerts ?? []));

  // ── 422 축: 서버 어휘 밖 상태 / 다른 신청서의 행 id
  //    화면 조작으로는 만들 수 없는 입력이다(화면이 어휘를 서버에서 받으므로). API 를
  //    **페이지 안에서** 같은 세션 토큰으로 호출해 서버 판정과 메시지 키를 그대로 잡는다.
  const resolved = await resolveClaimId(page, fixture.claim);
  const apiProbes = await page.evaluate(
    async ([base, segment, claimId, otherItemIds]: any) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const url = `${base}/${segment}/${claimId}/options/bulk-status`;

      /**
       * 같은 엔드포인트에 서로 다른 본문을 넣어 서버 판정을 받는다.
       *
       * @param body 요청 본문
       * @return 상태코드와 본문 앞머리
       */
      const call = async (body: any) => {
        const response = await fetch(url, {
          method: 'PATCH',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify(body),
        });

        return { status: response.status, body: (await response.text()).slice(0, 300) };
      };

      return {
        claimId,
        unknownStatus: await call({ items: [{ id: otherItemIds[0] }], status: 'not_a_status' }),
        foreignRow: await call({ items: otherItemIds.map((id: number) => ({ id })), status: 'approved' }),
        emptyItems: await call({ items: [], status: 'approved' }),
        scalarItems: await call({ items: otherItemIds, status: 'approved' }),
      };
    },
    [API, fixture.segment, resolved.id, FIXTURES.T10_other.items],
  );
  record('T4/422_probes', { resolved, ...apiProbes });

  // ── network error 축: **대상 URL 만** 끊고 화면 반응을 본다.
  //    (`emulate networkConditions:'Offline'` 은 §9-4 금지 — 페이지가 chrome-error 로 밀린다)
  //    페이지 안에서 `window.fetch` 를 감싸는 방식은 이 앱에 통하지 않는다 — API 왕복이
  //    axios(XHR)라 fetch 스텁을 그냥 지나쳐 **성공 경로가 한 번 더 실행됐다**(1차 실행에서
  //    실제로 픽스처가 한 단계 더 소모됐다). 라우팅 레벨에서 끊는다.
  let abortedCount = 0;
  await page.route('**/options/bulk-status', async (route: any) => {
    abortedCount += 1;
    await route.abort('failed');
  });

  await page.evaluate(() => {
    (window as any).__alerts = [];
  });

  const reselect = await selectAllRows(page);
  if (reselect.options[0]) {
    await openQuantityModal(page, reselect.options[0]);
    await page.locator('#ciq_005_confirm').click();
    await page.waitForTimeout(2_500);
  }
  const networkAlerts: string[] = await page.evaluate(() => (window as any).__alerts ?? []);
  const shortAlerts = networkAlerts.filter((t: string) => t.length < 160);
  record('T4/network_error', {
    reselect,
    abortedCount,
    alerts: shortAlerts.slice(0, 6),
    // onError 의 `suppress` 계약이 지켜지면 같은 사유 문구가 두 번 뜨지 않는다.
    duplicateAlerts: shortAlerts.filter((t: string, i: number, arr: string[]) => arr.indexOf(t) !== i),
  });

  // 라우팅 차단 해제 — 심은 계측은 측정 직후 되돌린다(§9-4).
  await page.unroute('**/options/bulk-status');
  record('T4/stub_removed', { unrouted: true, abortedCount });

  expect(status).toBe(200);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T5 사후 상태 — 선택 비워짐 · 헤더 상태·금액 갱신 · 재조회', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.T5;
  const status = await openDetail(page, fixture.claim);

  const refetches: string[] = [];
  page.on('request', (request: any) => {
    if (request.url().includes(`${API}/claims/`) && request.method() === 'GET') refetches.push(request.url());
  });

  const before = await page.evaluate(() => ({
    headerText: (document.querySelector('[data-section-id="claim_info"]')?.textContent ?? '').replace(/\s+/g, ' ').slice(0, 200),
    rowCount: document.querySelectorAll('tbody tr').length,
  }));

  const selection = await selectAllRows(page);
  const chosen = selection.options[0];
  if (!chosen) {
    record('T5/blocked', '교집합이 비어 전이를 실행할 수 없다');

    return;
  }

  // 한 행만 부분 승인한다 — 분할이 실제로 일어나는 변종이라야 사후 상태가 의미를 갖는다.
  const modalOpened = await openQuantityModal(page, chosen);
  await page.locator('#ciq_002_rows input[type="number"]').first().fill('1');
  await page.locator('#ciq_002_rows input[type="number"]').first().dispatchEvent('change');
  const response = await confirmAndCapture(page, fixture.segment);
  await page.waitForTimeout(2_000);

  const after = await page.evaluate(() => {
    const local = (window as any).G7Core?.state?.getLocal?.() ?? {};

    return {
      selectedAfter: (local.selectedClaimItems ?? []).length,
      bulkStatusAfter: local.itemBulkStatus ?? '',
      transitionOptionsAfter: (local.itemTransitionOptions ?? []).length,
      quantityContext: (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext ?? null,
      headerText: (document.querySelector('[data-section-id="claim_info"]')?.textContent ?? '').replace(/\s+/g, ' ').slice(0, 200),
      rowCount: document.querySelectorAll('tbody tr').length,
      modalVisible: document.querySelector('#ciq_005_confirm') !== null,
    };
  });

  record('T5/post_state', {
    status,
    modalOpened,
    chosen,
    response,
    before,
    after,
    refetchCount: refetches.length,
  });

  expect(status).toBe(200);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T6 영속성 — 부분 승인 뒤 새로고침해도 분할 행·상태·금액이 그대로', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.T6;
  const status = await openDetail(page, fixture.claim);

  const selection = await selectAllRows(page);
  const chosen = selection.options[0];
  if (!chosen) {
    record('T6/blocked', '교집합이 비어 전이를 실행할 수 없다');

    return;
  }

  await openQuantityModal(page, chosen);
  await page.locator('#ciq_002_rows input[type="number"]').first().fill('1');
  await page.locator('#ciq_002_rows input[type="number"]').first().dispatchEvent('change');
  const response = await confirmAndCapture(page, fixture.segment);
  await page.waitForTimeout(2_000);

  /** 화면이 그린 신청 상품 행을 상태·수량 문자열로 읽는다. */
  const readRows = () =>
    page.evaluate(() =>
      Array.from(document.querySelectorAll('tbody tr')).map((tr) =>
        (tr.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 120)
      )
    );

  const beforeReload = await readRows();
  await page.reload();
  await page.waitForSelector('[data-section-id="claim_info"]', { timeout: 30_000 });
  await page.waitForTimeout(1_500);
  const afterReload = await readRows();

  // 서버 쪽 진실도 같은 자리에서 읽는다 — 화면만 보면 "그리기가 같다" 까지만 말할 수 있다.
  const serverRows = await page.evaluate(
    async ([base, claimNumber]: any) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const detail = await fetch(`${base}/claims/${claimNumber}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      }).then((r) => r.json());
      const data = detail?.data ?? {};
      // 신청 상품은 `data.claim.items` 다 — `data.items` 로 짚으면 빈 배열만 남아
      // "서버 진실을 확인했다" 고 말할 수 없다(1차 실행에서 실제로 비었다).
      const claim = data.claim ?? {};
      const items = claim.items ?? data.items ?? [];

      return {
        dataKeys: Object.keys(data).slice(0, 16),
        claimStatus: claim.status ?? null,
        amounts: {
          refund_amount: claim.refund_amount ?? data.refund_amount ?? null,
          settlement: data.settlement?.refund_amount ?? null,
        },
        rows: items.map((i: any) => ({
          id: i.id,
          status: i.status ?? i.option_status ?? null,
          statusLabel: i.status_label ?? null,
          quantity: i.quantity ?? null,
        })),
      };
    },
    [API, fixture.claim],
  );

  record('T6/persistence', {
    status,
    chosen,
    response,
    rowCountBefore: beforeReload.length,
    rowCountAfter: afterReload.length,
    identical: JSON.stringify(beforeReload) === JSON.stringify(afterReload),
    serverRows,
  });

  expect(status).toBe(200);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T7 컨텍스트 전환 — 상세 → 목록 → 다른 신청서 → 복귀 · 탭 왕복', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.T7;
  const status = await openDetail(page, fixture.claim);

  // 선택을 남긴 채 떠난다 — 복귀했을 때 그 선택이 되살아나면 "체크하지 않은 행을 처리" 가 된다.
  const selection = await selectAllRows(page);

  await page.goto('/admin/ecommerce/claims');
  await page.waitForLoadState('networkidle');
  await openDetail(page, FIXTURES.T8.claim);
  const otherClaim = await page.evaluate(() => {
    const local = (window as any).G7Core?.state?.getLocal?.() ?? {};

    return {
      selected: (local.selectedClaimItems ?? []).length,
      bulkStatus: local.itemBulkStatus ?? '',
      quantityContext: (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext ?? null,
    };
  });

  await openDetail(page, fixture.claim);
  const back = await page.evaluate(() => {
    const local = (window as any).G7Core?.state?.getLocal?.() ?? {};

    return {
      selected: (local.selectedClaimItems ?? []).length,
      bulkStatus: local.itemBulkStatus ?? '',
      transitionOptions: (local.itemTransitionOptions ?? []).length,
      quantityContext: (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext ?? null,
      residualLocalKeys: Object.keys(local).filter((k) => k.toLowerCase().includes('claimitem')),
    };
  });

  // 탭 왕복 — 탭마다 섹션이 살아 있는지, 돌아왔을 때 선택이 비어 있는지.
  const tabs: Array<{ tab: string; sections: number; rows: number }> = [];
  for (const tab of ['items', 'payment', 'refund', 'activity']) {
    await page.goto(`/admin/ecommerce/claims/${fixture.claim}?tab=${tab}`);
    await page.waitForTimeout(1_200);
    tabs.push(
      await page.evaluate((name: string) => ({
        tab: name,
        sections: document.querySelectorAll('[data-section-id]').length,
        rows: document.querySelectorAll('tbody tr').length,
      }), tab)
    );
  }

  record('T7/context_switch', { status, selection, otherClaim, back, tabs });
  expect(status).toBe(200);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T8 변종 패리티 — 3유형 × 라이트/다크 × 데스크탑/모바일 × ko/en', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);

  const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ];
  const themes = ['light', 'dark'];
  const locales = ['ko', 'en'];
  const claims = [FIXTURES.T8, FIXTURES.TR_refund, FIXTURES.T9];

  const rows: unknown[] = [];
  for (const claim of claims) {
    for (const viewport of viewports) {
      for (const theme of themes) {
        for (const locale of locales) {
          await page.setViewportSize({ width: viewport.width, height: viewport.height });

          // 먼저 그 화면을 열어 origin 을 만든 뒤 값을 세운다 — about:blank 에서 건드리면
          // `SecurityError: Access is denied for this document` 로 막힌다.
          const detailStatus = await openDetail(page, claim.claim);
          await page.evaluate(
            ([t, l]: string[]) => {
              // 테마 키는 `g7_color_scheme` 이다 — `g7_theme` 에 쓰면 아무도 읽지 않아
              // 다크 축이 라이트로 측정된다.
              localStorage.setItem('g7_color_scheme', t);
              localStorage.setItem('g7_locale', l);
            },
            [theme, locale],
          );

          // 같은 주소를 한 번 더 `goto` 하면 SPA 가 상세를 다시 부르지 않아 응답 대기가
          // 그대로 타임아웃된다 — 값을 세운 뒤에는 **새로고침**으로 다시 그린다.
          await page.reload({ waitUntil: 'domcontentloaded' });
          await page.waitForSelector('[data-section-id="claim_info"]', { timeout: 30_000 });
          await page.waitForTimeout(1_200);

          const measured = await page.evaluate(() => {
            const bar = document.getElementById('claim_item_bulk_bar');
            const apply = document.getElementById('ci_004_item_bulk_apply');
            const body = document.body;

            return {
              sections: document.querySelectorAll('[data-section-id]').length,
              bulkBar: bar !== null,
              applyLabel: (apply?.textContent ?? '').trim().slice(0, 40),
              htmlTheme: document.documentElement.classList.contains('dark')
                ? 'dark'
                : (document.documentElement.getAttribute('data-theme') ?? 'light'),
              // 번역 키가 그대로 노출되면 `$t:` 나 네임스페이스 점표기가 화면에 남는다.
              rawKeyLeak: (body.textContent ?? '').match(/sirsoft-ecommerce\.[a-z_.]+/g)?.slice(0, 3) ?? [],
              horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
            };
          });

          rows.push({ claim: claim.claim, type: claim.type, viewport: viewport.name, theme, locale, detailStatus, ...measured });
        }
      }
    }
  }

  record('T8/parity', rows);
  // 로케일·테마는 다음 축에 새지 않도록 되돌린다.
  await page.evaluate(() => {
    localStorage.setItem('g7_color_scheme', 'light');
    localStorage.setItem('g7_locale', 'ko');
  });
  expect(rows.length).toBe(claims.length * viewports.length * themes.length * locales.length);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T9 권한 3종 — read 전용 · 전이 보유 · 환불실행 보유', async ({
  page,
  claimsReadToken,
  claimsManageToken,
  claimsScopedManageToken,
}) => {
  const fixture = FIXTURES.T9;
  const rows: unknown[] = [];

  for (const [label, token] of [
    ['claims.read', claimsReadToken],
    ['claims.update-status', claimsManageToken],
    ['claims.scoped-manage', claimsScopedManageToken],
  ] as Array<[string, string]>) {
    await authenticatePage(page, token);
    const status = await openDetail(page, fixture.claim);

    // 행을 고르지 않으면 [적용] 은 권한과 무관하게 비활성이다 — 그 상태로 읽으면 세 토큰이
    // 모두 같은 값을 내놓아 권한 축을 재지 못한다(1차 실행에서 실제로 그랬다).
    const selection = await selectAllRows(page).catch(() => ({ selected: -1, options: [] }));

    const measured = await page.evaluate(
      async ([base, claimNumber]: any) => {
        const apply = document.getElementById('ci_004_item_bulk_apply') as HTMLButtonElement | null;
        const style = apply ? getComputedStyle(apply) : null;

        // `abilities` 는 상세 응답 봉투의 최상위 키다 — 화면 state 경로를 추측하면 null 만
        // 남아 권한 축의 서버 쪽 근거가 비어 버린다(1차 실행에서 실제로 그랬다).
        const token = localStorage.getItem('auth_token') ?? '';
        const detail = await fetch(`${base}/claims/${claimNumber}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        }).then((r) => r.json());

        return {
          bulkBar: document.getElementById('claim_item_bulk_bar') !== null,
          selectPresent: document.getElementById('ci_002_item_status') !== null,
          applyPresent: apply !== null,
          applyDisabled: apply?.disabled ?? null,
          applyOpacity: style?.opacity ?? null,
          applyCursor: style?.cursor ?? null,
          abilities: detail?.data?.abilities ?? null,
        };
      },
      [API, fixture.claim],
    );

    // 게이트는 GET/unauth 축으로만 본다(§9-4: 게이트 검증에 DELETE/POST 실호출 금지).
    const probe = await page.evaluate(
      async ([base, claimNumber]: any) => {
        const t = localStorage.getItem('auth_token') ?? '';
        const response = await fetch(`${base}/claims/${claimNumber}`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${t}` },
        });

        return response.status;
      },
      [API, fixture.claim],
    );

    rows.push({ token: label, status, detailProbe: probe, selection, ...measured });
  }

  record('T9/permissions', rows);
  expect(rows.length).toBe(3);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T10 에지 — 수량 0·음수·초과 · 타 신청서 행 · 더블클릭 중복 요청', async ({ page, claimsManageToken }) => {
  await authenticatePage(page, claimsManageToken);
  const fixture = FIXTURES.T10;
  const status = await openDetail(page, fixture.claim);

  const selection = await selectAllRows(page);
  const chosen = selection.options[0];
  if (!chosen) {
    record('T10/blocked', '교집합이 비어 에지 축을 실행할 수 없다');

    return;
  }

  const modalOpened = await openQuantityModal(page, chosen);

  // ── 수량 경계: 0 · 음수 · 최대+1 · 정상값
  const clamped: Array<{ input: string; stored: unknown }> = [];
  for (const value of ['0', '-3', '99', '2']) {
    await page.locator('#ciq_002_rows input[type="number"]').first().fill(value);
    await page.locator('#ciq_002_rows input[type="number"]').first().dispatchEvent('change');
    clamped.push({
      input: value,
      stored: await page.evaluate(() => {
        const context = (window as any).G7Core?.state?.get?.()?.claimItemQuantityContext;

        return context ? Object.values(context.quantities)[0] : null;
      }),
    });
  }
  record('T10/clamping', { status, modalOpened, chosen, clamped });

  // ── 더블클릭: 같은 [적용] 을 연달아 두 번. 중복 요청이 나가는지 요청 수로 본다.
  const patchUrls: string[] = [];
  page.on('request', (request: any) => {
    if (request.url().includes('/options/bulk-status')) patchUrls.push(request.method() + ' ' + request.url());
  });

  await page.locator('#ciq_005_confirm').dblclick();
  await page.waitForTimeout(3_000);
  record('T10/double_click', { requestCount: patchUrls.length, requests: patchUrls.slice(0, 4) });

  // ── 타 신청서의 행 id 주입은 화면으로 만들 수 없다 — 서버 판정을 같은 세션으로 확인한다.
  const resolvedT10 = await resolveClaimId(page, fixture.claim);
  const foreign = await page.evaluate(
    async ([base, segment, claimId, otherIds]: any) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const response = await fetch(`${base}/${segment}/${claimId}/options/bulk-status`, {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ items: otherIds.map((id: number) => ({ id })), status: 'approved' }),
      });

      return { claimId, status: response.status, body: (await response.text()).slice(0, 300) };
    },
    [API, fixture.segment, resolvedT10.id, FIXTURES.T10_other.items],
  );
  record('T10/foreign_row', { resolved: resolvedT10, ...foreign });

  expect(status).toBe(200);
});

/** @effects claim_item_transition_matrix_live_measurement */
test('T-R 회귀 — 바뀐 공유 지점을 쓰는 화면 형태별 왕복', async ({ page, claimsManageToken, customerToken }) => {
  const rows: unknown[] = [];

  // ① 신청서 단위 전이 바(4유형) · ② 목록 일괄 전이 · ③ 완료 처리 모달 진입점 · ④ 환불 실행
  await authenticatePage(page, claimsManageToken);

  for (const [shape, url] of [
    ['목록/전체', '/admin/ecommerce/claims'],
    ['목록/교환', '/admin/ecommerce/claims?type=exchange'],
    ['목록/반품', '/admin/ecommerce/claims?type=return'],
    ['목록/취소', '/admin/ecommerce/claims?type=cancel'],
    ['목록/환불', '/admin/ecommerce/claims?type=refund'],
  ] as Array<[string, string]>) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-section-id]', { timeout: 30_000 }).catch(() => null);
    await page.waitForTimeout(1_200);
    rows.push(
      await page.evaluate((name: string) => ({
        shape: name,
        sections: document.querySelectorAll('[data-section-id]').length,
        gridRows: document.querySelectorAll('tbody tr').length,
        bulkBar: document.querySelector('[id*="bulk"]') !== null,
      }), shape)
    );
  }

  for (const [shape, fixture] of [
    ['상세/환불', FIXTURES.TR_refund],
    ['상세/교환', FIXTURES.TR_exchange],
  ] as Array<[string, typeof FIXTURES.TR_refund]>) {
    const status = await openDetail(page, fixture.claim);
    rows.push(
      await page.evaluate((name: string) => ({
        shape: name,
        sections: document.querySelectorAll('[data-section-id]').length,
        gridRows: document.querySelectorAll('tbody tr').length,
        bulkBar: document.getElementById('claim_item_bulk_bar') !== null,
        headerTransition: document.querySelector('[id*="claim_status"], [id*="status_change"]') !== null,
      }), shape)
    );
    rows.push({ shape: `${shape}/detailStatus`, status });
  }

  // ⑦ 구매자 신청 화면 · ⑧ 비회원 신청 화면 — 첨부 partial 을 공유하는 형태.
  await authenticatePage(page, customerToken);

  /**
   * 그 화면이 실제로 그려졌는지 읽는다 (구매자·비회원 템플릿은 `data-section-id` 를 쓰지 않는다).
   *
   * @param page 페이지
   * @param shape 형태 이름
   * @return 측정값
   */
  const readUserScreen = (shapeName: string) =>
    page.evaluate((name: string) => {
      const text = (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();

      return {
        shape: name,
        heading: (document.querySelector('h1, h2')?.textContent ?? '').trim().slice(0, 40),
        renderedChars: text.length,
        // 오류 화면 판정은 **제목**으로 한다 — 본문 전체를 훑으면 빈 목록 안내
        // ("주문을 찾을 수 없습니다") 같은 정상 문구까지 걸려 정상 화면이 회귀로 찍힌다.
        errorPage: /페이지를 찾을 수 없|404|500/.test(
          (document.querySelector('h1, h2')?.textContent ?? '').trim()
        ),
        listRows: document.querySelectorAll('tbody tr, [class*="card"], [class*="item"]').length,
        formPresent: document.querySelector('form, input') !== null,
      };
    }, shapeName);

  // 비회원 조회 폼의 주소는 레이아웃 이름(`shop/guest_order_form`)에서 곧바로 유추되지 않는다
  // — 후보를 돌면서 **오류 화면이 아닌 첫 주소**를 쓰고, 무엇을 시도했는지도 함께 남긴다.
  const guestCandidates = ['/shop/guest/orders', '/guest/orders', '/shop/guest_order_form'];
  const guestTried: Array<{ url: string; errorPage: boolean; heading: string }> = [];
  for (const candidate of guestCandidates) {
    await page.goto(candidate, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    const probe: any = await readUserScreen('비회원/주문조회');
    guestTried.push({ url: candidate, errorPage: probe.errorPage, heading: probe.heading });
    if (!probe.errorPage) {
      rows.push({ ...probe, url: candidate, tried: guestTried });
      break;
    }
  }
  if (!rows.some((r: any) => r.shape === '비회원/주문조회')) {
    rows.push({ shape: '비회원/주문조회', resolved: false, tried: guestTried });
  }

  for (const [shape, url] of [
    ['구매자/신청내역', '/mypage/claims'],
    ['구매자/주문목록', '/mypage/orders'],
  ] as Array<[string, string]>) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    rows.push({ ...(await readUserScreen(shape)), url });
  }

  record('T-R/regression', rows);
  expect(rows.length).toBeGreaterThan(8);
});
