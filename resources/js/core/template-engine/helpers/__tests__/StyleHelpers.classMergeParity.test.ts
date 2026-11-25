/**
 * 공용 픽스처 패리티 — 엔진 mergeClasses·applyResponsiveClassMerge (engine-v1.98.0)
 *
 * `tests/Fixtures/class-merge/cases.json` 은 SEO 렌더러 `App\Support\Style\ClassMerger` 시험
 * (`tests/Unit/Support/Style/ClassMergerTest.php`)도 같은 사례로 돌린다. 두 구현이 같은 결과를 내야
 * 봇 화면과 브라우저 화면의 클래스가 같다.
 *
 * @scenario-manifest tests/scenarios/responsive-class-merge.yaml
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { mergeClasses, applyResponsiveClassMerge } from '../StyleHelpers';

interface FixtureCase {
  id: string;
  fn: 'both' | 'mergeClasses' | 'applyResponsive';
  base: string | null;
  override: string | null;
  expected: string;
}

const fixture = JSON.parse(
  readFileSync(resolve(__dirname, '../../../../../../tests/Fixtures/class-merge/cases.json'), 'utf8')
) as { prefixes: string[]; cases: FixtureCase[] };

describe('classMerge 공용 픽스처 패리티', () => {
  beforeEach(() => {
    (window as any).G7Config = { extensionClassPrefixes: fixture.prefixes };
  });
  afterEach(() => {
    delete (window as any).G7Config;
  });

  /**
   * @scenario surface=fixture
   * @effects seo_class_merger_matches_engine_fixture
   */
  it('픽스처에 사례가 있다', () => {
    expect(fixture.cases.length).toBeGreaterThan(20);
  });

  for (const c of fixture.cases) {
    if (c.fn === 'both' || c.fn === 'mergeClasses') {
      /**
       * @scenario surface=fixture
       * @effects seo_class_merger_matches_engine_fixture
       */
      it(`mergeClasses — ${c.id}`, () => {
        expect(mergeClasses(c.base ?? '', c.override ?? undefined)).toBe(c.expected);
      });
    }
    if (c.fn === 'both' || c.fn === 'applyResponsive') {
      /**
       * @scenario surface=fixture
       * @effects seo_class_merger_matches_engine_fixture
       */
      it(`applyResponsiveClassMerge — ${c.id}`, () => {
        expect(applyResponsiveClassMerge(c.base, c.override)).toBe(c.expected);
      });
    }
  }
});
