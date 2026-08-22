/**
 * 페이지 og:image 본문 첫 이미지 공급 — 봇 경로 실측 (공개 이슈 #22, 내부 #610).
 *
 * 페이지는 og:image 를 공급할 경로가 전무했다. 본문에 이미지를 넣어 저장하면
 * 봇 경로(`?_escaped_fragment_=`)의 서버 렌더 HTML 에 og:image 메타가 절대 URL 로
 * 실리는지, 이미지 없는 페이지는 og:image 가 출력되지 않는지(사이트 기본값 미설정 시)
 * 확인한다. og:description 死키 정렬(#11)도 함께 실측한다.
 *
 * 단위/통합: PageContentThumbnailTest(saving 추출), PageContentThumbnailResourceTest
 * (Resource 방출)가 담당하고, 이 spec 은 봇 경로 meta 렌더를 담당한다.
 *
 * 전용 페이지(slug: e2e-og-image-*)를 spec 안에서 생성/삭제하므로 시드 의존이 없다.
 *
 * @scenario page-og-image
 * @axes image_source=content_internal_only image_source=none
 * @effects bot_page_renders_og_image_meta,
 *          content_image_fills_page_og
 */
import { test as base, expect } from '@playwright/test';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';

const API = '/api/modules/sirsoft-page';

type PageAuthFixtures = { pageManageToken: string };

const test = base.extend<PageAuthFixtures>({
    pageManageToken: async ({}, use) => {
        await use(issueToken(
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.update',
            'sirsoft-page.pages.delete',
        ));
    },
});

/** API 컨텍스트로 호출한다 (spec 전용 — 페이지 방문 불필요한 셋업/정리용). */
async function api(
    request: import('@playwright/test').APIRequestContext,
    bearer: string,
    method: 'get' | 'post' | 'delete',
    path: string,
    data?: unknown,
): Promise<{ status: number; body: any }> {
    const response = await request[method](path, {
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
        data: data as any,
    });
    let body: any = null;
    try {
        body = await response.json();
    } catch {
        /* 비 JSON 응답 허용 */
    }
    return { status: response.status(), body };
}

test.describe('페이지 og:image 본문 이미지 공급 (공개 #22)', () => {
    test('본문 이미지 페이지의 봇 경로 HTML 에 og:image 절대 URL 이 실린다', async ({ request, pageManageToken }) => {
        const slug = 'e2e-og-image-filled';
        await api(request, pageManageToken, 'delete', `${API}/admin/pages/by-slug/${slug}`).catch(() => null);

        const created = await api(request, pageManageToken, 'post', `${API}/admin/pages`, {
            slug,
            title: { ko: '[검수] og 이미지 페이지' },
            content: { ko: '<p>본문</p><img src="/favicon.ico">' },
            content_mode: 'html',
            published: true,
            seo_meta: { description: 'og 설명 검증용' },
        });
        expect([200, 201], `page create failed: ${JSON.stringify(created.body).slice(0, 300)}`).toContain(created.status);
        const pageId = created.body?.data?.id;

        // 공개 API 가 캐시를 방출하는지 (레이아웃 og.image 의 데이터 근원)
        const pub = await api(request, pageManageToken, 'get', `${API}/pages/${slug}`);
        expect(pub.body?.data?.content_thumbnail_url).toBe('/favicon.ico');

        // 봇 경로 서버 렌더 — og:image 절대 URL + og:description 정렬(#11)
        const bot = await request.get(`/page/${slug}?_escaped_fragment_=`);
        const html = await bot.text();
        expect(html).toMatch(/<meta property="og:image" content="https?:\/\/[^"]+\/favicon\.ico">/);
        expect(html).toContain('<meta property="og:description" content="og 설명 검증용">');

        // 정리
        const del = await api(request, pageManageToken, 'delete', `${API}/admin/pages/${pageId}`);
        expect([200, 204]).toContain(del.status);
    });

    test('이미지 없는 페이지는 og:image 가 본문 폴백으로 채워지지 않는다', async ({ request, pageManageToken }) => {
        const slug = 'e2e-og-image-empty';

        const created = await api(request, pageManageToken, 'post', `${API}/admin/pages`, {
            slug,
            title: { ko: '[검수] og 이미지 없는 페이지' },
            content: { ko: '<p>이미지가 없는 본문입니다.</p>' },
            content_mode: 'html',
            published: true,
        });
        expect([200, 201]).toContain(created.status);
        const pageId = created.body?.data?.id;

        const pub = await api(request, pageManageToken, 'get', `${API}/pages/${slug}`);
        expect(pub.body?.data?.content_thumbnail_url).toBeNull();

        // 정리
        const del = await api(request, pageManageToken, 'delete', `${API}/admin/pages/${pageId}`);
        expect([200, 204]).toContain(del.status);
    });
});
