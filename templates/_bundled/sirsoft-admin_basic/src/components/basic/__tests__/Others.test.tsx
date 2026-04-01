import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { A } from '../A';
import { Ul } from '../Ul';
import { Li } from '../Li';
import { Nav } from '../Nav';
import { Section } from '../Section';
import { Div } from '../Div';
import { Img } from '../Img';
import { Svg } from '../Svg';
import { Hr } from '../Hr';

describe('기타 컴포넌트', () => {
  describe('A (링크) 컴포넌트', () => {
    it('a 요소가 렌더링된다', () => {
      const { container } = render(<A href="/test">링크 텍스트</A>);
      const a = container.querySelector('a');
      expect(a).toBeTruthy();
      expect(a?.href).toContain('/test');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<A href="#">클릭하세요</A>);
      expect(screen.getByText('클릭하세요')).toBeTruthy();
    });

    it('href 속성이 적용된다', () => {
      const { container } = render(<A href="https://example.com">외부 링크</A>);
      const a = container.querySelector('a');
      expect(a?.href).toBe('https://example.com/');
    });

    it('target 속성이 적용된다', () => {
      const { container } = render(
        <A href="/page" target="_blank">
          새 탭에서 열기
        </A>
      );
      const a = container.querySelector('a');
      expect(a?.target).toBe('_blank');
    });

    it('rel 속성이 적용된다', () => {
      const { container } = render(
        <A href="https://external.com" rel="noopener noreferrer">
          외부 링크
        </A>
      );
      const a = container.querySelector('a');
      expect(a?.rel).toBe('noopener noreferrer');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn((e) => e.preventDefault());
      render(
        <A href="#" onClick={handleClick}>
          클릭 가능한 링크
        </A>
      );
      const a = screen.getByText('클릭 가능한 링크');
      fireEvent.click(a);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <A href="#" className="custom-link">
          링크
        </A>
      );
      const a = container.querySelector('a');
      expect(a?.className).toContain('custom-link');
    });

    it('download 속성이 적용된다', () => {
      const { container } = render(
        <A href="/file.pdf" download="document.pdf">
          다운로드
        </A>
      );
      const a = container.querySelector('a');
      expect(a?.download).toBe('document.pdf');
    });
  });

  describe('Ul (순서없는 목록) 컴포넌트', () => {
    it('ul 요소가 렌더링된다', () => {
      const { container } = render(
        <Ul>
          <li>항목</li>
        </Ul>
      );
      const ul = container.querySelector('ul');
      expect(ul).toBeTruthy();
    });

    it('자식 li 요소를 렌더링한다', () => {
      render(
        <Ul>
          <li>항목 1</li>
          <li>항목 2</li>
        </Ul>
      );
      expect(screen.getByText('항목 1')).toBeTruthy();
      expect(screen.getByText('항목 2')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <Ul className="custom-ul">
          <li>항목</li>
        </Ul>
      );
      const ul = container.querySelector('ul');
      expect(ul?.className).toContain('custom-ul');
    });
  });

  describe('Li (목록 항목) 컴포넌트', () => {
    it('li 요소가 렌더링된다', () => {
      const { container } = render(
        <ul>
          <Li>목록 항목</Li>
        </ul>
      );
      const li = container.querySelector('li');
      expect(li).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <ul>
          <Li>테스트 항목</Li>
        </ul>
      );
      expect(screen.getByText('테스트 항목')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <ul>
          <Li className="custom-li">항목</Li>
        </ul>
      );
      const li = container.querySelector('li');
      expect(li?.className).toContain('custom-li');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(
        <ul>
          <Li onClick={handleClick}>클릭 가능한 항목</Li>
        </ul>
      );
      const li = screen.getByText('클릭 가능한 항목');
      fireEvent.click(li);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });
  });

  describe('Nav (네비게이션) 컴포넌트', () => {
    it('nav 요소가 렌더링된다', () => {
      const { container } = render(
        <Nav>
          <a href="/">홈</a>
        </Nav>
      );
      const nav = container.querySelector('nav');
      expect(nav).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <Nav>
          <ul>
            <li>메뉴 1</li>
            <li>메뉴 2</li>
          </ul>
        </Nav>
      );
      expect(screen.getByText('메뉴 1')).toBeTruthy();
      expect(screen.getByText('메뉴 2')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <Nav className="main-nav">
          <ul>
            <li>메뉴</li>
          </ul>
        </Nav>
      );
      const nav = container.querySelector('nav');
      expect(nav?.className).toContain('main-nav');
    });

    it('aria-label이 적용된다', () => {
      const { container } = render(<Nav aria-label="주 메뉴">내용</Nav>);
      const nav = container.querySelector('nav');
      expect(nav?.getAttribute('aria-label')).toBe('주 메뉴');
    });
  });

  describe('Section 컴포넌트', () => {
    it('section 요소가 렌더링된다', () => {
      const { container } = render(<Section>섹션 내용</Section>);
      const section = container.querySelector('section');
      expect(section).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <Section>
          <h2>섹션 제목</h2>
          <p>섹션 내용</p>
        </Section>
      );
      expect(screen.getByText('섹션 제목')).toBeTruthy();
      expect(screen.getByText('섹션 내용')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Section className="custom-section">내용</Section>);
      const section = container.querySelector('section');
      expect(section?.className).toContain('custom-section');
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<Section id="about">소개</Section>);
      const section = container.querySelector('section');
      expect(section?.id).toBe('about');
    });
  });

  describe('Div 컴포넌트', () => {
    it('div 요소가 렌더링된다', () => {
      const { container } = render(<Div>div 내용</Div>);
      const div = container.querySelector('div');
      expect(div).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(<Div>테스트 내용</Div>);
      expect(screen.getByText('테스트 내용')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Div className="container mx-auto">내용</Div>);
      const div = container.querySelector('div');
      expect(div?.className).toContain('container');
      expect(div?.className).toContain('mx-auto');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(<Div onClick={handleClick}>클릭 가능한 div</Div>);
      const div = screen.getByText('클릭 가능한 div');
      fireEvent.click(div);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('style 속성이 적용된다', () => {
      const { container } = render(
        <Div style={{ padding: '20px', backgroundColor: 'blue' }}>내용</Div>
      );
      const div = container.querySelector('div') as HTMLElement;
      expect(div.style.padding).toBe('20px');
      expect(div.style.backgroundColor).toBe('blue');
    });

    it('중첩된 div가 렌더링된다', () => {
      const { container } = render(
        <Div className="outer">
          <Div className="inner">중첩된 내용</Div>
        </Div>
      );
      const outerDiv = container.querySelector('.outer');
      const innerDiv = container.querySelector('.inner');
      expect(outerDiv).toBeTruthy();
      expect(innerDiv).toBeTruthy();
    });
  });

  describe('Img 컴포넌트', () => {
    it('img 요소가 렌더링된다', () => {
      const { container } = render(<Img src="/test.jpg" alt="테스트 이미지" />);
      const img = container.querySelector('img');
      expect(img).toBeTruthy();
    });

    it('src 속성이 적용된다', () => {
      const { container } = render(<Img src="/image.png" alt="이미지" />);
      const img = container.querySelector('img');
      expect(img?.src).toContain('/image.png');
    });

    it('alt 속성이 적용된다', () => {
      const { container } = render(<Img src="/test.jpg" alt="설명 텍스트" />);
      const img = container.querySelector('img');
      expect(img?.alt).toBe('설명 텍스트');
    });

    it('기본 alt는 빈 문자열이다', () => {
      const { container } = render(<Img src="/test.jpg" />);
      const img = container.querySelector('img');
      expect(img?.alt).toBe('');
    });

    it('width와 height 속성이 적용된다', () => {
      const { container } = render(<Img src="/test.jpg" alt="이미지" width={200} height={150} />);
      const img = container.querySelector('img');
      expect(img?.width).toBe(200);
      expect(img?.height).toBe(150);
    });

    it('loading 속성이 적용된다', () => {
      const { container } = render(<Img src="/test.jpg" alt="이미지" loading="lazy" />);
      const img = container.querySelector('img');
      expect(img?.getAttribute('loading')).toBe('lazy');
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Img src="/test.jpg" alt="이미지" className="rounded-lg" />);
      const img = container.querySelector('img');
      expect(img?.className).toContain('rounded-lg');
    });

    it('onLoad 이벤트가 작동한다', () => {
      const handleLoad = vi.fn();
      const { container } = render(<Img src="/test.jpg" alt="이미지" onLoad={handleLoad} />);
      const img = container.querySelector('img')!;
      fireEvent.load(img);
      expect(handleLoad).toHaveBeenCalledTimes(1);
    });

    it('onError 이벤트가 작동한다', () => {
      const handleError = vi.fn();
      const { container } = render(<Img src="/invalid.jpg" alt="이미지" onError={handleError} />);
      const img = container.querySelector('img')!;
      fireEvent.error(img);
      expect(handleError).toHaveBeenCalledTimes(1);
    });
  });

  describe('Svg 컴포넌트', () => {
    it('svg 요소가 렌더링된다', () => {
      const { container } = render(
        <Svg viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg).toBeTruthy();
    });

    it('viewBox 속성이 적용된다', () => {
      const { container } = render(
        <Svg viewBox="0 0 100 100">
          <rect x="0" y="0" width="100" height="100" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg?.getAttribute('viewBox')).toBe('0 0 100 100');
    });

    it('width와 height 속성이 적용된다', () => {
      const { container } = render(
        <Svg width="24" height="24">
          <path d="M0 0h24v24H0z" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg?.getAttribute('width')).toBe('24');
      expect(svg?.getAttribute('height')).toBe('24');
    });

    it('자식 요소를 렌더링한다', () => {
      const { container } = render(
        <Svg viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" fill="blue" />
          <rect x="5" y="5" width="14" height="14" fill="red" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      const circle = svg?.querySelector('circle');
      const rect = svg?.querySelector('rect');
      expect(circle).toBeTruthy();
      expect(rect).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <Svg className="icon icon-lg" viewBox="0 0 24 24">
          <path d="M0 0h24v24H0z" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg?.className.baseVal).toContain('icon');
      expect(svg?.className.baseVal).toContain('icon-lg');
    });

    it('fill과 stroke 속성이 적용된다', () => {
      const { container } = render(
        <Svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <circle cx="12" cy="12" r="10" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg?.getAttribute('fill')).toBe('none');
      expect(svg?.getAttribute('stroke')).toBe('currentColor');
    });

    it('aria 속성이 적용된다', () => {
      const { container } = render(
        <Svg viewBox="0 0 24 24" aria-label="아이콘 설명" role="img">
          <path d="M0 0h24v24H0z" />
        </Svg>
      );
      const svg = container.querySelector('svg');
      expect(svg?.getAttribute('aria-label')).toBe('아이콘 설명');
      expect(svg?.getAttribute('role')).toBe('img');
    });
  });

  describe('복합 사용 사례', () => {
    it('Nav 안에 Ul과 Li가 중첩된다', () => {
      render(
        <Nav>
          <Ul>
            <Li>
              <A href="/">홈</A>
            </Li>
            <Li>
              <A href="/about">소개</A>
            </Li>
          </Ul>
        </Nav>
      );

      expect(screen.getByText('홈')).toBeTruthy();
      expect(screen.getByText('소개')).toBeTruthy();
    });

    it('Section 안에 여러 컴포넌트가 중첩된다', () => {
      render(
        <Section>
          <Div className="header">
            <h2>섹션 제목</h2>
          </Div>
          <Div className="content">
            <p>내용</p>
            <Ul>
              <Li>항목 1</Li>
              <Li>항목 2</Li>
            </Ul>
          </Div>
        </Section>
      );

      expect(screen.getByText('섹션 제목')).toBeTruthy();
      expect(screen.getByText('항목 1')).toBeTruthy();
    });

    it('Div 안에 Img와 텍스트가 함께 렌더링된다', () => {
      const { container } = render(
        <Div className="card">
          <Img src="/product.jpg" alt="상품 이미지" className="card-image" />
          <Div className="card-body">
            <h3>상품명</h3>
            <p>상품 설명</p>
          </Div>
        </Div>
      );

      const img = container.querySelector('img');
      expect(img?.src).toContain('/product.jpg');
      expect(screen.getByText('상품명')).toBeTruthy();
    });
  });

  describe('Hr (수평선) 컴포넌트', () => {
    it('hr 요소가 렌더링된다', () => {
      const { container } = render(<Hr />);
      const hr = container.querySelector('hr');
      expect(hr).toBeTruthy();
    });

    it('className이 적용된다', () => {
      const { container } = render(<Hr className="border-gray-100 dark:border-gray-800 mb-4" />);
      const hr = container.querySelector('hr');
      expect(hr?.className).toContain('border-gray-100');
      expect(hr?.className).toContain('dark:border-gray-800');
    });

    it('추가 HTML 속성이 전달된다', () => {
      const { container } = render(<Hr data-testid="divider" />);
      const hr = container.querySelector('hr');
      expect(hr?.getAttribute('data-testid')).toBe('divider');
    });
  });
});
