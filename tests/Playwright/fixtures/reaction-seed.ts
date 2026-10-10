/**
 * 게시판 반응 시드 fixture (#525).
 *
 * `playwright:seed-board-reaction` 을 불러 **세 상태의 행**(정상 · 반응 꺼짐 · 대상 삭제됨)과
 * **게시판이 지워진 행**을 한 화면에 세운다. 여기에 글 반응과 댓글 반응이 섞이고 유형 하나는
 * 비활성이라, 화면이 상태를 실제로 구분하는지 재려면 이 조합이 필요하다.
 *
 * 마친 뒤 `--teardown` 으로 되돌린다. 개발 DB 에 E2E 회원·게시판이 쌓이면 회원 목록·게시판
 * 목록의 건수가 조용히 달라져 다른 화면의 실측을 흔든다.
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { issueToken, issueScopedToken } from './auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 커맨드가 돌려주는 식별자 묶음. */
export type ReactionSeed = {
  member_a_token: string;
  member_b_token: string;
  member_a_uuid: string;
  member_b_uuid: string;
  member_c_uuid: string;
  member_a_email: string;
  member_b_email: string;
  member_a_name: string;
  board_free_slug: string;
  board_notice_slug: string;
  board_free_id: number;
  post_alive_id: number;
  post_body_match_id: number;
  post_deleted_id: number;
  comment_alive_id: number;
  comment_deleted_id: number;
  report_log_pending_id: number;
  report_log_rejected_id: number;
  reaction_ids: number[];
  other_member_reaction_id: number;
  withdrawn_member_reaction_id: number;
  inactive_type_reaction_id: number;
  reaction_type_like_id: number;
  reaction_type_dislike_id: number;
  reaction_type_inactive_id: number;
  reaction_type_inactive_code: string;
  title_keyword: string;
  body_keyword: string;
};

type ReactionFixtures = {
  /** 시드된 반응 도메인 데이터 */
  reactionSeed: ReactionSeed;
  /** 반응 조회 + 삭제 + 회원 조회 권한 토큰 */
  reactionManageToken: string;
  /** 조회만 가진 토큰 — 체크 칸·삭제가 잠기는지 보는 대조군 */
  reactionViewOnlyToken: string;
  /** 게시판 환경설정 토큰 — 반응 유형 편집기를 여는 데 쓴다 */
  settingsToken: string;
};

/**
 * 시드 커맨드를 실행하고 JSON 결과를 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 파싱된 결과 객체
 */
function runSeed(args: string): Record<string, unknown> {
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../');
  const out = execSync(`php artisan playwright:seed-board-reaction ${args}`, {
    cwd: coreRoot,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

export const test = base.extend<ReactionFixtures>({
  reactionSeed: async ({}, use) => {
    const seed = runSeed('--json') as ReactionSeed;
    await use(seed);
    runSeed('--teardown --json');
  },
  reactionManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-board.reactions.view', 'sirsoft-board.reactions.manage', 'core.users.read'));
  },
  reactionViewOnlyToken: async ({}, use) => {
    // admin 역할은 전 권한을 갖는다 — 「삭제 권한 없음」 을 재현하려면 역할 없이 발급해야 한다.
    await use(issueScopedToken('sirsoft-board.reactions.view'));
  },
  settingsToken: async ({}, use) => {
    await use(issueToken('sirsoft-board.settings.read', 'sirsoft-board.settings.update'));
  },
});

export { expect } from '@playwright/test';
export { authenticatePage } from './auth';
