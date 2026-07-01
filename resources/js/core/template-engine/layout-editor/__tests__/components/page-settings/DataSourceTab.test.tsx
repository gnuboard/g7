// e2e:allow [데이터] 탭 단위(RTL) — DataSourcesPanel 임베드 + 읽기전용 헤더/스크립트, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * DataSourceTab.test.tsx — [데이터] 탭 RTL
 *
 * 검증:
 *  ① DataSourcesPanel 임베드(CRUD 목록)
 *  ② globalHeaders 읽기전용 섹션(패턴→헤더 키 / 0건 안내)
 *  ③ scripts 읽기전용 섹션(0건 안내)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';

// I18nTextField 경량 모킹(DataSourcesPanel label_key 위젯 — 컨텍스트 회피).
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ value, onChange }: { value: string; onChange: (v: string | undefined) => void }) => (
    <input data-testid="ds-label-mock" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  ),
}));

import { DataSourceTab } from '../../../components/page-settings/DataSourceTab';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('DataSourceTab', () => {
  it('DataSourcesPanel 을 임베드한다(목록)', () => {
    const raw = { data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products' }] };
    render(<DataSourceTab raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    expect(screen.getByTestId('g7le-data-sources-panel')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-data-sources-list')).toBeInTheDocument();
  });

  it('globalHeaders 읽기전용 섹션 — 패턴→헤더 키', () => {
    const raw = {
      data_sources: [],
      globalHeaders: [{ pattern: '/api/shop/*', headers: { 'X-Shop-Token': 't', 'X-Locale': 'ko' } }],
    };
    render(<DataSourceTab raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const row = screen.getByTestId('g7le-global-header-0');
    expect(row.textContent).toContain('/api/shop/*');
    expect(row.textContent).toContain('X-Shop-Token');
  });

  it('globalHeaders/scripts 0건 안내', () => {
    render(<DataSourceTab raw={{ data_sources: [] }} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    expect(screen.getByTestId('g7le-global-header-empty')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-script-empty')).toBeInTheDocument();
  });

  it('scripts 읽기전용 섹션', () => {
    const raw = { data_sources: [], scripts: [{ id: 'analytics', src: 'https://x.js', loading: 'async' }] };
    render(<DataSourceTab raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    const row = screen.getByTestId('g7le-script-analytics');
    expect(row.textContent).toContain('analytics');
    expect(row.textContent).toContain('https://x.js');
  });
});
