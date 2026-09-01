/**
 * E2E: 편집기 자산 실패 시 평문 입력 폴백 (공개 #123)
 *
 * 배경: CKEditor5 본체를 못 불러오면 `html-editor.json` 의 `mode: "replace"` 때문에
 * 코어 HtmlEditor 도 렌더되지 않아 빈 `<div>` 만 남았다. 사용자에게는 "입력창 자리가
 * 비어 있다" 로만 보였고 — 글을 쓸 방법이 아예 없었다. 실패 처리는 `console.error`
 * 한 줄이 전부라 자체 서버 로그에도 흔적이 남지 않았다.
 *
 * 브라우저에서 확인해야 하는 것은 "폴백 코드가 있다" 가 아니라 아래 넷이다.
 *
 *  1. 자산이 실패하면 안내가 뜨고 입력창이 선다 (빈 화면이 남지 않는다)
 *  2. 그 입력창으로 실제로 작성하고 **저장**할 수 있다 (폴백이 저장까지 이어진다)
 *  3. 저장된 내용이 새로고침 뒤에도 남는다 (평문 계약이 서버까지 도달한다)
 *  4. 차단을 풀고 [다시 시도] 하면 편집기가 서고, 입력해 둔 내용이 승계된다
 *
 * 자산 차단은 `page.route()` 로 **결정적으로** 만든다 — 실제 네트워크 상태에 기대면
 * 재현되지 않는 날이 생긴다.
 */
import { test, expect, authenticatePage } from '../fixtures/ckeditor5-auth';

/**
 * 편집기 본체 자산 매처.
 *
 * 글로브가 아니라 정규식인 이유: 이 자산의 URL 형태는 `general.asset_url_mode` 와 정적 게시
 * 여부에 따라 셋으로 갈린다 —
 *   1. `/api/plugins/assets/{id}/dist/vendor/ckeditor5/{ver}/ckeditor5.umd.js` (확장자 모드)
 *   2. `/api/plugins/assets/{id}?file=dist%2Fvendor%2Fckeditor5%2F{ver}%2Fckeditor5.umd.js` (extensionless — 구분자가 %2F 로 인코딩된다)
 *   3. `/build/ext/{v}/plugins/{id}/assets/vendor/ckeditor5/{ver}/ckeditor5.umd.js` (정적 게시)
 * 경로 글로브(`**\/vendor/...`)는 2번에서 절대 매칭되지 않아 차단이 조용히 무효가 되고,
 * 그러면 이 spec 전체가 "폴백이 안 뜬다" 로 실패한다. 파일명만 보면 세 형태 모두 걸린다.
 * (번역 파일 `translations/ko.umd.js` 는 파일명이 달라 걸리지 않는다 — 본체만 차단한다.)
 */
const EDITOR_BUNDLE = /ckeditor5\.umd\.js/;

test.describe('편집기 자산 실패 폴백', () => {
    // 이 spec 은 라이브 서버에서 자산 로드 실패를 실제로 재현하고, 한 케이스는 저장 →
    // 재진입 → 정리 삭제까지 밟는다. 기본 30초 예산으로는 담기지 않아 케이스마다 늘린다.
    test.beforeEach(({}, testInfo) => {
        testInfo.setTimeout(120_000);
    });

    test('자산 실패 시 안내와 평문 입력창이 나타난다', async ({ page, editorToken }) => {
        // @scenario asset_class=vendored, outcome=failed
        // @effects failed_asset_falls_back_to_plain_input, failed_asset_shows_retry_notice
        await page.route(EDITOR_BUNDLE, (route) => route.abort());
        await authenticatePage(page, editorToken);

        await page.goto('/admin/board/notice/create');
        await page.waitForLoadState('networkidle');

        // 안내 배너 — 호스트 컴포넌트 없이 코어가 직접 주입한다
        const notice = page.locator('#g7-asset-failure-notice');
        await expect(notice).toBeVisible({ timeout: 20_000 });
        await expect(notice).toHaveAttribute('role', 'alert');

        // 빈 div 가 아니라 실제 입력창이 서 있어야 한다
        await expect(page.locator('[data-ckeditor5-fallback]')).toBeVisible({ timeout: 20_000 });
    });

    test('폴백 입력창으로 작성한 내용이 평문으로 저장되고 새로고침 뒤에도 남는다', async ({ page, editorToken }) => {
        // @scenario asset_class=vendored, outcome=failed
        // @effects failed_asset_falls_back_to_plain_input
        //
        // 폼 상태만 보면 "폴백에 글자가 들어갔다" 까지만 증명된다. 제보의 요구는 그게 아니라
        // **글을 쓸 수 있어야 한다** 이므로 저장 → 재진입 → 본문 보존까지 실제로 밟는다.
        // 라이브 쓰기이므로 생성한 게시글은 이 테스트가 스스로 지운다.
        await page.route(EDITOR_BUNDLE, (route) => route.abort());
        await authenticatePage(page, editorToken);

        await page.goto('/admin/board/notice/create');
        await page.waitForLoadState('networkidle');

        const textarea = page.locator('[data-ckeditor5-fallback]');
        await expect(textarea).toBeVisible({ timeout: 20_000 });

        const stamp = Date.now();
        const title = `E2E 폴백 저장 ${stamp}`;
        const body = `폴백 저장 확인 ${stamp} — 평문으로 저장되어야 한다.`;

        await page.locator('input[name="title"]').fill(title);
        await textarea.fill(body);

        // 폴백은 평문이므로 서버가 HTML 로 신뢰하면 안 된다
        const mode = await page.evaluate(
            () => (window as any).G7Core?.state?.getLocal?.()?.form?.content_mode
        );
        expect(mode).toBe('text');

        let postId: number | null = null;
        try {
            await page.locator('#footer_save_button').click();

            // 저장 성공 시 상세로 이동한다 (`/admin/board/{slug}/post/{id}`)
            await page.waitForURL(/\/admin\/board\/notice\/post\/(\d+)/, { timeout: 30_000 });
            postId = Number(page.url().match(/\/post\/(\d+)/)![1]);

            // 새로고침(재진입) 후에도 남아야 한다 — 자산은 여전히 차단된 상태이므로
            // 수정 화면도 폴백으로 열리고, 거기에 저장된 본문이 실려 있어야 한다.
            await page.goto(`/admin/board/notice/${postId}/edit`);
            await page.waitForLoadState('networkidle');

            const reopened = page.locator('[data-ckeditor5-fallback]');
            await expect(reopened).toBeVisible({ timeout: 20_000 });
            await expect(reopened).toHaveValue(new RegExp(`폴백 저장 확인 ${stamp}`), { timeout: 20_000 });

            // 평문 계약이 서버까지 도달했는지 — 저장본에 태그가 섞이면 안 된다
            const stored = await reopened.inputValue();
            expect(stored).not.toMatch(/<\/?[a-z][\s\S]*>/i);
        } finally {
            if (postId !== null) {
                const del = await page.request.delete(
                    `/api/modules/sirsoft-board/admin/board/notice/posts/${postId}`,
                    { headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' } },
                );
                expect(del.ok(), `정리 삭제가 성공해야 한다 (status ${del.status()})`).toBeTruthy();
            }
        }
    });

    test('차단 해제 후 다시 시도하면 편집기가 서고 입력 내용이 승계된다', async ({ page, editorToken }) => {
        // @scenario asset_class=vendored, outcome=failed
        // @effects retry_recovers_editor
        let blocked = true;

        await page.route(EDITOR_BUNDLE, (route) => (blocked ? route.abort() : route.continue()));
        await authenticatePage(page, editorToken);

        await page.goto('/admin/board/notice/create');
        await page.waitForLoadState('networkidle');

        const textarea = page.locator('[data-ckeditor5-fallback]');
        await expect(textarea).toBeVisible({ timeout: 20_000 });

        const carried = `승계 확인 ${Date.now()}`;
        await textarea.fill(carried);

        // 차단을 풀고 다시 시도
        blocked = false;
        await page.locator('#g7-asset-failure-notice [data-action="retry"]').click();

        // 편집기가 서면 폴백은 사라진다
        await expect(page.locator('.ck-editor')).toBeVisible({ timeout: 30_000 });
        await expect(textarea).toHaveCount(0, { timeout: 20_000 });

        // 폴백에 써 둔 내용이 사라지면 [다시 시도] 는 누를 수 없는 버튼이 된다
        const restored = await page.evaluate(
            () => (window as any).G7Core?.state?.getLocal?.()?.form?.content ?? ''
        );
        expect(restored).toContain('승계 확인');
    });

    test('정상 경로에서는 안내가 뜨지 않고 편집기가 선다', async ({ page, editorToken }) => {
        // 폴백이 정상 경로까지 삼키면 편집기가 영영 안 뜬다 — 반대 방향도 잠근다.
        // @scenario asset_class=vendored, outcome=loaded
        // @effects runtime_asset_served_same_origin
        await authenticatePage(page, editorToken);

        await page.goto('/admin/board/notice/create');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('.ck-editor')).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-ckeditor5-fallback]')).toHaveCount(0, { timeout: 20_000 });
        await expect(page.locator('#g7-asset-failure-notice')).toHaveCount(0, { timeout: 20_000 });
    });

    test('폭을 바꾼 뒤 저장해도 폴백 입력창의 본문이 그대로 전송된다', async ({ page, editorToken }) => {
        // @scenario asset_class=vendored, outcome=failed
        // @effects failed_asset_falls_back_to_plain_input
        //
        // 공개 #130(engine-v1.63.3) 인접 축. 평문 폴백도 본문을 `setLocal({ render:false,
        // selfManaged:true })` 로 저장소 B 에만 쓰므로, 편집기 경로와 **같은 조건**이 성립한다.
        // 다만 폴백은 디바운스를 쓰지 않아 입력 즉시 B 에 실린다 — 그래서 편집기 spec 의
        // 결정화 4단계 중 ①(디바운스 발화 대기)이 필요 없고, ②폭 변경 → ③pending 클리어
        // 확정 → ④저장만 밟는다.
        //
        // 판정은 화면이 아니라 **요청 body** 로 한다. 이 결함은 작성 화면에서 422 로,
        // 수정 화면에서는 성공 토스트와 함께 조용한 손실로 나타나므로 화면 피드백은 근거가
        // 되지 못한다.
        await page.route(EDITOR_BUNDLE, (route) => route.abort());
        await page.setViewportSize({ width: 1440, height: 900 });
        await authenticatePage(page, editorToken);

        await page.goto('/admin/board/notice/create');
        await page.waitForLoadState('networkidle');

        const textarea = page.locator('[data-ckeditor5-fallback]');
        await expect(textarea).toBeVisible({ timeout: 20_000 });

        const stamp = Date.now();
        const marker = `FALLBACK-RESIZE-${stamp}`;
        await page.locator('input[name="title"]').fill(`E2E 폴백 폭변경 ${stamp}`);
        await textarea.fill(marker);

        // 본문이 저장소 B 에 실렸는지 먼저 확정한다 (폴백은 디바운스가 없어 즉시 실린다)
        await expect
            .poll(
                async () =>
                    await page.evaluate(
                        () => (window as any).G7Core?.state?.getLocal?.()?.form?.content ?? ''
                    ),
                { timeout: 20_000 }
            )
            .toContain(marker);

        // 브레이크포인트를 넘지 않는 19px 변경 — 이것만으로 pending 이 비워진다
        await page.setViewportSize({ width: 1421, height: 900 });
        await expect
            .poll(async () => await page.evaluate(() => (window as any).__g7PendingLocalState === null), {
                timeout: 20_000,
            })
            .toBe(true);

        let postId: number | null = null;
        try {
            const [request, response] = await Promise.all([
                page.waitForRequest((r) => r.url().includes('/posts') && r.method() === 'POST'),
                page.waitForResponse((r) => r.url().includes('/posts') && r.request().method() === 'POST'),
                page.locator('#footer_save_button').click(),
            ]);

            const body = request.postDataJSON();
            expect(String(body.content ?? ''), '폭 변경 뒤에도 폴백 본문이 전송돼야 한다').toContain(marker);
            expect(body.content_mode, '폴백의 평문 계약은 폭 변경과 무관하게 유지돼야 한다').toBe('text');
            expect(response.status(), '저장이 422 가 아니어야 한다').toBeLessThan(400);

            const json = await response.json();
            postId = json?.data?.id ?? null;
        } finally {
            if (postId !== null) {
                const del = await page.request.delete(
                    `/api/modules/sirsoft-board/admin/board/notice/posts/${postId}`,
                    { headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' } },
                );
                expect(del.ok(), `정리 삭제가 성공해야 한다 (status ${del.status()})`).toBeTruthy();
            }
        }
    });
});
