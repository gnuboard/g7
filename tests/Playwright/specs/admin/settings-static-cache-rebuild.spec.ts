/**
 * E2E: 환경설정 > 일반 — 초기 화면 정적 파일 상태 카드 + 지금 다시 만들기 (#651 D4·D7)
 *
 * 시나리오 매니페스트: tests/scenarios/static-cache-admin-rebuild.yaml — 마킹은 각 테스트의
 * scenario(k=v 조합)·effects 주석이 담당한다.
 *
 * 배경: 확장 캐시 버전이 만료로 재생성되지 않으므로(영구 번호) 재게시 누락은 무기한 stale 이 된다.
 * 관리자 화면의 [지금 다시 만들기] 가 그 안전망이다 — 상태를 보고, 확인 모달을 거쳐, POST 하고,
 * 토스트와 카드 재조회로 결과를 확인한다.
 *
 * 환경 가드: 대상 사이트가 운영 모드(production)가 아니면 카드는 「개발 모드」 배지와 비활성 버튼을
 * 보인다 — 그 경우 재게시 케이스는 skip 하고 비활성 계약만 잠근다.
 */
import { test, expect, issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';

/** 관리자 환경설정 일반 탭 진입 + 상태 카드 도착 대기 */
async function gotoGeneralTab(page: import('@playwright/test').Page): Promise<void> {
  // 배지 문구는 데이터소스 도착 전에는 비어 있다 — 상태 GET 응답을 기다려야 publishable 판정이 실제 값이 된다
  const statusArrived = page.waitForResponse(
    (r) => r.request().method() === 'GET' && /\/api\/admin\/settings\/static-cache$/.test(new URL(r.url()).pathname),
    { timeout: 30_000 }
  );
  await page.goto('/admin/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#card_static_cache')).toBeAttached({ timeout: 20_000 });
  await statusArrived;
  await expect(page.locator('#static_cache_status_badge')).not.toHaveText('', { timeout: 20_000 });
}

/** 카드가 「게시됨/미게시」(publishable) 상태인지 — 운영 모드 + kill-switch 켜짐 */
async function isPublishable(page: import('@playwright/test').Page): Promise<boolean> {
  const text = (await page.locator('#static_cache_status_badge').textContent()) ?? '';
  return /게시됨|미게시|Published|Not published/.test(text);
}

// @scenario permission=granted, publishable=production_enabled, outcome=success
// @effects card_renders_status_rows
test('@smoke #651 - 일반 탭에 초기 화면 정적 파일 카드가 상태 행과 함께 렌더된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  const statusResponse = page.waitForResponse(
    (r) => r.request().method() === 'GET' && /\/api\/admin\/settings\/static-cache$/.test(new URL(r.url()).pathname),
    { timeout: 20_000 }
  );

  await gotoGeneralTab(page);

  const response = await statusResponse;
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.success).toBe(true);
  expect(body.data).toHaveProperty('publishable');
  expect(body.data).toHaveProperty('version');

  // 카드는 에셋 서빙 카드 다음에 있고, 행 텍스트에 다국어 키가 노출되지 않는다
  await expect(page.locator('#card_asset_serving')).toBeAttached();
  const cardText = (await page.locator('#card_static_cache').textContent()) ?? '';
  expect(cardText).not.toContain('$t:');
  expect(cardText).not.toContain('admin.settings');

  await expect(page.locator('#static_cache_version_row')).toBeAttached();
  await expect(page.locator('#static_cache_files_row')).toBeAttached();
  await expect(page.locator('#static_cache_published_at_row')).toBeAttached();
  await expect(page.locator('#static_cache_process_user_row')).toBeAttached();
});

// @scenario permission=granted, publishable=non_production, outcome=success
// @effects republish_button_disabled_when_not_publishable
test('#651 - 게시가 쓰이지 않는 환경이면 버튼이 비활성이고 사유 힌트가 있다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  const button = page.locator('#static_cache_republish_button');
  await expect(button).toBeAttached();

  if (await isPublishable(page)) {
    // 운영 모드 사이트 — 버튼은 활성이어야 하고 힌트는 없다 (비활성 분기는 단위 테스트가 덮는다)
    await expect(button).toBeEnabled();
    await expect(page.locator('#static_cache_not_publishable_hint')).toHaveCount(0);
    return;
  }

  await expect(button).toBeDisabled();
  await expect(button).toHaveAttribute('aria-disabled', 'true');
  await expect(page.locator('#static_cache_not_publishable_hint')).toBeAttached();
});

// @scenario permission=granted, publishable=production_enabled, outcome=success
// @effects confirm_modal_then_toast_and_refetch
test('#651 - 지금 다시 만들기 → 확인 모달 → POST 200 → 토스트 + 카드 재조회', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);
  test.skip(!(await isPublishable(page)), '대상 사이트가 운영 모드가 아니어서 재게시를 수행할 수 없다 (비활성 계약은 별도 케이스가 잠근다)');

  const versionBefore = ((await page.locator('#static_cache_version_row').textContent()) ?? '').replace(/\D/g, '');

  await page.locator('#static_cache_republish_button').click();
  await expect(page.locator('#static_cache_republish_confirm_button')).toBeVisible({ timeout: 10_000 });

  const republish = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/api\/admin\/settings\/static-cache\/republish$/.test(new URL(r.url()).pathname),
    { timeout: 300_000 }
  );
  const refetch = page.waitForResponse(
    (r) => r.request().method() === 'GET' && /\/api\/admin\/settings\/static-cache$/.test(new URL(r.url()).pathname),
    { timeout: 300_000 }
  );

  await page.locator('#static_cache_republish_confirm_button').click();

  const response = await republish;
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.success).toBe(true);
  expect(body.data.republished).toBe(true);
  expect(Number(body.data.version)).toBeGreaterThan(Number(versionBefore));

  // 상태 재조회가 뒤따르고 모달이 닫힌다
  expect((await refetch).status()).toBe(200);
  await expect(page.locator('#static_cache_republish_confirm_button')).toBeHidden({ timeout: 10_000 });

  // 카드에 새 버전이 반영된다
  await expect
    .poll(async () => ((await page.locator('#static_cache_version_row').textContent()) ?? '').replace(/\D/g, ''), { timeout: 20_000 })
    .toBe(String(body.data.version));

  // 토스트 문구가 키 그대로 새어 나가지 않는다
  const toast = page.locator('[role="alert"], [role="status"], .toast').filter({ hasText: /다시 만들|rebuilt/ }).first();
  await expect(toast).toBeVisible({ timeout: 10_000 });
});

// @scenario permission=denied, publishable=production_enabled, outcome=success
// @effects republish_denied_without_settings_update_permission
test('#651 - 읽기 권한만 있으면 상태는 보이지만 재게시 POST 는 403 이다', async ({ page }) => {
  const token = issueScopedToken('core.settings.read');
  await authenticatePage(page, token);

  await gotoGeneralTab(page);

  // 읽기 전용 계정에는 버튼이 비활성으로 렌더된다 (isReadOnly)
  await expect(page.locator('#static_cache_republish_button')).toBeDisabled();

  // 서버 경계 — 화면 비활성과 무관하게 API 자체가 거부한다
  const status = await page.evaluate(async (t) => {
    const r = await fetch('/api/admin/settings/static-cache/republish', {
      method: 'POST',
      headers: { Authorization: `Bearer ${t}`, Accept: 'application/json' },
    });
    return { status: r.status, body: await r.json().catch(() => null) };
  }, token);

  expect(status.status).toBe(403);
  expect(status.body?.success).toBe(false);
  expect(JSON.stringify(status.body)).not.toContain('settings.');
});
