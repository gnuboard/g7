// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * ColorSchemeTabs.tsx — 셀 색(테두리·배경) 라이트/다크 **단일 공용 탭**
 *
 * 라이트/다크 탭을 테두리 색·배경색 컨트롤에 **각각** 두지 않고, 색상
 * 섹션 상단에 **단일 탭 1개**만 둔다. 그 탭 선택이 테두리 색 + 배경색 **전체**에 적용된다.
 * 속성 패널 TableEditor 와 캔버스 인플레이스 TableInplaceOverlay 가 **동일 UI** 로 공유한다.
 *
 * 탭 아래에 다크 모드 안내(자유 색은 라이트 전용)를 다크 탭에서만 표시한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';

export interface ColorSchemeTabsProps {
  /** 활성 스킴. */
  colorScheme: 'light' | 'dark';
  /** 스킴 변경(부모 단일 상태). */
  onChange: (scheme: 'light' | 'dark') => void;
  /** 다국어 해석. */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 비활성. */
  disabled?: boolean;
}

export function ColorSchemeTabs({ colorScheme, onChange, t, disabled }: ColorSchemeTabsProps): React.ReactElement {
  const dark = colorScheme === 'dark';
  return (
    <div className="g7le-color-scheme-tabs" data-testid="g7le-color-scheme-tabs" style={wrap}>
      <div style={tabsRow}>
        <span style={label}>{t('layout_editor.table_editor.cell_color')}</span>
        <div style={tabs}>
          <button
            type="button"
            disabled={disabled}
            data-testid="g7le-color-scheme-light"
            aria-pressed={!dark}
            onClick={() => onChange('light')}
            style={!dark ? tabActive : tab}
          >
            {t('layout_editor.property_modal.scope.scheme_light')}
          </button>
          <button
            type="button"
            disabled={disabled}
            data-testid="g7le-color-scheme-dark"
            aria-pressed={dark}
            onClick={() => onChange('dark')}
            style={dark ? tabActive : tab}
          >
            {t('layout_editor.property_modal.scope.scheme_dark')}
          </button>
        </div>
      </div>
      {dark && (
        <div style={hint} data-testid="g7le-color-scheme-dark-hint">
          {t('layout_editor.table_editor.dark_free_color_hint')}
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 3 };
const tabsRow: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 };
const label: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a' };
const tabs: React.CSSProperties = { display: 'flex', gap: 2 };
const tab: React.CSSProperties = { padding: '2px 9px', fontSize: 11, border: 'none', borderBottom: '2px solid transparent', background: 'transparent', color: '#94a3b8', cursor: 'pointer' };
const tabActive: React.CSSProperties = { ...tab, color: '#1d4ed8', borderBottom: '2px solid #2563eb', fontWeight: 600 };
const hint: React.CSSProperties = { fontSize: 10, color: '#94a3b8', fontStyle: 'italic' };
