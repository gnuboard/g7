/**
 * GDPR "게스트로 배너 닫음 → 다른 계정 로그인" 시나리오 도메인 시드 fixture.
 *
 * `playwright:seed-gdpr-guest-login` artisan 커맨드를 호출하여 다음을 발급받는다:
 *  - 서명된 게스트 세션 쿠키 값 (이미 "모두 동의" 이력이 기록된 상태)
 *  - 동의 이력이 전혀 없는 신규 회원 계정 (email/password 고정, 로그인 폼에 실제 입력)
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';
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
  gdprGuestLoginSeed: async ({}, use, testInfo) => {
    // 6단계 상위 = 코어 루트 (artisan 실행 cwd)
    const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
    // 이 시드를 쓰는 spec 이 여럿이고 설정이 fullyParallel 이라, 고정 주체를 함께 쓰면 다른 워커가 서로의
    // 게스트 세션·회원을 지운다 — spec 파일마다 다른 꼬리로 주체를 가른다.
    const suffix = createHash('sha1').update(testInfo.file).digest('hex').slice(0, 8);
    const out = execSync(`php artisan playwright:seed-gdpr-guest-login --json --suffix=${suffix}`, {
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