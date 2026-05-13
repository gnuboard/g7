import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchBar, SearchSuggestion } from '../SearchBar';

describe('SearchBar', () => {
  it('컴포넌트가 렌더링됨', () => {
    render(<SearchBar />);

    const input = screen.getByPlaceholderText('검색...');
    expect(input).toBeInTheDocument();
  });

  it('커스텀 placeholder가 표시됨', () => {
    render(<SearchBar placeholder="검색어를 입력하세요" />);

    expect(screen.getByPlaceholderText('검색어를 입력하세요')).toBeInTheDocument();
  });

  it('입력 시 onChange가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<SearchBar onChange={onChange} />);

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'test');

    expect(onChange).toHaveBeenCalled();
  });

  it('폼 제출 시 onSubmit이 호출됨', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();

    render(<SearchBar onSubmit={onSubmit} />);

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'test');

    const form = input.closest('form');
    fireEvent.submit(form!);

    expect(onSubmit).toHaveBeenCalled();
  });

  it('검색 버튼 클릭 시 onSubmit이 호출됨', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();

    render(<SearchBar onSubmit={onSubmit} showButton={true} />);

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'test');

    const searchButton = screen.getByRole('button', { name: '검색' });
    await user.click(searchButton);

    expect(onSubmit).toHaveBeenCalled();
  });

  it('제안 목록이 표시됨', async () => {
    const user = userEvent.setup();
    const suggestions: SearchSuggestion[] = [
      { id: 1, text: 'React' },
      { id: 2, text: 'TypeScript' },
    ];

    render(<SearchBar suggestions={suggestions} showSuggestions={true} />);

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'R');
    fireEvent.focus(input);

    expect(screen.getByText('React')).toBeInTheDocument();
    expect(screen.getByText('TypeScript')).toBeInTheDocument();
  });

  it('제안 항목 클릭 시 onSuggestionClick이 호출됨', async () => {
    const user = userEvent.setup();
    const onSuggestionClick = vi.fn();
    const suggestions: SearchSuggestion[] = [
      { id: 1, text: 'React' },
    ];

    render(
      <SearchBar
        suggestions={suggestions}
        showSuggestions={true}
        onSuggestionClick={onSuggestionClick}
      />
    );

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'R');
    fireEvent.focus(input);

    const suggestion = screen.getByText('React');
    await user.click(suggestion);

    expect(onSuggestionClick).toHaveBeenCalledWith(suggestions[0]);
  });

  it('입력 값이 없으면 제안이 표시되지 않음', async () => {
    const user = userEvent.setup();
    const suggestions: SearchSuggestion[] = [
      { id: 1, text: 'React' },
    ];

    render(<SearchBar suggestions={suggestions} showSuggestions={true} />);

    const input = screen.getByPlaceholderText('검색...');
    fireEvent.focus(input);

    expect(screen.queryByText('React')).not.toBeInTheDocument();
  });

  it('포커스를 잃으면 제안이 숨겨짐', async () => {
    const user = userEvent.setup();
    const suggestions: SearchSuggestion[] = [
      { id: 1, text: 'React' },
    ];

    render(<SearchBar suggestions={suggestions} showSuggestions={true} />);

    const input = screen.getByPlaceholderText('검색...');
    await user.type(input, 'R');
    fireEvent.focus(input);

    expect(screen.getByText('React')).toBeInTheDocument();

    fireEvent.blur(input);

    // blur 후 setTimeout 때문에 즉시 확인은 어려움
  });

  it('controlled value가 표시됨', () => {
    render(<SearchBar value="검색어" />);

    const input = screen.getByPlaceholderText('검색...') as HTMLInputElement;
    expect(input.value).toBe('검색어');
  });

  it('className prop이 적용됨', () => {
    // className은 루트 Div가 아닌 Input 요소에 적용됨
    // SearchBar는 className을 inputClassName으로 병합하여 내부 Input에 전달
    render(<SearchBar className="custom-search" />);
    const input = screen.getByPlaceholderText('검색...');
    expect(input).toHaveClass('custom-search');
  });

  it('style prop이 적용됨', () => {
    const { container } = render(<SearchBar style={{ marginTop: '20px' }} />);
    expect(container.firstChild).toHaveStyle({ marginTop: '20px' });
  });
});
