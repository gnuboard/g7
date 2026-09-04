/**
 * 카드형 목록 — 본문 첫 내부 이미지 썸네일 폴백 (공개 이슈 #22, 내부 #610).
 *
 * 에디터로 본문에만 이미지를 넣은 글(첨부 없음)이 카드형 목록에서 "이미지 없음"
 * placeholder 대신 실제 썸네일을 렌더하는지, 이미지 첨부가 있으면 종전대로 첨부가
 * 우선하는지를 브라우저 수준에서 실측한다. 기대 이미지 URL 은 상수로 박제하지 않고
 * 그 글 상세 API 의 thumbnail 값을 읽어 단언한다 (운영자 편집 값 박제 금지 규율).
 *
 * 단위/통합: PostContentThumbnailTest(saving 추출), PostResourceThumbnailUrlTest(폴백
 * 우선순위·비밀글 게이트), PostListContentThumbnailTest(목록 API 계약)가 담당하고,
 * 이 spec 은 카드 레이아웃 렌더(브라우저)를 담당한다.
 *
 * 전용 게시판(e2e-content-thumbnail)을 spec 안에서 생성/삭제하므로 시드 의존이 없다.
 *
 * @scenario board-content-thumbnail-fallback
 * @axes image_source=content_internal_only image_source=both image_source=none board_type=card
 * @effects content_internal_image_fills_list_thumbnail,
 *          attachment_takes_precedence_over_content_image,
 *          detail_and_og_share_same_fallback
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'e2e-content-thumbnail';
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

/** 전용 카드형 게시판을 생성한다 (StoreBoardRequest 필수값 포함). */
async function createCardBoard(page: import('@playwright/test').Page, bearer: string): Promise<{ status: number; body: any }> {
    // board_manager_ids 필수값: 시드 게시판(free)의 관리자 uuid 를 재사용한다
    const freeBoard = await api(page, bearer, 'GET', `${API}/admin/boards/free`);
    const managerUuid = (freeBoard.body?.data?.board_manager_ids ?? [])[0];

    if (!managerUuid) {
        throw new Error(`manager uuid unavailable: status=${freeBoard.status} body=${JSON.stringify(freeBoard.body).slice(0, 300)}`);
    }

    return api(page, bearer, 'POST', `${API}/admin/boards`, {
        slug: SLUG,
        name: { ko: '[검수용] 본문썸네일', en: 'Content Thumbnail E2E' },
        type: 'card',
        show_view_count: true,
        use_report: false,
        board_manager_ids: [managerUuid],
    });
}

/** 관리자 글 작성 API 로 게시글을 생성하고 id 를 돌려준다 (모델 saving 추출 경로 경유). */
async function createPost(
    page: import('@playwright/test').Page,
    bearer: string,
    title: string,
    content: string,
): Promise<number> {
    const created = await api(page, bearer, 'POST', `${API}/admin/board/${SLUG}/posts`, {
        title,
        content,
        // text 모드는 이스케이프 렌더라 추출 게이트에 걸린다 — 에디터 시나리오는 html 모드
        content_mode: 'html',
        author_name: 'E2E',
    });
    if (![200, 201].includes(created.status)) {
        throw new Error(`post create failed: ${created.status} ${JSON.stringify(created.body).slice(0, 300)}`);
    }
    return created.body?.data?.id;
}

/** 1×1 픽셀 JPEG 를 브라우저에서 만들어 첨부로 업로드한다 (multipart). */
async function uploadImageAttachment(
    page: import('@playwright/test').Page,
    bearer: string,
    postId: number,
): Promise<{ status: number; body: any }> {
    return page.evaluate(
        async ({ bearer, postId, apiBase, slug }) => {
            // canvas 로 진짜 JPEG 바이트를 생성 (mimes 검증 통과)
            const canvas = document.createElement('canvas');
            canvas.width = 8;
            canvas.height = 8;
            const ctx = canvas.getContext('2d')!;
            ctx.fillStyle = '#3b82f6';
            ctx.fillRect(0, 0, 8, 8);
            const blob: Blob = await new Promise((resolve) =>
                canvas.toBlob((b) => resolve(b!), 'image/jpeg', 0.9),
            );

            const form = new FormData();
            form.append('file', new File([blob], 'e2e-attach.jpg', { type: 'image/jpeg' }));
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
        { bearer, postId, apiBase: API, slug: SLUG },
    );
}

/** 사용자 상세 API 에서 thumbnail 값을 읽는다 (기대값의 SSoT — 상수 박제 금지). */
async function fetchDetailThumbnail(
    page: import('@playwright/test').Page,
    bearer: string,
    postId: number,
): Promise<string | null> {
    const detail = await api(page, bearer, 'GET', `${API}/boards/${SLUG}/posts/${postId}`);
    expect(detail.status).toBe(200);
    return detail.body?.data?.thumbnail ?? null;
}

// 전용 게시판 slug 를 생성/삭제하므로 병렬 실행 시 경합한다 — 직렬 고정
test.describe.configure({ mode: 'serial' });

test.describe('카드형 목록 본문 썸네일 폴백 (공개 #22)', () => {
    // @scenario image_source=content_internal_only board_type=card
    // @effects content_internal_image_fills_list_thumbnail, detail_and_og_share_same_fallback
    test('첨부 없는 본문이미지 글의 카드에 썸네일이 렌더되고 placeholder 가 없다', async ({ page, contentThumbnailToken }) => {
        await authenticatePage(page, contentThumbnailToken);
        await page.goto('/');
        await deleteBoardIfExists(page, contentThumbnailToken);

        const created = await createCardBoard(page, contentThumbnailToken);
        expect([200, 201], `board create failed: ${JSON.stringify(created.body)}`).toContain(created.status);

        // 본문에만 내부 이미지 — 첨부 없음 (favicon 은 항상 서빙되는 내부 자산)
        const postId = await createPost(
            page,
            contentThumbnailToken,
            '[검수용] 본문 이미지만 있는 글',
            '<p>본문입니다.</p><img src="/favicon.ico">',
        );

        // 기대값은 상세 API 의 thumbnail (상수 박제 금지)
        const thumbnail = await fetchDetailThumbnail(page, contentThumbnailToken, postId);
        expect(thumbnail, '상세 API thumbnail 이 본문 이미지로 채워져야 합니다').toBe('/favicon.ico');

        // 카드형 목록 렌더 실측
        await page.goto(`/board/${SLUG}`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        const card = page.locator('a, div').filter({ hasText: '[검수용] 본문 이미지만 있는 글' }).last();
        await expect(page.locator(`img[src="${thumbnail}"]`).first()).toBeVisible({ timeout: 15_000 });

        // 그 카드에는 "이미지 없음" placeholder 가 없어야 한다
        await expect(card.getByText('이미지 없음')).toHaveCount(0);
    });

    // @scenario image_source=both board_type=card
    // @effects attachment_takes_precedence_over_content_image
    test('첨부와 본문이미지가 함께 있으면 첨부 preview 가 우선한다', async ({ page, contentThumbnailToken }) => {
        await authenticatePage(page, contentThumbnailToken);
        await page.goto('/');

        // 본문 이미지 + 이미지 첨부 동시 보유 글
        const postId = await createPost(
            page,
            contentThumbnailToken,
            '[검수용] 첨부 우선 글',
            '<p>본문입니다.</p><img src="/favicon.ico">',
        );
        const uploaded = await uploadImageAttachment(page, contentThumbnailToken, postId);
        expect([200, 201], `attachment upload failed: ${JSON.stringify(uploaded.body).slice(0, 300)}`).toContain(uploaded.status);

        const thumbnail = await fetchDetailThumbnail(page, contentThumbnailToken, postId);
        expect(thumbnail, '첨부가 있으면 첨부 preview URL 이어야 합니다').toContain('/attachment/');
        expect(thumbnail).toContain('/preview');

        await page.goto(`/board/${SLUG}`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        // 카드에 첨부 preview 가 렌더된다 (본문 favicon 이 아니라)
        await expect(page.locator(`img[src="${thumbnail}"]`).first()).toBeVisible({ timeout: 15_000 });
    });

    // @scenario image_source=none board_type=card
    // @effects content_internal_image_fills_list_thumbnail
    test('이미지가 전혀 없는 글은 placeholder 가 유지된다 (회귀 없음) — 정리 포함', async ({ page, contentThumbnailToken }) => {
        await authenticatePage(page, contentThumbnailToken);
        await page.goto('/');

        const postId = await createPost(
            page,
            contentThumbnailToken,
            '[검수용] 이미지 없는 글',
            '<p>이미지 없이 텍스트만 있는 본문입니다.</p>',
        );

        const thumbnail = await fetchDetailThumbnail(page, contentThumbnailToken, postId);
        expect(thumbnail).toBeNull();

        await page.goto(`/board/${SLUG}`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        // 이미지 없는 글의 카드에는 placeholder("이미지 없음")가 렌더된다
        await expect(page.getByText('이미지 없음').first()).toBeVisible({ timeout: 15_000 });

        // 생성물 정리 — 전용 게시판 삭제 (글·첨부 포함)
        await deleteBoardIfExists(page, contentThumbnailToken);
    });
});
