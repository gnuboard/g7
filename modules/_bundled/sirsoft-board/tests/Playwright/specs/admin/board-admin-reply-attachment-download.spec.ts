/**
 * 관리자 게시글 상세 — 원글·답변 카드 첨부 내려받기 (#703).
 *
 * 결함 두 겹:
 *   ① 두 카드가 관리자 템플릿에만 있는 접두어 없는 `downloadAttachment` 를 불러, 다른 관리자 템플릿에서는
 *      눌러도 아무 일이 없었다 → 모듈 핸들러 `sirsoft-board.downloadAttachment` 로 옮겼다.
 *   ② 원글 상세가 답변을 불러올 때 답변의 첨부 관계를 싣지 않아(`attachments: null`) 답변 카드의
 *      「첨부파일 (n)」을 펼쳐도 목록이 비어 있었다 → 답변 적재에 첨부를 함께 싣는다
 *      (PHPUnit ReplyAttachmentSerializationTest).
 *
 * 단위/레이아웃(downloadAttachment.test.ts, admin-attachment-download-handler.test.tsx)은 핸들러 인자와
 * JSON 구조를, PHPUnit 은 응답 계약을 본다. 이 spec 은 실제 화면에서 두 카드의 첨부가 보이고, 누르면
 * 토큰을 실은 요청으로 원본 파일명의 파일이 저장되는지를 브라우저 수준에서 확인한다.
 *
 * 전용 게시판(e2e-reply-attachment)을 spec 안에서 생성/삭제하므로 시드 의존이 없다.
 *
 * @scenario outcome=success
 * @effects admin_card_calls_module_owned_download_handler,
 *          reply_card_lists_its_own_attachments,
 *          admin_detail_downloads_post_and_reply_attachments_in_browser
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'e2e-reply-attachment';
const API = '/api/modules/sirsoft-board';

/** 브라우저 컨텍스트에서 Bearer 토큰으로 API 를 호출한다 (실 브라우저 실측 규약). */
async function api(
    page: import('@playwright/test').Page,
    bearer: string,
    method: string,
    path: string,
    body?: unknown,
): Promise<{ status: number; body: any }> {
    return page.evaluate(
        async ({ bearer, method, path, body }) => {
            const response = await fetch(path, {
                method,
                headers: {
                    Authorization: `Bearer ${bearer}`,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
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

async function deleteBoardIfExists(page: import('@playwright/test').Page, bearer: string): Promise<void> {
    await api(page, bearer, 'DELETE', `${API}/admin/boards/${SLUG}`);
}

/** 전용 게시판을 생성한다 (답변·첨부 사용, StoreBoardRequest 필수값 포함). */
async function createBoard(page: import('@playwright/test').Page, bearer: string): Promise<{ status: number; body: any }> {
    // board_manager_ids 필수값: 시드 게시판(free)의 관리자 uuid 를 재사용한다
    const freeBoard = await api(page, bearer, 'GET', `${API}/admin/boards/free`);
    const managerUuid = (freeBoard.body?.data?.board_manager_ids ?? [])[0];

    if (!managerUuid) {
        throw new Error(`manager uuid unavailable: status=${freeBoard.status} body=${JSON.stringify(freeBoard.body).slice(0, 300)}`);
    }

    return api(page, bearer, 'POST', `${API}/admin/boards`, {
        slug: SLUG,
        name: { ko: '[검수용] 답변첨부', en: 'Reply Attachment E2E' },
        type: 'basic',
        use_reply: true,
        use_file_upload: true,
        allowed_extensions: ['jpg', 'jpeg', 'png'],
        show_view_count: true,
        use_report: false,
        board_manager_ids: [managerUuid],
    });
}

/** 관리자 글 작성 API 로 게시글(또는 답변)을 만들고 id 를 돌려준다. */
async function createPost(
    page: import('@playwright/test').Page,
    bearer: string,
    title: string,
    parentId?: number,
): Promise<number> {
    const created = await api(page, bearer, 'POST', `${API}/admin/board/${SLUG}/posts`, {
        title,
        content: `${title} 본문입니다.`,
        content_mode: 'text',
        author_name: 'E2E',
        ...(parentId ? { parent_id: parentId } : {}),
    });
    if (![200, 201].includes(created.status)) {
        throw new Error(`post create failed: ${created.status} ${JSON.stringify(created.body).slice(0, 300)}`);
    }
    return created.body?.data?.id;
}

/** 8×8 JPEG 를 브라우저에서 만들어 지정한 파일명으로 첨부 업로드한다 (multipart). */
async function uploadAttachment(
    page: import('@playwright/test').Page,
    bearer: string,
    postId: number,
    filename: string,
): Promise<{ status: number; body: any }> {
    return page.evaluate(
        async ({ bearer, postId, filename, apiBase, slug }) => {
            const canvas = document.createElement('canvas');
            canvas.width = 8;
            canvas.height = 8;
            const ctx = canvas.getContext('2d')!;
            ctx.fillStyle = '#10b981';
            ctx.fillRect(0, 0, 8, 8);
            const blob: Blob = await new Promise((resolve) => canvas.toBlob((b) => resolve(b!), 'image/jpeg', 0.9));

            const form = new FormData();
            form.append('file', new File([blob], filename, { type: 'image/jpeg' }));
            form.append('post_id', String(postId));

            const response = await fetch(`${apiBase}/admin/board/${slug}/attachments`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
                body: form,
            });
            let parsed: unknown = null;
            try {
                parsed = await response.json();
            } catch {
                /* 비 JSON 응답 허용 */
            }
            return { status: response.status, body: parsed as any };
        },
        { bearer, postId, filename, apiBase: API, slug: SLUG },
    );
}

// 전용 게시판 slug 를 생성/삭제하므로 병렬 실행 시 경합한다 — 직렬 고정
test.describe.configure({ mode: 'serial' });

test.describe('관리자 게시글 상세 — 원글·답변 첨부 내려받기 (#703)', () => {
    // @scenario outcome=success
    // @effects reply_card_lists_its_own_attachments, admin_detail_downloads_post_and_reply_attachments_in_browser
    test('답변 카드에도 자기 첨부가 보이고, 두 카드 모두 토큰을 실어 원본 파일명으로 저장된다', async ({
        page,
        replyAttachmentToken,
    }) => {
        await authenticatePage(page, replyAttachmentToken);
        await page.goto('/');
        await deleteBoardIfExists(page, replyAttachmentToken);

        const created = await createBoard(page, replyAttachmentToken);
        expect([200, 201], `board create failed: ${JSON.stringify(created.body)}`).toContain(created.status);

        try {
            const parentId = await createPost(page, replyAttachmentToken, '[검수용] 첨부 원글');
            const replyId = await createPost(page, replyAttachmentToken, '[검수용] 첨부 답변', parentId);

            for (const [postId, name] of [[parentId, 'e2e-parent.jpg'], [replyId, 'e2e-reply.jpg']] as const) {
                const uploaded = await uploadAttachment(page, replyAttachmentToken, postId, name);
                expect([200, 201], `attachment upload failed: ${JSON.stringify(uploaded.body).slice(0, 300)}`).toContain(uploaded.status);
            }

            // 내려받기 요청의 Authorization 헤더를 모은다 (행위자 기록 경로)
            const downloadAuth: string[] = [];
            page.on('request', (request) => {
                if (request.url().includes(`/boards/${SLUG}/attachment/`) && !request.url().endsWith('/preview')) {
                    downloadAuth.push(request.headers()['authorization'] ?? '');
                }
            });

            await page.goto(`/admin/board/${SLUG}/post/${parentId}`);
            await expect(page.getByText('[검수용] 첨부 답변').first()).toBeVisible({ timeout: 30_000 });

            // 원글 카드 → 답변 카드 순서로 「첨부파일 (1)」 토글이 있다
            const toggles = page.getByText('첨부파일 (1)', { exact: true });
            await expect(toggles).toHaveCount(2);

            // ① 원글 첨부
            await toggles.nth(0).click();
            const parentCard = page.getByText('e2e-parent.jpg', { exact: false }).first();
            await expect(parentCard).toBeVisible();
            const [parentDownload] = await Promise.all([page.waitForEvent('download'), parentCard.click()]);
            expect(parentDownload.suggestedFilename()).toBe('e2e-parent.jpg');

            // ② 답변 첨부 — 답변 적재에 첨부가 실려야 목록이 보인다
            await toggles.nth(1).click();
            const replyCard = page.getByText('e2e-reply.jpg', { exact: false }).first();
            await expect(replyCard, '답변 카드의 첨부 목록이 비어 있습니다 (attachments 미적재)').toBeVisible();
            const [replyDownload] = await Promise.all([page.waitForEvent('download'), replyCard.click()]);
            expect(replyDownload.suggestedFilename()).toBe('e2e-reply.jpg');

            // 두 요청 모두 토큰을 실었다 (<a href> 직접 열기가 아니라 ApiClient 경유)
            expect(downloadAuth).toHaveLength(2);
            downloadAuth.forEach((header) => expect(header).toMatch(/^Bearer\s+\S+/));
        } finally {
            // 생성물 정리 — 전용 게시판 삭제 (글·첨부 포함)
            await deleteBoardIfExists(page, replyAttachmentToken);
        }
    });
});
