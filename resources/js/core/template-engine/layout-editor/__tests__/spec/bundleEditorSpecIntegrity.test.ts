/**
 * bundleEditorSpecIntegrity.test.ts — 번들 editor-spec.json 라벨 무결성 회귀 가드
 *
 *
 * 회귀 배경: sirsoft-basic editor-spec.json 작성 중 일괄 치환 스크립트의 replacement
 * 문자열 이스케이프 오류로 모든 `$t:editor.*` 라벨이 `$t$t:editor.*` 로 이중 접두돼,
 * 속성 편집 모달의 컨트롤/컴포넌트 라벨이 raw key 로 노출됐다(브라우저 검증으로 발견).
 *
 * 본 테스트는 모든 번들 editor-spec.json 의 모든 문자열 값이 다음을 만족하는지 가드:
 *  - `$t$t:` 이중 접두가 없다 (치환 오류 회귀 차단)
 *  - `$t:` 로 시작하는 라벨은 정확히 한 번만 접두된다
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SPEC_PATHS = [
  'templates/_bundled/sirsoft-basic/editor-spec.json',
  'templates/_bundled/sirsoft-admin_basic/editor-spec.json',
  'modules/_bundled/sirsoft-ecommerce/editor-spec.json',
  'modules/_bundled/sirsoft-board/editor-spec.json',
  'modules/_bundled/sirsoft-page/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nicepayments/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nhnkcp/editor-spec.json',
  'plugins/_bundled/sirsoft-verification_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-gdpr/editor-spec.json',
];

/** 레포 루트 — vitest cwd 기준 */
const ROOT = process.cwd();

function loadSpec(rel: string): unknown {
  return JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
}

/** 객체 트리의 모든 문자열 값을 (경로, 값) 으로 수집 */
function collectStrings(obj: unknown, path = '', out: Array<{ path: string; value: string }> = []): Array<{ path: string; value: string }> {
  if (typeof obj === 'string') {
    out.push({ path, value: obj });
  } else if (Array.isArray(obj)) {
    obj.forEach((v, i) => collectStrings(v, `${path}[${i}]`, out));
  } else if (obj && typeof obj === 'object') {
    for (const [k, v] of Object.entries(obj)) collectStrings(v, path ? `${path}.${k}` : k, out);
  }
  return out;
}

describe.each(SPEC_PATHS)('번들 editor-spec 라벨 무결성: %s', (specPath) => {
  const spec = loadSpec(specPath);
  const strings = collectStrings(spec);

  it('`$t$t:` 이중 접두 라벨이 없다 (치환 오류 회귀 가드)', () => {
    const doubled = strings.filter((s) => s.value.includes('$t$t:'));
    expect(doubled).toEqual([]);
  });

  it('`$t:` 로 시작하는 라벨은 연속 `$t:$t:` 중첩 접두가 없다', () => {
    const malformed = strings.filter((s) => s.value.startsWith('$t:') && s.value.slice(3).startsWith('$t:'));
    expect(malformed.map((s) => `${s.path}=${s.value}`)).toEqual([]);
  });
});

describe('번들 editor-spec JSON 유효성', () => {
  it.each(SPEC_PATHS)('%s 는 유효한 JSON 이다', (specPath) => {
    expect(() => loadSpec(specPath)).not.toThrow();
  });
});
