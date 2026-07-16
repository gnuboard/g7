/**
 * 주문설정 결제수단 — PG 고정(간편결제) 표시 회귀 (#475).
 *
 * 배경: PG 플러그인이 등록하는 간편결제(네이버페이 등)는 특정 PG 전용 수단인데,
 * 과거에는 이를 코어의 "PG 불필요" 목록(`['point','deposit','free','dbank']`)에 정규식으로
 * 끼워 넣어 PG 선택 셀렉트를 숨겼다. 화면에는 "PG 불필요" 로 보였고, 서버도 같은 오해를
 * 공유해 간편결제 주문을 PG 결제가 아닌 주문으로 처리했다 — 그 결과 결제 실패 주문에
 * 관리자 알림이 발송되고 임시주문이 삭제돼 재결제가 막혔다.
 *
 * 이제 결제수단 카탈로그가 `pg_locked` / `needs_pg` 를 내려주고 레이아웃이 그 값으로 직접
 * 3분기한다: PG 고정 배지 / PG 선택 셀렉트 / "PG 불필요".
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - **결제수단 ID·PG명·라벨 문자열을 하드코딩하지 않는다.** 설치된 PG 플러그인 구성은
 *    환경마다 다르다. 검증 대상 수단은 앱 상태의 카탈로그에서 `pg_locked` 플래그로 **런타임에
 *    선별**하고, 기대 PG 표시명도 `available_pg_providers` 에서 도출한다.
 *  - PG 고정 수단이 하나도 없는 환경(간편결제 플러그인 미설치)에서는 판별이 불가능하므로
 *    skip 한다 — 거짓 통과를 만들지 않는다.
 *  - "셀렉트가 없다" 만으로는 회귀를 못 잡는다. 과거 결함도 셀렉트를 숨겼기 때문이다.
 *    **PG 고정 배지가 PG 이름과 함께 뜨는 것**까지 확인해야 "불필요" 와 "고정" 이 구분된다.
 *  - PG 선택 UI 는 네이티브 `<select>` 가 아니라 템플릿의 Select 컴포넌트(button 렌더)다.
 *    태그로 찾지 말고 `data-testid` (`pg-select-{id}` / `pg-locked-badge-{id}` / `pg-not-required-{id}`)
 *    로 분기 결과를 조회한다 — 컴포넌트 내부 구현이 바뀌어도 이 spec 은 유지된다.
 *
 * @scenario extension_payment_method method_kind=extension × capability_declared=declared × capability=pg_locked
 * @effects admin_shows_pg_locked_badge,
 *          admin_hides_pg_select_for_locked,
 *          admin_shows_pg_select_for_unlocked,
 *          saved_null_pg_provider_self_healed
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const ORDER_SETTINGS_URL = '/admin/ecommerce/settings?tab=order_settings';

interface CatalogMethod {
  id: string;
  pg_provider: string | null;
  pg_locked?: boolean;
  needs_pg?: boolean;
}

/** 폼에 시드된 결제수단 카탈로그 (화면이 실제로 그리는 원본). */
async function catalogMethods(page: Page): Promise<CatalogMethod[]> {
  return page.evaluate(() => {
    const form = (window as any).G7Core?.state?.getLocal?.()?.form;
    return (form?.order_settings?.payment_methods ?? []) as CatalogMethod[];
  });
}

/** 설치된 PG 제공자 목록 (id → 표시명 도출용). */
async function pgProviders(page: Page): Promise<Array<{ id: string; name: unknown }>> {
  return page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.form?.available_pg_providers ?? []);
}

/**
 * PG 제공자의 화면 표시명을 도출한다.
 *
 * `name` 은 활성 로케일로 이미 해석된 문자열이거나(현행), 로케일별 객체일 수 있다.
 * 두 형태를 모두 받아 화면에 실제로 찍히는 문자열을 돌려준다 — 어느 쪽이든 spec 은 유지된다.
 *
 * @param provider available_pg_providers 항목 (미설치 PG 면 undefined)
 * @param fallback 제공자를 못 찾았을 때 쓸 값 (결제수단의 pg_provider ID)
 * @returns 배지에 표시될 것으로 기대되는 문자열
 */
function displayName(provider: { name?: unknown } | undefined, fallback: string | null): string {
  const name = provider?.name;
  if (typeof name === 'string' && name.trim()) return name;
  if (name && typeof name === 'object') {
    const localized = Object.values(name as Record<string, string>).find(Boolean);
    if (localized) return localized;
  }
  return String(fallback ?? '');
}

async function gotoOrderSettings(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(ORDER_SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 결제수단 표가 시드될 때까지 대기 (_local.form 시드 → DOM 렌더)
  await expect.poll(async () => (await catalogMethods(page)).length, { timeout: 30_000 }).toBeGreaterThan(0);
}

test.describe('@sirsoft-ecommerce 주문설정 결제수단 — PG 고정 표시', () => {
  // @scenario extension_payment_method:pg_locked_badge
  // @effects admin_shows_pg_locked_badge, admin_hides_pg_select_for_locked, saved_null_pg_provider_self_healed
  test('PG 고정 수단(간편결제)은 PG 셀렉트 대신 "PG 고정 · {PG명}" 배지로 표시된다', async ({
    page,
    settingsToken,
  }) => {
    await gotoOrderSettings(page, settingsToken);

    const methods = await catalogMethods(page);
    const locked = methods.filter((m) => m.pg_locked === true);

    test.skip(
      locked.length === 0,
      'PG 고정 결제수단이 없는 환경 (간편결제 플러그인 미설치) — 판별 불가',
    );

    // 자가 치유 검증: 저장 파일에 pg_provider=null 이 남아 있어도 정의값이 강제되어야 한다.
    for (const method of locked) {
      expect(
        method.pg_provider,
        `${method.id} 는 PG 고정 수단이므로 pg_provider 가 비어 있으면 안 된다 (저장된 null 이 정의값을 이기면 #475 재발)`,
      ).toBeTruthy();
      expect(method.needs_pg, `${method.id} 는 PG 결제 수단이어야 한다`).toBe(true);
    }

    const providers = await pgProviders(page);

    for (const method of locked) {
      const providerName = displayName(
        providers.find((p) => p.id === method.pg_provider),
        method.pg_provider,
      );

      // ① PG 고정 배지가 뜨고, 그 안에 PG 이름이 들어 있어야 한다.
      //    ("PG 불필요" 로 표시되면 #475 회귀 — 배지 자체가 없으므로 아래 단언이 실패한다.)
      const badge = page.getByTestId(`pg-locked-badge-${method.id}`);
      await expect(
        badge,
        `${method.id} 는 PG 고정 수단이므로 PG 고정 배지가 떠야 한다`,
      ).toBeVisible();
      await expect(
        badge,
        `${method.id} 의 배지에 PG 이름(${providerName})이 표시돼야 한다`,
      ).toContainText(String(providerName));

      // ② PG 고정 수단에는 관리자가 PG 를 바꿀 수 있는 셀렉트가 없어야 한다.
      await expect(
        page.getByTestId(`pg-select-${method.id}`),
        `${method.id} 는 PG 가 고정이므로 PG 선택 셀렉트가 뜨면 안 된다`,
      ).toHaveCount(0);

      // ③ PG 고정과 "PG 불필요" 는 상호배타 — 둘 다 뜨면 표시가 모순된다.
      await expect(
        page.getByTestId(`pg-not-required-${method.id}`),
        `${method.id} 가 "PG 불필요" 로 표시되면 #475 회귀`,
      ).toHaveCount(0);
    }
  });

  // @scenario extension_payment_method:pg_select_for_unlocked
  // @effects admin_shows_pg_select_for_unlocked
  test('일반 PG 수단(카드 등)은 PG 선택 셀렉트가 그대로 노출된다 (회귀 차단)', async ({
    page,
    settingsToken,
  }) => {
    await gotoOrderSettings(page, settingsToken);

    const methods = await catalogMethods(page);
    const selectable = methods.filter((m) => !m.pg_locked && m.needs_pg);

    test.skip(selectable.length === 0, 'PG 선택 가능한 결제수단이 없는 환경 — 판별 불가');

    const providers = await pgProviders(page);
    test.skip(providers.length === 0, '설치된 PG 플러그인이 없어 셀렉트가 렌더되지 않는 환경');

    // PG 고정 분기가 과잉 적용돼 일반 PG 수단의 셀렉트까지 삼키는 회귀를 차단한다.
    for (const method of selectable) {
      await expect(
        page.getByTestId(`pg-select-${method.id}`),
        `${method.id} 는 관리자가 PG 를 고를 수 있어야 하므로 PG 선택 셀렉트가 떠야 한다`,
      ).toBeVisible({ timeout: 10_000 });

      await expect(
        page.getByTestId(`pg-locked-badge-${method.id}`),
        `${method.id} 는 PG 고정이 아니므로 PG 고정 배지가 뜨면 안 된다`,
      ).toHaveCount(0);
    }
  });

  // @scenario extension_payment_method:no_pg_required
  // @effects admin_shows_pg_select_for_unlocked
  test('PG 불필요 수단(무통장·포인트 등)은 셀렉트도 배지도 없이 "PG 불필요"로 남는다', async ({
    page,
    settingsToken,
  }) => {
    await gotoOrderSettings(page, settingsToken);

    const methods = await catalogMethods(page);
    const noPg = methods.filter((m) => m.needs_pg === false);

    test.skip(noPg.length === 0, 'PG 불필요 결제수단이 없는 환경 — 판별 불가');

    for (const method of noPg) {
      // PG 불필요 수단은 PG 고정도 아니어야 한다 (두 분기가 겹치면 표시가 모순된다).
      expect(method.pg_locked ?? false, `${method.id} 는 PG 불필요이므로 PG 고정일 수 없다`).toBe(false);

      // 화면에도 "PG 불필요" 로만 남고, 셀렉트/배지 어느 쪽도 뜨지 않아야 한다.
      await expect(
        page.getByTestId(`pg-not-required-${method.id}`),
        `${method.id} 는 "PG 불필요" 로 표시돼야 한다`,
      ).toBeVisible();
      await expect(
        page.getByTestId(`pg-select-${method.id}`),
        `${method.id} 는 PG 가 필요 없으므로 PG 선택 셀렉트가 뜨면 안 된다`,
      ).toHaveCount(0);
      await expect(
        page.getByTestId(`pg-locked-badge-${method.id}`),
        `${method.id} 는 PG 가 필요 없으므로 PG 고정 배지가 뜨면 안 된다`,
      ).toHaveCount(0);
    }
  });
});
