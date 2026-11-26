/**
 * LayoutEditorChrome — 편집기 스펙 묶음 요청 실패 알림과 다시 시도 (설계 확정 2026-11-09)
 *
 * `GET /api/templates/{id}/editor-spec-bundle` 이 실패하면(4xx, 5xx, 네트워크) 편집기가 아무 표시 없이
 * 상태 전환 토글과 스펙(팔레트·스타일 컨트롤)을 빈 채로 보여 주었다. 실패하면 편집기 안에 알림과
 * 다시 시도 단추를 보이고, 다시 시도하면 **묶음을** 다시 요청한다(개별 엔드포인트로 되돌아가지 않는다).
 *
 * 실물 LayoutEditorChrome 을 렌더하고 fetch 만 바꾼다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent, cleanup } from '@testing-library/react';
import { LayoutEditorChrome } from '../../LayoutEditorChrome';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

type BundleReply = 'ok' | 'http422' | 'http500' | 'network';

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function installFetch(replies: BundleReply[]): { bundleCalls: string[]; individualCalls: string[] } {
  const bundleCalls: string[] = [];
  const individualCalls: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('/editor-spec-bundle')) {
        const reply = replies[Math.min(bundleCalls.length, replies.length - 1)];
        bundleCalls.push(url);
        if (reply === 'network') throw new TypeError('Failed to fetch');
        if (reply === 'http422') return json(422, { success: false, message: 'invalid' });
        if (reply === 'http500') return json(500, { success: false, message: 'error' });
        return json(200, {
          success: true,
          data: {
            identifier: 'sirsoft-admin_basic',
            items: [
              {
                kind: 'template',
                identifier: 'sirsoft-admin_basic',
                status: 200,
                spec: { version: '1', controls: {} },
                error: null,
              },
            ],
          },
        });
      }
      if (/\/editor-spec(\?|$)/.test(url)) individualCalls.push(url);
      // 라우트 요청이 실패하면 셸 전체가 접근 오류 화면으로 바뀐다 — 이 시험은 스펙 알림만 본다
      if (url.includes('/editor/routes')) {
        return json(200, { success: true, data: { routes: [
              {
                path: '*/admin/users',
                layout: 'admin_user_list',
                source: { kind: 'template', identifier: 'sirsoft-admin_basic' },
              },
            ] } });
      }
      return json(404, { success: false });
    }),
  );
  return { bundleCalls, individualCalls };
}

function renderShell(): ReturnType<typeof render> {
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
      <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
    </TranslationProvider>,
  );
}

describe('LayoutEditorChrome — 편집기 스펙 묶음 실패 알림', () => {
  beforeEach(() => {
    (window as any).G7Config = { ...((window as any).G7Config ?? {}), activeModules: [], activePlugins: [] };
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  // @scenario case=spec_bundle_http4xx
  // @effects editor_spec_bundle_failure_shows_alert_with_retry, editor_spec_bundle_retry_requests_bundle_again
  it('4xx 로 실패하면 알림과 다시 시도 단추가 보이고, 다시 시도하면 묶음을 다시 요청해 알림이 사라진다', async () => {
    const calls = installFetch(['http422', 'ok']);
    renderShell();

    const alert = await screen.findByTestId('g7le-spec-load-error');
    expect(alert.getAttribute('role')).toBe('alert');
    expect(alert.textContent).toContain('layout_editor.chrome.spec_load_error.message');
    expect(calls.bundleCalls).toHaveLength(1);

    const retry = screen.getByTestId('g7le-spec-load-error-retry');
    expect(retry.tagName).toBe('BUTTON');
    expect(retry.textContent).toContain('layout_editor.chrome.spec_load_error.retry');
    fireEvent.click(retry);

    await waitFor(() => expect(screen.queryByTestId('g7le-spec-load-error')).toBeNull());
    expect(calls.bundleCalls).toHaveLength(2);
    // 개별 엔드포인트로 되돌아가지 않는다
    expect(calls.individualCalls).toHaveLength(0);
  });

  // @scenario case=spec_bundle_http5xx
  // @effects editor_spec_bundle_failure_shows_alert_with_retry
  it('5xx 는 한 번 다시 묻고도 실패하면 알림을 보인다', async () => {
    const calls = installFetch(['http500', 'http500', 'ok']);
    renderShell();
    await screen.findByTestId('g7le-spec-load-error', undefined, { timeout: 3000 });
    expect(calls.bundleCalls).toHaveLength(2);
  });

  // @scenario case=spec_bundle_network
  // @effects editor_spec_bundle_failure_shows_alert_with_retry
  it('네트워크 오류도 알림을 보이고, 다시 시도가 또 실패하면 알림이 남는다', async () => {
    const calls = installFetch(['network']);
    renderShell();
    await screen.findByTestId('g7le-spec-load-error', undefined, { timeout: 3000 });
    fireEvent.click(screen.getByTestId('g7le-spec-load-error-retry'));
    await waitFor(() => expect(calls.bundleCalls.length).toBe(4), { timeout: 3000 });
    expect(await screen.findByTestId('g7le-spec-load-error')).toBeTruthy();
  });

  // @scenario case=spec_bundle_none
  // @effects editor_spec_bundle_success_shows_no_alert
  it('성공하면 알림이 없다', async () => {
    const calls = installFetch(['ok']);
    renderShell();
    await waitFor(() => expect(calls.bundleCalls).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('g7le-spec-load-error')).toBeNull();
  });
});
