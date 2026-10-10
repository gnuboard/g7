/**
 * 비밀 콘텐츠 게이트 우회 경로 차단 — 실서버 E2E (KVE-2026-2371 · 2375 · 2377).
 *
 * 비밀글 원문 게이트가 정상 조회 경로에만 걸려 있어 부가 경로로 원문이 나가던 결함군을, 실 브라우저
 * 세션의 fetch(회원 토큰 · 비회원)로 재현해 차단을 확인한다. 각 경로는 대조군(정당 열람자)과 쌍으로 잰다.
 *
 *   ① 비밀 댓글: 무관한 회원·비회원은 content=null, 부모 글 작성자는 원문. 상세 화면은 '비밀 댓글입니다'
 *      안내를 그리고 원문 문자열이 DOM 에 없다(새로고침 후에도). 관리자 상세도 비밀글 조회 권한 없는
 *      관리자 역할에게는 같은 안내를 그리고, 전체 관리자는 원문을 받는다.
 *   ② 활동 목록: 회원전용 게시판 일반글이 비회원의 users/{uuid}/posts 에서 빠진다(본인은 그대로).
 *   ③ 글쓰기 폼: 비밀번호 없는 비회원 비밀글 수정 폼에 원문·첨부 목록이 없고, 남의 비밀글 답글 폼은 404.
 *      비회원 작성자는 수정 화면에서 비밀번호를 맞히면 자기 첨부를 그대로 본다.
 *   ④ 신고 응답: 사용자 신고 생성 응답에 대상 본문(content_preview)이 없다.
 *
 * 단위(PHPUnit 4건)가 viewer 별 판정을, Vitest 가 안내 블록 게이트를 고정하고, 이 spec 은 실서버에서
 * 권한 시스템·라우트 미들웨어·화면 렌더가 함께 맞물리는지를 담당한다.
 * 소유 매니페스트는 `tests/scenarios/board-secret-content-bypass-paths.yaml`(test_files 로 귀속)이다.
 *
 * @axes viewer=outsider viewer=guest viewer=post_author viewer=restricted_admin viewer=owner viewer=reporter viewer=guest_author
 * @effects outsider_gets_null_secret_comment_content,
 *          guest_gets_null_secret_comment_content,
 *          post_author_sees_secret_comments_on_own_post,
 *          secret_comment_placeholder_rendered,
 *          restricted_admin_gets_null_secret_comment_content,
 *          admin_secret_comment_placeholder_rendered,
 *          guest_public_profile_excludes_unreadable_board,
 *          owner_public_profile_not_filtered,
 *          guest_secret_post_form_data_masked_without_password,
 *          reply_form_meta_blocked_for_unreadable_secret_parent,
 *          guest_secret_post_form_meta_attachments_hidden_without_password,
 *          guest_author_form_meta_attachments_after_password,
 *          guest_secret_post_editor_waits_for_body,
 *          user_report_response_omits_target_content
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/board-auth';
import { issueToken, issueScopedToken } from '../../../../../../../tests/Playwright/fixtures/auth';

const API = '/api/modules/sirsoft-board';
const RUN = Date.now().toString(36);
const S1 = `e2e-g2s-${RUN}`; // 비밀글 허용 · 비회원 읽기/쓰기
const S2 = `e2e-g2m-${RUN}`; // 회원전용 읽기

/** 브라우저 컨텍스트의 fetch 로 API 를 호출한다 (bearer 가 없으면 비회원). */
async function api(
    page: Page,
    bearer: string | null,
    method: string,
    path: string,
    body?: unknown,
): Promise<{ status: number; body: any }> {
    return page.evaluate(
        async ({ bearer, method, path, body }) => {
            const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
            if (bearer) headers.Authorization = `Bearer ${bearer}`;
            const response = await fetch(path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
            let parsed: unknown = null;
            try {
                parsed = await response.json();
            } catch {
                /* 비 JSON 응답 허용 */
            }
            return { status: response.status, body: parsed as any };
        },
        { bearer, method, path, body },
    );
}

/** 게시판 권한 payload (프론트 키 형식: 점 → 밑줄). */
function permissions(overrides: Record<string, string[]> = {}): Record<string, { roles: string[] }> {
    const all = ['guest', 'user', 'admin'];
    const base: Record<string, string[]> = {
        'admin.posts.read': ['admin'], 'admin.posts.write': ['admin'], 'admin.posts.read-secret': ['admin'],
        'admin.comments.read': ['admin'], 'admin.comments.write': ['admin'], 'admin.attachments.upload': ['admin'],
        'admin.attachments.download': ['admin'], 'admin.manage': ['admin'],
        'posts.read': all, 'posts.write': all, 'posts.read-secret': ['admin'], 'posts.write-notice': ['admin'],
        'comments.read': all, 'comments.write': all, 'attachments.upload': ['admin', 'user'],
        'attachments.download': all, manager: ['admin'], ...overrides,
    };
    return Object.fromEntries(Object.entries(base).map(([k, v]) => [k.replace(/\./g, '_'), { roles: v }]));
}

const memberPerms = (slug: string) =>
    ['posts.read', 'posts.write', 'comments.read', 'comments.write'].map((a) => `sirsoft-board.${slug}.${a}`);

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test.describe('비밀 콘텐츠 게이트 우회 경로 차단 (G-2)', () => {
    let admin = '';
    let author = '';
    let commenter = '';
    let outsider = '';
    let victim = '';
    let postId = 0;
    let secretPostId = 0;
    let secretCommentId = 0;

    test.beforeAll(async ({ browser }) => {
        // 토큰 발급(artisan) 5회 + 게시판 2개 생성 — 기본 훅 시간 30초를 넘는다
        test.setTimeout(180_000);
        const page = await browser.newPage({ ignoreHTTPSErrors: true });
        await page.goto('/');

        admin = issueToken('sirsoft-board.boards.read', 'sirsoft-board.boards.create', 'sirsoft-board.boards.delete');
        const managerUuid = (await api(page, admin, 'GET', '/api/admin/auth/user')).body?.data?.uuid;
        const board = (slug: string, secret: string, over: Record<string, string[]> = {}) => ({
            slug, name: { ko: `[E2E] ${slug}`, en: slug }, type: 'basic', secret_mode: secret,
            use_comment: true, use_reply: true, use_report: true, show_view_count: true,
            board_manager_ids: [managerUuid], permissions: permissions(over),
        });
        expect((await api(page, admin, 'POST', `${API}/admin/boards`, {
            ...board(S1, 'enabled', { 'attachments.upload': ['guest', 'user', 'admin'] }),
            use_file_upload: true, allowed_extensions: ['txt'],
        })).status).toBe(201);
        expect((await api(page, admin, 'POST', `${API}/admin/boards`, board(S2, 'disabled', {
            'posts.read': ['user', 'admin'], 'posts.write': ['user', 'admin'],
            'comments.read': ['user', 'admin'], 'comments.write': ['user', 'admin'],
        }))).status).toBe(201);

        author = issueScopedToken(...memberPerms(S1));
        commenter = issueScopedToken(...memberPerms(S1));
        outsider = issueScopedToken(...memberPerms(S1));
        victim = issueScopedToken(...memberPerms(S2));

        postId = (await api(page, author, 'POST', `${API}/boards/${S1}/posts`, {
            title: '공개 글', content: `PUBLIC-${RUN}`, content_mode: 'text', is_secret: false,
        })).body?.data?.id;
        secretPostId = (await api(page, author, 'POST', `${API}/boards/${S1}/posts`, {
            title: '회원 비밀글', content: `SECRET-POST-${RUN}`, content_mode: 'text', is_secret: true,
        })).body?.data?.id;
        secretCommentId = (await api(page, commenter, 'POST', `${API}/boards/${S1}/posts/${postId}/comments`, {
            content: `SECRET-COMMENT-${RUN}`, is_secret: true,
        })).body?.data?.id;
        expect(postId && secretPostId && secretCommentId).toBeTruthy();
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(60_000);
        const page = await browser.newPage({ ignoreHTTPSErrors: true });
        await page.goto('/');
        await api(page, admin, 'DELETE', `${API}/admin/boards/${S1}`);
        await api(page, admin, 'DELETE', `${API}/admin/boards/${S2}`);
        await page.close();
    });

    // @scenario viewer=outsider
    // @effects outsider_gets_null_secret_comment_content, guest_gets_null_secret_comment_content, post_author_sees_secret_comments_on_own_post
    test('① 비밀 댓글 — 무관한 회원·비회원은 원문 없음, 글 작성자는 원문', async ({ page }) => {
        await page.goto('/');
        const pick = (r: { body: any }) =>
            (r.body?.data ?? []).find((c: { id: number }) => c.id === secretCommentId) ?? {};
        const url = `${API}/boards/${S1}/posts/${postId}/comments`;

        const asOutsider = pick(await api(page, outsider, 'GET', url));
        expect(asOutsider.is_secret).toBe(true);
        expect(asOutsider.content).toBeNull();

        expect(pick(await api(page, null, 'GET', url)).content).toBeNull();
        expect(pick(await api(page, author, 'GET', url)).content).toBe(`SECRET-COMMENT-${RUN}`);
    });

    // @scenario viewer=outsider
    // @effects secret_comment_placeholder_rendered
    test('① 비밀 댓글 — 상세 화면은 안내를 그리고 원문이 DOM 에 없다 (새로고침 후에도)', async ({ page }) => {
        await authenticatePage(page, outsider);
        await page.goto(`/board/${S1}/${postId}`);
        const placeholder = page.getByText('비밀 댓글입니다.').first();
        await expect(placeholder).toBeVisible({ timeout: 30_000 });
        expect(await page.locator('body').innerText()).not.toContain(`SECRET-COMMENT-${RUN}`);

        await page.reload();
        await expect(page.getByText('비밀 댓글입니다.').first()).toBeVisible({ timeout: 30_000 });
        expect(await page.locator('body').innerText()).not.toContain(`SECRET-COMMENT-${RUN}`);
    });

    // @scenario viewer=restricted_admin
    // @effects restricted_admin_gets_null_secret_comment_content, admin_secret_comment_placeholder_rendered
    test('① 비밀 댓글 — 비밀글 조회 권한 없는 관리자 역할은 관리자 상세에서 안내를 보고, 전체 관리자는 원문', async ({ page }) => {
        // 「게시글 조회(관리자)」·「댓글 조회(관리자)」만 있고 「비밀글 조회(관리자)」·「게시판 관리」는 없는 역할
        const restricted = issueScopedToken(
            'sirsoft-board.boards.read',
            `sirsoft-board.${S1}.admin.posts.read`,
            `sirsoft-board.${S1}.admin.comments.read`,
        );
        await page.goto('/');
        const detailUrl = `${API}/admin/board/${S1}/posts/${postId}`;
        const pick = (r: { body: any }) =>
            (r.body?.data?.comments ?? []).find((c: { id: number }) => c.id === secretCommentId) ?? {};

        const asRestricted = await api(page, restricted, 'GET', detailUrl);
        expect(asRestricted.status).toBe(200);
        expect(pick(asRestricted).is_secret).toBe(true);
        expect(pick(asRestricted).content).toBeNull();
        expect(pick(await api(page, admin, 'GET', detailUrl)).content).toBe(`SECRET-COMMENT-${RUN}`);

        await authenticatePage(page, restricted);
        await page.goto(`/admin/board/${S1}/post/${postId}`);
        await expect(page.getByText('비밀 댓글입니다').first()).toBeVisible({ timeout: 30_000 });
        expect(await page.locator('body').innerText()).not.toContain(`SECRET-COMMENT-${RUN}`);
    });

    // @scenario viewer=guest
    // @effects guest_public_profile_excludes_unreadable_board, owner_public_profile_not_filtered
    test('② 활동 목록 — 회원전용 게시판 글은 비회원 공개 프로필에서 빠지고 본인에겐 남는다', async ({ page }) => {
        await page.goto('/');
        const created = await api(page, victim, 'POST', `${API}/boards/${S2}/posts`, {
            title: '회원전용 일반글', content: `MEMBERS-ONLY-${RUN}`, content_mode: 'text', is_secret: false,
        });
        expect(created.status).toBe(201);
        const victimUuid = created.body?.data?.author?.uuid;
        expect(victimUuid).toBeTruthy();

        expect((await api(page, null, 'GET', `${API}/boards/${S2}/posts`)).status).toBe(401);

        const asGuest = await api(page, null, 'GET', `${API}/users/${victimUuid}/posts`);
        expect(asGuest.status).toBe(200);
        expect(JSON.stringify(asGuest.body)).not.toContain(`MEMBERS-ONLY-${RUN}`);
        expect(asGuest.body?.data?.total).toBe(0);

        const asOwner = await api(page, victim, 'GET', `${API}/users/${victimUuid}/posts`);
        expect((asOwner.body?.data?.data ?? []).map((r: { board_slug: string }) => r.board_slug)).toContain(S2);
    });

    // @scenario viewer=guest
    // @effects guest_secret_post_form_data_masked_without_password, reply_form_meta_blocked_for_unreadable_secret_parent
    test('③ 글쓰기 폼 — 비번 없는 비회원 비밀글 수정 폼에 원문 없음, 남의 비밀글 답글 폼은 404', async ({ page }) => {
        await page.goto('/');
        const guestPost = await api(page, null, 'POST', `${API}/boards/${S1}/posts`, {
            title: '비회원 비밀글', content: `SECRET-GUEST-${RUN}`, content_mode: 'text', is_secret: true,
            author_name: 'e2e-guest', password: 'guestpw123',
        });
        expect(guestPost.status).toBe(201);
        const guestPostId = guestPost.body?.data?.id;

        for (const bearer of [outsider, null]) {
            const form = await api(page, bearer, 'GET', `${API}/boards/${S1}/posts/form-data?post_id=${guestPostId}`);
            expect(form.status).toBe(200);
            expect(JSON.stringify(form.body)).not.toContain(`SECRET-GUEST-${RUN}`);
        }

        const withPassword = await api(page, null, 'GET', `${API}/boards/${S1}/posts/form-data?post_id=${guestPostId}&password=guestpw123`);
        expect(withPassword.body?.data?.content).toBe(`SECRET-GUEST-${RUN}`);

        const reply = await api(page, outsider, 'GET', `${API}/boards/${S1}/posts/form-meta?parent_id=${secretPostId}`);
        expect(reply.status).toBe(404);
        expect(JSON.stringify(reply.body)).not.toContain(`SECRET-POST-${RUN}`);

        const ownReply = await api(page, author, 'GET', `${API}/boards/${S1}/posts/form-meta?parent_id=${secretPostId}`);
        expect(ownReply.body?.data?.parent_post?.content).toBe(`SECRET-POST-${RUN}`);
    });

    // @scenario viewer=guest_author
    // @effects guest_secret_post_form_meta_attachments_hidden_without_password, guest_author_form_meta_attachments_after_password, guest_secret_post_editor_waits_for_body
    test('③ 글쓰기 폼 — 비회원 비밀글 수정 화면은 비밀번호 확인 전 첨부 목록이 없고, 확인하면 작성자 첨부가 보인다', async ({ page }) => {
        await page.goto('/');
        const fileName = `secret-attach-${RUN}.txt`;
        const tempKey = await page.evaluate(() => crypto.randomUUID());
        const upload = await page.evaluate(
            async ({ path, tempKey, fileName }) => {
                const form = new FormData();
                form.append('file', new Blob(['e2e attachment'], { type: 'text/plain' }), fileName);
                form.append('temp_key', tempKey);
                form.append('collection', 'attachments');
                const response = await fetch(path, { method: 'POST', headers: { Accept: 'application/json' }, body: form });
                return response.status;
            },
            { path: `${API}/boards/${S1}/attachments`, tempKey, fileName },
        );
        expect(upload).toBe(201);
        const guestPost = await api(page, null, 'POST', `${API}/boards/${S1}/posts`, {
            title: '첨부 있는 비회원 비밀글', content: `<p>SECRET-ATTACH-POST-${RUN}</p>`, content_mode: 'html', is_secret: true,
            author_name: 'e2e-guest', password: 'guestpw123', temp_key: tempKey,
        });
        expect(guestPost.status).toBe(201);
        const guestPostId = guestPost.body?.data?.id;

        for (const bearer of [outsider, null]) {
            const meta = await api(page, bearer, 'GET', `${API}/boards/${S1}/posts/form-meta?post_id=${guestPostId}`);
            expect(meta.status).toBe(200);
            expect(meta.body?.data?.attachments).toEqual([]);
            expect(JSON.stringify(meta.body)).not.toContain(fileName);
        }

        // 화면: 수정 화면은 비밀번호 확인 창부터 뜨고, 그 뒤의 폼에는 첨부 이름이 없다
        await page.goto(`/board/${S1}/${guestPostId}/edit`);
        const passwordInput = page.getByPlaceholder('비밀번호를 입력하세요');
        await expect(passwordInput).toBeVisible({ timeout: 30_000 });
        expect(await page.locator('body').innerText()).not.toContain(fileName);

        // 비밀번호를 맞히면 폼 틀·값을 다시 받아 작성자는 자기 첨부와 본문을 본다 — 편집기는 다시 받은
        // 본문이 온 뒤에 뜬다(먼저 뜨면 빈 본문으로 세워져 입력·저장 시 원문이 지워진다)
        await passwordInput.fill('guestpw123');
        await page.getByRole('button', { name: '확인', exact: true }).click();
        await expect(passwordInput).toBeHidden({ timeout: 30_000 });
        await expect(page.getByText(fileName)).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[contenteditable="true"]').first()).toContainText(`SECRET-ATTACH-POST-${RUN}`, { timeout: 30_000 });
    });

    // @scenario viewer=reporter
    // @effects user_report_response_omits_target_content
    test('④ 신고 응답 — 사용자 신고 생성 응답에 대상 본문이 없다', async ({ page }) => {
        await page.goto('/');
        const secretReport = await api(page, outsider, 'POST', `${API}/boards/${S1}/posts/${secretPostId}/reports`, {
            reason_type: 'spam', reason_detail: 'e2e',
        });
        expect(secretReport.status).toBe(403);

        const report = await api(page, outsider, 'POST', `${API}/boards/${S1}/posts/${postId}/reports`, {
            reason_type: 'spam', reason_detail: 'e2e',
        });
        expect(report.status).toBe(201);
        expect(report.body?.data?.content_preview ?? null).toBeNull();
        expect(report.body?.data?.content ?? null).toBeNull();
        expect(JSON.stringify(report.body)).not.toContain(`PUBLIC-${RUN}`);
    });
});
