// e2e:allow 번역 폴백 문구 정합 — 번역 사전을 못 읽었을 때만 보이는 문구라 브라우저 E2E 로 재현할 수 없다. 대체 검증: 이 Vitest(원문 대조) + 주입기 관련 Vitest 57 passed(이커머스 전수 실측 S4 마감 검증 ②)
/**
 * @file injectorEnglishFallback.test.ts
 * @description NHN KCP 주문 상세·주문 완료 화면 스크립트의 번역 폴백 문구가 영어인지 (이커머스 전수 실측 S4 마감 검증)
 *
 * 규정(docs/frontend/actions-handlers.md 「영어 폴백 메시지 제공」): 번역 사전을 못 읽었을 때 보이는 폴백은 영어로 둔다.
 *   두 스크립트는 `t('user.vbank_notice', '입금 기한 내에 …')` 처럼 폴백이 한국어라, 사전이 늦거나 빠진 영어·일본어
 *   화면에 한국어가 그대로 보였다. 폴백을 플러그인 영어 사전(resources/lang/en.json)의 같은 키 값으로 맞춘다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import enDictionary from '../../lang/en.json';

const FILES = ['mypageOrderShowInjector.ts', 'orderCompleteReceiptInjector.ts'];

/** `t('키', '폴백' …)` 호출을 모두 뽑는다 */
function translateCalls(file: string): Array<{ key: string; fallback: string }> {
  const source = readFileSync(resolve(__dirname, '..', file), 'utf8');
  return [...source.matchAll(/\bt\(\s*'([^']+)'\s*,\s*'([^']*)'/g)].map((match) => ({ key: match[1], fallback: match[2] }));
}

/** 점 경로로 영어 사전 값을 찾는다 */
function english(key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), enDictionary);
}

describe('KCP 화면 스크립트 번역 폴백은 영어 사전 값이다', () => {
  /**
   * @scenario case=injector_fallbacks
   * @effects injector_fallback_is_english
   */
  it.each(FILES)('%s 의 t() 폴백이 en.json 같은 키 값과 같다', (file) => {
    const calls = translateCalls(file);
    expect(calls.length).toBeGreaterThan(0);
    for (const { key, fallback } of calls) {
      expect(english(key), `en.json 에 ${key} 가 없다`).toBeTypeOf('string');
      expect(fallback, key).toBe(english(key));
    }
  });
});
