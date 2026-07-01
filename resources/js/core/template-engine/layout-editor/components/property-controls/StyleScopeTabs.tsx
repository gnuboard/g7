/**
 * StyleScopeTabs.tsx — 스타일/표시조건 탭 상단 색 모드 × 디바이스 세부탭
 *
 * 두 줄의 **서브탭(밑줄 강조형 탭 UI)** 으로 StyleScope 를 선택한다(칩 버튼 아님):
 *  - 색 모드 탭 `라이트 / 다크` (showColorScheme=false 면 숨김 — 표시조건 탭, if 는 다크 무관 D9).
 *  - 디바이스 고정 4탭 `기본값 / PC / 태블릿 / 모바일` + 노드에 이미 있는 커스텀 범위 동적 탭
 *    + `[+ 커스텀 크기]` 추가 버튼(min-max px → `"min-max"` 키).
 *
 * 활성 탭은 하단 보더(언더라인) + 강조 텍스트로 표시한다. `role="tablist"`/`role="tab"`/
 * `aria-selected` 접근성 시맨틱을 부여하되, testid·`data-active` 는 보존(테스트/e2e 의존).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만, CSS 라이브러리 비종속.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import {
  hasScopeOverride,
  isPresetBreakpoint,
  isValidScopeBreakpoint,
  type ColorScheme,
  type ScopeBreakpoint,
  type StyleScope,
} from '../../spec/styleScope';
import { useDeviceList } from '../../hooks/useDeviceList';

export interface StyleScopeTabsProps {
  scope: StyleScope;
  onChange: (scope: StyleScope) => void;
  node: EditorNode;
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 색 모드 줄 표시 여부 (표시조건 탭은 false — D9) */
  showColorScheme?: boolean;
  /**
   * "기본값으로 초기화" — 현재 활성 scope 의 override 를 제거한다(노드 패치). 디바이스 scope 면
   * `responsive[bp].props` 제거, 다크 scope 면 `dark:` 토큰 제거. 미공급 시 버튼 비노출.
   *
   */
  onClearScope?: () => void;
}

/** 프리셋 디바이스 키 → i18n 라벨 키. 'base'(공통)은 항상 맨 앞. 동적 커스텀 키는 raw 표기. */
const PRESET_DEVICE_LABEL_KEYS: Record<string, string> = {
  desktop: 'layout_editor.property_modal.scope.device_desktop',
  tablet: 'layout_editor.property_modal.scope.device_tablet',
  mobile: 'layout_editor.property_modal.scope.device_mobile',
  portable: 'layout_editor.property_modal.scope.device_portable',
};

const COLOR_SCHEMES: Array<{ key: ColorScheme; labelKey: string }> = [
  { key: 'base', labelKey: 'layout_editor.property_modal.scope.scheme_light' },
  { key: 'dark', labelKey: 'layout_editor.property_modal.scope.scheme_dark' },
];

export function StyleScopeTabs({
  scope,
  onChange,
  node,
  t,
  showColorScheme = true,
  onClearScope,
}: StyleScopeTabsProps): React.ReactElement {
  const [showCustomInput, setShowCustomInput] = React.useState(false);
  const [minPx, setMinPx] = React.useState('');
  const [maxPx, setMaxPx] = React.useState('');
  const [customError, setCustomError] = React.useState<string | null>(null);

  // 캔버스 토글과 공유하는 디바이스 키 목록(프리셋 + 레이아웃 동적 커스텀).
  const sharedDeviceKeys = useDeviceList();

  // 디바이스 세부탭 키 순서: 'base'(공통) 항상 맨 앞 → 공유 목록(프리셋+동적) → 이 노드 고유
  // 커스텀 키(공유 목록 누락분) → 현재 활성 scope.breakpoint(미편집 새 탭 유지). 중복 제거.
  const deviceTabKeys = React.useMemo<ScopeBreakpoint[]>(() => {
    const ordered: ScopeBreakpoint[] = ['base', ...sharedDeviceKeys];
    const seen = new Set<string>(ordered);
    // 이 노드가 가진 키 중 공유 목록에 없는 것(다른 레이아웃 노드엔 없는 고유 커스텀 키).
    for (const k of Object.keys(node.responsive ?? {})) {
      if (k !== 'base' && !seen.has(k)) {
        seen.add(k);
        ordered.push(k);
      }
    }
    // 활성 scope 가 커스텀이면 항상 포함(편집 시작 전 빈 탭이 사라지지 않게).
    if (scope.breakpoint !== 'base' && !seen.has(scope.breakpoint)) {
      ordered.push(scope.breakpoint);
    }
    return ordered;
  }, [sharedDeviceKeys, node.responsive, scope.breakpoint]);

  const selectScheme = (cs: ColorScheme): void => onChange({ ...scope, colorScheme: cs });
  const selectBreakpoint = (bp: ScopeBreakpoint): void => onChange({ ...scope, breakpoint: bp });

  const addCustom = (): void => {
    const min = minPx.trim();
    const max = maxPx.trim();
    // min-max 키 합성 (둘 다 빈값 불가)
    if (min === '' && max === '') {
      setCustomError(t('layout_editor.property_modal.scope.custom_invalid'));
      return;
    }
    const key = `${min}-${max}`;
    if (!isValidScopeBreakpoint(key)) {
      setCustomError(t('layout_editor.property_modal.scope.custom_invalid'));
      return;
    }
    setCustomError(null);
    setShowCustomInput(false);
    setMinPx('');
    setMaxPx('');
    selectBreakpoint(key);
  };

  return (
    <div className="g7le-style-scope-tabs" data-testid="g7le-style-scope-tabs" style={wrap}>
      {showColorScheme && (
        <div
          className="g7le-style-scope-scheme"
          data-testid="g7le-style-scope-scheme"
          role="tablist"
          aria-label={t('layout_editor.property_modal.scope.scheme_light')}
          style={tabBar}
        >
          {COLOR_SCHEMES.map((cs) => {
            const active = scope.colorScheme === cs.key;
            return (
              <button
                key={cs.key}
                type="button"
                role="tab"
                aria-selected={active}
                data-testid={`g7le-style-scheme-${cs.key}`}
                data-active={active ? 'true' : 'false'}
                onClick={() => selectScheme(cs.key)}
                style={tabBtn(active)}
              >
                {t(cs.labelKey)}
                {/* 다크 탭 표시점 — 현재 breakpoint 에 dark: 토큰 존재 시 */}
                {cs.key === 'dark' &&
                  hasScopeOverride(node, { colorScheme: 'dark', breakpoint: scope.breakpoint }) && (
                    <span data-testid="g7le-style-scheme-dark-dot" style={dot} />
                  )}
              </button>
            );
          })}
        </div>
      )}

      <div
        className="g7le-style-scope-device"
        data-testid="g7le-style-scope-device"
        role="tablist"
        aria-label={t('layout_editor.property_modal.scope.device_base')}
        style={tabBar}
      >
        {deviceTabKeys.map((key) => {
          const active = scope.breakpoint === key;
          // 'base' + 프리셋 키는 i18n 라벨 + `g7le-style-bp-{key}` testid,
          // 커스텀 범위 키는 raw 표기 + `g7le-style-bp-custom-{key}` testid(기존 e2e 의존 보존).
          const isPresetOrBase = key === 'base' || isPresetBreakpoint(key);
          const labelKey =
            key === 'base'
              ? 'layout_editor.property_modal.scope.device_base'
              : PRESET_DEVICE_LABEL_KEYS[key];
          const label = labelKey ? t(labelKey) : key;
          const testidBase = isPresetOrBase ? `g7le-style-bp-${key}` : `g7le-style-bp-custom-${key}`;
          return (
            <button
              key={String(key)}
              type="button"
              role="tab"
              aria-selected={active}
              data-testid={testidBase}
              data-active={active ? 'true' : 'false'}
              onClick={() => selectBreakpoint(key)}
              style={tabBtn(active)}
            >
              {label}
              {/* 디바이스 탭 표시점 — 그 디바이스에 기본값과 다른 명시 override 존재 시(시드만이면 없음) */}
              {hasScopeOverride(node, { colorScheme: 'base', breakpoint: key }) && (
                <span data-testid={`${testidBase}-dot`} style={dot} />
              )}
            </button>
          );
        })}
        <button
          type="button"
          data-testid="g7le-style-bp-add-custom"
          onClick={() => setShowCustomInput((v) => !v)}
          style={addTab}
        >
          + {t('layout_editor.property_modal.scope.add_custom')}
        </button>
      </div>

      {showCustomInput && (
        <div className="g7le-style-scope-custom-input" data-testid="g7le-style-scope-custom-input" style={customRow}>
          <input
            type="number"
            data-testid="g7le-style-custom-min"
            placeholder={t('layout_editor.property_modal.scope.custom_min')}
            value={minPx}
            onChange={(e) => setMinPx(e.target.value)}
            style={numInput}
          />
          <span style={{ color: '#94a3b8' }}>—</span>
          <input
            type="number"
            data-testid="g7le-style-custom-max"
            placeholder={t('layout_editor.property_modal.scope.custom_max')}
            value={maxPx}
            onChange={(e) => setMaxPx(e.target.value)}
            style={numInput}
          />
          <button type="button" data-testid="g7le-style-custom-confirm" onClick={addCustom} style={confirmBtn}>
            {t('layout_editor.property_modal.scope.add_custom')}
          </button>
        </div>
      )}

      {customError && (
        <div data-testid="g7le-style-custom-error" style={errorNote}>
          {customError}
        </div>
      )}

      {scope.breakpoint !== 'base' && !isPresetBreakpoint(scope.breakpoint) && (
        <div data-testid="g7le-style-custom-priority-note" style={priorityNote}>
          {t('layout_editor.property_modal.scope.custom_priority_note')}
        </div>
      )}

      {/* "기본값으로 초기화" — 현재 scope 에 override 가 있고 콜백이 공급될 때만 노출. */}
      {onClearScope && hasScopeOverride(node, scope) && (
        <button
          type="button"
          data-testid="g7le-style-scope-reset"
          onClick={onClearScope}
          style={resetBtn}
        >
          ↺ {t('layout_editor.property_modal.scope.reset_to_base')}
        </button>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: 0,
  padding: '0 0 8px',
  borderBottom: '1px solid #e2e8f0',
  marginBottom: 8,
};
const customRow: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center', marginTop: 4 };

/**
 * 서브탭 바 — 하단 보더(언더라인) 위에 탭들을 나열.
 * 메인탭처럼 좌우 꽉 차게 — 본체 패딩을 음수 마진으로 상쇄하고 좌측 인셋(12)만 둔다(메인탭과 동일).
 */
const tabBar: React.CSSProperties = {
  display: 'flex',
  gap: 2,
  flexWrap: 'wrap',
  borderBottom: '1px solid #e2e8f0',
  margin: '0 -16px',
  padding: '0 12px',
};
/** 서브탭 1개 — 활성 시 하단 강조 보더 + 강조 텍스트(언더라인형 탭). */
function tabBtn(active: boolean): React.CSSProperties {
  return {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: '6px 12px',
    fontSize: 12,
    border: 'none',
    borderBottom: `2px solid ${active ? '#2563eb' : 'transparent'}`,
    background: 'transparent',
    color: active ? '#2563eb' : '#64748b',
    cursor: 'pointer',
    fontWeight: active ? 700 : 500,
    marginBottom: -1,
  };
}
/** 표시점(●) — 그 scope 에 기본값과 다른 명시 override 가 있음을 알리는 작은 점. */
const dot: React.CSSProperties = {
  display: 'inline-block',
  width: 5,
  height: 5,
  borderRadius: 3,
  background: '#f59e0b',
};
const resetBtn: React.CSSProperties = {
  alignSelf: 'flex-start',
  marginTop: 2,
  padding: '3px 8px',
  fontSize: 10,
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  background: '#f8fafc',
  color: '#475569',
  cursor: 'pointer',
};
/** "+ 커스텀 크기" 추가 탭 — 탭 바 끝에 점선 강조로 추가 동작 구분. */
const addTab: React.CSSProperties = {
  padding: '6px 12px',
  fontSize: 11,
  border: 'none',
  borderBottom: '2px dashed #cbd5e1',
  background: 'transparent',
  color: '#475569',
  cursor: 'pointer',
  marginBottom: -1,
};
const numInput: React.CSSProperties = { width: 70, padding: '4px 6px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6 };
const confirmBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 11, border: 'none', borderRadius: 6, background: '#2563eb', color: '#fff', cursor: 'pointer' };
const errorNote: React.CSSProperties = { fontSize: 11, color: '#dc2626' };
const priorityNote: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
