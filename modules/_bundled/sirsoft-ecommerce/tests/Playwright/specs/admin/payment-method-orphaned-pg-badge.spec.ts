/**
 * 주문설정 결제수단 — 죽은 PG 지정 표시·차단 (A2).
 *
 * 배경: 고아 판정(`_orphaned`)은 결제수단 ID 에만 계산된다. builtin 수단(카드 등)에 특정 PG 를
 * 지정한 뒤 그 PG 플러그인을 제거하면, 수단 자체는 카탈로그에 남아 있어 고아 필터를 그대로
 * 통과한다. 체크아웃에 선택 가능한 결제수단으로 노출되고, 주문하면 PG 라우팅이 매칭에 실패해
 * **결제창 없이 주문완료로 넘어간다**.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - 결제수단 ID·PG명을 하드코딩하지 않는다. 검증 대상은 카탈로그에서 `needs_pg && !pg_locked`
 *    조건으로 런타임 선별한다.
 *  - 죽은 PG 상태는 환경에 자연 발생하지 않으므로 **관리자 API 로 주입**하고 `finally` 에서
 *    반드시 원복한다. 주입 없이 skip 하면 그 skip 이 거짓 통과가 된다.
 *  - `_orphaned` 와 달리 행 편집 컨트롤은 막지 않는다 — 살아있는 PG 로 바꿔 복구하는 경로가
 *    남아야 하므로 PG 선택 셀렉트는 계속 보여야 한다.
 *
 * @scenario pg_provider_state=dead_own
 * @effects dead_pg_method_flagged_for_admin,
 *          dead_pg_method_hidden_from_checkout,
 *          dead_pg_method_keeps_recovery_control
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { APIRequestContext, Page } from '@playwright/test';

const ORDER_SETTINGS_URL = '/admin/ecommerce/settings?tab=order_settings';
const ADMIN_SETTINGS_API = '/api/modules/sirsoft-ecommerce/admin/settings';
const PUBLIC_PAYMENT_API = '/api/modules/sirsoft-ecommerce/settings/payment';
const DEAD_PG = 'ghost_pg_e2e';

interface CatalogMethod {
  id: string;
  pg_provider: string | null;
  pg_locked?: boolean;
  needs_pg?: boolean;
  _orphaned?: boolean;
  _orphaned_pg?: boolean;
}

/** 폼에 시드된 결제수단 카탈로그 (화면이 실제로 그리는 원본). */
async function catalogMethods(page: Page): Promise<CatalogMethod[]> {
  return page.evaluate(() => {
    const form = (window as any).G7Core?.state?.getLocal?.()?.form;
    return (form?.order_settings?.payment_methods ?? []) as CatalogMethod[];
  });
}

async function gotoOrderSettings(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(ORDER_SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect.poll(async () => (await catalogMethods(page)).length, { timeout: 30_000 }).toBeGreaterThan(0);
}

/** 관리자 API 로 저장된 결제수단 배열을 읽는다 (런타임 전용 플래그 포함). */
async function readSavedMethods(request: APIRequestContext, token: string): Promise<CatalogMethod[]> {
  const response = await request.get(ADMIN_SETTINGS_API, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(response.ok(), '관리자 설정 조회 실패').toBeTruthy();
  const body = await response.json();
  return body?.data?.order_settings?.payment_methods ?? [];
}

/** 결제수단 배열을 저장한다 (런타임 전용 플래그는 서버가 제거한다). */
async function saveMethods(
  request: APIRequestContext,
  token: string,
  methods: CatalogMethod[],
): Promise<void> {
  const response = await request.put(ADMIN_SETTINGS_API, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: { _tab: 'order_settings', order_settings: { payment_methods: methods } },
  });
  expect(response.ok(), `결제수단 저장 실패 (${response.status()})`).toBeTruthy();
}

test.describe('@sirsoft-ecommerce 주문설정 결제수단 — 죽은 PG 표시·차단', () => {
  // 이 그룹은 상점 설정(전역 상태)을 주입·원복한다. 병렬로 돌리면 다른 테스트가 주입 구간의
  // 상태를 읽어 거짓 실패를 낸다.
  test.describe.configure({ mode: 'serial' });

  // @scenario pg_provider_state=dead_own
  // @effects dead_pg_method_flagged_for_admin, dead_pg_method_hidden_from_checkout, dead_pg_method_keeps_recovery_control
  test('지정 PG 가 사라진 결제수단은 관리자에 배지가 뜨고 공개 응답에서는 제거된다', async ({
    page,
    request,
    settingsToken,
  }) => {
    const original = await readSavedMethods(request, settingsToken);
    expect(original.length, '결제수단 카탈로그가 비어 있어 판별 불가').toBeGreaterThan(0);

    const target = original.find((m) => m.needs_pg === true && !m.pg_locked);
    test.skip(!target, 'PG 를 관리자가 지정할 수 있는 결제수단이 없는 환경 — 판별 불가');

    const targetId = target!.id;

    // 주입 전에 이미 죽은 PG 가 지정돼 있으면 원복 기준(original)이 오염된 상태다.
    // 그대로 진행하면 "원복" 이 오염 상태를 되돌려 놓으므로 여기서 멈추고 드러낸다.
    expect(
      target!._orphaned_pg ?? false,
      `${targetId} 에 이미 죽은 PG 가 지정돼 있다 — 운영자 조치가 필요하거나 이전 실행이 원복에 실패했다`,
    ).toBeFalsy();

    try {
      // 죽은 PG 주입 — 플러그인 제거 후 저장값만 남은 상태를 등가 재현한다
      await saveMethods(
        request,
        settingsToken,
        original.map((m) => (m.id === targetId ? { ...m, pg_provider: DEAD_PG, is_active: true } : m)),
      );

      // ① 관리자 응답: 플래그가 서고 배지가 뜬다
      const flagged = (await readSavedMethods(request, settingsToken)).find((m) => m.id === targetId);
      expect(
        flagged?._orphaned_pg,
        `${targetId} 에 죽은 PG 가 지정됐으므로 관리자 응답에 _orphaned_pg 가 서야 한다`,
      ).toBe(true);

      await gotoOrderSettings(page, settingsToken);

      const badge = page.getByTestId(`orphaned-pg-badge-${targetId}`);
      await expect(badge, `${targetId} 에 죽은 PG 배지가 떠야 한다`).toBeVisible({ timeout: 15_000 });

      // ② 복구 경로 유지 — PG 선택 셀렉트를 막지 않는다
      await expect(
        page.getByTestId(`pg-select-${targetId}`),
        `${targetId} 는 살아있는 PG 로 바꿔 복구할 수 있어야 하므로 PG 선택 셀렉트가 남아야 한다`,
      ).toBeVisible();

      // ③ 공개 응답: 해당 수단이 제거된다 (체크아웃에 노출되면 결제창 없는 주문이 만들어진다)
      const publicResponse = await request.get(PUBLIC_PAYMENT_API, { headers: { Accept: 'application/json' } });
      expect(publicResponse.ok(), '공개 결제설정 조회 실패').toBeTruthy();
      const publicBody = await publicResponse.json();
      const publicIds = (publicBody?.data?.payment_methods ?? []).map((m: CatalogMethod) => m.id);

      expect(
        publicIds,
        `${targetId} 는 죽은 PG 를 지정했으므로 공개 응답에서 제거돼야 한다`,
      ).not.toContain(targetId);

      // 런타임 전용 플래그는 공개 응답에도 남지 않는다
      for (const method of publicBody?.data?.payment_methods ?? []) {
        expect(method._orphaned_pg ?? false, '공개 응답에 런타임 플래그가 남았다').toBeFalsy();
      }
    } finally {
      // 원복 — 실패하더라도 환경을 저장 전 상태로 되돌린다
      await saveMethods(request, settingsToken, original);
    }

    // 원복 확인: 배지가 사라지고 공개 응답에 다시 실린다
    const restored = (await readSavedMethods(request, settingsToken)).find((m) => m.id === targetId);
    expect(restored?._orphaned_pg ?? false, '원복 후에도 죽은 PG 플래그가 남았다').toBeFalsy();
    expect(restored?.pg_provider ?? null, '원복 후 PG 지정이 주입값 그대로다').not.toBe(DEAD_PG);
  });

  // 살아 있는 PG 만 있는 상태를 확인하는 테스트다 — dead_own 축이 아니다.
  // @scenario pg_provider_state=live
  // @effects live_pg_method_remains_visible
  test('정상 환경에서는 죽은 PG 배지가 어디에도 뜨지 않는다 (거짓 양성 차단)', async ({
    page,
    settingsToken,
  }) => {
    await gotoOrderSettings(page, settingsToken);

    const methods = await catalogMethods(page);

    // 부재를 단언하기 전에 카탈로그가 실제로 렌더됐는지부터 확정한다
    expect(methods.length, '결제수단 카탈로그가 비어 있다 — 부재 단언이 무의미해진다').toBeGreaterThan(0);

    const flagged = methods.filter((m) => m._orphaned_pg === true);

    expect(
      flagged.map((m) => m.id),
      '죽은 PG 를 지정한 결제수단이 남아 있다 (운영자 조치 필요) — 또는 판정식이 정상 PG 를 오판한다',
    ).toEqual([]);

    await expect(page.locator('[data-testid^="orphaned-pg-badge-"]')).toHaveCount(0);
  });
});
