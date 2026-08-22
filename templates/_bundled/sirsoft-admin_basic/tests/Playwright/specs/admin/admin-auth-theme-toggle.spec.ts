/**
 * E2E: 미인증 관리자 화면(로그인·비밀번호 찾기·비밀번호 재설정)의 테마 버튼 (dev-g7#640)
 *
 * @scenario screen=admin_login|admin_forgot_password|admin_reset_password, theme=light|dark|auto
 *
 * 배경: 세 화면의 테마 버튼이 `setTheme` 을 `params.theme` 로 호출했으나 템플릿 핸들러는
 * `action.target` 만 읽는다. 엔진에 상호 폴백이 없어 클릭이 콘솔 경고 한 줄만 남기고
 * 아무 것도 하지 않았다 — 예외도 실패한 요청도 없어 화면상 원인이 보이지 않는다.
 * 로그인 이후 화면은 핸들러를 경유하지 않는 ThemeToggle 컴포지트를 쓰므로 정상이었다.
 *
 * 이 spec 은 클릭 한 번으로 `data-theme` 속성 · `dark` 클래스 · `localStorage` 세 값이
 * 함께 바뀌는지를 세 화면 × 세 버튼(9변종) 전부에 대해 잰다.
 *
 * ## 저장 축이 여기 함께 있는 이유 (실측 2026-09-03)
 *
 * 이 결함을 고치는 과정에서 **두 번째 독립 결함**이 드러났다. sirsoft-gdpr 플러그인의
 * 스토리지 인터셉터가 `Storage.prototype.setItem` 을 감싸고 functional 동의 전에는
 * strictly-necessary 허용목록 밖 키의 쓰기를 조용히 버리는데, `g7_color_scheme` 이 그
 * 목록에서 빠져 있었다. 그래서 테마를 바꿔도 새로고침하면 되돌아갔고, 증상이 미인증
 * 화면뿐 아니라 관리자 화면 전체에 나타났다.
 *
 * 그 키를 언어 설정(`g7_locale`)과 같은 필수 항목으로 재분류해 함께 고쳤으므로, 저장과
 * 새로고침 영속까지 이 spec 이 잰다. 두 결함 중 하나만 되돌아가도 여기가 붉어진다.
 *
 * 규율: `check()` 를 쓰지 않고 `click()` + 단언을 분리한다. `_global.theme` 은 `page.goto`
 * 로 초기화되므로 E2E 단언에 넣지 않는다.
 */
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/** 테마 버튼의 `title` 은 `$t:admin.theme.*` — config 가 로케일을 ko 로 고정한다. */
const THEME_BUTTON_TITLE = {
  light: '라이트 모드',
  dark: '다크 모드',
  auto: '시스템 설정',
} as const;

type ThemeMode = keyof typeof THEME_BUTTON_TITLE;

const SCREENS: Array<[string, string]> = [
  ['로그인', '/admin/login'],
  ['비밀번호 찾기', '/admin/forgot-password'],
  // 토큰 없이도 레이아웃은 렌더된다 (init_actions 에 토큰 가드 없음).
  ['비밀번호 재설정', '/admin/reset-password'],
];

/** 화면 진입 — 테마 세그먼트 컨트롤이 그려질 때까지 기다린다. */
async function gotoScreen(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(`button[title="${THEME_BUTTON_TITLE.dark}"]`)).toBeVisible({
    timeout: 20_000,
  });
}

/** DOM 표현 2종 + 저장값을 한 번에 채집한다. */
async function readThemeState(page: Page) {
  return await page.evaluate(() => ({
    dataTheme: document.documentElement.getAttribute('data-theme'),
    darkClass: document.documentElement.classList.contains('dark'),
    stored: window.localStorage.getItem('g7_color_scheme'),
  }));
}

async function clickTheme(page: Page, mode: ThemeMode): Promise<void> {
  await page.locator(`button[title="${THEME_BUTTON_TITLE[mode]}"]`).click();
}

test.describe('미인증 관리자 화면 테마 버튼', () => {
  for (const [label, path] of SCREENS) {
    test(`${label} 화면 — 다크/라이트 전환이 화면과 저장값에 즉시 반영된다`, async ({ page }) => {
      await gotoScreen(page, path);

      await clickTheme(page, 'dark');
      await expect
        .poll(async () => (await readThemeState(page)).dataTheme, { timeout: 10_000 })
        .toBe('dark');
      const afterDark = await readThemeState(page);
      expect(afterDark.darkClass).toBe(true);
      expect(afterDark.stored).toBe('dark');

      await clickTheme(page, 'light');
      await expect
        .poll(async () => (await readThemeState(page)).dataTheme, { timeout: 10_000 })
        .toBe('light');
      const afterLight = await readThemeState(page);
      expect(afterLight.darkClass).toBe(false);
      expect(afterLight.stored).toBe('light');
    });

    test(`${label} 화면 — 자동(시스템 설정) 버튼이 시스템 모드로 해석된다`, async ({ page }) => {
      await gotoScreen(page, path);

      // 먼저 dark 를 걸어 두고 auto 로 되돌아오는지 본다 (초기값과 구분).
      await clickTheme(page, 'dark');
      await expect
        .poll(async () => (await readThemeState(page)).dataTheme, { timeout: 10_000 })
        .toBe('dark');

      await clickTheme(page, 'auto');
      // auto 는 prefers-color-scheme 으로 해석된다 — 이 실행 환경은 light 다.
      await expect
        .poll(async () => (await readThemeState(page)).stored, { timeout: 10_000 })
        .toBe('auto');
      const state = await readThemeState(page);
      expect(state.dataTheme).toBe('light');
      expect(state.darkClass).toBe(false);
    });
  }

  // 두 결함(핸들러 계약 · GDPR 허용목록)이 모두 고쳐져야만 통과한다.
  // 계약이 되돌아가면 클릭이 no-op 이 되고, 허용목록이 되돌아가면 저장이 버려진다.
  test('로그인 화면 — 다크 설정이 새로고침 뒤에도 유지된다 (동의 없는 첫 방문)', async ({ page }) => {
    test.setTimeout(90_000);
    await gotoScreen(page, '/admin/login');

    await clickTheme(page, 'dark');
    await expect
      .poll(async () => (await readThemeState(page)).stored, { timeout: 10_000 })
      .toBe('dark');

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect
      .poll(async () => (await readThemeState(page)).dataTheme, { timeout: 20_000 })
      .toBe('dark');
    const restored = await readThemeState(page);
    expect(restored.darkClass).toBe(true);
    expect(restored.stored).toBe('dark');
  });

  // 대조군 — 허용목록이 통째로 열린 것이 아님을 확인한다.
  // 이 단언이 없으면 "테마가 저장된다" 가 게이트 무력화로도 성립해 버린다.
  test('로그인 화면 — 허용목록 밖 키는 여전히 동의 전 차단된다', async ({ page }) => {
    await gotoScreen(page, '/admin/login');

    const accepted = await page.evaluate(() => {
      window.localStorage.setItem('e2e_non_allowlisted_probe', 'x');
      return window.localStorage.getItem('e2e_non_allowlisted_probe') !== null;
    });

    expect(accepted).toBe(false);
  });

  test('로그인 화면 — 테마 클릭이 Invalid theme 경고를 남기지 않는다', async ({ page }) => {
    const noisy: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning' || msg.type() === 'error') noisy.push(msg.text());
    });

    await gotoScreen(page, '/admin/login');

    // 세 버튼 전부 — 어느 하나라도 계약이 어긋나면 그 클릭에서 경고가 난다.
    for (const mode of ['dark', 'light', 'auto'] as ThemeMode[]) {
      await clickTheme(page, mode);
      await page.waitForTimeout(200);
    }

    expect(noisy.filter((w) => /Invalid theme|Unsupported theme/i.test(w))).toEqual([]);
  });
});
