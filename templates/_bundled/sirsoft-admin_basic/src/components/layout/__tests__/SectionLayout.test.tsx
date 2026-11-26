import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SectionLayout } from '../SectionLayout';

describe('SectionLayout 컴포넌트', () => {
  describe('기본 렌더링', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('자식 요소를 렌더링한다', () => {
      render(<SectionLayout>Test Content</SectionLayout>);
      expect(screen.getByText('Test Content')).toBeTruthy();
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('section 태그로 렌더링된다', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.tagName).toBe('SECTION');
    });
  });

  describe('Title & Subtitle', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('title이 렌더링된다', () => {
      render(<SectionLayout title="Test Title">Content</SectionLayout>);
      expect(screen.getByText('Test Title')).toBeTruthy();
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('subtitle이 렌더링된다', () => {
      render(<SectionLayout subtitle="Test Subtitle">Content</SectionLayout>);
      expect(screen.getByText('Test Subtitle')).toBeTruthy();
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('title과 subtitle이 함께 렌더링된다', () => {
      render(
        <SectionLayout title="Main Title" subtitle="Sub Title">
          Content
        </SectionLayout>
      );
      expect(screen.getByText('Main Title')).toBeTruthy();
      expect(screen.getByText('Sub Title')).toBeTruthy();
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_renders_children_and_header
     */
    it('title과 subtitle이 없으면 헤더가 렌더링되지 않는다', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      // title/subtitle 없으면 mb-4 div가 없어야 함
      expect(section.querySelector('.mb-4')).toBeNull();
    });
  });

  describe('Padding', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('padding none이 적용되면 padding 토큰을 추가하지 않는다', () => {
      // 호출처 className 자산(admin-card 등)이 padding 을 책임지도록 컴포넌트가
      // padding 토큰을 prepend 하지 않음. p-0 도 호출처 자산의 padding 을
      // 의도치 않게 무력화하므로 추가하지 않는다.
      const { container } = render(<SectionLayout padding="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('p-0');
      expect(section.className).not.toMatch(/\bp-\d+\b/);
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('padding sm이 적용된다', () => {
      const { container } = render(<SectionLayout padding="sm">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-2');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('padding md(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout padding="md">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-4');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('padding lg이 적용된다', () => {
      const { container } = render(<SectionLayout padding="lg">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-6');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('padding xl이 적용된다', () => {
      const { container } = render(<SectionLayout padding="xl">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('p-8');
    });
  });

  describe('Background', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('background none(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout background="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('bg-');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('background white가 적용된다', () => {
      const { container } = render(<SectionLayout background="white">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-white');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('background gray가 적용된다', () => {
      const { container } = render(<SectionLayout background="gray">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-gray-50');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('background primary가 적용된다', () => {
      const { container } = render(<SectionLayout background="primary">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('bg-blue-50');
    });
  });

  describe('Border', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('border가 적용되지 않는다(기본값)', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('border');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('border가 적용된다', () => {
      const { container } = render(<SectionLayout border={true}>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('border');
      expect(section.className).toContain('border-gray-200');
    });
  });

  describe('Shadow', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('shadow none(기본값)이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="none">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('shadow');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('shadow sm이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="sm">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-sm');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('shadow md이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="md">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-md');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('shadow lg이 적용된다', () => {
      const { container } = render(<SectionLayout shadow="lg">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('shadow-lg');
    });
  });

  describe('Rounded', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('rounded가 적용되지 않는다(기본값)', () => {
      const { container } = render(<SectionLayout>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).not.toContain('rounded');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
    it('rounded가 적용된다', () => {
      const { container } = render(<SectionLayout rounded={true}>Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('rounded-lg');
    });
  });

  describe('사용자 정의 Props', () => {
    /**
     * @scenario component=section_layout
     * @effects section_layout_custom_props
     */
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<SectionLayout className="custom-section">Content</SectionLayout>);
      const section = container.firstChild as HTMLElement;
      expect(section.className).toContain('custom-section');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_custom_props
     */
    it('인라인 스타일이 적용된다', () => {
      const { container } = render(
        <SectionLayout style={{ backgroundColor: 'green' }}>Content</SectionLayout>
      );
      const section = container.firstChild as HTMLElement;
      expect(section.style.backgroundColor).toBe('green');
    });

    /**
     * @scenario component=section_layout
     * @effects section_layout_custom_props
     */
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
    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
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

    /**
     * @scenario component=section_layout
     * @effects section_layout_style_variants
     */
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
