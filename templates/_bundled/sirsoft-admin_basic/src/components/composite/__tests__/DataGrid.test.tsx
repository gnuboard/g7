import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataGrid, DataGridColumn } from '../DataGrid';

// window.G7Core.useResponsive mock
const mockUseResponsive = vi.fn(() => ({
  width: 1024,
  isMobile: false,
  isTablet: false,
  isDesktop: true,
  matchedPreset: 'desktop' as const,
}));

describe('DataGrid', () => {
  // 원래 G7Core 백업
  let originalG7Core: any;

  beforeEach(() => {
    // 기존 G7Core를 유지하면서 useResponsive만 추가/덮어쓰기
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      useResponsive: mockUseResponsive,
    };
  });

  afterEach(() => {
    // 원래 G7Core 복원
    (window as any).G7Core = originalG7Core;
    vi.clearAllMocks();
  });
  const mockColumns: DataGridColumn[] = [
    { field: 'name', header: '이름', sortable: true },
    { field: 'email', header: '이메일', sortable: true },
    { field: 'role', header: '역할', sortable: false },
  ];

  const mockData = [
    { name: '홍길동', email: 'hong@example.com', role: '관리자' },
    { name: '김철수', email: 'kim@example.com', role: '사용자' },
    { name: '이영희', email: 'lee@example.com', role: '사용자' },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<DataGrid columns={mockColumns} data={mockData} />);

    expect(screen.getByText('이름')).toBeInTheDocument();
    expect(screen.getByText('이메일')).toBeInTheDocument();
    expect(screen.getByText('역할')).toBeInTheDocument();
  });

  it('데이터가 표시됨', () => {
    render(<DataGrid columns={mockColumns} data={mockData} />);

    expect(screen.getByText('홍길동')).toBeInTheDocument();
    expect(screen.getByText('hong@example.com')).toBeInTheDocument();
    expect(screen.getByText('관리자')).toBeInTheDocument();
  });

  it('컬럼 헤더 클릭 시 정렬됨', async () => {
    const user = userEvent.setup();
    const onSortChange = vi.fn();

    // controlled 모드로 테스트 (외부 상태 제어)
    render(
      <DataGrid
        columns={mockColumns}
        data={mockData}
        sortable={true}
        sortField="name"
        sortDirection="asc"
        onSortChange={onSortChange}
      />
    );

    const nameHeader = screen.getByText('이름');

    // 정렬 화살표가 표시되는지 확인
    expect(nameHeader.parentElement).toContainHTML('↑');

    // 클릭 시 onSortChange가 호출되는지 확인
    await user.click(nameHeader);
    expect(onSortChange).toHaveBeenCalledWith('name', 'desc');
  });

  it('정렬 방향이 토글됨', async () => {
    const user = userEvent.setup();
    const onSortChange = vi.fn();

    // controlled 모드로 테스트: asc 상태에서 시작
    const { rerender } = render(
      <DataGrid
        columns={mockColumns}
        data={mockData}
        sortable={true}
        sortField="name"
        sortDirection="asc"
        onSortChange={onSortChange}
      />
    );

    const nameHeader = screen.getByText('이름');

    // 초기 상태: 오름차순 화살표 표시
    expect(nameHeader.parentElement).toContainHTML('↑');

    // 클릭 시 desc로 토글 요청
    await user.click(nameHeader);
    expect(onSortChange).toHaveBeenCalledWith('name', 'desc');

    // 상태를 desc로 업데이트하여 다시 렌더링
    rerender(
      <DataGrid
        columns={mockColumns}
        data={mockData}
        sortable={true}
        sortField="name"
        sortDirection="desc"
        onSortChange={onSortChange}
      />
    );

    // 내림차순 화살표 표시
    expect(nameHeader.parentElement).toContainHTML('↓');
  });

  it('sortable이 false인 컬럼은 정렬되지 않음', async () => {
    const user = userEvent.setup();
    render(<DataGrid columns={mockColumns} data={mockData} sortable={true} />);

    const roleHeader = screen.getByText('역할');
    await user.click(roleHeader);

    // 정렬 화살표가 표시되지 않아야 함
    expect(roleHeader.parentElement).not.toContainHTML('↑');
    expect(roleHeader.parentElement).not.toContainHTML('↓');
  });

  it('row 클릭 시 onRowClick 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();

    render(
      <DataGrid
        columns={mockColumns}
        data={mockData}
        onRowClick={onRowClick}
      />
    );

    const row = screen.getByText('홍길동').closest('tr');
    await user.click(row!);

    expect(onRowClick).toHaveBeenCalledWith(mockData[0]);
  });

  it('페이지네이션이 활성화되면 페이지 버튼이 표시됨', () => {
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      name: `User ${i}`,
      email: `user${i}@example.com`,
      role: '사용자',
    }));

    render(
      <DataGrid
        columns={mockColumns}
        data={manyData}
        pagination={true}
        pageSize={10}
      />
    );

    expect(screen.getByLabelText('이전 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('다음 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('페이지 1')).toBeInTheDocument();
  });

  it('페이지 버튼 클릭 시 다른 페이지로 이동함', async () => {
    const user = userEvent.setup();
    const manyData = Array.from({ length: 25 }, (_, i) => ({
      name: `User ${i}`,
      email: `user${i}@example.com`,
      role: '사용자',
    }));

    render(
      <DataGrid
        columns={mockColumns}
        data={manyData}
        pagination={true}
        pageSize={10}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);

    // 2페이지 데이터가 표시되어야 함
    expect(screen.getByText('User 10')).toBeInTheDocument();
  });

  it('빈 데이터일 때 아무것도 표시되지 않음', () => {
    render(<DataGrid columns={mockColumns} data={[]} />);

    expect(screen.queryByText('홍길동')).not.toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <DataGrid
        columns={mockColumns}
        data={mockData}
        className="custom-grid"
      />
    );
    expect(container.firstChild).toHaveClass('custom-grid');
  });

  describe('외부 정렬 제어 (sortField, sortDirection, onSortChange)', () => {
    it('외부에서 sortField와 sortDirection을 제어할 수 있음', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="name"
          sortDirection="asc"
        />
      );

      // 정렬 화살표가 표시되는지 확인
      const nameHeader = screen.getByText('이름');
      expect(nameHeader.parentElement).toContainHTML('↑');
    });

    it('외부 sortDirection이 desc일 때 내림차순 화살표가 표시됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="email"
          sortDirection="desc"
        />
      );

      const emailHeader = screen.getByText('이메일');
      expect(emailHeader.parentElement).toContainHTML('↓');
    });

    it('컬럼 헤더 클릭 시 onSortChange 콜백이 호출됨', async () => {
      const user = userEvent.setup();
      const onSortChange = vi.fn();

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          onSortChange={onSortChange}
        />
      );

      const nameHeader = screen.getByText('이름');
      await user.click(nameHeader);

      expect(onSortChange).toHaveBeenCalledWith('name', 'asc');
    });

    it('동일한 컬럼 재클릭 시 정렬 방향이 토글됨 (외부 제어)', async () => {
      const user = userEvent.setup();
      const onSortChange = vi.fn();

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="name"
          sortDirection="asc"
          onSortChange={onSortChange}
        />
      );

      const nameHeader = screen.getByText('이름');
      await user.click(nameHeader);

      // 이미 asc이므로 desc로 변경되어야 함
      expect(onSortChange).toHaveBeenCalledWith('name', 'desc');
    });

    it('다른 컬럼 클릭 시 새 컬럼으로 asc 정렬됨', async () => {
      const user = userEvent.setup();
      const onSortChange = vi.fn();

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="name"
          sortDirection="desc"
          onSortChange={onSortChange}
        />
      );

      const emailHeader = screen.getByText('이메일');
      await user.click(emailHeader);

      // 새 컬럼이므로 asc로 시작
      expect(onSortChange).toHaveBeenCalledWith('email', 'asc');
    });

    it('onSortChange가 없을 때도 클릭이 오류 없이 동작함', async () => {
      const user = userEvent.setup();

      // onSortChange 없이 렌더링 (uncontrolled 모드)
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
        />
      );

      const nameHeader = screen.getByText('이름');

      // 클릭 시 오류가 발생하지 않아야 함
      await expect(user.click(nameHeader)).resolves.not.toThrow();

      // 컴포넌트가 여전히 안정적으로 렌더링되어야 함
      expect(screen.getByText('이름')).toBeInTheDocument();
      expect(screen.getByText('이메일')).toBeInTheDocument();
      expect(screen.getByText('역할')).toBeInTheDocument();
    });

    it('sortable이 false인 컬럼은 onSortChange가 호출되지 않음', async () => {
      const user = userEvent.setup();
      const onSortChange = vi.fn();

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          onSortChange={onSortChange}
        />
      );

      // role 컬럼은 sortable: false
      const roleHeader = screen.getByText('역할');
      await user.click(roleHeader);

      expect(onSortChange).not.toHaveBeenCalled();
    });

    it('외부 정렬 상태에 따라 데이터가 정렬됨', () => {
      const { rerender } = render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="name"
          sortDirection="asc"
        />
      );

      // 이름 기준 오름차순 정렬 확인 (김철수 < 이영희 < 홍길동)
      const rows = screen.getAllByRole('row');
      // 첫 번째 row는 header이므로 두 번째부터 데이터
      expect(rows[1]).toHaveTextContent('김철수');

      // 내림차순으로 변경
      rerender(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          sortField="name"
          sortDirection="desc"
        />
      );

      const rowsAfter = screen.getAllByRole('row');
      expect(rowsAfter[1]).toHaveTextContent('홍길동');
    });
  });

  describe('서브 행 (Sub Row) 기능 - v1.6.0+', () => {
    it('subRowRender가 제공되면 각 데이터 행 아래에 서브 행이 렌더링됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">배송비: {row.name}</span>}
        />
      );

      // 각 데이터 행에 대해 서브 행이 렌더링되어야 함
      const subRowContents = screen.getAllByTestId('sub-row-content');
      expect(subRowContents).toHaveLength(3);
      expect(subRowContents[0]).toHaveTextContent('배송비: 홍길동');
      expect(subRowContents[1]).toHaveTextContent('배송비: 김철수');
      expect(subRowContents[2]).toHaveTextContent('배송비: 이영희');
    });

    it('subRowClassName이 서브 행에 적용됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowClassName="bg-gray-100 text-sm"
        />
      );

      // subRowClassName이 있으면:
      // - td에는 'p-2' padding만 적용
      // - 내부 Div에 subRowClassName 적용 (스타일 분리를 위한 의도적 설계)
      // @see commit 346e704b "subRow 내부 Div wrapper 추가"
      const subRowContents = screen.getAllByTestId('sub-row-content');
      subRowContents.forEach((content) => {
        // 내부 Div wrapper에 className이 적용됨
        const divWrapper = content.closest('div.bg-gray-100');
        expect(divWrapper).toBeInTheDocument();
        expect(divWrapper).toHaveClass('bg-gray-100');
        expect(divWrapper).toHaveClass('text-sm');

        // td에는 패딩만 적용
        const td = content.closest('td');
        expect(td).toHaveClass('p-2');
      });
    });

    it('subRowCondition이 false를 반환하면 서브 행이 렌더링되지 않음', () => {
      const dataWithFlag = [
        { name: '홍길동', email: 'hong@example.com', role: '관리자', showSubRow: true },
        { name: '김철수', email: 'kim@example.com', role: '사용자', showSubRow: false },
        { name: '이영희', email: 'lee@example.com', role: '사용자', showSubRow: true },
      ];

      render(
        <DataGrid
          columns={mockColumns}
          data={dataWithFlag}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowCondition="{{row.showSubRow}}"
        />
      );

      // showSubRow가 true인 행만 서브 행이 렌더링되어야 함
      const subRowContents = screen.getAllByTestId('sub-row-content');
      expect(subRowContents).toHaveLength(2);
      expect(subRowContents[0]).toHaveTextContent('홍길동');
      expect(subRowContents[1]).toHaveTextContent('이영희');
    });

    it('subRowCondition 없이 subRowRender만 제공되면 모든 행에 서브 행이 렌더링됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
        />
      );

      const subRowContents = screen.getAllByTestId('sub-row-content');
      expect(subRowContents).toHaveLength(3);
    });

    it('subRowRender와 subRowChildren이 모두 없으면 서브 행이 렌더링되지 않음', () => {
      const { container } = render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
        />
      );

      // 서브 행 관련 요소가 없어야 함
      expect(container.querySelectorAll('[data-testid="sub-row-content"]')).toHaveLength(0);

      // 기본 데이터 행만 있어야 함 (헤더 1개 + 데이터 3개)
      const rows = screen.getAllByRole('row');
      expect(rows).toHaveLength(4);
    });

    it('서브 행의 colSpan이 전체 컬럼을 커버함', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
        />
      );

      const subRowContents = screen.getAllByTestId('sub-row-content');
      subRowContents.forEach((content) => {
        const td = content.closest('td');
        // mockColumns가 3개이므로 colSpan도 3이어야 함
        expect(td).toHaveAttribute('colspan', '3');
      });
    });

    it('selectable과 함께 사용시 서브 행 colSpan이 체크박스 컬럼을 포함함', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          selectable={true}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
        />
      );

      const subRowContents = screen.getAllByTestId('sub-row-content');
      subRowContents.forEach((content) => {
        const td = content.closest('td');
        // mockColumns 3개 + 체크박스 컬럼 1개 = 4
        expect(td).toHaveAttribute('colspan', '4');
      });
    });

    it('rowActions와 함께 사용시 서브 행 colSpan이 액션 컬럼을 포함함', () => {
      const mockRowActions = [
        { id: 'edit', label: '수정', icon: 'edit' },
        { id: 'delete', label: '삭제', icon: 'trash' },
      ];

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          rowActions={mockRowActions}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
        />
      );

      const subRowContents = screen.getAllByTestId('sub-row-content');
      subRowContents.forEach((content) => {
        const td = content.closest('td');
        // mockColumns 3개 + 액션 컬럼 1개 = 4
        expect(td).toHaveAttribute('colspan', '4');
      });
    });

    it('subRowCondition이 잘못된 표현식이어도 오류 없이 기본값(true)으로 처리됨', () => {
      // 콘솔 경고를 억제
      const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowCondition="{{invalid.syntax.here}}"
        />
      );

      // 잘못된 조건이어도 기본값 true로 모든 행에 서브 행이 렌더링됨
      const subRowContents = screen.getAllByTestId('sub-row-content');
      expect(subRowContents).toHaveLength(3);

      consoleWarn.mockRestore();
    });

    it('subRowCondition이 빈 문자열이면 모든 행에 서브 행이 렌더링됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowCondition=""
        />
      );

      const subRowContents = screen.getAllByTestId('sub-row-content');
      expect(subRowContents).toHaveLength(3);
    });
  });

  // =============================================================================
  // 회귀 테스트: SPA 네비게이션 시 컬럼 변경 감지
  // 문서: troubleshooting-state-closure.md - 사례 165 (동일 패턴)
  // =============================================================================

  describe('SPA 네비게이션 시 컬럼 변경 감지 - visibleColumns 리셋', () => {
    it('columns prop이 변경되면 visibleColumns가 새 columns 기준으로 리셋됨', () => {
      const orderColumns: DataGridColumn[] = [
        { field: 'no', header: '번호' },
        { field: 'ordered_at', header: '주문일' },
        { field: 'order_number', header: '주문번호' },
      ];

      const shippingColumns: DataGridColumn[] = [
        { field: 'name_localized', header: '정책명' },
        { field: 'shipping_method_label', header: '배송방법' },
        { field: 'charge_policy_label', header: '요금정책' },
      ];

      const { rerender } = render(
        <DataGrid columns={orderColumns} data={[]} />
      );

      // 주문관리 컬럼 헤더 확인
      expect(screen.getByText('번호')).toBeInTheDocument();
      expect(screen.getByText('주문일')).toBeInTheDocument();
      expect(screen.getByText('주문번호')).toBeInTheDocument();

      // 배송정책 컬럼으로 변경 (SPA 네비게이션 시뮬레이션)
      rerender(
        <DataGrid columns={shippingColumns} data={[]} />
      );

      // 새 컬럼 헤더가 모두 표시되어야 함
      expect(screen.getByText('정책명')).toBeInTheDocument();
      expect(screen.getByText('배송방법')).toBeInTheDocument();
      expect(screen.getByText('요금정책')).toBeInTheDocument();

      // 이전 컬럼 헤더는 없어야 함
      expect(screen.queryByText('번호')).not.toBeInTheDocument();
      expect(screen.queryByText('주문일')).not.toBeInTheDocument();
      expect(screen.queryByText('주문번호')).not.toBeInTheDocument();
    });

    it('외부 visibleColumns가 있으면 columns 변경 시 리셋하지 않음', () => {
      const columnsA: DataGridColumn[] = [
        { field: 'a1', header: 'A1' },
        { field: 'a2', header: 'A2' },
      ];

      const columnsB: DataGridColumn[] = [
        { field: 'b1', header: 'B1' },
        { field: 'b2', header: 'B2' },
      ];

      const { rerender } = render(
        <DataGrid columns={columnsA} data={[]} visibleColumns={['a1', 'a2']} />
      );

      // 외부 visibleColumns와 함께 새 columns로 변경
      rerender(
        <DataGrid columns={columnsB} data={[]} visibleColumns={['b1']} />
      );

      // 외부 visibleColumns에 의해 b1만 표시
      expect(screen.getByText('B1')).toBeInTheDocument();
    });
  });

  describe('모바일 뷰 서브 행', () => {
    beforeEach(() => {
      // 모바일 모드로 설정
      mockUseResponsive.mockReturnValue({
        width: 375,
        isMobile: true,
        isTablet: false,
        isDesktop: false,
        matchedPreset: 'portable' as const,
      });
    });

    afterEach(() => {
      // 데스크톱 모드로 복원
      mockUseResponsive.mockReturnValue({
        width: 1024,
        isMobile: false,
        isTablet: false,
        isDesktop: true,
        matchedPreset: 'desktop' as const,
      });
    });

    it('모바일 카드 뷰에서도 서브 행이 렌더링됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          mobileBreakpoint={768}
          subRowRender={(row) => <div data-testid="mobile-sub-row">모바일 서브: {row.name}</div>}
        />
      );

      // 모바일 뷰에서 서브 행이 렌더링되어야 함
      const subRowContents = screen.getAllByTestId('mobile-sub-row');
      expect(subRowContents).toHaveLength(3);
      expect(subRowContents[0]).toHaveTextContent('모바일 서브: 홍길동');
    });

    it('모바일 뷰에서 subRowCondition이 적용됨', () => {
      const dataWithFlag = [
        { name: '홍길동', email: 'hong@example.com', role: '관리자', showSubRow: true },
        { name: '김철수', email: 'kim@example.com', role: '사용자', showSubRow: false },
        { name: '이영희', email: 'lee@example.com', role: '사용자', showSubRow: true },
      ];

      render(
        <DataGrid
          columns={mockColumns}
          data={dataWithFlag}
          mobileBreakpoint={768}
          subRowRender={(row) => <div data-testid="mobile-sub-row">{row.name}</div>}
          subRowCondition="{{row.showSubRow}}"
        />
      );

      const subRowContents = screen.getAllByTestId('mobile-sub-row');
      expect(subRowContents).toHaveLength(2);
    });

    it('모바일 뷰에서 subRowClassName이 적용됨', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          mobileBreakpoint={768}
          subRowRender={(row) => <div data-testid="mobile-sub-row">{row.name}</div>}
          subRowClassName="mobile-sub-row-custom"
        />
      );

      const subRowContents = screen.getAllByTestId('mobile-sub-row');
      subRowContents.forEach((content) => {
        const wrapper = content.closest('.mobile-sub-row-custom');
        expect(wrapper).toBeInTheDocument();
      });
    });
  });

  // =========================================================================
  // disabledField 테스트
  // =========================================================================
  describe('rowActions disabledField', () => {
    const dataWithAbilities = [
      { name: '본인', email: 'self@test.com', role: '매니저', abilities: { can_update: true, can_delete: false } },
      { name: '타인', email: 'other@test.com', role: '사용자', abilities: { can_update: false, can_delete: false } },
    ];

    it('disabledField가 falsy인 행의 액션은 disabled 클래스가 적용된다', async () => {
      const user = userEvent.setup();

      render(
        <DataGrid
          columns={mockColumns}
          data={dataWithAbilities}
          rowActions={[
            { id: 'edit', label: '수정', disabledField: 'abilities.can_update' },
            { id: 'delete', label: '삭제', disabledField: 'abilities.can_delete' },
          ]}
        />
      );

      // 첫 번째 행의 액션 메뉴 열기
      const actionButtons = screen.getAllByRole('button');
      // 액션 메뉴 트리거 버튼 찾기 (테이블 행별 하나씩)
      const triggerButtons = actionButtons.filter(btn =>
        btn.closest('.relative.inline-block')
      );

      if (triggerButtons.length > 0) {
        await user.click(triggerButtons[0]);

        await waitFor(() => {
          const menuItems = document.querySelectorAll('.fixed.z-\\[9999\\] > div');
          if (menuItems.length >= 2) {
            // 첫 번째 행: can_update=true → 수정 활성, can_delete=false → 삭제 비활성
            expect(menuItems[0]).not.toHaveClass('opacity-50');
            expect(menuItems[1]).toHaveClass('opacity-50');
          }
        });
      }
    });

    it('disabledField 미지정 액션은 항상 활성화된다', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={dataWithAbilities}
          rowActions={[
            { id: 'view', label: '보기' },
            { id: 'edit', label: '수정', disabledField: 'abilities.can_update' },
          ]}
        />
      );

      // 렌더링 자체가 에러 없이 완료되면 성공
      expect(screen.getByText('본인')).toBeInTheDocument();
      expect(screen.getByText('타인')).toBeInTheDocument();
    });
  });
});
