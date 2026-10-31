/**
 * sampleDataHasNoTestFixtures.test.ts — 편집기 표본에 개발 DB 의 테스트 데이터가 섞이지 않았는지 정적 가드
 *
 * 편집기 표본(sampleData·states)은 실제 응답을 떠서 만드는 일이 많다. 그때 개발 DB 에 남아 있던 E2E 실행 잔여물이
 * 그대로 들어오면 편집기 캔버스가 운영 화면에 없을 행을 그린다. 오류는 나지 않는다.
 *
 *  - 7.1.0 실측: 관리자 템플릿의 역할 표본 359행 중 351행이 Playwright 실행이 남긴 `playwright_test_*` 역할이었고,
 *    회원 표본 15명도 모두 그 역할을 달고 있었다. 그 역할의 이름은 언어별 맵이 문자열로 직렬화된 값
 *    (`"{\"ko\":\"Playwright 테스트 관리자\",…}"`)이라 역할 배지에 JSON 원문이 그대로 그려졌다.
 *
 * 판정 1: 문자열 값이 테스트 실행 잔여물의 표지(`playwright_test_` 식별자, 「Playwright 테스트」·「E2E automation」 문구)를 담지 않는다.
 * 판정 2: 문자열 값이 언어별 맵을 직렬화한 JSON 원문(`{"ko": …}`)이 아니다 — 맵은 객체로 둔다.
 *
 * 모집단은 저장소에서 파생한다(번들 확장의 `editor-spec.json` 과 `editor-spec/*.json`).
 *
 * @since engine-v1.76.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

/** 테스트 실행 잔여물 표지 */
const TEST_FIXTURE = /playwright_test_|Playwright 테스트|E2E automation/i;
/** 언어별 맵을 직렬화한 JSON 원문 */
const SERIALIZED_LOCALE_MAP = /^\s*\{\s*"(?:ko|en|ja)"\s*:/;

type Json = any;

/**
 * 번들 확장의 편집기 표본 파일을 모은다.
 *
 * @returns 저장소 기준 상대경로와 파싱 결과 목록
 */
function collectSpecFiles(): Array<{ file: string; json: Json }> {
  const out: Array<{ file: string; json: Json }> = [];
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const extRoot = resolve(baseDir, ext);
      if (!statSync(extRoot).isDirectory()) continue;
      const candidates = [resolve(extRoot, 'editor-spec.json')];
      const specDir = resolve(extRoot, 'editor-spec');
      if (existsSync(specDir)) candidates.push(...readdirSync(specDir).filter((f) => f.endsWith('.json')).map((f) => join(specDir, f)));
      for (const abs of candidates) {
        if (!existsSync(abs)) continue;
        try {
          out.push({ file: relative(ROOT, abs).split('\\').join('/'), json: JSON.parse(readFileSync(abs, 'utf8')) });
        } catch {
          // 깨진 JSON 은 다른 가드가 잡는다
        }
      }
    }
  }

  return out;
}

/**
 * 판정 1·2 위반을 모은다.
 *
 * @param label 위치 표지
 * @param json 표본 트리
 * @returns 위반 목록(`label 경로=값`)
 */
export function findPollutedSampleStrings(label: string, json: Json): string[] {
  const offenders: string[] = [];
  const visit = (node: Json, trail: string): void => {
    if (Array.isArray(node)) {
      node.forEach((child, i) => visit(child, `${trail}[${i}]`));
      return;
    }
    if (node && typeof node === 'object') {
      for (const [key, value] of Object.entries(node)) visit(value, `${trail}.${key}`);
      return;
    }
    if (typeof node === 'string' && (TEST_FIXTURE.test(node) || SERIALIZED_LOCALE_MAP.test(node))) {
      offenders.push(`${label} ${trail}=${node.slice(0, 40)}`);
    }
  };
  visit(json, '');

  return offenders;
}

const SPECS = collectSpecFiles();

describe('편집기 표본 — 테스트 데이터 혼입', () => {
  /**
   * @effects sample_pollution_guard_population_is_derived_from_repository
   */
  it('번들 확장의 편집기 표본 파일을 저장소에서 파생한다', () => {
    expect(SPECS.length).toBeGreaterThanOrEqual(30);
    expect(SPECS.some((s) => s.file.endsWith('sirsoft-admin_basic/editor-spec/sampleData.json'))).toBe(true);
  });

  /**
   * @effects sample_test_fixture_pollution_is_detected
   */
  it('판정이 테스트 역할 식별자와 직렬화된 언어별 맵 이름을 잡고, 객체 맵과 평범한 문구는 통과시킨다', () => {
    const serializedName = '{"ko":"Playwright 테스트 관리자","en":"Playwright Test Admin"}';
    const fixture = {
      roles: [
        { identifier: 'playwright_test_0123abcd', name: serializedName },
        { identifier: 'manager', name: { ko: '매니저', en: 'Manager' } },
        { identifier: 'user', name: '일반 사용자', description: '{코드} 자리를 쓰는 안내' },
      ],
    };
    expect(findPollutedSampleStrings('fixture', fixture)).toEqual([
      'fixture .roles[0].identifier=playwright_test_0123abcd',
      `fixture .roles[0].name=${serializedName.slice(0, 40)}`,
    ]);
  });

  /**
   * @effects editor_samples_carry_no_test_fixtures_or_serialized_maps
   */
  it('편집기 표본에는 테스트 실행 잔여물과 직렬화된 언어별 맵 문자열이 없다', () => {
    expect(SPECS.flatMap((s) => findPollutedSampleStrings(s.file, s.json))).toEqual([]);
  });
});
