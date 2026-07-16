/**
 * 게시판 모듈 권한 fixture (skeleton).
 *
 * 코어 fixture 의 issueToken / authenticatePage 헬퍼를 재사용. 권한 식별자는 임의 string
 * 이므로 코어 PlaywrightIssueToken 커맨드(Permission::firstOrCreate) 가 즉시 발급한다.
 *
 * 활성화 절차 (게시판 대시보드 E2E 작업 세션):
 *   1. templates/sirsoft-admin_basic 의 quick_menu 슬롯이 게시판 모듈 extension 으로 채워진 상태에서
 *      게시글/신고 버튼에 data-testid="qm-boards" / "qm-reports" 보강
 *   2. 모듈 community extension 의 각 카드(post_graph_card / latest_posts_card / report_management_card)
 *      에 data-testid="board-dashboard-{name}" 보강
 *   3. specs/admin/dashboard-board-injection.spec.ts 의 test.skip → test 변경
 *   4. 모듈 활성/비활성 토글 fixture (또는 시드 커맨드) 도입
 */
import { test as base } from '@playwright/test';
// 6단계 상위 = 코어 루트의 fixtures/auth.ts
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

type BoardAuthFixtures = {
  /** 대시보드 진입 권한 + 게시판 조회/신고 조회 권한 토큰 */
  dashboardToken: string;
  /** 환경설정 조회/수정 권한 토큰 */
  settingsToken: string;
  /** 권한 없는 일반 사용자 토큰 (영역 부재 검증용) */
  noPermissionToken: string;
  /** 게시판 생성/수정/조회 권한 토큰 (관리자 메뉴 토글 검증용) */
  boardManageToken: string;
  /** 게시판 조회 + 첨부 다운로드 권한 토큰 (#413-58b 행위자 기록 검증용) */
  attachmentDownloadToken: string;
};

export const test = base.extend<BoardAuthFixtures>({
  dashboardToken: async ({}, use) => {
    await use(
      issueToken(
        'core.dashboard.read',
        'sirsoft-board.boards.read',
        'sirsoft-board.reports.view',
      ),
    );
  },
  settingsToken: async ({}, use) => {
    await use(
      issueToken(
        'sirsoft-board.settings.read',
        'sirsoft-board.settings.update',
      ),
    );
  },
  noPermissionToken: async ({}, use) => {
    await use(issueToken());
  },
  boardManageToken: async ({}, use) => {
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.boards.create',
        'sirsoft-board.boards.update',
        'sirsoft-board.boards.delete',
      ),
    );
  },
  attachmentDownloadToken: async ({}, use) => {
    // 다운로드는 scope 권한(sirsoft-board.{slug}.attachments.download). 임의 식별자라 즉시 발급.
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.notice.attachments.download',
      ),
    );
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';