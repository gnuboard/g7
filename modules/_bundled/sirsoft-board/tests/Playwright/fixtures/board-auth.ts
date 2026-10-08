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
  /** 답글 삭제 정책 E2E 용 — 게시판 CRUD + 환경설정 + 전용 게시판(e2e-reply-policy) 글 관리 토큰 */
  replyPolicyToken: string;
  /** 환경설정 조회/수정 권한 토큰 */
  settingsToken: string;
  /** 권한 없는 일반 사용자 토큰 (영역 부재 검증용) */
  noPermissionToken: string;
  /** 게시판 생성/수정/조회 권한 토큰 (관리자 메뉴 토글 검증용) */
  boardManageToken: string;
  /** 게시판 조회 + 첨부 다운로드 권한 토큰 (#413-58b 행위자 기록 검증용) */
  attachmentDownloadToken: string;
  /** 본문 썸네일 폴백 E2E 용 — 게시판 CRUD + 전용 게시판(e2e-content-thumbnail) 글/첨부 관리 토큰 (공개 #22) */
  contentThumbnailToken: string;
  /** 관리자 상세 원글·답변 첨부 내려받기 E2E 용 — 게시판 CRUD + 전용 게시판(e2e-reply-attachment) 글/첨부 관리 토큰 (#703) */
  replyAttachmentToken: string;
  /** 게시글 조회 권한 토큰 (#524 스크랩 사용자 흐름 검증용) */
  memberScrapToken: string;
  /** 게시판/게시글 조회 권한 토큰 (반응 등록/전환/해제 흐름 검증용, 반응은 로그인만 요구) */
  reactionToken: string;
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
  replyPolicyToken: async ({}, use) => {
    // 게시판 스코프 권한은 slug 가 식별자에 박혀 게시판을 만든 뒤에야 생긴다 — admin 역할이 그 권한을 받는다.
    // core.profile.read: 게시판 생성 필수값(board_manager_ids)에 넣을 본인 uuid 조회용.
    await use(
      issueToken(
        'core.profile.read',
        'sirsoft-board.boards.read',
        'sirsoft-board.boards.create',
        'sirsoft-board.boards.update',
        'sirsoft-board.boards.delete',
        'sirsoft-board.settings.read',
        'sirsoft-board.settings.update',
        // 게시판별 권한(sirsoft-board.{slug}.admin.*)은 spec 이 게시판을 만들 때 생기고 admin 역할(함께 부여)에 붙는다.
        // 게시판이 생기기 전에 그 식별자로 발급하면 발급 커맨드가 거절한다(없는 권한 — 7.1.0 O-D2).
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
    // 다운로드는 scope 권한(sirsoft-board.{slug}.attachments.download). notice 게시판이 있어야 발급된다(없으면 발급 커맨드가 거절).
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.notice.attachments.download',
      ),
    );
  },
  contentThumbnailToken: async ({}, use) => {
    // 전용 카드형 게시판(e2e-content-thumbnail)을 spec 안에서 생성/삭제하고,
    // 관리자 글 작성 + 첨부 업로드로 첨부 우선 정책까지 실측한다 (공개 #22).
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.boards.create',
        'sirsoft-board.boards.update',
        'sirsoft-board.boards.delete',
        // 게시판별 권한(sirsoft-board.{slug}.admin.*)은 spec 이 게시판을 만들 때 생기고 admin 역할(함께 부여)에 붙는다.
        // 게시판이 생기기 전에 그 식별자로 발급하면 발급 커맨드가 거절한다(없는 권한 — 7.1.0 O-D2).
      ),
    );
  },
  replyAttachmentToken: async ({}, use) => {
    // 전용 게시판(e2e-reply-attachment)을 spec 안에서 생성/삭제하고, 관리자 글·답변 작성 +
    // 첨부 업로드 후 관리자 상세 화면에서 원글·답변 첨부를 내려받는다 (#703).
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.boards.create',
        'sirsoft-board.boards.delete',
        // 게시판별 권한(sirsoft-board.{slug}.admin.*)은 spec 이 게시판을 만들 때 생기고 admin 역할(함께 부여)에 붙는다.
        // 게시판이 생기기 전에 그 식별자로 발급하면 발급 커맨드가 거절한다(없는 권한 — 7.1.0 O-D2).
      ),
    );
  },
  memberScrapToken: async ({}, use) => {
    // 스크랩은 로그인 회원이면 누구나 가능. 게시글 조회 권한만 있으면 상세 진입 → 토글 가능.
    await use(issueToken('sirsoft-board.free.posts.read'));
  },
  reactionToken: async ({}, use) => {
    // 반응은 별도 권한 없이 로그인(auth:sanctum)만 요구. 게시글 조회 권한만 발급.
    await use(
      issueToken(
        'sirsoft-board.boards.read',
        'sirsoft-board.notice.posts.read',
      ),
    );
  },
});

export { authenticatePage };
export { expect } from '@playwright/test';