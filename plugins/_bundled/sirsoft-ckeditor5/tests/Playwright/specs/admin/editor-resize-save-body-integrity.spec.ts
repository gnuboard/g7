/**
 * E2E: 브라우저 폭이 바뀐 뒤 저장해도 편집기 본문이 사라지지 않는다 (공개 이슈 #130)
 *
 * 배경: 엔진은 폼 상태를 React `localDynamicState`(저장소 A)와 `globalState._local`(저장소 B)에
 * 이중 저장한다. CKEditor 는 `setLocal({ render:false, selfManaged:true })` 로 **B 에만** 본문을
 * 쓴다 — 그 호출은 React 렌더를 한 번도 일으키지 않으므로 `context.state` 는 입력 이전 스냅샷에
 * 머문다. 여기에 **브레이크포인트를 넘지 않는 폭 변경**(19px 로 충분)이 겹치면 의존성 배열 없는
 * `useLayoutEffect` 가 `__g7PendingLocalState` 를 null 로 지우고, 저장 시 setState 의 base 가
 * stale A 로 떨어져 B 와 sequence 반환값이 함께 오염된다.
 *
 * 증상은 화면에 드러나지 않는다:
 *   - 작성 화면: `내용은 필수입니다` 422
 *   - 수정 화면: **성공 토스트가 뜨고 직전 본문이 저장되어 편집분이 사라진다**
 *   - 콘솔 에러 0건
 *
 * 그래서 확인해야 하는 것은 "저장됐다" 가 아니라 **요청 body 의 content** 다.
 *
 * 결정화(없으면 간헐적 false green):
 *   1. 타이핑 후 저장소 B 에 마커가 실릴 때까지 poll (CKEditor 디바운스 발화 확정)
 *   2. 폭 변경
 *   3. `__g7PendingLocalState === null` 이 될 때까지 poll (리렌더 완료 확정)
 *   4. 저장 클릭
 *
 * 준수 사항:
 *   - 본문 입력은 `pressSequentially` — `fill`/`editor.setData()` 는 `change:data` → `syncToForm`
 *     경로를 타지 않아 재현 조건 자체가 성립하지 않는다
 *   - 화면 진입 후에는 `page.goto` 금지 (전체 새로고침이 모든 렌더러를 재마운트해 조건이 사라진다)
 *   - 입력 순서는 **제목 먼저 → 본문 나중** 고정 (본문 뒤에 다른 입력이 오면 그 렌더가 memo 를
 *     재계산시켜 조건이 사라진다)
 *
 * @see 트러블슈팅 사례 40 (저장소 B 통째 교체 + stale 반환값)
 */
import { test, expect, authenticatePage } from '../../fixtures/ckeditor5-auth';
import type { Page } from '@playwright/test';

const BOARD = 'notice';
const API = `/api/modules/sirsoft-board/admin/board/${BOARD}/posts`;

/** 브레이크포인트를 넘지 않는 폭 변경 (desktop 구간 안에서만 움직인다) */
const SAME_BREAKPOINT = { from: { width: 1440, height: 900 }, to: { width: 1421, height: 900 } };
/** 브레이크포인트를 넘는 폭 변경 (desktop → tablet) */
const CROSSED_BREAKPOINT = { from: { width: 1034, height: 900 }, to: { width: 1015, height: 900 } };

test.describe.configure({ mode: 'serial' });

/**
 * 저장소 B(`globalState._local`) 에 마커가 실릴 때까지 기다립니다 — 디바운스 발화 확정.
 *
 * @param page Playwright 페이지
 * @param marker 본문에 넣은 마커 문자열
 */
async function waitForDebounceFlush(page: Page, marker: string): Promise<void> {
    await expect
        .poll(
            () =>
                page.evaluate(() => {
                    const w = window as any;
                    const c = w.__templateApp?.getGlobalState?.()?._local?.form?.content;
                    return typeof c === 'string' ? c : JSON.stringify(c ?? '');
                }),
            { timeout: 15_000, message: 'CKEditor 디바운스가 저장소 B 에 본문을 기록해야 한다' },
        )
        .toContain(marker);
}

/**
 * 폭 변경이 `__g7PendingLocalState` 를 비울 때까지 기다립니다 — 리렌더 완료 확정.
 *
 * @param page Playwright 페이지
 */
async function waitForPendingCleared(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => (window as any).__g7PendingLocalState === null), {
            timeout: 15_000,
            message: '폭 변경 리렌더가 __g7PendingLocalState 를 비워야 한다 (재현 조건의 절반)',
        })
        .toBe(true);
}

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

test.describe('폭 변경 후 저장 — 편집기 본문 보존', () => {
    test.beforeEach(({}, testInfo) => {
        testInfo.setTimeout(180_000);
    });

    /**
     * 작성 화면 본문: 폭 변경 후 저장하고 요청 body 를 검사합니다.
     *
     * `@scenario` 마커는 축 값을 담으므로 각 `test` 안에 **리터럴로** 둔다 —
     * 템플릿 리터럴로 만들면 주석 안에서 보간되지 않아 죽은 마커가 되고,
     * 게이트는 그것을 조용히 통과시킨다.
     *
     * @param page Playwright 페이지
     * @param editorToken 권한 토큰
     * @param viewport 폭 변경 전/후 뷰포트
     */
    async function runCreateFlow(
        page: Page,
        editorToken: string,
        viewport: { from: { width: number; height: number }; to: { width: number; height: number } },
    ): Promise<void> {
        const marker = `RESIZE-CREATE-${Date.now()}`;
        const consoleErrors: string[] = [];
        page.on('console', (m) => {
            if (m.type() === 'error') consoleErrors.push(m.text());
        });

        await page.setViewportSize(viewport.from);
        await authenticatePage(page, editorToken);
        await page.goto(`/admin/board/${BOARD}/create`);

        const editor = page.locator('.ck-editor__editable').first();
        await expect(editor).toBeVisible({ timeout: 30_000 });

        // 제목 먼저 → 본문 나중 (순서 고정)
        await page.locator('input[name="title"]').pressSequentially(`제목 ${marker}`);
        await editor.click();
        await editor.pressSequentially(marker);

        await waitForDebounceFlush(page, marker);          // ① 디바운스 발화 확정

        // 주장하는 것은 "이 앱에 콘솔 에러가 하나도 없다" 가 아니라 **폭 변경 → 저장 구간이
        // 조용하다** 는 것이다. 진입 시점의 에러(환경별 자산 404 등)까지 싸잡으면 이 축이
        // 결함과 무관한 이유로 red 가 되어, 정작 지켜야 할 "조용함" 이 신호를 잃는다.
        consoleErrors.length = 0;

        await page.setViewportSize(viewport.to);           // ② 폭 변경
        await waitForPendingCleared(page);                 // ③ 리렌더 완료 확정

        const [request, response] = await Promise.all([
            page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'POST'),
            page.waitForResponse((r) => r.url().includes('/posts') && r.request().method() === 'POST'),
            page.locator('#footer_save_button').click(),   // ④ 저장
        ]);

        expect(String(request.postDataJSON().content ?? ''), '요청 body 의 본문에 입력한 마커가 있어야 한다').toContain(marker);
        expect(response.status(), '저장이 422 가 아니어야 한다').toBeLessThan(400);
        expect(consoleErrors, '폭 변경 → 저장 구간은 조용해야 한다 (이 결함은 콘솔에 아무것도 남기지 않으므로 조용함 자체를 고정한다)').toEqual([]);
    }

    /**
     * 수정 화면 본문: 폭 변경 후 저장하고 요청 body 와 DB 반영을 함께 검사합니다.
     *
     * @param page Playwright 페이지
     * @param request Playwright 요청 컨텍스트
     * @param editorToken 권한 토큰
     * @param viewport 폭 변경 전/후 뷰포트
     */
    async function runEditFlow(
        page: Page,
        request: import('@playwright/test').APIRequestContext,
        editorToken: string,
        viewport: { from: { width: number; height: number }; to: { width: number; height: number } },
    ): Promise<void> {
        const original = `ORIGINAL-${Date.now()}`;
        const added = `ADDED-${Date.now()}`;
        const id = await createPost(request, editorToken, `수정대상 ${original}`, `<p>${original}</p>`);

        await page.setViewportSize(viewport.from);
        await authenticatePage(page, editorToken);
        await page.goto(`/admin/board/${BOARD}/${id}/edit`);

        const editor = page.locator('.ck-editor__editable').first();
        await expect(editor).toContainText(original, { timeout: 30_000 });

        // 제목 먼저 → 본문 나중 (순서 고정).
        //
        // 제목을 함께 건드리는 것은 편의가 아니라 필수다 — 본문만 고치면 저장 버튼이 계속
        // disabled 로 남아 클릭이 무반응이 된다. CKEditor 의 setLocal({render:false}) 은 저장소 B
        // 의 hasChanges 만 true 로 만들고 React 렌더를 일으키지 않아, 버튼의 disabled 바인딩
        // (저장소 A 기준)이 재평가되지 않기 때문이다. 이 결함은 #130 수정 범위 밖의 **표시 축**
        // 이며(저장소 A 를 건드리는 경로는 2026-04-22 철회 이력이 있는 영역이다), 여기서는
        // 재현 조건을 성립시키기 위해 제목을 먼저 건드린다.
        await page.locator('input[name="title"]').pressSequentially('!');
        await editor.click();
        await editor.pressSequentially(added);

        await waitForDebounceFlush(page, added);
        await page.setViewportSize(viewport.to);
        await waitForPendingCleared(page);

        const [saveRequest, saveResponse] = await Promise.all([
            page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'PUT'),
            page.waitForResponse((r) => r.url().includes('/posts') && r.request().method() === 'PUT'),
            page.locator('#footer_save_button').click(),
        ]);

        const sent = String(saveRequest.postDataJSON().content ?? '');
        expect(sent, '원본 본문이 남아 있어야 한다').toContain(original);
        expect(sent, '방금 입력한 편집분이 실려야 한다 — 이것이 조용한 손실 축이다').toContain(added);
        expect(saveResponse.status(), '수정 저장이 성공해야 한다').toBeLessThan(400);

        // 서버에 실제로 반영됐는지까지 본다 — 이 화면은 실패해도 성공 토스트가 뜨므로
        // 화면 피드백으로는 판정할 수 없다. 응답을 기다린 뒤 재조회한다.
        const check = await request.get(`${API}/${id}`, {
            headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' },
        });
        expect(String((await check.json()).data.content ?? ''), 'DB 에 편집분이 남아야 한다').toContain(added);
    }

    test('작성: 같은 브레이크포인트 안에서 폭을 바꿔도 본문이 그대로 전송된다', async ({ page, editorToken }) => {
        // @scenario save_flow=create, resize_kind=same_breakpoint
        // @effects request_body_content_matches_editor_getdata, create_save_returns_2xx_not_422, canonical_local_keeps_editor_content_after_resize, resize_save_emits_no_console_errors
        await runCreateFlow(page, editorToken, SAME_BREAKPOINT);
    });

    test('작성: 브레이크포인트를 넘겨 폭을 바꿔도 본문이 그대로 전송된다', async ({ page, editorToken }) => {
        // @scenario save_flow=create, resize_kind=crossed_breakpoint
        // @effects request_body_content_matches_editor_getdata, create_save_returns_2xx_not_422, canonical_local_keeps_editor_content_after_resize, resize_save_emits_no_console_errors
        await runCreateFlow(page, editorToken, CROSSED_BREAKPOINT);
    });

    test('수정: 같은 브레이크포인트 안에서 폭을 바꿔도 편집분이 유실되지 않는다', async ({ page, request, editorToken }) => {
        // @scenario save_flow=edit, resize_kind=same_breakpoint
        // @effects request_body_content_matches_editor_getdata, edit_save_persists_typed_addition, canonical_local_keeps_editor_content_after_resize, success_toast_only_when_content_actually_saved
        await runEditFlow(page, request, editorToken, SAME_BREAKPOINT);
    });

    test('수정: 브레이크포인트를 넘겨 폭을 바꿔도 편집분이 유실되지 않는다', async ({ page, request, editorToken }) => {
        // @scenario save_flow=edit, resize_kind=crossed_breakpoint
        // @effects request_body_content_matches_editor_getdata, edit_save_persists_typed_addition, canonical_local_keeps_editor_content_after_resize, success_toast_only_when_content_actually_saved
        await runEditFlow(page, request, editorToken, CROSSED_BREAKPOINT);
    });

    test('대조군(작성): 폭을 바꾸지 않으면 수정 전에도 정상이다', async ({ page, editorToken }) => {
        // @scenario save_flow=create, resize_kind=none
        // @effects save_without_resize_unaffected, create_save_returns_2xx_not_422
        const marker = `NORESIZE-CREATE-${Date.now()}`;

        await page.setViewportSize(SAME_BREAKPOINT.from);
        await authenticatePage(page, editorToken);
        await page.goto(`/admin/board/${BOARD}/create`);

        const editor = page.locator('.ck-editor__editable').first();
        await expect(editor).toBeVisible({ timeout: 30_000 });

        await page.locator('input[name="title"]').pressSequentially(`제목 ${marker}`);
        await editor.click();
        await editor.pressSequentially(marker);
        await waitForDebounceFlush(page, marker);

        // 폭을 바꾸지 않으므로 pending 이 살아 있어야 한다 — 결함 모델의 전제
        expect(
            await page.evaluate(() => (window as any).__g7PendingLocalState !== null),
            '폭을 바꾸지 않았으므로 pending 이 살아 있어야 한다',
        ).toBe(true);

        const [request, response] = await Promise.all([
            page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'POST'),
            page.waitForResponse((r) => r.url().includes('/posts') && r.request().method() === 'POST'),
            page.locator('#footer_save_button').click(),
        ]);

        expect(String(request.postDataJSON().content ?? '')).toContain(marker);
        expect(response.status()).toBeLessThan(400);
    });

    test('대조군(수정): 폭을 바꾸지 않으면 편집분이 그대로 저장된다', async ({ page, request, editorToken }) => {
        // @scenario save_flow=edit, resize_kind=none
        // @effects save_without_resize_unaffected, edit_save_persists_typed_addition
        const original = `NORESIZE-ORIG-${Date.now()}`;
        const added = `NORESIZE-ADD-${Date.now()}`;
        const id = await createPost(request, editorToken, `대조군 ${original}`, `<p>${original}</p>`);

        await page.setViewportSize(SAME_BREAKPOINT.from);
        await authenticatePage(page, editorToken);
        await page.goto(`/admin/board/${BOARD}/${id}/edit`);

        const editor = page.locator('.ck-editor__editable').first();
        await expect(editor).toContainText(original, { timeout: 30_000 });

        // 제목 먼저 → 본문 나중 (순서 고정).
        //
        // 제목을 함께 건드리는 것은 편의가 아니라 필수다 — 본문만 고치면 저장 버튼이 계속
        // disabled 로 남아 클릭이 무반응이 된다. CKEditor 의 setLocal({render:false}) 은 저장소 B
        // 의 hasChanges 만 true 로 만들고 React 렌더를 일으키지 않아, 버튼의 disabled 바인딩
        // (저장소 A 기준)이 재평가되지 않기 때문이다. 이 결함은 #130 수정 범위 밖의 **표시 축**
        // 이며(저장소 A 를 건드리는 경로는 2026-04-22 철회 이력이 있는 영역이다), 여기서는
        // 재현 조건을 성립시키기 위해 제목을 먼저 건드린다.
        await page.locator('input[name="title"]').pressSequentially('!');
        await editor.click();
        await editor.pressSequentially(added);
        await waitForDebounceFlush(page, added);

        const [request2] = await Promise.all([
            page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'PUT'),
            page.locator('#footer_save_button').click(),
        ]);

        const sent = String(request2.postDataJSON().content ?? '');
        expect(sent).toContain(original);
        expect(sent).toContain(added);
    });

    test('폭 변경 뒤에도 저장소 B 는 본문을 들고 있다 (setState 가 덮지 않는다)', async ({ page, editorToken }) => {
        // @scenario save_flow=create, resize_kind=same_breakpoint
        // @effects setstate_local_preserves_canonical_keys_when_pending_is_null, canonical_local_keeps_editor_content_after_resize
        const marker = `CANONICAL-${Date.now()}`;

        await page.setViewportSize(SAME_BREAKPOINT.from);
        await authenticatePage(page, editorToken);
        await page.goto(`/admin/board/${BOARD}/create`);

        const editor = page.locator('.ck-editor__editable').first();
        await expect(editor).toBeVisible({ timeout: 30_000 });

        await page.locator('input[name="title"]').pressSequentially(`제목 ${marker}`);
        await editor.click();
        await editor.pressSequentially(marker);

        await waitForDebounceFlush(page, marker);
        await page.setViewportSize(SAME_BREAKPOINT.to);
        await waitForPendingCleared(page);

        // pending 이 null 인 상태에서 setState(local) 을 한 번 태운다 — 저장 sequence 의 첫 액션과 동형
        await page.evaluate(() =>
            (window as any).G7Core.dispatch({
                handler: 'setState',
                params: { target: 'local', isSaving: true, errors: null },
            }),
        );

        const canonical = await page.evaluate(() => {
            const c = (window as any).__templateApp?.getGlobalState?.()?._local?.form?.content;
            return typeof c === 'string' ? c : JSON.stringify(c ?? '');
        });
        expect(canonical, 'setState 가 저장소 B 의 본문을 지우지 않아야 한다').toContain(marker);
    });

    test('폭 변경 뒤 자동바인딩 입력이 편집분을 되돌리지 않는다 (사례 41)', async ({ page, editorToken }) => {
        // @scenario save_flow=edit, resize_kind=same_breakpoint
        // @effects autobinding_keystroke_preserves_editor_content, pending_snapshot_carries_forced_overlay
        //
        // engine-v1.63.4. 같은 뿌리(저장소 B 통째 교체)의 **다른 방아쇠**다 — 손실이 저장 클릭이
        // 아니라 그 앞의 키입력에서 일어나므로 engine-v1.63.3 의 수정은 이 경로를 지나가지 않는다.
        //
        // 순서가 중요하다: 본문 편집 → 폭 변경 → **제목 입력** → 저장.
        // 폭 변경으로 pending 이 비워진 뒤의 자동바인딩 키입력이 stale 한 저장소 A 스냅샷을
        // pending 에 실으면, 이어지는 setLocal 이 그것을 base 로 채택해 B 를 통째 교체한다.
        //
        // 판정은 화면이 아니라 요청 body 로 한다 — 수정 화면은 성공 토스트가 뜨고 서버 원본이
        // 저장되므로 화면 피드백이 근거가 되지 못한다.
        const stamp = Date.now();
        const original = `AUTOBIND-ORIG-${stamp}`;
        const added = `AUTOBIND-EDIT-${stamp}`;

        await page.setViewportSize(SAME_BREAKPOINT.from);
        await authenticatePage(page, editorToken);

        // 원본 글을 만든다 (리사이즈 없이 — 대조군과 같은 경로)
        await page.goto(`/admin/board/${BOARD}/create`);
        const createEditor = page.locator('.ck-editor__editable').first();
        await expect(createEditor).toBeVisible({ timeout: 30_000 });
        await page.locator('input[name="title"]').pressSequentially(`제목 ${stamp}`);
        await createEditor.click();
        await createEditor.pressSequentially(original);
        await waitForDebounceFlush(page, original);

        const [createRequest, createResponse] = await Promise.all([
            page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'POST'),
            page.waitForResponse((r) => r.url().includes('/posts') && r.request().method() === 'POST'),
            page.locator('#footer_save_button').click(),
        ]);
        expect(String(createRequest.postDataJSON().content ?? '')).toContain(original);
        const postId = (await createResponse.json())?.data?.id ?? null;
        expect(postId, '원본 글 id 를 받아야 한다').not.toBeNull();

        try {
            // 수정 화면 — 본문을 고치고, 폭을 바꾸고, 그 뒤에 제목을 건드린다
            await page.goto(`/admin/board/${BOARD}/${postId}/edit`);
            const editor = page.locator('.ck-editor__editable').first();
            await expect(editor).toBeVisible({ timeout: 30_000 });
            await expect(editor).toContainText(original, { timeout: 30_000 });

            await editor.click();
            await editor.pressSequentially(added);
            await waitForDebounceFlush(page, added);          // ① 디바운스 발화 확정

            await page.setViewportSize(SAME_BREAKPOINT.to);   // ② 폭 변경
            await waitForPendingCleared(page);                // ③ pending 클리어 확정

            // ④ 자동바인딩 키입력 — 여기서 편집분이 사라졌다
            await page.locator('input[name="title"]').pressSequentially('!');

            await expect
                .poll(
                    async () =>
                        await page.evaluate(() => {
                            const c = (window as any).__templateApp?.getGlobalState?.()?._local?.form?.content;
                            return typeof c === 'string' ? c : JSON.stringify(c ?? '');
                        }),
                    { timeout: 20_000 },
                )
                .toContain(added);

            const [request] = await Promise.all([
                page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'PUT'),
                page.locator('#footer_save_button').click(),
            ]);

            const sent = String(request.postDataJSON().content ?? '');
            expect(sent, '자동바인딩 키입력 뒤에도 편집분이 전송돼야 한다').toContain(added);
            expect(sent, '원본도 함께 남아야 한다').toContain(original);
        } finally {
            if (postId !== null) {
                const del = await page.request.delete(
                    `/api/modules/sirsoft-board/admin/board/${BOARD}/posts/${postId}`,
                    { headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' } },
                );
                expect(del.ok(), `정리 삭제가 성공해야 한다 (status ${del.status()})`).toBeTruthy();
            }
        }
    });

});
