/**
 * s7-followup-style-controls.test.ts —.
 *
 * 추가 컨트롤(그림자/테두리 모양·색/모서리 라운드/투명도/스크롤/굵기·기울임·밑줄/줄바꿈/
 * 텍스트 정렬 justify)의 apply → reverseResolve 라운드트립과 group 토큰 교체를 검증한다.
 * 컨트롤 정의는 번들 editor-spec.json(SSoT)에서 직접 읽어 실제 적용 형식과 일치를 보장한다.
 */

import { describe, it, expect } from 'vitest';
import { resolve } from 'node:path';
import { applyRecipe, reverseResolve } from '../../spec/recipeEngine';
import { assembleEditorSpec } from './assembleEditorSpecFixture';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const SPEC_PATH = resolve(
  __dirname,
  '../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec.json',
);
// editor-spec 은 S7 에서 manifest + `$include` 블록으로 분할됨 — 합본 헬퍼로 단일 spec 복원.
const spec = assembleEditorSpec(SPEC_PATH) as {
  controls: Record<string, EditorControlSpec>;
  componentCapabilities: Record<string, { styleControls?: string[] }>;
};

const ctrl = (key: string): EditorControlSpec => {
  const c = spec.controls[key];
  if (!c) throw new Error(`control ${key} missing in editor-spec`);
  return c;
};

const className = (node: EditorNode): string =>
  ((node.props ?? {}) as Record<string, unknown>).className as string ?? '';

describe('신규 컨트롤 — editor-spec 존재', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_new_controls_declared
   */
  it.each([
    'boxShadow', 'borderStyle', 'borderColor', 'borderRadius', 'opacity',
    'overflow', 'fontBold', 'fontItalic', 'textUnderline', 'whitespace',
  ])('%s 컨트롤이 정의되어 있다', (key) => {
    expect(spec.controls[key]).toBeTruthy();
  });

  /**
   * @scenario surface=s7_style_controls
   * @effects style_new_controls_declared
   */
  it('textAlign 에 justify 옵션이 있다', () => {
    const opts = (ctrl('textAlign').options ?? []).map((o) => (o as { value?: unknown }).value);
    expect(opts).toContain('justify');
  });
});

describe('그림자(boxShadow) — select group:box-shadow', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_box_shadow_roundtrip
   */
  it('옵션 적용 후 역해석 라운드트립', () => {
    const c = ctrl('boxShadow');
    const applied = applyRecipe({ name: 'Card' }, c, 'shadow-lg');
    expect(className(applied)).toContain('shadow-lg');
    expect(reverseResolve(applied, c).value).toBe('shadow-lg');
  });
  /**
   * @scenario surface=s7_style_controls
   * @effects style_box_shadow_roundtrip
   */
  it('옵션 교체 시 이전 그림자 토큰 제거', () => {
    const c = ctrl('boxShadow');
    let n = applyRecipe({ name: 'Card' }, c, 'shadow-lg');
    n = applyRecipe(n, c, 'shadow-sm');
    expect(className(n)).toContain('shadow-sm');
    expect(className(n)).not.toContain('shadow-lg');
  });
});

describe('테두리 모양(borderStyle) — 폭+스타일 토큰 정리', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_border_style_cleans_width_tokens
   */
  it('solid → none 전환 시 border 폭 토큰까지 제거', () => {
    const c = ctrl('borderStyle');
    let n = applyRecipe({ name: 'Div' }, c, 'solid');
    expect(className(n).split(/\s+/)).toEqual(expect.arrayContaining(['border', 'border-solid']));
    n = applyRecipe(n, c, 'none');
    const tokens = className(n).split(/\s+/).filter(Boolean);
    expect(tokens).toContain('border-0');
    expect(tokens).not.toContain('border');
    expect(tokens).not.toContain('border-solid');
  });
});

describe('테두리 색(borderColor) — borderStyle 과 독립', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_border_color_independent_of_style
   */
  it('borderStyle 변경이 borderColor 토큰을 지우지 않는다', () => {
    const style = ctrl('borderStyle');
    const color = ctrl('borderColor');
    let n = applyRecipe({ name: 'Div' }, color, 'border-blue-600');
    n = applyRecipe(n, style, 'dashed');
    const tokens = className(n).split(/\s+/).filter(Boolean);
    expect(tokens).toContain('border-blue-600'); // 색 보존
    expect(tokens).toContain('border-dashed');
  });
  /**
   * @scenario surface=s7_style_controls
   * @effects style_border_color_independent_of_style
   */
  it('자유 HEX → border-[#hex] 합성 및 역추출', () => {
    const color = ctrl('borderColor');
    const n = applyRecipe({ name: 'Div' }, color, '#3a7bd5');
    expect(className(n)).toContain('border-[#3a7bd5]');
    expect(reverseResolve(n, color).value).toBe('#3a7bd5');
  });
});

describe('모서리 라운드(borderRadius)', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_border_radius_roundtrip
   */
  it('라운드트립', () => {
    const c = ctrl('borderRadius');
    const n = applyRecipe({ name: 'Card' }, c, 'rounded-lg');
    expect(className(n)).toContain('rounded-lg');
    expect(reverseResolve(n, c).value).toBe('rounded-lg');
  });
});

describe('투명도(opacity) — slider scale 토큰', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_opacity_roundtrip
   */
  it('적용/역해석/교체', () => {
    const c = ctrl('opacity');
    let n = applyRecipe({ name: 'Div' }, c, 'opacity-50');
    expect(className(n)).toContain('opacity-50');
    expect(reverseResolve(n, c).value).toBe('opacity-50');
    n = applyRecipe(n, c, 'opacity-100');
    expect(className(n)).toContain('opacity-100');
    expect(className(n)).not.toContain('opacity-50');
  });
});

describe('스크롤(overflow)', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_overflow_roundtrip
   */
  it.each(['overflow-auto', 'overflow-hidden', 'overflow-x-auto', 'overflow-y-auto'])(
    '%s 적용/역해석',
    (val) => {
      const c = ctrl('overflow');
      const n = applyRecipe({ name: 'Div' }, c, val);
      expect(className(n)).toContain(val);
      expect(reverseResolve(n, c).value).toBe(val);
    },
  );
});

describe('텍스트 서식 토글(bold/italic/underline)', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_text_format_toggles
   */
  it('italic on → italic 토큰, off → 제거', () => {
    const c = ctrl('fontItalic');
    let n = applyRecipe({ name: 'Span' }, c, 'italic');
    expect(className(n)).toContain('italic');
    n = applyRecipe(n, c, undefined);
    expect(className(n)).not.toContain('italic');
  });
  /**
   * @scenario surface=s7_style_controls
   * @effects style_text_format_toggles
   */
  it('underline on/off', () => {
    const c = ctrl('textUnderline');
    let n = applyRecipe({ name: 'A' }, c, 'underline');
    expect(className(n)).toContain('underline');
    n = applyRecipe(n, c, undefined);
    expect(className(n)).not.toContain('underline');
  });
  /**
   * @scenario surface=s7_style_controls
   * @effects style_text_format_toggles
   */
  it('fontBold(굵기 세그먼트 없는 P/Button 전용)', () => {
    const c = ctrl('fontBold');
    const n = applyRecipe({ name: 'P' }, c, 'font-bold');
    expect(className(n)).toContain('font-bold');
    expect(reverseResolve(n, c).value).toBe('font-bold');
  });
});

describe('줄바꿈(whitespace) — normal/nowrap/pre-wrap 교체', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_whitespace_replaced
   */
  it('nowrap → normal 전환', () => {
    const c = ctrl('whitespace');
    let n = applyRecipe({ name: 'P' }, c, 'nowrap');
    expect(className(n)).toContain('whitespace-nowrap');
    n = applyRecipe(n, c, 'normal');
    expect(className(n)).toContain('whitespace-normal');
    expect(className(n)).not.toContain('whitespace-nowrap');
  });
});

describe('텍스트 정렬 justify', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_text_align_justify_roundtrip
   */
  it('justify 적용/역해석', () => {
    const c = ctrl('textAlign');
    const n = applyRecipe({ name: 'P' }, c, 'justify');
    expect(className(n)).toContain('text-justify');
    expect(reverseResolve(n, c).value).toBe('justify');
  });
});

describe('굵기 컨트롤 충돌 회피 — fontWeight ↔ fontBold 공존 금지', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_font_weight_excludes_font_bold
   */
  it.each(['H1', 'H2', 'H3', 'Span'])('%s 는 fontWeight 보유 → fontBold 미부착', (comp) => {
    const sc = spec.componentCapabilities[comp]?.styleControls ?? [];
    // 조건부 단언이면 fontWeight 가 빠지거나 컴포넌트가 사라져도 단언 0개로 통과한다 — 무조건 잰다.
    expect(sc).toContain('fontWeight');
    expect(sc).not.toContain('fontBold');
  });
  /**
   * @scenario surface=s7_style_controls
   * @effects style_font_weight_excludes_font_bold
   */
  it.each(['P', 'Button'])('%s 는 fontWeight 없음 → fontBold 부착', (comp) => {
    const sc = spec.componentCapabilities[comp]?.styleControls ?? [];
    expect(sc).not.toContain('fontWeight');
    expect(sc).toContain('fontBold');
  });
});

describe('컨테이너 컴포넌트 박스 컨트롤 부착', () => {
  /**
   * @scenario surface=s7_style_controls
   * @effects style_container_box_controls_attached
   */
  it.each(['Div', 'Container', 'Flex', 'Card', 'SectionLayout', 'Table'])(
    '%s 에 박스 컨트롤(textColor/boxShadow/borderStyle/borderColor/borderRadius/opacity/overflow)',
    (comp) => {
      const sc = spec.componentCapabilities[comp]?.styleControls ?? [];
      for (const k of ['textColor', 'boxShadow', 'borderStyle', 'borderColor', 'borderRadius', 'opacity', 'overflow']) {
        expect(sc).toContain(k);
      }
    },
  );
});
