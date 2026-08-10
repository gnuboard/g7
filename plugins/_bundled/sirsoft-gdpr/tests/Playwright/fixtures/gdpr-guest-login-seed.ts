/**
 * GDPR "게스트로 배너 닫음 → 다른 계정 로그인" 시나리오 도메인 시드 fixture.
 *
 * `playwright:seed-gdpr-guest-login` artisan 커맨드를 호출하여 다음을 발급받는다:
 *  - 서명된 게스트 세션 쿠키 값 (이미 "모두 동의" 이력이 기록된 상태)
 *  - 동의 이력이 전혀 없는 신규 회원 계정 (email/password 고정, 로그인 폼에 실제 입력)
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 미정의 → import.meta.url 로 재구성.
const __dirname = dirname(fileURLToPath(import.meta.url));

type GdprGuestLoginSeedFixtures = {
  /** 게스트→로그인 배너 재노출 시나리오용 시드 데이터 */
  gdprGuestLoginSeed: {
    guest_session_cookie_value: string;
    member_email: string;
    member_password: string;
  };
};

export const test = base.extend<GdprGuestLoginSeedFixtures>({
  gdprGuestLoginSeed: async ({}, use) => {
    // 6단계 상위 = 코어 루트 (artisan 실행 cwd)
    const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
    const out = execSync('php artisan playwright:seed-gdpr-guest-login --json', {
      cwd: coreRoot,
      encoding: 'utf-8',
      env: {
        ...process.env,
        G7_PLAYWRIGHT_BYPASS: '1',
      },
    });
    const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';
    const seed = JSON.parse(jsonLine);
    await use(seed);
  },
});

export { expect } from '@playwright/test';