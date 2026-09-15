/**
 * 이커머스 회원 데이터 시드 fixture (#524 WP-E · WP-C2).
 *
 * `playwright:seed-ecommerce-member-data` 를 불러 회원 하나에 네 갈래(찜·장바구니·배송지·문의)를
 * 심고, 상품을 «정상 · 숨김 · 판매중지» 세 상태로 갈라 둔다. 찜 목록에서 숨김 상품만 빠지는지는
 * 세 상태가 함께 있어야 말할 수 있다 — 숨김만 있으면 "목록이 비었다" 와 구분되지 않는다.
 *
 * 마친 뒤 `--teardown` 으로 되돌린다.
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { issueToken } from '../../../../../../tests/Playwright/fixtures/auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 커맨드가 돌려주는 식별자 묶음. */
export type MemberDataSeed = {
  member_uuid: string;
  member_email: string;
  product_visible_id: number;
  product_hidden_id: number;
  product_suspended_id: number;
  guest_cart_key: string;
  inquiry_id: number;
};

type MemberDataFixtures = {
  /** 시드된 회원 이커머스 데이터 */
  memberDataSeed: MemberDataSeed;
  /** 찜 목록 조회 + 삭제 권한 토큰 */
  wishlistManageToken: string;
};

/**
 * 시드 커맨드를 실행하고 JSON 결과를 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 파싱된 결과 객체
 */
function runSeed(args: string): Record<string, unknown> {
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
  const out = execSync(`php artisan playwright:seed-ecommerce-member-data ${args}`, {
    cwd: coreRoot,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

export const test = base.extend<MemberDataFixtures>({
  memberDataSeed: async ({}, use) => {
    const seed = runSeed('--json') as MemberDataSeed;
    await use(seed);
    runSeed('--teardown --json');
  },
  wishlistManageToken: async ({}, use) => {
    await use(issueToken('sirsoft-ecommerce.wishlists.read', 'sirsoft-ecommerce.wishlists.manage'));
  },
});

export { expect } from '@playwright/test';
export { authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';
