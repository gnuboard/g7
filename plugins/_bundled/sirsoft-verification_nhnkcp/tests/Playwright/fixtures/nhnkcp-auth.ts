/**
 * NHN KCP 본인확인 플러그인 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼(issueToken / authenticatePage)를 재사용한다.
 * 본 플러그인 설정 화면은 코어 플러그인 관리 권한(`core.plugins.*`)으로 접근하므로 별도 권한
 * 네임스페이스를 만들지 않는다 — 레이아웃 `permissions` 선언과 동일한 식별자를 사용한다.
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 tests/Playwright/fixtures/auth.ts
// (plugins/_bundled/sirsoft-verification_nhnkcp/tests/Playwright/fixtures → 코어 루트)
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type NhnkcpAuthFixtures = {
  /** 플러그인 설정 조회/수정 권한 토큰 */
  pluginSettingsToken: string;
};

export const test = base.extend<NhnkcpAuthFixtures>({
  pluginSettingsToken: async ({}, use) => {
    await use(issueToken('core.plugins.read', 'core.plugins.update'));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
