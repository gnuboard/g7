/**
 * Smoke: admin 로그인 페이지 진입 검증.
 *
 * - /admin/login 진입 시 페이지 마운트 + email/password 입력 필드 존재 확인
 * - 인증 없이 접근 가능한 화면이므로 토큰 fixture 불필요
 */
import { test, expect } from '@playwright/test';

test('@smoke admin 로그인 페이지 진입 시 email/password 입력 필드가 렌더된다', async ({ page }) => {
  await page.goto('/admin/login');

  // SPA 마운트 대기 — h1/타이틀 또는 첫 입력 필드 가시화
  const emailInput = page.locator('input[type="email"], input[name="email"]').first();
  await expect(emailInput).toBeVisible({ timeout: 15_000 });

  const passwordInput = page.locator('input[type="password"], input[name="password"]').first();
  await expect(passwordInput).toBeVisible();
});
