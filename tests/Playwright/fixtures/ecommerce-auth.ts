/**
 * Playwright 이커머스 인증 fixture.
 *
 * 코어 `auth.ts` 의 `issueToken` / `authenticatePage` 헬퍼를 재사용해
 * 이커머스 도메인 spec 이 쓰는 토큰 fixture 를 구성한다.
 * 권한 식별자는 임의 string 이므로 코어 헬퍼를 그대로 쓸 수 있다.
 *
 * 참조: docs/testing/e2e-testing.md "인증 가드"
 */
import { test as base } from '@playwright/test';
import { authenticatePage, issueToken } from './auth';

/**
 * 이커머스 spec 용 토큰 fixture.
 *
 * - memberToken       : 일반 회원 (마이페이지 주문/리뷰 등 본인 리소스 접근)
 * - ordersUpdateToken : 주문 상태 변경 권한 (관리자 주문 관리)
 *
 * 토큰은 spec 별로 lazy 발급된다.
 */
type EcommerceAuthFixtures = {
  memberToken: string;
  ordersUpdateToken: string;
};

export const test = base.extend<EcommerceAuthFixtures>({
  memberToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.orders.read'));
  },
  ordersUpdateToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.orders.update'));
  },
});

export { authenticatePage, issueToken };
export { expect } from '@playwright/test';
