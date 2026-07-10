/**
 * Smoke: `initLocal` 을 가진 progressive 데이터소스가 둘 이상인 화면에서, SPA 네비게이션으로
 *        진입할 때 먼저 도착한 소스의 초기값이 유실되던 회귀 (engine-v1.52.2).
 *
 * 버그:
 *   progressive 소스는 응답이 오는 대로 각자 `updateTemplateData({ _localInit: 자기 payload })`
 *   를 호출한다. 그런데 `_localInit` 은 단일 슬롯이고 얕은 스프레드(`...data`)로 교체되었다.
 *   소비부(DynamicRenderer 의 `_localInit` useEffect)는 React commit 이후에 실행되므로,
 *   두 번의 updateTemplateData 가 commit 사이에 연달아 들어오면 슬롯에는 마지막 payload 만 남고
 *   먼저 도착한 소스의 payload 는 한 번도 관측되지 않은 채 사라진다.
 *
 *   admin_ecommerce_settings.json 은 `settings`(initLocal: "form", refetchOnMount) 와
 *   `ecommerceNotificationDefinitions`(initLocal 맵) 을 모두 progressive 로 갖는다.
 *   그 결과 배송설정 탭의 배송가능 국가 표가 간헐적으로 비어 "No shipping countries registered."
 *   가 표시되었다. 직접 URL 접근/새로고침으로는 재현되지 않는다 (SPA 진입에서만 발생).
 *
 * 수정:
 *   아직 어떤 렌더러도 관측하지 않은(unconsumed) `_localInit` 슬롯만 누적 병합한다.
 *   이미 관측된 슬롯은 종전대로 교체한다 — 그래야 사용자가 폼을 편집한 뒤 다른 소스를 refetch 해도
 *   소비가 끝난 과거 payload 가 재적용되어 편집 결과를 되돌리지 않는다.
 *
 * 검증:
 *   응답 도착 간격은 원인이 아니다(81ms 실패 / 62ms 통과). 결정 요인은 두 updateTemplateData 사이에
 *   React commit 이 끼어드는지 여부다. 정상 CPU 에서는 간헐적이라 거짓 통과하므로,
 *   CDP `Emulation.setCPUThrottlingRate` 로 commit 을 지연시켜 경합 창을 결정적으로 연다.
 *   (수정 전 번들은 이 조건에서 8/8 실패, 수정 후 8/8 통과 — 브라우저 A/B 실측 확인됨)
 *
 * @scenario source=localinit-multi-progressive axis=entry:spa-navigation,cpu:throttled
 * @effects no-lost-initlocal, table-rendered, no-empty-state
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 이커머스 환경설정 화면 진입에 필요한 권한 토큰 */
function settingsToken(): string {
  return issueToken(
    'core.settings.read',
    'sirsoft-ecommerce.settings.read',
    'core.admin.identity.policies.read',
  );
}

/** CPU 스로틀링으로 React commit 을 지연시켜 `_localInit` 경합 창을 연다. */
async function throttleCpu(page: import('@playwright/test').Page, rate: number): Promise<void> {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Emulation.setCPUThrottlingRate', { rate });
}

/** SPA 네비게이션으로 배송설정 탭에 진입한다 (직접 URL 접근으로는 재현되지 않는다). */
async function navigateToShippingTab(page: import('@playwright/test').Page): Promise<void> {
  await page.evaluate(() => {
    (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: '/admin/dashboard' } });
  });
  await page.waitForTimeout(2000);

  await page.evaluate(() => {
    (window as any).G7Core.dispatch({
      handler: 'navigate',
      params: { path: '/admin/ecommerce/settings', query: { tab: 'shipping' } },
    });
  });
}

test('@smoke initLocal progressive 소스가 둘 이상이어도 먼저 도착한 초기값이 유실되지 않는다', async ({ page }) => {
  await authenticatePage(page, settingsToken());
  await page.goto('/admin/dashboard');
  await page.waitForFunction(() => !!(window as any).G7Core?.dispatch);

  // commit 을 지연시켜 두 progressive 응답이 같은 commit 사이에 도착하도록 만든다.
  await throttleCpu(page, 6);

  await navigateToShippingTab(page);

  // settings 소스의 initLocal("form") 이 _local 에 반영되어야 한다.
  await expect
    .poll(
      () =>
        page.evaluate(
          () =>
            (window as any).G7Core.state.getLocal()?.form?.shipping?.available_countries?.length ?? null,
        ),
      { timeout: 30_000, message: 'settings 데이터소스의 initLocal(form) 이 _local 에 반영되어야 함' },
    )
    .toBeGreaterThan(0);

  // 나중에 도착한 notification 소스의 initLocal 도 함께 살아 있어야 한다 (한쪽만 남으면 안 됨).
  const notifPage = await page.evaluate(
    () => (window as any).G7Core.state.getLocal()?.notificationDefinitionCurrentPage ?? null,
  );
  expect(notifPage).not.toBeNull();

  // 사용자 화면: 국가 표가 렌더되고, 빈 상태 문구는 보이지 않아야 한다.
  // (상태 반영과 DOM 커밋 사이에 지연이 있으므로 표 렌더를 먼저 기다린다)
  await expect.poll(() => page.locator('tbody tr').count(), { timeout: 15_000 }).toBeGreaterThan(0);
  await expect(page.getByText('No shipping countries registered.')).toBeHidden();
});

test('@smoke 폼 편집 후 다른 데이터소스를 refetch 해도 편집값이 되돌아가지 않는다', async ({ page }) => {
  await authenticatePage(page, settingsToken());
  await page.goto('/admin/dashboard');
  await page.waitForFunction(() => !!(window as any).G7Core?.dispatch);

  await navigateToShippingTab(page);
  await expect
    .poll(
      () =>
        page.evaluate(
          () => (window as any).G7Core.state.getLocal()?.form?.shipping?.available_countries?.length ?? 0,
        ),
      { timeout: 30_000 },
    )
    .toBeGreaterThan(0);

  // 나중에 도착하는 소스(notification)의 `_localInit` 까지 소비가 끝난 뒤에 편집해야 한다.
  // 소비 직전에 편집하면 그 편집이 나중 소비에 덮이는데, 그것은 본 규칙이 다루는 상황이 아니다.
  // 검증 대상은 "소비가 끝난 payload 가 이후 refetch 로 재적용되지 않는가" 이다.
  //
  // 마지막 슬롯의 키 구성은 시행마다 다르다 — 두 payload 가 같은 commit 사이에 도착하면 합집합이,
  // 각각 별도 commit 에서 소비되면 나중 payload 만 담긴다. 둘 다 정상이므로 키 구성에는 기대지 않고,
  // 두 소스의 initLocal 이 모두 `_local` 에 도달했는지로 판정한다.
  await expect
    .poll(
      () =>
        page.evaluate(
          () => (window as any).G7Core.state.getLocal()?.notificationDefinitionCurrentPage ?? null,
        ),
      { timeout: 20_000, message: '나중 도착 소스의 initLocal 까지 소비되어야 함' },
    )
    .not.toBeNull();

  // 소비 직후의 후속 커밋이 끝나도록 잠시 안정화한다 (편집이 in-flight 소비에 덮이는 것 방지).
  await page.waitForTimeout(1500);

  // 사용자가 폼을 편집한다. (편집이 _local 에 반영될 때까지 대기 — 고정 sleep 금지)
  await page.evaluate(() => {
    const G7Core = (window as any).G7Core;
    const form = G7Core.state.getLocal().form;
    G7Core.state.setLocal({
      form: { ...form, shipping: { ...form.shipping, free_shipping_threshold: 999999 } },
    });
  });
  await expect
    .poll(
      () =>
        page.evaluate(
          () => (window as any).G7Core.state.getLocal()?.form?.shipping?.free_shipping_threshold,
        ),
      { timeout: 10_000, message: '편집값이 _local 에 반영되어야 함' },
    )
    .toBe(999999);

  // 다른 소스를 refetch — 소비가 끝난 settings payload 가 재적용되면 편집이 되돌아간다.
  // refetch 응답 도착을 명시적으로 기다린 뒤 판정한다 (고정 sleep 은 응답 전 단언 위험).
  const refetchDone = page.waitForResponse(
    (res) => res.url().includes('/api/admin/notification-definitions') && res.status() === 200,
    { timeout: 20_000 },
  );
  await page.evaluate(() => {
    (window as any).G7Core.dispatch({
      handler: 'refetchDataSource',
      params: { dataSourceId: 'ecommerceNotificationDefinitions' },
    });
  });
  await refetchDone;

  // refetch 결과가 _local 에 반영될 때까지 기다린 뒤(= 소비 완료), 편집값이 살아 있는지 본다.
  await expect
    .poll(
      () =>
        page.evaluate(
          () => (window as any).G7Core.state.getLocal()?.notificationDefinitionCurrentPage ?? null,
        ),
      { timeout: 15_000, message: 'refetch 한 소스의 initLocal 이 _local 에 반영되어야 함' },
    )
    .not.toBeNull();

  const threshold = await page.evaluate(
    () => (window as any).G7Core.state.getLocal()?.form?.shipping?.free_shipping_threshold,
  );
  expect(threshold).toBe(999999);
});
