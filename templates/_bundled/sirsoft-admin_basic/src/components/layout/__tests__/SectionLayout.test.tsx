import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SectionLayout } from '../SectionLayout';

describe('SectionLayout 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('자식 요소를 렌더링한다', () => {
      render(<SectionLayout>Test Content</SectionLayout>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    it('section 태그로 렌더링된다', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.tagName).toBe('SECTION');
    });
  });

  describe('Title & Subtitle', () => {
    it('title이 렌더링된다', () => {
      render(<SectionLayout title="Test Title">Content</SectionLayout>);
      expect(screen.getByText('Test Title')).toBeTruthy();
    });

    it('subtitle이 렌더링된다', () => {
      render(<SectionLayout subtitle="Test Subtitle">Content</SectionLayout>);
      expect(screen.getByText('Test Subtitle')).toBeTruthy();
    });

    it('title과 subtitle이 함께 렌더링된다', () => {
      render(
        <SectionLayout title="Main Title" subtitle="Sub Title">
          Content
        </SectionLayout>
      );
      expect(screen.getByText('Main Title')).toBeTruthy();
      expect(screen.getByText('Sub Title')).toBeTruthy();
    });

    it('title과 subtitle이 없으면 헤더가 렌더링되지 않는다', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      // title/subtitle 없으면 mb-4 div가 없어야 함
      expect(section.querySelector('.mb-4')).toBeNull();
    });
  });

  describe('Padding', () => {
    it('padding none이 적용된다', () => {
      const { container } = render(<SectionLayout padding="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-0');
    });

    it('padding sm이 적용된다', () => {
      const { container } = render(<SectionLayout padding="sm">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-2');
    });

    it('padding md(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout padding="md">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-4');
    });

    it('padding lg이 적용된다', () => {
      const { container } = render(<SectionLayout padding="lg">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-6');
    });

    it('padding xl이 적용된다', () => {
      const { container } = render(<SectionLayout padding="xl">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-8');
    });
  });

  describe('Background', () => {
    it('background none(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout background="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('bg-');
    });

    it('background white가 적용된다', () => {
      const { container } = render(<SectionLayout background="white">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-white');
    });

    it('background gray가 적용된다', () => {
      const { container } = render(<SectionLayout background="gray">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-gray-50');
    });

    it('background primary가 적용된다', () => {
      const { container } = render(<SectionLayout background="primary">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-blue-50');
    });
  });

  describe('Border', () => {
    it('border가 적용되지 않는다(기본값)', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('border');
    });

    it('border가 적용된다', () => {
      const { container } = render(<SectionLayout border={true}>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('border');
      expect(section.className).toContain('border-gray-200');
    });
  });

  describe('Shadow', () => {
    it('shadow none(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('shadow');
    });

    it('shadow sm이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="sm">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-sm');
    });

    it('shadow md이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="md">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-md');
    });

    it('shadow lg이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="lg">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-lg');
    });
  });

  describe('Rounded', () => {
    it('rounded가 적용되지 않는다(기본값)', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('rounded');
    });

    it('rounded가 적용된다', () => {
      const { container } = render(<SectionLayout rounded={true}>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('rounded-lg');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<SectionLayout className="custom-section">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('custom-section');
    });

    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <SectionLayout style={{ backgroundColor: 'green' }}>Content</SectionLayout>
      );
      const section = container.firstChild as HTMLElement;
      expect(section.style.backgroundColor).toBe('green');
    });

    it('onClick 핸들러가 호출된다', () => {
      let clicked = false;
      const { container } = render(
        <SectionLayout onClick={() => (clicked = true)}>Content</SectionLayout>
      );
      const section = container.firstChild as HTMLElement;
      section.click();
      expect(clicked).toBe(true);
    });
  });

  describe('복합 속성 조합', () => {
    it('여러 속성이 함께 적용된다', () => {
      const { container } = render(
        <SectionLayout
          title="Section Title"
          subtitle="Section Subtitle"
          padding="lg"
          background="white"
          border={true}
          shadow="md"
          rounded={true}
        >
          Content
        </SectionLayout>
      );
      const section = container.firstChild as HTMLElement;

      // Title & Subtitle
      expect(screen.getByText('Section Title')).toBeTruthy();
      expect(screen.getByText('Section Subtitle')).toBeTruthy();

      // Styling
      expect(section.className).toContain('p-6');
      expect(section.className).toContain('bg-white');
      expect(section.className).toContain('border');
      expect(section.className).toContain('shadow-md');
      expect(section.className).toContain('rounded-lg');
    });

    it('카드 스타일 SectionLayout을 만들 수 있다', () => {
      const { container } = render(
        <SectionLayout
          title="Card Title"
          padding="lg"
          background="white"
          border={true}
          shadow="lg"
          rounded={true}
        >
          Card Content
        </SectionLayout>
      );
      const section = container.firstChild as HTMLElement;

      expect(screen.getByText('Card Title')).toBeTruthy();
      expect(screen.getByText('Card Content')).toBeTruthy();
      expect(section.className).toContain('p-6');
      expect(section.className).toContain('bg-white');
      expect(section.className).toContain('border');
      expect(section.className).toContain('shadow-lg');
      expect(section.className).toContain('rounded-lg');
    });
  });
});
