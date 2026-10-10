/**
 * Playwright 비밀번호 만료 fixture (코어 영역, #736).
 *
 * 비밀번호 변경 단계·만료 대상은 **회원의 변경일·사이트 보안 설정**에 달려 있어 브라우저만으로 만들 수 없다.
 * `php artisan playwright:seed-password-expiry`(S1)로 다음을 가역적으로 준비한다.
 *
 *   ① 보안 설정 스냅샷(`--snapshot-settings`) → 만료 켜기(`--enable-policy`) → 끝나면 스냅샷 복원(`--restore-settings`).
 *      복원은 SettingsService 저장 경로를 지나므로 캐시·설정 미러도 함께 되돌아간다(파일 직접 복사 금지).
 *   ② 상태별 테스트 계정(`--stage=fresh|notice|expired|postponed|admin_assigned`, `--admin`, `--with-history`,
 *      `--issue-token`) — 이메일은 `pwexp-…@playwright.local`, 끝나면 `--purge-users` 로 지운다.
 *
 * 원복은 실패해도 조용히 넘기지 않는다 — 만료가 켜진 채 남으면 사이트 회원이 로그인 때 변경을 요구받는다.
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** artisan 이 있는 코어 루트 */
function coreRoot(): string {
  return process.env.G7_ROOT || resolve(__dirname, '../../../');
}

/**
 * `playwright:seed-password-expiry` 를 실행하고 비어 있지 않은 출력 줄을 돌려준다.
 *
 * @param args 커맨드 인자 (예: ['--stage=expired', '--admin'])
 * @returns stdout 의 비어 있지 않은 줄 목록
 */
export function runSeedPasswordExpiry(args: string[]): string[] {
  const command = `php artisan playwright:seed-password-expiry ${args.join(' ')}`.trim();
  const stdout = execSync(command, {
    cwd: coreRoot(),
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });

  return stdout.split(/\r?\n/).map((line) => line.trim()).filter((line) => line.length > 0);
}

/** 시드 계정 하나 — 이메일·비밀번호·(요청 시) 다른 기기 토큰 */
export type SeededAccount = { email: string; password: string; otherDeviceToken: string | null };

/**
 * 상태별 테스트 계정 하나를 만들거나(같은 조합이면) 그 상태로 덮어쓴다.
 *
 * @param stage 시드 상태
 * @param options admin(관리자 역할)·withHistory(이전 비밀번호 OldPassw0rd!1~3)·issueToken(다른 기기 토큰)
 * @returns 시드 계정
 */
export function seedAccount(
  stage: 'fresh' | 'notice' | 'expired' | 'postponed' | 'admin_assigned',
  options: { admin?: boolean; withHistory?: boolean; issueToken?: boolean } = {},
): SeededAccount {
  const args = [`--stage=${stage}`];
  if (options.admin) args.push('--admin');
  if (options.withHistory) args.push('--with-history');
  if (options.issueToken) args.push('--issue-token');

  const lines = runSeedPasswordExpiry(args);
  const email = lines.find((line) => line.endsWith('@playwright.local'));
  const tokenLine = lines.find((line) => line.startsWith('other-device-token: '));
  if (!email) {
    throw new Error(`시드 계정 이메일을 출력에서 찾지 못했습니다: ${lines.join(' | ')}`);
  }

  return {
    email,
    password: lines[lines.length - 1],
    otherDeviceToken: tokenLine ? tokenLine.replace('other-device-token: ', '') : null,
  };
}

/** 보안 설정을 스냅샷하고 만료를 켠다(범위 전체·변경해야 로그인·시작일 기간+1일 전). */
export function enablePasswordExpiryFixture(): void {
  runSeedPasswordExpiry(['--snapshot-settings']);
  runSeedPasswordExpiry(['--enable-policy']);
}

/** 테스트 계정을 지우고 보안 설정을 스냅샷으로 되돌린다. */
export function restorePasswordExpiryFixture(): void {
  runSeedPasswordExpiry(['--purge-users']);
  runSeedPasswordExpiry(['--restore-settings']);
}
