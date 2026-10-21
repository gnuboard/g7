import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach, type MockInstance } from 'vitest';
import { CodeWorkspace } from '../CodeWorkspace';
import { isPlainLeftClick as engineIsPlainLeftClick } from '@core/template-engine/navigationLinks';

/**
 * CodeWorkspace — SPA 이탈 가드와 링크 새 탭 열기 (D5 · #757 링크 단계)
 *
 * 미저장 편집이 있을 때 `data-leave-guard` 요소를 누르면 되묻는다. 그러나 링크를 Ctrl·Meta·Shift 로 누르거나
 * `target="_blank"` 링크를 누르면 새 탭·새 창에서 열리고 이 화면은 그대로다 — 물을 일도, 막을 일도 없다.
 * 「보통 클릭인가」 판정은 엔진 `G7Core.navigation.isPlainLeftClick` 의 실제 함수로 한다(링크 요소의 target·download 포함).
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */

vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, onChange }: any) => (
    <textarea data-testid="editor-textarea" value={value ?? ''} onChange={(event) => onChange?.(event.target.value)} />
  ),
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

let confirmSpy: MockInstance<typeof window.confirm>;

beforeEach(() => {
  window.localStorage.clear();
  (window as any).G7Core = {
    t: (key: string) => key,
    AuthManager: { getInstance: () => ({ getUser: () => ({ uuid: 'admin-test' }) }) },
    navigation: { isPlainLeftClick: engineIsPlainLeftClick },
  };
  confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
});

afterEach(() => {
  confirmSpy.mockRestore();
  delete (window as any).G7Core;
});

/** 편집기에 한 글자를 넣어 미저장 상태를 만들고, 가드가 붙은 링크 두 개를 함께 그린다 */
const renderDirty = () => {
  render(
    <>
      <a href="/admin/dashboard" data-leave-guard="true">대시보드</a>
      <a href="/admin/help" target="_blank" data-leave-guard="true">도움말</a>
      <CodeWorkspace fileKey="layout:a" serverValue="<layout/>" serverRevision={1} draftNamespace="g7le.leaveGuard.test" />
    </>,
  );
  fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });
};

/** 클릭 이벤트를 보내고 기본 동작이 막혔는지 돌려준다 */
const click = (el: Element, init: MouseEventInit = {}): boolean => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  el.dispatchEvent(ev);

  return ev.defaultPrevented;
};

describe('CodeWorkspace — 이탈 가드와 링크', () => {
  // @scenario component=code_workspace
  // @effects code_workspace_leave_guard_confirms_plain_link_click
  it('미저장 상태에서 가드 링크를 보통 클릭하면 되묻고, 취소하면 이동을 막는다', () => {
    renderDirty();
    expect(click(screen.getByRole('link', { name: '대시보드' }))).toBe(true);
    expect(confirmSpy).toHaveBeenCalledTimes(1);
  });

  // @scenario component=code_workspace
  // @effects code_workspace_leave_guard_passes_modified_link_click
  it('Ctrl·Meta·Shift 클릭은 새 탭·새 창에서 열리므로 묻지도 막지도 않는다', () => {
    renderDirty();
    const link = screen.getByRole('link', { name: '대시보드' });
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
      expect(click(link, init)).toBe(false);
    }
    expect(confirmSpy).not.toHaveBeenCalled();
  });

  // @scenario component=code_workspace
  // @effects code_workspace_leave_guard_passes_target_blank_link
  it('target="_blank" 링크는 보통 클릭이어도 새 탭에서 열리므로 묻지도 막지도 않는다', () => {
    renderDirty();
    expect(click(screen.getByRole('link', { name: '도움말' }))).toBe(false);
    expect(confirmSpy).not.toHaveBeenCalled();
  });
});
