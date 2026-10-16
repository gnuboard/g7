/**
 * 목록 컨텍스트 왕복 — 이전글/다음글 leg 의 URL 목록 상태 보존 (공개 이슈 #75).
 *
 * 결함: 목록에서 상세로 들어갈 때는 `mergeQuery: true` 로 page/search/category 가 상세 URL 에
 *   실렸지만, 상세의 이전글/다음글 버튼은 규약에서 누락돼 `del` 하나만 남기고 나머지를 전부
 *   떨궜다. 그 뒤 '목록' 버튼은 복원할 상태가 없어 1페이지로 되돌아갔다.
 *
 * 이 spec 은 시드 의존을 최소화한다 — 자유게시판(`free`)에 글이 3개 이상이면 이전글·다음글·목록
 * leg 가 돈다(2페이지가 비면 1페이지 가운데 글에 `page=2` 를 실어 들어간다). 목록 2페이지 행을 눌러
 * 들어가는 leg 만 2페이지에 글이 있어야 한다(샘플 시드 28건/3페이지) — 없으면 사유를 남기고 건너뛴다.
 * 별도 전용 게시판(navtest) 시드를 요구하는 board-post-navigation.spec.ts 와 달리 describe 는 늘 실행된다.
 *
 * 단위/렌더링:
 *   - templates/_bundled/sirsoft-basic/src/__tests__/layouts/board-post-navigation-list-return.test.tsx
 *     가 실 `_navigation.json` 의 액션 params 로 mergeQuery 왕복을 고정
 *   - 저장소 전역 정적 검사가 목록 클러스터 내 navigate 의 mergeQuery 누락을 재발 차단
 *   이 spec 은 브라우저 수준(실제 URL + 목록 API 요청 파라미터)을 담당한다.
 *
 * @scenario board-list-context-round-trip
 * @axes leg=enter leg=back leg=prev leg=next
 * @effects navigation_preserves_list_query,
 *          list_return_preserves_query
 */
import { test, expect } from '@playwright/test';

const BOARD_SLUG = 'free';
const LIST_URL = `/board/${BOARD_SLUG}`;

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
    return new URL(url).searchParams;
}

/**
 * 목록의 게시글 행 로케이터.
 *
 * basic 형 목록의 행은 상세 주소를 가진 링크(`<a href>` + 경로 없는 navigate, #757)이며, 접근성 이름이
 * "번호 제목 … 작성일 조회수" 형태다. 페이지 번호 링크("1")와 구분하기 위해
 * 번호로 시작하는 이름만 고른다.
 *
 * @param page Playwright 페이지
 * @returns 행 로케이터
 */
function postRows(page: import('@playwright/test').Page) {
    return page.getByRole('link', { name: /^\d+\s+\S/ });
}

/**
 * 목록 2페이지 상태(`page=2`)를 실은 채 글 상세에 들어갑니다.
 *
 * 2페이지에 글이 있으면 그 행을 눌러 들어간다(목록 → 상세 leg 까지 실측). 글 수가 쪽당 개수보다
 * 적은 사이트(2페이지가 비는 곳)에서는 1페이지 가운데 글의 주소에 `page=2` 를 붙여 바로 들어간다 —
 * 이전글·다음글·목록 leg 의 상태 승계는 그대로 잴 수 있다.
 *
 * @param page Playwright 페이지
 * @returns 진입한 상세 URL 과 목록 행을 눌러 들어갔는지
 */
async function enterDetailFromPage2(
    page: import('@playwright/test').Page,
): Promise<{ url: string; viaList: boolean }> {
    await page.goto(`${LIST_URL}?page=2`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    let viaList = true;
    if ((await postRows(page).count()) > 0) {
        await postRows(page).first().click();
    } else {
        viaList = false;
        await page.goto(`${LIST_URL}?page=1`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        const rows = postRows(page);
        const total = await rows.count();
        expect(total, '자유게시판에 글이 없어 상세 leg 를 잴 수 없다').toBeGreaterThan(0);
        // 가운데 글을 골라 이전글·다음글이 둘 다 있게 한다
        const href = await rows.nth(Math.floor(total / 2)).getAttribute('href');
        const target = new URL(href ?? '', page.url());
        target.searchParams.set('page', '2');
        await page.goto(target.toString());
    }
    await page.waitForURL(new RegExp(`/board/${BOARD_SLUG}/\\d+`), { timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    return { url: page.url(), viaList };
}

test.describe('목록 컨텍스트 왕복 — 이전글/다음글 (#75)', () => {
    // @scenario leg=enter
    // @effects list_return_preserves_query
    test('목록 2페이지에서 상세로 들어가면 page 가 상세 URL 에 실린다', async ({ page }) => {
        const { url: detailUrl, viaList } = await enterDetailFromPage2(page);
        test.skip(
            !viaList,
            '자유게시판 글이 쪽당 개수보다 적어 2페이지가 비었다 — 목록 2페이지 행을 눌러 들어가는 leg 는 샘플 시드(free 28건)에서 잰다',
        );

        expect(detailUrl).toContain(`/board/${BOARD_SLUG}/`);
        expect(queryOf(detailUrl).get('page')).toBe('2');
    });

    // @scenario leg=prev
    // @effects navigation_preserves_list_query
    test('이전글로 이동해도 page 가 유지된다', async ({ page }) => {
        await enterDetailFromPage2(page);

        const prev = page.getByRole('link', { name: /(이전글|Previous)(?!\s*page)/i }).first();
        await prev.click();
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        expect(queryOf(page.url()).get('page')).toBe('2');
    });

    // @scenario leg=next
    // @effects navigation_preserves_list_query
    test('다음글로 이동해도 page 가 유지된다', async ({ page }) => {
        await enterDetailFromPage2(page);

        const next = page.getByRole('link', { name: /(다음글|Next)(?!\s*page)/i }).first();
        await next.click();
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        expect(queryOf(page.url()).get('page')).toBe('2');
    });

    // @scenario leg=prev, leg=back
    // @effects navigation_preserves_list_query, list_return_preserves_query
    test('이전글 3회 연속 후 목록으로 돌아가도 2페이지가 복원된다', async ({ page }) => {
        await enterDetailFromPage2(page);

        for (let i = 0; i < 3; i++) {
            const prev = page.getByRole('link', { name: /(이전글|Previous)(?!\s*page)/i }).first();
            if (!(await prev.isVisible().catch(() => false))) break;
            const before = page.url();
            const prevHref = await prev.getAttribute('href');
            if (prevHref && new URL(prevHref, before).pathname === new URL(before).pathname) break;
            await prev.click();
            // 이동 직후 잠깐은 옛 글의 이전·다음 링크가 남아 있다(새 글의 navigation 응답 전) — 주소가 바뀌고
            // 전환 덮개가 걷힌 뒤에 다음 회차의 이전글을 본다. 이전글이 없는 글에 닿으면 거기서 멈춘다.
            await page.waitForURL((url) => url.toString() !== before, { timeout: 30_000 });
            await expect(page.locator('#g7-skeleton-overlay')).toHaveCount(0, { timeout: 30_000 });
            await page.waitForLoadState('networkidle', { timeout: 30_000 });
            expect(queryOf(page.url()).get('page'), `${i + 1}회차 이전글 이동에서 page 소실`).toBe('2');
        }

        // 목록 복귀 — 목록 API 가 실제로 2페이지를 요청해야 한다.
        // 직전 이동 직후에는 상세가 다시 렌더되며 링크가 교체되므로, 링크가 안정된 뒤 클릭한다.
        const listLink = page.getByRole('link', { name: /(목록|Back to List)$/i }).first();
        await expect(listLink).toBeVisible({ timeout: 30_000 });

        const [listRequest] = await Promise.all([
            page.waitForRequest(
                (req) => req.url().includes('/posts') && req.url().includes('page=2'),
                { timeout: 30_000 }
            ),
            listLink.click(),
        ]);

        expect(listRequest.url()).toContain('page=2');
        await page.waitForURL(new RegExp(`/board/${BOARD_SLUG}\\?`), { timeout: 30_000 });
        expect(queryOf(page.url()).get('page')).toBe('2');
    });

    // @scenario leg=prev
    // @effects navigation_preserves_list_query
    test('검색어가 걸린 상태에서도 이전글 이동이 검색어를 유지한다', async ({ page }) => {
        await page.goto(`${LIST_URL}?page=1&search=맛집`);
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        const row = postRows(page).first();
        if (!(await row.isVisible().catch(() => false))) {
            test.skip(true, '검색 결과가 없어 이 축을 실행할 수 없습니다.');
            return;
        }
        await row.click();
        await page.waitForURL(new RegExp(`/board/${BOARD_SLUG}/\\d+`), { timeout: 30_000 });
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        expect(queryOf(page.url()).get('search')).toBe('맛집');

        const prev = page.getByRole('link', { name: /(이전글|Previous)(?!\s*page)/i }).first();
        if (await prev.isVisible().catch(() => false)) {
            await prev.click();
            await page.waitForLoadState('networkidle', { timeout: 30_000 });
            expect(queryOf(page.url()).get('search')).toBe('맛집');
        }
    });
});
