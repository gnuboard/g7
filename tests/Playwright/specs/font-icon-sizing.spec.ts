/**
 * E2E: 글꼴 아이콘 크기 — 관리자 빈 화면 아이콘 (이슈 #682 S4 라운드 8)
 *
 * `Icon` 은 Font Awesome 글꼴 아이콘이라 글리프 크기가 글자 크기를 따른다. 너비·높이만 준 칸은
 * 커져도 글리프는 기본 글자 크기(16px)로 칸 위쪽에 작게 놓인다 — 소스 가드(`FontIconSizingTest`)는
 * 클래스 조합만 보므로, 실제 브라우저에서 글리프가 얼마나 크고 원 가운데에 있는지는 여기서 잰다.
 *
 * 사용자 템플릿 `Header`·`MobileNav` 컴포넌트의 모바일 단추(같은 원인으로 함께 고침)는 번들 레이아웃이
 * 그리지 않는다 — 모바일 머리글은 `_user_base` 레이아웃이 직접 그리고(`<icon size="md">`, 칸 없는 16px),
 * `Header` 는 데스크톱 머리글로만 쓰인다. 그 두 자리는 소스 가드(`FontIconSizingTest`)가 잰다.
 *
 * 축 요약(마커 아님 — 평문): 관리자 빈 화면(원 안 아이콘).
 */
import type { Locator } from '@playwright/test';
import { authenticatePage, expect, issueToken, test } from '../fixtures/auth';

/** 요소의 글자 크기(px) */
async function fontSize(locator: Locator): Promise<number> {
  return locator.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
}

/** 두 상자의 가운데 사이 거리(px) — [가로, 세로] */
async function centerOffset(inner: Locator, outer: Locator): Promise<[number, number]> {
  const a = await inner.boundingBox();
  const b = await outer.boundingBox();
  expect(a, '안쪽 요소가 그려지지 않았다').not.toBeNull();
  expect(b, '바깥 요소가 그려지지 않았다').not.toBeNull();

  return [
    Math.abs(a!.x + a!.width / 2 - (b!.x + b!.width / 2)),
    Math.abs(a!.y + a!.height / 2 - (b!.y + b!.height / 2)),
  ];
}

test.describe('글꼴 아이콘 크기', () => {
  // @scenario box=large
  // @effects empty_state_icon_is_centered_at_font_size
  test('관리자 빈 화면 아이콘은 원 가운데에 기본 글자보다 큰 글자 크기로 그려진다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read'));
    // 번들 확장은 분류 기준을 선언하지 않으므로 「확장별 분류」 탭은 늘 빈 화면 안내를 그린다.
    await page.goto('/admin/analytics/actions?tab=dimensions');

    const empty = page.locator('#dimensions_none');
    await expect(empty).toBeVisible({ timeout: 30_000 });
    const glyph = empty.locator('i').first();
    const circle = glyph.locator('xpath=..');

    expect(await fontSize(glyph), '글리프 글자 크기').toBeGreaterThanOrEqual(32);
    const circleBox = await circle.boundingBox();
    const glyphBox = await glyph.boundingBox();
    expect(glyphBox!.height, '글리프가 원의 1/3 보다 작다').toBeGreaterThan(circleBox!.height / 3);

    const [dx, dy] = await centerOffset(glyph, circle);
    expect(dx, '가로 가운데').toBeLessThanOrEqual(2);
    expect(dy, '세로 가운데').toBeLessThanOrEqual(2);
  });
});
