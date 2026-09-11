/**
 * 인스톨러 CSS 색 대비 게이트 (16차 G3 D-1 · D-2 · D-3).
 *
 * 노출 점검 카드의 경고·오류 배경 위에 놓이는 문자 세 종(인라인 코드 · 링크 · 힌트)이
 * 라이트·다크 **양쪽**에서 WCAG AA(4.5:1)를 넘는지 잰다. 종전에는 토큰 리터럴 게이트
 * (`InstallerCssColorLiteralGateTest`)만 있어 「토큰을 썼다」까지만 보장했고, 그 토큰이
 * 놓이는 배경과의 대비는 주석의 수치 주장(검증되지 않은 채 틀려 있었다)에 맡겨져 있었다.
 *
 * 토큰 값은 CSS 에서 파싱한다 — `:root` 와 `[data-theme="dark"]` 블록의 `--x: …` 선언을
 * 읽고 `var()` 체인을 따라 최종 hex 로 푼다. 규칙 본문의 `color:` 도 소스에서 읽으므로
 * 규칙이 사라지거나 다른 토큰으로 바뀌면 그대로 드러난다.
 */
import { readFileSync } from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const CSS_PATH = path.resolve(__dirname, '../../../../public/install/assets/css/installer.css');
const AA_MINIMUM = 4.5;

type TokenTable = Map<string, string>;

function stripComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** 선택자 블록 하나의 본문을 돌려준다 — 같은 선택자가 여럿이면 첫 블록. */
function blockOf(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = css.match(new RegExp(`(?:^|[}\\n])\\s*${escaped}\\s*\\{([^}]*)\\}`));
  expect(match, `\`${selector}\` 규칙이 CSS 에 없다`).not.toBeNull();

  return match![1];
}

/** 블록 본문에서 `--x: value;` 선언을 표로 만든다. */
function tokensOf(block: string): TokenTable {
  const table: TokenTable = new Map();
  for (const decl of block.matchAll(/--([\w-]+)\s*:\s*([^;]+);/g)) {
    table.set(`--${decl[1]}`, decl[2].trim());
  }

  return table;
}

/** `var(--a)` 체인을 따라 최종 값으로 푼다 (다크 표에 없으면 라이트 표로 떨어진다). */
function resolve(value: string, theme: TokenTable, light: TokenTable, depth = 0): string {
  expect(depth, `var() 체인이 너무 깊다: ${value}`).toBeLessThan(12);
  const ref = value.match(/^var\((--[\w-]+)\)$/);
  if (!ref) return value;
  const next = theme.get(ref[1]) ?? light.get(ref[1]);
  expect(next, `${ref[1]} 토큰이 선언되어 있지 않다`).toBeDefined();

  return resolve(next!, theme, light, depth + 1);
}

function luminance(hex: string): number {
  expect(hex, '대비 계산은 6자리 hex 만 받는다 — 알파가 있는 값은 배경이 무엇인지 정해지지 않는다').toMatch(/^#[0-9a-f]{6}$/i);
  const channel = (i: number) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;

    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };

  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

function contrast(fg: string, bg: string): number {
  const [l1, l2] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);

  return (l1 + 0.05) / (l2 + 0.05);
}

// `expect` 는 테스트 안에서만 쓸 수 있으므로 표는 첫 테스트가 부를 때 만든다.
let cache: { css: string; light: TokenTable; dark: TokenTable } | null = null;
function tables() {
  if (!cache) {
    const css = stripComments(readFileSync(CSS_PATH, 'utf8'));
    cache = { css, light: tokensOf(blockOf(css, ':root')), dark: tokensOf(blockOf(css, '[data-theme="dark"]')) };
  }

  return cache;
}

/**
 * 잴 쌍 — 문자 규칙의 `color:` 와 그것이 놓이는 배경 토큰.
 *
 * 배경은 규칙 자신이 아니라 **조상 카드**가 칠한다(`.alert-warning` = `--warning-light`,
 * `.alert-error` = `--error-light`). 인라인 코드만 자기 배경(`--inline-code-bg`)을 갖는다.
 */
const PAIRS: Array<{ selector: string; background: string; note: string }> = [
  { selector: '.exposure-address-list code', background: '--inline-code-bg', note: '노출 주소 목록의 인라인 코드 (D-1)' },
  { selector: '.missing-files li code', background: '--inline-code-bg', note: '같은 토큰을 쓰는 형제 목록' },
  { selector: '.exposure-address-list a', background: '--warning-light', note: '판정 불능 카드(경고 배경)의 확인 주소 링크 (D-2)' },
  { selector: '.alert-warning .fix-guide-hint', background: '--warning-light', note: '경고 카드 위 힌트 (D-3)' },
  { selector: '.alert-error .fix-guide-hint', background: '--error-light', note: '오류 카드 위 힌트 (D-3)' },
  // 17차 E-1 — 경고 카드 위 라벨(`.alert-warning .fix-guide-label`)이 모집단에서 빠져 있었다.
  // 경고 배경 위 문자라 형제(힌트·링크)와 같은 축인데 손 열거에서 누락됐다.
  { selector: '.alert-warning .fix-guide-label', background: '--warning-light', note: '경고 카드 위 라벨 (E-1)' },
  // 18차 A-1 — 오류 카드 위 라벨(`.alert-error .fix-guide-label`)이 형제 힌트·경고 라벨과
  // 같은 축인데 census 에서 빠져 있었다(현재 대비 통과이나 토큰 회귀 시 조용히 초록).
  { selector: '.alert-error .fix-guide-label', background: '--error-light', note: '오류 카드 위 라벨 (18차 A-1)' },
  // D1 — 판정 불능 카드의 **판정 기준** 한 줄. 경고 배경 위 문자이고 굵기를 올려 강조하는
  // 자리라 형제(라벨·힌트·링크)와 같은 축이다. 신설과 동시에 모집단에 넣는다 — 나중에
  // 손 열거를 다시 훑는 일은 17차 E-1·18차 A-1 에서 두 번 다 실패했다.
  { selector: '.exposure-criteria', background: '--warning-light', note: '판정 불능 카드의 판정 기준 문장 (D1)' },
];

// 손 열거 모집단은 비어도 `it.each` 가 0건을 돌려 초록이 된다 (17차 E-1) — 인구조사로 하한을 잠근다.
const PAIRS_CENSUS_MINIMUM = 8;

describe('인스톨러 CSS — 경고·오류 카드 위 문자의 AA 대비 (라이트·다크)', () => {
  /**
   * @scenario exposure_probe=unknown
   *
   * @effects installer_alert_text_meets_aa_contrast_in_both_themes
   */
  it('모집단 — 토큰 표를 실제로 읽었다', () => {
    const { light, dark } = tables();
    expect(light.size).toBeGreaterThan(50);
    expect(dark.size).toBeGreaterThan(50);
  });

  /**
   * @scenario exposure_probe=unknown
   *
   * @effects installer_alert_text_meets_aa_contrast_in_both_themes
   */
  it('인구조사 — 잴 쌍 목록이 비지 않는다 (손 열거가 비면 it.each 가 0건으로 초록이 된다)', () => {
    expect(PAIRS.length).toBeGreaterThanOrEqual(PAIRS_CENSUS_MINIMUM);
  });

  /**
   * 노출 확정(오류 카드)·판정 불능(경고 카드) 두 상태의 문자를 라이트·다크에서 각각 잰다.
   *
   * @scenario color_theme=light, color_theme=dark, exposure_probe=exposed, exposure_probe=unknown
   *
   * @effects installer_alert_text_meets_aa_contrast_in_both_themes
   */
  it.each(
    PAIRS.flatMap((pair) => [
      [`${pair.note} — 라이트`, pair, 'light'],
      [`${pair.note} — 다크`, pair, 'dark'],
    ] as Array<[string, (typeof PAIRS)[number], 'light' | 'dark']>),
  )('%s ≥ 4.5:1', (_label, pair, themeName) => {
    const { css, light, dark } = tables();
    const theme = themeName === 'dark' ? dark : light;
    const block = blockOf(css, pair.selector);
    const colorDecl = block.match(/(?:^|;|\s)color\s*:\s*([^;]+);/);
    expect(colorDecl, `\`${pair.selector}\` 에 color 선언이 없다`).not.toBeNull();

    const fg = resolve(colorDecl![1].trim(), theme, light);
    const bg = resolve(`var(${pair.background})`, theme, light);
    const ratio = contrast(fg, bg);

    expect(
      ratio,
      `${pair.selector} — ${fg} on ${bg} 가 ${ratio.toFixed(2)}:1 로 AA(4.5:1) 미달`,
    ).toBeGreaterThanOrEqual(AA_MINIMUM);
  });

  /**
   * @scenario exposure_probe=unknown
   *
   * @effects installer_alert_text_meets_aa_contrast_in_both_themes
   */
  it('판정기 자기검증 — 알려진 값을 바르게 잰다', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
    expect(contrast('#ef4444', '#f9fafb')).toBeCloseTo(3.6, 1);
  });
});
