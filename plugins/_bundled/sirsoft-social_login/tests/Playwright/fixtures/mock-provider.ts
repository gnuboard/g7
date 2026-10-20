/**
 * 소셜 로그인 사용자 흐름 E2E fixture.
 *
 * - 모의 제공자는 CLI 로만 켜고 끈다(화면·설정으로 켜는 경로가 없다). spec 이 끝나면 반드시 끈다.
 * - 회원은 `members.php` 로 이메일 접두어 `e2e40.` 로만 만들고 지운다(코어 회원 삭제 경로 — 연결 정리 리스너 포함).
 * - 모의 동의 화면은 이 사이트 안의 HTML 폼이라 변종·제공자 사용자 ID 를 고르고 [동의하고 계속]을 누른다.
 */
import { expect, type Page } from '@playwright/test';
import { execFileSync, execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../../../../..');
const membersScript = resolve(here, 'members.php');

/**
 * 플러그인 artisan 명령을 실행합니다.
 *
 * @param args 인자
 * @returns 출력
 */
function artisan(args: string): string {
  return execSync(`php artisan ${args}`, { cwd: root, encoding: 'utf8' });
}

/** 모의 제공자를 켭니다 (30분 뒤 저절로 꺼진다). */
export function mockOn(): void {
  artisan('sirsoft-social_login:mock --enable --minutes=30');
}

/** 모의 제공자를 끕니다. */
export function mockOff(): void {
  artisan('sirsoft-social_login:mock --disable');
}

/**
 * 회원 fixture 명령을 실행하고 마지막 줄을 돌려줍니다.
 *
 * @param args 인자
 * @returns 마지막 출력 줄
 */
export function members(...args: string[]): string {
  const out = execFileSync('php', [membersScript, ...args], { env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' }, encoding: 'utf8' });
  return out.trim().split(/\r?\n/).pop() ?? '';
}

/**
 * 실행마다 겹치지 않는 접미어를 만듭니다.
 *
 * @returns 접미어
 */
export function runId(): string {
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
}

/**
 * 모의 동의 화면에서 변종·사용자 ID·이메일을 정하고 동의합니다.
 *
 * @param page 페이지 (모의 동의 화면이 열린 상태)
 * @param options 변종·제공자 사용자 ID·이메일
 */
export async function approveMock(page: Page, options: { variant?: string; subject: string; email?: string }): Promise<void> {
  await expect(page.locator('select[name="variant"]')).toBeVisible();
  await page.locator('select[name="variant"]').selectOption(options.variant ?? 'full_verified');
  await page.locator('input[name="subject"]').fill(options.subject);
  if (options.email !== undefined) {
    await page.locator('input[name="email"]').fill(options.email);
  }
  await page.locator('button[value="approve"]').click();
}

/**
 * 쿠키 안내 띠(화면 아래 고정)를 닫습니다 — 열려 있으면 아래쪽 버튼을 가려 클릭이 닿지 않는다.
 *
 * 띠는 동의 상태 응답이 오면 다시 그려진다 — 잡은 버튼이 떨어져 나가면 클릭이 시험 시간 끝까지 재시도하므로, 짧은 제한으로
 * 몇 번 다시 잡는다.
 *
 * @param page 페이지
 */
export async function dismissCookieBanner(page: Page): Promise<void> {
  const decline = page.getByRole('button', { name: '동의하지 않고 계속하기' }).first();
  for (let attempt = 0; attempt < 3; attempt++) {
    if (!(await decline.isVisible().catch(() => false))) {
      return;
    }
    await decline.click({ timeout: 5_000 }).catch(() => undefined);
    if (await decline.isHidden({ timeout: 0 }).catch(() => false)) {
      return;
    }
    await page.waitForTimeout(500);
  }
  await expect(decline).toBeHidden({ timeout: 5_000 });
}
