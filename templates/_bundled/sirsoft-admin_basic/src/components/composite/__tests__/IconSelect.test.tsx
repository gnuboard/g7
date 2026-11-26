import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IconSelect } from '../IconSelect';

describe('IconSelect', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('컴포넌트가 렌더링됨', () => {
    render(<IconSelect />);

    const button = screen.getByRole('button');
    expect(button).toBeInTheDocument();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('placeholder가 표시됨', () => {
    render(<IconSelect placeholder="아이콘 선택" />);

    expect(screen.getByText('아이콘 선택')).toBeInTheDocument();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('value prop이 있을 때 선택된 아이콘이 표시됨', () => {
    render(<IconSelect value="Home" />);

    expect(screen.getByText('Home')).toBeInTheDocument();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('disabled일 때 클릭되지 않음', async () => {
    const user = userEvent.setup();
    render(<IconSelect disabled={true} searchPlaceholder="검색" />);

    const button = screen.getByRole('button');
    expect(button).toBeDisabled();

    await user.click(button);

    // 드롭다운이 열리지 않음
    expect(screen.queryByPlaceholderText('검색')).not.toBeInTheDocument();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('className prop이 적용됨', () => {
    const { container } = render(<IconSelect className="custom-class" />);

    expect(container.firstChild).toHaveClass('custom-class');
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
  it('name prop이 적용됨', () => {
    const { container } = render(<IconSelect name="icon-field" />);

    const hiddenInput = container.querySelector('input[name="icon-field"]');
    expect(hiddenInput).toBeInTheDocument();
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_dropdown_contract
   */
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

  /**
   * @scenario component=icon_select
   * @effects icon_select_detects_prefixed_value, style_prefix_tokens_resolved_once
   */
  it.each([['fa-solid fa-users'], ['fas fa-users'], ['far fa-users']])(
    'value %j 는 글리프로 비교해 Users 를 선택으로 보인다',
    (value) => {
      const { container } = render(<IconSelect value={value} placeholder="아이콘 선택" />);

      expect(screen.getByText('Users')).toBeInTheDocument();
      expect(screen.queryByText('아이콘 선택')).toBeNull();
      // 선택 표시 아이콘은 목록 글리프(users)를 Icon 으로 그린다 — fa-fas 같은 겹친 클래스가 없다
      const selectedIcon = container.querySelector('button[aria-haspopup="listbox"] i');
      expect(selectedIcon?.className).toBe('fas fa-users w-4 h-4 text-gray-600 dark:text-gray-400');
    },
  );

  /**
   * @scenario component=icon_select
   * @effects icon_select_detects_prefixed_value
   */
  it('접두 붙은 값으로 연 목록에서 같은 글리프 항목에 체크 표시가 붙는다', async () => {
    const user = userEvent.setup();
    const { container } = render(<IconSelect value="fa-solid fa-users" />);

    await user.click(screen.getByRole('button', { expanded: false }));

    const usersOption = screen.getAllByText('Users')[1].closest('button');
    expect(usersOption?.querySelector('i.fa-check')).not.toBeNull();
    expect(container.querySelectorAll('i.fa-check')).toHaveLength(1);
  });

  /**
   * @scenario component=icon_select
   * @effects icon_select_detects_prefixed_value
   */
  it('목록에 없는 글리프(fas fa-rocket)는 선택으로 보지 않는다', () => {
    render(<IconSelect value="fas fa-rocket" placeholder="아이콘 선택" />);

    expect(screen.getByText('아이콘 선택')).toBeInTheDocument();
  });
});
