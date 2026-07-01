// e2e:allow DataSourcesPanel 보강 필드 단위(RTL) — 종류분기/onSuccess/errorHandling/websocket, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * DataSourcesPanel.enrich.test.tsx — 편집 폼 보강 RTL
 *
 * 검증(기존 CRUD 회귀는 DataSourcesPanel.test.tsx 가 커버 — 본 파일은 보강 필드만):
 *  ① contentType select(type=api) → 저장
 *  ② refetchOnMount 토글 → 저장
 *  ③ onSuccess/onError 다중 액션 빌더 섹션 렌더
 *  ④ errorHandling 코드 추가 → 행 생성
 *  ⑤ websocket 종류 → 채널/이벤트/채널타입/타겟소스/onReceive 섹션 분기
 *  ⑥ 종류 전환 시 무관 필드 보존(__source)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// I18nTextField 경량 모킹(label_key 위젯 — 컨텍스트 회피).
vi.mock('../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({ value, onChange }: { value: string; onChange: (v: string | undefined) => void }) => (
    <input data-testid="ds-label-mock" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  ),
}));
// ConditionBuilder 경량 모킹(StyleScope 등 의존 회피 — 조건부 로딩 섹션 존재만 확인).
vi.mock('../../components/property-controls/ConditionBuilder', () => ({
  ConditionBuilder: () => <div data-testid="condition-builder-mock" />,
}));

import { DataSourcesPanel } from '../../components/property-controls/DataSourcesPanel';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('DataSourcesPanel — 보강', () => {
  it('편집 폼에 contentType/refetchOnMount/onSuccess/onError/errorHandling/조건부 섹션 렌더', () => {
    const raw = { data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products' }] };
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-ds-contenttype')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-refetch')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-onsuccess')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-onerror')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-errorhandling')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-loadcondition')).toBeInTheDocument();
  });

  it('contentType/refetchOnMount 저장 라운드트립', () => {
    const onChange = vi.fn();
    const raw = { data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products' }] };
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.change(screen.getByTestId('g7le-ds-contenttype'), { target: { value: 'multipart/form-data' } });
    fireEvent.click(screen.getByTestId('g7le-ds-refetch'));
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const merged = onChange.mock.calls.at(-1)![0];
    expect(merged[0]).toMatchObject({ contentType: 'multipart/form-data', refetchOnMount: true });
  });

  it('errorHandling 코드 추가 → 행 생성', () => {
    const raw = { data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products' }] };
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.click(screen.getByTestId('g7le-ds-errorhandling-add'));
    expect(screen.getByTestId('g7le-ds-eh-row-403')).toBeInTheDocument();
  });

  it('websocket 종류 → 채널/이벤트/채널타입/타겟소스/onReceive 섹션 분기', () => {
    const raw = { data_sources: [{ id: 'live', type: 'websocket' }] };
    render(<DataSourcesPanel raw={raw} onChange={vi.fn()} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    expect(screen.getByTestId('g7le-ds-ws-channel')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-ws-event')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-ws-channeltype')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-ws-target')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-ds-ws-onreceive')).toBeInTheDocument();
    // contentType 은 api 전용 — websocket 에선 부재.
    expect(screen.queryByTestId('g7le-ds-contenttype')).not.toBeInTheDocument();
  });

  it('종류 전환 시 무관 필드(__source) 보존', () => {
    const onChange = vi.fn();
    const raw = { data_sources: [{ id: 'products', type: 'api', endpoint: '/api/products', __source: { kind: 'route' } }] };
    render(<DataSourcesPanel raw={raw} onChange={onChange} t={t} onClose={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-data-sources-edit'));
    fireEvent.change(screen.getByTestId('g7le-data-sources-field-type'), { target: { value: 'static' } });
    fireEvent.click(screen.getByTestId('g7le-data-sources-form-submit'));
    const merged = onChange.mock.calls.at(-1)![0];
    expect(merged[0].__source).toEqual({ kind: 'route' });
    expect(merged[0].type).toBe('static');
  });
});
