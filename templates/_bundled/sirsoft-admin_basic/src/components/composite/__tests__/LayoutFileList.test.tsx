import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LayoutFileList, LayoutFileItem } from '../LayoutFileList';

describe('LayoutFileList 컴포넌트', () => {
  const mockFiles: LayoutFileItem[] = [
    { id: 1, name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
    { id: 2, name: 'layout2.json', updated_at: '2024-01-02T15:30:00Z' },
    { id: 3, name: 'layout3.json', updated_at: '2024-01-03T09:45:00Z' },
  ];

  describe('기본 렌더링', () => {
    it('파일 목록이 렌더링된다', () => {
      const onSelect = vi.fn();
      render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      expect(screen.getByText('layout1.json')).toBeTruthy();
      expect(screen.getByText('layout2.json')).toBeTruthy();
      expect(screen.getByText('layout3.json')).toBeTruthy();
    });

    it('updated_at이 포맷팅되어 표시된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      // 2024-01-01T12:00:00Z -> 2024-01-01 12:00 (로컬 시간대에 따라 다를 수 있음)
      const dateElements = container.querySelectorAll('.text-sm');
      expect(dateElements.length).toBeGreaterThan(0);
    });

    it('role="listbox" 속성이 있다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);
      const listboxElement = container.querySelector('[role="listbox"]');
      expect(listboxElement).toBeTruthy();
    });

    it('aria-label이 있다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);
      const listboxElement = container.querySelector('[aria-label="레이아웃 파일 목록"]');
      expect(listboxElement).toBeTruthy();
    });
  });

  describe('파일 항목 렌더링', () => {
    it('각 파일 항목은 role="option" 속성이 있다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);
      const optionElements = container.querySelectorAll('[role="option"]');
      expect(optionElements.length).toBe(mockFiles.length);
    });

    it('각 파일 항목은 tabIndex=0이 설정된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);
      const optionElements = container.querySelectorAll('[role="option"]');
      optionElements.forEach((element) => {
        expect(element.getAttribute('tabindex')).toBe('0');
      });
    });
  });

  describe('선택된 파일 하이라이트', () => {
    it('selectedId와 일치하는 항목이 하이라이트된다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={2} onSelect={onSelect} />
      );

      const optionElements = container.querySelectorAll('[role="option"]');
      const selectedElement = Array.from(optionElements).find(
        (el) => el.getAttribute('aria-selected') === 'true'
      );

      expect(selectedElement).toBeTruthy();
      expect(selectedElement?.textContent).toContain('layout2.json');
    });

    it('선택된 항목은 aria-selected="true"가 설정된다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={1} onSelect={onSelect} />
      );

      const selectedElement = container.querySelector('[aria-selected="true"]');
      expect(selectedElement).toBeTruthy();
    });

    it('선택되지 않은 항목은 aria-selected="false"가 설정된다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={1} onSelect={onSelect} />
      );

      const notSelectedElements = container.querySelectorAll('[aria-selected="false"]');
      expect(notSelectedElements.length).toBe(mockFiles.length - 1);
    });

    it('선택된 항목은 bg-blue-100 클래스를 갖는다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={2} onSelect={onSelect} />
      );

      const selectedElement = container.querySelector('[aria-selected="true"]');
      expect(selectedElement?.className).toContain('bg-blue-100');
    });

    it('선택된 항목은 border-l-4 클래스를 갖는다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={2} onSelect={onSelect} />
      );

      const selectedElement = container.querySelector('[aria-selected="true"]');
      expect(selectedElement?.className).toContain('border-l-4');
      expect(selectedElement?.className).toContain('border-blue-500');
    });
  });

  describe('onSelect 콜백', () => {
    it('파일 클릭 시 onSelect가 호출된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;
      expect(firstOption).toBeTruthy();

      fireEvent.click(firstOption);
      expect(onSelect).toHaveBeenCalledTimes(1);
      expect(onSelect).toHaveBeenCalledWith(1);
    });

    it('다른 파일 클릭 시 올바른 id로 onSelect가 호출된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      const options = container.querySelectorAll('[role="option"]');
      const secondOption = options[1] as HTMLElement;

      fireEvent.click(secondOption);
      expect(onSelect).toHaveBeenCalledWith(2);
    });

    it('Enter 키 입력 시 onSelect가 호출된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;
      fireEvent.keyDown(firstOption, { key: 'Enter', code: 'Enter' });

      expect(onSelect).toHaveBeenCalledTimes(1);
      expect(onSelect).toHaveBeenCalledWith(1);
    });

    it('Space 키 입력 시 onSelect가 호출된다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;
      fireEvent.keyDown(firstOption, { key: ' ', code: 'Space' });

      expect(onSelect).toHaveBeenCalledTimes(1);
      expect(onSelect).toHaveBeenCalledWith(1);
    });

    it('다른 키 입력 시 onSelect가 호출되지 않는다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={mockFiles} onSelect={onSelect} />);

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;
      fireEvent.keyDown(firstOption, { key: 'a', code: 'KeyA' });

      expect(onSelect).not.toHaveBeenCalled();
    });
  });

  describe('빈 배열 처리', () => {
    it('빈 배열일 때 안내 메시지가 표시된다', () => {
      const onSelect = vi.fn();
      render(<LayoutFileList files={[]} onSelect={onSelect} />);

      expect(screen.getByText('파일이 없습니다.')).toBeTruthy();
    });

    it('빈 배열일 때 listbox가 렌더링되지 않는다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={[]} onSelect={onSelect} />);

      const listboxElement = container.querySelector('[role="listbox"]');
      expect(listboxElement).toBeNull();
    });

    it('빈 배열일 때 안내 메시지는 회색 텍스트이다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={[]} onSelect={onSelect} />);

      const messageElement = container.querySelector('.text-gray-500');
      expect(messageElement).toBeTruthy();
      expect(messageElement?.textContent).toBe('파일이 없습니다.');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 적용된다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} onSelect={onSelect} className="custom-list" />
      );

      // 최상위 Div에 클래스가 적용되는지 확인
      const rootElement = container.firstChild as HTMLElement;
      expect(rootElement.className).toContain('custom-list');
    });
  });

  describe('날짜 포맷팅', () => {
    it('ISO 형식 날짜가 YYYY-MM-DD HH:MM 형식으로 변환된다', () => {
      const onSelect = vi.fn();
      const files: LayoutFileItem[] = [
        { id: 1, name: 'test.json', updated_at: '2024-12-25T14:30:00Z' },
      ];

      const { container } = render(<LayoutFileList files={files} onSelect={onSelect} />);

      // 날짜 텍스트는 .text-sm 클래스를 가진 요소에 있음
      const dateElements = container.querySelectorAll('.text-sm');
      expect(dateElements.length).toBeGreaterThan(0);

      // 날짜 포맷이 YYYY-MM-DD HH:MM 형식인지 확인
      const dateText = dateElements[0].textContent || '';
      expect(dateText).toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
    });

    it('잘못된 날짜 형식은 원본 문자열을 반환한다', () => {
      const onSelect = vi.fn();
      const files: LayoutFileItem[] = [
        { id: 1, name: 'test.json', updated_at: 'invalid-date' },
      ];

      const { container } = render(<LayoutFileList files={files} onSelect={onSelect} />);

      const dateElements = container.querySelectorAll('.text-sm');
      expect(dateElements[0].textContent).toBe('invalid-date');
    });
  });

  describe('복합 시나리오', () => {
    it('여러 파일 중 하나를 선택하고 다시 클릭할 수 있다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={1} onSelect={onSelect} />
      );

      const options = container.querySelectorAll('[role="option"]');

      // 두 번째 파일 클릭
      fireEvent.click(options[1] as HTMLElement);
      expect(onSelect).toHaveBeenCalledWith(2);

      // 세 번째 파일 클릭
      fireEvent.click(options[2] as HTMLElement);
      expect(onSelect).toHaveBeenCalledWith(3);

      expect(onSelect).toHaveBeenCalledTimes(2);
    });

    it('같은 파일을 여러 번 클릭할 수 있다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={1} onSelect={onSelect} />
      );

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;

      fireEvent.click(firstOption);
      fireEvent.click(firstOption);
      fireEvent.click(firstOption);

      expect(onSelect).toHaveBeenCalledTimes(3);
      expect(onSelect).toHaveBeenCalledWith(1);
    });
  });

  describe('다크 모드 클래스', () => {
    it('선택된 항목은 다크 모드 클래스를 갖는다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={2} onSelect={onSelect} />
      );

      const selectedElement = container.querySelector('[aria-selected="true"]');
      expect(selectedElement?.className).toContain('dark:bg-blue-900');
    });

    it('선택되지 않은 항목은 다크 모드 hover 클래스를 갖는다', () => {
      const onSelect = vi.fn();
      const { container } = render(
        <LayoutFileList files={mockFiles} selectedId={1} onSelect={onSelect} />
      );

      const notSelectedElement = container.querySelector('[aria-selected="false"]');
      expect(notSelectedElement?.className).toContain('dark:hover:bg-gray-800');
    });

    it('빈 목록 안내 메시지는 다크 모드 클래스를 갖는다', () => {
      const onSelect = vi.fn();
      const { container } = render(<LayoutFileList files={[]} onSelect={onSelect} />);

      const messageElement = container.querySelector('.text-gray-500');
      expect(messageElement?.className).toContain('dark:text-gray-400');
    });
  });

  describe('문자열 ID 지원', () => {
    it('문자열 ID를 사용하는 파일 목록이 렌더링된다', () => {
      const onSelect = vi.fn();
      const filesWithStringId: LayoutFileItem[] = [
        { id: 'layout-1', name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
        { id: 'layout-2', name: 'layout2.json', updated_at: '2024-01-02T15:30:00Z' },
      ];

      render(<LayoutFileList files={filesWithStringId} onSelect={onSelect} />);

      expect(screen.getByText('layout1.json')).toBeTruthy();
      expect(screen.getByText('layout2.json')).toBeTruthy();
    });

    it('문자열 ID로 선택이 동작한다', () => {
      const onSelect = vi.fn();
      const filesWithStringId: LayoutFileItem[] = [
        { id: 'layout-1', name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
        { id: 'layout-2', name: 'layout2.json', updated_at: '2024-01-02T15:30:00Z' },
      ];

      const { container } = render(
        <LayoutFileList files={filesWithStringId} selectedId="layout-2" onSelect={onSelect} />
      );

      const selectedElement = container.querySelector('[aria-selected="true"]');
      expect(selectedElement?.textContent).toContain('layout2.json');
    });

    it('문자열 ID로 onSelect 콜백이 호출된다', () => {
      const onSelect = vi.fn();
      const filesWithStringId: LayoutFileItem[] = [
        { id: 'layout-1', name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
      ];

      const { container } = render(
        <LayoutFileList files={filesWithStringId} onSelect={onSelect} />
      );

      const firstOption = container.querySelector('[role="option"]') as HTMLElement;
      fireEvent.click(firstOption);

      expect(onSelect).toHaveBeenCalledWith('layout-1');
    });
  });
});
