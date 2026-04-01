import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Container } from '../Container';

/**
 * Container 컴포넌트 테스트
 *
 * 현재 Container는 id, className, style, children만 지원하는 단순한 div 래퍼입니다.
 */
describe('Container 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('자식 요소를 렌더링한다', () => {
      render(<Container>Test Content</Container>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    it('기본 div로 렌더링된다', () => {
      const { container } = render(<Container>Content</Container>);
      const div = container.firstChild as HTMLElement;
      expect(div.tagName).toBe('DIV');
    });
  });

  describe('사용자 정의 Props', () => {
    it('id prop이 적용된다', () => {
      const { container } = render(<Container id="test-container">Content</Container>);
      const div = container.firstChild as HTMLElement;
      expect(div.id).toBe('test-container');
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Container className="custom-class">Content</Container>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('custom-class');
    });

    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <Container style={{ backgroundColor: 'red' }}>Content</Container>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.style.backgroundColor).toBe('red');
    });
  });

  describe('children 처리', () => {
    it('단일 자식 요소를 렌더링한다', () => {
      render(
        <Container>
          <span>Single child</span>
        </Container>
      );
      expect(screen.getByText('Single child')).toBeTruthy();
    });

    it('복수 자식 요소를 렌더링한다', () => {
      render(
        <Container>
          <span>First</span>
          <span>Second</span>
        </Container>
      );
      expect(screen.getByText('First')).toBeTruthy();
      expect(screen.getByText('Second')).toBeTruthy();
    });

    it('children이 없으면 빈 div를 렌더링한다', () => {
      const { container } = render(<Container />);
      const div = container.firstChild as HTMLElement;
      expect(div.tagName).toBe('DIV');
      expect(div.childNodes.length).toBe(0);
    });
  });
});
