/**
 * E2E: 표시 이름을 닉네임으로 바꾸면 게시글 작성자가 닉네임으로 보인다 (#490 계획서 10절 ④, T6-c).
 *
 * 게시판 글 영역(`sirsoft-board:posts`)은 사이트 기본 영역(`core:site`)을 따른다(기본값 inherit). 회원이 쓴 글의 작성자는
 * 사이트 기본이 「이름」이면 실명, 「닉네임」으로 고정하면 같은 글(과거 글 포함)이 닉네임으로 바뀌어야 한다.
 * 운영자가 표시 이름 구획에서 바꾸는 화면 조작은 코어 스펙 `tests/Playwright/specs/member-fields/member-display-name.spec.ts`
 * 가 잰다. 이 스펙은 설정을 API 로 바꾸고 게시판 목록과 글 보기 화면의 작성자 글자를 잰다.
 *
 * 준비와 원복:
 *   - 임시 게시판: `playwright:seed-board-posts --tag=<꼬리표>`(비로그인 읽기, 회원 쓰기) → afterAll `--teardown`.
 *   - 시험 회원 하나(이메일 접두 `e2e490.`, 실명과 닉네임이 다르다) → afterAll 에서 삭제. 그 회원이 API 로 글 하나를 쓴다.
 *   - 회원 탭 설정(표시 이름 영역): beforeAll 이 탭 전체 값을 떠 두고 사이트 기본을 「이름 고정」, 게시판 글 영역을 「따름」으로
 *     맞춘 뒤 시작하고, afterAll 이 떠 둔 값을 그대로 다시 저장한다.
 *
 * 축 요약(마커 아님 — 평문): case=site_name, site_nickname
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import {
  bearer,
  createE2eMember,
  deleteE2eMember,
  dismissCookieBanner,
  loginMemberApi,
  openApiContext,
  patchSettingsTab,
  purgeE2eMembers,
  readSettingsTab,
  uniqueTag,
  withDisplayNameAreas,
  writeSettingsTab,
  type E2eMember,
} from '../../../../../../../tests/Playwright/fixtures/member-fields';

const __dirname = dirname(fileURLToPath(import.meta.url));

test.describe.configure({ mode: 'serial', timeout: 120_000 });

const tag = uniqueTag();
const realName = `E2E작성자${tag}`;
const nickname = `e2e글닉${tag}`;
const postTitle = `[e2e490] 표시 이름 글 ${tag}`;

/** 시드 게시판 — slug 와 시드 회원 토큰 (이 스펙은 시드 회원 대신 자기 회원으로 쓴다) */
type BoardSeed = { board_slug: string; post_ids: number[] };

let api: APIRequestContext;
let adminToken: string;
let memberSnapshot: Record<string, unknown> | null = null;
let member: E2eMember | null = null;
let board: BoardSeed | null = null;
let postId = 0;
const seedTag = `dn${tag}`;

/**
 * 게시판 시드 커맨드를 코어 루트에서 실행한다(3중 가드 옵트인).
 *
 * @param args 커맨드 인자
 * @returns 표준 출력
 */
function runBoardSeed(args: string[]): string {
  return execSync(`php artisan playwright:seed-board-posts ${args.join(' ')}`, {
    cwd: process.env.G7_ROOT || resolve(__dirname, '../../../../../../../'),
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
}

test.beforeAll(async ({ playwright }, testInfo) => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.create', 'core.users.delete');
  await purgeE2eMembers(api, adminToken);

  const current = await readSettingsTab(api, adminToken, 'member');
  memberSnapshot = await patchSettingsTab(api, adminToken, 'member', {
    display_name_areas: withDisplayNameAreas(current, {
      'core:site': { mode: 'fixed', kind: 'name' },
      'sirsoft-board:posts': { mode: 'inherit' },
    }),
  });

  const out = runBoardSeed([`--tag=${seedTag}`, '--posts=1', '--per-page=20', '--json']);
  board = JSON.parse(out.trim().split(/\r?\n/).pop() ?? '{}') as BoardSeed;
  expect(board.board_slug, '시드 게시판이 만들어진다').toBeTruthy();

  member = await createE2eMember(api, adminToken, { tag, role: 'author', name: realName, nickname });
  const { token } = await loginMemberApi(api, member.email, member.password);
  const created = await api.post(`/api/modules/sirsoft-board/boards/${board.board_slug}/posts`, {
    headers: { ...bearer(token), 'Content-Type': 'application/json' },
    data: { title: postTitle, content: `${postTitle} 본문`, content_mode: 'text' },
  });
  const body = await created.json();
  expect(created.status(), JSON.stringify(body?.errors ?? body?.message ?? '').slice(0, 400)).toBeLessThan(300);
  postId = Number(body?.data?.id);
  expect(postId).toBeGreaterThan(0);
});

test.afterAll(async () => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  const errors: unknown[] = [];
  for (const step of [
    async () => {
      if (memberSnapshot) await writeSettingsTab(api, adminToken, 'member', memberSnapshot);
    },
    async () => {
      if (board) runBoardSeed([`--tag=${seedTag}`, '--teardown']);
    },
    () => deleteE2eMember(api, adminToken, member?.uuid ?? ''),
  ]) {
    try {
      await step();
    } catch (error) {
      errors.push(error);
    }
  }
  await api?.dispose();
  if (errors.length > 0) {
    throw errors[0];
  }
});

/**
 * 비로그인으로 게시판 목록을 열고 글 목록 응답을 받는다.
 *
 * @param page 비로그인 페이지
 * @returns 글 목록 응답 본문
 */
async function openBoardList(page: Page): Promise<any> {
  const list = page.waitForResponse(
    (r) => new URL(r.url()).pathname === `/api/modules/sirsoft-board/boards/${board!.board_slug}/posts` && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  await page.goto(`/board/${board!.board_slug}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  const body = await (await list).json();
  await dismissCookieBanner(page);
  await expect(page.getByText(postTitle).first()).toBeVisible({ timeout: 30_000 });

  return body;
}

/**
 * @scenario case=site_name
 * @effects board_author_shows_real_name_when_site_default_is_name
 */
test('④ 사이트 기본이 「이름」이면 게시판 목록과 글 보기의 작성자는 실명이다', async ({ page }) => {
  await openBoardList(page);
  await expect(page.getByText(realName, { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText(nickname, { exact: true })).toHaveCount(0);

  await page.goto(`/board/${board!.board_slug}/${postId}`);
  await expect(page.getByText(realName, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
});

/**
 * @scenario case=site_nickname
 * @effects board_author_follows_site_default_nickname
 */
test('④ 사이트 기본을 닉네임으로 고정하면 같은 글의 작성자가 닉네임으로 바뀐다', async ({ page }) => {
  const current = await readSettingsTab(api, adminToken, 'member');
  await writeSettingsTab(api, adminToken, 'member', {
    ...current,
    display_name_areas: withDisplayNameAreas(current, { 'core:site': { mode: 'fixed', kind: 'nickname' } }),
  });

  await openBoardList(page);
  await expect(page.getByText(nickname, { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText(realName, { exact: true })).toHaveCount(0);

  await page.goto(`/board/${board!.board_slug}/${postId}`);
  await expect(page.getByText(nickname, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(realName, { exact: true })).toHaveCount(0);
});
