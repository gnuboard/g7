/**
 * Smoke: 홈페이지 기본 진입 검증.
 *
 * - 토큰 없이 / 로 진입 시 200 응답 + Header 의 nav 버튼 3종(home/popular/shop)이 표시되는지 확인
 * - sirsoft-basic 템플릿 사용자 기본 흐름의 최소 헬스체크
 */
import { test, expect } from '@playwright/test';

test('@smoke 홈페이지 진입 시 네비게이션 버튼이 표시된다', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByTestId('nav-home')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByTestId('nav-popular')).toBeVisible();
  await expect(page.getByTestId('nav-shop')).toBeVisible();
});
