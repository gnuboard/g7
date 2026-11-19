/**
 * LayoutEditorChrome — 검수 서버(실행 역할 stage)의 「검수용 저장」 안내 띠
 *
 * 검수 서버에서 시각 편집기로 저장한 레이아웃은 검수 서버 표(`stage_template_layouts`)에만 남고 운영 사이트에 반영되지
 * 않는다. 관리자 화면 상단 띠와 코드 편집 화면의 「검수용 저장」 안내는 관리자 템플릿 레이아웃이 그리는데, 시각 편집기
 * 셸은 셸 설정의 실행 역할(`G7Config.appConfig.runtime_role`)을 읽지 않아 이 화면에만 안내가 없었다.
 *
 * 실물 LayoutEditorChrome 을 렌더하고 fetch 만 바꾼다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import { LayoutEditorChrome } from '../../LayoutEditorChrome';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** 라우트·스펙 요청만 성공시키는 fetch — 라우트가 실패하면 셸 전체가 접근 오류 화면으로 바뀐다. */
function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('/editor/routes')) {
        return json(200, { success: true, data: { routes: [
          { path: '*/search', layout: 'search', source: { kind: 'template', identifier: 'sirsoft-basic' } },
        ] } });
      }
      if (url.includes('/editor-spec-bundle')) {
        return json(200, { success: true, data: { identifier: 'sirsoft-basic', items: [] } });
      }
      return json(404, { success: false });
    }),
  );
}

function renderShell(runtimeRole: string | undefined): ReturnType<typeof render> {
  (window as any).G7Config = {
    ...((window as any).G7Config ?? {}),
    activeModules: [],
    activePlugins: [],
    appConfig: runtimeRole === undefined ? {} : { runtime_role: runtimeRole },
  };
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
      <LayoutEditorChrome templateIdentifier="sirsoft-basic" initialLocale="ko" />
    </TranslationProvider>,
  );
}

describe('LayoutEditorChrome — 검수 서버 안내 띠', () => {
  beforeEach(() => installFetch());

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete (window as any).G7Config?.appConfig;
  });

  // @scenario case=stage_role
  // @effects editor_stage_banner_only_on_stage
  it('stage 면 편집기 머리에 「검수용 저장」 안내 띠를 보인다', async () => {
    renderShell('stage');
    const banner = await screen.findByTestId('g7le-stage-role-banner');
    expect(banner.getAttribute('role')).toBe('status');
    expect(banner.textContent).toContain('layout_editor.chrome.stage_banner');
  });

  // @scenario case=live_role
  // @effects editor_stage_banner_only_on_stage
  it.each([['live'], [undefined]])('역할이 %s 이면 띠가 없다', async (role) => {
    renderShell(role);
    await waitFor(() => expect(screen.getByTestId('g7le-chrome-device-toolbar')).toBeTruthy());
    expect(screen.queryByTestId('g7le-stage-role-banner')).toBeNull();
  });
});
