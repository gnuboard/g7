/**
 * SwitchExpressionEditor.test.tsx
 *
 * $switch 표현식 편집기 컴포넌트 테스트
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SwitchExpressionEditor, type SwitchExpressionValue } from '../SwitchExpressionEditor';

describe('SwitchExpressionEditor', () => {
  let mockOnChange: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockOnChange = vi.fn();
  });

  describe('초기 렌더링', () => {
    it('값이 없으면 기본 상태로 렌더링되어야 함', () => {
      render(
        <SwitchExpressionEditor value={undefined} onChange={mockOnChange} />
      );

      expect(screen.getByText('$switch 표현식')).toBeInTheDocument();
    });

    it('문자열 값이면 단순 값 모드로 렌더링되어야 함', () => {
      render(
        <SwitchExpressionEditor value="simple-value" onChange={mockOnChange} />
      );

      expect(screen.getByText('$switch 표현식')).toBeInTheDocument();
    });

    it('$switch 객체면 $switch 배지가 표시되어야 함', () => {
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: { active: 'green', inactive: 'gray' },
        $default: 'blue',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      expect(screen.getByText('$switch')).toBeInTheDocument();
    });

    it('$switch 객체면 자동으로 확장되어 내용이 표시되어야 함', () => {
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: { active: 'green' },
        $default: 'blue',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // 확장된 상태이므로 내용이 보여야 함
      expect(screen.getByText(/Switch 키/)).toBeInTheDocument();
      expect(screen.getByText(/Cases/)).toBeInTheDocument();
    });
  });

  describe('모드 전환', () => {
    it('$switch 표현식 버튼 클릭 시 $switch 구조가 생성되어야 함', async () => {
      const user = userEvent.setup();
      render(
        <SwitchExpressionEditor value="simple" onChange={mockOnChange} />
      );

      // 패널 확장 (헤더 클릭)
      await user.click(screen.getByText('$switch 표현식'));

      // $switch 표현식 버튼 클릭 (두 번째 - 모드 전환 버튼)
      const switchButtons = screen.getAllByRole('button');
      const switchModeButton = switchButtons.find(btn => btn.textContent === '$switch 표현식');
      if (switchModeButton) {
        await user.click(switchModeButton);
      }

      expect(mockOnChange).toHaveBeenCalledWith({
        $switch: '{{status}}',
        $cases: {},
        $default: 'simple',
      });
    });

    it('단순 값 버튼 클릭 시 $default 값으로 변환되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: {},
        $default: 'default-value',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      // 단순 값 버튼 클릭
      await user.click(screen.getByText('단순 값'));

      expect(mockOnChange).toHaveBeenCalledWith('default-value');
    });
  });

  describe('case 관리', () => {
    it('case 추가 버튼 클릭 시 새 case가 생성되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: {},
        $default: 'default',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      // + 추가 버튼 클릭
      await user.click(screen.getByText('+ 추가'));

      expect(mockOnChange).toHaveBeenCalledWith({
        $switch: '{{status}}',
        $cases: { case_1: '' },
        $default: 'default',
      });
    });

    it('case 삭제 시 해당 case가 제거되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: { active: 'green' },
        $default: 'default',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      // 삭제 버튼 클릭
      const deleteButton = screen.getByTitle('case 삭제');
      await user.click(deleteButton);

      expect(mockOnChange).toHaveBeenCalledWith({
        $switch: '{{status}}',
        $cases: {},
        $default: 'default',
      });
    });
  });

  describe('$switch 키 편집', () => {
    it('$switch 키 입력 시 {{}} 래핑이 자동으로 추가되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '',
        $cases: {},
        $default: '',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      const switchInput = screen.getByPlaceholderText('status');
      await user.type(switchInput, 't');

      // {{t}}로 래핑되어 전달되어야 함
      expect(mockOnChange).toHaveBeenCalledWith(
        expect.objectContaining({
          $switch: expect.stringMatching(/\{\{.*\}\}/),
        })
      );
    });
  });

  describe('$default 편집', () => {
    it('$default 값 변경 시 onChange가 호출되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: {},
        $default: '',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      const defaultInput = screen.getByPlaceholderText('기본 반환 값');
      await user.type(defaultInput, 'd');

      expect(mockOnChange).toHaveBeenCalled();
      const lastCall = mockOnChange.mock.calls[mockOnChange.mock.calls.length - 1][0];
      expect(lastCall.$default).toBeDefined();
    });
  });

  describe('JSON 미리보기', () => {
    it('$switch 모드에서 JSON 미리보기가 표시되어야 함', () => {
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: { active: 'green' },
        $default: 'gray',
      };

      render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // $switch 객체면 자동으로 확장됨
      expect(screen.getByText(/생성될 JSON 미리보기/)).toBeInTheDocument();
      expect(screen.getByText(/"\$switch"/)).toBeInTheDocument();
      expect(screen.getByText(/"\$cases"/)).toBeInTheDocument();
    });
  });

  describe('패널 토글', () => {
    it('헤더 클릭 시 패널이 토글되어야 함', async () => {
      const user = userEvent.setup();
      const value: SwitchExpressionValue = {
        $switch: '{{status}}',
        $cases: { active: 'green' },
        $default: 'gray',
      };

      const { container } = render(
        <SwitchExpressionEditor value={value} onChange={mockOnChange} />
      );

      // 초기에는 확장 상태
      expect(screen.getByText(/Switch 키/)).toBeInTheDocument();

      // 헤더 영역 클릭하여 접기 (label 텍스트가 있는 span의 부모)
      const header = container.querySelector('.bg-gray-50.dark\\:bg-gray-800.cursor-pointer');
      expect(header).not.toBeNull();
      await user.click(header!);

      // 접힌 상태
      expect(screen.queryByText(/Switch 키/)).not.toBeInTheDocument();

      // 다시 클릭하여 펼치기
      await user.click(header!);

      // 다시 확장 상태
      expect(screen.getByText(/Switch 키/)).toBeInTheDocument();
    });
  });
});
