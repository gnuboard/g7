import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Code } from '../Code';

describe('Code 컴포넌트', () => {
  it('code 요소가 렌더링된다', () => {
    const { container } = render(<Code>const x = 1;</Code>);
    const code = container.querySelector('code');
    expect(code).toBeTruthy();
    expect(code?.textContent).toBe('const x = 1;');
  });

  it('자식 요소를 렌더링한다', () => {
    render(<Code>console.log("Hello");</Code>);
    expect(screen.getByText('console.log("Hello");')).toBeTruthy();
  });

  it('사용자 정의 클래스가 추가된다', () => {
    const { container } = render(<Code className="custom-code">코드</Code>);
    const code = container.querySelector('code');
    expect(code?.className).toContain('custom-code');
  });

  it('onClick 이벤트가 작동한다', () => {
    const handleClick = vi.fn();
    render(<Code onClick={handleClick}>클릭 가능한 코드</Code>);
    const code = screen.getByText('클릭 가능한 코드');
    fireEvent.click(code);
    expect(handleClick).toHaveBeenCalledTimes(1);
  });

  it('id 속성이 적용된다', () => {
    const { container } = render(<Code id="code-snippet">snippet</Code>);
    const code = container.querySelector('code');
    expect(code?.id).toBe('code-snippet');
  });

  it('data 속성이 적용된다', () => {
    const { container } = render(<Code data-testid="test-code">테스트</Code>);
    const code = container.querySelector('code');
    expect(code?.getAttribute('data-testid')).toBe('test-code');
  });

  it('style 속성이 적용된다', () => {
    const style = { color: 'red', fontSize: '12px' };
    const { container } = render(<Code style={style}>styled code</Code>);
    const code = container.querySelector('code') as HTMLElement;
    expect(code.style.color).toBe('red');
    expect(code.style.fontSize).toBe('12px');
  });

  it('aria 속성이 적용된다', () => {
    const { container } = render(<Code aria-label="코드 스니펫">snippet</Code>);
    const code = container.querySelector('code');
    expect(code?.getAttribute('aria-label')).toBe('코드 스니펫');
  });

  it('빈 className이어도 렌더링된다', () => {
    const { container } = render(<Code>코드</Code>);
    const code = container.querySelector('code');
    expect(code).toBeTruthy();
    expect(code?.className).toBe('');
  });

  it('여러 줄 코드를 렌더링한다', () => {
    const multilineCode = 'const x = 1;\nconst y = 2;';
    render(<Code>{multilineCode}</Code>);
    expect(screen.getByText(/const x = 1/)).toBeTruthy();
  });

  it('복합 Props가 함께 적용된다', () => {
    const handleClick = vi.fn();
    const { container } = render(
      <Code
        id="main-code"
        className="bg-gray-100 text-sm"
        style={{ padding: '4px' }}
        onClick={handleClick}
        data-testid="code-block"
      >
        npm install
      </Code>
    );

    const code = container.querySelector('code')!;
    expect(code.id).toBe('main-code');
    expect(code.className).toContain('bg-gray-100');
    expect(code.className).toContain('text-sm');
    expect((code as HTMLElement).style.padding).toBe('4px');
    expect(code.getAttribute('data-testid')).toBe('code-block');

    fireEvent.click(code);
    expect(handleClick).toHaveBeenCalledTimes(1);
  });

  it('특수 문자가 포함된 코드를 렌더링한다', () => {
    render(<Code>{'<div>Hello</div>'}</Code>);
    expect(screen.getByText('<div>Hello</div>')).toBeTruthy();
  });
});
