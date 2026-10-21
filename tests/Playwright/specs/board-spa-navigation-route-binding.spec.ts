/**
 * E2E: SPA 이동 뒤 게시판 화면의 `{{route.*}}` 링크·요청이 새 화면 값을 쓰는지 (7.1.1 결함, engine-v1.82.1)
 *
 * 배경: 엔진의 정적 경로 영구 캐시(키 = 경로 글자, 30초)가 `route`·`query` 루트도 담아,
 * 글 보기(`/board/A/{id}`) → SPA 로 게시판 B 목록 이동 시 「글쓰기」 링크
 * `/board/{{route.slug}}/write` 가 `/board/A/write` 로 남았다. 누르면 글쓰기 화면이 A 의
 * `form-data`·`form-meta` 를 요청해 A 쓰기 권한이 없는 회원은 403 을 받았다. 같은 게시판에서
 * 글1 → 글2 로 가면 답글 링크 `?parent_id={{route.id}}` 가 글1 id 로 남았다.
 *
 * `route-binding-after-navigation.spec.ts` 는 배포 번들의 바인딩 엔진만 떼어 잠그고, 이 spec 은
 * 실제 게시판 레이아웃·라우터·데이터소스를 거쳐 화면 링크와 API 요청까지 새 값이 닿는지 본다.
 * **첫 진입만 `page.goto`** — 새로고침·전체 이동은 엔진 캐시를 비워 결함이 사라지므로, 이후 이동은
 * 메뉴 클릭과 같은 경로(`G7Core.dispatch` navigate)와 화면 안 링크 클릭으로만 한다.
 *
 * 데이터는 이 파일이 관리자 API 로 게시판 둘(A: 읽기만 허용, B: 읽기·쓰기 허용)과 글을 만들고,
 * 끝나면 게시판째 지운다. 회원은 admin 역할 없이 A 읽기 · B 읽기·쓰기 권한만 가진 계정이다.
 *
 * @scenario surface=cache
 * @effects route_query_bindings_follow_current_screen
 */
import { test as base, expect, request as pwRequest, type APIRequestContext, type Page, type Request, type Response } from '@playwright/test';
import { issueToken, issueScopedToken, authenticatePage } from '../fixtures/auth';

/** 시드가 돌려주는 식별자 묶음 */
type Seed = {
  slugA: string;
  slugB: string;
  boardIdA: number;
  boardIdB: number;
  postA: number;
  postB1: number;
  postB2: number;
  memberToken: string;
};

/** 이 파일 전용 게시판 slug 접두어 — 잔여 정리 기준 */
const PREFIX = 'e2e-rb-';

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
 * 게시판 하나를 만든다. 사용자 쓰기 권한은 admin 역할에만 열고, 회원은 권한 식별자로 따로 받는다.
 *
 * @param api 요청 컨텍스트
 * @param admin 관리자 요청 헤더
 * @param managerUuid 게시판 관리자 uuid
 * @param slug 게시판 slug
 * @param label 이름 접미어
 * @returns 생성된 게시판 id
 */
async function createBoard(api: APIRequestContext, admin: Record<string, string>, managerUuid: string, slug: string, label: string): Promise<number> {
  const roles = (list: string[]) => ({ roles: list });
  const board = await json(
    await api.post('/api/modules/sirsoft-board/admin/boards', {
      headers: admin,
      data: {
        name: { ko: `E2E 경로 바인딩 ${label}`, en: `E2E Route Binding ${label}` }, slug, type: 'basic', is_active: true,
        per_page: 20, per_page_mobile: 15, order_by: 'created_at', order_direction: 'DESC',
        show_view_count: true, secret_mode: 'disabled', use_comment: false, use_reply: true, use_report: false,
        use_reaction: false, comment_order: 'ASC', use_file_upload: false, board_manager_ids: [managerUuid],
        max_reply_depth: 5, reply_delete_policy: 'cascade', notify_admin_on_post: false, notify_author: false,
        permissions: {
          'admin.posts.read': roles(['admin']), 'admin.posts.write': roles(['admin']), 'admin.posts.read-secret': roles(['admin']),
          'admin.comments.read': roles(['admin']), 'admin.comments.write': roles(['admin']), 'admin.manage': roles(['admin']),
          'admin.attachments.upload': roles(['admin']), 'admin.attachments.download': roles(['admin']),
          'posts.read': roles(['admin', 'user', 'guest']), 'posts.write': roles(['admin']),
          'posts.read-secret': roles(['admin']), 'posts.write-notice': roles(['admin']),
          'comments.read': roles(['admin', 'user', 'guest']), 'comments.write': roles(['admin']),
          'attachments.upload': roles(['admin']), 'attachments.download': roles(['admin', 'user', 'guest']),
          manager: roles(['admin']),
        },
      },
    }),
    `게시판 ${label} 생성`,
  );

  return board.data.id;
}

/**
 * 관리자로 일반 글 하나를 쓴다.
 *
 * @param api 요청 컨텍스트
 * @param admin 관리자 요청 헤더
 * @param slug 게시판 slug
 * @param title 제목
 * @returns 글 id
 */
async function createPost(api: APIRequestContext, admin: Record<string, string>, slug: string, title: string): Promise<number> {
  const post = await json(
    await api.post(`/api/modules/sirsoft-board/boards/${slug}/posts`, {
      headers: admin,
      data: { title, content: `${title} 본문`, content_mode: 'text', is_secret: false },
    }),
    `${slug} 글 작성`,
  );

  return post.data.id;
}

const test = base.extend<{}, { seed: Seed }>({
  seed: [
    async ({}, use, workerInfo) => {
      const baseURL = workerInfo.project.use.baseURL as string;
      const api = await pwRequest.newContext({ baseURL, ignoreHTTPSErrors: true });
      const adminToken = issueToken();
      const admin = { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };

      // 이전 실행이 정리 단계를 못 돈 게시판을 먼저 지운다 — 접두어는 이 파일 전용이다
      const leftovers = await json(
        await api.get(`/api/modules/sirsoft-board/admin/boards?search=${PREFIX}&per_page=50`, { headers: admin }),
        '잔여 게시판 조회',
      );
      for (const board of leftovers?.data?.data ?? []) {
        if (typeof board?.slug === 'string' && board.slug.startsWith(PREFIX)) {
          await api.delete(`/api/modules/sirsoft-board/admin/boards/${board.id}`, { headers: admin });
        }
      }

      const me = (await json(await api.get('/api/auth/user', { headers: admin }), '관리자 조회')).data;
      const stamp = Date.now().toString(36);
      const slugA = `${PREFIX}a-${stamp}`;
      const slugB = `${PREFIX}b-${stamp}`;
      const boardIdA = await createBoard(api, admin, me.uuid, slugA, 'A');
      const boardIdB = await createBoard(api, admin, me.uuid, slugB, 'B');
      const postA = await createPost(api, admin, slugA, 'E2E A 게시판 글');
      const postB1 = await createPost(api, admin, slugB, 'E2E B 게시판 글1');
      const postB2 = await createPost(api, admin, slugB, 'E2E B 게시판 글2');

      // 회원 — admin 역할 없이 A 읽기 · B 읽기·쓰기만. 권한 식별자는 게시판 생성으로 생기므로 시드 뒤에 발급한다
      const memberToken = issueScopedToken(
        `sirsoft-board.${slugA}.posts.read`,
        `sirsoft-board.${slugB}.posts.read`,
        `sirsoft-board.${slugB}.posts.write`,
      );

      await use({ slugA, slugB, boardIdA, boardIdB, postA, postB1, postB2, memberToken });

      for (const id of [boardIdA, boardIdB]) {
        await api.delete(`/api/modules/sirsoft-board/admin/boards/${id}`, { headers: admin });
      }
      await api.dispose();
    },
    // artisan 토큰 발급 2회 + 게시판 2개·글 3개 생성 API
    { scope: 'worker', timeout: 120_000 },
  ],
});

/**
 * 게시판 API 요청 술어 — 지정 경로 접미어로 끝나는 GET.
 *
 * @param path `/boards/{slug}/posts…` 이후를 포함한 경로 조각
 * @returns 응답 술어
 */
function isBoardGet(path: string): (r: Response) => boolean {
  return (r) => r.request().method() === 'GET' && new URL(r.url()).pathname === `/api/modules/sirsoft-board/boards/${path}`;
}

/**
 * 메뉴 클릭과 같은 경로(라우터 navigate 핸들러)로 SPA 이동한다 — 새로고침하지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @param waitFor 이동 뒤 기다릴 응답 술어
 * @returns 기다린 응답
 */
async function spaNavigate(page: Page, path: string, waitFor: (r: Response) => boolean): Promise<Response> {
  const [res] = await Promise.all([
    page.waitForResponse(waitFor, { timeout: 30_000 }),
    page.evaluate((p) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } }), path),
  ]);
  await page.waitForURL(`**${path}`, { timeout: 30_000 });

  return res;
}

/**
 * 게시판 API 의 401·403 응답과 요청 경로를 모은다.
 *
 * @param page 대상 페이지
 * @returns 모인 목록(참조) — 거부 응답과 게시판 API 요청 경로
 */
function watchBoardApi(page: Page): { denied: string[]; requests: string[] } {
  const bag = { denied: [] as string[], requests: [] as string[] };
  page.on('request', (req: Request) => {
    if (req.url().includes('/api/modules/sirsoft-board/boards/')) {
      const u = new URL(req.url());
      bag.requests.push(`${req.method()} ${u.pathname}${u.search}`);
    }
  });
  page.on('response', (res: Response) => {
    if (res.url().includes('/api/modules/sirsoft-board/') && [401, 403].includes(res.status())) {
      bag.denied.push(`${res.status()} ${new URL(res.url()).pathname}`);
    }
  });

  return bag;
}

test.describe('SPA 이동 뒤 게시판 route 바인딩 (7.1.1)', () => {
  test.beforeEach(async ({ page, seed }) => {
    await authenticatePage(page, seed.memberToken);
  });

  // @scenario surface=cache
  // @effects route_query_bindings_follow_current_screen
  test('A 글 보기 → SPA 로 B 목록 → 글쓰기 링크·form 요청이 B 를 쓴다', async ({ page, seed }) => {
    const bag = watchBoardApi(page);

    // 첫 진입만 goto — A 글 보기 화면이 route.slug=A·route.id 를 그린다
    await Promise.all([
      page.waitForResponse(isBoardGet(`${seed.slugA}/posts/${seed.postA}`), { timeout: 30_000 }),
      page.goto(`/board/${seed.slugA}/${seed.postA}`),
    ]);
    await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 30_000 });
    await expect(page.locator('body')).toContainText('E2E A 게시판 글', { timeout: 20_000 });

    // SPA 로 B 목록 (새로고침 없음)
    const list = await spaNavigate(page, `/board/${seed.slugB}`, isBoardGet(`${seed.slugB}/posts`));
    expect(list.status()).toBe(200);
    expect((await list.json())?.data?.abilities?.can_write).toBe(true);

    // 글쓰기 링크 — 수정 전: /board/{A}/write
    const write = page.locator('a[href*="/write"]').filter({ hasText: /글쓰기|Write/ }).first();
    await expect(write).toBeVisible({ timeout: 20_000 });
    expect(new URL((await write.getAttribute('href'))!, 'https://x').pathname).toBe(`/board/${seed.slugB}/write`);

    // 누르면 글쓰기 화면이 B 의 form-data·form-meta 를 받는다
    const [formData, formMeta] = await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'GET' && /\/posts\/form-data(\?|$)/.test(r.url()), { timeout: 30_000 }),
      page.waitForResponse((r) => r.request().method() === 'GET' && /\/posts\/form-meta(\?|$)/.test(r.url()), { timeout: 30_000 }),
      write.click(),
    ]);
    await page.waitForURL(`**/board/${seed.slugB}/write**`, { timeout: 30_000 });

    expect(new URL(formData.url()).pathname).toBe(`/api/modules/sirsoft-board/boards/${seed.slugB}/posts/form-data`);
    expect(new URL(formMeta.url()).pathname).toBe(`/api/modules/sirsoft-board/boards/${seed.slugB}/posts/form-meta`);
    expect(formData.status()).toBe(200);
    expect(formMeta.status()).toBe(200);
    // 새 글 — 앞 화면의 글 번호가 post_id 로 실리지 않는다
    expect(new URL(formData.url()).searchParams.get('post_id') ?? '').toBe('');

    // A 의 form 요청은 한 번도 나가지 않았고, 거부 응답도 없다
    expect(bag.requests.filter((r) => r.includes(`/boards/${seed.slugA}/posts/form-`))).toEqual([]);
    expect(bag.denied).toEqual([]);
  });

  // @scenario surface=cache
  // @effects route_query_bindings_follow_current_screen
  test('같은 게시판 글1 → 글2 로 이동하면 답글 링크·form 요청 parent_id 가 글2 다', async ({ page, seed }) => {
    const bag = watchBoardApi(page);

    // 첫 진입만 goto — 글1
    await Promise.all([
      page.waitForResponse(isBoardGet(`${seed.slugB}/posts/${seed.postB1}`), { timeout: 30_000 }),
      page.goto(`/board/${seed.slugB}/${seed.postB1}`),
    ]);
    await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 30_000 });
    const reply = page.locator('a[href*="parent_id="]').first();
    await expect(reply).toBeVisible({ timeout: 20_000 });
    expect(new URL((await reply.getAttribute('href'))!, 'https://x').searchParams.get('parent_id')).toBe(String(seed.postB1));

    // SPA 로 글2 (새로고침 없음)
    const show = await spaNavigate(page, `/board/${seed.slugB}/${seed.postB2}`, isBoardGet(`${seed.slugB}/posts/${seed.postB2}`));
    expect(show.status()).toBe(200);
    await expect(page.locator('body')).toContainText('E2E B 게시판 글2', { timeout: 20_000 });

    // 수정 전: parent_id 가 글1 로 남는다 — 다시 그려질 때까지 기다린다
    await expect
      .poll(async () => {
        const href = await page.locator('a[href*="parent_id="]').first().getAttribute('href');
        return href ? new URL(href, 'https://x').searchParams.get('parent_id') : null;
      }, { timeout: 10_000 })
      .toBe(String(seed.postB2));

    // 누르면 답글 폼이 글2 를 부모로 받는다
    const [formData, formMeta] = await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'GET' && /\/posts\/form-data(\?|$)/.test(r.url()), { timeout: 30_000 }),
      page.waitForResponse((r) => r.request().method() === 'GET' && /\/posts\/form-meta(\?|$)/.test(r.url()), { timeout: 30_000 }),
      page.locator('a[href*="parent_id="]').first().click(),
    ]);
    await page.waitForURL(`**/board/${seed.slugB}/write?*parent_id=${seed.postB2}*`, { timeout: 30_000 });

    for (const res of [formData, formMeta]) {
      const url = new URL(res.url());
      expect(url.pathname.startsWith(`/api/modules/sirsoft-board/boards/${seed.slugB}/posts/form-`)).toBe(true);
      expect(url.searchParams.get('parent_id')).toBe(String(seed.postB2));
      expect(res.status()).toBe(200);
    }
    expect(bag.denied).toEqual([]);
  });
});
