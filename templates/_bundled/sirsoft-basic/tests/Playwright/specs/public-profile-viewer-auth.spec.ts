/**
 * 공개 프로필·작성글 화면 — 글 목록·건수 요청이 보는 사람의 토큰을 싣는지 검증 (7.1.0 실측 O-K4-7)
 *
 * 배경:
 * - 서버(`/users/{uuid}/posts`, `/users/{uuid}/posts/stats`)는 optional.sanctum 이라 토큰이 실리면
 *   그 회원이 읽을 수 있는 게시판(회원전용 등)의 글까지 세고, 없으면 비로그인 기준으로 센다.
 * - 화면 데이터 소스가 토큰을 싣지 않아(auth_mode none) 로그인 회원에게도 비로그인 기준 목록·건수가
 *   보였다. 예외도 콘솔 경고도 없어 **실제 요청 헤더를 봐야만** 드러난다.
 *
 * 회귀 방향이 둘이라 양쪽을 다 본다:
 *  1) 로그인 회원 → 세 요청 모두 Authorization 이 실린다
 *  2) 비로그인 → 요청은 그대로 나가되(건너뛰지 않음) Authorization 이 없다.
 *     required 로 바꾸면 비로그인 방문자에게는 요청 자체가 건너뛰어 공개 프로필이 비어 버린다.
 *
 * 토큰: 기본은 `playwright:issue-token`, `G7_E2E_TOKEN` 이 있으면 그 토큰을 쓴다(실측 계정 재사용).
 */
import { test, expect, type Page } from '@playwright/test';
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/** 공개 프로필 글 목록·건수 API (회원 uuid 무관 매칭) */
const PROFILE_POSTS_ENDPOINT = /\/api\/modules\/sirsoft-board\/users\/[^/]+\/posts(\/stats)?(\?|$)/;

/**
 * 화면 진입 중 나간 글 목록·건수 요청의 Authorization 유무를 모읍니다.
 *
 * @param page Playwright 페이지
 * @param path 진입할 화면 경로
 * @param expected 기다릴 요청 수
 * @return 요청 URL 과 Authorization 유무 목록
 */
async function captureProfilePostRequests(page: Page, path: string, expected: number): Promise<Array<{ url: string; auth: boolean }>> {
  const captured: Array<{ url: string; auth: boolean }> = [];

  page.on('request', (req) => {
    if (PROFILE_POSTS_ENDPOINT.test(req.url())) {
      captured.push({ url: req.url(), auth: Boolean(req.headers()['authorization']) });
    }
  });

  await page.goto(path);
  await expect.poll(() => captured.length, { timeout: 60_000 }).toBeGreaterThanOrEqual(expected);

  return captured;
}

/**
 * 토큰 주인의 uuid 를 받아 옵니다(프로필 주인 — 누구의 프로필이든 요청 계약은 같다).
 *
 * @param page Playwright 페이지
 * @param token Sanctum 토큰
 * @return 회원 uuid
 */
async function resolveUserUuid(page: Page, token: string): Promise<string> {
  const res = await page.request.get('/api/auth/user', {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(res.ok(), '/api/auth/user').toBeTruthy();
  const body = await res.json();

  return body?.data?.uuid ?? body?.data?.user?.uuid;
}

test.describe('공개 프로필 — 글 목록·건수 요청의 보는 사람 토큰', () => {
  // 화면 두 개를 차례로 열고 진행형(progressive) 데이터 소스 요청을 기다리므로 기본 30초보다 넉넉히 둔다
  test.setTimeout(150_000);

  // @scenario viewer=member
  // @effects public_profile_post_sources_send_viewer_token
  test('로그인 회원의 프로필·작성글 화면 요청에는 토큰이 실린다', async ({ page }) => {
    const token = process.env.G7_E2E_TOKEN || issueToken();
    const uuid = await resolveUserUuid(page, token);
    await authenticatePage(page, token);

    const profile = await captureProfilePostRequests(page, `/users/${uuid}`, 2);
    expect(profile.filter((r) => /\/posts\/stats/.test(r.url)).length, '건수 요청').toBeGreaterThan(0);
    expect(profile.every((r) => r.auth), '프로필 화면 요청 Authorization').toBe(true);

    const posts = await captureProfilePostRequests(page, `/users/${uuid}/posts`, 1);
    expect(posts.every((r) => r.auth), '작성글 화면 요청 Authorization').toBe(true);
  });

  // @scenario viewer=guest
  // @effects public_profile_post_sources_send_viewer_token
  test('비로그인 방문자에게도 요청은 나가고 토큰은 없다', async ({ page }) => {
    const token = process.env.G7_E2E_TOKEN || issueToken();
    const uuid = await resolveUserUuid(page, token);

    const profile = await captureProfilePostRequests(page, `/users/${uuid}`, 2);
    expect(profile.some((r) => r.auth), '비로그인 요청에 Authorization').toBe(false);
  });
});
