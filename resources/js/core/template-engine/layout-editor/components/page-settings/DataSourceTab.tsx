/**
 * DataSourceTab.tsx — [데이터] 탭 본체
 *
 * `DataSourcesPanel`(CRUD)을 그대로 임베드하고(진입점 단일화 — 확정 11), 하단에
 * `globalHeaders`/`scripts` **읽기전용** 섹션을 더한다. data_sources 편집 진입점은
 * [데이터] 탭으로 단일화된다(툴바 ⚙데이터 버튼 제거 — 세션 D).
 *
 * globalHeaders/scripts 는 코드 편집기에서만 수정(읽기전용 표시) — 패턴→헤더 키, 스크립트
 * id·src·로드 시점. 0건이면 안내.
 *
 * 본 탭은 prop 주도 — 셸이 DataSourcesPanel props(raw/onChange/t/...)를 그대로 흘리고,
 * globalHeaders/scripts 는 raw 에서 읽는다.
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { DataSourcesPanel, type DataSourcesPanelProps } from '../property-controls/DataSourcesPanel';

export interface DataSourceTabProps extends DataSourcesPanelProps {
  // DataSourcesPanel props 전체를 그대로 받는다(raw/onChange/t/resolveLabel/onClose/recipes 등).
}

/** raw 에서 globalHeaders 읽기(배열) */
function readGlobalHeaders(raw: Record<string, unknown> | null | undefined): Array<{ pattern?: string; headers?: Record<string, unknown> }> {
  const v = raw?.globalHeaders;
  return Array.isArray(v) ? (v as Array<{ pattern?: string; headers?: Record<string, unknown> }>) : [];
}

/** raw 에서 scripts 읽기(배열) */
function readScripts(raw: Record<string, unknown> | null | undefined): Array<{ id?: string; src?: string; loading?: string }> {
  const v = raw?.scripts;
  return Array.isArray(v) ? (v as Array<{ id?: string; src?: string; loading?: string }>) : [];
}

/**
 * [데이터] 탭 본체.
 *
 * @param props DataSourceTabProps
 * @return 데이터 탭 엘리먼트
 */
export function DataSourceTab(props: DataSourceTabProps): React.ReactElement {
  const headers = readGlobalHeaders(props.raw);
  const scripts = readScripts(props.raw);

  return (
    <div className="g7le-data-source-tab" data-testid="g7le-data-source-tab" style={tab}>
      <DataSourcesPanel {...props} />

      {/* 전역 헤더(읽기전용) */}
      <div style={roSection} data-testid="g7le-global-headers">
        <div style={roTitle}>{props.t('layout_editor.data_sources.global_headers')}</div>
        {headers.length === 0 ? (
          <p data-testid="g7le-global-header-empty" style={emptyHint}>{props.t('layout_editor.data_sources.global_headers_empty')}</p>
        ) : (
          headers.map((h, i) => (
            <div key={i} data-testid={`g7le-global-header-${i}`} style={roRow}>
              <code style={roKey}>{h.pattern ?? '*'}</code>
              <span style={roArrow}>→</span>
              <span style={roVal}>{Object.keys(h.headers ?? {}).join(', ')}</span>
            </div>
          ))
        )}
        <p style={roHint}>ⓘ {props.t('layout_editor.data_sources.global_headers_hint')}</p>
      </div>

      {/* 외부 스크립트(읽기전용) */}
      <div style={roSection} data-testid="g7le-scripts">
        <div style={roTitle}>{props.t('layout_editor.data_sources.scripts')}</div>
        {scripts.length === 0 ? (
          <p data-testid="g7le-script-empty" style={emptyHint}>{props.t('layout_editor.data_sources.scripts_empty')}</p>
        ) : (
          scripts.map((s, i) => (
            <div key={s.id ?? i} data-testid={`g7le-script-${s.id ?? i}`} style={roRow}>
              <code style={roKey}>{s.id ?? `#${i}`}</code>
              <span style={roVal}>{s.src ?? ''}</span>
              {s.loading ? <span style={roBadge}>{s.loading}</span> : null}
            </div>
          ))
        )}
        <p style={roHint}>ⓘ {props.t('layout_editor.data_sources.scripts_hint')}</p>
      </div>
    </div>
  );
}

const tab: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 };
const roSection: React.CSSProperties = { borderTop: '1px solid #e2e8f0', paddingTop: 12, display: 'flex', flexDirection: 'column', gap: 6 };
const roTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#475569' };
const roRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, padding: '4px 8px', border: '1px solid #f1f5f9', borderRadius: 6 };
const roKey: React.CSSProperties = { fontSize: 11, color: '#0f172a', fontFamily: 'monospace' };
const roArrow: React.CSSProperties = { color: '#94a3b8' };
const roVal: React.CSSProperties = { flex: 1, minWidth: 0, color: '#475569', wordBreak: 'break-all' };
const roBadge: React.CSSProperties = { fontSize: 10, color: '#64748b', background: '#f1f5f9', borderRadius: 4, padding: '0 4px' };
const roHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#94a3b8' };
const emptyHint: React.CSSProperties = { margin: 0, fontSize: 12, color: '#94a3b8' };
