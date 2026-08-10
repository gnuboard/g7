/**
 * GDPR 플러그인 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼를 재사용하되, 플러그인 권한 토큰 fixture 를
 * 자체적으로 정의한다. 권한 식별자는 임의 string 이므로 코어 PlaywrightIssueToken 커맨드가
 * 그대로 동작한다 (Permission::firstOrCreate 가 자동 생성).
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 fixtures/auth.ts
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type GdprAuthFixtures = {
  /** GDPR 개인정보 조회 + 설정 변경 권한 보유 토큰 (환경설정 화면 접근용) */
  privacyManageToken: string;
};

export const test = base.extend<GdprAuthFixtures>({
  privacyManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-gdpr.privacy.view', 'sirsoft-gdpr.privacy.update'));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
