// e2e:allow 단위 테스트 보강 (디폴트 시맨틱 머지 회귀) — 동작 무변, 컴포넌트 소스의 e2e:allow 와 동일 사이클에서 E2E spec 일괄 작성 예정.
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

  // 디폴트 시맨틱 머지 회귀 테스트 (#369)
  // 호출처가 이미 'select' 토큰을 명시한 경우 중복 prepend ("select select ...") 를 방지한다.
  describe('디폴트 시맨틱 클래스 머지 (children 모드)', () => {
    it('className 미지정 시 디폴트 시맨틱 "select" 가 자동 적용된다', () => {
      const { container } = render(
        <Select>
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.className).toBe('select');
    });

    it('className 에 시맨틱 토큰이 없으면 "select" 를 prepend 한다', () => {
      const { container } = render(
        <Select className="w-full">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.className).toBe('select w-full');
    });

    it('className 에 이미 "select" 토큰이 있으면 중복 prepend 하지 않는다', () => {
      const { container } = render(
        <Select className="select w-full">
          <option value="1">옵션 1</option>
        </Select>
      );
      const select = container.querySelector('select');
      expect(select?.className).toBe('select w-full');
      expect(select?.className.split(/\s+/).filter((t) => t === 'select')).toHaveLength(1);
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

  // ========================================
  // 레이아웃 편집기 editorAttrs/props passthrough
  // ========================================
  // DynamicRenderer 는 편집 모드에서 basic 컴포넌트에 개별 data-editor-* 키를
  // props 로 주입한다. Select 의 두 렌더 경로(children 폴백 <select>, options 커스텀 <Div>)
  // 루트 모두 그 키가 DOM 에 도달해야 캔버스에서 선택/식별/편집이 가능하다.
  describe('편집기 passthrough (editorAttrs/props → 루트 DOM)', () => {
    it('options 커스텀 드롭다운 루트에 data-editor-* 가 도달한다', () => {
      const options = [
        { value: '1', label: '옵션 1' },
        { value: '2', label: '옵션 2' },
      ];
      const { container } = render(
        <Select
          options={options}
          value="1"
          onChange={() => {}}
          data-editor-name="Select"
          data-editor-path="1.children.0"
          id="root-id"
        />
      );

      // 커스텀 드롭다운 루트는 button 의 부모 Div (relative)
      const root = container.querySelector('[data-editor-name="Select"]') as HTMLElement;
      expect(root).toBeTruthy();
      expect(root.getAttribute('data-editor-path')).toBe('1.children.0');
      expect(root.getAttribute('id')).toBe('root-id');
      // 루트는 button(드롭다운 트리거)을 자식으로 가진다 = 커스텀 모드 루트 확인
      expect(root.querySelector('button[aria-haspopup="listbox"]')).toBeTruthy();
    });

    it('children 폴백 <select> 루트에 data-editor-* 가 도달한다', () => {
      const { container } = render(
        <Select data-editor-name="Select" data-editor-path="1.children.1" id="sel-id">
          <option value="1">옵션 1</option>
        </Select>
      );

      const select = container.querySelector('select') as HTMLElement;
      expect(select).toBeTruthy();
      expect(select.getAttribute('data-editor-name')).toBe('Select');
      expect(select.getAttribute('data-editor-path')).toBe('1.children.1');
      expect(select.getAttribute('id')).toBe('sel-id');
    });

    it('editorAttrs 객체로 전달된 키도 루트에 도달한다', () => {
      const options = [{ value: '1', label: '옵션 1' }];
      const { container } = render(
        <Select
          options={options}
          value="1"
          onChange={() => {}}
          editorAttrs={{ 'data-editor-name': 'Select', 'data-editor-path': '2.children.3' }}
        />
      );

      const root = container.querySelector('[data-editor-name="Select"]') as HTMLElement;
      expect(root).toBeTruthy();
      expect(root.getAttribute('data-editor-path')).toBe('2.children.3');
    });
  });

  describe('variant 접두사 className 과 베이스 크기 유지 (비활성 정책 Select 회귀)', () => {
    /**
     * 회귀 배경: 정책 편집 모달의 잠금 Select 는 className 에 `disabled:bg-gray-100` 만 추가한다.
     * hasCustomStyle 판정이 `bg-` 단순 포함으로 동작하면 variant 접두사 유틸(disabled:bg-)을
     * 커스텀 베이스 외형으로 오인 → 기본 크기 클래스(w-full px-4 py-2.5)를 통째로 버려
     * 비활성 Select 만 작아지고 활성 Select 와 크기/모양이 달라진다.
     */
    const getTriggerBtn = (container: HTMLElement) =>
      container.querySelector('button[aria-haspopup="listbox"]') as HTMLElement;

    it('disabled:bg- 만 추가된 비활성 Select 도 베이스 크기 클래스(w-full px-4 py-2.5) 유지', () => {
      const options = [{ value: 'sensitive_action', label: '민감 작업' }];
      const { container } = render(
        <Select
          options={options}
          value="sensitive_action"
          onChange={() => {}}
          disabled
          className=" disabled:bg-gray-100 dark:disabled:bg-gray-900"
        />
      );
      const btn = getTriggerBtn(container);
      expect(btn).toBeTruthy();
      expect(btn.className).toContain('w-full');
      expect(btn.className).toContain('px-4');
      expect(btn.className).toContain('py-2.5');
    });

    it('활성 Select(동일 컬럼)와 비활성 Select 의 베이스 크기 클래스가 동일', () => {
      const options = [{ value: '', label: '(기본 인증 수단 사용)' }];
      const active = render(
        <Select options={options} value="" onChange={() => {}} className="" />
      );
      const disabled = render(
        <Select
          options={options}
          value=""
          onChange={() => {}}
          disabled
          className=" disabled:bg-gray-100 dark:disabled:bg-gray-900"
        />
      );
      const pick = (cls: string) =>
        ['w-full', 'px-4', 'py-2.5'].filter((t) => cls.split(/\s+/).includes(t)).sort().join(' ');
      const activeBase = pick(getTriggerBtn(active.container).className);
      const disabledBase = pick(getTriggerBtn(disabled.container).className);
      expect(disabledBase).toBe(activeBase);
      expect(disabledBase).toBe('px-4 py-2.5 w-full');
    });

    it('실제 커스텀 베이스 bg-(접두사 없음)는 기존대로 기본 크기 미적용', () => {
      const options = [{ value: '1', label: '옵션 1' }];
      const { container } = render(
        <Select options={options} value="1" onChange={() => {}} className="bg-indigo-600 px-2" />
      );
      const btn = getTriggerBtn(container);
      // 베이스 bg- 가 있으면 호출처 className 을 그대로 존중(w-full 자동 주입 안 함)
      expect(btn.className).toContain('bg-indigo-600');
      expect(btn.className).not.toContain('w-full');
    });
  });
});