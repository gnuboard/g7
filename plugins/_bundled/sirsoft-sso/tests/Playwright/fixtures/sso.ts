/**
 * SSO 연동 사용자·관리자 흐름 E2E fixture.
 *
 * - 모의 IdP 는 CLI 로만 켜고 끈다(화면·설정으로 켜는 경로가 없다). spec 이 끝나면 반드시 끈다.
 * - 회원은 `sso.php` 로 이메일 접두어 `e2e798.` 로만 만들고 지운다(코어 회원 삭제 경로).
 * - 정책·IdP 칸은 시작 때 읽어 둔 값으로 끝에 되돌린다 — 정책 저장은 저장소에 바로 써서 개발 사이트의 다른 회원 토큰을 회수하지 않는다.
 * - 모의 OIDC·SAML 승인 화면은 이 사이트 안의 HTML 폼이라 변종·subject 를 고르고 [Approve]/[Deny] 를 누른다.
 */
import { expect, type Page } from '@playwright/test';
import { execFileSync, execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../../../../..');
const fixtureScript = resolve(here, 'sso.php');

/** 모의 OIDC IdP 키 (`sirsoft-sso:mock --seed-idp`) */
export const MOCK_OIDC = 'mock_oidc';

/** 모의 SAML IdP 키 (`sirsoft-sso:mock-saml --seed-idp`) */
export const MOCK_SAML = 'mock_saml_a';

/** 모의 IdP 가 소유하는 도메인 */
export const OWNED_DOMAIN = 'corp798.test';

/**
 * artisan 명령을 실행합니다.
 *
 * @param args 인자
 * @returns 출력
 */
function artisan(args: string): string {
  return execSync(`php artisan ${args}`, { cwd: root, encoding: 'utf8' });
}

/** 모의 IdP 게이트를 켜고 모의 OIDC IdP 행을 맞춥니다 (소유 도메인 corp798.test, 자동 가입, 동의 받음). */
export function mockOidcOn(): void {
  artisan(`sirsoft-sso:mock --enable --minutes=30 --seed-idp --domain=${OWNED_DOMAIN}`);
}

/**
 * 모의 IdP 게이트를 켜고 모의 SAML IdP 행을 맞춘 뒤 메타데이터를 다시 받습니다.
 *
 * 모의 IdP 서명 키는 플러그인 캐시에 게이트 최대 시간만큼만 살고, SP 가 받아 둔 메타데이터는 그보다 오래 남는다 —
 * 키가 새로 만들어진 뒤 옛 메타데이터의 인증서로 검증하면 정상 응답도 `saml_invalid` 다.
 */
export function mockSamlOn(): void {
  artisan('sirsoft-sso:mock --enable --minutes=30');
  artisan(`sirsoft-sso:mock-saml --seed-idp --key=${MOCK_SAML} --domain=${OWNED_DOMAIN}`);
  artisan(`sirsoft-sso:metadata-refresh --idp=${MOCK_SAML}`);
}

/** 모의 IdP 게이트를 끕니다. */
export function mockOff(): void {
  artisan('sirsoft-sso:mock --disable');
}

/**
 * fixture 명령을 실행하고 마지막 줄을 돌려줍니다.
 *
 * @param args 인자
 * @returns 마지막 출력 줄
 */
export function sso(...args: string[]): string {
  const out = execFileSync('php', [fixtureScript, ...args], { env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' }, encoding: 'utf8' });
  return out.trim().split(/\r?\n/).pop() ?? '';
}

/** 회원·연결·동의 상태 */
export type MemberState = {
  exists: boolean;
  has_password?: boolean;
  links?: { idp: string; linked_via: string }[];
  consents?: number;
  tokens?: number;
};

/**
 * 회원 상태를 읽습니다.
 *
 * @param email 이메일
 * @returns 상태
 */
export function memberState(email: string): MemberState {
  return JSON.parse(sso('state', email)) as MemberState;
}

/**
 * 사이트 정책 일부를 덮어씁니다.
 *
 * @param policy 바꿀 값 (비상 계정은 `emergency_emails`)
 */
export function putPolicy(policy: Record<string, unknown>): void {
  sso('policy-put', JSON.stringify(policy));
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
 * 측정용 비밀번호를 만듭니다 (실행마다 새 값 — 기록하지 않는다).
 *
 * @returns 비밀번호
 */
export function randomPassword(): string {
  return `E2e!${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36)}`;
}

/**
 * 모의 OIDC 승인 화면에서 변종·subject·이메일을 정하고 승인하거나 거부합니다.
 *
 * @param page 페이지 (모의 승인 화면이 열린 상태)
 * @param options 변종·subject·이메일·이름·결정
 */
export async function decideMockOidc(page: Page, options: { variant?: string; sub?: string; email?: string; name?: string; decision?: 'approve' | 'deny' }): Promise<void> {
  await expect(page.locator('select[name="variant"]')).toBeVisible();
  await page.locator('select[name="variant"]').selectOption(options.variant ?? 'full');
  if (options.email !== undefined) await page.locator('input[name="email"]').fill(options.email);
  if (options.sub !== undefined) await page.locator('input[name="sub"]').fill(options.sub);
  if (options.name !== undefined) await page.locator('input[name="name"]').fill(options.name);
  await page.locator(`button[value="${options.decision ?? 'approve'}"]`).click();
}

/**
 * 모의 SAML 승인 화면에서 변종·NameID·이메일을 정하고 승인하거나 거부합니다.
 *
 * @param page 페이지 (모의 승인 화면이 열린 상태)
 * @param options 변종·NameID·이메일·이름·결정
 */
export async function decideMockSaml(page: Page, options: { variant?: string; nameId?: string; email?: string; name?: string; decision?: 'approve' | 'deny' }): Promise<void> {
  await expect(page.locator('select[name="variant"]')).toBeVisible();
  await page.locator('select[name="variant"]').selectOption(options.variant ?? 'ok');
  if (options.email !== undefined) await page.locator('input[name="email"]').fill(options.email);
  if (options.nameId !== undefined) await page.locator('input[name="name_id"]').fill(options.nameId);
  if (options.name !== undefined) await page.locator('input[name="name"]').fill(options.name);
  await page.locator(`button[value="${options.decision ?? 'approve'}"]`).click();
}

/**
 * 첫 로그인 동의 모달에서 두 항목에 동의하고 완료한 뒤 로그인 인계까지 기다립니다.
 *
 * 제출 버튼은 두 항목 모두 체크하기 전에는 비활성이다 — 그것도 함께 단언한다.
 *
 * @param page 페이지 (`/login?sso_pending=…` 또는 `/admin/login?sso_pending=…`)
 * @param guard 사용자 화면 `user`, 관리자 화면 `admin` (모달 id·인계 교환 API 가 다르다)
 * @returns 완료·인계 응답 상태 코드
 */
export async function completeConsent(page: Page, guard: 'user' | 'admin' = 'user'): Promise<{ complete: number; handoff: number }> {
  const submit = page.locator(guard === 'admin' ? '#sso_admin_consent_submit' : '#sso_consent_submit');
  const handoffPath = guard === 'admin' ? '/api/auth/admin/login/handoff' : '/api/auth/login/handoff';
  await expect(submit).toBeVisible({ timeout: 15_000 });
  await expect(submit).toBeDisabled();
  for (const name of ['sso_agree_terms', 'sso_agree_privacy']) {
    // 동의 칸은 상태가 값을 소유한다(전역 상태 반영 뒤 다시 그린다) — 클릭하고 체크 상태를 기다린다.
    await page.locator(`input[name="${name}"]`).click();
    await expect(page.locator(`input[name="${name}"]`)).toBeChecked();
  }
  await expect(submit).toBeEnabled();
  const [complete, handoff] = await Promise.all([
    page.waitForResponse((r) => /\/pending\/[^/]+\/complete$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST'),
    page.waitForResponse((r) => new URL(r.url()).pathname === handoffPath),
    submit.click(),
  ]);
  return { complete: complete.status(), handoff: handoff.status() };
}

/**
 * 쿠키 안내 띠(화면 아래 고정)를 닫습니다 — 열려 있으면 아래쪽 버튼을 가려 클릭이 닿지 않는다.
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
