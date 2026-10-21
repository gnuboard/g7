/**
 * 운영자 데이터 색 → 대비 만족 화면 색 파생 도우미 시험
 *
 * 라벨 색(초록·노랑·주황 500 등)을 글자에 그대로 쓰면 연한 바탕에서 2 안팎으로 읽히지 않던 결함의 재발 방지.
 */
import { describe, it, expect } from 'vitest';
import { blend, contrastRatio, parseHexColor, softChipColors, solidChipColors } from '../accessibleColor';

const PAGE_LIGHT = { r: 255, g: 255, b: 255 };
const PAGE_DARK = { r: 30, g: 41, b: 57 };
// 샘플 라벨 기본 색(시더) + 운영자가 고르기 쉬운 밝은 색
const SAMPLES = ['#22C55E', '#F59E0B', '#EF4444', '#F97316', '#8B5CF6', '#3B82F6', '#EC4899', '#14B8A6', '#6366F1', '#84CC16', '#FACC15', '#FFFFFF', '#000000', '#6B7280', '#fff'];

describe('accessibleColor', () => {
  /**
   * @scenario component=chip_checkbox
   * @effects chip_custom_color_text_meets_contrast
   */
  it('색 코드를 읽는다(#rgb·#rrggbb), 못 읽으면 null', () => {
    expect(parseHexColor('#22C55E')).toEqual({ r: 34, g: 197, b: 94 });
    expect(parseHexColor('fff')).toEqual({ r: 255, g: 255, b: 255 });
    expect(parseHexColor('red')).toBeNull();
    expect(parseHexColor(null)).toBeNull();
  });

  /**
   * @scenario component=chip_checkbox
   * @effects chip_custom_color_text_meets_contrast
   */
  it.each(SAMPLES)('연한 칩 %s — 글자가 바탕·마우스 올림 바탕과 두 모드 모두 4.5 이상', (hex) => {
    const rgb = parseHexColor(hex)!;
    for (const dark of [false, true]) {
      const soft = softChipColors(hex, dark)!;
      const fg = parseHexColor(soft.color)!;
      const page = dark ? PAGE_DARK : PAGE_LIGHT;
      for (const a of dark ? [0.15, 0.25] : [0.06, 0.12]) {
        expect(contrastRatio(fg, blend(rgb, a, page))).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  /**
   * @scenario component=chip_checkbox
   * @effects chip_custom_color_text_meets_contrast
   */
  it.each(SAMPLES)('꽉 찬 칩 %s — 글자가 바탕·마우스 올림 바탕과 4.5 이상', (hex) => {
    const solid = solidChipColors(hex)!;
    const fg = parseHexColor(solid.color)!;
    expect(contrastRatio(fg, parseHexColor(solid.background)!)).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(fg, parseHexColor(solid.hoverBackground)!)).toBeGreaterThanOrEqual(4.5);
  });

  /**
   * @scenario component=chip_checkbox
   * @effects chip_custom_color_text_meets_contrast
   */
  it('초록 500(신상품 기본 색)은 연한 칩에서 같은 계열의 짙은 초록 글자가 된다', () => {
    const soft = softChipColors('#22C55E', false)!;
    const fg = parseHexColor(soft.color)!;
    expect(fg.g).toBeGreaterThan(fg.r);
    expect(fg.g).toBeGreaterThan(fg.b);
  });
});
