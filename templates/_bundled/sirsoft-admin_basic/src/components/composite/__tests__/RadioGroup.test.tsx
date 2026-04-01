import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RadioGroup } from '../RadioGroup';

describe('RadioGroup', () => {
  const defaultOptions = [
    { value: 'option1', label: '옵션 1' },
    { value: 'option2', label: '옵션 2' },
    { value: 'option3', label: '옵션 3' },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<RadioGroup name="test" options={defaultOptions} />);

    // 모든 라디오 버튼이 렌더링되는지 확인
    const radios = screen.getAllByRole('radio');
    expect(radios).toHaveLength(3);
  });

  it('모든 옵션 라벨이 표시됨', () => {
    render(<RadioGroup name="test" options={defaultOptions} />);

    expect(screen.getByText('옵션 1')).toBeInTheDocument();
    expect(screen.getByText('옵션 2')).toBeInTheDocument();
    expect(screen.getByText('옵션 3')).toBeInTheDocument();
  });

  it('그룹 라벨이 표시됨', () => {
    render(<RadioGroup name="test" options={defaultOptions} label="선택하세요" />);

    expect(screen.getByText('선택하세요')).toBeInTheDocument();
  });

  it('에러 메시지가 표시됨', () => {
    render(<RadioGroup name="test" options={defaultOptions} error="필수 항목입니다" />);

    expect(screen.getByText('필수 항목입니다')).toBeInTheDocument();
  });

  it('value prop으로 초기 선택 상태가 설정됨', () => {
    render(<RadioGroup name="test" options={defaultOptions} value="option2" />);

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios[0].checked).toBe(false);
    expect(radios[1].checked).toBe(true);
    expect(radios[2].checked).toBe(false);
  });

  it('onChange 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<RadioGroup name="test" options={defaultOptions} onChange={onChange} />);

    const radios = screen.getAllByRole('radio');
    await user.click(radios[1]);

    expect(onChange).toHaveBeenCalledTimes(1);
    const callArg = onChange.mock.calls[0][0];
    expect(callArg).toHaveProperty('target');
    expect(callArg.target.value).toBe('option2');
  });

  it('라디오 버튼 클릭 시 선택 상태가 변경됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<RadioGroup name="test" options={defaultOptions} onChange={onChange} />);

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];

    // 첫 번째 옵션 클릭
    await user.click(radios[0]);
    expect(radios[0].checked).toBe(true);

    // 두 번째 옵션 클릭
    await user.click(radios[1]);
    expect(radios[1].checked).toBe(true);
    expect(radios[0].checked).toBe(false);
  });

  it('disabled일 때 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<RadioGroup name="test" options={defaultOptions} onChange={onChange} disabled={true} />);

    const radios = screen.getAllByRole('radio');
    radios.forEach((radio) => {
      expect(radio).toBeDisabled();
    });

    await user.click(radios[1]);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('개별 옵션이 disabled일 때 해당 옵션만 비활성화됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const optionsWithDisabled = [
      { value: 'option1', label: '옵션 1' },
      { value: 'option2', label: '옵션 2', disabled: true },
      { value: 'option3', label: '옵션 3' },
    ];

    render(<RadioGroup name="test" options={optionsWithDisabled} onChange={onChange} />);

    const radios = screen.getAllByRole('radio');
    expect(radios[0]).not.toBeDisabled();
    expect(radios[1]).toBeDisabled();
    expect(radios[2]).not.toBeDisabled();

    // 비활성화된 옵션 클릭 시도
    await user.click(radios[1]);
    expect(onChange).not.toHaveBeenCalled();

    // 활성화된 옵션 클릭
    await user.click(radios[0]);
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('inline prop이 적용됨', () => {
    const { container } = render(
      <RadioGroup name="test" options={defaultOptions} inline={true} />
    );

    // inline 모드에서는 flex-wrap 클래스가 적용됨
    const optionsWrapper = container.querySelector('.flex-wrap');
    expect(optionsWrapper).toBeInTheDocument();
  });

  it('inline이 아닐 때 세로 배치됨', () => {
    const { container } = render(
      <RadioGroup name="test" options={defaultOptions} inline={false} />
    );

    // 세로 배치에서는 flex-col 클래스가 적용됨
    const optionsWrapper = container.querySelector('.flex-col');
    expect(optionsWrapper).toBeInTheDocument();
  });

  it('size="sm" 스타일이 적용됨', () => {
    const { container } = render(
      <RadioGroup name="test" options={defaultOptions} size="sm" />
    );

    const radio = container.querySelector('input[type="radio"]');
    expect(radio).toHaveClass('w-3.5', 'h-3.5');
  });

  it('size="md" 스타일이 적용됨 (기본값)', () => {
    const { container } = render(<RadioGroup name="test" options={defaultOptions} />);

    const radio = container.querySelector('input[type="radio"]');
    expect(radio).toHaveClass('w-4', 'h-4');
  });

  it('size="lg" 스타일이 적용됨', () => {
    const { container } = render(
      <RadioGroup name="test" options={defaultOptions} size="lg" />
    );

    const radio = container.querySelector('input[type="radio"]');
    expect(radio).toHaveClass('w-5', 'h-5');
  });

  it('name prop이 모든 라디오에 적용됨', () => {
    render(<RadioGroup name="test-group" options={defaultOptions} />);

    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    radios.forEach((radio) => {
      expect(radio.name).toBe('test-group');
    });
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <RadioGroup name="test" options={defaultOptions} className="custom-radio-group" />
    );

    const wrapper = container.firstChild as HTMLElement;
    expect(wrapper).toHaveClass('custom-radio-group');
  });

  it('다크 모드 스타일이 포함됨', () => {
    const { container } = render(<RadioGroup name="test" options={defaultOptions} />);

    const radio = container.querySelector('input[type="radio"]');
    expect(radio).toHaveClass('dark:bg-gray-700');
  });

  it('value prop 변경 시 선택 상태가 동기화됨', () => {
    const { rerender } = render(
      <RadioGroup name="test" options={defaultOptions} value="option1" />
    );

    let radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios[0].checked).toBe(true);

    rerender(<RadioGroup name="test" options={defaultOptions} value="option3" />);

    radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios[0].checked).toBe(false);
    expect(radios[2].checked).toBe(true);
  });
});
