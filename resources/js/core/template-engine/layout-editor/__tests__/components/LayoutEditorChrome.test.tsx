/**
 * LayoutEditorChrome 셸 테스트
 *
 * 검증:
 *  - 셸이 같은 React tree 안에 마운트됨 (Provider 트리 안에서 동작)
 *  - g7le- CSS 프리픽스 강제 (19.3 격리)
 *  - 툴바 + 디바이스 미리보기 툴바 + 라우트 트리 패널 + 프리뷰 캔버스 통합
 *  - 대화면 전용 최소 너비(좁은 창에서 압착 대신 가로 스크롤)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { LayoutEditorChrome, EDITOR_MIN_WIDTH } from '../../LayoutEditorChrome';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function renderShell(): ReturnType<typeof render> {
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
    </TranslationProvider>,
  );
}

describe('LayoutEditorChrome', () => {
  it('셸 + 툴바 + 디바이스 미리보기 툴바 + 라우트 트리 + 프리뷰 캔버스 모두 마운트', () => {
    renderShell();
    expect(screen.getByTestId('g7le-chrome')).toBeTruthy();
    expect(screen.getByTestId('g7le-toolbar')).toBeTruthy();
    expect(screen.getByTestId('g7le-chrome-device-toolbar')).toBeTruthy();
    expect(screen.getByTestId('g7le-device-toolbar')).toBeTruthy();
    expect(screen.getByTestId('g7le-route-tree-panel')).toBeTruthy();
    // 라우트 미선택 상태 — PreviewCanvas 의 empty placeholder 가 렌더됨
    expect(screen.getByTestId('g7le-preview-empty')).toBeTruthy();
  });

  it('셸 클래스에 g7le- 프리픽스 강제 (CSS 격리 — 19.3)', () => {
    renderShell();
    const chrome = screen.getByTestId('g7le-chrome');
    expect(chrome.className).toContain('g7le-');
    const toolbar = screen.getByTestId('g7le-toolbar');
    expect(toolbar.className).toContain('g7le-');
    const panel = screen.getByTestId('g7le-route-tree-panel');
    expect(panel.className).toContain('g7le-');
  });

  it('툴바가 useTranslation 훅을 통해 $t: 키를 전달받음 (자동 해석 통합 경로)', () => {
    // 본 단위 테스트에서는 translation dictionary 가 비어있어 키가 fallback 으로
    // 그대로 반환되지만, 그 자체가 useTranslation 훅을 통해 t() 함수로 흐른
    // 경로를 검증한다. 실제 dictionary 적재 + 해석은 e2e/통합 테스트에서 검증.
    renderShell();
    const toolbar = screen.getByTestId('g7le-toolbar');
    // 적어도 layout_editor.* 키 공간이 사용됨을 증명 (다른 키 공간 오염 차단)
    expect(toolbar.innerHTML).toContain('layout_editor.chrome.toolbar.');
  });

  it('템플릿 식별자 라벨 표시', () => {
    renderShell();
    expect(screen.getByTestId('g7le-toolbar-template-label').textContent).toBe('sirsoft-admin_basic');
  });
});

describe('LayoutEditorChrome — 대화면 전용 최소 너비 (좁은 창 압착 차단)', () => {
  // 회귀 배경: 셸에 최소 너비가 없어 좁은 창에서 툴바 버튼이 flex-shrink 로 깎이고
  // 라벨이 글자 단위로 줄바꿈됐다("요 소 추 가"). 편집기는 대화면 전용 도구이므로
  // 반응형 재배치 대신 최소 너비를 유지하고 모자란 폭은 브라우저 가로 스크롤로 흡수한다.

  it('셸이 EDITOR_MIN_WIDTH 최소 너비를 갖는다 (압착 대신 가로 스크롤)', () => {
    renderShell();
    const chrome = screen.getByTestId('g7le-chrome') as HTMLElement;
    expect(chrome.style.minWidth).toBe(`${EDITOR_MIN_WIDTH}px`);
  });

  it('툴바 직접 자식 전체가 축소·줄바꿈되지 않는다 (flex-shrink:0 + nowrap)', () => {
    // 버튼마다 인라인 style 로 붙이면 자체 style 을 가진 하위 컴포넌트
    // (TemplateSwitcher/LocaleSwitcher)와 이후 추가 항목이 빠져 결함이 재발한다.
    // 직접 자식 전체를 덮는 CSS 규칙이어야 한다 — 그 규칙의 존재를 잠근다.
    renderShell();
    const toolbar = screen.getByTestId('g7le-toolbar');
    expect(toolbar.children.length).toBeGreaterThan(1);
    const styleEl = toolbar.querySelector('style');
    expect(styleEl?.textContent).toContain('.g7le-toolbar > *');
    expect(styleEl?.textContent).toContain('flex-shrink: 0');
    expect(styleEl?.textContent).toContain('white-space: nowrap');
  });
});

describe('LayoutEditorChrome — 템플릿 메타 fetch 인증 헤더', () => {
  // 회귀 배경: admin 템플릿 API 는 Sanctum 토큰 인증이라, 메타/목록 fetch 가
  // Authorization Bearer 토큰을 누락하면 401 → name/version 미수신 → 식별자만 노출
  // 본 테스트는 두 fetch 가 Bearer 헤더를 첨부하고,
  // 응답의 name/version 이 툴바에 반영됨을 잠근다.
  let originalFetch: typeof globalThis.fetch | undefined;

  beforeEach(() => {
    originalFetch = globalThis.fetch;
    try {
      window.localStorage.setItem('auth_token', 'test-token-abc');
    } catch {
      /* noop */
    }
  });

  afterEach(() => {
    if (originalFetch) globalThis.fetch = originalFetch;
    try {
      window.localStorage.removeItem('auth_token');
    } catch {
      /* noop */
    }
    vi.restoreAllMocks();
  });

  it('메타 fetch 는 Bearer 토큰 첨부 + name/version 을 툴바에 반영', async () => {
    const calls: Array<{ url: string; auth: string | null }> = [];
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === 'string' ? input : String(input);
      const headers = (init?.headers ?? {}) as Record<string, string>;
      calls.push({ url, auth: headers.Authorization ?? null });

      // show 엔드포인트 — name/version/type
      if (/\/api\/admin\/templates\/sirsoft-admin_basic$/.test(url)) {
        return new Response(
          JSON.stringify({ data: { identifier: 'sirsoft-admin_basic', name: 'Admin Basic', version: '1.0.0-beta.8', type: 'admin' } }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        );
      }
      // index 엔드포인트 — 전환 목록
      if (/\/api\/admin\/templates\/\?/.test(url)) {
        return new Response(
          JSON.stringify({ data: { data: [
            { identifier: 'sirsoft-admin_basic', name: 'Admin Basic', version: '1.0.0-beta.8', type: 'admin', is_pending: false },
            { identifier: 'sirsoft-basic', name: 'Basic', version: '1.0.0-beta.6', type: 'user', is_pending: false },
          ] } }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        );
      }
      // routes.json — 유효 routes 응답(없으면 useEditorRoutes 가 routesError 셋팅 → 셸이
      // AccessErrorPanel 로 분기해 툴바 미마운트). 최소 1개 라우트로 정상 분기 유도.
      if (/\/editor\/routes\.json/.test(url)) {
        return new Response(
          JSON.stringify({ success: true, data: { routes: [
            { path: '/', layout: 'home', source: { kind: 'template', identifier: null }, meta: { title: '홈' } },
          ] } }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        );
      }
      // 그 외(components.json, editor-spec 등)는 빈 200
      return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
    }) as unknown as typeof globalThis.fetch;

    const engine = new TranslationEngine();
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
      </TranslationProvider>,
    );

    // name/version 이 비동기 fetch 후 툴바에 반영될 때까지 대기
    await waitFor(() => {
      expect(screen.getByTestId('g7le-toolbar-template-name').textContent).toBe('Admin Basic');
    });
    expect(screen.getByTestId('g7le-toolbar-template-version').textContent).toBe('v1.0.0-beta.8');

    // show + index fetch 가 모두 Bearer 토큰을 첨부했는지 검증
    const showCall = calls.find((c) => /\/api\/admin\/templates\/sirsoft-admin_basic$/.test(c.url));
    const indexCall = calls.find((c) => /\/api\/admin\/templates\/\?/.test(c.url));
    expect(showCall?.auth).toBe('Bearer test-token-abc');
    expect(indexCall?.auth).toBe('Bearer test-token-abc');
  });
});
