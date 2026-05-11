import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThreeColumnLayout } from '../ThreeColumnLayout';

describe('ThreeColumnLayout 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('3개의 영역이 모두 렌더링된다', () => {
      const { container } = render(
        <ThreeColumnLayout
          leftSlot={<div>Left</div>}
          centerSlot={<div>Center</div>}
          rightSlot={<div>Right</div>}
        />
      );

      expect(screen.getByText('Left')).toBeTruthy();
      expect(screen.getByText('Center')).toBeTruthy();
      expect(screen.getByText('Right')).toBeTruthy();
      expect(container.firstChild?.childNodes.length).toBe(3);
    });

    it('슬롯이 없어도 렌더링된다', () => {
      const { container } = render(<ThreeColumnLayout />);
      expect(container.firstChild).toBeTruthy();
      expect(container.firstChild?.childNodes.length).toBe(3);
    });
  });

  describe('leftSlot 렌더링', () => {
    it('leftSlot에 전달된 컴포넌트가 렌더링된다', () => {
      render(<ThreeColumnLayout leftSlot={<div>Left Content</div>} />);
      expect(screen.getByText('Left Content')).toBeTruthy();
    });

    it('leftSlot에 복잡한 컴포넌트도 렌더링된다', () => {
      const ComplexComponent = () => (
        <div>
          <h1>Title</h1>
          <p>Description</p>
        </div>
      );

      render(<ThreeColumnLayout leftSlot={<ComplexComponent />} />);
      expect(screen.getByText('Title')).toBeTruthy();
      expect(screen.getByText('Description')).toBeTruthy();
    });
  });

  describe('centerSlot 렌더링', () => {
    it('centerSlot에 전달된 컴포넌트가 렌더링된다', () => {
      render(<ThreeColumnLayout centerSlot={<div>Center Content</div>} />);
      expect(screen.getByText('Center Content')).toBeTruthy();
    });

    it('centerSlot에 복잡한 컴포넌트도 렌더링된다', () => {
      const ComplexComponent = () => (
        <div>
          <h2>Main Title</h2>
          <p>Main Content</p>
        </div>
      );

      render(<ThreeColumnLayout centerSlot={<ComplexComponent />} />);
      expect(screen.getByText('Main Title')).toBeTruthy();
      expect(screen.getByText('Main Content')).toBeTruthy();
    });
  });

  describe('rightSlot 렌더링', () => {
    it('rightSlot에 전달된 컴포넌트가 렌더링된다', () => {
      render(<ThreeColumnLayout rightSlot={<div>Right Content</div>} />);
      expect(screen.getByText('Right Content')).toBeTruthy();
    });

    it('rightSlot에 복잡한 컴포넌트도 렌더링된다', () => {
      const ComplexComponent = () => (
        <div>
          <h3>Sidebar Title</h3>
          <ul>
            <li>Item 1</li>
            <li>Item 2</li>
          </ul>
        </div>
      );

      render(<ThreeColumnLayout rightSlot={<ComplexComponent />} />);
      expect(screen.getByText('Sidebar Title')).toBeTruthy();
      expect(screen.getByText('Item 1')).toBeTruthy();
      expect(screen.getByText('Item 2')).toBeTruthy();
    });
  });

  describe('width 설정', () => {
    it('기본 leftWidth(250px)가 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout leftSlot={<div>Left</div>} />
      );
      const leftColumn = container.firstChild?.childNodes[0] as HTMLElement;
      expect(leftColumn.style.width).toBe('250px');
    });

    it('기본 rightWidth(300px)가 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout rightSlot={<div>Right</div>} />
      );
      const rightColumn = container.firstChild?.childNodes[2] as HTMLElement;
      expect(rightColumn.style.width).toBe('300px');
    });

    it('커스텀 leftWidth가 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout leftWidth="200px" leftSlot={<div>Left</div>} />
      );
      const leftColumn = container.firstChild?.childNodes[0] as HTMLElement;
      expect(leftColumn.style.width).toBe('200px');
    });

    it('커스텀 rightWidth가 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout rightWidth="350px" rightSlot={<div>Right</div>} />
      );
      const rightColumn = container.firstChild?.childNodes[2] as HTMLElement;
      expect(rightColumn.style.width).toBe('350px');
    });

    it('leftWidth와 rightWidth를 동시에 설정할 수 있다', () => {
      const { container } = render(
        <ThreeColumnLayout
          leftWidth="180px"
          rightWidth="320px"
          leftSlot={<div>Left</div>}
          rightSlot={<div>Right</div>}
        />
      );

      const leftColumn = container.firstChild?.childNodes[0] as HTMLElement;
      const rightColumn = container.firstChild?.childNodes[2] as HTMLElement;

      expect(leftColumn.style.width).toBe('180px');
      expect(rightColumn.style.width).toBe('320px');
    });

    it('가운데 영역은 flex: 1로 남은 공간을 차지한다', () => {
      const { container } = render(
        <ThreeColumnLayout centerSlot={<div>Center</div>} />
      );
      const centerColumn = container.firstChild?.childNodes[1] as HTMLElement;
      expect(centerColumn.style.flex).toContain('1');
      // jsdom 은 number 0 을 '0' 문자열로 반환 (단위 추가 없음)
      expect(centerColumn.style.minWidth).toBe('0');
    });
  });

  describe('레이아웃 구조', () => {
    it('컨테이너는 flex row 레이아웃이다', () => {
      const { container } = render(<ThreeColumnLayout />);
      const layoutContainer = container.firstChild as HTMLElement;
      expect(layoutContainer.className).toContain('flex');
      expect(layoutContainer.className).toContain('flex-row');
    });

    it('각 영역은 flex-col 레이아웃이다', () => {
      const { container } = render(<ThreeColumnLayout />);
      const leftColumn = container.firstChild?.childNodes[0] as HTMLElement;
      const centerColumn = container.firstChild?.childNodes[1] as HTMLElement;
      const rightColumn = container.firstChild?.childNodes[2] as HTMLElement;

      expect(leftColumn.className).toContain('flex-col');
      expect(centerColumn.className).toContain('flex-col');
      expect(rightColumn.className).toContain('flex-col');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout className="custom-layout" />
      );
      const layoutContainer = container.firstChild as HTMLElement;
      expect(layoutContainer.className).toContain('custom-layout');
    });

    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <ThreeColumnLayout style={{ backgroundColor: 'gray' }} />
      );
      const layoutContainer = container.firstChild as HTMLElement;
      expect(layoutContainer.style.backgroundColor).toBe('gray');
    });
  });

  describe('모든 슬롯 동시 사용', () => {
    it('3개의 슬롯을 모두 사용할 수 있다', () => {
      render(
        <ThreeColumnLayout
          leftWidth="200px"
          rightWidth="300px"
          leftSlot={<div data-testid="left">Left Panel</div>}
          centerSlot={<div data-testid="center">Main Content</div>}
          rightSlot={<div data-testid="right">Right Panel</div>}
        />
      );

      expect(screen.getByTestId('left')).toBeTruthy();
      expect(screen.getByTestId('center')).toBeTruthy();
      expect(screen.getByTestId('right')).toBeTruthy();
    });

    it('각 슬롯의 컨텐츠가 독립적으로 렌더링된다', () => {
      render(
        <ThreeColumnLayout
          leftSlot={
            <div>
              <h1>Left</h1>
              <p>Left content</p>
            </div>
          }
          centerSlot={
            <div>
              <h1>Center</h1>
              <p>Center content</p>
            </div>
          }
          rightSlot={
            <div>
              <h1>Right</h1>
              <p>Right content</p>
            </div>
          }
        />
      );

      const leftHeaders = screen.getAllByText('Left');
      const centerHeaders = screen.getAllByText('Center');
      const rightHeaders = screen.getAllByText('Right');

      expect(leftHeaders.length).toBeGreaterThan(0);
      expect(centerHeaders.length).toBeGreaterThan(0);
      expect(rightHeaders.length).toBeGreaterThan(0);
    });
  });
});
