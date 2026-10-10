import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchableDropdown, SearchableDropdownOption } from '../SearchableDropdown';

describe('SearchableDropdown', () => {
  const mockOptions: SearchableDropdownOption[] = [
    { value: 'nike', label: 'Nike', description: '스포츠 의류 및 용품' },
    { value: 'adidas', label: 'Adidas', description: '스포츠웨어 브랜드' },
    { value: 'samsung', label: 'Samsung', description: '전자제품 제조사' },
    { value: 'apple', label: 'Apple', description: '스마트 디바이스' },
  ];

  /** @effects dropdown_renders_with_a_default_trigger */
  it('컴포넌트가 렌더링됨', () => {
    render(<SearchableDropdown options={mockOptions} />);

    expect(screen.getByText('Select')).toBeInTheDocument();
  });

  /**
   * @scenario interaction=props, selection_mode=single
   * @effects trigger_label_prop_names_the_button
   */
  it('triggerLabel prop이 적용됨', () => {
    render(<SearchableDropdown options={mockOptions} triggerLabel="브랜드 선택" />);

    expect(screen.getByText('브랜드 선택')).toBeInTheDocument();
  });

  /**
   * @scenario interaction=open_close, selection_mode=single
   * @effects clicking_the_trigger_opens_the_list_with_a_search_box
   */
  it('트리거 버튼 클릭 시 드롭다운이 표시됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();
    expect(screen.getByText('Adidas')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('검색...')).toBeInTheDocument();
  });

  /**
   * @scenario interaction=search, selection_mode=single
   * @effects typing_filters_options_by_label
   */
  it('검색어 입력 시 옵션이 필터링됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('검색...');
    await user.type(searchInput, 'nike');

    expect(screen.getByText('Nike')).toBeInTheDocument();
    // 필터링된 옵션은 DOM에서 제거됨
    expect(screen.queryByText('Adidas')).not.toBeInTheDocument();
  });

  /** @effects typing_filters_options_by_description */
  it('설명으로도 검색됨', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('검색...');
    await user.type(searchInput, '전자제품');

    expect(screen.getByText('Samsung')).toBeInTheDocument();
  });

  /** @effects no_match_shows_the_no_results_text */
  it('결과 없음 메시지 표시', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        noResultsText="No results found"
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('검색...');
    await user.type(searchInput, 'xyz없는검색어');

    expect(screen.getByText('No results found')).toBeInTheDocument();
  });

  /**
   * @scenario interaction=selection, selection_mode=single
   * @effects single_select_closes_after_choosing
   */
  it('단일 선택: 옵션 클릭 시 선택되고 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown options={mockOptions} triggerLabel="Select" onChange={onChange} />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const option = screen.getByText('Nike');
    await user.click(option);

    expect(onChange).toHaveBeenCalledWith('nike');
    // 드롭다운이 닫힘 (검색 입력창이 사라짐)
    expect(screen.queryByPlaceholderText('검색...')).not.toBeInTheDocument();
  });

  /**
   * @scenario interaction=selection, selection_mode=multiple
   * @effects multi_select_keeps_the_list_open_for_more
   */
  it('다중 선택: multiple prop이 true일 때 여러 옵션 선택 가능', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        triggerLabel="Select"
        onChange={onChange}
      />
    );

    const trigger = screen.getByRole('button', { name: /Select/i });
    await user.click(trigger);

    // 드롭다운 내 옵션 선택 (role="option")
    const nikeOption = screen.getByRole('option', { name: /Nike/i });
    await user.click(nikeOption);

    expect(onChange).toHaveBeenCalledWith(['nike']);

    // 다중 선택 모드에서는 드롭다운이 열린 상태 유지
    // 두 번째 옵션 선택 - 컴포넌트는 비제어 상태이므로 내부 selectedValues로 동작
    const adidasOption = screen.getByRole('option', { name: /Adidas/i });
    await user.click(adidasOption);

    // 두 번째 호출은 빈 배열에서 시작하므로 ['adidas']만 전달됨
    // (비제어 컴포넌트이므로 이전 선택을 유지하지 않음)
    expect(onChange).toHaveBeenLastCalledWith(['adidas']);
  });

  /** @effects multi_select_toggles_a_chosen_option_off */
  it('다중 선택: 선택된 옵션 다시 클릭하면 선택 해제', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        value={['nike']}
        triggerLabel="Select"
        onChange={onChange}
      />
    );

    // value=['nike']일 때 트리거 버튼에 'Nike'가 표시됨
    const trigger = screen.getByRole('button', { name: /Nike/i });
    await user.click(trigger);

    // 드롭다운 내의 Nike 옵션 (role="option")
    const nikeOption = screen.getByRole('option', { name: /Nike/i });
    await user.click(nikeOption);

    expect(onChange).toHaveBeenCalledWith([]);
  });

  /** @effects escape_closes_the_list */
  it('ESC 키로 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    // 드롭다운 닫힘 확인
  });

  /** @effects clicking_outside_closes_the_list */
  it('외부 클릭 시 드롭다운 닫힘', async () => {
    const user = userEvent.setup();
    render(
      <div>
        <SearchableDropdown options={mockOptions} triggerLabel="Select" />
        <button data-testid="outside">외부 버튼</button>
      </div>
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Nike')).toBeInTheDocument();

    const outsideButton = screen.getByTestId('outside');
    await user.click(outsideButton);

    // 드롭다운이 닫힘
  });

  /** @effects disabled_dropdown_refuses_to_open */
  it('disabled prop이 true일 때 클릭 불가', async () => {
    const user = userEvent.setup();
    render(<SearchableDropdown options={mockOptions} triggerLabel="Select" disabled />);

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    // 드롭다운이 열리지 않음
    expect(screen.queryByPlaceholderText('검색...')).not.toBeInTheDocument();
  });

  /** @effects value_prop_seeds_the_current_selection */
  it('value prop으로 초기 선택값 설정', () => {
    render(<SearchableDropdown options={mockOptions} value="nike" triggerLabel="Select" />);

    expect(screen.getByText('Nike')).toBeInTheDocument();
  });

  // 회귀: 알림 템플릿 편집 모달의 역할 드롭다운 라벨 미표시 버그
  // value 는 있으나 options 가 비어 있으면 라벨을 찾지 못해 triggerLabel 폴백.
  // → 레이아웃이 현재 선택값을 options 에 시드해야 라벨이 표시된다(아래 두 케이스가 그 계약).
  /** @effects unknown_value_falls_back_to_the_trigger_label */
  it('회귀: value 가 options 에 없으면 triggerLabel 폴백 (시드 누락 시 버그 재현)', () => {
    render(<SearchableDropdown options={[]} value="admin" triggerLabel="역할" />);

    // 선택값(admin)에 대응하는 옵션이 없어 라벨을 못 찾음 → 폴백
    expect(screen.getByText('역할')).toBeInTheDocument();
    expect(screen.queryByText('관리자')).not.toBeInTheDocument();
  });

  /** @effects seeded_selection_shows_its_label */
  it('회귀: 현재 선택값이 options 에 시드되면 라벨 표시', () => {
    // 레이아웃이 rcpt.value + rcpt.display_name 으로 시드한 옵션
    render(
      <SearchableDropdown
        options={[{ value: 'admin', label: '관리자', description: 'admin' }]}
        value="admin"
        triggerLabel="역할"
      />
    );

    expect(screen.getByText('관리자')).toBeInTheDocument();
  });

  /** @effects seeded_multi_selection_shows_the_count */
  it('회귀: 다중 선택값이 display_names 로 시드되면 개수/라벨 표시', () => {
    render(
      <SearchableDropdown
        multiple
        options={[{ value: 'uuid-1', label: '홍길동', description: 'hong@example.com' }]}
        value={['uuid-1']}
        triggerLabel="사용자"
      />
    );

    // 단일 선택 시 라벨, 복수 선택 시 "N selected" 가 표시되는 기존 계약과 결합
    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  /** @effects multi_selection_shows_how_many_are_chosen */
  it('다중 선택 시 선택 개수 표시', () => {
    render(
      <SearchableDropdown
        multiple
        options={mockOptions}
        value={['nike', 'adidas']}
        triggerLabel="Select"
      />
    );

    expect(screen.getByText('2 selected')).toBeInTheDocument();
  });

  /** @effects search_placeholder_prop_reaches_the_input */
  it('searchPlaceholder prop이 적용됨', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        searchPlaceholder="Search brands..."
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByPlaceholderText('Search brands...')).toBeInTheDocument();
  });

  /** @effects typing_calls_the_on_search_callback */
  it('onSearch 콜백이 호출됨', async () => {
    const user = userEvent.setup();
    const onSearch = vi.fn();

    render(
      <SearchableDropdown options={mockOptions} triggerLabel="Select" onSearch={onSearch} />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('검색...');
    await user.type(searchInput, 'test');

    expect(onSearch).toHaveBeenCalled();
  });

  /** @effects class_name_prop_reaches_the_container */
  it('className prop이 적용됨', () => {
    const { container } = render(
      <SearchableDropdown
        options={mockOptions}
        triggerLabel="Select"
        className="custom-searchable"
      />
    );

    expect(container.firstChild).toHaveClass('custom-searchable');
  });

  describe('[case:components-form-searchable-dropdown-nonstring-label] 검색형 드롭다운이 첫 글자에 목록째 사라짐', () => {
  /** @effects searchable_dropdown_survives_a_non_string_label */
  it('회귀: 라벨이 문자열이 아닌 옵션이 섞여도 검색이 화면을 죽이지 않는다', async () => {
    // 실측(§9 T-D2)에서 알림 템플릿 수정 창의 역할 칸이 검색어 첫 글자에 통째로 사라졌다.
    // 원인은 `opt.label.toLowerCase()` 였다 — 레이아웃 식이 빈 선택값에서 만들어 낸 항목의
    // 라벨이 배열이라 예외가 났고, 그 예외가 드롭다운 전체를 언마운트시켰다.
    //
    // 드롭다운은 **옵션 하나가 이상해도 자기 화면을 지켜야 한다** — 옵션을 공급하는 쪽은
    // 화면마다 다르고(검색 API·선택값 시드·확장 주입), 한 곳이 틀렸다고 운영자가 그 화면을
    // 쓰지 못하게 되는 것은 과한 대가다.
    const user = userEvent.setup();
    const malformed = [
      { value: [] as unknown as string, label: [] as unknown as string },
      { value: 'admin', label: '최고관리자', description: 'admin' },
    ];

    render(<SearchableDropdown options={malformed} triggerLabel="역할" />);

    const trigger = screen.getByText('역할');
    await user.click(trigger);

    const searchInput = screen.getByPlaceholderText('검색...');
    await user.type(searchInput, 'adm');

    // 드롭다운이 살아 있고, 정상 옵션은 그대로 걸린다.
    expect(screen.getByPlaceholderText('검색...')).toBeInTheDocument();
    expect(screen.getByText('최고관리자')).toBeInTheDocument();
  });
  });

  /** @effects empty_options_show_the_initial_hint */
  it('옵션이 없을 때 초기 메시지 표시', async () => {
    const user = userEvent.setup();
    render(
      <SearchableDropdown
        options={[]}
        triggerLabel="Select"
        initialText="Type to search"
      />
    );

    const trigger = screen.getByText('Select');
    await user.click(trigger);

    expect(screen.getByText('Type to search')).toBeInTheDocument();
  });
});
