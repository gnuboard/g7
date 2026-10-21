/**
 * E2E: 게시판 메뉴 요청의 로그인 토큰 전달 (게시판 1.2.1 #142).
 *
 * 게시판 메뉴(`boards/board-menu`)는 보는 사람이 열람할 수 있는 게시판만 돌려준다. 머리글 데이터소스가 토큰을 보내지 않으면
 * 로그인한 회원도 비회원 메뉴를 받는다 — 실측에서 회원의 마이페이지 게시판 선택칸이 7건(비회원 기준)으로 나왔다.
 * 비회원은 토큰 없이, 로그인한 회원은 토큰을 실어 메뉴를 부르는지 실제 브라우저의 요청 머리말로 본다.
 *
 * 실행 뒤 `php artisan playwright:cleanup` 으로 발급 토큰·시험 계정을 지운다.
 *
 * @scenario-manifest tests/scenarios/board-data-source-auth-mode.yaml
 */
// @scenario case=e2e_board_menu_token_by_login_state
// @effects e2e_board_menu_request_carries_token_when_logged_in
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

const MENU_PATH = '/api/modules/sirsoft-board/boards/board-menu';

test.describe('게시판 메뉴 — 로그인 토큰 전달 (#142)', () => {
  // @scenario case=e2e_board_menu_token_by_login_state
  // @effects e2e_board_menu_request_carries_token_when_logged_in
  test('비회원은 토큰 없이, 로그인한 회원은 토큰을 실어 게시판 메뉴를 부른다', async ({ browser }) => {
    const guest = await browser.newPage();
    const guestRequest = guest.waitForRequest((r) => r.url().includes(MENU_PATH));
    await guest.goto('/');
    expect((await guestRequest).headers()['authorization']).toBeUndefined();
    await guest.close();

    const member = await browser.newPage();
    await authenticatePage(member, issueToken('core.dashboard.read'));
    const memberRequest = member.waitForRequest((r) => r.url().includes(MENU_PATH));
    await member.goto('/');
    expect((await memberRequest).headers()['authorization']).toMatch(/^Bearer\s+\S+/);
    await member.close();
  });
});
