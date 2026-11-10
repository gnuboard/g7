/**
 * 환경설정 「본문 콘텐츠」를 저장한 뒤 새로고침 없이 같은 탭에서 글 상세로 옮겨도 새 표시 규칙이 바로 적용된다 (#887).
 *
 * 종전에는 관리자 설정 응답에 화면용 계산값(`render_policy`)이 없어 같은 탭에서는 로드 때의 옛 규칙이 남았고(동영상이 안 보임),
 * 「그림 캡션」을 꺼도 캡션이 보이고, 「외부 영상 삽입」을 끄면 영상 주소가 사라진 빈 상자만 남았다.
 * 이 spec 은 `ContentPolicyRuntime` 이 같은 탭에서 새 규칙으로 캡션을 지우고 외부 영상을 링크로 바꾸는지, 새로고침 뒤에도 같은지 잰다.
 * 사이트의 본문 콘텐츠 설정을 바꾸므로 실행 전 값을 떠 두고 afterAll 에서 되돌린다(serial). 만든 글은 영구 삭제한다.
 *
 * // @scenario case=runtime_same_tab_e2e
 * // @effects same_tab_policy_applied_without_reload
 */
import { execSync } from 'node:child_process';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe.configure({ mode: 'serial' });

type ContentSettings = {
  allow_figure_caption: boolean;
  allow_uploaded_video: boolean;
  allow_uploaded_audio: boolean;
  allow_external_embed: boolean;
};

let token: string;
let snapshot: ContentSettings;
let boardSlug: string;
let postId: number | null = null;

/** 관리자 API 요청 머리 */
function headers(): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
}

/** 1×1 PNG(data URI) — 외부 자원 없이 그림을 그린다 */
const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

test.beforeAll(async ({ playwright, baseURL }) => {
  token = issueToken();
  const api = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });

  const settings = await (await api.get('/api/admin/settings', { headers: headers() })).json();
  const content = settings?.data?.content ?? {};
  snapshot = {
    allow_figure_caption: Boolean(content.allow_figure_caption),
    allow_uploaded_video: Boolean(content.allow_uploaded_video),
    allow_uploaded_audio: Boolean(content.allow_uploaded_audio),
    allow_external_embed: Boolean(content.allow_external_embed),
  };

  // 시작 상태: 캡션·외부 영상 켬
  const on = await api.post('/api/admin/settings', {
    headers: headers(),
    data: { _tab: 'content', content: { ...snapshot, allow_figure_caption: true, allow_external_embed: true } },
  });
  expect(on.status()).toBe(200);

  const boards = await (await api.get('/api/modules/sirsoft-board/admin/boards?per_page=1', { headers: headers() })).json();
  boardSlug = boards?.data?.data?.[0]?.slug ?? boards?.data?.[0]?.slug;
  expect(boardSlug, '게시판이 하나 이상 있어야 한다').toBeTruthy();

  const created = await api.post(`/api/modules/sirsoft-board/admin/board/${boardSlug}/posts`, {
    headers: headers(),
    data: {
      title: 'E2E 본문 규칙 같은 탭',
      content_mode: 'html',
      content:
        `<figure class="image"><img src="${PIXEL}" alt="점"><figcaption>캡션E2E</figcaption></figure>`
        + '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
        + '<p>본문E2E</p>',
    },
  });
  expect(created.status()).toBe(201);
  postId = (await created.json())?.data?.id ?? null;
  await api.dispose();
});

test.afterAll(async ({ playwright, baseURL }) => {
  const api = await playwright.request.newContext({ baseURL, ignoreHTTPSErrors: true });
  await api.post('/api/admin/settings', { headers: headers(), data: { _tab: 'content', content: snapshot } });
  await api.dispose();
  if (postId) {
    execSync(
      `php artisan tinker --execute="Modules\\Sirsoft\\Board\\Models\\Post::withTrashed()->find(${postId})?->forceDelete();"`,
      { stdio: 'ignore', input: '' },
    );
  }
});

/** 상세 본문에서 캡션·외부 영상 표시 상태를 잰다 */
async function measure(page: import('@playwright/test').Page) {
  await expect(page.getByText('본문E2E')).toBeVisible({ timeout: 20_000 });
  return page.evaluate(() => ({
    figcaption: document.querySelectorAll('figcaption').length,
    captionText: document.body.innerText.includes('캡션E2E'),
    images: [...document.querySelectorAll('figure.image img')].length,
    iframes: document.querySelectorAll('iframe').length,
    links: [...document.querySelectorAll('figure.media a')].map((a) => ({ host: new URL((a as HTMLAnchorElement).href).host, rel: a.getAttribute('rel') })),
    emptyMedia: [...document.querySelectorAll('figure.media')].filter((f) => f.innerHTML.trim() === '').length,
  }));
}

/**
 * @scenario case=runtime_same_tab_e2e
 * @effects same_tab_policy_applied_without_reload
 */
test('캡션·외부 영상을 끄고 저장한 뒤 같은 탭에서 글 상세로 옮기면 캡션은 사라지고 영상은 링크가 된다', async ({ page }) => {
  await authenticatePage(page, token);

  // 켬 상태 확인
  await page.goto(`/admin/board/${boardSlug}/post/${postId}`);
  const before = await measure(page);
  expect(before.figcaption).toBe(1);
  expect(before.iframes).toBe(1);

  // 설정 화면에서 두 스위치를 끄고 저장
  await page.goto('/admin/settings?tab=content');
  const caption = page.getByRole('switch', { name: '그림 캡션' });
  const embed = page.getByRole('switch', { name: '외부 영상 삽입' });
  await expect(caption).toHaveAttribute('aria-checked', 'true', { timeout: 20_000 });
  await caption.click();
  await embed.click();
  const [saved] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/admin/settings') && r.request().method() === 'POST'),
    page.getByRole('button', { name: '저장' }).last().click(),
  ]);
  expect(saved.status()).toBe(200);

  // 새로고침 없이 같은 문서에서 라우터로 이동
  await page.evaluate(() => {
    (window as any).__sameDocE2E = true;
  });
  await page.evaluate((url) => {
    history.pushState({}, '', url);
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
  }, `/admin/board/${boardSlug}/post/${postId}`);

  const after = await measure(page);
  expect(await page.evaluate(() => (window as any).__sameDocE2E === true), '같은 문서(새로고침 없음)').toBe(true);
  expect(after.figcaption).toBe(0);
  expect(after.captionText).toBe(false);
  expect(after.images).toBe(1);
  expect(after.iframes).toBe(0);
  expect(after.emptyMedia).toBe(0);
  expect(after.links).toEqual([{ host: 'www.youtube.com', rel: 'noopener noreferrer nofollow' }]);

  // 새로고침해도 같다
  await page.reload();
  const reloaded = await measure(page);
  expect(reloaded.figcaption).toBe(0);
  expect(reloaded.iframes).toBe(0);
  expect(reloaded.links.length).toBe(1);
});
