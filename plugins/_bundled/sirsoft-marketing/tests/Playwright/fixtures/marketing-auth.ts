/**
 * 마케팅 동의 플러그인 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼(issueToken / authenticatePage)를 재사용한다.
 * 설정 화면은 플러그인 설정 조회·수정 권한(core.plugins.read/update)을 요구한다.
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 tests/Playwright/fixtures/auth.ts
// (plugins/_bundled/sirsoft-marketing/tests/Playwright/fixtures → 코어 루트)
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type MarketingAuthFixtures = {
  /** 플러그인 설정 조회+수정 권한 토큰 */
  settingsToken: string;
};

export const test = base.extend<MarketingAuthFixtures>({
  settingsToken: async ({}, use) => {
    await use(issueToken('core.plugins.read', 'core.plugins.update'));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
