import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput, TagOption } from '../TagInput';

const mockOptions: TagOption[] = [
  { value: 'as', label: 'A/S', count: 5 },
  { value: 'return', label: '반품', count: 0 },
  { value: 'exchange', label: '교환', count: 3 },
  { value: 'delivery', label: '배송', count: 12 },
];

const roleOptions: TagOption[] = [
  { value: '1', label: '회원' },
  { value: '2', label: '콘텐츠 관리자' },
  { value: '3', label: '마케팅 담당자' },
  { value: '4', label: '운영자' },
];

describe('TagInput 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('컴포넌트가 렌더링된다', () => {
      const { container } = render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={() => {}}
        />
      );
      expect(container.querySelector('.tag-input__control')).toBeTruthy();
    });

    it('placeholder가 표시된다', () => {
      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={() => {}}
          placeholder="분류 검색..."
        />
      );
      expect(screen.getByText('분류 검색...')).toBeTruthy();
    });

    it('선택된 값이 태그로 표시된다', () => {
      render(
        <TagInput
          value={['as', 'return']}
          options={mockOptions}
          onChange={() => {}}
        />
      );
      expect(screen.getByText('A/S')).toBeTruthy();
      expect(screen.getByText('반품')).toBeTruthy();
    });

    it('count가 있는 옵션은 count가 표시된다', () => {
      render(
        <TagInput
          value={['as']}
          options={mockOptions}
          onChange={() => {}}
        />
      );
      expect(screen.getByText('(5)')).toBeTruthy();
    });
  });

  describe('비활성화 상태', () => {
    it('disabled prop이 적용된다', () => {
      const { container } = render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={() => {}}
          disabled={true}
        />
      );
      const control = container.querySelector('.tag-input__control');
      expect(control?.className).toContain('tag-input__control--is-disabled');
    });
  });

  describe('onChange 콜백', () => {
    it('옵션 선택 시 onChange가 호출된다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
        />
      );

      // input에 포커스
      const input = screen.getByRole('combobox');
      await user.click(input);

      // 옵션 선택
      const option = await screen.findByText('A/S');
      await user.click(option);

      // TagInput은 템플릿 엔진용 가짜 이벤트 객체를 전달함
      expect(handleChange).toHaveBeenCalled();
      const callArg = handleChange.mock.calls[0][0];
      expect(callArg.target).toEqual({ value: ['as'] });
      expect(callArg.currentTarget).toEqual({ value: ['as'] });
      expect(typeof callArg.preventDefault).toBe('function');
      expect(typeof callArg.stopPropagation).toBe('function');
    });
  });

  describe('onBeforeRemove 콜백', () => {
    it('onBeforeRemove가 true를 반환하면 삭제된다', async () => {
      const handleChange = vi.fn();
      const handleBeforeRemove = vi.fn().mockReturnValue(true);
      const user = userEvent.setup();

      render(
        <TagInput
          value={['return']}
          options={mockOptions}
          onChange={handleChange}
          onBeforeRemove={handleBeforeRemove}
        />
      );

      // 삭제 버튼 클릭
      const removeButton = screen.getByRole('button', { name: /remove/i });
      await user.click(removeButton);

      expect(handleBeforeRemove).toHaveBeenCalled();
      // TagInput은 템플릿 엔진용 가짜 이벤트 객체를 전달함
      expect(handleChange).toHaveBeenCalled();
      const callArg = handleChange.mock.calls[0][0];
      expect(callArg.target).toEqual({ value: [] });
      expect(callArg.currentTarget).toEqual({ value: [] });
      expect(typeof callArg.preventDefault).toBe('function');
      expect(typeof callArg.stopPropagation).toBe('function');
    });

    it('onBeforeRemove가 string을 반환하면 삭제되지 않고 alert 표시', async () => {
      const handleChange = vi.fn();
      const handleBeforeRemove = vi.fn().mockReturnValue('삭제할 수 없습니다.');
      // happy-dom에서 window.alert가 undefined일 수 있으므로 직접 정의
      const originalAlert = window.alert;
      window.alert = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={['as']}
          options={mockOptions}
          onChange={handleChange}
          onBeforeRemove={handleBeforeRemove}
        />
      );

      // 삭제 버튼 클릭
      const removeButton = screen.getByRole('button', { name: /remove/i });
      await user.click(removeButton);

      expect(handleBeforeRemove).toHaveBeenCalled();
      expect(window.alert).toHaveBeenCalledWith('삭제할 수 없습니다.');
      expect(handleChange).not.toHaveBeenCalled();

      window.alert = originalAlert;
    });
  });

  describe('Creatable 모드', () => {
    it('creatable 모드에서 새 옵션을 생성할 수 있다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          creatable={true}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '새분류');

      // 새 옵션 생성 버튼이 표시되는지 확인
      // formatCreateLabel 기본 동작: <strong>{inputValue}</strong> {createLabelSuffix}
      await waitFor(() => {
        expect(screen.getByText('새분류')).toBeTruthy();
        expect(screen.getByText('추가')).toBeTruthy();
      });
    });

    it('onCreateOption 콜백이 호출된다', async () => {
      const handleChange = vi.fn();
      const handleCreate = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          creatable={true}
          onCreateOption={handleCreate}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '새분류');

      // 새 옵션 생성 클릭 - strong 태그 안에 텍스트가 있으므로 getByText로 찾음
      const createOption = await screen.findByText('추가');
      await user.click(createOption);

      expect(handleCreate).toHaveBeenCalledWith('새분류');
      // TagInput은 템플릿 엔진용 가짜 이벤트 객체를 전달함
      expect(handleChange).toHaveBeenCalled();
      const callArg = handleChange.mock.calls[0][0];
      expect(callArg.target).toEqual({ value: ['새분류'] });
      expect(callArg.currentTarget).toEqual({ value: ['새분류'] });
      expect(typeof callArg.preventDefault).toBe('function');
      expect(typeof callArg.stopPropagation).toBe('function');
    });
  });

  describe('Select Only 모드 (역할)', () => {
    it('creatable=false일 때 새 옵션 생성 불가', async () => {
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={roleOptions}
          onChange={() => {}}
          creatable={false}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '새역할');

      // 새 옵션 생성 버튼이 없어야 함
      await waitFor(() => {
        expect(screen.queryByText(/추가/)).toBeNull();
      });
    });
  });

  describe('maxItems 제한', () => {
    it('maxItems 이상 선택 불가', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={['as', 'return']}
          options={mockOptions}
          onChange={handleChange}
          maxItems={2}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // 세 번째 옵션 선택 시도
      const option = await screen.findByText('교환');
      await user.click(option);

      // onChange가 호출되지 않아야 함 (이미 2개 선택됨)
      expect(handleChange).not.toHaveBeenCalled();
    });
  });

  describe('검색 필터링', () => {
    it('입력 시 옵션이 필터링된다', async () => {
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={() => {}}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);
      await user.type(input, 'A/S');

      // A/S만 표시되어야 함
      await waitFor(() => {
        expect(screen.getByText('A/S')).toBeTruthy();
        expect(screen.queryByText('반품')).toBeNull();
      });
    });
  });

  describe('접근성', () => {
    it('combobox role이 있다', () => {
      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={() => {}}
        />
      );
      expect(screen.getByRole('combobox')).toBeTruthy();
    });
  });

  describe('구분자 기반 자동 태그 분리', () => {
    it('쉼표 입력 시 자동으로 태그가 분리된다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={[]}
          onChange={handleChange}
          creatable={true}
          delimiters={[',']}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '블루,레드,');

      // 쉼표 입력 시 자동으로 태그가 생성되어야 함
      await waitFor(() => {
        expect(handleChange).toHaveBeenCalled();
        const lastCall = handleChange.mock.calls[handleChange.mock.calls.length - 1][0];
        expect(lastCall.target.value).toContain('블루');
      });
    });

    it('커스텀 구분자로 태그가 분리된다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={[]}
          onChange={handleChange}
          creatable={true}
          delimiters={[',', ';']}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '옵션1;');

      await waitFor(() => {
        expect(handleChange).toHaveBeenCalled();
        const lastCall = handleChange.mock.calls[handleChange.mock.calls.length - 1][0];
        expect(lastCall.target.value).toContain('옵션1');
      });
    });

    it('중복 태그는 추가되지 않는다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={['블루']}
          options={[]}
          onChange={handleChange}
          creatable={true}
          delimiters={[',']}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '블루,');

      // 약간의 지연 후에도 onChange가 호출되지 않아야 함 (중복)
      await new Promise(resolve => setTimeout(resolve, 100));

      // 중복이므로 onChange가 호출되지 않거나, 호출되어도 값이 변하지 않아야 함
      if (handleChange.mock.calls.length > 0) {
        const lastCall = handleChange.mock.calls[handleChange.mock.calls.length - 1][0];
        // 중복 추가 시 배열에 블루가 1개만 있어야 함
        expect(lastCall.target.value.filter((v: string) => v === '블루').length).toBe(1);
      }
    });

    it('creatable=false일 때는 구분자가 동작하지 않는다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          creatable={false}
          delimiters={[',']}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '새항목,');

      // creatable=false이므로 구분자로 태그 생성 불가
      await new Promise(resolve => setTimeout(resolve, 100));
      expect(handleChange).not.toHaveBeenCalled();
    });

    it('maxItems 제한이 구분자 입력에도 적용된다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={['기존값']}
          options={[]}
          onChange={handleChange}
          creatable={true}
          delimiters={[',']}
          maxItems={2}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '새값1,새값2,새값3,');

      await waitFor(() => {
        expect(handleChange).toHaveBeenCalled();
        const lastCall = handleChange.mock.calls[handleChange.mock.calls.length - 1][0];
        // maxItems=2이고 기존값이 1개이므로 1개만 추가 가능
        expect(lastCall.target.value.length).toBeLessThanOrEqual(2);
      });
    });
  });

  describe('options prop 선택적 사용', () => {
    it('options 없이도 creatable 모드로 동작한다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          onChange={handleChange}
          creatable={true}
        />
      );

      const input = screen.getByRole('combobox');
      await user.type(input, '자유입력');

      // 새 옵션 생성 가능해야 함
      await waitFor(() => {
        expect(screen.getByText('자유입력')).toBeTruthy();
        expect(screen.getByText('추가')).toBeTruthy();
      });
    });
  });

  describe('그룹핑 기능', () => {
    const groupedOptions: TagOption[] = [
      { value: '1', label: '전체', group: '시스템 역할', description: '모든 사용자 (비회원 포함)' },
      { value: '2', label: '회원', group: '시스템 역할', description: '로그인한 모든 회원' },
      { value: '3', label: '관리자', group: '시스템 역할', description: '관리자 권한을 가진 사용자' },
      { value: '4', label: 'VIP 회원', group: '사용자 정의 역할', description: 'VIP 등급 회원' },
      { value: '5', label: '프리미엄 회원', group: '사용자 정의 역할', description: '프리미엄 구독 회원' },
    ];

    it('group 필드가 있는 옵션은 그룹별로 표시된다', async () => {
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={groupedOptions}
          onChange={() => {}}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // 그룹 헤더가 표시되어야 함
      await waitFor(() => {
        expect(screen.getByText('시스템 역할')).toBeTruthy();
        expect(screen.getByText('사용자 정의 역할')).toBeTruthy();
      });
    });

    it('description이 옵션과 함께 표시된다', async () => {
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={groupedOptions}
          onChange={() => {}}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // description이 표시되어야 함
      await waitFor(() => {
        expect(screen.getByText('모든 사용자 (비회원 포함)')).toBeTruthy();
        expect(screen.getByText('VIP 등급 회원')).toBeTruthy();
      });
    });

    it('group 필드가 없는 옵션은 평탄하게 표시된다', async () => {
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}  // group 없는 옵션
          onChange={() => {}}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // 그룹 헤더 없이 옵션이 표시되어야 함
      await waitFor(() => {
        expect(screen.getByText('A/S')).toBeTruthy();
        expect(screen.queryByText('시스템 역할')).toBeNull();
      });
    });

    it('그룹화된 옵션에서도 선택이 정상 동작한다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={groupedOptions}
          onChange={handleChange}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // VIP 회원 선택
      const option = await screen.findByText('VIP 회원');
      await user.click(option);

      // onChange가 올바른 값으로 호출되어야 함
      expect(handleChange).toHaveBeenCalled();
      const callArg = handleChange.mock.calls[0][0];
      expect(callArg.target.value).toContain('4');
    });
  });

  describe('onInputChange 콜백', () => {
    it('onInputChange가 없으면 기존 동작과 동일하게 작동한다', async () => {
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);
      await user.type(input, 'A');

      // onInputChange가 없어도 에러 없이 동작
      expect(input).toBeTruthy();
    });

    it('onInputChange가 제공되면 입력 시 호출된다', async () => {
      const handleChange = vi.fn();
      const handleInputChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          onInputChange={handleInputChange}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);
      await user.type(input, 'A');

      // onInputChange가 호출되어야 함
      expect(handleInputChange).toHaveBeenCalled();
      // fake event 형태로 호출됨
      const callArg = handleInputChange.mock.calls[0][0];
      expect(callArg).toHaveProperty('target');
      expect(callArg).toHaveProperty('target.value');
    });

    it('onInputChange 이벤트에 입력값이 전달된다', async () => {
      const handleChange = vi.fn();
      const handleInputChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          onInputChange={handleInputChange}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);
      await user.type(input, 'A/S');

      // 마지막 호출에서 입력값이 포함되어야 함
      const lastCall = handleInputChange.mock.calls[handleInputChange.mock.calls.length - 1][0];
      expect(typeof lastCall.target.value).toBe('string');
      expect(lastCall.target.value.length).toBeGreaterThan(0);
    });

    it('onInputChange가 있어도 onChange는 정상 동작한다', async () => {
      const handleChange = vi.fn();
      const handleInputChange = vi.fn();
      const user = userEvent.setup();

      render(
        <TagInput
          value={[]}
          options={mockOptions}
          onChange={handleChange}
          onInputChange={handleInputChange}
        />
      );

      const input = screen.getByRole('combobox');
      await user.click(input);

      // 옵션 선택
      const option = await screen.findByText('A/S');
      await user.click(option);

      // onChange가 정상 호출
      expect(handleChange).toHaveBeenCalled();
      const callArg = handleChange.mock.calls[0][0];
      expect(callArg.target.value).toContain('as');
    });
  });
});
