import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Flex } from '../Flex';

describe('Flex 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('자식 요소를 렌더링한다', () => {
      render(<Flex>Test Content</Flex>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    it('기본 flex 클래스가 적용된다', () => {
      const { container } = render(<Flex>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex');
    });
  });

  describe('Direction (방향)', () => {
    it('row 방향이 적용된다', () => {
      const { container } = render(<Flex direction="row">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-row');
    });

    it('row-reverse 방향이 적용된다', () => {
      const { container } = render(<Flex direction="row-reverse">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-row-reverse');
    });

    it('col 방향이 적용된다', () => {
      const { container } = render(<Flex direction="col">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-col');
    });

    it('col-reverse 방향이 적용된다', () => {
      const { container } = render(<Flex direction="col-reverse">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-col-reverse');
    });
  });

  describe('Justify (주축 정렬)', () => {
    it('justify-start가 적용된다', () => {
      const { container } = render(<Flex justify="start">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-start');
    });

    it('justify-end가 적용된다', () => {
      const { container } = render(<Flex justify="end">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-end');
    });

    it('justify-center가 적용된다', () => {
      const { container } = render(<Flex justify="center">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-center');
    });

    it('justify-between이 적용된다', () => {
      const { container } = render(<Flex justify="between">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-between');
    });

    it('justify-around가 적용된다', () => {
      const { container } = render(<Flex justify="around">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-around');
    });

    it('justify-evenly가 적용된다', () => {
      const { container } = render(<Flex justify="evenly">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('justify-evenly');
    });
  });

  describe('Align (교차축 정렬)', () => {
    it('items-start가 적용된다', () => {
      const { container } = render(<Flex align="start">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('items-start');
    });

    it('items-end가 적용된다', () => {
      const { container } = render(<Flex align="end">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('items-end');
    });

    it('items-center가 적용된다', () => {
      const { container } = render(<Flex align="center">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('items-center');
    });

    it('items-baseline이 적용된다', () => {
      const { container } = render(<Flex align="baseline">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('items-baseline');
    });

    it('items-stretch가 적용된다', () => {
      const { container } = render(<Flex align="stretch">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('items-stretch');
    });
  });

  describe('Wrap (줄바꿈)', () => {
    it('flex-nowrap이 적용된다', () => {
      const { container } = render(<Flex wrap="nowrap">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-nowrap');
    });

    it('flex-wrap이 적용된다', () => {
      const { container } = render(<Flex wrap="wrap">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-wrap');
    });

    it('flex-wrap-reverse가 적용된다', () => {
      const { container } = render(<Flex wrap="wrap-reverse">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-wrap-reverse');
    });
  });

  describe('Gap (간격)', () => {
    it('gap 클래스가 적용된다', () => {
      const { container } = render(<Flex gap={4}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-4');
    });

    it('gap이 0이면 적용되지 않는다', () => {
      const { container } = render(<Flex gap={0}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).not.toContain('gap-');
    });
  });

  describe('Grow & Shrink', () => {
    it('flex-grow가 적용된다', () => {
      const { container } = render(<Flex grow={1}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-grow');
    });

    it('flex-shrink가 적용된다', () => {
      const { container } = render(<Flex shrink={1}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex-shrink');
    });

    it('grow와 shrink가 0이면 적용되지 않는다', () => {
      const { container } = render(<Flex grow={0} shrink={0}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).not.toContain('flex-grow');
      expect(div.className).not.toContain('flex-shrink');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Flex className="custom-class">Content</Flex>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('custom-class');
    });

    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <Flex style={{ backgroundColor: 'red' }}>Content</Flex>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.style.backgroundColor).toBe('red');
    });

    it('onClick 핸들러가 호출된다', () => {
      let clicked = false;
      const { container } = render(<Flex onClick={() => (clicked = true)}>Content</Flex>);
      const div = container.firstChild as HTMLElement;
      div.click();
      expect(clicked).toBe(true);
    });
  });

  describe('복합 속성 조합', () => {
    it('여러 속성이 함께 적용된다', () => {
      const { container } = render(
        <Flex direction="col" justify="center" align="center" gap={4} wrap="wrap">
          Content
        </Flex>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('flex');
      expect(div.className).toContain('flex-col');
      expect(div.className).toContain('justify-center');
      expect(div.className).toContain('items-center');
      expect(div.className).toContain('gap-4');
      expect(div.className).toContain('flex-wrap');
    });
  });
});
