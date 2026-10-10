/**
 * 미리보기 매체 격리 — `@media` 조건식 분석·변환 (이슈 #141 D7)
 *
 * 폭 조건은 미리보기 프레임 컨테이너 조건(`@container g7le-preview …`)으로 옮기고, 폭이 아닌
 * 조건(hover·prefers-*·orientation)은 바깥 `@media` 로 남긴다. `@container` 의 rem/em 은
 * 루트·컨테이너 글꼴 크기 기준이라 `@media` 와 판정이 어긋나므로 px 로 바꾼다.
 * 선택자 이름(Tailwind `md:` 등)이 아니라 조건식만 보므로 CSS 제작 도구와 무관하다.
 */

import { describe, it, expect } from 'vitest';
import { analyzeMediaQueryList } from '../../../utils/previewMediaIsolation/mediaQuery';

const convertible = (text: string, remPx = 16) => {
  const result = analyzeMediaQueryList(text, remPx);
  expect(result.kind).toBe('convertible');
  return (result as Extract<typeof result, { kind: 'convertible' }>).entries;
};

describe('[case:wysiwyg-2] analyzeMediaQueryList — 변환 가능', () => {
  /**
   * @scenario media_condition=min_width_px
   * @effects preview_media_isolation_width_media_to_container
   */
  it.each([
    ['(min-width: 768px)', '(min-width: 768px)'],
    ['(min-width:48rem)', '(min-width: 768px)'],
    ['(max-width:640px)', '(max-width: 640px)'],
    ['screen and (min-width: 1024px)', '(min-width: 1024px)'],
    ['only screen and (max-width: 480px)', '(max-width: 480px)'],
    ['all and (min-width: 40rem)', '(min-width: 640px)'],
    ['(width >= 48rem)', '(width >= 768px)'],
    ['(48rem <= width < 64rem)', '(768px <= width < 1024px)'],
    ['(min-device-width: 768px)', '(min-width: 768px)'],
    ['(min-width: 30em)', '(min-width: 480px)'],
    ['(min-width: 0)', '(min-width: 0)'],
  ])('%s → %s', (input, expected) => {
    expect(convertible(input)).toEqual([{ containerCondition: expected, outerMedia: null }]);
  });

  /**
   * @scenario media_condition=rem, root_font_px=20
   * @effects preview_media_isolation_rem_uses_media_basis
   */
  it('remPx 20 이면 48rem → 960px', () => {
    expect(convertible('(width >= 48rem)', 20)).toEqual([{ containerCondition: '(width >= 960px)', outerMedia: null }]);
  });

  /**
   * @scenario media_condition=width_and_non_width
   * @effects preview_media_isolation_non_width_kept_outer
   */
  it('폭 + 비폭 조건 → 컨테이너 조건 + 바깥 media', () => {
    expect(convertible('(min-width: 40rem) and (hover: hover)')).toEqual([
      { containerCondition: '(min-width: 640px)', outerMedia: '(hover: hover)' },
    ]);
    expect(convertible('(orientation: landscape) and (min-width: 1024px)')).toEqual([
      { containerCondition: '(min-width: 1024px)', outerMedia: '(orientation: landscape)' },
    ]);
  });

  /**
   * @scenario media_condition=tailwind_v3_max
   * @effects preview_media_isolation_width_media_to_container
   */
  it('Tailwind v3 `not all and (min-width: X)` → `not (min-width: X)`', () => {
    expect(convertible('not all and (min-width: 768px)')).toEqual([
      { containerCondition: 'not (min-width: 768px)', outerMedia: null },
    ]);
  });

  /**
   * @scenario media_condition=list_with_print
   * @effects preview_media_isolation_width_media_to_container
   */
  it('`(min-width: 1px), print` → print 항목은 빠지고 1항목', () => {
    expect(convertible('(min-width: 1px), print')).toEqual([{ containerCondition: '(min-width: 1px)', outerMedia: null }]);
  });

  /**
   * @scenario media_condition=list_two_width
   * @effects preview_media_isolation_width_media_to_container
   */
  it('폭 항목 2개 → 항목 2개 (OR 의미 보존)', () => {
    expect(convertible('(max-width: 480px), (min-width: 1200px)')).toEqual([
      { containerCondition: '(max-width: 480px)', outerMedia: null },
      { containerCondition: '(min-width: 1200px)', outerMedia: null },
    ]);
  });
});

describe('analyzeMediaQueryList — 폭 조건 없음 / 변환 불가', () => {
  /**
   * @scenario media_condition=no_width
   * @effects preview_media_isolation_non_width_untouched
   */
  it.each(['(hover: hover)', 'print', '(prefers-reduced-motion)', '(prefers-reduced-motion: reduce)', 'screen', ''])(
    '%s → none',
    (input) => {
      expect(analyzeMediaQueryList(input, 16).kind).toBe('none');
    },
  );

  /**
   * @scenario media_condition=or|calc|vw|nested_mix
   * @effects preview_media_isolation_unsupported_counted
   */
  it.each([
    '(min-width: 1px) or (hover)',
    '(min-width: calc(1px + 2px))',
    '(min-width: 50vw)',
    '((min-width: 1px) and (hover: hover))',
    'not screen and (min-width: 768px)',
    '(min-width: 768px), (hover: hover)',
  ])('%s → unsupported', (input) => {
    expect(analyzeMediaQueryList(input, 16).kind).toBe('unsupported');
  });
});
