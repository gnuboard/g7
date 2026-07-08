/**
 * 페이지 모듈 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼(issueToken / authenticatePage)를 재사용하되,
 * 모듈 권한 토큰 fixture 를 자체적으로 정의한다. 권한 식별자는 임의 string 이므로 코어
 * PlaywrightIssueToken 커맨드가 그대로 동작한다 (Permission::firstOrCreate 가 자동 생성).
 *
 * 권한 식별자 (module src 정의):
 *   sirsoft-page.pages.read / create / update / delete
 *
 * 활성화 절차 (페이지 모듈 후속 작업 세션):
 *   1. 대상 화면 컴포넌트에 data-testid 보강 (각 spec docblock 의 "활성화 절차" 참조)
 *   2. 해당 spec 의 test.describe.skip → test.describe 변경
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 fixtures/auth.ts
// (modules/_bundled/sirsoft-page/tests/Playwright/fixtures → 코어 루트)
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type PageAuthFixtures = {
  /** 페이지 조회 권한 토큰 (목록/상세 조회 검증용) */
  pageReadToken: string;
  /** 페이지 조회 + 생성 권한 토큰 (등록 폼 검증용) */
  pageCreateToken: string;
  /** 페이지 조회 + 수정 권한 토큰 (수정 폼/첨부/버전 검증용) */
  pageManageToken: string;
  /** 권한 없는 일반 사용자 토큰 (메뉴/화면 미노출 검증용) */
  noPermissionToken: string;
};

export const test = base.extend<PageAuthFixtures>({
  pageReadToken: async ({}, use) => {
    await use(issueToken('sirsoft-page.pages.read'));
  },
  pageCreateToken: async ({}, use) => {
    await use(issueToken('sirsoft-page.pages.read', 'sirsoft-page.pages.create'));
  },
  pageManageToken: async ({}, use) => {
    await use(
      issueToken(
        'sirsoft-page.pages.read',
        'sirsoft-page.pages.create',
        'sirsoft-page.pages.update'
      )
    );
  },
  noPermissionToken: async ({}, use) => {
    // 빈 권한 — 인증만 통과, 어떤 모듈 권한도 없음
    await use(issueToken());
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
