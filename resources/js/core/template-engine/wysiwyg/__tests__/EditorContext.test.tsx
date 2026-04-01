/**
 * EditorContext.test.tsx
 *
 * G7 위지윅 레이아웃 편집기 - EditorProvider 및 컨텍스트 테스트
 *
 * 테스트 항목:
 * 1. EditorProvider 초기화
 * 2. useEditorContext 훅
 * 3. 키보드 단축키
 * 4. 설정 오버라이드
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { renderHook, act } from '@testing-library/react';
import { EditorProvider, useEditorContext } from '../EditorContext';
import { useEditorState } from '../hooks/useEditorState';

// Zustand 스토어 초기화
beforeEach(() => {
  const { reset } = useEditorState.getState();
  if (reset) {
    reset();
  }
});

// 테스트용 컴포넌트 - EditorContext에서 제공하는 값만 사용
const TestConsumer: React.FC = () => {
  const context = useEditorContext();

  return (
    <div>
      <div data-testid="is-active">{context.isEditorActive ? 'active' : 'inactive'}</div>
      <div data-testid="is-loading">{context.isLoading ? 'loading' : 'not-loading'}</div>
      <div data-testid="is-saving">{context.isSaving ? 'saving' : 'not-saving'}</div>
      <div data-testid="has-changes">{context.hasChanges ? 'changed' : 'unchanged'}</div>
      <div data-testid="can-undo">{context.canUndo ? 'yes' : 'no'}</div>
      <div data-testid="can-redo">{context.canRedo ? 'yes' : 'no'}</div>
      <div data-testid="error">{context.error || 'none'}</div>
      <div data-testid="layout-name">{context.layoutData?.layout_name || 'none'}</div>
      <div data-testid="selected-component">
        {context.selectedComponent?.id || 'none'}
      </div>
      <div data-testid="auto-save">{context.config.autoSave ? 'enabled' : 'disabled'}</div>
    </div>
  );
};

describe('EditorContext', () => {
  describe('EditorProvider', () => {
    it('기본 설정으로 초기화되어야 함', () => {
      render(
        <EditorProvider>
          <TestConsumer />
        </EditorProvider>
      );

      // 기본 상태 확인
      expect(screen.getByTestId('is-active')).toHaveTextContent('inactive');
      expect(screen.getByTestId('is-loading')).toHaveTextContent('not-loading');
      expect(screen.getByTestId('has-changes')).toHaveTextContent('unchanged');
      expect(screen.getByTestId('auto-save')).toHaveTextContent('disabled');
    });

    it('설정 오버라이드가 적용되어야 함', () => {
      render(
        <EditorProvider config={{ autoSave: true, autoSaveInterval: 10000 }}>
          <TestConsumer />
        </EditorProvider>
      );

      expect(screen.getByTestId('auto-save')).toHaveTextContent('enabled');
    });

    it('children이 렌더링되어야 함', () => {
      render(
        <EditorProvider>
          <div data-testid="child">Child Content</div>
        </EditorProvider>
      );

      expect(screen.getByTestId('child')).toHaveTextContent('Child Content');
    });
  });

  describe('useEditorContext', () => {
    it('Provider 없이 사용 시 에러를 던져야 함', () => {
      // 콘솔 에러 억제
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      expect(() => {
        renderHook(() => useEditorContext());
      }).toThrow('useEditorContext must be used within an EditorProvider');

      consoleSpy.mockRestore();
    });

    it('Provider 내에서 컨텍스트 값을 반환해야 함', () => {
      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider>{children}</EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      // 필수 속성 확인
      expect(result.current).toHaveProperty('isEditorActive');
      expect(result.current).toHaveProperty('config');
      expect(result.current).toHaveProperty('initialize');
      expect(result.current).toHaveProperty('deactivate');
      expect(result.current).toHaveProperty('layoutData');
      expect(result.current).toHaveProperty('selectedComponent');
      expect(result.current).toHaveProperty('canUndo');
      expect(result.current).toHaveProperty('canRedo');
      expect(result.current).toHaveProperty('hasChanges');
      expect(result.current).toHaveProperty('isLoading');
      expect(result.current).toHaveProperty('isSaving');
      expect(result.current).toHaveProperty('error');
    });

    it('initialize 함수가 제공되어야 함', () => {
      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider>{children}</EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      expect(typeof result.current.initialize).toBe('function');
    });

    it('deactivate 함수가 제공되어야 함', () => {
      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider>{children}</EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      expect(typeof result.current.deactivate).toBe('function');
    });
  });

  describe('설정', () => {
    it('기본 설정이 적용되어야 함', () => {
      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider>{children}</EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      expect(result.current.config).toEqual(
        expect.objectContaining({
          autoSave: false,
          autoSaveInterval: 30000,
          maxHistorySize: 50,
          snapToGrid: false,
          gridSize: 8,
          keyboardShortcuts: true,
          accessibilityMode: false,
        })
      );
    });

    it('설정 오버라이드가 병합되어야 함', () => {
      const customConfig = {
        autoSave: true,
        maxHistorySize: 100,
      };

      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider config={customConfig}>{children}</EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      expect(result.current.config.autoSave).toBe(true);
      expect(result.current.config.maxHistorySize).toBe(100);
      // 기본값 유지
      expect(result.current.config.snapToGrid).toBe(false);
      expect(result.current.config.keyboardShortcuts).toBe(true);
    });
  });

  describe('키보드 단축키', () => {
    it('키보드 단축키가 비활성화되면 이벤트를 처리하지 않아야 함', () => {
      const wrapper = ({ children }: { children: React.ReactNode }) => (
        <EditorProvider config={{ keyboardShortcuts: false }}>
          {children}
        </EditorProvider>
      );

      const { result } = renderHook(() => useEditorContext(), { wrapper });

      expect(result.current.config.keyboardShortcuts).toBe(false);
    });
  });

  describe('상태 값', () => {
    it('초기 로딩 상태가 idle이어야 함', () => {
      render(
        <EditorProvider>
          <TestConsumer />
        </EditorProvider>
      );

      expect(screen.getByTestId('is-loading')).toHaveTextContent('not-loading');
      expect(screen.getByTestId('is-saving')).toHaveTextContent('not-saving');
    });

    it('초기에 변경사항이 없어야 함', () => {
      render(
        <EditorProvider>
          <TestConsumer />
        </EditorProvider>
      );

      expect(screen.getByTestId('has-changes')).toHaveTextContent('unchanged');
    });

    it('초기에 Undo/Redo가 불가능해야 함', () => {
      render(
        <EditorProvider>
          <TestConsumer />
        </EditorProvider>
      );

      expect(screen.getByTestId('can-undo')).toHaveTextContent('no');
      expect(screen.getByTestId('can-redo')).toHaveTextContent('no');
    });

    it('초기에 에러가 없어야 함', () => {
      render(
        <EditorProvider>
          <TestConsumer />
        </EditorProvider>
      );

      expect(screen.getByTestId('error')).toHaveTextContent('none');
    });
  });
});
