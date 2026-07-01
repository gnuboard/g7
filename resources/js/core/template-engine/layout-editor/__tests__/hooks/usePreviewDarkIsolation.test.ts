/**
 * usePreviewDarkIsolation 회귀 테스트
 *
 * 어드민 다크 환경의 `<html class="dark">` 가 편집기 프리뷰 콘텐츠(같은 이름의 Tailwind
 * `dark:` 유틸 사용)를 어드민 호스트 CSS 의 `.dark <desc>` 규칙으로 침범하는 것을 막기 위해,
 * 편집기 마운트 동안 `html.dark` 를 제거하고 언마운트 시 복원하는 hook 을 가드한다.
 *
 * Chrome MCP 실측에서: 어드민 테마 초기화가 편집기 React 마운트보다 늦게 `dark` 를 (재)부착해
 * 마운트 시점 단발 제거로는 격리가 깨졌다 → MutationObserver 로 생존 동안 계속 제거. 본 테스트는
 * (1) 마운트 시 제거 (2) 늦은 재부착도 제거 (3) 언마운트 시 복원 (4) 원래 dark 없으면 복원 안 함을 검증.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { usePreviewDarkIsolation } from '../../hooks/usePreviewDarkIsolation';

describe('usePreviewDarkIsolation', () => {
  beforeEach(() => {
    document.documentElement.classList.remove('dark');
  });
  afterEach(() => {
    document.documentElement.classList.remove('dark');
  });

  it('마운트 시 html.dark 를 제거하고 언마운트 시 복원한다 (원래 dark 였던 경우)', () => {
    document.documentElement.classList.add('dark');
    expect(document.documentElement.classList.contains('dark')).toBe(true);

    const { unmount } = renderHook(() => usePreviewDarkIsolation());

    // 마운트 직후 제거됨 (어드민 호스트 CSS 다크 cascade 차단)
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    // 언마운트 시 원래 상태(dark) 복원
    unmount();
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('마운트 후 늦게 재부착된 html.dark 도 제거한다 (MutationObserver, 어드민 테마 늦은 init)', async () => {
    // 마운트 시점엔 dark 없음 (어드민 테마 init 이 아직 안 됨)
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    const { unmount } = renderHook(() => usePreviewDarkIsolation());

    // 마운트 후 어드민 테마가 늦게 dark 부착 → observer 가 다시 제거해야 함
    document.documentElement.classList.add('dark');
    await waitFor(() => {
      expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    // 언마운트 — 그 사이 dark 가 한 번이라도 있었으므로 복원
    unmount();
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('원래 dark 가 아니었고 한 번도 부착 안 됐으면 언마운트 시 dark 를 추가하지 않는다', () => {
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    const { unmount } = renderHook(() => usePreviewDarkIsolation());
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    unmount();
    // 라이트 환경이었으면 복원 시에도 라이트 유지
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });
});
