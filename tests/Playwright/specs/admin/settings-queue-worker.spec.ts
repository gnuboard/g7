/**
 * E2E: 환경설정 > 드라이버 — 「큐 워커」 구역 (#139)
 *
 * 시나리오 매니페스트: tests/scenarios/queue-worker-registry.yaml — 마킹은 각 테스트의
 * scenario(k=v 조합)·effects 주석이 담당한다.
 *
 * 배경: 큐 드라이버가 database·redis 일 때 운영자가 실제로 워커를 돌리는 연결·큐를 등록하고,
 * 등록한 큐마다 대기 건수·마지막 워커 신호를 본다. 서버 422 오류 키는 해당 칸 아래에만 붙어야 한다
 * (계산값 `queueErrors` — 엔진 식 파서가 대입식을 거부해 칸 아래 문구가 비던 결함의 회귀 가드).
 *
 * 저장값을 바꾸지 않는다 — 진입·미저장 선택·422 로 거부되는 저장만 쓴다(422 는 파일을 건드리지 않는다).
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 드라이버 탭 진입 */
async function gotoDriversTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=drivers');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('[data-testid="queue-worker-section"]')).toBeAttached({ timeout: 20_000 });
}

/** 큐 카드의 드라이버 드롭다운에서 「데이터베이스」를 고른다(미저장) */
async function chooseDatabaseDriver(page: import('@playwright/test').Page): Promise<void> {
  const card = page.locator('.admin-card', { has: page.locator('[data-testid="queue-worker-section"]') });
  await card.locator('button[aria-haspopup="listbox"]').first().click();
  await page.getByRole('option', { name: '데이터베이스' }).click();
  await expect(page.locator('[data-testid="queue-connections-input"]')).toBeAttached({ timeout: 10_000 });
}

// @scenario screen=driver_sync
// @effects queue_worker_ui_hidden_for_sync, status_endpoint_contract
test('@smoke #139 - 드라이버 탭에 큐 워커 구역이 마운트되고 상태 API 를 부른다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  const statusResponse = page.waitForResponse((r) => r.url().includes('/api/admin/settings/queue-status'));
  await gotoDriversTab(page);
  expect((await statusResponse).status()).toBe(200);

  const section = page.locator('[data-testid="queue-worker-section"]');
  await expect(section).toHaveCount(1);
  const text = (await section.innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text).not.toMatch(/admin\.settings\./);
});

// @scenario screen=driver_database
// @effects queue_worker_inputs_per_connection, saved_driver_notice
test('#139 - 드라이버를 데이터베이스로 고르면(미저장) 입력칸과 「저장 뒤 반영」 안내가 보인다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);
  await gotoDriversTab(page);

  const savedDriver = await page.evaluate(() => (window as any).G7Core.state.getLocal().originalDrivers?.queue_driver ?? 'sync');
  test.skip(savedDriver !== 'sync', '저장된 큐 드라이버가 sync 일 때의 전이를 본다');

  await chooseDatabaseDriver(page);
  await expect(page.locator('[data-testid="queue-worker-not-required"]')).toHaveCount(0);
  await expect(page.locator('[data-testid="queue-status-saved-driver-differs"]')).toHaveCount(1);
  await expect(page.locator('[data-testid="queue-names-database"]')).toHaveCount(1);
  await expect(page.locator('#save_button')).toBeEnabled();
});

// @scenario screen=error_routing
// @effects queue_errors_routed_per_field, invalid_queue_registration_rejected
test('#139 - 서버 422 오류가 연결 칸·연결별 큐 칸 아래에만 붙는다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);
  await gotoDriversTab(page);
  await chooseDatabaseDriver(page);

  // 화면으로 만들 수 없는 변종(알 수 없는 연결)은 상태 주입 뒤 실제 저장 버튼을 누른다 — 저장 요청·오류 렌더는 실경로
  await page.evaluate(() => {
    (window as any).G7Core.state.setLocal(
      { form: { drivers: { queue_connections: ['database', 'nope'], queue_names: { database: ['default', 'bad name!'] } } }, hasChanges: true },
      { merge: 'deep' }
    );
  });

  const [saveResponse] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/admin\/settings$/.test(r.url())),
    page.locator('#save_button').click(),
  ]);
  expect(saveResponse.status()).toBe(422);
  const errors = (await saveResponse.json())?.errors ?? {};
  expect(errors['drivers.queue_connections.1']?.[0]).toBeTruthy();
  expect(errors['drivers.queue_names.database.1']?.[0]).toBeTruthy();

  await expect(page.locator('[data-testid="queue-connections-error"]')).toContainText(errors['drivers.queue_connections.1'][0]);
  await expect(page.locator('[data-testid="queue-names-error-database"]')).toContainText(errors['drivers.queue_names.database.1'][0]);
});

// @scenario screen=load_failed
// @effects queue_status_failure_notice
test('#139 - 칩을 고친 뒤 상태 새로 고침이 실패해도 실패 안내가 남는다', async ({ page }) => {
  test.setTimeout(120_000);
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);
  await gotoDriversTab(page);
  const savedDriver = await page.evaluate(() => (window as any).G7Core.state.getLocal().originalDrivers?.queue_driver ?? 'sync');
  test.skip(savedDriver !== 'sync', '저장된 큐 드라이버가 sync 일 때만 — 끝에 sync 로 되돌린다');

  const save = async (): Promise<number> => {
    await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });
    const [res] = await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/admin\/settings$/.test(r.url()), { timeout: 20_000 }),
      page.locator('#save_button').click(),
    ]);
    return res.status();
  };
  const chipInput = page.locator('[data-testid="queue-names-database"] input.tag-input__input');

  try {
    await test.step('데이터베이스 드라이버 + 연결 database 저장', async () => {
      await chooseDatabaseDriver(page);
      await page.evaluate(() => {
        (window as any).G7Core.state.setLocal({ form: { drivers: { queue_connections: ['database'] } }, hasChanges: true }, { merge: 'deep' });
      });
      expect(await save()).toBe(200);
      await expect(page.locator('[data-testid="queue-status-refresh"]')).toBeAttached({ timeout: 10_000 });
    });

    await test.step('칩 편집 → 상태 조회만 실패시키고 새로 고침', async () => {
      await chipInput.fill('qe2e');
      await chipInput.press('Enter');
      await page.route('**/api/admin/settings/queue-status', (route) => route.abort('failed'));
      await page.locator('[data-testid="queue-status-refresh"]').click();
      await expect(page.locator('[data-testid="queue-status-load-failed"]')).toBeVisible({ timeout: 10_000 });
    });

    await test.step('한 번 더 편집해도(입력 change 의 setState) 실패 안내가 남는다', async () => {
      await chipInput.fill('qe2e2');
      await chipInput.press('Enter');
      await expect(page.locator('[data-testid="queue-status-load-failed"]')).toBeVisible();
    });
  } finally {
    // 복구: 실제 드롭다운으로 「동기」를 골라 저장한다(큐 등록값은 저장 정규화가 비운다)
    await page.unroute('**/api/admin/settings/queue-status').catch(() => {});
    await page.reload();
    await gotoDriversTab(page);
    const card = page.locator('.admin-card', { has: page.locator('[data-testid="queue-worker-section"]') });
    await card.locator('button[aria-haspopup="listbox"]').first().click();
    await page.getByRole('option', { name: '동기' }).click();
    expect(await save()).toBe(200);
  }
});

// @scenario screen=fallback_count
// @effects queue_fallback_notice
test('#139 - 리스너가 등록 안 된 큐를 가리켜 기본값으로 보낸 횟수가 있으면 안내가 보인다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  // 저장값은 건드리지 않는다 — 상태 구역은 저장된 드라이버가 큐일 때만 그리므로 설정 조회 응답의 큐 드라이버만
  // database 로 바꿔 돌려주고, 상태 응답은 실제 서버 응답에 폴백 사례 하나를 얹는다(렌더 경로는 실경로).
  await page.route(/\/api\/admin\/settings(\?.*)?$/, async (route) => {
    if (route.request().method() !== 'GET') return route.continue();
    const res = await route.fetch();
    const body = await res.json();
    if (body?.data?.drivers) body.data.drivers.queue_driver = 'database';
    await route.fulfill({ response: res, json: body });
  });
  await page.route(/\/api\/admin\/settings\/queue-status(\?.*)?$/, async (route) => {
    const res = await route.fetch();
    const body = await res.json();
    body.data.placement = {
      ...(body.data.placement ?? {}),
      fallback: {
        count: 4,
        last: { listener: 'Vendor\\Foo\\NotifyListener', hook: 'vendor-foo.after_create', requested_connection: null, requested_queue: 'notifications', reasons: ['queue_unregistered'] },
      },
    };
    await route.fulfill({ response: res, json: body });
  });
  await gotoDriversTab(page);

  try {
    await page.locator('[data-testid="queue-status-refresh"]').click();
    const notice = page.locator('[data-testid="queue-fallback-notice"]');
    await expect(notice).toBeVisible({ timeout: 10_000 });
    await expect(notice).toContainText('4');
    await expect(page.locator('[data-testid="queue-fallback-last"]')).toContainText('Vendor\\Foo\\NotifyListener');
    await expect(page.locator('[data-testid="queue-fallback-last"]')).toContainText('notifications');
    expect(await notice.innerText()).not.toMatch(/\$t:|admin\.settings\./);
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  }
});
