import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from '../Button';

describe('Button 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('자식 요소를 렌더링한다', () => {
      render(<Button>클릭하기</Button>);
      expect(screen.getByText('클릭하기')).toBeTruthy();
    });

    it('기본 variant는 primary이다', () => {
      const { container } = render(<Button>버튼</Button>);
      const button = container.querySelector('button');
      expect(button).toBeTruthy();
    });

    it('기본 size는 md이다', () => {
      const { container } = render(<Button>버튼</Button>);
      const button = container.querySelector('button');
      expect(button).toBeTruthy();
    });
  });

  describe('Variant Props', () => {
    it('primary variant가 적용된다', () => {
      render(<Button variant="primary">Primary 버튼</Button>);
      expect(screen.getByText('Primary 버튼')).toBeTruthy();
    });

    it('secondary variant가 적용된다', () => {
      render(<Button variant="secondary">Secondary 버튼</Button>);
      expect(screen.getByText('Secondary 버튼')).toBeTruthy();
    });

    it('danger variant가 적용된다', () => {
      render(<Button variant="danger">Danger 버튼</Button>);
      expect(screen.getByText('Danger 버튼')).toBeTruthy();
    });

    it('success variant가 적용된다', () => {
      render(<Button variant="success">Success 버튼</Button>);
      expect(screen.getByText('Success 버튼')).toBeTruthy();
    });
  });

  describe('Size Props', () => {
    it('sm size가 적용된다', () => {
      render(<Button size="sm">작은 버튼</Button>);
      expect(screen.getByText('작은 버튼')).toBeTruthy();
    });

    it('md size가 적용된다', () => {
      render(<Button size="md">중간 버튼</Button>);
      expect(screen.getByText('중간 버튼')).toBeTruthy();
    });

    it('lg size가 적용된다', () => {
      render(<Button size="lg">큰 버튼</Button>);
      expect(screen.getByText('큰 버튼')).toBeTruthy();
    });
  });

  describe('이벤트 핸들링', () => {
    it('onClick 핸들러가 호출된다', () => {
      const handleClick = vi.fn();
      render(<Button onClick={handleClick}>클릭</Button>);

      const button = screen.getByText('클릭');
      fireEvent.click(button);

      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('disabled 상태에서는 클릭 이벤트가 발생하지 않는다', () => {
      const handleClick = vi.fn();
      render(<Button onClick={handleClick} disabled>비활성 버튼</Button>);

      const button = screen.getByText('비활성 버튼');
      fireEvent.click(button);

      expect(handleClick).not.toHaveBeenCalled();
    });
  });

  describe('HTML 속성', () => {
    it('type 속성이 적용된다', () => {
      const { container } = render(<Button type="submit">제출</Button>);
      const button = container.querySelector('button');
      expect(button?.type).toBe('submit');
    });

    it('disabled 속성이 적용된다', () => {
      const { container } = render(<Button disabled>비활성</Button>);
      const button = container.querySelector('button');
      expect(button?.disabled).toBe(true);
    });

    it('name 속성이 적용된다', () => {
      const { container } = render(<Button name="testButton">버튼</Button>);
      const button = container.querySelector('button');
      expect(button?.name).toBe('testButton');
    });

    it('value 속성이 적용된다', () => {
      const { container } = render(<Button value="testValue">버튼</Button>);
      const button = container.querySelector('button');
      expect(button?.value).toBe('testValue');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Button className="custom-button">버튼</Button>);
      const button = container.querySelector('button');
      expect(button?.className).toContain('custom-button');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(<Button aria-label="사용자 정의 레이블">버튼</Button>);
      const button = container.querySelector('button');
      expect(button?.getAttribute('aria-label')).toBe('사용자 정의 레이블');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Button data-testid="test-button">버튼</Button>);
      const button = container.querySelector('button');
      expect(button?.getAttribute('data-testid')).toBe('test-button');
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleClick = vi.fn();
      const { container } = render(
        <Button
          variant="danger"
          size="lg"
          className="custom-class"
          onClick={handleClick}
          type="button"
        >
          복합 버튼
        </Button>
      );

      const button = container.querySelector('button');
      expect(button).toBeTruthy();
      expect(button?.className).toContain('custom-class');
      expect(button?.type).toBe('button');

      fireEvent.click(button!);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });
  });
});
