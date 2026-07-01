/**
 * stateLabelKeyResolution.test.ts — states 라벨 `$t:` 키가 실제 lang 파일에 존재하는지 회귀 가드
 *
 *
 * 회귀 배경: states 그룹의 `label`/`formErrors` 값은 `$t:editor.state.*` 키로 선언되며,
 * 이 키는 활성 템플릿의 `lang/partial/{ko,en}/editor.json` 에서 해석된다(코어가 모듈/템플릿
 * states 를 concat 하므로 모듈 states 라벨도 템플릿 lang 으로 해석됨). 키가 lang 에 없으면
 * 캔버스 툴바(PageStateSwitcher)에 raw key (`editor.state.xxx`) 가 그대로 노출된다.
 *
 * 본 테스트는 모든 번들 states 스펙의 `$t:` 키가 사용자/관리자 템플릿 lang 의 합집합에
 * 실재함을 ko·en 양쪽에서 가드한다. 사용자 라우트 states 는 sirsoft-basic, 관리자 라우트
 * states 는 sirsoft-admin_basic 으로 해석되므로 두 lang 의 합집합으로 검사한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();

/** states 가 선언된 모든 번들 스펙 파일 (템플릿은 분할 states.json, 모듈은 monolithic). */
const STATE_SPEC_PATHS = [
  'templates/_bundled/sirsoft-basic/editor-spec/states.json',
  'templates/_bundled/sirsoft-admin_basic/editor-spec/states.json',
  'modules/_bundled/sirsoft-ecommerce/editor-spec.json',
  'modules/_bundled/sirsoft-board/editor-spec.json',
  'modules/_bundled/sirsoft-page/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nicepayments/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-pay_nhnkcp/editor-spec.json',
  'plugins/_bundled/sirsoft-verification_kginicis/editor-spec.json',
  'plugins/_bundled/sirsoft-gdpr/editor-spec.json',
];

/** `$t:editor.*` 키를 해석하는 lang 파일들 (사용자 + 관리자 템플릿). */
const LANG_PATHS = [
  'templates/_bundled/sirsoft-basic/lang/partial/ko/editor.json',
  'templates/_bundled/sirsoft-basic/lang/partial/en/editor.json',
  'templates/_bundled/sirsoft-admin_basic/lang/partial/ko/editor.json',
  'templates/_bundled/sirsoft-admin_basic/lang/partial/en/editor.json',
];

function load(rel: string): unknown {
  return JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
}

/** 객체를 `editor.a.b.c` 형태의 평탄 키 집합으로 펼친다(배열은 리프 취급). */
function flattenKeys(obj: unknown, prefix: string, out: Set<string>): Set<string> {
  if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
    for (const [k, v] of Object.entries(obj)) {
      const next = prefix ? `${prefix}.${k}` : k;
      if (v && typeof v === 'object' && !Array.isArray(v)) {
        flattenKeys(v, next, out);
      } else {
        out.add(next);
      }
    }
  }
  return out;
}

/**
 * states 그룹 트리에서 **토글 라벨/설명** 의 `$t:` 키만 수집한다(접두 제거).
 *
 * `label`/`description` 은 캔버스 툴바(PageStateSwitcher)가 편집기 chrome 의 `editor.*`
 * 사전으로 해석하는 값이므로 editor lang 에 실재해야 한다. 반면 `formErrors` 의 메시지
 * 값은 **캔버스가 콘텐츠 사전으로 해석**(`$t:auth.*` 등)하거나 평문이므로 editor lang
 * 검사 대상이 아니다(별도: resolveFormErrorMessages 단위 + Chrome 캔버스 raw 키 0 검증).
 */
function collectLabelKeys(groups: Array<{ items?: Array<Record<string, unknown>> }>, out: Set<string>): Set<string> {
  for (const g of groups ?? []) {
    for (const item of g.items ?? []) {
      for (const field of ['label', 'description'] as const) {
        const v = item[field];
        if (typeof v === 'string' && v.startsWith('$t:')) out.add(v.slice(3));
      }
    }
  }
  return out;
}

/** lang 파일별 합집합 키 — 각 lang 은 그 자체로 `editor.*` 네임스페이스를 해석한다. */
const langKeySets = LANG_PATHS.map((p) => flattenKeys(load(p), 'editor', new Set<string>()));

describe.each(STATE_SPEC_PATHS)('states 라벨 키 해석: %s', (specPath) => {
  const spec = load(specPath) as { states?: { groups?: unknown }; groups?: unknown };
  // 템플릿 분할 파일은 { groups: [...] }, 모듈은 { states: { groups: [...] } }.
  const groups = ((spec.states?.groups ?? spec.groups) ?? []) as Array<{ items?: Array<Record<string, unknown>> }>;
  const usedKeys = collectLabelKeys(groups, new Set<string>());

  it('모든 `$t:` 라벨 키가 ko 템플릿 lang 합집합에 존재한다', () => {
    const koUnion = new Set<string>([...langKeySets[0], ...langKeySets[2]]);
    const missing = [...usedKeys].filter((k) => !koUnion.has(k));
    expect(missing).toEqual([]);
  });

  it('모든 `$t:` 라벨 키가 en 템플릿 lang 합집합에 존재한다', () => {
    const enUnion = new Set<string>([...langKeySets[1], ...langKeySets[3]]);
    const missing = [...usedKeys].filter((k) => !enUnion.has(k));
    expect(missing).toEqual([]);
  });
});
