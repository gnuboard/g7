import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RichSelect, RichSelectOption } from '../RichSelect';

describe('RichSelect', () => {
  const mockOptions: RichSelectOption[] = [
    { value: 'file1', label: 'home.json', size: '14.5 KB', updated_at: '2026-01-06' },
    { value: 'file2', label: '403.json', size: '2.8 KB', updated_at: '2026-01-05' },
    { value: 'file3', label: '404.json', size: '2.8 KB', updated_at: '2026-01-05' },
    { value: 'file4', label: 'routes.json', size: '857 B', updated_at: '2026-01-04', disabled: true },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<RichSelect options={mockOptions} />);

    // placeholder가 표시되어야 함
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('placeholder prop이 적용됨', () => {
    render(<RichSelect options={mockOptions} placeholder="파일 선택" />);

    expect(screen.getByText('파일 선택')).toBeInTheDocument();
  });

  it('트리거 버튼 클릭 시 드롭다운이 표시됨', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={mockOptions} placeholder="Select" />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    expect(screen.getByText('home.json')).toBeInTheDocument();
    expect(screen.getByText('403.json')).toBeInTheDocument();
  });

  it('옵션 클릭 시 선택되고 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<RichSelect options={mockOptions} placeholder="Select" onChange={onChange} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const option = screen.getByText('home.json');
    await user.click(option);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        target: { value: 'file1' },
      })
    );
    // 드롭다운이 닫힘
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('ESC 키로 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={mockOptions} placeholder="Select" />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    expect(screen.getByRole('listbox')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('외부 클릭 시 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(
      <div>
        <RichSelect options={mockOptions} placeholder="Select" />
        <button data-testid="outside">외부 버튼</button>
      </div>
    );

    const trigger = screen.getByRole('button', { name: /Select/i });
    await user.click(trigger);

    expect(screen.getByRole('listbox')).toBeInTheDocument();

    const outsideButton = screen.getByTestId('outside');
    await user.click(outsideButton);

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('disabled prop이 true일 때 클릭 불가', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={mockOptions} placeholder="Select" disabled />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    // 드롭다운이 열리지 않음
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('value prop으로 초기 선택값 설정', () => {
    render(<RichSelect options={mockOptions} value="file1" placeholder="Select" />);

    expect(screen.getByText('home.json')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <RichSelect options={mockOptions} placeholder="Select" className="custom-rich-select" />
    );

    expect(container.firstChild).toHaveClass('custom-rich-select');
  });

  it('옵션이 없을 때 빈 메시지 표시', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={[]} placeholder="Select" />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    // common.no_options 키가 반환됨 (t 함수 폴백)
    expect(screen.getByText('common.no_options')).toBeInTheDocument();
  });

  it('disabled 옵션은 클릭해도 선택되지 않음', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<RichSelect options={mockOptions} placeholder="Select" onChange={onChange} />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const disabledOption = screen.getByText('routes.json');
    await user.click(disabledOption);

    expect(onChange).not.toHaveBeenCalled();
  });

  it('선택된 옵션에 선택 표시가 됨', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={mockOptions} value="file1" placeholder="Select" />);

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const selectedOption = screen.getByRole('option', { selected: true });
    expect(selectedOption).toHaveAttribute('data-item-value', 'file1');
  });

  it('maxHeight prop이 적용됨', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <RichSelect options={mockOptions} placeholder="Select" maxHeight="200px" />
    );

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    const scrollContainer = container.querySelector('[style*="max-height"]');
    expect(scrollContainer).toHaveStyle({ maxHeight: '200px' });
  });

  it('다크 모드 클래스가 포함됨', () => {
    const { container } = render(<RichSelect options={mockOptions} placeholder="Select" />);

    const button = screen.getByRole('button');
    expect(button.className).toContain('dark:bg-gray-700');
    expect(button.className).toContain('dark:text-gray-200');
  });

  it('children이 제공되면 기본 렌더링 대신 children 사용', async () => {
    const user = userEvent.setup();
    render(
      <RichSelect options={mockOptions} placeholder="Select">
        <span data-testid="custom-item">Custom Item</span>
      </RichSelect>
    );

    const trigger = screen.getByRole('button');
    await user.click(trigger);

    // children이 각 옵션에 렌더링됨
    const customItems = screen.getAllByTestId('custom-item');
    expect(customItems.length).toBe(mockOptions.length);
  });

  it('aria 속성이 올바르게 설정됨', async () => {
    const user = userEvent.setup();
    render(<RichSelect options={mockOptions} value="file1" placeholder="Select" />);

    const trigger = screen.getByRole('button');
    expect(trigger).toHaveAttribute('aria-haspopup', 'listbox');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await user.click(trigger);

    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    const selectedOption = screen.getByRole('option', { selected: true });
    expect(selectedOption).toHaveAttribute('aria-selected', 'true');
  });
});
