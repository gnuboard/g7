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
 *   admin_ecommerce_settings.g7.html 은 `settings`(initLocal: "form", refetchOnMount) 와
 *   `ecommerceNotificationDefinitions`(initLocal 맵) 을 모두 progressive 로 갖는다.
 *   그 결과 배송설정 탭의 배송가능 국가 표가 간헐적으로 비어 "No shipping countries registered."
 *   가 표시되었다. 직접 URL 접근/새로고침으로는 재현되지 않는다 (SPA 진입에서만 발생).
 *
 *   ⓘ 이후 #471(관리자 설정 탭 지연로딩)이 데이터소스에 탭 `if` 게이트를 붙이면서, 두 소스가 함께
 *   뜨는 탭이 바뀌었다. 최초 재현 무대였던 배송설정 탭에는 이제 `initLocal` 소스가 하나뿐이라
 *   경합이 성립하지 않는다 — 검증은 `PROGRESSIVE_PAIR_TAB` 에서 수행한다.
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
 * @scenario source=localinit-multi-progressive, axis=entry:spa-navigation, cpu=throttled
 * @effects no-lost-initlocal, table-rendered, no-empty-state
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/**
 * progressive `initLocal` 소스가 **둘 이상 동시에 활성**인 탭.
 *
 * `admin_ecommerce_settings.g7.html` 에서 `initLocal` 을 가진 소스는 둘뿐이다 —
 * `settings`(게이트 없음, 모든 탭)와 `ecommerceNotificationDefinitions`
 * (`if`: `notification_definitions`·`mileage` 탭 한정).
 *
 * 이 스펙이 처음 작성된 #459(07-10) 시점에는 게이트가 없어 **배송설정 탭에서도** 두 소스가 함께
 * 떴다. 그 뒤 #471(07-14)의 관리자 설정 탭 지연로딩이 `if` 게이트를 도입하면서 배송설정 탭에는
 * `initLocal` 소스가 `settings` 하나만 남았다 — 경합 자체가 성립하지 않는다. 그래서 이 회귀를
 * 실제로 재현하려면 두 소스가 함께 뜨는 탭에서 돌려야 한다.
 *
 * 실측(브라우저): 배송설정 탭에서는 `/api/admin/notification-definitions` 요청이 **0건**이고,
 * `_local.notificationDefinitionCurrentPage` 는 응답이 아니라 레이아웃 `state` 기본값(1)에서 온다.
 * 그 자리에서 `refetchDataSource` 를 호출해도 게이트가 닫혀 있어 요청이 생기지 않는다.
 */
const PROGRESSIVE_PAIR_TAB = 'notification_definitions';

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

/**
 * SPA 네비게이션으로 이커머스 환경설정의 특정 탭에 진입한다 (직접 URL 접근으로는 재현되지 않는다).
 *
 * 탭을 인자로 받는 이유: #471 의 **관리자 설정 탭 지연로딩**이 데이터소스마다 `if` 게이트를
 * 붙이면서, "progressive `initLocal` 소스가 둘 이상" 인 화면 상태가 탭에 따라 달라졌다.
 * 아래 `PROGRESSIVE_PAIR_TAB` 주석 참조.
 */
async function navigateToSettingsTab(
  page: import('@playwright/test').Page,
  tab: string,
): Promise<void> {
  await page.evaluate(() => {
    (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: '/admin/dashboard' } });
  });
  await page.waitForTimeout(2000);

  await page.evaluate((t) => {
    (window as any).G7Core.dispatch({
      handler: 'navigate',
      params: { path: '/admin/ecommerce/settings', query: { tab: t } },
    });
  }, tab);
}

test('@smoke initLocal progressive 소스가 둘 이상이어도 먼저 도착한 초기값이 유실되지 않는다', async ({ page }) => {
  await authenticatePage(page, settingsToken());
  await page.goto('/admin/dashboard');
  await page.waitForFunction(() => !!(window as any).G7Core?.dispatch);

  // commit 을 지연시켜 두 progressive 응답이 같은 commit 사이에 도착하도록 만든다.
  await throttleCpu(page, 6);

  // 두 progressive 소스가 함께 뜨는 탭이어야 경합이 성립한다 (PROGRESSIVE_PAIR_TAB 주석 참조).
  // 나중 도착 소스가 실제로 응답을 받는지까지 확인해야 단언이 정적 state 기본값에 속지 않는다.
  const notifResponse = page.waitForResponse(
    (res) => res.url().includes('/api/admin/notification-definitions') && res.status() === 200,
    { timeout: 30_000 },
  );
  await navigateToSettingsTab(page, PROGRESSIVE_PAIR_TAB);
  await notifResponse;

  // settings 소스의 initLocal("form") 이 _local 에 반영되어야 한다.
  // `form` 은 레이아웃 `state` 에 기본값이 없으므로, 존재 자체가 이 소스의 payload 가 살아남았다는 증거다
  // (반대로 notificationDefinition* 3키는 전부 static 기본값이 있어 유실 판별에 쓸 수 없다).
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

  // 사용자 화면: 알림 정의 목록이 렌더되고 빈 상태 블록은 보이지 않아야 한다.
  // (이 탭은 표가 아니라 Div 카드 목록이라 `tbody tr` 로는 잡히지 않는다 — 탭별 렌더 구조가 다르다.)
  await expect(page.locator('#ecommerce_notif_definitions_list')).toBeAttached({ timeout: 15_000 });
  await expect(page.locator('#ecommerce_notif_empty')).toBeHidden();
});

// 대상 탭 정정 (2026-07-30 실측):
//   이 테스트는 배송(shipping) 탭에서 `ecommerceNotificationDefinitions` 를 refetch 했는데 요청이
//   전혀 발생하지 않았다. 원인은 제품 결함이 아니라 **탭 선택 오류**다 — 그 소스의 `if` 게이트는
//   `tab ∈ {notification_definitions, mileage}` 이라 배송 탭에서는 애초에 등록되지 않는다.
//   등록되지 않은 소스의 refetch 가 무연산인 것은 정상 동작이다.
//
//   계측 근거 (탭별 등록 소스 실측):
//     - tab=shipping                → [settings, carriers]                          (notification 요청 0 → refetch 후 0)
//     - tab=notification_definitions → [settings, ecommerceNotificationDefinitions, availableChannels]
//                                                                                   (notification 요청 1 → refetch 후 2)
//     - 대조군 carriers 는 shipping 탭에서 1 → refetch 후 2 (핸들러 자체는 정상)
//
//   본 규칙("소비가 끝난 payload 가 이후 refetch 로 재적용되지 않는가")은 initLocal 을 가진 소스가
//   둘 이상 등록된 탭에서만 성립한다. `settings`(initLocal:"form") 는 게이트가 없어 전 탭에 등록되고,
//   짝이 되는 `ecommerceNotificationDefinitions`(initLocal 맵) 는 알림정의 탭에만 있으므로 그 탭에서 측정한다.
//   `form.shipping.*` 값은 `settings` 응답에 담겨 탭과 무관하게 존재한다(실측: available_countries 10건).
test('@smoke 폼 편집 후 다른 데이터소스를 refetch 해도 편집값이 되돌아가지 않는다', async ({ page }) => {
  await authenticatePage(page, settingsToken());
  await page.goto('/admin/dashboard');
  await page.waitForFunction(() => !!(window as any).G7Core?.dispatch);

  // 편집 → 다른 소스 refetch 를 검증하려면 그 "다른 소스" 가 **현재 탭에서 활성**이어야 한다.
  // 배송설정 탭에서는 `ecommerceNotificationDefinitions` 의 `if` 게이트가 닫혀 있어 refetch 가
  // 요청조차 만들지 않는다 (PROGRESSIVE_PAIR_TAB 주석의 실측 참조).
  //
  // 대기는 네비게이션 **이전에** 걸어야 한다 — 이미 도착한 응답은 뒤늦은 waitForResponse 가 잡지 못한다.
  const initialNotifResponse = page.waitForResponse(
    (res) => res.url().includes('/api/admin/notification-definitions') && res.status() === 200,
    { timeout: 30_000 },
  );
  await navigateToSettingsTab(page, PROGRESSIVE_PAIR_TAB);
  await initialNotifResponse;
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
  // 각각 별도 commit 에서 소비되면 나중 payload 만 담긴다. 둘 다 정상이므로 키 구성에는 기대지 않는다.
  //
  // 소비 완료 판정에 `_local.notificationDefinitionCurrentPage` 를 쓰면 안 된다 — 그 키는 레이아웃
  // `state` 에 기본값(1)이 있어 **응답이 한 번도 오지 않아도 채워져 있다**. 실제로 배송설정 탭에서는
  // 요청 0건인 채로 이 단언이 통과해, 전제가 깨진 것을 숨기고 있었다.
  // 응답 도착은 위 `initialNotifResponse` 로 이미 확인했다.

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

  // 소비(=`_localInit` 슬롯 관측)는 응답 도착 다음 React commit 에서 일어난다. 그 시점을 상태값으로
  // 잡을 수는 없다 — 이 소스의 initLocal 대상 3키(`notificationDefinition{CurrentPage,TotalPages,
  // TotalItems}`)는 전부 레이아웃 `state` 에 기본값이 있어 "응답에서 왔는지" 를 구분해 주지 못한다.
  // 그래서 커밋이 지나갈 만큼 안정화한 뒤 판정한다. 되돌림(회귀)은 바로 이 소비 시점에 일어나므로,
  // 소비를 지나 보낸 뒤에도 편집값이 남아 있으면 규칙이 지켜진 것이다.
  await page.waitForTimeout(1500);

  const threshold = await page.evaluate(
    () => (window as any).G7Core.state.getLocal()?.form?.shipping?.free_shipping_threshold,
  );
  expect(threshold).toBe(999999);
});
