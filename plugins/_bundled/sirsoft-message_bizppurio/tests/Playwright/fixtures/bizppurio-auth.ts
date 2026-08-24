/**
 * 비즈뿌리오 메시징 플러그인 권한 fixture (#597).
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼(issueToken / authenticatePage)를 재사용한다.
 * 알림 템플릿 라이프사이클 화면은 플러그인 권한(messaging.view/manage)과 코어 알림 설정
 * 화면 접근 권한을 함께 요구한다.
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 tests/Playwright/fixtures/auth.ts
// (plugins/_bundled/sirsoft-message_bizppurio/tests/Playwright/fixtures → 코어 루트)
import { issueToken, issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type BizppurioAuthFixtures = {
  /** 알림 템플릿 조회+관리 권한 토큰 (코어 설정 화면 접근 포함) */
  messagingManageToken: string;
  /** 조회 전용 권한 토큰 (권한 경계 검증용) */
  messagingViewToken: string;
};

export const test = base.extend<BizppurioAuthFixtures>({
  messagingManageToken: async ({}, use) => {
    await use(issueToken(
      'core.settings.read',
      'core.settings.update',
      'core.plugins.read',
      'core.plugins.update',
      'sirsoft-message_bizppurio.messaging.view',
      'sirsoft-message_bizppurio.messaging.manage',
    ));
  },
  messagingViewToken: async ({}, use) => {
    // issueToken 은 admin 역할(전체 권한)을 함께 부여한다 — 권한 경계를 재는 토큰은
    // 반드시 issueScopedToken 이어야 한다. 라운드 5 실측에서 view 전용 토큰으로 POST 가
    // 201 을 받았고, 원인은 제품이 아니라 이 fixture 였다.
    await use(issueScopedToken(
      'core.plugins.read',
      'sirsoft-message_bizppurio.messaging.view',
    ));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
