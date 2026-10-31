/**
 * 스크랩 도메인 시드 fixture (#524).
 *
 * `playwright:seed-board-scrap` 커맨드를 불러 스크랩 화면이 필요로 하는 **네 상태의 행**을
 * 한 번에 세운다 — 정상 · 스크랩 중지(게시판 설정 off) · 삭제된 원글 · 게시판이 지워진 행.
 * 손으로 만들면 매번 다른 상태가 되어 실행 간 결과를 비교할 수 없다.
 *
 * 시드는 같은 slug·계정을 먼저 지우고 다시 만들어(멱등) 워커가 여럿이어도 상태가 같다.
 * 마친 뒤에는 `--teardown` 으로 되돌린다 — 개발 DB 에 E2E 계정이 쌓이면 다른 화면의 목록
 * 건수가 조용히 달라져 그쪽 실측을 흔든다.
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { issueToken, issueScopedToken } from '../../../../../../tests/Playwright/fixtures/auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 커맨드가 돌려주는 식별자 묶음. */
export type ScrapSeed = {
  member_a_token: string;
  member_b_token: string;
  member_a_uuid: string;
  member_b_uuid: string;
  member_a_email: string;
  member_b_email: string;
  board_free_slug: string;
  board_notice_slug: string;
  post_alive_id: number;
  post_body_match_id: number;
  post_deleted_id: number;
  scrap_ids: number[];
  other_member_scrap_id: number;
  title_keyword: string;
  body_keyword: string;
};

type ScrapFixtures = {
  /** 시드된 스크랩 도메인 데이터 */
  scrapSeed: ScrapSeed;
  /** 스크랩 조회 + 삭제 권한 토큰 (관리자 화면용) */
  scrapManageToken: string;
  /** 스크랩 조회만 가진 토큰 — 삭제 버튼이 잠기는지 보는 대조군 */
  scrapViewOnlyToken: string;
};

/**
 * artisan 을 실행할 코어 루트 (확장 디렉토리에서 Playwright 가 뜨므로 자기 위치에서 역산).
 *
 * @returns 코어 루트 절대경로
 */
function coreRoot(): string {
  return process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
}

/**
 * 시드 커맨드를 실행하고 JSON 결과를 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 파싱된 결과 객체
 */
function runSeed(args: string): Record<string, unknown> {
  const out = execSync(`php artisan playwright:seed-board-scrap ${args}`, {
    cwd: coreRoot(),
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  // artisan 출력에 경고가 섞일 수 있어 마지막 JSON 라인만 읽는다.
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

export const test = base.extend<ScrapFixtures>({
  scrapSeed: async ({}, use) => {
    const seed = runSeed('--json') as ScrapSeed;
    await use(seed);
    runSeed('--teardown --json');
  },
  scrapManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-board.scraps.view', 'sirsoft-board.scraps.manage', 'core.users.read'));
  },
  scrapViewOnlyToken: async ({}, use) => {
    // admin 역할은 전 권한을 갖는다 — 「삭제 권한 없음」 을 재현하려면 역할 없이 발급해야 한다.
    await use(issueScopedToken('sirsoft-board.scraps.view'));
  },
});

export { expect } from '@playwright/test';
export { authenticatePage, issueToken, issueScopedToken } from '../../../../../../tests/Playwright/fixtures/auth';
