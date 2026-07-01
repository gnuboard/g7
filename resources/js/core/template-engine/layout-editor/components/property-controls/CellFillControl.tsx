// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellFillControl.tsx — 셀 배경색 시각 피커
 *
 * 표 셀의 **배경색**을 raw className 타이핑 없이 시각 UI 로 지정한다(계획서 raw
 * 편집 금지 · 기술용어 배제 · 일반 편집기 느낌). 프리셋 스와치 + 자유 색
 * 컬러피커(HEX) + **라이트/다크 모드별 탭**.
 *
 * **적용 메커니즘**: 프리셋 색은 카탈로그 `token`(예 `bg-gray-100`)
 * 을 **className 토큰**으로 적용한다 — 활성 스킴이 다크면 코어가 `dark:` prefix 를 붙여 라이트/
 * 다크 색이 공존(스타일 탭 색 컨트롤과 동일 메커닉). 자유 HEX 는 인라인 `style.backgroundColor`
 * 로 **라이트 전용**(다크 빌드 누락 위험 회피, feedback_color_size_control_template_lib_mapping)
 * — 다크 탭에서 자유 컬러피커는 비활성 + 안내. 토큰 없는 프리셋은 다크 탭에서 비활성(graceful).
 *
 * **라이브러리 중립**(메모리 feedback_layout_editor_no_css_lib_dependency): 코어는 색 어휘를
 * 모른다 — 프리셋 색 토큰/swatch 는 템플릿 editor-spec 의 cellBackground 카탈로그가 공급하고,
 * 코어는 토큰을 className 에 더하고 빼거나(프리셋) hex 를 인라인에 바를 뿐(자유).
 *
 * 속성 패널 TableEditor 와 캔버스 인플레이스 TableInplaceOverlay 가 공유한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { scopedClassTokens } from '../../spec/recipeEngine';

/** cellBackground 카탈로그(템플릿 editor-spec params.cellBackground). */
export interface CellFillCatalog {
  /** 프리셋 배경색 — swatch(미리보기 hex). token(예 `bg-gray-100`) 있으면 className 토큰 경로. */
  colors?: Array<{ value: string; label?: string; swatch?: string; token?: string }>;
}

export interface CellFillControlProps {
  /** 현재 셀 인라인 배경색(style.backgroundColor — 자유 HEX, 라이트 전용). 없으면 빈 문자열. */
  colorStyle?: string;
  /** 현재 셀 className(다크/라이트 `bg-*` 토큰 역해석용). 없으면 빈 문자열. */
  className?: string;
  /** 카탈로그(템플릿 공급). 없으면 자유 색만(프리셋 부재). */
  catalog?: CellFillCatalog | null;
  /** 다국어 해석. */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 활성 색 스킴('light'|'dark'). 미공급 시 'light'. */
  colorScheme?: 'light' | 'dark';
  /** 프리셋 색 클릭 — 카탈로그 token 전달(부모가 활성 스킴 토큰으로 적용). token 보유 프리셋만. */
  onPresetToken?: (token: string) => void;
  /** 자유 HEX(컬러피커) 변경 — 인라인 style.backgroundColor 적용(라이트 전용, 빈값=제거). */
  onCustomColor?: (hex: string) => void;
  /** "없음"(배경 제거) — 활성 스킴 토큰 + (라이트면 인라인) 제거. */
  onClear?: () => void;
  /** 비활성. */
  disabled?: boolean;
}

const HEX_RE = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

export function CellFillControl({
  colorStyle,
  className,
  catalog,
  t,
  colorScheme = 'light',
  onPresetToken,
  onCustomColor,
  onClear,
  disabled,
}: CellFillControlProps): React.ReactElement {
  const colors = catalog?.colors ?? [];
  const dark = colorScheme === 'dark';
  const cur = colorStyle ?? '';
  // 활성 스킴의 색 토큰(라이트=비-dark `bg-*`, 다크=`dark:bg-*`→bare).
  const schemeColorTokens = scopedClassTokens(className ?? '', dark);
  const activeToken = colors.find((c) => c.token && schemeColorTokens.includes(c.token))?.token ?? null;

  const lbl = (key: string | undefined, fallback: string): string =>
    key && key.startsWith('$t:') ? t(key.slice(3)) : key || fallback;

  // "없음"(투명) 활성 = 활성 스킴에 색 토큰 없음 + (라이트면 인라인 색 없음).
  const noneActive = !activeToken && (dark || cur.trim() === '');
  // 자유 HEX 활성(라이트 전용) = 인라인 색이 있고 프리셋 swatch 와 불일치.
  const isPresetSwatch = !!cur && colors.some((c) => c.swatch && c.swatch.toLowerCase() === cur.toLowerCase());
  const customActive = !dark && !!cur && !isPresetSwatch;

  return (
    <div className="g7le-cell-fill" data-testid="g7le-cell-fill" style={wrap}>
      <div style={headRow}>
        <span style={sectionLabel}>{t('layout_editor.table_editor.cell_fill')}</span>
      </div>
      <div style={row} data-testid="g7le-cell-fill-colors">
        {/* 없음(투명) — 배경 제거 */}
        <button
          type="button"
          disabled={disabled}
          title={t('layout_editor.table_editor.cell_fill_none')}
          data-testid="g7le-cell-fill-none"
          aria-pressed={noneActive}
          onClick={() => onClear?.()}
          style={{
            ...swatch,
            background:
              'repeating-conic-gradient(#e2e8f0 0% 25%, #ffffff 0% 50%) 50% / 10px 10px',
            outline: noneActive ? '2px solid #2563eb' : '1px solid #cbd5e1',
          }}
        />
        {colors.map((c) => {
          // 프리셋 활성 = 활성 스킴 토큰 일치(token 경로) 또는 (라이트) 인라인 swatch 일치.
          const tokenMatch = !!c.token && activeToken === c.token;
          const swatchMatch = !dark && !!c.swatch && cur.toLowerCase() === (c.swatch ?? '').toLowerCase();
          const presetActive = tokenMatch || swatchMatch;
          // 다크 탭에서 token 없는 프리셋은 비활성(인라인은 다크 미적용 — graceful).
          const presetDisabled = disabled || (dark && !c.token);
          return (
            <button
              key={c.value}
              type="button"
              disabled={presetDisabled}
              title={lbl(c.label, c.value)}
              data-testid={`g7le-cell-fill-color-${c.value}`}
              aria-pressed={presetActive}
              onClick={() => {
                if (c.token && onPresetToken) onPresetToken(c.token);
                else if (!dark && c.swatch && onCustomColor) onCustomColor(c.swatch);
              }}
              style={{
                ...swatch,
                background: c.swatch ?? '#fff',
                outline: presetActive ? '2px solid #2563eb' : '1px solid #cbd5e1',
                opacity: presetDisabled ? 0.4 : 1,
                cursor: presetDisabled ? 'not-allowed' : 'pointer',
              }}
            />
          );
        })}
        {/* 자유 색 — 네이티브 컬러 피커(인라인, 라이트 전용). 다크 탭에서 비활성 + 안내. */}
        {onCustomColor && (
          <label
            title={dark ? t('layout_editor.table_editor.dark_free_color_hint') : t('layout_editor.table_editor.cell_fill_custom')}
            data-testid="g7le-cell-fill-custom"
            style={{
              ...swatch, position: 'relative', overflow: 'hidden', display: 'inline-flex',
              alignItems: 'center', justifyContent: 'center',
              background: customActive && HEX_RE.test(cur) ? cur : 'conic-gradient(red, yellow, lime, aqua, blue, magenta, red)',
              outline: customActive ? '2px solid #2563eb' : '1px solid #cbd5e1',
              opacity: dark ? 0.4 : 1, cursor: dark ? 'not-allowed' : 'pointer',
            }}
          >
            <input
              type="color"
              disabled={disabled || dark}
              data-testid="g7le-cell-fill-color-picker"
              value={cur && HEX_RE.test(cur) ? cur : '#ffffff'}
              onChange={(e) => onCustomColor(e.target.value)}
              style={{ position: 'absolute', width: 0, height: 0, opacity: 0 }}
            />
          </label>
        )}
      </div>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const headRow: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 };
const sectionLabel: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#475569' };
const row: React.CSSProperties = { display: 'flex', gap: 4, flexWrap: 'wrap', alignItems: 'center' };
const swatch: React.CSSProperties = { width: 22, height: 22, borderRadius: 5, padding: 0, cursor: 'pointer', boxSizing: 'border-box' };
