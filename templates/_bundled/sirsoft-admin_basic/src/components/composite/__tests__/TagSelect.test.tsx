import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagSelect, TagSelectOption } from '../TagSelect';

describe('TagSelect', () => {
  const mockOptions: TagSelectOption[] = [
    { value: 's', label: 'S' },
    { value: 'm', label: 'M' },
    { value: 'l', label: 'L' },
    { value: 'xl', label: 'XL' },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<TagSelect options={mockOptions} value={['s', 'm']} />);

    expect(screen.getByText('S')).toBeInTheDocument();
    expect(screen.getByText('M')).toBeInTheDocument();
  });

  it('선택된 값이 태그로 표시됨', () => {
    render(<TagSelect options={mockOptions} value={['s', 'm', 'l']} />);

    expect(screen.getByText('S')).toBeInTheDocument();
    expect(screen.getByText('M')).toBeInTheDocument();
    expect(screen.getByText('L')).toBeInTheDocument();
  });

  it('선택된 값이 없으면 placeholder가 표시됨', () => {
    render(<TagSelect options={mockOptions} value={[]} placeholder="No items selected" />);

    expect(screen.getByText('No items selected')).toBeInTheDocument();
  });

  it('빈 value prop일 때 placeholder 표시', () => {
    render(<TagSelect options={mockOptions} placeholder="Select items..." />);

    expect(screen.getByText('Select items...')).toBeInTheDocument();
  });

  it('태그 삭제 버튼 클릭 시 onChange가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <TagSelect options={mockOptions} value={['s', 'm', 'l']} onChange={onChange} />
    );

    // 'M' 태그의 삭제 버튼 클릭 (aria-label로 찾기)
    const removeBtn = screen.getByRole('button', { name: 'Remove M' });
    await user.click(removeBtn);

    expect(onChange).toHaveBeenCalledWith(['s', 'l']);
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <TagSelect options={mockOptions} value={['s']} className="custom-tag-select" />
    );

    expect(container.firstChild).toHaveClass('custom-tag-select');
  });

  it('options에 없는 값도 표시됨 (값 자체가 라벨로)', () => {
    render(<TagSelect options={mockOptions} value={['s', 'unknown']} />);

    expect(screen.getByText('S')).toBeInTheDocument();
    expect(screen.getByText('unknown')).toBeInTheDocument();
  });

  it('숫자 값도 지원됨', () => {
    const numericOptions: TagSelectOption[] = [
      { value: 1, label: 'One' },
      { value: 2, label: 'Two' },
      { value: 3, label: 'Three' },
    ];

    render(<TagSelect options={numericOptions} value={[1, 2]} />);

    expect(screen.getByText('One')).toBeInTheDocument();
    expect(screen.getByText('Two')).toBeInTheDocument();
  });

  it('여러 태그를 연속으로 삭제할 수 있음', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const { rerender } = render(
      <TagSelect options={mockOptions} value={['s', 'm', 'l']} onChange={onChange} />
    );

    // 첫 번째 삭제
    const sTag = screen.getByText('S').closest('div');
    const removeBtn1 = sTag?.querySelector('[role="button"]');
    if (removeBtn1) {
      await user.click(removeBtn1);
    }

    expect(onChange).toHaveBeenCalledWith(['m', 'l']);

    // 상태 업데이트 시뮬레이션
    rerender(
      <TagSelect options={mockOptions} value={['m', 'l']} onChange={onChange} />
    );

    // 두 번째 삭제
    const mTag = screen.getByText('M').closest('div');
    const removeBtn2 = mTag?.querySelector('[role="button"]');
    if (removeBtn2) {
      await user.click(removeBtn2);
    }

    expect(onChange).toHaveBeenCalledWith(['l']);
  });

  it('빈 options 배열도 처리됨', () => {
    render(<TagSelect options={[]} value={['custom-value']} />);

    expect(screen.getByText('custom-value')).toBeInTheDocument();
  });
});
