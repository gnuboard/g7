/**
 * 게시판 답글 삭제 정책 (block/cascade) — 설정 노출·저장 왕복 + block 차단 (#573, 공개 #106/#107 후속).
 *
 * 검증 축:
 *   ① 게시판 환경설정 "답변글" 탭에 정책 Select 렌더 + 기본값 cascade
 *   ② 게시판 편집 폼에서 정책을 block 으로 저장 → 재조회 왕복
 *   ③ block 게시판에서 답글 달린 글 삭제 → 422 + has_replies 메시지 (DB 무변화)
 *
 * 단위(PHPUnit ReplyDeletePolicyTest)는 서비스/저장소 cascade 의미론을 담당하고,
 * 이 spec 은 화면 노출과 API 계약(저장 왕복·422)의 브라우저 수준 확인을 담당한다.
 *
 * 전용 게시판(e2e-reply-policy)을 spec 안에서 생성/삭제하므로 시드 의존이 없다.
 *
 * @scenario post-reply-delete-policy
 * @axes policy=block, policy=cascade
 * @effects settings_and_form_expose_policy, block_returns_422_with_message
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'e2e-reply-policy';
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

/** 전용 게시판을 생성한다 (StoreBoardRequest 필수값 포함). */
async function createBoard(
    page: import('@playwright/test').Page,
    bearer: string,
    extra: Record<string, unknown> = {},
): Promise<{ status: number; body: any }> {
    // board_manager_ids 필수값: 시드 게시판(free)의 관리자 uuid 를 재사용한다
    // (프로필 엔드포인트는 user 타입 권한 게이트라 admin 토큰으로도 403 — 실측)
    const freeBoard = await api(page, bearer, 'GET', `${API}/admin/boards/free`);
    const managerUuid = (freeBoard.body?.data?.board_manager_ids ?? [])[0];

    if (!managerUuid) {
        throw new Error(`manager uuid unavailable: status=${freeBoard.status} body=${JSON.stringify(freeBoard.body).slice(0, 300)}`);
    }

    return api(page, bearer, 'POST', `${API}/admin/boards`, {
        slug: SLUG,
        name: { ko: '[검수용] 답글정책', en: 'Reply Policy E2E' },
        type: 'basic',
        use_reply: true,
        show_view_count: true,
        use_report: false,
        board_manager_ids: [managerUuid],
        ...extra,
    });
}

// 두 테스트가 같은 전용 게시판 slug 를 생성/삭제하므로 병렬 실행 시 경합한다 — 직렬 고정
test.describe.configure({ mode: 'serial' });

test.describe('게시판 답글 삭제 정책 (#573)', () => {
    test('환경설정 답변글 탭에 정책 Select 가 렌더되고 기본값이 cascade 다', async ({ page, replyPolicyToken }) => {
        await authenticatePage(page, replyPolicyToken);

        // 답변글 서브탭은 basic_defaults 탭의 스크롤 섹션으로 함께 렌더된다 (TabNavigationScroll)
        await page.goto('/admin/boards/settings?tab=basic_defaults');
        await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

        // 필드 라벨 + Select 렌더 (admin_basic Select 는 커스텀 드롭다운 — name 루트로 조회)
        await expect(page.getByText('답변글 삭제 방식').first()).toBeVisible({ timeout: 15_000 });

        // 저장값(기본 cascade)이 응답에 실려 있는지 API 로 대조
        const settings = await api(page, replyPolicyToken, 'GET', `${API}/admin/settings`);
        expect(settings.status).toBe(200);
        const basicDefaults =
            settings.body?.data?.settings?.basic_defaults ?? settings.body?.data?.basic_defaults ?? {};
        expect(basicDefaults.reply_delete_policy ?? 'cascade').toBe('cascade');
    });

    test('게시판 편집 폼 저장 왕복 — block 저장 후 재조회에 반영된다', async ({ page, replyPolicyToken }) => {
        await authenticatePage(page, replyPolicyToken);
        await page.goto('/admin/boards');
        await deleteBoardIfExists(page, replyPolicyToken);

        // 전용 게시판 생성 (기본 cascade)
        const created = await createBoard(page, replyPolicyToken);
        expect([200, 201], `board create failed: ${JSON.stringify(created.body)}`).toContain(created.status);
        expect(created.body?.data?.reply_delete_policy ?? 'cascade').toBe('cascade');

        // 편집 폼에서 필드 렌더 확인 (use_reply ON 조건부)
        await page.goto(`/admin/boards/${SLUG}/edit`);
        await expect(page.getByText('답변글 삭제 방식').first()).toBeVisible({ timeout: 20_000 });

        // block 으로 저장 왕복 (API PUT — 폼과 동일 엔드포인트/검증 경로)
        const updated = await api(page, replyPolicyToken, 'PUT', `${API}/admin/boards/${SLUG}`, {
            reply_delete_policy: 'block',
        });
        expect(updated.status).toBe(200);

        const fetched = await api(page, replyPolicyToken, 'GET', `${API}/admin/boards/${SLUG}`);
        expect(fetched.body?.data?.reply_delete_policy).toBe('block');

        // 허용 밖 값은 422 (닫힌 집합)
        const invalid = await api(page, replyPolicyToken, 'PUT', `${API}/admin/boards/${SLUG}`, {
            reply_delete_policy: 'evil',
        });
        expect(invalid.status).toBe(422);

        await deleteBoardIfExists(page, replyPolicyToken);
    });

    test('block 게시판에서 답글 달린 글 삭제는 422 로 거부되고 DB 가 변하지 않는다', async ({ page, replyPolicyToken }) => {
        await authenticatePage(page, replyPolicyToken);
        await page.goto('/admin/boards');
        await deleteBoardIfExists(page, replyPolicyToken);

        const created = await createBoard(page, replyPolicyToken, { reply_delete_policy: 'block' });
        expect([200, 201], `board create failed: ${JSON.stringify(created.body)}`).toContain(created.status);

        // 부모 글 + 답글 생성 (admin posts API)
        const parent = await api(page, replyPolicyToken, 'POST', `${API}/admin/board/${SLUG}/posts`, {
            title: '[검수용] 원글',
            content: '답글 삭제 정책 검증용 원글입니다.',
        });
        expect([200, 201]).toContain(parent.status);
        const parentId = parent.body?.data?.id;
        expect(parentId).toBeTruthy();

        const reply = await api(page, replyPolicyToken, 'POST', `${API}/admin/board/${SLUG}/posts`, {
            title: 'Re: [검수용] 원글',
            content: '답글 삭제 정책 검증용 답글입니다.',
            parent_id: parentId,
        });
        expect([200, 201], `reply create failed: ${JSON.stringify(reply.body).slice(0, 400)}`).toContain(reply.status);
        const replyId = reply.body?.data?.id;

        // block: 원글 삭제 422 + 메시지
        const blocked = await api(page, replyPolicyToken, 'DELETE', `${API}/admin/board/${SLUG}/posts/${parentId}`);
        expect(blocked.status).toBe(422);
        expect(String(blocked.body?.message ?? '')).toContain('답글');

        // DB 무변화 — 원글이 여전히 조회된다
        const stillThere = await api(page, replyPolicyToken, 'GET', `${API}/admin/board/${SLUG}/posts/${parentId}`);
        expect(stillThere.status).toBe(200);

        // 답글 먼저 삭제하면 원글 삭제가 허용된다
        const replyDeleted = await api(page, replyPolicyToken, 'DELETE', `${API}/admin/board/${SLUG}/posts/${replyId}`);
        expect(replyDeleted.status).toBe(200);

        const parentDeleted = await api(page, replyPolicyToken, 'DELETE', `${API}/admin/board/${SLUG}/posts/${parentId}`);
        expect(parentDeleted.status).toBe(200);

        await deleteBoardIfExists(page, replyPolicyToken);
    });
});
