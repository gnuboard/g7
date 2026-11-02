/**
 * 히어로섹션 플러그인 E2E 공용 fixture·도우미.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 토큰 발급을 재사용한다. 배너는 사양이 만들고 사양이 지운다 —
 * 사양마다 만든 배너 id 를 모아 두었다가 끝날 때 관리자 API 로 일괄 삭제한다(이미지 파일까지 지워진다).
 */
import { test as base, expect, type APIRequestContext, type Page } from '@playwright/test';
import { issueToken, issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

export const API = '/api/plugins/sirsoft-hero_section';
export const ADMIN_LIST = '/admin/plugins/sirsoft-hero_section/banners';

/** 1x1 PNG — 업로드 검증(JPG·PNG·WEBP)을 통과하는 최소 이미지 */
export const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

type HeroFixtures = {
  /** 조회·등록/수정·삭제 권한을 모두 가진 관리자 토큰 */
  managerToken: string;
  /** 조회 권한만 가진 관리자 토큰(admin 역할 없음 — 권한 경계 검증용) */
  readerToken: string;
  /** 이 사양이 만든 배너 id — 끝나면 지운다 */
  created: number[];
};

export const test = base.extend<HeroFixtures>({
  managerToken: async ({}, use) => {
    await use(issueToken('sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.update', 'sirsoft-hero_section.banners.delete'));
  },
  readerToken: async ({}, use) => {
    await use(issueScopedToken('sirsoft-hero_section.banners.read'));
  },
  created: async ({ request, managerToken }, use) => {
    const ids: number[] = [];
    await use(ids);
    await deleteBanners(request, managerToken, ids);
  },
});

/** 인증 헤더 */
export const auth = (token: string) => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });

/**
 * 고유한 테스트 제목을 만든다 — 운영자가 바꾸는 문구가 아니라 사양이 만든 값으로 단언하기 위해.
 *
 * @param label 구분 이름
 * @returns 제목
 */
export const uniqueTitle = (label: string): string => `E2E ${label} ${Date.now()}-${Math.floor(Math.random() * 10000)}`;

/**
 * 관리자 API 로 배너를 하나 만든다(PC 이미지 업로드 → 등록).
 *
 * @param request API 요청 컨텍스트
 * @param token 관리자 토큰
 * @param overrides 바꿀 배너 값
 * @returns 만든 배너 id
 */
export async function createBanner(request: APIRequestContext, token: string, overrides: Record<string, unknown> = {}): Promise<number> {
  const tempKey = `e2e-${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const upload = await request.post(`${API}/admin/banner-images`, {
    headers: auth(token),
    multipart: { file: { name: 'e2e-pc.png', mimeType: 'image/png', buffer: PNG_1X1 }, device: 'pc', temp_key: tempKey },
  });
  expect(upload.status(), await upload.text()).toBe(201);

  const response = await request.post(`${API}/admin/banners`, {
    headers: auth(token),
    data: {
      position: 'home_hero',
      is_active: true,
      display_period: 'always',
      title: { ko: uniqueTitle('배너') },
      title_visible: true,
      title_color: '#FFFFFF',
      title_align: 'left',
      description_visible: true,
      description_color: '#E5E7EB',
      description_align: 'left',
      link_url: '/',
      link_new_window: false,
      temp_key: tempKey,
      ...overrides,
    },
  });
  expect(response.status(), await response.text()).toBe(201);

  return (await response.json()).data.id as number;
}

/**
 * 배너를 일괄 삭제한다(없는 id 는 건너뛴다).
 *
 * @param request API 요청 컨텍스트
 * @param token 관리자 토큰
 * @param ids 지울 배너 id
 */
export async function deleteBanners(request: APIRequestContext, token: string, ids: number[]): Promise<void> {
  for (const id of ids) {
    await request.delete(`${API}/admin/banners/${id}`, { headers: auth(token) });
  }
}

/**
 * 사이트 홈(또는 쇼핑 메인)을 열고 화면이 붙을 때까지 기다린다.
 *
 * @param page 페이지
 * @param path 경로
 */
export async function gotoUser(page: Page, path = '/'): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(() => !!(window as any).G7Core, undefined, { timeout: 30_000 });
}

/** 사용자 화면 슬라이더 영역 */
export const slider = (page: Page) => page.locator('section.hs-slider[role="region"]');

/**
 * 슬라이더 안 슬라이드 제목들(배너 순서대로) — Swiper 무한 반복은 슬라이드 DOM 순서를 바꾸므로 논리 순서(`data-index`)로 정렬한다.
 *
 * @param page 페이지
 * @returns 슬라이드 제목(제목을 숨긴 슬라이드는 빠진다)
 */
export async function slideTitles(page: Page): Promise<string[]> {
  const rows = await page.locator('section.hs-slider [data-index]').evaluateAll((els) => els.map((el) => [
    Number(el.getAttribute('data-index')),
    el.querySelector('.hs-slider__title')?.textContent ?? null,
  ] as [number, string | null]));

  return rows.sort((a, b) => a[0] - b[0]).map(([, title]) => title).filter((title): title is string => title !== null);
}

/**
 * 지금 보이는 슬라이드의 제목을 돌려준다.
 *
 * @param page 페이지
 * @returns 활성 슬라이드 제목
 */
export async function activeTitle(page: Page): Promise<string> {
  return (await page.locator('section.hs-slider .hs-slider__slide--active .hs-slider__title').first().textContent()) ?? '';
}

/** 데스크톱·휴대폰 폭 */
export const VIEWPORTS = {
  desktop: { width: 1366, height: 900 },
  tablet: { width: 768, height: 1024 },
  mobile: { width: 390, height: 844 },
} as const;

export { authenticatePage, expect };
