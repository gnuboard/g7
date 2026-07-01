// e2e:allow 코드별 에러 동작 행 단위(RTL) — badge/local 모드 분기, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ErrorHandlingRows.test.tsx — 코드별 에러 동작 행 RTL
 *
 * 검증:
 *  ① codes 로 행 생성(없으면 value 키)
 *  ② badge 모드 — sourceOf 출처 배지
 *  ③ local 모드 — 배지 부재(데이터소스 단순 편집)
 *  ④ default 행 경고
 *  ⑤ renderActionList 렌더 프롭 위임(동작별 입력)
 *  ⑥ clear → 코드 키 제거
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ErrorHandlingRows } from '../../../components/page-settings/ErrorHandlingRows';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('ErrorHandlingRows', () => {
  it('codes 로 행 생성 + badge 모드 출처 배지', () => {
    render(
      <ErrorHandlingRows
        value={{ '403': { handler: 'showErrorPage' } }}
        onChange={vi.fn()}
        t={t}
        codes={['403', '404', 'default']}
        mode="badge"
        sourceOf={(c) => (c === '403' ? 'self' : c === 'default' ? 'inherited' : 'none')}
      />,
    );
    expect(screen.getByTestId('g7le-error-rows-row-403')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-404')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-source-403')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-default-warn-default')).toBeInTheDocument();
  });

  it('상속/템플릿 출처 행 — 오버라이드 안내 + [되돌림]/[덮기] (W7 L2829)', () => {
    const onChange = vi.fn();
    render(
      <ErrorHandlingRows
        // 403=상속+자식값보유(되돌림), 500=템플릿+자식값없음(덮기), 404=자체(안내없음)
        value={{ '403': { handler: 'showErrorPage' }, '404': { handler: 'toast' } }}
        onChange={onChange}
        t={t}
        codes={['403', '404', '500']}
        mode="badge"
        sourceOf={(c) => (c === '403' ? 'inherited' : c === '500' ? 'template' : 'self')}
      />,
    );
    // 상속+자식값 → 오버라이드 안내 + [되돌림].
    expect(screen.getByTestId('g7le-error-rows-override-403')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-revert-403')).toBeInTheDocument();
    // 템플릿+자식값없음 → 오버라이드 안내 + [덮기].
    expect(screen.getByTestId('g7le-error-rows-override-500')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-override-here-500')).toBeInTheDocument();
    // 자체 출처(self) → 오버라이드 안내 부재.
    expect(screen.queryByTestId('g7le-error-rows-override-404')).not.toBeInTheDocument();
    // [되돌림] 클릭 → 자기 키 제거.
    fireEvent.click(screen.getByTestId('g7le-error-rows-revert-403'));
    expect(onChange).toHaveBeenLastCalledWith({ '404': { handler: 'toast' } });
    // [덮기] 클릭 → 빈 동작으로 override 시작(키 추가).
    fireEvent.click(screen.getByTestId('g7le-error-rows-override-here-500'));
    expect(onChange).toHaveBeenLastCalledWith({ '403': { handler: 'showErrorPage' }, '404': { handler: 'toast' }, '500': {} });
  });

  it('local 모드 — 출처 배지 부재(데이터소스 단순)', () => {
    render(<ErrorHandlingRows value={{ '500': { handler: 'toast' } }} onChange={vi.fn()} t={t} codes={['500']} mode="local" />);
    expect(screen.queryByTestId('g7le-error-rows-source-500')).not.toBeInTheDocument();
  });

  it('renderActionList 위임 — 동작별 입력 폼 렌더', () => {
    render(
      <ErrorHandlingRows
        value={{ '401': { handler: 'navigate' } }}
        onChange={vi.fn()}
        t={t}
        codes={['401']}
        renderActionList={(code) => <div data-testid={`custom-editor-${code}`}>editor</div>}
      />,
    );
    expect(screen.getByTestId('custom-editor-401')).toBeInTheDocument();
  });

  it('clear → 코드 키 제거', () => {
    const onChange = vi.fn();
    render(<ErrorHandlingRows value={{ '403': { handler: 'showErrorPage' } }} onChange={onChange} t={t} codes={['403']} />);
    fireEvent.click(screen.getByTestId('g7le-error-rows-clear-403'));
    expect(onChange).toHaveBeenCalledWith({});
  });

  it('codes 미지정 시 value 키로 행 생성', () => {
    render(<ErrorHandlingRows value={{ '404': { handler: 'toast' }, '500': { handler: 'toast' } }} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-error-rows-row-404')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-500')).toBeInTheDocument();
  });
});
