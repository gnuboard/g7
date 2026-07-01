// e2e:allow 레이아웃 편집기 SEO 봇 미리보기 패널 — 실 HTML 육안은 Chrome MCP 매트릭스(세션 D), 응답 매핑·디바운스는 단위로 검증
/**
 * SeoBotPreviewPanel.test.tsx — 봇 HTML 실시간 미리보기 RTL
 *
 * 검증:
 *  ① 하단 접이식 기본 펼침
 *  ② 코드/렌더 토글
 *  ③ 미리보기 응답 완성 HTML 렌더(코드 pre)
 *  ④ 설정 변경(settingsSignature) → 디바운스 재호출(과다 호출 방지)
 *  ⑤ 수동 새로고침
 *  ⑥ enabled=false → "노출 안 됨"
 *  ⑦ "저장 전·샘플 데이터 기준" 안내
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, act, waitFor } from '@testing-library/react';
import { SeoBotPreviewPanel } from '../../../components/page-settings/SeoBotPreviewPanel';

const t = (k: string) => k;

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

function makeFetch(data: { enabled: boolean; html: string | null }): typeof fetch {
  return vi.fn(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ success: true, data: { identifier: 'sirsoft-basic', ...data } }),
  })) as unknown as typeof fetch;
}

const baseProps = {
  templateIdentifier: 'sirsoft-basic',
  layout: { meta: { seo: { enabled: true } }, components: [] },
  url: '/preview/test',
  locale: 'ko',
  t,
};

describe('SeoBotPreviewPanel', () => {
  it('① 기본 펼침 상태로 열린다(샘플 안내 노출)', async () => {
    const fetchImpl = makeFetch({ enabled: true, html: '<html><head><title>X</title></head></html>' });
    render(<SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={0} fetchImpl={fetchImpl} />);
    expect(screen.getByTestId('g7le-seo-bot-preview')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-bot-preview-sample-note')).toBeInTheDocument();
    // 펼침 → 미리보기 본문 노출.
    await waitFor(() => expect(screen.getByTestId('g7le-seo-bot-preview-body')).toBeInTheDocument());
  });

  it('③ 응답 HTML 을 코드 보기로 렌더한다', async () => {
    const fetchImpl = makeFetch({ enabled: true, html: '<html><head><title>봇</title><meta property="og:title" content="봇"></head></html>' });
    render(<SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={0} fetchImpl={fetchImpl} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-bot-preview-code')).toBeInTheDocument());
    expect(screen.getByTestId('g7le-seo-bot-preview-code').textContent).toContain('og:title');
  });

  it('② 렌더(iframe) 토글 제거 — HTML 코드만 표시', async () => {
    const fetchImpl = makeFetch({ enabled: true, html: '<html><body>본문</body></html>' });
    render(<SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={0} fetchImpl={fetchImpl} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-bot-preview-code')).toBeInTheDocument());
    // 코드/렌더 토글과 iframe 렌더 보기는 더 이상 존재하지 않는다.
    expect(screen.queryByTestId('g7le-seo-bot-preview-mode')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-bot-preview-mode-render')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-seo-bot-preview-render')).not.toBeInTheDocument();
  });

  it('⑥ enabled=false → "노출 안 됨" 안내(미리보기 본문 부재)', async () => {
    const fetchImpl = makeFetch({ enabled: false, html: null });
    render(<SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={0} fetchImpl={fetchImpl} />);
    await waitFor(() => expect(screen.getByTestId('g7le-seo-bot-preview-disabled')).toBeInTheDocument());
    expect(screen.queryByTestId('g7le-seo-bot-preview-body')).not.toBeInTheDocument();
  });

  it('⑤ 수동 새로고침 → 즉시 재호출', async () => {
    const fetchImpl = makeFetch({ enabled: true, html: '<html></html>' });
    render(<SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={0} fetchImpl={fetchImpl} />);
    await waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByTestId('g7le-seo-bot-preview-refresh'));
    await waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(2));
  });

  it('④ settingsSignature 연속 변경 → 디바운스로 1회만 호출(과다 호출 방지)', async () => {
    vi.useFakeTimers();
    const fetchImpl = makeFetch({ enabled: true, html: '<html></html>' });
    const { rerender } = render(
      <SeoBotPreviewPanel {...baseProps} settingsSignature="s1" debounceMs={300} fetchImpl={fetchImpl} />,
    );
    // 연속으로 시그니처 변경(디바운스 만료 전).
    rerender(<SeoBotPreviewPanel {...baseProps} settingsSignature="s2" debounceMs={300} fetchImpl={fetchImpl} />);
    rerender(<SeoBotPreviewPanel {...baseProps} settingsSignature="s3" debounceMs={300} fetchImpl={fetchImpl} />);
    expect(fetchImpl).not.toHaveBeenCalled();
    await act(async () => {
      vi.advanceTimersByTime(300);
    });
    // 마지막 시그니처 1회만.
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });
});
