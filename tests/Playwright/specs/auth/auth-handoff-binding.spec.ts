/**
 * @file auth-handoff-binding.spec.ts
 * @description 로그인 인계 코드는 발급한 브라우저에서만 교환된다(7.2.0 — 로그인 CSRF 방어, 계획 #798 K2).
 *
 * 실제 브라우저 맥락 둘(쿠키 저장소가 따로)로 잰다:
 *  1. 맥락 A 에만 묶음 쿠키를 심은 코드를 맥락 B 가 교환하면 422, 그 시도로 코드는 소진된다(A 가 뒤에 내도 422 — 1회용).
 *  2. 새로 발급한 코드를 쿠키를 가진 맥락 A 가 교환하면 200 과 로그인 토큰.
 *
 * 묶음 코드는 `php artisan playwright:issue-handoff`(CLI + G7_PLAYWRIGHT_BYPASS 가드)가 `issueTicket()` 으로 만든다 — 웹 응답이
 * 쿠키를 굽는 것과 같은 값(이름, 값, 경로 `/api/auth`)을 돌려받아 맥락 A 에만 넣는다.
 *
 * 실행: PLAYWRIGHT_BASE_URL=https://<개발 사이트 주소> npx playwright test tests/Playwright/specs/auth/auth-handoff-binding.spec.ts
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, issueToken } from '../../fixtures/auth';
import type { APIRequestContext, Browser, BrowserContext } from '@playwright/test';

const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../');

interface HandoffTicket {
  code: string;
  cookie: { name: string; value: string; path: string };
}

/**
 * 묶음 인계 코드를 발급합니다.
 *
 * @param email 인계할 회원 이메일
 * @returns 코드와 묶음 쿠키
 */
function issueHandoff(email: string): HandoffTicket {
  const stdout = execSync(`php artisan playwright:issue-handoff ${JSON.stringify(email)}`, {
    cwd: CORE_ROOT,
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
    encoding: 'utf8',
  });
  const line = stdout.split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop();
  if (!line) {
    throw new Error(`playwright:issue-handoff 가 JSON 을 내지 않았습니다: ${JSON.stringify(stdout)}`);
  }

  return JSON.parse(line) as HandoffTicket;
}

/**
 * 맥락의 요청 객체로 인계 코드를 교환합니다(브라우저와 같은 쿠키 저장소).
 *
 * @param context 브라우저 맥락
 * @param code 인계 코드
 * @returns 상태 코드와 본문
 */
async function redeem(context: BrowserContext, code: string): Promise<{ status: number; body: any }> {
  const response = await context.request.post('/api/auth/login/handoff', {
    data: { code },
    headers: { Accept: 'application/json' },
  });

  return { status: response.status(), body: await response.json().catch(() => null) };
}

test.describe.serial('로그인 인계 브라우저 묶기 (7.2.0)', () => {
  let email = '';
  let api: APIRequestContext;

  test.beforeAll(async ({ playwright }, testInfo) => {
    const token = issueToken();
    api = await playwright.request.newContext({
      baseURL: testInfo.project.use.baseURL,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const me = await api.get('/api/auth/user');
    expect(me.ok(), `현재 회원 조회 실패 ${me.status()}`).toBeTruthy();
    email = (await me.json())?.data?.email ?? '';
    expect(email, '시험 계정 이메일이 없다').not.toBe('');
  });

  test.afterAll(async () => {
    await api?.dispose();
  });

  /**
   * @scenario context=other, cookie=absent
   * @effects other_context_redeem_422, code_consumed_by_mismatch
   */
  test('다른 브라우저 맥락이 교환하면 422 이고, 그 시도로 코드가 소진된다', async ({ browser, baseURL }) => {
    const ticket = issueHandoff(email);
    const owner = await newContext(browser, baseURL!, ticket);
    const other = await newContext(browser, baseURL!, null);

    const stolen = await redeem(other, ticket.code);
    expect(stolen.status).toBe(422);

    const late = await redeem(owner, ticket.code);
    expect(late.status, '소진된 코드를 원래 브라우저가 내도 거절되어야 한다(1회용)').toBe(422);

    await owner.close();
    await other.close();
  });

  /**
   * @scenario context=owner, cookie=present
   * @effects owner_context_redeem_200
   */
  test('발급한 브라우저 맥락(묶음 쿠키가 있는)이 교환하면 200 과 로그인 토큰이다', async ({ browser, baseURL }) => {
    const ticket = issueHandoff(email);
    const owner = await newContext(browser, baseURL!, ticket);

    const result = await redeem(owner, ticket.code);
    expect(result.status).toBe(200);
    expect(typeof result.body?.data?.token).toBe('string');

    await owner.close();
  });
});

/**
 * 브라우저 맥락을 만들고, 묶음 쿠키가 주어지면 그 맥락에만 심습니다.
 *
 * @param browser 브라우저
 * @param baseURL 사이트 주소
 * @param ticket 묶음 코드 (없으면 쿠키 없음)
 * @returns 맥락
 */
async function newContext(browser: Browser, baseURL: string, ticket: HandoffTicket | null): Promise<BrowserContext> {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
  if (ticket) {
    const url = new URL(baseURL);
    await context.addCookies([{
      name: ticket.cookie.name,
      value: ticket.cookie.value,
      domain: url.hostname,
      path: ticket.cookie.path,
      httpOnly: true,
      secure: url.protocol === 'https:',
      sameSite: 'Lax',
    }]);
  }

  return context;
}
