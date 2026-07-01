/**
 * 이커머스 모듈 도메인 시드 fixture (sample skeleton).
 *
 * `playwright:seed-ecommerce` artisan 커맨드를 호출하여 도메인 데이터를 발급받는다.
 * 본 stub 은 시드 커맨드가 LogicException 을 throw 하도록 구성되어 있어 실제 사용 전에는
 * test.skip 로 비활성화되어야 한다.
 *
 * 활성화 절차 (이커머스 모듈 작업 세션):
 *   1. PlaywrightSeedEcommerce 의 handle() 본문을 LogicException 에서 실제 시드 로직으로 교체
 *   2. 본 fixture 의 test.skip 표기 제거 (spec 파일에서 활성화)
 *   3. teardown 시 cleanup 커맨드 (예: playwright:cleanup-ecommerce) 호출 추가 권장
 */
import { test as base } from '@playwright/test';
import { execSync } from 'node:child_process';
import { resolve } from 'node:path';

type SeedFixtures = {
  /** 시드된 이커머스 도메인 데이터 (상품/카테고리/주문 ID 배열) */
  seededEcommerce: { productIds: number[]; categoryIds: number[]; orderIds: number[] };
};

export const test = base.extend<SeedFixtures>({
  seededEcommerce: async ({}, use) => {
    const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');
    const out = execSync(
      'php artisan playwright:seed-ecommerce --products=5 --categories=3 --orders=2 --json',
      {
        cwd: coreRoot,
        encoding: 'utf-8',
        env: {
          ...process.env,
          G7_PLAYWRIGHT_BYPASS: '1',
        },
      }
    );
    const seed = JSON.parse(out);
    await use(seed);
    // TODO: teardown 시 cleanup 커맨드 호출
  },
});

export { expect } from '@playwright/test';
