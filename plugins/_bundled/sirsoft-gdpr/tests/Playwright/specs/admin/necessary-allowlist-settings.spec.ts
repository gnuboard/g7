/**
 * E2E: 관리자 환경설정 > 필수 저장 항목 — 허용목록 운영자 편집
 *
 * @scenario admin_gdpr_necessary_storage_allowlist_edit
 * @effects e2e_allowlist_cards_render_three_scopes, e2e_locked_chips_render_readonly, e2e_allowlist_tag_persists_after_reload, e2e_allowlist_reaches_inline_payload, e2e_allowlist_invalid_item_rejected
 *
 * 배경: 필수 저장 항목 허용목록이 플러그인 코드 상수에서 운영자 설정으로 옮겨졌다. 이 화면이
 * 실제로 저장까지 도달하지 못하면 결함은 조용하다 — 화면에는 칩이 보이는데 판정에는 반영되지
 * 않고, 그 사이트는 "설정이 저장되지 않는" 증상만 겪는다.
 *
 * 특히 인라인 페이로드(`G7Config.plugins['sirsoft-gdpr']`) 도달은 단위 테스트로 잡을 수 없다.
 * `defaults.json` 의 `frontend_schema` 에 `expose: true` 가 빠지면 저장은 성공하고 화면도
 * 정상인데 브라우저의 인터셉터만 빈 목록으로 서기 때문이다.
 *
 * 검증:
 *  1. 저장소별 카드 3개가 렌더되고 잠금 칩 행이 읽기 전용으로 표시된다
 *  2. 항목을 추가해 저장하면 200 이고, 새로고침 후에도 칩이 남는다
 *  3. 저장된 목록이 인라인 페이로드에 실려 브라우저에 도달한다 (+ 잠금 집합 동반)
 *  4. 형식 위반 항목은 422 로 거부되고 그 카드에 에러가 붙는다
 *
 * 종료 시 추가한 항목을 되돌린다 — PO 와 브라우저를 공유하므로 원상 복구는 의무다.
 */
import { test, expect, authenticatePage } from '../../fixtures/gdpr-auth';
import type { Page } from '@playwright/test';

const SETTINGS_PATH = '/admin/plugins/sirsoft-gdpr/settings';
const CARD = '#card_necessary_storage';
const SCOPES = ['localStorage', 'sessionStorage', 'cookie'] as const;
const PROBE_KEY = 'g7_e2e_allowlist_probe';

/** 관리자 GDPR 환경설정 진입 후 필수 저장 항목 카드까지 스크롤 */
async function gotoAllowlistCard(page: Page): Promise<void> {
  await page.goto(SETTINGS_PATH);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#settings_tab_navigation')).toBeAttached({ timeout: 20_000 });

  await expect(page.locator(CARD)).toBeAttached({ timeout: 20_000 });
  await page.locator(CARD).scrollIntoViewIfNeeded();
}

/** TagInput 에 항목 하나를 입력하고 Enter 로 칩을 만든다 */
async function addTag(page: Page, scope: string, value: string): Promise<void> {
  const input = page.locator(`#necessary_storage_card_${scope} input`).first();
  await expect(input).toBeAttached({ timeout: 10_000 });
  await input.click();
  await input.fill(value);
  await input.press('Enter');
}

/** 저장 버튼을 누르고 PUT 응답 상태 코드를 돌려준다 */
async function save(page: Page): Promise<number> {
  const pending = page.waitForResponse(
    (r) => r.request().method() === 'PUT'
      && /\/api\/plugins\/sirsoft-gdpr\/admin\/settings$/.test(new URL(r.url()).pathname),
    { timeout: 30_000 },
  );

  await page.locator('#footer_save_button').click();

  return (await pending).status();
}

/** 현재 저장된 허용목록을 API 로 읽는다 (원상 복구 판정용) */
async function readSavedAllowlist(page: Page): Promise<Record<string, string[]>> {
  return page.evaluate(async () => {
    const res = await fetch('/api/plugins/sirsoft-gdpr/admin/settings', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${window.localStorage.getItem('auth_token') ?? ''}`,
      },
    });
    const json = await res.json();

    return json?.data?.settings?.necessary_storage_allowlist ?? {};
  });
}

// @scenario scope=all, permitted=yes
// @effects e2e_allowlist_cards_render_three_scopes, e2e_locked_chips_render_readonly
test('필수 저장 항목 카드가 저장소별로 3개 렌더되고 잠금 칩이 읽기 전용으로 표시된다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoAllowlistCard(page);

  for (const scope of SCOPES) {
    await expect(page.locator(`#necessary_storage_card_${scope}`)).toBeVisible({ timeout: 10_000 });
  }

  // 잠금 항목이 있는 스코프(localStorage / cookie)는 잠금 칩 행을 그린다.
  const lockedLocal = page.locator('#necessary_storage_locked_localStorage');
  await expect(lockedLocal).toBeVisible({ timeout: 10_000 });
  await expect(lockedLocal).toContainText('auth_token');

  const lockedCookie = page.locator('#necessary_storage_locked_cookie');
  await expect(lockedCookie).toBeVisible();
  await expect(lockedCookie).toContainText('XSRF-TOKEN');
  await expect(lockedCookie).toContainText('gdpr_session');

  // 잠금 칩은 편집 가능한 칩과 시각적으로 구분되어야 한다 — 속성만이 아니라 표현까지.
  const chip = lockedLocal.locator('span', { hasText: 'auth_token' }).last();
  const style = await chip.evaluate((el) => {
    const s = getComputedStyle(el);

    return { cursor: s.cursor, opacity: Number(s.opacity) };
  });
  expect(style.cursor).toBe('not-allowed');
  expect(style.opacity).toBeLessThan(1);

  // 잠금 칩에는 삭제 버튼이 없다 (TagInput 칩의 X 버튼 부재).
  await expect(lockedLocal.locator('button')).toHaveCount(0);
});

// @scenario scope=localStorage, item=valid, permitted=yes
// @effects e2e_allowlist_tag_persists_after_reload, e2e_allowlist_reaches_inline_payload, allowlist_exposed_in_inline_payload_via_frontend_schema, locked_set_exposed_in_inline_payload
test('허용목록에 항목을 추가해 저장하면 새로고침 후에도 유지되고 인라인 페이로드에 실린다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoAllowlistCard(page);

  const before = await readSavedAllowlist(page);

  try {
    await addTag(page, 'localStorage', PROBE_KEY);
    await expect(page.locator('#necessary_storage_card_localStorage')).toContainText(PROBE_KEY);

    expect(await save(page)).toBe(200);

    // 새로고침 — 저장이 서버에 도달했는지, 화면이 그 값을 다시 그리는지.
    await gotoAllowlistCard(page);
    await expect(page.locator('#necessary_storage_card_localStorage')).toContainText(PROBE_KEY, {
      timeout: 15_000,
    });

    // 인라인 페이로드 도달 — frontend_schema 의 expose 가 빠지면 저장·화면은 정상인데
    // 브라우저의 인터셉터만 빈 목록으로 선다 (이 축이 그 유일한 관문이다).
    const inline = await page.evaluate(() => {
      const cfg = (window as unknown as {
        G7Config?: { plugins?: Record<string, Record<string, unknown>> };
      }).G7Config;
      const plugin = cfg?.plugins?.['sirsoft-gdpr'] ?? {};

      return {
        allowlist: plugin.necessary_storage_allowlist as Record<string, string[]> | undefined,
        locked: plugin.necessary_storage_locked as Record<string, string[]> | undefined,
      };
    });

    expect(inline.allowlist?.localStorage).toContain(PROBE_KEY);
    // 잠금 집합도 함께 실려야 판정이 '운영자 목록 ∪ 잠금' 으로 성립한다.
    expect(inline.locked?.localStorage).toContain('auth_token');
    expect(inline.locked?.cookie).toContain('XSRF-TOKEN');
  } finally {
    // 원상 복구 — 추가한 항목을 되돌린다.
    await page.evaluate(async ([original]) => {
      await fetch('/api/plugins/sirsoft-gdpr/admin/settings', {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${window.localStorage.getItem('auth_token') ?? ''}`,
        },
        body: JSON.stringify({ necessary_storage_allowlist: original }),
      });
    }, [before]);

    const restored = await readSavedAllowlist(page);
    expect(restored.localStorage ?? []).not.toContain(PROBE_KEY);
  }
});

// @scenario scope=cookie, item=invalid, permitted=yes
// @effects e2e_allowlist_invalid_item_rejected
test('형식 위반 항목은 422 로 거부되고 그 카드에 에러가 표시된다', async ({ page, privacyManageToken }) => {
  await authenticatePage(page, privacyManageToken);
  await gotoAllowlistCard(page);

  await addTag(page, 'cookie', 'bad key!');

  expect(await save(page)).toBe(422);

  // 에러 키는 `necessary_storage_allowlist.cookie.{index}` 형태여야 그 카드에 붙는다.
  const card = page.locator('#necessary_storage_card_cookie');
  await expect(card).toHaveClass(/border-red-500/, { timeout: 10_000 });
  await expect(card.locator('.form-error')).toBeVisible();
});
