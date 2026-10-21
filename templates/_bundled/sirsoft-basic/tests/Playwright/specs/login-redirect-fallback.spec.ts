/**
 * E2E: 사용자 로그인 복귀 주소가 거부되면 대체 경로로 간다 (#757)
 *
 * 결함: 엔진이 실행 스킴·다른 출처 주소로의 navigate 를 무동작으로 바꾼 뒤(결정), 사용자 로그인 폼의
 *   `<navigate path="{{query.redirect ?? '/'}}"/>` 가 엔진의 「거부 → `/`」에 기대고 있어
 *   `/login?redirect=javascript:…`·`?redirect=//다른출처` 로 들어와 로그인하면 토큰을 받고도 로그인 화면에 남았다.
 *   빈 `?redirect=` 는 그 전부터 머물렀다(`'' ?? '/'` 는 `''`).
 *   로그인 폼의 이동에 엔진 navigate `fallbackPath="/"` 를 달아(기존 `fallback` 은 미등록 라우트용 핸들러라 다른 인자), 복귀 주소가 비었거나 거부되면 첫 화면으로 간다.
 *
 * 정적: __tests__/layouts/navigate-fallback.test.ts — 바깥에서 온 주소로 가는 navigate 에 fallbackPath 가 있다
 * 이 spec 은 실제 브라우저에서 거부 주소·빈 값이면 `/` 로, 정상 같은 출처 주소면 그 주소로 가는지,
 * 그리고 `javascript:` 가 실행되지 않는지(대화상자 0) 본다.
 *
 * 라이브 쓰기: 비밀번호를 아는 시드 회원(`playwright:seed-password-expiry --stage=fresh`)으로 실제 로그인한다.
 *   계정은 afterAll 의 `--purge-users` 로 지운다. 각 시험은 새 브라우저 컨텍스트라 로그인 상태가 이어지지 않는다.
 *
 * @scenario-manifest tests/scenarios/login-redirect-fallback.yaml
 */
import { test, expect, type Page } from '@playwright/test';

import { runSeedPasswordExpiry, seedAccount, type SeededAccount } from '../../../../../../tests/Playwright/fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

/** 비밀번호를 아는 시드 회원(한 번 만들어 같이 쓴다) */
let member: SeededAccount | null = null;

/**
 * 시드 회원을 만들거나 이미 만든 것을 돌려준다.
 *
 * @returns 시드 회원
 */
function seededMember(): SeededAccount {
  if (!member) member = seedAccount('fresh');

  return member;
}

test.afterAll(() => {
  // 시드 회원(pwexp-…@playwright.local)을 지운다
  if (member) runSeedPasswordExpiry(['--purge-users']);
});

/**
 * 로그인 화면을 복귀 주소와 함께 열고 로그인한 뒤, 이동이 끝난 경로를 돌려준다.
 *
 * @param page 대상 페이지
 * @param redirect `?redirect=` 에 넣을 값(인코딩 전)
 * @param expectedPath 도착해야 할 경로
 */
async function loginWithRedirect(page: Page, redirect: string, expectedPath: string): Promise<void> {
  const account = seededMember();
  const dialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.message());
    await dialog.dismiss();
  });

  await page.goto(`/login?redirect=${encodeURIComponent(redirect)}`);
  await page.waitForLoadState('domcontentloaded');
  await page.fill('input[name="email"]', account.email);
  await page.fill('input[name="password"]', account.password);

  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/login') && r.request().method() === 'POST'),
    page.click('form button[type="submit"]'),
  ]);
  expect(response.status(), '로그인 자체는 성공한다').toBe(200);

  // 로그인 화면에 머물면 waitForURL 이 시간 초과로 실패한다 — 결함 재현 지점
  await page.waitForURL((url) => url.pathname === expectedPath, { timeout: 15_000 });
  const baseURL = test.info().project.use.baseURL ?? process.env.PLAYWRIGHT_BASE_URL ?? '';
  expect(new URL(page.url()).origin, '다른 출처로 나가지 않는다').toBe(new URL(baseURL).origin);
  expect(dialogs, 'javascript: 가 실행되지 않는다').toEqual([]);
}

/**
 * @scenario redirect=javascript_scheme
 * @effects rejected_redirect_lands_on_fallback, script_scheme_never_executes
 */
test('redirect 가 javascript: 면 로그인 뒤 첫 화면으로 간다', async ({ page }) => {
  await loginWithRedirect(page, 'javascript:alert(1)', '/');
});

/**
 * @scenario redirect=protocol_relative
 * @effects rejected_redirect_lands_on_fallback
 */
test('redirect 가 다른 출처(//host) 면 로그인 뒤 첫 화면으로 간다', async ({ page }) => {
  await loginWithRedirect(page, '//example.com/x', '/');
  expect(new URL(page.url()).host).not.toBe('example.com');
});

/**
 * @scenario redirect=empty
 * @effects rejected_redirect_lands_on_fallback
 */
test('redirect 가 빈 값이면 로그인 뒤 첫 화면으로 간다', async ({ page }) => {
  await loginWithRedirect(page, '', '/');
});

/**
 * @scenario redirect=same_origin
 * @effects valid_redirect_is_honored
 */
test('redirect 가 같은 출처 경로면 그 화면으로 돌아간다', async ({ page }) => {
  await loginWithRedirect(page, '/board/free', '/board/free');
});
