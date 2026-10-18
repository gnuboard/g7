/**
 * previewDollarGlobals.test.ts — 미리보기 렌더 맥락의 `$` 전역(`$locale` = 콘텐츠 언어)
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { previewDollarGlobals } from '../../sample-data/dollarGlobals';
/**
 * 7.1.0 실측(영역 I2): 콘텐츠 언어를 English 로 바꿔도 식(`{{조건 ? '$t:a' : '$t:b'}}`) 결과 문구만 한국어로 남았다
 * (회원 삭제 창 [삭제], 템플릿 상세 「현재 활성」, 회원 목록 상태). 표본 전역의 `$locale: "ko"` 가 렌더 맥락 최상위로
 * 올라가, 편집 모드의 `$locale` 자동 주입(콘텐츠 언어 — 값이 없을 때만)이 막혔다. 미리보기의 `$locale` 은 콘텐츠 언어다.
 */
describe('previewDollarGlobals — 미리보기 렌더 맥락의 `$` 전역', () => {
  // @scenario source=fixture
  // @effects preview_locale_follows_content_language
  it('표본의 `$locale` 을 콘텐츠 언어로 덮는다(목록 등 나머지 `$` 전역은 유지)', () => {
    const out = previewDollarGlobals({ $locale: 'ko', $locales: ['ko', 'en', 'ja'], currentUser: { uuid: 'x' } }, 'en');
    expect(out).toEqual({ $locale: 'en', $locales: ['ko', 'en', 'ja'] });
  });

  // @scenario source=fixture
  // @effects preview_locale_keeps_seed_without_content_language
  it('콘텐츠 언어를 모르면 표본 값을 그대로 둔다', () => {
    expect(previewDollarGlobals({ $locale: 'ko' }, undefined)).toEqual({ $locale: 'ko' });
    expect(previewDollarGlobals({ $locales: ['ko'] }, 'ja')).toEqual({ $locales: ['ko'], $locale: 'ja' });
  });
});
