/**
 * 게시판 첨부 다운로드 — 토큰 동반으로 활동이력 행위자(user_id) 기록 (이슈 #413 item 58b).
 *
 * 결함: 다운로드가 <a href="{{download_url}}"> 브라우저 직접 링크(GET)였다. <a> 네비게이션에는
 * Authorization 헤더(토큰)가 실리지 않아, download_url 이 가리키는 optional.sanctum 라우트가
 * 요청을 guest 로 통과 → 회원이 받아도 서버 Auth::id() 가 NULL → 활동이력 행위자 누락.
 *
 * 수정: 다운로드 카드를 custom:downloadAttachment 핸들러로 전환. 핸들러는 코어 ApiClient
 * (G7Core.api.get) 로 요청하므로 Authorization 헤더가 자동 첨부된다. 이 spec 은 회원 다운로드
 * 요청에 Authorization 헤더가 실리는지(= 행위자 기록 경로)를 네트워크 레벨에서 단언한다.
 *
 * 단위/레이아웃 테스트(downloadAttachment.test.ts, *-download-handler.test.tsx)는 핸들러 호출
 * 인자와 JSON 분기 구조를 검증하므로, 브라우저에서 실제 요청에 토큰이 실리는 점은 이 spec 이 담당한다.
 * 실제 DB user_id 기록 자체는 실환경 검수(Chrome MCP)로 최종 확인한다.
 *
 * @scenario card=user_post
 * @effects download_via_api_client_with_token,member_download_records_user_id,guest_download_keeps_user_id_null
 *
 * 활성화 절차: PlaywrightIssueToken 발급 + 첨부 있는 공개 게시글 시드가 가능한 환경에서
 *   test.describe.skip → test.describe. SLUG/POST_PATH 는 시드에 맞춰 조정.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'notice';
// 첨부가 있는 공개 게시글 상세 경로 (시드에 맞춰 조정)
const POST_PATH = `/board/${SLUG}/1`;
const DOWNLOAD_API = `**/api/modules/sirsoft-board/boards/${SLUG}/attachment/**`;

test.describe.skip('게시판 첨부 다운로드 행위자 기록 (#413-58b)', () => {
  // @scenario card=user_post
  // @effects download_via_api_client_with_token, member_download_records_user_id
  test('회원 다운로드 요청에는 Authorization 헤더가 실린다 (행위자 기록 경로)', async ({
    page,
    attachmentDownloadToken,
  }) => {
    await authenticatePage(page, attachmentDownloadToken);

    // 다운로드 요청을 가로채 blob 응답을 주입하면서 Authorization 헤더 존재를 단언한다.
    let authHeaderSeen: string | undefined;
    await page.route(DOWNLOAD_API, async (route) => {
      authHeaderSeen = route.request().headers()['authorization'];
      await route.fulfill({
        status: 200,
        contentType: 'application/octet-stream',
        headers: { 'content-disposition': 'attachment; filename="report.pdf"' },
        body: 'file-content',
      });
    });

    await page.goto(POST_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 권한 있을 때 첨부 카드 클릭 → 핸들러가 G7Core.api.get 으로 다운로드
    await page.locator('[data-testid="attachment-download-card"]').first().click();

    await expect.poll(() => authHeaderSeen, { timeout: 5_000 }).toBeTruthy();
    expect(authHeaderSeen).toContain('Bearer ');
  });

  // @scenario card=user_post
  // @effects guest_download_keeps_user_id_null
  test('비회원 다운로드 요청에는 Authorization 헤더가 없다 (user_id NULL 유지)', async ({
    page,
  }) => {
    // 인증 없이 진입 (토큰 미주입)
    let authHeaderSeen: string | undefined = 'unset';
    await page.route(DOWNLOAD_API, async (route) => {
      authHeaderSeen = route.request().headers()['authorization'];
      await route.fulfill({
        status: 200,
        contentType: 'application/octet-stream',
        headers: { 'content-disposition': 'attachment; filename="report.pdf"' },
        body: 'file-content',
      });
    });

    await page.goto(POST_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('[data-testid="attachment-download-card"]').first().click();

    await expect.poll(() => authHeaderSeen, { timeout: 5_000 }).not.toBe('unset');
    expect(authHeaderSeen).toBeFalsy();
  });
});
