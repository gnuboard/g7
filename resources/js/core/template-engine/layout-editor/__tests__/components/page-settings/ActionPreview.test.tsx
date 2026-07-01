// e2e:allow 액션 미리보기 단위(RTL) — 친화 요약/코드 토글, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ActionPreview.test.tsx — 액션 친화 요약 + 코드 보기 RTL
 *
 * 검증:
 *  ① 친화 요약 합성(스펙 라벨 + 입력값)
 *  ② 필수 누락 시 같은 자리 안내 전환
 *  ③ [</> 코드 보기] buildAction JSON 읽기전용(편집 핸들 부재)
 *  ④ 고급 보존 항목(recipe=null) 도 코드 열람
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ActionPreview } from '../../../components/page-settings/ActionPreview';

const t = (k: string) => k;

const toastRecipe = {
  label: '$t:안내 메시지',
  params: [{ key: 'message', label: '$t:메시지' }],
};

beforeEach(() => cleanup());

describe('ActionPreview', () => {
  it('친화 요약을 합성해 표시한다', () => {
    render(
      <ActionPreview
        action={{ handler: 'toast', params: { message: '안녕' } }}
        recipe={toastRecipe}
        values={{ message: '안녕' }}
        t={t}
      />,
    );
    const summary = screen.getByTestId('g7le-action-preview-summary');
    expect(summary.textContent).toContain('안내 메시지');
    expect(summary.textContent).toContain('안녕');
  });

  it('필수 누락 시 같은 자리에 안내로 전환한다', () => {
    render(
      <ActionPreview action={{ handler: 'toast' }} recipe={toastRecipe} values={{}} t={t} missingRequired />,
    );
    expect(screen.getByTestId('g7le-action-preview-missing')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-action-preview-summary')).not.toBeInTheDocument();
  });

  it('코드 보기 토글은 실제 JSON 을 읽기전용으로 펼친다', () => {
    render(
      <ActionPreview action={{ handler: 'toast', params: { message: 'hi' } }} recipe={toastRecipe} values={{}} t={t} />,
    );
    expect(screen.queryByTestId('g7le-action-preview-code')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-action-preview-code-toggle'));
    const code = screen.getByTestId('g7le-action-preview-code');
    expect(code.tagName).toBe('PRE');
    expect(code.textContent).toContain('"handler": "toast"');
  });

  it('고급 보존 항목(recipe=null)도 핸들러명 요약 + 코드 열람', () => {
    render(<ActionPreview action={{ handler: 'apiCall', onSuccess: [] }} recipe={null} t={t} />);
    expect(screen.getByTestId('g7le-action-preview-summary').textContent).toContain('apiCall');
    fireEvent.click(screen.getByTestId('g7le-action-preview-code-toggle'));
    expect(screen.getByTestId('g7le-action-preview-code').textContent).toContain('apiCall');
  });
});
