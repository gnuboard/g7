import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { H1 } from '../H1';
import { H2 } from '../H2';
import { H3 } from '../H3';
import { P } from '../P';
import { Span } from '../Span';
import { Label } from '../Label';

describe('Typography 컴포넌트', () => {
  describe('H1 컴포넌트', () => {
    it('h1 요소가 렌더링된다', () => {
      const { container } = render(<H1>제목 1</H1>);
      const h1 = container.querySelector('h1');
      expect(h1).toBeTruthy();
      expect(h1?.textContent).toBe('제목 1');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<H1>Main Heading</H1>);
      expect(screen.getByText('Main Heading')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<H1 className="custom-h1">제목</H1>);
      const h1 = container.querySelector('h1');
      expect(h1?.className).toContain('custom-h1');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(<H1 onClick={handleClick}>클릭 가능한 제목</H1>);
      const h1 = screen.getByText('클릭 가능한 제목');
      fireEvent.click(h1);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('id 속성이 적용된다', () => {
      const { container } = render(<H1 id="main-title">제목</H1>);
      const h1 = container.querySelector('h1');
      expect(h1?.id).toBe('main-title');
    });
  });

  describe('H2 컴포넌트', () => {
    it('h2 요소가 렌더링된다', () => {
      const { container } = render(<H2>제목 2</H2>);
      const h2 = container.querySelector('h2');
      expect(h2).toBeTruthy();
      expect(h2?.textContent).toBe('제목 2');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<H2>Sub Heading</H2>);
      expect(screen.getByText('Sub Heading')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<H2 className="custom-h2">제목</H2>);
      const h2 = container.querySelector('h2');
      expect(h2?.className).toContain('custom-h2');
    });
  });

  describe('H3 컴포넌트', () => {
    it('h3 요소가 렌더링된다', () => {
      const { container } = render(<H3>제목 3</H3>);
      const h3 = container.querySelector('h3');
      expect(h3).toBeTruthy();
      expect(h3?.textContent).toBe('제목 3');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<H3>Section Heading</H3>);
      expect(screen.getByText('Section Heading')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<H3 className="custom-h3">제목</H3>);
      const h3 = container.querySelector('h3');
      expect(h3?.className).toContain('custom-h3');
    });
  });

  describe('P 컴포넌트', () => {
    it('p 요소가 렌더링된다', () => {
      const { container } = render(<P>단락 텍스트</P>);
      const p = container.querySelector('p');
      expect(p).toBeTruthy();
      expect(p?.textContent).toBe('단락 텍스트');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<P>This is a paragraph.</P>);
      expect(screen.getByText('This is a paragraph.')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<P className="custom-paragraph">텍스트</P>);
      const p = container.querySelector('p');
      expect(p?.className).toContain('custom-paragraph');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(<P onClick={handleClick}>클릭 가능한 단락</P>);
      const p = screen.getByText('클릭 가능한 단락');
      fireEvent.click(p);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('여러 줄 텍스트를 렌더링한다', () => {
      const multilineText = '첫 번째 줄\n두 번째 줄';
      render(<P>{multilineText}</P>);
      expect(screen.getByText(/첫 번째 줄/)).toBeTruthy();
    });
  });

  describe('Span 컴포넌트', () => {
    it('span 요소가 렌더링된다', () => {
      const { container } = render(<Span>인라인 텍스트</Span>);
      const span = container.querySelector('span');
      expect(span).toBeTruthy();
      expect(span?.textContent).toBe('인라인 텍스트');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<Span>Inline text</Span>);
      expect(screen.getByText('Inline text')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Span className="custom-span">텍스트</Span>);
      const span = container.querySelector('span');
      expect(span?.className).toContain('custom-span');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(<Span onClick={handleClick}>클릭 가능한 span</Span>);
      const span = screen.getByText('클릭 가능한 span');
      fireEvent.click(span);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Span data-testid="test-span">텍스트</Span>);
      const span = container.querySelector('span');
      expect(span?.getAttribute('data-testid')).toBe('test-span');
    });
  });

  describe('Label 컴포넌트', () => {
    it('label 요소가 렌더링된다', () => {
      const { container } = render(<Label>레이블 텍스트</Label>);
      const label = container.querySelector('label');
      expect(label).toBeTruthy();
      expect(label?.textContent).toBe('레이블 텍스트');
    });

    it('자식 요소를 렌더링한다', () => {
      render(<Label>Form Label</Label>);
      expect(screen.getByText('Form Label')).toBeTruthy();
    });

    it('htmlFor 속성이 적용된다', () => {
      const { container } = render(<Label htmlFor="input-id">레이블</Label>);
      const label = container.querySelector('label');
      expect(label?.htmlFor).toBe('input-id');
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Label className="custom-label">레이블</Label>);
      const label = container.querySelector('label');
      expect(label?.className).toContain('custom-label');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(<Label onClick={handleClick}>클릭 가능한 레이블</Label>);
      const label = screen.getByText('클릭 가능한 레이블');
      fireEvent.click(label);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('input과 연결된다', () => {
      const { container } = render(
        <>
          <Label htmlFor="test-input">이름</Label>
          <input id="test-input" type="text" />
        </>
      );

      const label = container.querySelector('label');
      const input = container.querySelector('input');

      expect(label?.htmlFor).toBe('test-input');
      expect(input?.id).toBe('test-input');
    });
  });

  describe('공통 HTML 속성', () => {
    it('모든 컴포넌트에 style 속성이 적용된다', () => {
      const style = { color: 'red', fontSize: '20px' };

      const { container: h1Container } = render(<H1 style={style}>H1</H1>);
      const { container: pContainer } = render(<P style={style}>P</P>);
      const { container: spanContainer } = render(<Span style={style}>Span</Span>);

      const h1 = h1Container.querySelector('h1') as HTMLElement;
      const p = pContainer.querySelector('p') as HTMLElement;
      const span = spanContainer.querySelector('span') as HTMLElement;

      expect(h1.style.color).toBe('red');
      expect(p.style.color).toBe('red');
      expect(span.style.color).toBe('red');
    });

    it('모든 컴포넌트에 aria 속성이 적용된다', () => {
      const { container: h1Container } = render(<H1 aria-label="메인 제목">H1</H1>);
      const { container: pContainer } = render(<P aria-describedby="desc">P</P>);
      const { container: spanContainer } = render(<Span role="status">Span</Span>);

      const h1 = h1Container.querySelector('h1');
      const p = pContainer.querySelector('p');
      const span = spanContainer.querySelector('span');

      expect(h1?.getAttribute('aria-label')).toBe('메인 제목');
      expect(p?.getAttribute('aria-describedby')).toBe('desc');
      expect(span?.getAttribute('role')).toBe('status');
    });
  });

  describe('중첩된 요소', () => {
    it('P 안에 Span이 중첩된다', () => {
      render(
        <P>
          일반 텍스트 <Span className="highlight">강조 텍스트</Span> 계속
        </P>
      );

      expect(screen.getByText(/일반 텍스트/)).toBeTruthy();
      expect(screen.getByText('강조 텍스트')).toBeTruthy();
    });

    it('Label 안에 Span이 중첩된다', () => {
      render(
        <Label>
          <Span>필수</Span> 사용자명
        </Label>
      );

      expect(screen.getByText('필수')).toBeTruthy();
      expect(screen.getByText(/사용자명/)).toBeTruthy();
    });

    it('H1 안에 Span이 중첩된다', () => {
      render(
        <H1>
          메인 제목 <Span className="subtitle">부제목</Span>
        </H1>
      );

      expect(screen.getByText(/메인 제목/)).toBeTruthy();
      expect(screen.getByText('부제목')).toBeTruthy();
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const handleClick = vi.fn();
      const { container } = render(
        <P
          id="intro"
          className="text-lg text-gray-700"
          style={{ marginBottom: '20px' }}
          onClick={handleClick}
          data-testid="intro-paragraph"
        >
          소개 텍스트
        </P>
      );

      const p = container.querySelector('p')!;
      expect(p.id).toBe('intro');
      expect(p.className).toContain('text-lg');
      expect(p.className).toContain('text-gray-700');
      expect((p as HTMLElement).style.marginBottom).toBe('20px');
      expect(p.getAttribute('data-testid')).toBe('intro-paragraph');

      fireEvent.click(p);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });
  });
});
