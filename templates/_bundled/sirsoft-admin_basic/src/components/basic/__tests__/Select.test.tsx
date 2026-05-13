import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Select } from '../Select';

describe('Select 컴포넌트', () => {
  describe('기본 렌더링 (children 모드)', () => {
    it('select 요소가 렌더링된다', () => {
      const { container } = render(
        <Select>
          <option value="">선택</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select).toBeTruthy();
    });

    it('option 요소가 렌더링된다', () => {
      render(
        <Select>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
        </Select>
      );

      expect(screen.getByText('옵션 1')).toBeTruthy();
      expect(screen.getByText('옵션 2')).toBeTruthy();
    });

    it('기본 선택 옵션이 표시된다', () => {
      const { container } = render(
        <Select defaultValue="2">
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
        </Select>
      );

      const select = container.querySelector('select') as HTMLSelectElement;
      expect(select.value).toBe('2');
    });
  });

  describe('이벤트 핸들링 (children 모드)', () => {
    it('onChange 핸들러가 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Select onChange={handleChange}>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
        </Select>
      );

      const select = container.querySelector('select')!;
      fireEvent.change(select, { target: { value: '2' } });

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('onFocus 핸들러가 호출된다', () => {
      const handleFocus = vi.fn();
      const { container } = render(
        <Select onFocus={handleFocus}>
          <option value="1">옵션 1</option>
        </Select>
      );

      const select = container.querySelector('select')!;
      fireEvent.focus(select);

      expect(handleFocus).toHaveBeenCalledTimes(1);
    });

    it('onBlur 핸들러가 호출된다', () => {
      const handleBlur = vi.fn();
      const { container } = render(
        <Select onBlur={handleBlur}>
          <option value="1">옵션 1</option>
        </Select>
      );

      const select = container.querySelector('select')!;
      fireEvent.blur(select);

      expect(handleBlur).toHaveBeenCalledTimes(1);
    });
  });

  describe('value 및 제어 컴포넌트 (children 모드)', () => {
    it('value prop이 적용된다', () => {
      const { container } = render(
        <Select value="2" onChange={() => {}}>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
          <option value="3">옵션 3</option>
        </Select>
      );

      const select = container.querySelector('select') as HTMLSelectElement;
      expect(select.value).toBe('2');
    });

    it('제어 컴포넌트로 작동한다', () => {
      const TestComponent = () => {
        const [value, setValue] = React.useState('1');

        return (
          <Select value={value} onChange={(e) => setValue(String(e.target.value))}>
            <option value="1">옵션 1</option>
            <option value="2">옵션 2</option>
          </Select>
        );
      };

      const { container } = render(<TestComponent />);
      const select = container.querySelector('select')!;

      expect((select as HTMLSelectElement).value).toBe('1');

      fireEvent.change(select, { target: { value: '2' } });

      expect((select as HTMLSelectElement).value).toBe('2');
    });
  });

  describe('HTML 속성 (children 모드)', () => {
    it('disabled 속성이 적용된다', () => {
      const { container } = render(
        <Select disabled>
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.disabled).toBe(true);
    });

    it('required 속성이 적용된다', () => {
      const { container } = render(
        <Select required>
          <option value="">선택</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.required).toBe(true);
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(
        <Select name="category">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.name).toBe('category');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(
        <Select id="category-select">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.id).toBe('category-select');
    });

    it('multiple 속성이 적용된다', () => {
      const { container } = render(
        <Select multiple>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.multiple).toBe(true);
    });

    it('size 속성이 적용된다', () => {
      const { container } = render(
        <Select size={3}>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.size).toBe(3);
    });
  });

  describe('Label 및 Error Props', () => {
    it('label prop이 존재한다', () => {
      // label prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(
        <Select label="카테고리 선택">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select).toBeTruthy();
    });

    it('error prop이 존재한다', () => {
      // error prop은 있지만 렌더링하지 않음 (부모에서 처리)
      const { container } = render(
        <Select error="카테고리를 선택해주세요">
          <option value="">선택</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select).toBeTruthy();
    });
  });

  describe('사용자 정의 Props (children 모드)', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <Select className="custom-select">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.className).toContain('custom-select');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(
        <Select aria-label="카테고리 선택">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.getAttribute('aria-label')).toBe('카테고리 선택');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(
        <Select data-testid="test-select">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.getAttribute('data-testid')).toBe('test-select');
    });
  });

  describe('optgroup 지원', () => {
    it('optgroup이 렌더링된다', () => {
      render(
        <Select>
          <optgroup label="그룹 1">
            <option value="1">옵션 1</option>
            <option value="2">옵션 2</option>
          </optgroup>
          <optgroup label="그룹 2">
            <option value="3">옵션 3</option>
            <option value="4">옵션 4</option>
          </optgroup>
        </Select>
      );

      expect(screen.getByText('옵션 1')).toBeTruthy();
      expect(screen.getByText('옵션 3')).toBeTruthy();
    });
  });

  describe('복합 Props (children 모드)', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <Select
          name="category"
          value="2"
          onChange={handleChange}
          required
          className="custom-select"
          aria-label="카테고리 선택"
        >
          <option value="">선택하세요</option>
          <option value="1">옵션 1</option>
          <option value="2">옵션 2</option>
          <option value="3">옵션 3</option>
        </Select>
      );

      const select = container.querySelector('select')!;
      expect(select.name).toBe('category');
      expect((select as HTMLSelectElement).value).toBe('2');
      expect(select.required).toBe(true);
      expect(select.className).toContain('custom-select');
      expect(select.getAttribute('aria-label')).toBe('카테고리 선택');

      fireEvent.change(select, { target: { value: '3' } });
      expect(handleChange).toHaveBeenCalledTimes(1);
    });
  });

  // ========================================
  // 커스텀 드롭다운 모드 (options prop 사용)
  // ========================================
  describe('커스텀 드롭다운 렌더링 (options 모드)', () => {
    const options = [
      { value: '', label: '전체' },
      { value: 'name', label: '이름' },
      { value: 'email', label: '이메일' },
    ];

    it('options prop으로 커스텀 드롭다운이 렌더링된다', () => {
      render(<Select options={options} value="" onChange={() => {}} />);

      // 버튼이 렌더링되어야 함 (select가 아닌 button)
      const button = screen.getByRole('button');
      expect(button).toBeTruthy();
    });

    it('선택된 옵션의 라벨이 버튼에 표시된다', () => {
      render(<Select options={options} value="name" onChange={() => {}} />);

      expect(screen.getByText('이름')).toBeTruthy();
    });

    it('빈 값일 때 해당 옵션의 라벨이 표시된다', () => {
      render(<Select options={options} value="" onChange={() => {}} />);

      expect(screen.getByText('전체')).toBeTruthy();
    });

    it('버튼 클릭 시 드롭다운이 열린다', () => {
      render(<Select options={options} value="" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      // 드롭다운 메뉴가 열림 (listbox)
      const listbox = screen.getByRole('listbox');
      expect(listbox).toBeTruthy();

      // 모든 옵션이 표시됨 (옵션은 role="option"으로 찾음)
      const optionElements = screen.getAllByRole('option');
      expect(optionElements.length).toBe(3);
    });

    it('옵션 클릭 시 onChange가 호출되고 드롭다운이 닫힌다', () => {
      const handleChange = vi.fn();
      render(<Select options={options} value="" onChange={handleChange} />);

      // 드롭다운 열기
      const button = screen.getByRole('button');
      fireEvent.click(button);

      // 옵션 선택
      const nameOption = screen.getAllByRole('option').find(
        opt => opt.textContent?.includes('이름')
      );
      fireEvent.click(nameOption!);

      // onChange가 호출됨 (synthetic event 형식)
      expect(handleChange).toHaveBeenCalledTimes(1);
      expect(handleChange).toHaveBeenCalledWith(
        expect.objectContaining({
          target: { value: 'name' },
          type: 'change',
        })
      );

      // 드롭다운이 닫힘
      expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('선택된 옵션에 체크마크가 표시된다', () => {
      render(<Select options={options} value="email" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      // 선택된 옵션 찾기
      const selectedOption = screen.getAllByRole('option').find(
        opt => opt.getAttribute('aria-selected') === 'true'
      );

      expect(selectedOption).toBeTruthy();
      expect(selectedOption?.textContent).toContain('이메일');
    });

    it('disabled 상태에서 클릭이 작동하지 않는다', () => {
      render(<Select options={options} value="" onChange={() => {}} disabled />);

      const button = screen.getByRole('button');
      expect(button).toHaveProperty('disabled', true);

      fireEvent.click(button);

      // 드롭다운이 열리지 않음
      expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('외부 클릭 시 드롭다운이 닫힌다', () => {
      render(
        <div>
          <Select options={options} value="" onChange={() => {}} />
          <div data-testid="outside">외부 영역</div>
        </div>
      );

      // 드롭다운 열기
      const button = screen.getByRole('button');
      fireEvent.click(button);
      expect(screen.getByRole('listbox')).toBeTruthy();

      // 외부 클릭
      const outside = screen.getByTestId('outside');
      fireEvent.mouseDown(outside);

      // 드롭다운이 닫힘
      expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('ESC 키로 드롭다운이 닫힌다', () => {
      render(<Select options={options} value="" onChange={() => {}} />);

      // 드롭다운 열기
      const button = screen.getByRole('button');
      fireEvent.click(button);
      expect(screen.getByRole('listbox')).toBeTruthy();

      // ESC 키 입력
      fireEvent.keyDown(document, { key: 'Escape' });

      // 드롭다운이 닫힘
      expect(screen.queryByRole('listbox')).toBeNull();
    });
  });

  describe('커스텀 드롭다운 스타일 (options 모드)', () => {
    const options = [
      { value: '1', label: '옵션 1' },
      { value: '2', label: '옵션 2' },
    ];

    it('기본 스타일이 적용된다', () => {
      render(<Select options={options} value="1" onChange={() => {}} />);

      const button = screen.getByRole('button');
      expect(button.className).toContain('bg-gray-100');
      expect(button.className).toContain('rounded-xl');
    });

    it('커스텀 className이 적용된다', () => {
      render(
        <Select
          options={options}
          value="1"
          onChange={() => {}}
          className="bg-blue-100 custom-class"
        />
      );

      const button = screen.getByRole('button');
      expect(button.className).toContain('bg-blue-100');
      expect(button.className).toContain('custom-class');
    });

    it('드롭다운 메뉴가 둥근 모서리를 가진다', () => {
      render(<Select options={options} value="1" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      const listbox = screen.getByRole('listbox');
      expect(listbox.className).toContain('rounded-2xl');
      expect(listbox.className).toContain('shadow-lg');
    });
  });

  describe('string[] options 지원', () => {
    it('string 배열을 옵션으로 변환한다', () => {
      render(<Select options={['ko', 'en']} value="ko" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      // 로케일 이름으로 변환됨 (role="option"으로 찾음)
      const optionElements = screen.getAllByRole('option');
      const labels = optionElements.map(opt => opt.textContent);
      expect(labels).toContain('한국어');
      expect(labels).toContain('English');
    });

    it('알 수 없는 로케일은 그대로 표시된다', () => {
      render(<Select options={['unknown']} value="unknown" onChange={() => {}} />);

      expect(screen.getByText('unknown')).toBeTruthy();
    });
  });

  describe('disabled 옵션 지원', () => {
    it('disabled 옵션이 비활성화된다', () => {
      const options = [
        { value: '1', label: '옵션 1' },
        { value: '2', label: '옵션 2', disabled: true },
      ];

      const handleChange = vi.fn();
      render(<Select options={options} value="1" onChange={handleChange} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      const disabledOption = screen.getAllByRole('option').find(
        opt => opt.textContent?.includes('옵션 2')
      );

      expect(disabledOption).toHaveProperty('disabled', true);

      // 클릭해도 onChange가 호출되지 않음
      fireEvent.click(disabledOption!);
      expect(handleChange).not.toHaveBeenCalled();
    });
  });

  describe('접근성 (options 모드)', () => {
    const options = [
      { value: '1', label: '옵션 1' },
      { value: '2', label: '옵션 2' },
    ];

    it('aria-haspopup 속성이 있다', () => {
      render(<Select options={options} value="1" onChange={() => {}} />);

      const button = screen.getByRole('button');
      expect(button.getAttribute('aria-haspopup')).toBe('listbox');
    });

    it('aria-expanded가 열림 상태를 반영한다', () => {
      render(<Select options={options} value="1" onChange={() => {}} />);

      const button = screen.getByRole('button');
      expect(button.getAttribute('aria-expanded')).toBe('false');

      fireEvent.click(button);
      expect(button.getAttribute('aria-expanded')).toBe('true');
    });

    it('옵션에 role="option"이 있다', () => {
      render(<Select options={options} value="1" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      const optionElements = screen.getAllByRole('option');
      expect(optionElements.length).toBe(2);
    });

    it('선택된 옵션에 aria-selected="true"가 있다', () => {
      render(<Select options={options} value="2" onChange={() => {}} />);

      const button = screen.getByRole('button');
      fireEvent.click(button);

      const selectedOption = screen.getAllByRole('option').find(
        opt => opt.getAttribute('aria-selected') === 'true'
      );

      expect(selectedOption?.textContent).toContain('옵션 2');
    });
  });

  describe('제어 컴포넌트 (options 모드)', () => {
    it('value 변경에 따라 표시가 업데이트된다', () => {
      const options = [
        { value: '1', label: '옵션 1' },
        { value: '2', label: '옵션 2' },
      ];

      const TestComponent = () => {
        const [value, setValue] = React.useState('1');

        return (
          <div>
            <Select
              options={options}
              value={value}
              onChange={(e) => setValue(String(e.target.value))}
            />
            <button onClick={() => setValue('2')}>변경</button>
          </div>
        );
      };

      render(<TestComponent />);

      // 초기값 확인
      expect(screen.getByText('옵션 1')).toBeTruthy();

      // 외부에서 값 변경
      fireEvent.click(screen.getByText('변경'));

      // 표시가 업데이트됨
      expect(screen.getByText('옵션 2')).toBeTruthy();
    });
  });

  describe('빈 options 처리', () => {
    it('빈 배열일 때도 렌더링된다', () => {
      render(<Select options={[]} value="" onChange={() => {}} />);

      const button = screen.getByRole('button');
      expect(button).toBeTruthy();
    });

    it('options가 배열이 아닐 때 기본 select로 폴백된다', () => {
      // @ts-expect-error - 의도적으로 잘못된 타입 전달
      const { container } = render(<Select options="invalid" />);

      const select = container.querySelector('select');
      expect(select).toBeTruthy();
    });
  });

  // ========================================
  // searchable 모드 (engine-v1.40.0+)
  // ========================================
  describe('searchable 모드', () => {
    const timezoneOptions = [
      { value: 'Asia/Seoul', label: '(UTC+09:00) Asia/Seoul' },
      { value: 'Asia/Tokyo', label: '(UTC+09:00) Asia/Tokyo' },
      { value: 'America/New_York', label: '(UTC-05:00) America/New_York' },
      { value: 'Europe/London', label: '(UTC+00:00) Europe/London' },
      { value: 'Pacific/Auckland', label: '(UTC+13:00) Pacific/Auckland' },
    ];

    it('searchable=false 기본값에서는 검색 input이 렌더링되지 않는다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} />);
      fireEvent.click(screen.getByRole('button'));
      expect(screen.queryByRole('searchbox')).toBeNull();
    });

    it('searchable=true 시 드롭다운 내 검색 input이 렌더링된다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);
      fireEvent.click(screen.getByRole('button'));
      expect(screen.getByRole('searchbox')).toBeTruthy();
    });

    it('searchPlaceholder가 input placeholder로 적용된다', () => {
      render(
        <Select
          options={timezoneOptions}
          value="Asia/Seoul"
          onChange={() => {}}
          searchable
          searchPlaceholder="타임존 검색..."
        />
      );
      fireEvent.click(screen.getByRole('button'));
      const input = screen.getByRole('searchbox') as HTMLInputElement;
      expect(input.placeholder).toBe('타임존 검색...');
    });

    it('검색어 입력 시 라벨 기준으로 옵션이 필터링된다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);
      fireEvent.click(screen.getByRole('button'));

      const input = screen.getByRole('searchbox');
      fireEvent.change(input, { target: { value: 'tokyo' } });

      const visibleOptions = screen.getAllByRole('option');
      expect(visibleOptions.length).toBe(1);
      expect(visibleOptions[0].textContent).toContain('Asia/Tokyo');
    });

    it('검색은 대소문자를 구분하지 않는다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);
      fireEvent.click(screen.getByRole('button'));

      fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'LONDON' } });

      const visibleOptions = screen.getAllByRole('option');
      expect(visibleOptions.length).toBe(1);
      expect(visibleOptions[0].textContent).toContain('Europe/London');
    });

    it('value 문자열에 대해서도 검색된다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);
      fireEvent.click(screen.getByRole('button'));

      fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'pacific' } });

      const visibleOptions = screen.getAllByRole('option');
      expect(visibleOptions.length).toBe(1);
      expect(visibleOptions[0].textContent).toContain('Pacific/Auckland');
    });

    it('검색 결과가 없으면 "No results" 메시지가 표시된다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);
      fireEvent.click(screen.getByRole('button'));

      fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'nonexistent-zzz' } });

      expect(screen.queryAllByRole('option').length).toBe(0);
      expect(screen.getByText('No results')).toBeTruthy();
    });

    it('필터된 옵션 선택 시 onChange가 호출되고 드롭다운이 닫힌다', () => {
      const handleChange = vi.fn();
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={handleChange} searchable />);
      fireEvent.click(screen.getByRole('button'));

      fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'tokyo' } });

      const tokyoOption = screen.getAllByRole('option')[0];
      fireEvent.click(tokyoOption);

      expect(handleChange).toHaveBeenCalledTimes(1);
      expect(handleChange).toHaveBeenCalledWith(
        expect.objectContaining({ target: { value: 'Asia/Tokyo' } })
      );
      expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('드롭다운을 다시 열면 검색어가 초기화된다', () => {
      render(<Select options={timezoneOptions} value="Asia/Seoul" onChange={() => {}} searchable />);

      // 첫 번째 열기 + 필터링
      fireEvent.click(screen.getByRole('button'));
      fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'tokyo' } });
      expect(screen.getAllByRole('option').length).toBe(1);

      // 닫기 (외부 클릭 대신 ESC)
      fireEvent.keyDown(document, { key: 'Escape' });
      expect(screen.queryByRole('listbox')).toBeNull();

      // 다시 열기 — 검색어가 초기화되어 모든 옵션이 보여야 함
      fireEvent.click(screen.getByRole('button'));
      expect(screen.getAllByRole('option').length).toBe(timezoneOptions.length);
      const input = screen.getByRole('searchbox') as HTMLInputElement;
      expect(input.value).toBe('');
    });
  });
});