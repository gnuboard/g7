/**
 * 미리보기 매체 격리 — `vw` 단위 치환 (이슈 #141 D7)
 *
 * `vw` 는 언제나 브라우저 창 폭 기준이다. 미리보기 안에서는 프레임 폭/100 을 담은
 * CSS 변수(`--g7le-vw`)로 바꾼다. `cqw` 는 이름과 무관하게 가장 가까운 컨테이너 기준이라
 * Tailwind `@container` 유틸리티가 중첩되면 틀어지므로 쓰지 않는다.
 */

import { describe, it, expect } from 'vitest';
import {
  rewriteViewportWidthUnits,
  hasViewportWidthUnits,
} from '../../../utils/previewMediaIsolation/vwRewrite';

describe('rewriteViewportWidthUnits', () => {
  /**
   * @scenario unit=vw|svw|dvw, sign=positive|negative|fraction
   * @effects preview_media_isolation_vw_follows_frame_width
   */
  it.each([
    ['max-width: calc(100vw - 2rem);', 'max-width: calc(calc(100 * var(--g7le-vw)) - 2rem);'],
    ['max-width: 85vw;', 'max-width: calc(85 * var(--g7le-vw));'],
    ['margin-left: -5vw;', 'margin-left: calc(-5 * var(--g7le-vw));'],
    ['width: .5vw;', 'width: calc(.5 * var(--g7le-vw));'],
    ['width: 50svw;', 'width: calc(50 * var(--g7le-vw));'],
    ['width: 50dvw;', 'width: calc(50 * var(--g7le-vw));'],
    ['width: 50lvw !important;', 'width: calc(50 * var(--g7le-vw)) !important;'],
  ])('%s', (input, expected) => {
    expect(rewriteViewportWidthUnits(input)).toBe(expected);
  });

  /**
   * @scenario unit=vh|vmin|string|url
   * @effects preview_media_isolation_non_width_untouched
   */
  it.each([
    'height: 100vh;',
    'width: 2vmin;',
    'width: 2vmax;',
    'content: "10vw";',
    "background: url(a100vw.png);",
    'background: url("b 10vw.png");',
  ])('%s 는 바꾸지 않는다', (input) => {
    expect(rewriteViewportWidthUnits(input)).toBe(input);
    expect(hasViewportWidthUnits(input)).toBe(false);
  });

  /**
   * @scenario unit=vw
   * @effects preview_media_isolation_vw_follows_frame_width
   */
  it('hasViewportWidthUnits 는 vw 사용을 감지한다', () => {
    expect(hasViewportWidthUnits('max-width: calc(100vw - 2rem)')).toBe(true);
  });
});
