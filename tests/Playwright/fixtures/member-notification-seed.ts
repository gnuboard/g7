/**
 * 회원 알림함 시드 fixture (#524 WP-F).
 *
 * `playwright:seed-member-notification` 을 불러 회원 하나에 **읽음 1 · 안 읽음 2** 를 심는다.
 * 읽음 필터와 제목/본문 검색을 한 화면에서 보려면 두 상태가 섞여 있어야 하고, 제목 낱말과
 * 본문 낱말이 갈라져 있어야 한다 — 겹치면 어느 갈래가 걸린 것인지 구분할 수 없다.
 *
 * 마친 뒤 `--teardown` 으로 되돌린다. 개발 DB 에 E2E 회원이 쌓이면 회원 목록·알림함의 건수가
 * 조용히 달라져 다른 화면의 실측을 흔든다.
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { issueToken, issueScopedToken } from './auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 커맨드가 돌려주는 식별자 묶음. */
export type MemberNotificationSeed = {
  member_uuid: string;
  member_email: string;
  notification_ids: string[];
  unread_count: number;
  total_count: number;
  subject_keyword: string;
  body_keyword: string;
};

type NotificationFixtures = {
  /** 시드된 회원 알림함 데이터 */
  notificationSeed: MemberNotificationSeed;
  /** 알림함 조회 + 삭제 권한 토큰 */
  notificationManageToken: string;
  /** 조회만 가진 토큰 — 삭제 버튼이 잠기는지 보는 대조군 */
  notificationReadOnlyToken: string;
};

/**
 * 시드 커맨드를 실행하고 JSON 결과를 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 파싱된 결과 객체
 */
function runSeed(args: string): Record<string, unknown> {
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../');
  const out = execSync(`php artisan playwright:seed-member-notification ${args}`, {
    cwd: coreRoot,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

export const test = base.extend<NotificationFixtures>({
  notificationSeed: async ({}, use) => {
    const seed = runSeed('--json') as MemberNotificationSeed;
    await use(seed);
    runSeed('--teardown --json');
  },
  notificationManageToken: async ({}, use) => {
    await use(issueToken('core.member-notifications.read', 'core.member-notifications.delete'));
  },
  notificationReadOnlyToken: async ({}, use) => {
    // admin 역할은 전 권한을 갖는다 — 「삭제 권한 없음」 을 재현하려면 역할 없이 발급해야 한다.
    await use(issueScopedToken('core.member-notifications.read'));
  },
});

export { expect } from '@playwright/test';
export { authenticatePage } from './auth';
