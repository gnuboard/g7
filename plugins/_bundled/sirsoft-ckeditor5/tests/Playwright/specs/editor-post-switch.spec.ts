/**
 * E2E: 글을 옮겨 다녀도 이전 글의 본문이 새 글에 새지 않는다
 *
 * 배경: 편집기는 `html-editor.json` 의 `onMount` 에서만 만들어진다. 관리자 화면에서
 * 글 A 수정 → 글 B 수정 으로 옮기는 이동은 **같은 레이아웃**이라 컴포넌트가 언마운트되지
 * 않아 그 훅이 다시 발화하지 않는다. 편집기는 글 A 의 본문을 그대로 보여주고, 폼 상태만
 * 글 B 의 값으로 바뀐다.
 *
 * 그 상태에서 한 글자만 입력하면 `change:data` 가 **화면에 보이는 글 A 의 본문**을 글 B 의
 * `form.content` 에 써 넣는다. 저장하면 글 B 의 본문이 글 A 의 것으로 통째로 대체된다 —
 * 예외도 경고도 남지 않고, 운영자에게는 "원래 그런 내용이던 글" 로 보인다.
 *
 * 그래서 브라우저에서 확인해야 하는 것은 "편집기가 뜬다" 가 아니라 아래 셋이다.
 *
 *  1. 글을 옮기면 편집기가 **새 글의 본문**을 보여준다
 *  2. 옮긴 뒤 입력한 내용이 **새 글의 본문에** 붙는다 (이전 글 본문이 섞이지 않는다)
 *  3. 되돌아오면 원래 글의 본문이 그대로 보인다
 */
import { test, expect, authenticatePage } from '../fixtures/ckeditor5-auth';

const BOARD = 'notice';
const API = `/api/modules/sirsoft-board/admin/board/${BOARD}/posts`;

/**
 * 게시글을 API 로 만들고 id 를 돌려줍니다.
 *
 * @param request Playwright 요청 컨텍스트
 * @param token 권한 토큰
 * @param title 제목
 * @param content 본문 (HTML)
 * @return 생성된 게시글 id
 */
async function createPost(
    request: import('@playwright/test').APIRequestContext,
    token: string,
    title: string,
    content: string,
): Promise<number> {
    const res = await request.post(API, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        data: { title, content, content_mode: 'html' },
    });

    expect(res.ok(), `게시글 생성이 성공해야 한다 (status ${res.status()})`).toBeTruthy();

    return (await res.json()).data.id as number;
}

test.describe('글 전환 시 본문 격리', () => {
    test.beforeEach(({}, testInfo) => {
        testInfo.setTimeout(120_000);
    });

    test('다른 글로 옮기면 편집기가 그 글의 본문으로 다시 선다', async ({ page, editorToken }) => {
        // @scenario asset_class=vendored, outcome=loaded
        // @effects runtime_asset_served_same_origin
        const stamp = Date.now();
        const bodyA = `<p>A 글 본문 ${stamp} 입니다.</p>`;
        const bodyB = `<p>B 글 본문 ${stamp} 입니다. 길이를 다르게 둔다.</p>`;

        const idA = await createPost(page.request, editorToken, `A 전환 점검 ${stamp}`, bodyA);
        const idB = await createPost(page.request, editorToken, `B 전환 점검 ${stamp}`, bodyB);

        try {
            await authenticatePage(page, editorToken);

            await page.goto(`/admin/board/${BOARD}/${idA}/edit`);
            await expect(page.locator('.ck-editor__editable')).toContainText(`A 글 본문 ${stamp}`, { timeout: 30_000 });

            // 레이아웃이 같아 컴포넌트가 언마운트되지 않는 이동 — 앱 자신의 라우터로 옮긴다
            await page.evaluate(
                (path) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path } }),
                `/admin/board/${BOARD}/${idB}/edit`,
            );

            // 1. 편집기가 B 의 본문으로 다시 서야 한다
            await expect(page.locator('.ck-editor__editable')).toContainText(`B 글 본문 ${stamp}`, { timeout: 30_000 });
            await expect(page.locator('.ck-editor__editable')).not.toContainText(`A 글 본문 ${stamp}`);

            // 2. 여기서 입력한 내용은 B 의 본문에 붙어야 한다
            await page.locator('.ck-editor__editable').click();
            await page.keyboard.type('추가입력');

            await expect
                .poll(
                    () => page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.form?.content ?? ''),
                    { timeout: 15_000 },
                )
                .toContain('추가입력');

            const formContent = await page.evaluate(
                () => (window as any).G7Core?.state?.getLocal?.()?.form?.content ?? '',
            );
            expect(formContent).toContain(`B 글 본문 ${stamp}`);
            expect(formContent).not.toContain(`A 글 본문 ${stamp}`);

            // 3. 되돌아가면 A 의 본문이 그대로여야 한다
            await page.evaluate(
                (path) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path } }),
                `/admin/board/${BOARD}/${idA}/edit`,
            );
            await expect(page.locator('.ck-editor__editable')).toContainText(`A 글 본문 ${stamp}`, { timeout: 30_000 });
            await expect(page.locator('.ck-editor__editable')).not.toContainText(`B 글 본문 ${stamp}`);
        } finally {
            for (const id of [idA, idB]) {
                await page.request.delete(`${API}/${id}`, {
                    headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' },
                });
            }
        }
    });
});
