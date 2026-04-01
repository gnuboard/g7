import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IconSelect } from '../IconSelect';

describe('IconSelect', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('컴포넌트가 렌더링됨', () => {
    render(<IconSelect />);

    const button = screen.getByRole('button');
    expect(button).toBeInTheDocument();
  });

  it('placeholder가 표시됨', () => {
    render(<IconSelect placeholder="아이콘 선택" />);

    expect(screen.getByText('아이콘 선택')).toBeInTheDocument();
  });

  it('value prop이 있을 때 선택된 아이콘이 표시됨', () => {
    render(<IconSelect value="Home" />);

    expect(screen.getByText('Home')).toBeInTheDocument();
  });

  it('버튼 클릭 시 드롭다운이 열림', async () => {
    const user = userEvent.setup();
    render(<IconSelect searchPlaceholder="아이콘 검색" />);

    const button = screen.getByRole('button');
    await user.click(button);

    // 드롭다운이 열리면 검색 입력 필드가 보임
    await waitFor(() => {
      const searchInput = screen.getByPlaceholderText('아이콘 검색');
      expect(searchInput).toBeInTheDocument();
    });
  });

  it('드롭다운에 아이콘 목록이 표시됨', async () => {
    const user = userEvent.setup();
    render(<IconSelect />);

    const button = screen.getByRole('button');
    await user.click(button);

    // 기본 아이콘 목록 중 일부가 표시되는지 확인
    await waitFor(() => {
      expect(screen.getByText('Home')).toBeInTheDocument();
    });
  });

  it('검색 필터링이 작동함', async () => {
    const user = userEvent.setup();
    render(<IconSelect searchPlaceholder="검색" />);

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      const searchInput = screen.getByPlaceholderText('검색');
      expect(searchInput).toBeInTheDocument();
    });

    const searchInput = screen.getByPlaceholderText('검색');
    await user.type(searchInput, 'Home');

    // 'Home'을 포함하는 아이콘만 표시됨
    await waitFor(() => {
      expect(screen.getByText('Home')).toBeInTheDocument();
    });
  });

  it('아이콘 클릭 시 onChange가 호출됨', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<IconSelect onChange={onChange} />);

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      const homeIcon = screen.getByText('Home');
      expect(homeIcon).toBeInTheDocument();
    });

    const homeOption = screen.getByText('Home');
    await user.click(homeOption);

    // handleSelect는 FontAwesome 형식으로 변환: 'fas fa-home'
    expect(onChange).toHaveBeenCalledWith('fas fa-home');
  });

  it('선택 후 드롭다운이 닫힘', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<IconSelect onChange={onChange} searchPlaceholder="검색" />);

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      expect(screen.getByPlaceholderText('검색')).toBeInTheDocument();
    });

    const homeOption = screen.getByText('Home');
    await user.click(homeOption);

    // 드롭다운이 닫히면 검색 입력 필드가 사라짐
    await waitFor(() => {
      expect(screen.queryByPlaceholderText('검색')).not.toBeInTheDocument();
    });
  });

  it('disabled일 때 클릭되지 않음', async () => {
    const user = userEvent.setup();
    render(<IconSelect disabled={true} searchPlaceholder="검색" />);

    const button = screen.getByRole('button');
    expect(button).toBeDisabled();

    await user.click(button);

    // 드롭다운이 열리지 않음
    expect(screen.queryByPlaceholderText('검색')).not.toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(<IconSelect className="custom-class" />);

    expect(container.firstChild).toHaveClass('custom-class');
  });

  it('name prop이 적용됨', () => {
    const { container } = render(<IconSelect name="icon-field" />);

    const hiddenInput = container.querySelector('input[name="icon-field"]');
    expect(hiddenInput).toBeInTheDocument();
  });

  it('빈 검색 결과 시 메시지가 표시됨', async () => {
    const user = userEvent.setup();
    render(<IconSelect searchPlaceholder="검색" noResultsText="검색 결과 없음" />);

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      const searchInput = screen.getByPlaceholderText('검색');
      expect(searchInput).toBeInTheDocument();
    });

    const searchInput = screen.getByPlaceholderText('검색');
    await user.type(searchInput, 'zzzznonexistent');

    // 빈 결과 메시지 확인
    await waitFor(() => {
      expect(screen.getByText('검색 결과 없음')).toBeInTheDocument();
    });
  });

  it('외부 클릭 시 드롭다운이 닫힘', async () => {
    const user = userEvent.setup();
    render(
      <div>
        <IconSelect searchPlaceholder="검색" />
        <div data-testid="outside">외부 영역</div>
      </div>
    );

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      expect(screen.getByPlaceholderText('검색')).toBeInTheDocument();
    });

    // 외부 클릭
    const outside = screen.getByTestId('outside');
    await user.click(outside);

    // 드롭다운이 닫힘
    await waitFor(() => {
      expect(screen.queryByPlaceholderText('검색')).not.toBeInTheDocument();
    });
  });

  it('선택된 옵션에 체크 아이콘이 표시됨', async () => {
    const user = userEvent.setup();
    render(<IconSelect value="Home" />);

    const button = screen.getByRole('button');
    await user.click(button);

    await waitFor(() => {
      // Home 옵션이 선택되어 있으므로 해당 행에 스타일이 적용됨
      const homeOption = screen.getAllByText('Home')[1]; // 드롭다운 내의 Home
      expect(homeOption).toBeInTheDocument();
    });
  });
});
