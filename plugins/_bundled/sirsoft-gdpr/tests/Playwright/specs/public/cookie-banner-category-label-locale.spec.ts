/**
 * E2E: 쿠키 배너의 카테고리 라벨·설명이 접속 언어를 따른다
 *
 * 시나리오 매니페스트: `tests/scenarios/cookie-banner-category-locale.yaml`
 * (조합 마킹은 파서가 조합을 여러 개 읽을 수 있는 라인 주석으로 테스트 바로 앞에 둔다)
 *
 * 배경: 배너의 카테고리 라벨/설명 표현식이 `category?.label?.ko ?? category?.label?.en` 이라
 * **접속 언어와 무관하게 항상 한국어**를 집었다. 영어로 보는 방문자에게도 한국어 라벨이
 * 그대로 나갔고, 일본어 로케일에서는 한국어와 영어가 한 화면에 섞였다.
 * 현재는 `category?.label?.[$locale]` 를 먼저 보고, 그 언어의 문구가 없을 때만
 * ko → en → key 순으로 내려간다.
 *
 * `$locale` 인 이유: 엔진은 현재 로케일을 `createGlobalVariables()` 의 전역 변수로만 노출한다.
 * `_global.locale` 은 어디서도 채워지지 않는 경로라 항상 `undefined` 이고, 그래서 뒤의 `?? ko`
 * 폴백이 늘 이겨 결함이 화면상 "정상"으로 보였다.
 *
 * 기대 문구를 상수로 박지 않는 이유: 카테고리 라벨은 **운영자가 편집하는 자유 문구**다.
 * 시드 기본값을 상수로 박으면 라벨을 한 번만 바꿔도 이 spec 이 깨지고, 깨진 이유가
 * "기능이 회귀했다" 로 오독된다. 그래서 사이트의 공개 설정 API 에서 그 사이트의 실제
 * 라벨을 읽어 와 **"렌더된 문구 == label[현재 로케일]"** 이라는 계약 자체를 본다.
 *
 * 검증:
 *  1. 기본(ko) 진입 시 배너에 그 사이트의 ko 라벨이 보이고 en 라벨은 보이지 않는다
 *  2. English 로 바꾸면 같은 자리에 en 라벨이 보이고 ko 라벨은 사라진다
 */
import { test, expect } from '@playwright/test';

const BANNER = '#gdpr_cookie_banner';
const PREFERENCES_TOGGLE = '#gdpr_preferences_toggle_button';
const SETTINGS_ENDPOINT = '/api/plugins/sirsoft-gdpr/settings';

interface CategoryLabels {
    key: string;
    ko: string;
    en: string;
}

/**
 * 이 사이트가 실제로 저장하고 있는 카테고리 라벨을 가져온다.
 *
 * ko 와 en 이 **서로 다른** 카테고리만 고른다 — 같으면 로케일이 반영됐는지 판별할 수 없어
 * 그 카테고리로는 어떤 단언도 의미가 없다.
 */
async function resolveDistinguishableCategory(
    request: import('@playwright/test').APIRequestContext,
): Promise<CategoryLabels> {
    const response = await request.get(SETTINGS_ENDPOINT);
    expect(response.ok(), `공개 설정 API 응답 실패 (${response.status()})`).toBeTruthy();

    const body = await response.json();
    const categories = body?.data?.cookie_categories ?? [];

    for (const category of categories) {
        const ko = typeof category?.label?.ko === 'string' ? category.label.ko.trim() : '';
        const en = typeof category?.label?.en === 'string' ? category.label.en.trim() : '';
        if (ko && en && ko !== en) {
            return { key: String(category.key ?? ''), ko, en };
        }
    }

    throw new Error(
        'ko/en 라벨이 서로 다른 쿠키 카테고리가 없다 — 이 사이트 데이터로는 로케일 반영 여부를 판별할 수 없다.',
    );
}

/** 배너를 띄운 상태로 카테고리 목록을 펼친다. */
async function openCategoryList(page: import('@playwright/test').Page): Promise<void> {
    await expect(page.locator(BANNER)).toBeVisible({ timeout: 20_000 });
    await page.locator(PREFERENCES_TOGGLE).click();
    // 펼침 영역이 그려질 때까지 — 필수 카테고리는 항상 하나 이상 존재한다.
    await expect(page.locator(`${BANNER} input[type="checkbox"]`).first()).toBeVisible({ timeout: 15_000 });
}

// @scenario visitor_locale=ko, category_label=present_for_locale
// @effects banner_category_label_uses_current_locale
// @scenario visitor_locale=en, category_label=present_for_locale
// @effects banner_category_label_uses_current_locale
test('쿠키 배너 카테고리 라벨이 접속 언어를 따른다', async ({ page, request }) => {
    const category = await resolveDistinguishableCategory(request);

    // 동의 이력이 없는 새 컨텍스트로 진입해야 배너가 뜬다.
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await openCategoryList(page);

    const banner = page.locator(BANNER);

    // 1. 기본 로케일(ko)
    await expect(banner).toContainText(category.ko, { timeout: 15_000 });
    await expect(banner).not.toContainText(category.en);

    // 2. English 로 전환
    await page.evaluate(() => {
        localStorage.setItem('g7_locale', 'en');
    });
    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await openCategoryList(page);

    // 수정 전에는 여기서도 ko 라벨이 나왔다 — 그것이 이 spec 이 잠그는 회귀다.
    await expect(banner).toContainText(category.en, { timeout: 15_000 });
    await expect(banner).not.toContainText(category.ko);
});
