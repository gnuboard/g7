/**
 * 이커머스 모듈 권한 fixture (sample skeleton).
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼를 재사용하되, 모듈 권한 토큰 fixture 를
 * 자체적으로 정의한다. 권한 식별자는 임의 string 이므로 코어 PlaywrightIssueToken 커맨드가
 * 그대로 동작한다 (Permission::firstOrCreate 가 자동 생성).
 *
 * 활성화 절차 (이커머스 모듈 작업 세션):
 *   1. 사이드바 메뉴 컴포넌트에 data-testid="admin-menu-ecommerce-settings" 보강
 *   2. 환경설정 폼 컴포넌트에 data-testid="ecommerce-settings-form" 보강
 *   3. specs/admin/ecommerce-settings-menu.spec.ts 의 test.skip → test 변경
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 fixtures/auth.ts
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type EcommerceAuthFixtures = {
  /** 이커머스 환경설정 권한 보유 토큰 */
  settingsToken: string;
  /** 이커머스 대시보드 조회 권한 보유 토큰 (대시보드 이커머스 영역 검증용) */
  dashboardToken: string;
  /** 권한 없는 일반 사용자 토큰 (메뉴 미노출 검증용) */
  noPermissionToken: string;
  /** 마일리지 조회 + 수동 관리 권한 보유 토큰 */
  mileageManageToken: string;
  /** 마일리지 조회 전용 토큰 (수동 지급/차감 게이팅 검증용) */
  mileageReadOnlyToken: string;
  /** 상품 조회 + 수정 권한 보유 토큰 (상품폼 등록/수정 검증용) */
  productManageToken: string;
};

export const test = base.extend<EcommerceAuthFixtures>({
  settingsToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update'));
  },
  dashboardToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.dashboard.view'));
  },
  noPermissionToken: async ({}, use) => {
    // 빈 권한 — 인증만 통과, 어떤 모듈 권한도 없음
    await use(issueToken());
  },
  mileageManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.mileage.read', 'sirsoft-ecommerce.mileage.manage'));
  },
  mileageReadOnlyToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.mileage.read'));
  },
  productManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.products.read', 'sirsoft-ecommerce.products.update'));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
