/**
 * ClassMapEditor.test.tsx
 *
 * classMap 편집기 컴포넌트 테스트
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ClassMapEditor, type ClassMapValue } from '../ClassMapEditor';

describe('ClassMapEditor', () => {
  let mockOnChange: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockOnChange = vi.fn();
  });

  describe('초기 렌더링', () => {
    it('값이 없으면 비활성화 상태로 렌더링되어야 함', () => {
      render(<ClassMapEditor value={undefined} onChange={mockOnChange} />);

      expect(screen.getByText('classMap')).toBeInTheDocument();
      expect(screen.getByText('추가')).toBeInTheDocument();
      expect(screen.queryByText('활성화')).not.toBeInTheDocument();
    });

    it('값이 있으면 활성화 배지가 표시되어야 함', () => {
      const value: ClassMapValue = {
        base: 'px-2 py-1',
        variants: { success: 'bg-green-100' },
        key: '{{status}}',
        default: 'bg-gray-100',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      expect(screen.getByText('활성화')).toBeInTheDocument();
    });

    it('값이 있으면 자동으로 확장되어 내용이 표시되어야 함', () => {
      const value: ClassMapValue = {
        base: 'px-2 py-1',
        variants: { success: 'bg-green-100' },
        key: '{{status}}',
        default: 'bg-gray-100',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // 확장된 상태이므로 내용이 보여야 함
      expect(screen.getByText('기본 클래스 (base)')).toBeInTheDocument();
      expect(screen.getByText('키 표현식 (key)')).toBeInTheDocument();
    });
  });

  describe('활성화/비활성화 토글', () => {
    it('추가 버튼 클릭 시 기본 구조가 생성되어야 함', async () => {
      const user = userEvent.setup();
      render(<ClassMapEditor value={undefined} onChange={mockOnChange} />);

      await user.click(screen.getByText('추가'));

      expect(mockOnChange).toHaveBeenCalledWith({
        base: '',
        variants: {},
        key: '',
        default: '',
      });
    });

    it('제거 버튼 클릭 시 undefined가 전달되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: 'px-2',
        variants: {},
        key: '{{status}}',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      await user.click(screen.getByText('제거'));

      expect(mockOnChange).toHaveBeenCalledWith(undefined);
    });
  });

  describe('base 클래스 편집', () => {
    it('base 입력 시 onChange가 호출되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: '',
        variants: {},
        key: '',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      // textarea를 placeholder로 찾기
      const baseInput = screen.getByPlaceholderText('px-2 py-1 rounded-full text-xs font-medium');
      await user.type(baseInput, 't');

      // 각 문자마다 onChange가 호출됨
      expect(mockOnChange).toHaveBeenCalled();
    });
  });

  describe('variant 관리', () => {
    it('variant 추가 버튼 클릭 시 새 variant가 생성되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: '',
        variants: {},
        key: '',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      await user.click(screen.getByText('+ 추가'));

      expect(mockOnChange).toHaveBeenCalledWith({
        base: '',
        variants: { variant_1: '' },
        key: '',
        default: '',
      });
    });

    it('variant 삭제 시 해당 variant가 제거되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: '',
        variants: { success: 'bg-green-100' },
        key: '',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('variant 삭제');
      await user.click(deleteButton);

      expect(mockOnChange).toHaveBeenCalledWith({
        base: '',
        variants: {},
        key: '',
        default: '',
      });
    });
  });

  describe('key 표현식 편집', () => {
    it('key 입력 시 {{}} 래핑이 자동으로 추가되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: '',
        variants: {},
        key: '',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      const keyInput = screen.getByPlaceholderText('row.status');
      await user.type(keyInput, 's');

      // {{s}}로 래핑되어 전달되어야 함
      expect(mockOnChange).toHaveBeenCalledWith(
        expect.objectContaining({
          key: expect.stringMatching(/\{\{.*\}\}/),
        })
      );
    });
  });

  describe('JSON 미리보기', () => {
    it('JSON 미리보기가 올바르게 표시되어야 함', () => {
      const value: ClassMapValue = {
        base: 'px-2 py-1',
        variants: { success: 'bg-green-100' },
        key: '{{row.status}}',
        default: 'bg-gray-100',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      expect(screen.getByText(/생성될 JSON 미리보기/)).toBeInTheDocument();
      expect(screen.getByText(/"base":/)).toBeInTheDocument();
      expect(screen.getByText(/"variants":/)).toBeInTheDocument();
    });
  });

  describe('패널 토글', () => {
    it('헤더 클릭 시 패널이 토글되어야 함', async () => {
      const user = userEvent.setup();
      const value: ClassMapValue = {
        base: 'px-2 py-1',
        variants: {},
        key: '',
        default: '',
      };

      render(<ClassMapEditor value={value} onChange={mockOnChange} />);

      // 초기에는 확장 상태
      expect(screen.getByText('기본 클래스 (base)')).toBeInTheDocument();

      // 헤더 클릭하여 접기
      await user.click(screen.getByText('classMap'));

      // 접힌 상태
      expect(screen.queryByText('기본 클래스 (base)')).not.toBeInTheDocument();

      // 다시 클릭하여 펼치기
      await user.click(screen.getByText('classMap'));

      // 다시 확장 상태
      expect(screen.getByText('기본 클래스 (base)')).toBeInTheDocument();
    });
  });
});
