/**
 * ColorPickerControl.tsx — `color` 위젯
 *
 * 글자 색상·배경색 컨트롤이 공유하는 컬러 피커. 두 종류의 값을 다룬다:
 *  - **프리셋 색**: 컨트롤이 `options` 로 선언한 디자인 시스템 색 토큰(예 Tailwind
 *    `text-gray-900`). 옵션마다 표시용 `swatch`(HEX) + 적용 `value`(토큰)를 가진다.
 *    프리셋은 classToken 으로 적용돼 **라이트·다크 모두** 동작한다(다크 = `dark:` prefix).
 *  - **자유 색**: 사용자가 컬러 피커/HEX 로 직접 고른 임의 색(`#3a7bd5`). control-level
 *    `apply.tokenTemplate`(예 `text-[{value}]`) 으로 적용되며, 다크 변형은 Tailwind
 *  safelist 한계로 빌드 불가 → **라이트 전용**.
 *
 * 다크 scope(`freeValueDisabled`)에서는 자유 입력칸을 disabled 처리하고 "패널 색을
 * 고르면 다크도 적용됩니다" 안내를 표시한다 — 프리셋 클릭만 다크 적용.
 *
 * 값(value/onChange): 프리셋이면 옵션 value(토큰 문자열), 자유색이면 HEX 문자열.
 * 코드 생성(classToken/styleProp)은 ControlRenderer 가 recipeEngine 으로 처리한다(원칙 4.8).
 * 프리셋 미선언 컨트롤(options 없음)은 종전과 동일하게 HEX 자유색만 다룬다(하위 호환).
 *
 * 편집기 코어 위젯 — `g7le-*` + 인라인 스타일만(메모리 feedback_layout_editor_no_css_lib_dependency).
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';

/** 프리셋 미선언 컨트롤의 폴백 HEX 팔레트 (하위 호환 — 자유색 단축 선택) */
const FALLBACK_PRESET_COLORS = [
  '#0f172a', '#334155', '#64748b', '#94a3b8', '#e2e8f0', '#ffffff',
  '#dc2626', '#ea580c', '#d97706', '#16a34a', '#0891b2', '#2563eb',
  '#7c3aed', '#db2777',
];

const HEX_RE = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

/** 프리셋 옵션 — value=적용 토큰, swatch=표시용 HEX, label=다국어 라벨 */
interface ColorPresetOption {
  value: string;
  swatch?: string;
  label?: string;
}

/** control.options → 프리셋 옵션 배열 (swatch HEX 보유 옵션만 토큰 프리셋으로 본다) */
function readPresetOptions(control: WidgetProps['control']): ColorPresetOption[] {
  if (!Array.isArray(control.options)) return [];
  return (control.options as Array<Record<string, unknown>>)
    .filter((o) => typeof o.value === 'string')
    .map((o) => ({
      value: o.value as string,
      swatch: typeof o.swatch === 'string' ? (o.swatch as string) : undefined,
      label: typeof o.label === 'string' ? (o.label as string) : undefined,
    }));
}

export function ColorPickerControl({
  control,
  value,
  onChange,
  t,
  freeValueDisabled,
}: WidgetProps): React.ReactElement {
  const presets = readPresetOptions(control);
  const hasTokenPresets = presets.length > 0;

  // 현재값이 프리셋 토큰인지 / 자유 HEX 인지 판정.
  const currentToken = typeof value === 'string' && presets.some((p) => p.value === value) ? value : '';
  const currentHex = typeof value === 'string' && HEX_RE.test(value) ? value : '';
  const [hexDraft, setHexDraft] = useState<string>(currentHex);

  // value prop 변경(역해석/외부 패치) 동기 — 컨트롤 외부에서 값이 바뀌면 draft 갱신
  React.useEffect(() => {
    setHexDraft(currentHex);
  }, [currentHex]);

  const commitHex = (raw: string): void => {
    const v = raw.trim();
    if (v === '') {
      onChange(undefined);
      return;
    }
    const normalized = v.startsWith('#') ? v : `#${v}`;
    if (HEX_RE.test(normalized)) {
      onChange(normalized);
    }
  };

  // 자유색 팔레트(프리셋 미선언 컨트롤) — 종전 HEX 팔레트.
  const fallbackSwatches = FALLBACK_PRESET_COLORS;

  return (
    <div className="g7le-widget g7le-widget--color" data-testid="g7le-widget-color" style={wrap}>
      {/* 자유 색 입력 행 — 다크 scope 에서는 disabled + 안내(자유값 라이트 전용) */}
      {freeValueDisabled ? (
        <div data-testid="g7le-color-free-disabled" style={freeDisabledNote}>
          {t('layout_editor.property_modal.dark_preset_only')}
        </div>
      ) : (
        <div style={row}>
          {/* 현재 색상 스와치 + 네이티브 피커 (그라데이션/HSV 대체) */}
          <label style={swatchLabel}>
            <span
              data-testid="g7le-color-swatch"
              style={{
                ...swatch,
                background: currentHex || 'transparent',
                backgroundImage: currentHex
                  ? undefined
                  : 'linear-gradient(45deg,#e2e8f0 25%,transparent 25%,transparent 75%,#e2e8f0 75%),linear-gradient(45deg,#e2e8f0 25%,#fff 25%,#fff 75%,#e2e8f0 75%)',
                backgroundSize: '8px 8px',
                backgroundPosition: '0 0, 4px 4px',
              }}
            />
            <input
              type="color"
              data-testid="g7le-color-native"
              value={currentHex || '#000000'}
              onChange={(e) => onChange(e.target.value)}
              style={{ width: 0, height: 0, opacity: 0, position: 'absolute' }}
            />
          </label>

          <input
            type="text"
            data-testid="g7le-color-hex"
            value={hexDraft}
            placeholder={t('layout_editor.control.color_hex_placeholder')}
            onChange={(e) => setHexDraft(e.target.value)}
            onBlur={(e) => commitHex(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') commitHex((e.target as HTMLInputElement).value);
            }}
            style={hexInput}
          />

          <button
            type="button"
            data-testid="g7le-color-clear"
            onClick={() => onChange(undefined)}
            style={clearBtn}
          >
            {t('layout_editor.control.default')}
          </button>
        </div>
      )}

      {/* 프리셋 토큰 팔레트 — 라이트·다크 모두 적용 가능(디자인 시스템 색). */}
      {hasTokenPresets ? (
        <div style={presetGrid} data-testid="g7le-color-token-presets">
          {presets.map((p) => {
            const active = currentToken === p.value;
            const labelText = p.label
              ? p.label.startsWith('$t:')
                ? t(p.label.slice(3))
                : p.label
              : p.value;
            return (
              <button
                key={p.value}
                type="button"
                aria-label={labelText}
                title={labelText}
                data-testid={`g7le-color-token-${p.value}`}
                data-active={active ? 'true' : 'false'}
                onClick={() => onChange(active ? undefined : p.value)}
                style={{
                  ...presetSwatch,
                  background: p.swatch || '#e2e8f0',
                  outline: active ? '2px solid #2563eb' : 'none',
                }}
              />
            );
          })}
        </div>
      ) : (
        // 프리셋 미선언 — 종전 HEX 자유색 팔레트(하위 호환). 다크에서는 숨김.
        !freeValueDisabled && (
          <div style={presetGrid} data-testid="g7le-color-presets">
            {fallbackSwatches.map((c) => (
              <button
                key={c}
                type="button"
                aria-label={c}
                data-testid={`g7le-color-preset-${c}`}
                onClick={() => onChange(c)}
                style={{
                  ...presetSwatch,
                  background: c,
                  outline: currentHex.toLowerCase() === c.toLowerCase() ? '2px solid #2563eb' : 'none',
                }}
              />
            ))}
          </div>
        )
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8 };
const row: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const swatchLabel: React.CSSProperties = { position: 'relative', display: 'inline-flex', cursor: 'pointer' };
const swatch: React.CSSProperties = { width: 24, height: 24, borderRadius: 4, border: '1px solid #cbd5e1', display: 'inline-block' };
const hexInput: React.CSSProperties = { flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, fontFamily: 'monospace' };
const clearBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', cursor: 'pointer', color: '#64748b' };
const presetGrid: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 4 };
const presetSwatch: React.CSSProperties = { width: '100%', height: 18, borderRadius: 4, border: '1px solid #cbd5e1', cursor: 'pointer', padding: 0 };
const freeDisabledNote: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic', padding: '2px 0' };
