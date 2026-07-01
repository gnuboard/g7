// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellBorderControl.tsx — 셀 테두리 시각 피커
 *
 * 표 셀의 테두리를 **raw className 타이핑 없이** 시각 UI 로 지정한다(계획서 raw
 * 편집 금지 · 기술용어 배제 · 일반 편집기 느낌). 적용 변(전체/상/하/좌/우) ×
 * 두께(없음/얇게/보통/굵게) × 색(프리셋 스와치 + 자유 HEX) × **라이트/다크 탭**(4차).
 *
 * **적용 메커니즘**:
 *  - 두께/변(width/side)은 className 토큰(스킴 무관 단일 base 토큰 — 라이트/다크 공통).
 *  - **프리셋 색**은 카탈로그 `token`(예 `border-gray-500`)을 className 토큰으로 적용 — 활성
 *    스킴이 다크면 코어가 `dark:` prefix 부여(라이트/다크 색 공존). 종전의 per-side 공유 변
 *    인라인 보정은 토큰 경로에선 무의미해 드롭(사용자 페이지의 모든 Tailwind border 색 클래스와
 *    동일 렌더 — border-collapse 인접 셀과 동일 색이 정상·기대 동작).
 *  - **자유 HEX** 는 인라인 `style.borderColor` 로 라이트 전용(다크 빌드 누락 회피) — 다크
 *    탭에서 자유 컬러피커 비활성 + 안내. 토큰 없는 프리셋은 다크 탭에서 비활성(graceful).
 *
 * **라이브러리 중립**(메모리 feedback_color_size_control_template_lib_mapping ·
 * feedback_layout_editor_no_css_lib_dependency): 코어는 테두리 토큰 어휘를 모른다 —
 * 두께 suffix·변 prefix·색 token/swatch 는 모두 템플릿 editor-spec 의 cellBorder 카탈로그가
 * 공급하고, 코어는 그 토큰을 className 에 더하고 빼거나(두께/변/프리셋색) hex 를 인라인에
 * 바를 뿐(자유 색).
 *
 * 속성 패널 TableEditor 와 캔버스 인플레이스 TableInplaceOverlay 가 공유한다.
 *
 * @since engine-v1.50.0
 */

import React, { useMemo } from 'react';
import { scopedClassTokens } from '../../spec/recipeEngine';

/** cellBorder 카탈로그(템플릿 editor-spec params.cellBorder). */
export interface CellBorderCatalog {
  sides?: Array<{ value: string; label?: string; prefix: string }>;
  widths?: Array<{ value: string; label?: string; suffix?: string }>;
  colors?: Array<{ value: string; label?: string; swatch?: string; token: string }>;
}

export interface CellBorderControlProps {
  /** 현재 셀 className(없으면 빈 문자열). */
  className: string;
  /** 카탈로그(템플릿 공급). 없으면 컨트롤 미표시(폴백). */
  catalog?: CellBorderCatalog | null;
  /** 다국어 해석. */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 변경 콜백 — 합성된 새 className(두께/변만, 색 제외). */
  onChange: (nextClassName: string) => void;
  /** 활성 색 스킴('light'|'dark') — 부모의 단일 색 스킴 탭에서 주입(컨트롤은 표시만). 미공급 시 'light'. */
  colorScheme?: 'light' | 'dark';
  /** 프리셋 색 클릭 — 카탈로그 token 전달(부모가 활성 스킴 토큰으로 적용). */
  onPresetToken?: (colorToken: string) => void;
  /** 현재 셀 인라인 테두리 색(style.borderColor — 자유 HEX, 라이트 전용). 없으면 빈 문자열. */
  colorStyle?: string;
  /** 자유 색(컬러피커/HEX) 변경 콜백 — 인라인 style.borderColor 적용(라이트 전용, 빈값=제거). */
  onColorStyle?: (hex: string) => void;
  /** 비활성. */
  disabled?: boolean;
}

const HEX_RE = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

/** className → 토큰 배열. */
function tokenize(cn: string): string[] {
  return cn.split(/\s+/).filter(Boolean);
}

/** 모든 변 prefix 의 두께 토큰 집합(`border`,`border-2`,`border-t`,`border-t-2`…) 판정. */
function isBorderWidthToken(tok: string, sidePrefixes: string[]): boolean {
  // 색(border-{color}) 은 제외 — 두께는 border / border-N / border-{side} / border-{side}-N.
  for (const p of sidePrefixes) {
    if (tok === p) return true; // border, border-t …
    if (/^-?\d+$/.test(tok.slice(p.length + 1)) && tok.startsWith(p + '-')) return true; // border-2, border-t-2
  }
  return false;
}

export function CellBorderControl({
  className,
  catalog,
  t,
  onChange,
  colorScheme = 'light',
  onPresetToken,
  colorStyle,
  onColorStyle,
  disabled,
}: CellBorderControlProps): React.ReactElement | null {
  const sides = catalog?.sides ?? [];
  const widths = catalog?.widths ?? [];
  const colors = catalog?.colors ?? [];
  const dark = colorScheme === 'dark';
  const sidePrefixes = useMemo(() => sides.map((s) => s.prefix), [sides]);
  const colorTokens = useMemo(() => new Set(colors.map((c) => c.token)), [colors]);

  const tokens = useMemo(() => tokenize(className), [className]);
  // 활성 스킴의 색 토큰(라이트=비-dark, 다크=dark:→bare).
  const schemeColorTokens = useMemo(() => scopedClassTokens(className, dark), [className, dark]);
  const activeColorToken = useMemo(
    () => schemeColorTokens.find((tk) => colorTokens.has(tk)) ?? null,
    [schemeColorTokens, colorTokens],
  );
  // 활성 변 = border-width 토큰이 존재하는 prefix 집합(스킴 무관 — 두께/변은 base 토큰).
  const activeSides = useMemo(() => {
    const set = new Set<string>();
    for (const s of sides) {
      const has = tokens.some(
        (tk) => tk === s.prefix || (tk.startsWith(s.prefix + '-') && /^-?\d+$/.test(tk.slice(s.prefix.length + 1))),
      );
      if (has) set.add(s.value);
    }
    return set;
  }, [tokens, sides]);
  // 활성 두께 = 첫 두께 토큰의 suffix 매칭(없으면 none).
  const activeWidth = useMemo(() => {
    const widthTok = tokens.find((tk) => isBorderWidthToken(tk, sidePrefixes));
    if (!widthTok) return 'none';
    const realWidths = widths.filter((w) => w.value !== 'none');
    for (const s of sides) {
      if (widthTok === s.prefix) return realWidths.find((w) => (w.suffix ?? '') === '')?.value ?? 'thin';
      if (widthTok.startsWith(s.prefix + '-')) {
        const rest = widthTok.slice(s.prefix.length); // '-2'
        const w = realWidths.find((x) => (x.suffix ?? '') === rest);
        if (w) return w.value;
      }
    }
    return 'thin';
  }, [tokens, sidePrefixes, sides, widths]);

  if (!catalog || sides.length === 0 || widths.length === 0) return null;

  /** 비-테두리 토큰만 남긴 베이스(색 토큰 + 두께 토큰 제거). 색 토큰은 라이트/다크 양쪽 보존
   *  (두께/변 합성은 색을 건드리지 않음 — 색은 onPresetToken 토큰 경로 SSoT). */
  function stripBorderWidth(toks: string[]): string[] {
    return toks.filter((tk) => !isBorderWidthToken(tk, sidePrefixes));
  }

  /** 현재 상태에서 sides/width 를 적용해 새 className 합성(색 토큰 보존). */
  function compose(nextSides: Set<string>, nextWidth: string): string {
    const base = stripBorderWidth(tokens);
    const w = widths.find((x) => x.value === nextWidth);
    if (!w || nextWidth === 'none' || nextSides.size === 0) {
      // 두께 없음/변 없음 → 두께 토큰 제거(색 토큰은 보존 — 두께 0이면 안 보이지만 색은 유지).
      return base.join(' ').trim();
    }
    const suffix = w.suffix ?? '';
    const out = [...base];
    for (const s of sides) {
      if (!nextSides.has(s.value)) continue;
      out.push(suffix ? `${s.prefix}${suffix}` : s.prefix);
    }
    return out.join(' ').trim();
  }

  const toggleSide = (sideVal: string): void => {
    const next = new Set(activeSides);
    if (next.has(sideVal)) next.delete(sideVal);
    else {
      if (sideVal === 'all') next.clear();
      else next.delete('all');
      next.add(sideVal);
    }
    const w = activeWidth === 'none' ? (widths.find((x) => x.value !== 'none')?.value ?? 'thin') : activeWidth;
    onChange(compose(next, w));
  };

  const setWidth = (widthVal: string): void => {
    const s = activeSides.size === 0 ? new Set(['all']) : activeSides;
    onChange(compose(s, widthVal));
  };

  // 프리셋 색 — 카탈로그 token 을 부모에 전달(부모가 활성 스킴 토큰으로 적용).
  const setPreset = (colorToken: string): void => {
    onPresetToken?.(colorToken);
  };

  const lbl = (key: string | undefined, fallback: string): string =>
    key && key.startsWith('$t:') ? t(key.slice(3)) : key || fallback;

  return (
    <div className="g7le-cell-border" data-testid="g7le-cell-border" style={wrap}>
      <div style={headRow}>
        <span style={sectionLabel}>{t('layout_editor.table_editor.cell_border')}</span>
      </div>

      {/* 두께 */}
      <div style={row} data-testid="g7le-cell-border-widths">
        {widths.map((w) => (
          <button
            key={w.value}
            type="button"
            disabled={disabled}
            data-testid={`g7le-cell-border-width-${w.value}`}
            aria-pressed={activeWidth === w.value}
            onClick={() => setWidth(w.value)}
            style={activeWidth === w.value ? chipActive : chip}
          >
            {lbl(w.label, w.value)}
          </button>
        ))}
      </div>

      {/* 적용 변(두께가 none 이 아닐 때만) */}
      {activeWidth !== 'none' && (
        <div style={row} data-testid="g7le-cell-border-sides">
          {sides.map((s) => (
            <button
              key={s.value}
              type="button"
              disabled={disabled}
              data-testid={`g7le-cell-border-side-${s.value}`}
              aria-pressed={activeSides.has(s.value)}
              onClick={() => toggleSide(s.value)}
              style={activeSides.has(s.value) ? chipActive : chip}
            >
              {lbl(s.label, s.value)}
            </button>
          ))}
        </div>
      )}

      {/* 색 — 라이트/다크 탭 + 프리셋 스와치(토큰) + 자유 색(인라인, 라이트 전용). 두께 none 이면 숨김. */}
      {activeWidth !== 'none' && (colors.length > 0 || onColorStyle) && (
        <>
          <div style={row} data-testid="g7le-cell-border-colors">
            {colors.map((c) => {
              // 프리셋 활성 = 활성 스킴 색 토큰 일치(token 경로).
              const presetActive = activeColorToken === c.token;
              return (
                <button
                  key={c.value}
                  type="button"
                  disabled={disabled}
                  title={lbl(c.label, c.value)}
                  data-testid={`g7le-cell-border-color-${c.value}`}
                  aria-pressed={presetActive}
                  onClick={() => setPreset(c.token)}
                  style={{
                    ...swatch,
                    background: c.swatch ?? '#fff',
                    outline: presetActive ? '2px solid #2563eb' : '1px solid #cbd5e1',
                  }}
                />
              );
            })}
            {/* 자유 색 — 네이티브 컬러 피커(인라인, 라이트 전용). 다크 탭에서 비활성 + 안내. */}
            {onColorStyle && (() => {
              const isPresetSwatch = !!colorStyle && colors.some((c) => c.swatch && c.swatch.toLowerCase() === colorStyle.toLowerCase());
              const customActive = !dark && !!colorStyle && !isPresetSwatch;
              return (
              <label
                title={dark ? t('layout_editor.table_editor.dark_free_color_hint') : t('layout_editor.table_inplace.custom_color')}
                data-testid="g7le-cell-border-color-custom"
                style={{
                  ...swatch, position: 'relative', overflow: 'hidden', display: 'inline-flex',
                  alignItems: 'center', justifyContent: 'center',
                  background: customActive && HEX_RE.test(colorStyle!) ? colorStyle! : 'conic-gradient(red, yellow, lime, aqua, blue, magenta, red)',
                  outline: customActive ? '2px solid #2563eb' : '1px solid #cbd5e1',
                  opacity: dark ? 0.4 : 1, cursor: dark ? 'not-allowed' : 'pointer',
                }}
              >
                <input
                  type="color"
                  disabled={disabled || dark}
                  data-testid="g7le-cell-border-color-picker"
                  value={colorStyle && HEX_RE.test(colorStyle) ? colorStyle : '#000000'}
                  onChange={(e) => onColorStyle(e.target.value)}
                  style={{ position: 'absolute', width: 0, height: 0, opacity: 0 }}
                />
              </label>
              );
            })()}
          </div>
        </>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const headRow: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 };
const sectionLabel: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#475569' };
const row: React.CSSProperties = { display: 'flex', gap: 4, flexWrap: 'wrap', alignItems: 'center' };
const chip: React.CSSProperties = { padding: '3px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const chipActive: React.CSSProperties = { ...chip, border: '1px solid #2563eb', background: '#eff6ff', color: '#1d4ed8', fontWeight: 600 };
const swatch: React.CSSProperties = { width: 22, height: 22, borderRadius: 5, padding: 0, cursor: 'pointer', boxSizing: 'border-box' };
