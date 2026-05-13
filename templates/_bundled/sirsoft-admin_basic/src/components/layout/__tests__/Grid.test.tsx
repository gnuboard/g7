import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Grid } from '../Grid';

describe('Grid 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('자식 요소를 렌더링한다', () => {
      render(<Grid>Test Content</Grid>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    it('기본 grid 클래스가 적용된다', () => {
      const { container } = render(<Grid>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid');
    });

    it('기본 1 컬럼이 적용된다', () => {
      const { container } = render(<Grid>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-1');
    });
  });

  describe('Columns (컬럼 수)', () => {
    it('2 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={2}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-2');
    });

    it('3 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={3}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-3');
    });

    it('4 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-4');
    });

    it('12 컬럼이 적용된다', () => {
      const { container } = render(<Grid cols={12}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-12');
    });
  });

  describe('Responsive (반응형)', () => {
    it('sm 브레이크포인트가 적용된다', () => {
      const { container } = render(<Grid cols={1} responsive={{ sm: 2 }}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-1');
      expect(div.className).toContain('sm:grid-cols-2');
    });

    it('md 브레이크포인트가 적용된다', () => {
      const { container } = render(<Grid cols={1} responsive={{ md: 3 }}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('md:grid-cols-3');
    });

    it('lg 브레이크포인트가 적용된다', () => {
      const { container } = render(<Grid cols={1} responsive={{ lg: 4 }}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('lg:grid-cols-4');
    });

    it('xl 브레이크포인트가 적용된다', () => {
      const { container } = render(<Grid cols={1} responsive={{ xl: 5 }}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('xl:grid-cols-5');
    });

    it('2xl 브레이크포인트가 적용된다', () => {
      const { container } = render(<Grid cols={1} responsive={{ '2xl': 6 }}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('2xl:grid-cols-6');
    });

    it('모든 브레이크포인트가 함께 적용된다', () => {
      const { container } = render(
        <Grid cols={1} responsive={{ sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 }}>
          Content
        </Grid>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-cols-1');
      expect(div.className).toContain('sm:grid-cols-2');
      expect(div.className).toContain('md:grid-cols-3');
      expect(div.className).toContain('lg:grid-cols-4');
      expect(div.className).toContain('xl:grid-cols-5');
      expect(div.className).toContain('2xl:grid-cols-6');
    });
  });

  describe('Gap (간격)', () => {
    it('gap 클래스가 적용된다', () => {
      const { container } = render(<Grid gap={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-4');
    });

    it('rowGap 클래스가 적용된다', () => {
      const { container } = render(<Grid rowGap={3}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-y-3');
    });

    it('colGap 클래스가 적용된다', () => {
      const { container } = render(<Grid colGap={5}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-x-5');
    });

    it('gap, rowGap, colGap이 함께 적용된다', () => {
      const { container } = render(<Grid gap={2} rowGap={3} colGap={4}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('gap-2');
      expect(div.className).toContain('gap-y-3');
      expect(div.className).toContain('gap-x-4');
    });

    it('gap이 0이면 적용되지 않는다', () => {
      const { container } = render(<Grid gap={0}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).not.toContain('gap-');
    });
  });

  describe('Auto Rows & Cols', () => {
    it('autoRows가 적용된다', () => {
      const { container } = render(<Grid autoRows="auto">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-auto');
    });

    it('autoRows min 값이 적용된다', () => {
      const { container } = render(<Grid autoRows="min">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-min');
    });

    it('autoRows max 값이 적용된다', () => {
      const { container } = render(<Grid autoRows="max">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-rows-max');
    });

    it('autoCols가 적용된다', () => {
      const { container } = render(<Grid autoCols="auto">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-auto');
    });

    it('autoCols min 값이 적용된다', () => {
      const { container } = render(<Grid autoCols="min">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-min');
    });

    it('autoCols max 값이 적용된다', () => {
      const { container } = render(<Grid autoCols="max">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('auto-cols-max');
    });
  });

  describe('Grid Flow', () => {
    it('flow row가 적용된다', () => {
      const { container } = render(<Grid flow="row">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-row');
    });

    it('flow col이 적용된다', () => {
      const { container } = render(<Grid flow="col">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-col');
    });

    it('flow dense가 적용된다', () => {
      const { container } = render(<Grid flow="dense">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid-flow-dense');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Grid className="custom-class">Content</Grid>);
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('custom-class');
    });

    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <Grid style={{ backgroundColor: 'blue' }}>Content</Grid>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.style.backgroundColor).toBe('blue');
    });

    it('onClick 핸들러가 호출된다', () => {
      let clicked = false;
      const { container } = render(<Grid onClick={() => (clicked = true)}>Content</Grid>);
      const div = container.firstChild as HTMLElement;
      div.click();
      expect(clicked).toBe(true);
    });
  });

  describe('복합 속성 조합', () => {
    it('여러 속성이 함께 적용된다', () => {
      const { container } = render(
        <Grid
          cols={2}
          responsive={{ md: 3, lg: 4 }}
          gap={4}
          rowGap={6}
          autoRows="min"
          flow="row"
        >
          Content
        </Grid>
      );
      const div = container.firstChild as HTMLElement;
      expect(div.className).toContain('grid');
      expect(div.className).toContain('grid-cols-2');
      expect(div.className).toContain('md:grid-cols-3');
      expect(div.className).toContain('lg:grid-cols-4');
      expect(div.className).toContain('gap-4');
      expect(div.className).toContain('gap-y-6');
      expect(div.className).toContain('auto-rows-min');
      expect(div.className).toContain('grid-flow-row');
    });
  });
});
