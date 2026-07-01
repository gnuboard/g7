// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * cellBorderPreview.ts — 셀 테두리 className/인라인색 → 미리보기 CSS 변환
 *
 * 속성 패널 TableEditor 의 미니 표 미리보기 `<td>` 가 셀의 **실제 테두리**(className 의
 * 두께/변 토큰 + 인라인 style 의 borderColor)를 그대로 렌더하도록, 셀의 현재 상태를
 * React.CSSProperties 의 per-side border 속성으로 환산한다. 종전 미리보기는 고정 회색
 * 1px(`cellStyle`)만 그려 컨트롤로 두께/색을 바꿔도 미리보기에 반영되지 않던 결함을 고친다
 *
 *
 * **라이브러리 중립**: 두께 suffix·변 prefix·색 token→swatch 매핑은 모두 템플릿이 공급한
 * cellBorder 카탈로그에서 읽는다(코어는 어휘를 모름, feedback_layout_editor_no_css_lib_dependency).
 * 캔버스 인플레이스/사용자 페이지는 실제 className(Tailwind 등)이 적용되므로 본 변환은
 * 속성 패널 미리보기 전용이다.
 *
 * @since engine-v1.50.0
 */

import type React from 'react';
import type { EditorNode } from '../utils/layoutTreeUtils';

/** cellBorder 카탈로그(CellBorderControl 와 동일 형태). */
export interface CellBorderPreviewCatalog {
  sides?: Array<{ value: string; prefix: string }>;
  widths?: Array<{ value: string; suffix?: string }>;
  colors?: Array<{ swatch?: string; token: string }>;
}

/** 미리보기 색 스킴 — 'light' = base 토큰/인라인, 'dark' = `dark:` 토큰. */
export type PreviewScheme = 'light' | 'dark';

const DARK_PREFIX = 'dark:';

/** className → 토큰 배열. */
function tokenize(cn: unknown): string[] {
  return typeof cn === 'string' ? cn.split(/\s+/).filter(Boolean) : [];
}

/**
 * 토큰 목록을 스킴 기준으로 정규화 — 라이트는 비-`dark:` 토큰 그대로, 다크는 `dark:` 토큰만
 * prefix 를 벗겨 반환. 색 토큰(테두리/배경) 역해석을 스킴별로 분리한다.
 */
function schemeTokens(tokens: string[], scheme: PreviewScheme): string[] {
  if (scheme === 'dark') {
    return tokens.filter((t) => t.startsWith(DARK_PREFIX)).map((t) => t.slice(DARK_PREFIX.length));
  }
  return tokens.filter((t) => !t.startsWith(DARK_PREFIX));
}

/** 두께 value → CSS px. none 은 미리보기에서 0(테두리 없음 표현). */
const WIDTH_PX: Record<string, number> = { none: 0, thin: 1, medium: 2, thick: 4 };

/** 변 value → CSS per-side 속성 키(width/style/color). */
const SIDE_PROPS: Record<string, { w: string; s: string; c: string }> = {
  top: { w: 'borderTopWidth', s: 'borderTopStyle', c: 'borderTopColor' },
  bottom: { w: 'borderBottomWidth', s: 'borderBottomStyle', c: 'borderBottomColor' },
  left: { w: 'borderLeftWidth', s: 'borderLeftStyle', c: 'borderLeftColor' },
  right: { w: 'borderRightWidth', s: 'borderRightStyle', c: 'borderRightColor' },
};

const ALL_SIDES = ['top', 'bottom', 'left', 'right'] as const;

/**
 * 셀의 className(두께/변 토큰) + 인라인 borderColor 를 미리보기용 per-side CSS 로 환산.
 *
 * 색은 스킴(`scheme`)별로 다르게 읽는다:
 *  - **light**: 인라인 `style.borderColor`(자유 HEX, 라이트 전용) 우선, 없으면 비-`dark:`
 *    색 토큰 swatch. (오늘과 바이트 동일 — 회귀 무변경 기본값.)
 *  - **dark**: `dark:border-*` 색 토큰만 swatch 로 매핑(인라인은 다크 미적용). 다크 색 토큰이
 *    없으면 색 미지정(폴백색) → 캔버스 다크 렌더와 일관.
 * 두께/변 토큰은 스킴 무관(단일 base 토큰) — width/side 는 라이트/다크 공통.
 *
 * @param cell 대상 셀 노드(props.className / props.style 참조)
 * @param catalog 템플릿 cellBorder 카탈로그(suffix/prefix/swatch 어휘)
 * @param scheme 미리보기 색 스킴('light' 기본 = 회귀 동일)
 * @return per-side border CSS (테두리 토큰이 없으면 빈 객체 → 호출부 폴백 스타일 사용)
 */
export function cellBorderPreviewStyle(
  cell: EditorNode | null | undefined,
  catalog: CellBorderPreviewCatalog | null | undefined,
  scheme: PreviewScheme = 'light',
): React.CSSProperties {
  if (!cell || !catalog) return {};
  const sides = catalog.sides ?? [];
  const widths = catalog.widths ?? [];
  const colors = catalog.colors ?? [];
  const tokens = tokenize(cell.props?.className);
  if (tokens.length === 0 && !cell.props?.style) return {};

  // suffix → 두께 value 역매핑(가장 긴 suffix 우선 매칭: '-4' 가 '' 보다 먼저).
  const widthBySuffix = [...widths]
    .filter((w) => w.value !== 'none')
    .sort((a, b) => (b.suffix ?? '').length - (a.suffix ?? '').length);

  // 각 변(prefix)에 대해 활성 두께 value 판정.
  const sideWidth: Record<string, string> = {};
  for (const s of sides) {
    // 이 변에 해당하는 두께 토큰 탐색(border / border-2 / border-t / border-t-2 …).
    for (const w of widthBySuffix) {
      const tok = w.suffix ? `${s.prefix}${w.suffix}` : s.prefix;
      if (tokens.includes(tok)) {
        sideWidth[s.value] = w.value;
        break;
      }
    }
  }

  // 색: 스킴별. light = 인라인 borderColor(자유 HEX, 라이트 전용) 우선 + 비-dark 토큰 swatch.
  // dark = dark:border-* 토큰 swatch 만(인라인 다크 미적용).
  const style = (cell.props?.style ?? {}) as Record<string, unknown>;
  const colorTokens = schemeTokens(tokens, scheme);
  const tokenColor = colors.find((c) => colorTokens.includes(c.token))?.swatch ?? '';
  const inlineColor =
    scheme === 'light' && typeof style.borderColor === 'string' ? (style.borderColor as string) : '';
  const baseColor = inlineColor || tokenColor || '#94a3b8';

  // per-side 인라인 색(공유 변 보정으로 borderBottomColor/borderRightColor 등 개별 지정 가능).
  const sideColor = (sideVal: string): string => {
    const key = SIDE_PROPS[sideVal]?.c;
    const v = key ? style[key] : undefined;
    return typeof v === 'string' && v ? (v as string) : baseColor;
  };

  const css: Record<string, string | number> = {};
  const applySide = (sideVal: string, widthVal: string): void => {
    const props = SIDE_PROPS[sideVal];
    if (!props) return;
    const px = WIDTH_PX[widthVal] ?? 1;
    css[props.w] = `${px}px`;
    css[props.s] = px > 0 ? 'solid' : 'none';
    if (px > 0) css[props.c] = sideColor(sideVal);
  };

  // 'all' 은 4변 동시. 개별 변은 해당 변만. 'all' + 개별 공존 시 개별이 우선(나중 적용).
  if (sideWidth.all) for (const sd of ALL_SIDES) applySide(sd, sideWidth.all);
  for (const sd of ALL_SIDES) if (sideWidth[sd]) applySide(sd, sideWidth[sd]);

  return css as React.CSSProperties;
}

/** cellBackground 카탈로그(배경 토큰→swatch 매핑 — 다크 미리보기용). */
export interface CellFillPreviewCatalog {
  colors?: Array<{ swatch?: string; token?: string }>;
}

/**
 * 셀의 배경색/여백을 미리보기 CSS 로 환산. 여백(`style.padding`)은 스킴 무관 인라인.
 * 배경색은 스킴별:
 *  - **light**: 인라인 `style.backgroundColor`(자유 HEX, 라이트 전용) 우선, 없으면 비-`dark:`
 *    `bg-*` 색 토큰 swatch(카탈로그). (인라인만 있던 종전과 호환 — 카탈로그 미공급 시 인라인.)
 *  - **dark**: `dark:bg-*` 색 토큰만 카탈로그 swatch 로 매핑(인라인 다크 미적용).
 *
 * 배경 색 토큰→swatch 매핑은 카탈로그가 공급(코어 라이브러리 중립). 카탈로그 미공급이면
 * 라이트 인라인만 반영(다크 토큰 매핑 불가 — graceful).
 *
 * @param cell 대상 셀 노드(props.className / props.style 참조)
 * @param catalog cellBackground 카탈로그(색 토큰→swatch). 미공급 시 라이트 인라인만.
 * @param scheme 미리보기 색 스킴('light' 기본 = 회귀 동일)
 * @return backgroundColor/padding CSS (없으면 빈 객체)
 */
export function cellFillPaddingPreviewStyle(
  cell: EditorNode | null | undefined,
  catalog?: CellFillPreviewCatalog | null,
  scheme: PreviewScheme = 'light',
): React.CSSProperties {
  if (!cell?.props) return {};
  const style = (cell.props.style ?? {}) as Record<string, unknown>;
  const css: Record<string, string | number> = {};

  // 배경 색 토큰(스킴별) swatch — 카탈로그 공급 시.
  const tokens = tokenize(cell.props.className);
  const bgTokenColor =
    catalog?.colors?.find((c) => c.token && schemeTokens(tokens, scheme).includes(c.token))?.swatch ?? '';
  const inlineBg =
    scheme === 'light' && typeof style.backgroundColor === 'string' ? (style.backgroundColor as string) : '';
  const bg = inlineBg || bgTokenColor;
  if (bg) css.backgroundColor = bg;

  if (typeof style.padding === 'string' && style.padding) {
    css.padding = style.padding;
  }
  return css as React.CSSProperties;
}
