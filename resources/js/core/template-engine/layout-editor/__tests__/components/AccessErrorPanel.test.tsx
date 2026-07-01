/**
 * AccessErrorPanel 테스트
 *
 * 다음 시나리오를 검증:
 * - kind 별 제목/아이콘/액션 버튼 분기 (401/403/404/5xx/network/unknown)
 * - 401 감지 시 AuthManager.getLoginRedirectUrl 호출 + setTimeout 후 location.href 변경
 * - 403 일 때 requiredPermissions 칩 노출
 * - 에러 메시지 details 영역에 원본 메시지 노출 (디버깅)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import type { EditorAccessError } from '../../types/editorErrors';
import { AuthManager } from '../../../../auth/AuthManager';

// useTranslation 을 vi.mock — TranslationProvider 없이도 키 자체를 텍스트로 반환.
vi.mock('../../../TranslationContext', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    translationEngine: null,
    translationContext: null,
  }),
}));

// 위 mock 보다 import 가 먼저 되도록 lazy import.
async function importPanel() {
  const mod = await import('../../components/AccessErrorPanel');
  return mod.AccessErrorPanel;
}

async function renderPanel(error: EditorAccessError) {
  const AccessErrorPanel = await importPanel();
  return render(<AccessErrorPanel error={error} />);
}

describe('AccessErrorPanel — kind 별 분기 렌더', () => {
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('401 unauthorized — 제목 + 로그인 버튼', async () => {
    await renderPanel({
      kind: 'unauthorized',
      status: 401,
      message: '로그인이 필요한 레이아웃입니다.',
      requiredPermissions: 'core.templates.layouts.edit',
    });
    expect(screen.getByTestId('g7le-access-error')).toHaveAttribute('data-error-kind', 'unauthorized');
    expect(screen.getByTestId('g7le-access-error-title')).toHaveTextContent(
      'layout_editor.access_error.unauthorized.title',
    );
    expect(screen.getByTestId('g7le-access-error-action-signin')).toBeInTheDocument();
  });

  it('403 forbidden — 권한 칩 + 홈으로 버튼', async () => {
    await renderPanel({
      kind: 'forbidden',
      status: 403,
      message: '권한이 없습니다',
      requiredPermissions: 'core.templates.layouts.edit',
    });
    expect(screen.getByTestId('g7le-access-error')).toHaveAttribute('data-error-kind', 'forbidden');
    expect(screen.getByTestId('g7le-access-error-permissions')).toHaveTextContent(
      'core.templates.layouts.edit',
    );
    expect(screen.getByTestId('g7le-access-error-action-home')).toBeInTheDocument();
  });

  it('404 not_found — 권한 칩 없음, 다시 시도 + 홈 버튼', async () => {
    await renderPanel({
      kind: 'not_found',
      status: 404,
      message: '레이아웃 없음',
    });
    expect(screen.getByTestId('g7le-access-error')).toHaveAttribute('data-error-kind', 'not_found');
    expect(screen.queryByTestId('g7le-access-error-permissions')).toBeNull();
    expect(screen.getByTestId('g7le-access-error-action-retry')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-access-error-action-home-secondary')).toBeInTheDocument();
  });

  it('5xx server_error — 다시 시도 가능', async () => {
    await renderPanel({ kind: 'server_error', status: 500, message: 'Internal Server Error' });
    expect(screen.getByTestId('g7le-access-error-action-retry')).toBeInTheDocument();
  });

  it('network — 다시 시도 가능 + 디버그 메시지 노출', async () => {
    await renderPanel({ kind: 'network', status: 0, message: 'fetch failed' });
    expect(screen.getByTestId('g7le-access-error-action-retry')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-access-error-detail')).toBeInTheDocument();
  });

  it('unknown — fallback 분기', async () => {
    await renderPanel({ kind: 'unknown', status: 418, message: "I'm a teapot" });
    expect(screen.getByTestId('g7le-access-error')).toHaveAttribute('data-error-kind', 'unknown');
  });
});

describe('AccessErrorPanel — 401 자동 redirect (AccessRedirectGate)', () => {
  let originalHref: string;
  let hrefSetter: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    originalHref = window.location.href;
    hrefSetter = vi.fn();
    // window.location.href setter 가로채기 — jsdom 은 기본적으로 navigation 미지원이라
    // defineProperty 로 setter 만 spy 한다.
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: new Proxy(window.location, {
        set(_target, prop, value) {
          if (prop === 'href') {
            hrefSetter(value);
            return true;
          }
          (_target as any)[prop] = value;
          return true;
        },
      }),
    });
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
    // location 복원은 jsdom 환경 한정이라 무시 — 다음 테스트는 새로 defineProperty.
  });

  it('401 마운트 → setTimeout 후 AuthManager.getLoginRedirectUrl 결과로 location 이동', async () => {
    const getLoginRedirectUrlSpy = vi
      .spyOn(AuthManager.getInstance(), 'getLoginRedirectUrl')
      .mockReturnValue('/admin/login?redirect=%2Fadmin%2Flayout-editor%2Fx&reason=session_expired');

    await renderPanel({ kind: 'unauthorized', status: 401, message: 'session expired' });

    // 마운트 직후에는 아직 redirect 발화 안 됨
    expect(hrefSetter).not.toHaveBeenCalled();

    // 600ms 후 redirect
    vi.advanceTimersByTime(700);

    expect(getLoginRedirectUrlSpy).toHaveBeenCalledWith(
      'admin',
      expect.any(String),
      'session_expired',
    );
    expect(hrefSetter).toHaveBeenCalledWith(
      '/admin/login?redirect=%2Fadmin%2Flayout-editor%2Fx&reason=session_expired',
    );
  });

  it('401 가 아닌 경우 redirect 발화하지 않음', async () => {
    await renderPanel({ kind: 'forbidden', status: 403, message: '권한 없음' });
    vi.advanceTimersByTime(1000);
    expect(hrefSetter).not.toHaveBeenCalled();
  });
});
