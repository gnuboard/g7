/**
 * CKEditor5 플러그인 권한 fixture.
 *
 * 코어 `tests/Playwright/fixtures/auth.ts` 의 헬퍼를 재사용하되, 플러그인 권한 토큰 fixture 를
 * 자체적으로 정의한다. 권한 식별자는 임의 string 이므로 코어 PlaywrightIssueToken 커맨드가
 * 그대로 동작한다 (Permission::firstOrCreate 가 자동 생성).
 *
 * 업로드 관리 화면은 조회와 삭제 권한이 분리돼 있으므로 두 토큰을 따로 발급해
 * 권한 경계(조회만 가진 계정의 삭제 403)를 실제로 밟는다.
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 fixtures/auth.ts
import { issueToken, issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type Ckeditor5AuthFixtures = {
  /** 업로드 조회 + 삭제 권한 보유 토큰 */
  uploadsManageToken: string;
  /** 업로드 조회만 가능한 토큰 (삭제 403 경계 검증용) */
  uploadsReadOnlyToken: string;
  /** 에디터가 실린 작성 화면 진입용 토큰 (자산 실패 폴백 검증) */
  editorToken: string;
};

export const test = base.extend<Ckeditor5AuthFixtures>({
  uploadsManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-ckeditor5.uploads.read', 'sirsoft-ckeditor5.uploads.delete'));
  },
  // 권한 경계를 실제로 밟으려면 admin 역할이 없어야 한다 — `issueToken` 은 admin 역할을 함께
  // 부여하고 admin 은 전체 권한을 보유하므로, 그 토큰으로는 삭제가 통과해 403 대신 404 가 온다.
  uploadsReadOnlyToken: async ({}, use) => {
    await use(issueScopedToken('admin.access', 'sirsoft-ckeditor5.uploads.read'));
  },
  // 자산 실패 폴백은 권한 경계가 아니라 편집기 마운트를 보는 검증이므로 admin 역할을
  // 함께 받는 `issueToken` 으로 충분하다 (게시글 작성 화면 전체가 열려야 한다).
  editorToken: async ({}, use) => {
    await use(issueToken('sirsoft-board.posts.create'));
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';
