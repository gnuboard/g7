/**
 * 팝업 플러그인 E2E 공통 — 토큰 발급(코어 fixture), 사양이 만든 팝업만 지우는 정리, 사용자 화면 이동·레이어 찾기.
 *
 * 사양은 자기가 만든 팝업만 바꾸고 끝에서 지운다(운영 팝업을 다시 저장하지 않는다). 공개 API 응답이 아니라 그 응답이 그린
 * 레이어 요소를 기다린다(데이터 응답 뒤 관찰 — #810 교훈).
 */
import { test as base, expect, type APIRequestContext, type Page } from '@playwright/test';
import { issueToken, issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/** 플러그인 API 접두 */
export const API = '/api/plugins/sirsoft-popup';

/** 관리자 목록 화면 */
export const ADMIN_LIST = '/admin/plugins/sirsoft-popup/popups';

/** 1×1 PNG — 업로드 표본 */
export const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

type PopupFixtures = {
  managerToken: string;
  readerToken: string;
  memberToken: string;
  created: number[];
};

export const test = base.extend<PopupFixtures>({
  managerToken: async ({}, use) => {
    await use(issueToken('sirsoft-popup.popups.read', 'sirsoft-popup.popups.update', 'sirsoft-popup.popups.delete'));
  },
  readerToken: async ({}, use) => {
    // 조회 권한만 — 관리자 역할(모든 권한)을 주지 않는다
    await use(issueScopedToken('sirsoft-popup.popups.read'));
  },
  memberToken: async ({}, use) => {
    // 관리자 역할 없는 일반 회원
    await use(issueScopedToken());
  },
  created: async ({ request, managerToken }, use) => {
    const ids: number[] = [];
    await use(ids);
    for (const id of ids) {
      await request.delete(`${API}/admin/popups/${id}`, { headers: auth(managerToken) });
    }
  },
});

/** 인증 헤더 */
export const auth = (token: string) => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });

/** 겹치지 않는 관리용 제목 */
export const uniqueTitle = (label: string): string => `E2E ${label} ${Date.now()}-${Math.floor(Math.random() * 10000)}`;

/**
 * 직접 작성형 팝업을 만든다(가장 가벼운 표본).
 *
 * @returns 팝업 ID
 */
export async function createHtmlPopup(request: APIRequestContext, token: string, overrides: Record<string, unknown> = {}): Promise<number> {
  const response = await request.post(`${API}/admin/popups`, {
    headers: auth(token),
    data: {
      title: uniqueTitle('팝업'),
      is_active: true,
      content_type: 'html',
      display_period: 'always',
      page_scope: 'all',
      audience: 'all',
      device: 'all',
      body_html: { ko: '<p>E2E 본문</p>' },
      ...overrides,
    },
  });
  expect(response.status(), await response.text()).toBe(201);

  return (await response.json()).data.id as number;
}

/**
 * 이미지형 팝업을 만든다(PC 이미지 업로드 → 저장).
 *
 * @returns 팝업 ID
 */
export async function createImagePopup(request: APIRequestContext, token: string, overrides: Record<string, unknown> = {}): Promise<number> {
  const tempKey = `e2e-${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const upload = await request.post(`${API}/admin/popup-images`, {
    headers: auth(token),
    multipart: { file: { name: 'e2e-pc.png', mimeType: 'image/png', buffer: PNG_1X1 }, device: 'pc', temp_key: tempKey },
  });
  expect(upload.status(), await upload.text()).toBe(201);

  return createHtmlPopup(request, token, { content_type: 'image', image_alt: { ko: '이미지 대체 문구' }, body_html: undefined, temp_key: tempKey, ...overrides });
}

/** 사용자 화면으로 가서 엔진이 뜰 때까지 기다린다 */
export async function gotoUser(page: Page, path = '/'): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(() => !!(window as any).G7Core, undefined, { timeout: 30_000 });
  // 공개 API 응답이 아니라 그 응답이 그린 결과를 기다린다 — 레이어가 그려졌거나(팝업 있음) 확장 데이터 받기가 끝날 때까지
  await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
}

/** 팝업 레이어(대화상자) */
export const layer = (page: Page) => page.locator('[data-popup-layer=""] [role="dialog"]');

/** 레이어에 보이는 팝업 id(순서대로) */
export async function layerIds(page: Page): Promise<number[]> {
  return page.locator('[data-popup-layer=""] .g7pp-slide').evaluateAll((els) => els.map((el) => Number(el.getAttribute('data-popup-id'))));
}

/** SPA 안에서 다른 경로로 간다(전체 새로고침 없이) */
export async function navigateSpa(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } }), path);
  await page.waitForURL((url) => url.pathname === path, { timeout: 15_000 });
}

/** 화면 크기 */
export const VIEWPORTS = {
  desktop: { width: 1366, height: 900 },
  mobile: { width: 390, height: 844 },
} as const;

export { authenticatePage, expect };
