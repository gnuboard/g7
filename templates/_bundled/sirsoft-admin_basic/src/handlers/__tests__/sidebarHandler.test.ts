/**
 * sidebarHandler 테스트
 *
 * 데스크톱 사이드바 접힘 토글 + localStorage 영속 + 새로고침 복원을 검증한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { toggleSidebarHandler, initSidebarHandler, initSidebar } from '../sidebarHandler';

const STORAGE_KEY = 'g7_admin_sidebar_collapsed';

describe('sidebarHandler', () => {
  let store: Record<string, string> = {};
  let globalState: Record<string, any> = {};
  let setGlobalSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    store = {};
    globalState = {};

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation((k: string) => store[k] ?? null);
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation((k: string, v: string) => { store[k] = v; });

    setGlobalSpy = vi.fn((updates: Record<string, any>) => { Object.assign(globalState, updates); });
    (window as any).G7Core = {
      state: {
        setGlobal: setGlobalSpy,
        getGlobal: () => globalState,
      },
    };
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  describe('toggleSidebarHandler', () => {
    it('펼침(false) 상태에서 토글하면 접힘(true)이 되고 localStorage에 저장된다', async () => {
      globalState.sidebarCollapsed = false;
      await toggleSidebarHandler({}, {});
      expect(store[STORAGE_KEY]).toBe('1');
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: true });
    });

    it('접힘(true) 상태에서 토글하면 펼침(false)이 되고 localStorage에 저장된다', async () => {
      globalState.sidebarCollapsed = true;
      await toggleSidebarHandler({}, {});
      expect(store[STORAGE_KEY]).toBe('0');
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: false });
    });

    it('초기 상태가 없으면 펼침으로 간주하여 첫 토글에서 접힘이 된다', async () => {
      // globalState 비어있음 → undefined → false 취급
      await toggleSidebarHandler({}, {});
      expect(store[STORAGE_KEY]).toBe('1');
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: true });
    });
  });

  describe('initSidebar (새로고침 복원)', () => {
    it('localStorage에 접힘(1)이 저장돼 있으면 sidebarCollapsed=true로 복원한다', () => {
      store[STORAGE_KEY] = '1';
      initSidebar();
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: true });
    });

    it('localStorage에 펼침(0)이 저장돼 있으면 sidebarCollapsed=false로 복원한다', () => {
      store[STORAGE_KEY] = '0';
      initSidebar();
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: false });
    });

    it('localStorage에 값이 없으면 기본 펼침(false)으로 복원한다', () => {
      initSidebar();
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: false });
    });
  });

  describe('initSidebarHandler (액션 래퍼)', () => {
    it('저장된 접힘 상태를 복원한다', async () => {
      store[STORAGE_KEY] = '1';
      await initSidebarHandler({}, {});
      expect(setGlobalSpy).toHaveBeenCalledWith({ sidebarCollapsed: true });
    });
  });

  describe('토글 → 새로고침 → 복원 시나리오 (영속성)', () => {
    it('접은 뒤 initSidebar로 복원하면 접힘 상태가 유지된다', async () => {
      globalState.sidebarCollapsed = false;
      await toggleSidebarHandler({}, {}); // 접음 → store '1'
      // 새로고침 시뮬레이션: globalState 초기화
      globalState = {};
      (window as any).G7Core.state.getGlobal = () => globalState;
      initSidebar();
      expect(setGlobalSpy).toHaveBeenLastCalledWith({ sidebarCollapsed: true });
    });
  });
});
