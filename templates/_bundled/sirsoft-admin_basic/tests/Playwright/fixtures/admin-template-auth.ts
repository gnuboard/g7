/**
 * sirsoft-admin_basic 템플릿 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 `issueToken` / `authenticatePage` 헬퍼를
 * 재사용한다. 권한 식별자는 임의 string 이므로 코어 PlaywrightIssueToken 커맨드가
 * Permission::firstOrCreate 로 자동 생성한다.
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 tests/Playwright/fixtures/auth.ts
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type AdminTemplateAuthFixtures = {
  /** 레이아웃 편집/조회 권한 보유 토큰 */
  layoutEditToken: string;
  /** 관리자 대시보드 진입 권한 보유 토큰 (모바일 뷰포트 검증용) */
  adminDashboardToken: string;
  /** 회원 목록·등록 권한 보유 토큰 (목록 컨텍스트 왕복 검증용) */
  userManageToken: string;
  /** 템플릿 목록 조회 권한 보유 토큰 (탭 전환 검증용) */
  templateReadToken: string;
};

export const test = base.extend<AdminTemplateAuthFixtures>({
  layoutEditToken: async ({}, use) => {
    await use(issueToken('core.templates.layouts.edit', 'core.templates.read'));
  },
  adminDashboardToken: async ({}, use) => {
    await use(issueToken('core.dashboard.read'));
  },
  userManageToken: async ({}, use) => {
    await use(issueToken('core.users.read', 'core.users.write'));
  },
  templateReadToken: async ({}, use) => {
    await use(issueToken('core.templates.read'));
  },
});

export { issueToken, authenticatePage };
export { expect } from '@playwright/test';
