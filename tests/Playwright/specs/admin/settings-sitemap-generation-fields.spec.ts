/**
 * E2E: 환경설정 > SEO/고급 — Sitemap 생성 설정 칸 (#481)
 *
 * @scenario admin_settings_sitemap_generation_fields
 * @effects fields_mounted, fields_persisted, regenerate_is_async
 *
 * 배경: 대용량 사이트에서 sitemap 을 여러 파일로 나눠 생성하도록 분할 기준/압축 설정을
 * 추가하고, 관리자 "지금 생성" 버튼을 즉시 실행에서 큐 예약으로 바꿨다. 설정 칸이 실제로
 * 마운트·저장되는지, 재생성 버튼이 응답을 기다리며 멈추지 않는지 브라우저에서 확인한다.
 *
 * 검증:
 *  1. SEO 탭에 분할 기준/압축 칸이 번역문과 함께 마운트된다
 *  2. 분할 기준을 바꿔 저장하면 422 없이 성공하고 재진입 시 유지된다
 *  3. 고급 탭에 Sitemap 캐시 기준값 칸이 마운트되고 기본값(86400)이 저장된다
 *  4. "지금 생성" 클릭이 즉시 응답을 받는다 (동기 생성이면 대용량에서 타임아웃)
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

// 이 파일의 테스트 다수가 **같은 관리자 설정 화면에 실제로 저장**한다(SEO 탭 sitemap 설정).
// 전역 `fullyParallel: true` 아래서는 같은 파일 안 테스트도 워커에 흩어져 동시 실행되고, 서로의
// 저장 상태를 덮어써 간헐 실패한다(실측: admin 디렉토리 병렬 실행에서 "분할 기준 저장" 30초 타임아웃).
// `mode: 'default'` 는 이 파일만 단일 워커 순차 실행으로 되돌린다 — `serial` 과 달리 앞 테스트가
// 실패해도 뒤 테스트를 건너뛰지 않는다.
test.describe.configure({ mode: 'default' });

const URLS_PER_FILE = '#field_sitemap_urls_per_file';
const GZIP_ROW = '#field_sitemap_gzip';
const HREFLANG_ROW = '#field_sitemap_hreflang_enabled';
const ADVANCED_SITEMAP_CACHE = '#cache_seo_sitemap';

/** 관리자 환경설정 SEO 탭 진입 */
async function gotoSeoTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=seo');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(URLS_PER_FILE)).toBeAttached({ timeout: 20_000 });
}

/** 관리자 환경설정 고급 탭 진입 */
async function gotoAdvancedTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=advanced');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(ADVANCED_SITEMAP_CACHE)).toBeAttached({ timeout: 20_000 });
}

// @scenario tab=seo, permitted=yes
// @effects fields_mounted
test('@smoke #481 - SEO 탭에 Sitemap 분할 기준/압축 칸이 번역문과 함께 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSeoTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const urlsPerFile = page.locator(URLS_PER_FILE);
  await expect(urlsPerFile).toBeAttached();

  // 다국어 키 미해석 회귀 가드
  const urlsText = (await urlsPerFile.innerText()).trim();
  expect(urlsText).not.toContain('$t:');
  expect(urlsText.length).toBeGreaterThan(0);

  // 프로토콜 상한이 입력 단계에서 그대로 반영된다
  const input = urlsPerFile.locator('input[type="number"]').first();
  await expect(input).toHaveAttribute('max', '50000');
  await expect(input).toHaveAttribute('min', '1000');

  const gzipRow = page.locator(GZIP_ROW);
  await expect(gzipRow).toBeAttached();
  expect((await gzipRow.innerText()).trim()).not.toContain('$t:');
  await expect(gzipRow.locator('input[type="checkbox"]').first()).toBeAttached();

  const hreflangRow = page.locator(HREFLANG_ROW);
  await expect(hreflangRow).toBeAttached();
  expect((await hreflangRow.innerText()).trim()).not.toContain('$t:');
  await expect(hreflangRow.locator('input[type="checkbox"]').first()).toBeAttached();
});

// @scenario tab=seo, permitted=yes
// @effects fields_persisted
test('#481 - hreflang 토글을 바꿔 저장하면 성공하고 재진입 시 유지된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSeoTab(page);

  const toggle = page.locator(`${HREFLANG_ROW} input[type="checkbox"]`).first();
  await expect(toggle).toBeAttached({ timeout: 15_000 });
  // 체크박스 input 은 sr-only(화면에서 숨김)이고 클릭 대상은 wrapper div(onClick) 이다.
  // input 을 직접 클릭하면 toggle-switch-track 오버레이가 포인터 이벤트를 가로챈다.
  const toggleSwitch = page.locator(`${HREFLANG_ROW} .toggle-switch-wrapper`).first();

  const wasChecked = await toggle.isChecked();

  // 토글을 반대 상태로 바꾼다
  await toggleSwitch.click();
  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });

  const save = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();

  // 신규 boolean 키가 검증 규칙에 없으면 422 로 떨어진다 (배관 누락 회귀 가드)
  expect((await save).status()).toBe(200);

  await gotoSeoTab(page);
  const reloaded = page.locator(`${HREFLANG_ROW} input[type="checkbox"]`).first();
  await expect.poll(() => reloaded.isChecked(), { timeout: 15_000 }).toBe(!wasChecked);

  // 원상 복구 (E2E 는 실제 환경 설정을 건드린다)
  await page.locator(`${HREFLANG_ROW} .toggle-switch-wrapper`).first().click();
  const restore = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();
  expect((await restore).status()).toBe(200);
});

// @scenario tab=seo, permitted=yes
// @effects fields_persisted
test('#481 - 분할 기준을 바꿔 저장하면 성공하고 재진입 시 유지된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSeoTab(page);

  const input = page.locator(`${URLS_PER_FILE} input[type="number"]`).first();

  // 폼이 저장된 설정으로 채워진 뒤에 수정해야 한다 (로드 전 입력은 다른 필드가 빈 채로 전송됨)
  await expect.poll(() => input.inputValue(), { timeout: 15_000 }).not.toBe('');

  const original = await input.inputValue();
  const next = original === '30000' ? '40000' : '30000';

  await input.fill(next);
  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });

  const save = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();

  // 신규 키가 검증 규칙에 없으면 422 로 떨어진다 (배관 누락 회귀 가드)
  expect((await save).status()).toBe(200);

  await gotoSeoTab(page);
  const reloaded = page.locator(`${URLS_PER_FILE} input[type="number"]`).first();
  await expect.poll(() => reloaded.inputValue(), { timeout: 15_000 }).toBe(next);

  // 원상 복구 (E2E 는 실제 환경 설정을 건드린다)
  await reloaded.fill(original);
  const restore = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();
  expect((await restore).status()).toBe(200);
});

// @scenario tab=advanced, permitted=yes
// @effects fields_mounted, fields_persisted
test('#481 - 고급 탭 Sitemap 캐시 기준값 칸이 마운트되고 기본값이 저장된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoAdvancedTab(page);

  const row = page.locator(ADVANCED_SITEMAP_CACHE);
  await expect(row).toBeAttached();
  expect((await row.innerText()).trim()).not.toContain('$t:');

  const input = row.locator('input[type="number"]').first();
  await expect(input).toBeAttached();

  // 폼이 저장된 설정으로 채워진 뒤에 수정해야 한다 (로드 전 입력은 다른 필드가 빈 채로 전송됨)
  await expect.poll(() => input.inputValue(), { timeout: 15_000 }).not.toBe('');

  const original = await input.inputValue();
  // 기본값 86400 은 형제 칸 규칙(최대 14400)을 재사용하면 저장 자체가 막힌다
  const next = original === '86400' ? '43200' : '86400';

  await input.fill(next);
  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });

  const save = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();

  expect((await save).status()).toBe(200);

  // 원상 복구 (E2E 는 실제 환경 설정을 건드린다)
  await gotoAdvancedTab(page);
  const restored = page.locator(`${ADVANCED_SITEMAP_CACHE} input[type="number"]`).first();
  await expect.poll(() => restored.inputValue(), { timeout: 15_000 }).toBe(next);
  await restored.fill(original);
  const restore = page.waitForResponse(
    (r) => r.url().includes('/api/admin/settings') && r.request().method() === 'POST',
    { timeout: 20_000 }
  );
  await page.locator('#save_button').click();
  expect((await restore).status()).toBe(200);
});

// @scenario tab=seo, permitted=yes
// @effects regenerate_is_async, progress_visible
test('#481 - "지금 생성" 클릭이 큐에 예약되고 진행상황이 화면에 표시된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoSeoTab(page);

  const regenerate = page.waitForResponse(
    (r) => r.url().includes('/api/admin/seo/sitemap/regenerate'),
    { timeout: 20_000 }
  );

  const startedAt = Date.now();
  // 재생성 버튼은 진행 중(queued/running/writing)이면 disabled 다.
  // 재생성은 큐로 위임되므로 **큐 워커가 돌지 않는 환경**에서는 상태가 `queued` 로 멈춰
  // 버튼이 영원히 disabled 다(진행상황 캐시 TTL 1시간). 코드 결함이 아니라 실행 환경
  // 전제이므로, 그런 환경에서는 이 테스트를 건너뛴다.
  const regenerateButton = page.locator('#sitemap_last_updated_block button').first();
  const clickable = await regenerateButton
    .waitFor({ state: 'attached', timeout: 10_000 })
    .then(() => regenerateButton.isEnabled())
    .catch(() => false);
  test.skip(!clickable, '이전 sitemap 재생성이 queued 로 남아 있다 — 큐 워커 미가동 환경');
  await regenerateButton.click();
  const response = await regenerate;
  const elapsed = Date.now() - startedAt;

  expect(response.status()).toBe(200);
  // 예약 즉시 진행상황이 'queued' 로 실려 온다 (동기 생성이 아니라 큐 예약)
  expect((await response.json())?.data?.progress?.status).toBe('queued');

  // 동기 생성으로 되돌아가면 응답 시간이 데이터 규모에 비례해 늘어난다.
  // 절대 하한 대신 "예약 응답이라면 당연히 만족하는" 상한으로 회귀만 잡는다.
  expect(elapsed).toBeLessThan(10_000);

  // 진행상황 블록이 화면에 나타나고 상태 배지가 미해석 키가 아닌 실제 문구로 렌더된다
  const progressBlock = page.locator('#sitemap_progress_block');
  await expect(progressBlock).toBeAttached({ timeout: 15_000 });
  const progressText = (await progressBlock.innerText()).trim();
  expect(progressText).not.toContain('$t:');
  expect(progressText.length).toBeGreaterThan(0);
});

// @scenario tab=seo, permitted=yes
// @effects status_endpoint
test('#481 - SEO 탭 진입 시 sitemap 상태 API 가 진행상황/실시간 여부를 반환한다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  const status = page.waitForResponse(
    (r) => r.url().includes('/api/admin/seo/sitemap/status') && r.request().method() === 'GET',
    { timeout: 20_000 }
  );

  await gotoSeoTab(page);

  const res = await status;
  expect(res.status()).toBe(200);
  const body = await res.json();
  // 상태 응답 스키마: 진행상황(progress) + 실시간 연결 가능 여부(realtime_enabled)
  expect(body?.data).toHaveProperty('realtime_enabled');
  expect(body?.data).toHaveProperty('progress');
  expect(body?.data).toHaveProperty('last_updated_at');
});

// @scenario tab=seo, permitted=yes
// @effects regenerate_is_async
test('#481 - 재생성 후 실시간/폴링 모드에 맞게 진행상황이 갱신된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  // 상태(GET) 응답을 가로채 실시간 여부 판정 + 재조회 횟수 카운트 (앱의 인증 요청 기준)
  let realtimeEnabled: boolean | null = null;
  let statusGets = 0;
  page.on('response', async (r) => {
    if (r.url().includes('/api/admin/seo/sitemap/status') && r.request().method() === 'GET') {
      statusGets += 1;
      if (realtimeEnabled === null) {
        try { realtimeEnabled = (await r.json())?.data?.realtime_enabled === true; } catch { /* ignore */ }
      }
    }
  });

  await gotoSeoTab(page);
  await expect.poll(() => realtimeEnabled, { timeout: 20_000 }).not.toBeNull();

  // 여기부터 재생성 이후의 폴링만 센다
  statusGets = 0;
  // 재생성 버튼은 진행 중(queued/running/writing)이면 disabled 다.
  // 재생성은 큐로 위임되므로 **큐 워커가 돌지 않는 환경**에서는 상태가 `queued` 로 멈춰
  // 버튼이 영원히 disabled 다(진행상황 캐시 TTL 1시간). 코드 결함이 아니라 실행 환경
  // 전제이므로, 그런 환경에서는 이 테스트를 건너뛴다.
  const regenerateButton = page.locator('#sitemap_last_updated_block button').first();
  const clickable = await regenerateButton
    .waitFor({ state: 'attached', timeout: 10_000 })
    .then(() => regenerateButton.isEnabled())
    .catch(() => false);
  test.skip(!clickable, '이전 sitemap 재생성이 queued 로 남아 있다 — 큐 워커 미가동 환경');
  await regenerateButton.click();
  await page.waitForResponse((r) => r.url().includes('/api/admin/seo/sitemap/regenerate'), { timeout: 20_000 });

  const pollingMode = realtimeEnabled === false;

  // 8초 관찰
  await page.waitForTimeout(8_000);

  if (pollingMode) {
    // Reverb OFF: startInterval 폴링이 상태를 반복 재조회해야 한다.
    // 회귀(폴링 미시작) 시 재생성 직후 refetch 1회로 멈춰 배지가 갱신되지 않는다.
    expect(statusGets).toBeGreaterThanOrEqual(2);
  } else {
    // 실시간(WebSocket) 모드면 폴링하지 않는다 — 재생성 직후 refetch 수준
    expect(statusGets).toBeLessThanOrEqual(2);
  }
});

// @scenario tab=seo, transition_overlay=wait_for
// @effects sitemap_status_awaited_before_reveal
//
// 측정 방식 정정 (2026-07-30 실측):
//
//   이 테스트는 원래 "`#sitemap_last_updated_block` 이 보이는 시점 = 오버레이가 걷힌 시점" 을
//   전제로 블록 가시성 시각을 쟀다. 그 전제가 틀렸다 — 전환 오버레이는 타겟 **안에**
//   `#g7-skeleton-overlay` 를 덧붙이는 방식이고 기존 콘텐츠는 DOM 에 그대로 남는데,
//   Playwright 의 `toBeVisible()` 은 가림(occlusion)을 보지 않으므로 덮여 있어도 visible 로 판정한다.
//
//   오버레이 **컨테이너**(`#g7-skeleton-overlay`)를 관측 대상으로 삼는 것도 불안정했다. 그것은
//   타겟 안에 있어 렌더가 타겟을 갈아치울 때마다 사라졌다 재부착된다 — 하드 진입은 렌더 **전**
//   단계라 타겟도 폴백도 아직 없어 `#app` 로 3단계 폴백되는데, 곧이어 첫 렌더가 `#app` 을 갈아치워
//   40ms 표본에 한 번도 잡히지 않는다(실측: appendChild 후킹으로 `#app` 2회 부착만 확인됨).
//   대조군으로 `/admin/users` 하드 진입도 동일함을 확인했다 — SEO 탭 고유 문제가 아니라 하드 진입
//   일반의 동작이다.
//
//   그래서 두 가지를 바꿨다.
//     ① 진입 경로를 **SPA 전환**으로 (실제 사용자 흐름도 탭 전환이라 가드 의도에도 맞다)
//     ② 관측 대상을 `<head>` 의 `#g7-skeleton-overlay-style` 로. 이 스타일 엘리먼트는 렌더 영향을
//        받지 않고, 오버레이 해제(`hideTransitionOverlay`)가 이것을 명시적으로 제거한다 —
//        "오버레이가 살아 있는가" 의 안정적인 신호다.
test('#481 - SEO 탭 콘텐츠 노출 시점에 sitemap 상태가 이미 채워져 있다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  // 오버레이 컨테이너의 존재 여부를 페이지 안에서 촘촘히 표본한다.
  //
  // 제거 시각을 `Element.remove` 후킹으로 잡으면 놓친다 — 첫 렌더가 `#app` 을 통째로 교체할 때는
  // 그 API 를 거치지 않고 사라진다. 부착은 appendChild 로 확실히 잡고, 사라짐은 "마지막으로
  // 보였던 시각(lastSeenAt)" 으로 판정한다. 부팅 중 잠깐 사라졌다 재부착되는 구간이 있으므로
  // (렌더가 지운 뒤 reattachSpinnerOverlay 가 다시 붙임) 단발 부재가 아니라 lastSeenAt 을 쓴다.
  await page.addInitScript(() => {
    (window as any).__overlay = { mountedAt: 0, lastSeenAt: 0 };
    // 오버레이 컨테이너(`#g7-skeleton-overlay`)는 타겟 안에 있어 렌더가 타겟을 갈아치우면 함께
    // 사라졌다가 재부착된다 — 표본으로 잡으면 있다가 없다가 한다. 반면 스타일 엘리먼트는
    // `<head>` 에 있어 렌더 영향을 받지 않고, 오버레이 해제(`hideTransitionOverlay`)가 이것을
    // 명시적으로 제거한다. 그래서 "오버레이가 살아 있는가" 의 안정적인 신호는 이쪽이다.
    const STYLE_ID = 'g7-skeleton-overlay-style';
    const origAppend = Node.prototype.appendChild;
    Node.prototype.appendChild = function <T extends Node>(this: Node, node: T): T {
      if ((node as unknown as Element)?.id === STYLE_ID) {
        (window as any).__overlay.mountedAt ||= Date.now();
      }

      return origAppend.call(this, node) as T;
    };
    setInterval(() => {
      if (document.getElementById(STYLE_ID)) {
        (window as any).__overlay.lastSeenAt = Date.now();
      }
    }, 50);
  });

  // 상태 API 를 의도적으로 지연시켜, 전환 오버레이가 이 데이터소스를 기다리는지 드러낸다.
  // wait_for 에 sitemap_status 가 빠지면 오버레이가 먼저 걷히고 빈 상태로 노출된다.
  let statusResolvedAt = 0;
  await page.route(
    (url) => url.pathname.endsWith('/api/admin/seo/sitemap/status'),
    async (route) => {
      await new Promise((r) => setTimeout(r, 1_500));
      statusResolvedAt = Date.now();
      await route.continue().catch(() => undefined);
    },
  );

  // 다른 탭으로 먼저 들어간 뒤 SPA 전환으로 SEO 탭에 진입한다 (위 주석 참조).
  await page.goto('/admin/settings?tab=general');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(() => !!(window as any).G7Core?.dispatch, { timeout: 30_000 });
  await page.waitForTimeout(3_000);
  // 진입 전 상태를 초기화해 SEO 탭 전환 구간만 측정한다.
  await page.evaluate(() => ((window as any).__overlay = { mountedAt: 0, lastSeenAt: 0 }));

  await page.evaluate(() => {
    (window as any).G7Core.dispatch({
      handler: 'navigate',
      params: { path: '/admin/settings', query: { tab: 'seo' } },
    });
  });

  // 오버레이가 실제로 걸렸는지 먼저 확인한다 — 걸리지 않았다면 이 가드는 아무것도 측정하지 못한다.
  await expect
    .poll(() => page.evaluate(() => (window as any).__overlay?.mountedAt ?? 0), {
      message: '전환 오버레이가 한 번도 마운트되지 않았다 — wait_for 가드가 동작하지 않는 상태',
      timeout: 20_000,
    })
    .toBeGreaterThan(0);

  // 지연시킨 상태 응답이 실제로 도착할 때까지 기다린다.
  await expect
    .poll(() => statusResolvedAt, { message: 'sitemap 상태 응답이 오지 않았다', timeout: 20_000 })
    .toBeGreaterThan(0);

  // 판정: **상태 응답이 도착한 그 시점에 오버레이가 아직 떠 있었는가**.
  //
  // "오버레이가 완전히 걷히는 시각" 으로 재지 않는다 — 사이트맵 생성이 `queued` 로 남아 있는
  // 환경(큐 워커 미가동)에서는 `sitemap_status.onSuccess` 가 3초 폴링을 걸고, 그 refetch 가
  // `wait_for` 대상이라 오버레이를 매번 다시 띄운다. 그러면 오버레이가 영영 걷히지 않아 측정
  // 자체가 불가능하다(실측: 40초 내내 유지). 이 순환은 환경 조건이지 가드 대상이 아니다.
  //
  // `wait_for` 에서 sitemap_status 가 빠지면 오버레이가 1.5초 지연 응답보다 먼저 걷히므로
  // `lastSeenAt < statusResolvedAt` 이 되어 실패한다 — 가드 의도는 그대로 유지된다.
  const lastSeenAt = await page.evaluate(() => (window as any).__overlay.lastSeenAt as number);
  expect(
    lastSeenAt,
    '상태 응답이 도착하기 전에 전환 오버레이가 걷혔다 — wait_for 가 이 데이터소스를 기다리지 않는다',
  ).toBeGreaterThanOrEqual(statusResolvedAt - 100);

  // 노출된 내용이 빈 껍데기가 아니어야 한다 (미해석 바인딩/빈 문자열 회귀 가드)
  const statusBlock = page.locator('#sitemap_last_updated_block');
  await expect(statusBlock).toBeVisible({ timeout: 15_000 });
  const text = (await statusBlock.innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text.length).toBeGreaterThan(0);
});
