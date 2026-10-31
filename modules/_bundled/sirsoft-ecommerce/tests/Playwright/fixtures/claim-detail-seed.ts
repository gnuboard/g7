/**
 * 관리자 클레임 상세 E2E 신청서 시드 fixture (#757).
 *
 * `playwright:seed-claim-detail` 을 불러 상세 화면 시험이 끝까지 잴 신청서 셋을 세운다 — 옵션 변경 교환(수량 2),
 * 상품 1건짜리 승인 반품(송장 없음), 계좌가 빈 승인 환불신청서(입금된 가상계좌). 라이브 DB 에 그런 건이 우연히
 * 있을 때만 도는 시험은 「건너뜀」이 통과로 세어지므로, 시험이 전제를 스스로 세운다.
 *
 * **워커 단위 fixture 다.** 이 모듈 설정은 `fullyParallel` 이라 같은 spec 의 시험이 여러 워커에서 돈다. 워커마다
 * 다른 tag 로 시드해야 한 워커의 재시드·정리가 다른 워커의 신청서를 지우지 않는다. 워커가 끝날 때
 * `--teardown --tag` 로 그 워커 몫만 지운다.
 *
 * 시드 신청서 번호에는 `claim_mark`(`E2ECLM`)가 들어 있다 — 운영 데이터에서 신청서를 찾는 시험은 그 표식이 든
 * 번호를 건너뛰어, 다른 워커가 정리 중인 시드 건을 집지 않는다.
 */
import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test as authTest } from './ecommerce-auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 커맨드가 돌려주는 식별자 묶음. */
export type ClaimDetailSeed = {
  tag: string;
  claim_mark: string;
  exchange_number: string;
  exchange_order_number: string;
  return_number: string;
  return_order_number: string;
  refund_number: string;
  refund_order_number: string;
};

/** 시드 신청서 번호의 표식 — 커맨드의 `CLAIM_MARK` 와 같은 문자열 */
export const CLAIM_SEED_MARK = 'E2ECLM';

/**
 * 시드 커맨드를 실행하고 JSON 결과를 돌려준다.
 *
 * @param args 커맨드 인자
 * @returns 파싱된 결과 객체
 */
function runSeed(args: string): Record<string, unknown> {
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
  const out = execSync(`php artisan playwright:seed-claim-detail ${args}`, {
    cwd: coreRoot,
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
  const jsonLine = out.trim().split(/\r?\n/).filter((l) => l.trim().startsWith('{')).pop() ?? '{}';

  return JSON.parse(jsonLine);
}

type ClaimDetailWorkerFixtures = {
  /** 이 워커가 시드한 신청서 번호 묶음 */
  claimDetailSeed: ClaimDetailSeed;
};

export const test = authTest.extend<object, ClaimDetailWorkerFixtures>({
  claimDetailSeed: [
    async ({}, use, workerInfo) => {
      // 영숫자 16자 이내 — 워커 번호 + 시각(36진) 으로 같은 실행·다음 실행의 워커와도 겹치지 않게 한다.
      const tag = `w${workerInfo.workerIndex}${Date.now().toString(36)}`.slice(0, 16);
      const seed = runSeed(`--tag=${tag} --json`) as ClaimDetailSeed;
      try {
        await use(seed);
      } finally {
        runSeed(`--tag=${tag} --teardown --json`);
      }
    },
    { scope: 'worker' },
  ],
});

export { expect, authenticatePage } from './ecommerce-auth';
