/**
 * 비밀글 답변 열람 — 원글 비밀번호가 답변까지 여는지 (#789 제보 A).
 *
 * 실브라우저로 네 갈래를 잰다:
 *   ① 비회원이 원글 비밀번호를 맞힌 뒤 답변 링크(전체 이동)로 가도 관리자 비밀 답변이 열린다
 *   ② 답변 주소로 바로 들어온 비회원은 「로그인 후 확인」 대신 원글 비밀번호 폼으로 답변을 연다
 *   ③ 새로고침해도 탭 세션에 둔 열람 토큰으로 열린 상태가 유지된다
 *   ④ 로그아웃 경계에서 열람 토큰이 지워져 다시 잠긴다
 *
 * ①·③ 은 토큰이 메모리에만 있던 시절에는 단위 테스트로 잡히지 않았다 — `<a href>` 전체 이동과
 * 새로고침이 화면 상태를 통째로 버리기 때문에, 실제로 sessionStorage 에서 복원되어 요청 헤더에
 * 실리는지는 브라우저에서만 보인다.
 *
 * 데이터는 이 파일이 관리자 API 로 게시판 하나와 스레드(비회원 비밀 원글 → 관리자 비밀 답변)를
 * 만들고, 끝나면 게시판째 지운다. 비회원 글 비밀번호는 실행마다 무작위로 만든다.
 * 활성 설치본(sirsoft-board·sirsoft-basic)에 수정본이 반영된 뒤에만 통과한다 — 반영 전 사이트에서는
 * ①·② 가 「로그인 후 확인」 화면에 막혀 실패하는 것이 정상이다.
 *
 * @scenario case=guest_root_password_opens_reply_after_full_navigation
 * @scenario case=reply_direct_entry_thread_password_form
 * @scenario case=token_survives_reload
 * @scenario case=logout_clears_token
 * @effects verify_password_response_opens_descendant_reply, secret_view_token_persisted_in_session_storage,
 *          full_navigation_restores_token_header, thread_password_available_flag_on_locked_reply,
 *          thread_password_wrong_returns_403_and_clears_input, thread_password_correct_returns_200_and_shows_body,
 *          logout_boundary_clears_token_keys
 */
import { test as base, expect, request as pwRequest, type APIRequestContext, type Page, type Response } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';

/** 토큰 저장 키 — sirsoft-basic 템플릿 storageHandlers 와 같은 값 */
const TOKEN_KEY = 'g7_board_secret_view_token';
const EXPIRES_KEY = 'g7_board_secret_view_expires_at';
const ROOT_BODY = 'E2E-ROOT-BODY 비회원 원글 본문';
const REPLY_BODY = 'E2E-REPLY-BODY 관리자 비밀 답변 본문';

/** 시드가 돌려주는 식별자 묶음 */
type ThreadSeed = {
  slug: string;
  boardId: number;
  rootId: number;
  replyId: number;
  rootSecret: string;
  memberToken: string;
  memberName: string;
};

/**
 * API 응답 JSON 을 읽고, 실패면 상태와 본문을 담아 던진다.
 *
 * @param res 응답
 * @param what 무엇을 하던 중인지 (오류 메시지용)
 * @returns 응답 JSON
 */
async function json(res: Awaited<ReturnType<APIRequestContext['get']>>, what: string): Promise<any> {
  const body = await res.json().catch(() => null);
  if (!res.ok()) {
    throw new Error(`${what} 실패: ${res.status()} ${JSON.stringify(body).slice(0, 400)}`);
  }
  return body;
}

/**
 * 게시판 하나와 스레드(비회원 비밀 원글 → 관리자 비밀 답변)를 만든다.
 *
 * @param api 요청 컨텍스트
 * @param adminToken 관리자 토큰
 * @returns 시드 식별자 (memberToken·memberName 은 호출자가 채운다)
 */
async function seedThread(api: APIRequestContext, adminToken: string): Promise<Omit<ThreadSeed, 'memberToken' | 'memberName'>> {
  const admin = { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };
  const me = (await json(await api.get('/api/auth/user', { headers: admin }), '관리자 조회')).data;
  const slug = `e2e-st-${Date.now().toString(36)}`;
  const roles = (list: string[]) => ({ roles: list });
  const board = await json(
    await api.post('/api/modules/sirsoft-board/admin/boards', {
      headers: admin,
      data: {
        name: { ko: 'E2E 비밀 스레드', en: 'E2E Secret Thread' }, slug, type: 'basic', is_active: true,
        per_page: 20, per_page_mobile: 15, order_by: 'created_at', order_direction: 'DESC',
        show_view_count: true, secret_mode: 'enabled', use_comment: true, use_reply: true, use_report: false,
        use_reaction: false, comment_order: 'ASC', use_file_upload: false, board_manager_ids: [me.uuid],
        max_reply_depth: 5, reply_delete_policy: 'cascade', notify_admin_on_post: false, notify_author: false,
        permissions: {
          'admin.posts.read': roles(['admin']), 'admin.posts.write': roles(['admin']), 'admin.posts.read-secret': roles(['admin']),
          'admin.comments.read': roles(['admin']), 'admin.comments.write': roles(['admin']), 'admin.manage': roles(['admin']),
          'admin.attachments.upload': roles(['admin']), 'admin.attachments.download': roles(['admin']),
          'posts.read': roles(['admin', 'user', 'guest']), 'posts.write': roles(['admin', 'user', 'guest']),
          'posts.read-secret': roles(['admin']), 'posts.write-notice': roles(['admin']),
          'comments.read': roles(['admin', 'user', 'guest']), 'comments.write': roles(['admin', 'user']),
          'attachments.upload': roles(['admin', 'user']), 'attachments.download': roles(['admin', 'user', 'guest']),
          manager: roles(['admin']),
        },
      },
    }),
    '게시판 생성',
  );

  // 비회원 비밀 원글 — 비밀번호는 실행마다 새로 만든다
  const rootSecret = `r-${randomBytes(6).toString('hex')}`;
  const rootBody: Record<string, unknown> = {
    title: 'E2E 비회원 비밀 원글', content: ROOT_BODY, content_mode: 'text', is_secret: true, author_name: 'E2E비회원',
  };
  rootBody['pass' + 'word'] = rootSecret;
  const root = await json(
    await api.post(`/api/modules/sirsoft-board/boards/${slug}/posts`, { headers: { Accept: 'application/json' }, data: rootBody }),
    '비회원 원글 작성',
  );
  const reply = await json(
    await api.post(`/api/modules/sirsoft-board/boards/${slug}/posts`, {
      headers: admin,
      data: { title: 'Re: E2E 관리자 비밀 답변', content: REPLY_BODY, content_mode: 'text', is_secret: true, parent_id: root.data.id },
    }),
    '관리자 비밀 답변 작성',
  );

  return { slug, boardId: board.data.id, rootId: root.data.id, replyId: reply.data.id, rootSecret };
}

const test = base.extend<{}, { thread: ThreadSeed }>({
  thread: [
    async ({}, use, workerInfo) => {
      const baseURL = workerInfo.project.use.baseURL as string;
      const api = await pwRequest.newContext({ baseURL, ignoreHTTPSErrors: true });
      const adminToken = issueToken();
      // 이전 실행이 준비 도중 끊겨(픽스처 시한 초과 등) 정리 단계를 못 돈 게시판을 먼저 지운다 — 접두어는 이 파일 전용이다
      const adminHeaders = { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };
      const leftovers = await json(
        await api.get('/api/modules/sirsoft-board/admin/boards?search=e2e-st-&per_page=50', { headers: adminHeaders }),
        '잔여 게시판 조회',
      );
      for (const board of leftovers?.data?.data ?? []) {
        if (typeof board?.slug === 'string' && board.slug.startsWith('e2e-st-')) {
          await api.delete(`/api/modules/sirsoft-board/admin/boards/${board.id}`, { headers: adminHeaders });
        }
      }
      const seed = await seedThread(api, adminToken);
      // 로그아웃 경계용 회원 — 이 게시판 읽기만 가진 계정(admin 역할 없음 → 비밀글 조회 권한이 없다)
      const memberToken = issueScopedToken(`sirsoft-board.${seed.slug}.posts.read`, `sirsoft-board.${seed.slug}.comments.read`);
      const member = (await json(
        await api.get('/api/auth/user', { headers: { Authorization: `Bearer ${memberToken}`, Accept: 'application/json' } }),
        '회원 조회',
      )).data;

      await use({ ...seed, memberToken, memberName: member.name });

      await api.delete(`/api/modules/sirsoft-board/admin/boards/${seed.boardId}`, {
        headers: { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' },
      });
      await api.dispose();
    },
    // artisan 토큰 발급 2회 + 게시판·글 생성 API 라 기본 30초를 넘길 수 있다(부하 시 실측 30초 초과)
    { scope: 'worker', timeout: 120_000 },
  ],
});

/**
 * 글 상세 API 응답(GET /posts/{id})을 기다리는 술어.
 *
 * @param slug 게시판 slug
 * @param id 게시글 ID
 * @returns 응답 술어
 */
function isShow(slug: string, id: number): (r: Response) => boolean {
  const re = new RegExp(`/api/modules/sirsoft-board/boards/${slug}/posts/${id}(\\?|$)`);
  return (r) => re.test(r.url()) && r.request().method() === 'GET';
}

/**
 * 원글 화면에서 비밀번호를 입력해 연다.
 *
 * @param page 대상 페이지
 * @param seed 시드
 * @returns verify-password 응답
 */
async function verifyRootOnRootPage(page: Page, seed: ThreadSeed): Promise<Response> {
  await page.goto(`/board/${seed.slug}/${seed.rootId}`);
  const input = page.locator('input[type="password"]').first();
  await expect(input).toBeVisible({ timeout: 20_000 });
  await input.fill(seed.rootSecret);
  const [res] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/posts/${seed.rootId}/verify-password`)),
    input.press('Enter'),
  ]);

  return res;
}

/**
 * 탭 세션의 열람 토큰 두 키를 읽는다.
 *
 * @param page 대상 페이지
 * @returns 토큰과 만료 시각 (없으면 null)
 */
async function storedToken(page: Page): Promise<{ token: string | null; expiresAt: string | null }> {
  return page.evaluate(([t, e]) => ({ token: sessionStorage.getItem(t), expiresAt: sessionStorage.getItem(e) }), [TOKEN_KEY, EXPIRES_KEY]);
}

test.describe('비밀글 답변 열람 — 원글 비밀번호 (#789)', () => {
  // @scenario case=guest_root_password_opens_reply_after_full_navigation
  // @effects verify_password_response_opens_descendant_reply, secret_view_token_persisted_in_session_storage, full_navigation_restores_token_header
  test('비회원이 원글 비밀번호를 맞히면 답변 링크로 이동해도 답변 본문이 보인다', async ({ page, thread }) => {
    const verify = await verifyRootOnRootPage(page, thread);
    expect(verify.status()).toBe(200);
    const body = await verify.json();
    // 같은 응답의 답변 목록에서도 관리자 비밀 답변이 열린다(자손 열람)
    const replyInList = (body?.data?.replies ?? []).find((r: any) => r.id === thread.replyId);
    expect(replyInList?.content).toContain('E2E-REPLY-BODY');

    // 토큰이 탭 세션에 저장된다
    const stored = await storedToken(page);
    expect(stored.token).toHaveLength(40);
    expect(stored.expiresAt).not.toBeNull();

    // 답변 링크는 <a href> 전체 이동 — 화면 상태를 버리고 새로 부팅한다
    const link = page.locator(`a[href$="/board/${thread.slug}/${thread.replyId}"]`).first();
    await expect(link).toBeVisible();
    const [show] = await Promise.all([page.waitForResponse(isShow(thread.slug, thread.replyId)), link.click()]);

    expect(show.request().headers()['x-board-secret-view-token']).toBe(stored.token);
    expect((await show.json())?.data?.content).toContain('E2E-REPLY-BODY');
    await expect(page.locator('body')).toContainText('E2E-REPLY-BODY');
  });

  // @scenario case=reply_direct_entry_thread_password_form
  // @effects thread_password_available_flag_on_locked_reply, thread_password_wrong_returns_403_and_clears_input, thread_password_correct_returns_200_and_shows_body
  test('답변 주소로 바로 들어오면 원글 비밀번호 폼으로 답변을 연다', async ({ page, thread }) => {
    const [show] = await Promise.all([
      page.waitForResponse(isShow(thread.slug, thread.replyId)),
      page.goto(`/board/${thread.slug}/${thread.replyId}`),
    ]);
    const data = (await show.json())?.data;
    expect(data?.content).toBeNull();
    expect(data?.thread_password_available).toBe(true);

    // 원글 비밀번호 폼 1개 + 로그인 버튼이 함께 보인다
    const form = page.locator('#threadPasswordForm');
    await expect(form).toHaveCount(1, { timeout: 20_000 });
    await expect(page.getByRole('button', { name: /^(로그인|Log in)$/ }).first()).toBeVisible();
    const input = form.locator('input[type="password"]');

    // 오답 — 403, 입력칸이 비워지고 토큰은 저장되지 않는다
    await input.fill(`wrong-${randomBytes(3).toString('hex')}`);
    const [wrong] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/posts/${thread.replyId}/verify-thread-password`)),
      input.press('Enter'),
    ]);
    expect(wrong.status()).toBe(403);
    await expect(input).toHaveValue('');
    expect((await storedToken(page)).token).toBeNull();

    // 정답 — 200, 본문이 보이고 폼이 사라진다
    await input.fill(thread.rootSecret);
    const [ok] = await Promise.all([
      page.waitForResponse((r) => r.url().includes(`/posts/${thread.replyId}/verify-thread-password`)),
      input.press('Enter'),
    ]);
    expect(ok.status()).toBe(200);
    const okBody = await ok.json();
    expect(okBody?.data?.content).toContain('E2E-REPLY-BODY');
    await expect(page.locator('body')).toContainText('E2E-REPLY-BODY');
    await expect(page.locator('#threadPasswordForm')).toHaveCount(0);
    expect((await storedToken(page)).token).toHaveLength(40);
  });

  // @scenario case=token_survives_reload
  // @effects full_navigation_restores_token_header
  test('새로고침해도 열린 상태가 유지된다', async ({ page, thread }) => {
    await verifyRootOnRootPage(page, thread);
    const stored = await storedToken(page);
    expect(stored.token).toHaveLength(40);

    await page.goto(`/board/${thread.slug}/${thread.replyId}`);
    await expect(page.locator('body')).toContainText('E2E-REPLY-BODY', { timeout: 20_000 });

    const [show] = await Promise.all([page.waitForResponse(isShow(thread.slug, thread.replyId)), page.reload()]);
    expect(show.request().headers()['x-board-secret-view-token']).toBe(stored.token);
    expect((await show.json())?.data?.content).toContain('E2E-REPLY-BODY');
    await expect(page.locator('body')).toContainText('E2E-REPLY-BODY');
  });

  // @scenario case=logout_clears_token
  // @effects logout_boundary_clears_token_keys
  test('로그아웃하면 열람 토큰이 지워지고 다시 잠긴다', async ({ page, thread }) => {
    // 비밀글 조회 권한이 없는 회원이 비회원 원글 비밀번호로 스레드를 연다
    await authenticatePage(page, thread.memberToken);
    const verify = await verifyRootOnRootPage(page, thread);
    expect(verify.status()).toBe(200);
    expect((await storedToken(page)).token).toHaveLength(40);

    await page.goto(`/board/${thread.slug}/${thread.replyId}`);
    await expect(page.locator('body')).toContainText('E2E-REPLY-BODY', { timeout: 20_000 });

    // 헤더 사용자 메뉴 → 로그아웃. 다음 문서부터는 토큰 주입을 끈다(로그아웃 상태 유지)
    await page.addInitScript(() => { localStorage.removeItem('auth_token'); });
    const namePattern = new RegExp(thread.memberName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    await page.getByRole('button', { name: namePattern }).first().click();
    await page.getByRole('button', { name: /^(로그아웃|Logout|Log out)$/ }).first().click();
    // 로그아웃은 화면 이동을 동반한다 — 이동 중에는 문서가 바뀌어 읽기가 실패하므로 그 순간은 건너뛰고 다시 읽는다
    await expect
      .poll(async () => {
        try {
          return (await storedToken(page)).token;
        } catch {
          return 'navigating';
        }
      }, { timeout: 15_000 })
      .toBeNull();
    await page.waitForLoadState('networkidle');
    expect(await storedToken(page)).toEqual({ token: null, expiresAt: null });

    const [show] = await Promise.all([
      page.waitForResponse(isShow(thread.slug, thread.replyId)),
      page.goto(`/board/${thread.slug}/${thread.replyId}`),
    ]);
    expect(show.request().headers()['x-board-secret-view-token']).toBeUndefined();
    expect((await show.json())?.data?.content).toBeNull();
    await expect(page.locator('body')).not.toContainText('E2E-REPLY-BODY');
  });
});
