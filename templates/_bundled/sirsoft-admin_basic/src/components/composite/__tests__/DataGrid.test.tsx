import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DataGrid, DataGridColumn } from '../DataGrid';

/**
 * 런타임의 `G7Core.useControllableState` 와 같은 계약을 갖는 테스트용 구현.
 *
 * 코어 훅(resources/js/core/hooks/useControllableState.ts)을 직접 임포트하면 코어와
 * 템플릿이 각자의 node_modules/react 를 갖고 있어 React 사본이 둘이 되고 "Invalid hook
 * call" 이 난다. vitest 설정에서 react 를 전역 alias 로 단일화하면 이 파일은 통과하지만
 * 다른 레이아웃 테스트 300여 건이 깨진다(실측 확인).
 *
 * 이 테스트가 지키려는 것은 훅 내부 동작이 아니라 **DataGrid 가 정렬을 디스패치하는
 * 방식** 이므로, 훅은 테스트의 React 로 같은 계약(낙관적 내부 상태 + onChange 콜백)만
 * 재현하면 충분하다.
 */
function useControllableState<T>(
  controlledValue: T | undefined,
  defaultValue: T,
  onChange?: (value: T) => void
): [T, (value: T | ((prev: T) => T)) => void] {
  const [internal, setInternal] = useState<T>(
    controlledValue !== undefined ? controlledValue : defaultValue
  );
  const prevControlled = useRef(controlledValue);

  useEffect(() => {
    if (controlledValue !== undefined && controlledValue !== prevControlled.current) {
      setInternal(controlledValue);
    }
    prevControlled.current = controlledValue;
  }, [controlledValue]);

  const setValue = useCallback(
    (next: T | ((prev: T) => T)) => {
      const resolved = typeof next === 'function' ? (next as (prev: T) => T)(internal) : next;
      if (resolved === internal) return;
      setInternal(resolved);
      onChange?.(resolved);
    },
    [internal, onChange]
  );

  return [internal, setValue];
}

/** shallowArrayEqual 의 테스트용 동등 구현 */
function shallowArrayEqual<T>(a: T[], b: T[]): boolean {
  if (a === b) return true;
  if (a.length !== b.length) return false;
  return a.every((item, index) => item === b[index]);
}

/**
 * window.G7Core.evaluateCondition mock
 *
 * 런타임에서는 엔진의 `evaluateStringCondition` 이 처리하며, 컨텍스트에는 컴포넌트가
 * 넘긴 `row` 뿐 아니라 엔진이 병합한 `_global`/`_local`/`_computed` 도 들어 있다
 * (`G7CoreGlobals.evaluateCondition`). mock 도 그 계약을 그대로 따라야 `_local` 을
 * 참조하는 조건이 실제로 검증된다 — 종전처럼 `row` 만 파라미터로 넘기면 `_local` 이
 * 스코프에 없어 무조건 false 로 떨어져, 통과해도 아무것도 증명하지 못한다.
 *
 * 실패 시 false 는 엔진 `evaluateStringCondition` 의 catch 와 같은 방향이다.
 */
const mockEngineState: { _local: Record<string, any>; _global: Record<string, any> } = {
  _local: {},
  _global: {},
};

const mockEvaluateCondition = vi.fn((condition: string, ctx: Record<string, any>) => {
  if (!condition) return true;
  const match = condition.match(/^\{\{([\s\S]+)\}\}$/);
  if (!match) return true;

  const context = {
    _global: mockEngineState._global,
    _local: mockEngineState._local,
    _computed: {},
    ...(ctx || {}),
  };
  const names = Object.keys(context);
  const values = names.map((n) => (context as any)[n]);

  try {
    // eslint-disable-next-line no-new-func
    return !!new Function(...names, `return ${match[1].trim()}`)(...values);
  } catch {
    return false;
  }
});

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
      // subRowCondition 판정은 엔진(G7Core.evaluateCondition)에 위임한다.
      // 런타임과 같은 계약(조건 문자열 + row 컨텍스트 → boolean)을 최소 구현으로 세운다.
      evaluateCondition: mockEvaluateCondition,
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

  it('데이터 셀이 align-top 으로 상단 정렬된다 (셀 콘텐츠 높이가 달라도 입력칸 라인 정렬 유지)', () => {
    // 회귀 가드: 한 셀(예: 판매가)에 다통화 환산값 같은 보조 표시가 세로로 쌓여
    // 셀 높이가 커져도, 기본 vertical-align: middle 이면 다른 셀 입력칸이 중앙으로
    // 내려가 라인이 어긋난다. 모든 데이터 셀을 align-top 으로 상단 정렬해 입력칸을
    // 같은 수평선에 맞춘다.
    const { container } = render(<DataGrid columns={mockColumns} data={mockData} />);

    const bodyCells = container.querySelectorAll('tbody td');
    expect(bodyCells.length).toBeGreaterThan(0);
    bodyCells.forEach((td) => {
      expect(td.className).toContain('align-top');
    });
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

  // 회귀 가드: 서버가 정렬을 책임지는(controlled, onSortChange 연결) 그리드는
  // 서버가 내려준 순서를 그대로 출력해야 한다. 클라이언트가 다시 정렬하면 null 값을
  // 항상 앞으로 밀어 서버 ORDER BY 순서를 훼손한다(페이지 발행순 정렬 버그).
  it('controlled 정렬(onSortChange 연결) 시 서버가 내려준 순서를 그대로 출력한다 (null 포함)', () => {
    const columns: DataGridColumn[] = [
      { field: 'title', header: '제목', sortable: true },
      { field: 'published_at', header: '발행일', sortable: true },
    ];

    // 서버가 published_at asc(오래된 발행순)로 정렬해 내려준 순서.
    // 발행된 건이 먼저, 미발행(null) 건이 뒤에 오도록 서버가 정한 순서를 가정한다.
    const serverSortedData = [
      { title: '먼저 발행', published_at: '2026-01-01 00:00:00' },
      { title: '나중 발행', published_at: '2026-06-01 00:00:00' },
      { title: '미발행 A', published_at: null },
      { title: '미발행 B', published_at: null },
    ];

    const { container } = render(
      <DataGrid
        columns={columns}
        data={serverSortedData}
        sortable={true}
        sortField="published_at"
        sortDirection="asc"
        onSortChange={vi.fn()}
      />
    );

    const firstCellTexts = Array.from(
      container.querySelectorAll('tbody tr')
    ).map((tr) => tr.querySelector('td')?.textContent?.trim());

    // 서버 순서 그대로 유지: null 행이 앞으로 튀어나오지 않는다.
    expect(firstCellTexts).toEqual(['먼저 발행', '나중 발행', '미발행 A', '미발행 B']);
  });

  // uncontrolled(onSortChange 미연결) 그리드는 컴포넌트가 단독 정렬 주체이므로
  // 전달받은 로컬 데이터를 클라이언트에서 정렬하는 동작을 유지한다.
  it('uncontrolled 정렬(onSortChange 미연결) 시 클라이언트 정렬이 동작한다', () => {
    const columns: DataGridColumn[] = [
      { field: 'name', header: '이름', sortable: true },
    ];
    const localData = [
      { name: '다람쥐' },
      { name: '가나다' },
      { name: '나비' },
    ];

    const { container } = render(
      <DataGrid
        columns={columns}
        data={localData}
        sortable={true}
        sortField="name"
        sortDirection="asc"
      />
    );

    const cellTexts = Array.from(
      container.querySelectorAll('tbody tr')
    ).map((tr) => tr.querySelector('td')?.textContent?.trim());

    // 클라이언트 정렬 적용: 가나다순
    expect(cellTexts).toEqual(['가나다', '나비', '다람쥐']);
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

  describe('서버 페이지네이션 목록의 헤더 정렬 (#492 D-18)', () => {
    // 서버 페이지네이션이면 data 는 "전체 중 이번 페이지" 다. onSortChange 가 없으면
    // 컴포넌트는 서버에 정렬을 요청할 수단이 없는데, 그 상태로 헤더를 누를 수 있게 두면
    // 이 페이지 안에서만 순서가 바뀌고 화살표가 붙어 전체 정렬로 오인된다.
    // 클릭이 실제로 정렬 상태를 바꾸는지 보려면 런타임과 같은 controllable-state 훅이
    // 있어야 한다. 훅을 주입하지 않으면 클릭해도 상태가 안 바뀌어, 결함이 있어도
    // "행 순서 그대로 / 화살표 없음" 이 성립해 테스트가 공허하게 통과한다.
    describe('G7Core.useControllableState 주입 시 (런타임 경로)', () => {
      beforeEach(() => {
        (window as any).G7Core = {
          ...(window as any).G7Core,
          useControllableState,
          shallowArrayEqual,
        };
      });

      it('onSortChange 가 없으면 헤더 클릭이 현재 페이지를 정렬하지 않는다', async () => {
        const user = userEvent.setup();

        render(
          <DataGrid
            columns={mockColumns}
            data={mockData}
            sortable={true}
            serverSidePagination={true}
            serverCurrentPage={1}
            serverTotalPages={5}
          />
        );

        const before = screen.getAllByRole('row').slice(1).map((r) => r.textContent);

        await user.click(screen.getByText('이름'));

        const after = screen.getAllByRole('row').slice(1).map((r) => r.textContent);

        expect(after).toEqual(before);
      });

      it('onSortChange 가 없으면 헤더에 정렬 화살표가 붙지 않는다', async () => {
        const user = userEvent.setup();

        render(
          <DataGrid
            columns={mockColumns}
            data={mockData}
            sortable={true}
            serverSidePagination={true}
            serverCurrentPage={1}
            serverTotalPages={5}
          />
        );

        await user.click(screen.getByText('이름'));

        expect(screen.getByText('이름').parentElement).not.toContainHTML('↑');
        expect(screen.getByText('이름').parentElement).not.toContainHTML('↓');
      });
    });

    it('onSortChange 가 없으면 헤더에 클릭 가능 커서를 표시하지 않는다', () => {
      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          serverSidePagination={true}
          serverCurrentPage={1}
          serverTotalPages={5}
        />
      );

      expect(screen.getByText('이름').className).not.toContain('cursor-pointer');
    });

    it('onSortChange 가 배선돼 있으면 서버 정렬을 요청한다 (정상 경로는 유지)', async () => {
      const user = userEvent.setup();
      const onSortChange = vi.fn();

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          sortable={true}
          serverSidePagination={true}
          serverCurrentPage={1}
          serverTotalPages={5}
          onSortChange={onSortChange}
        />
      );

      expect(screen.getByText('이름').className).toContain('cursor-pointer');

      await user.click(screen.getByText('이름'));

      expect(onSortChange).toHaveBeenCalledWith('name', 'asc');
    });

    // 가드가 과하게 걸려 클라이언트 목록의 정렬까지 죽이면 안 된다.
    // 서버 페이지네이션이 아니면 data 가 전체 집합이므로 컴포넌트 단독 정렬이 옳다.
    it('서버 페이지네이션이 아니면 헤더 정렬 어포던스를 유지한다 (클라이언트 목록)', () => {
      render(<DataGrid columns={mockColumns} data={mockData} sortable={true} />);

      expect(screen.getByText('이름').className).toContain('cursor-pointer');
      // sortable: false 인 컬럼은 원래대로 정렬 대상이 아니다
      expect(screen.getByText('역할').className).not.toContain('cursor-pointer');
    });
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

    // 회귀 가드 (#492 브라우저 실측): 런타임에는 G7Core.useControllableState 가 존재하므로
    // handleSort 가 폴백(onSortChange 직접 호출) 대신 controllable-state 경로를 탄다.
    // 이 훅을 주입하지 않은 테스트는 폴백만 검증하게 되어 런타임 결함을 통과시킨다.
    // (실측: 이름 desc 상태에서 이메일 헤더 클릭 → 헤더는 `이메일↑`, 요청은 sort_by=name&sort_order=asc)
    describe('G7Core.useControllableState 주입 시 (런타임 경로)', () => {
      beforeEach(() => {
        (window as any).G7Core = {
          ...(window as any).G7Core,
          useControllableState,
          shallowArrayEqual,
        };
      });

      it('다른 컬럼 헤더 클릭 시 새 컬럼 + asc 로 단 한 번만 디스패치된다', async () => {
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

        await user.click(screen.getByText('이메일'));

        // 이전 컬럼(name)이 섞인 디스패치가 있어서는 안 된다
        expect(onSortChange).toHaveBeenCalledTimes(1);
        expect(onSortChange).toHaveBeenCalledWith('email', 'asc');
      });

      it('동일 컬럼 재클릭 시 같은 컬럼 + 반대 방향으로 단 한 번만 디스패치된다', async () => {
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

        await user.click(screen.getByText('이름'));

        expect(onSortChange).toHaveBeenCalledTimes(1);
        expect(onSortChange).toHaveBeenCalledWith('name', 'desc');
      });

      it('정렬되지 않은 상태에서 헤더 클릭 시 해당 컬럼 asc 로 디스패치된다', async () => {
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

        await user.click(screen.getByText('이메일'));

        expect(onSortChange).toHaveBeenCalledTimes(1);
        expect(onSortChange).toHaveBeenCalledWith('email', 'asc');
      });
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

    it('subRowCondition이 해석되지 않는 경로면 오류 없이 숨김 처리됨', () => {
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

      // 조건 판정을 엔진에 위임하면서, 해석되지 않는 조건은 다른 화면 요소의 `if` 와
      // 같은 규칙으로 false(숨김)가 된다. 종전에는 이 컴포넌트만 true(표시)로 갈렸다.
      expect(screen.queryAllByTestId('sub-row-content')).toHaveLength(0);

      consoleWarn.mockRestore();
    });

    it('subRowCondition이 _local 을 참조해도 예외 없이 엔진이 판정한다', () => {
      // 종전에는 이 컴포넌트가 `new Function('row', ...)` 로 자체 평가해서, row 스코프에
      // 없는 `_local` 을 만나면 ReferenceError 가 났다. 위임 이후에는 엔진이 `_local` 을
      // 컨텍스트에 병합해 넘기므로 정상 판정된다.
      mockEngineState._local = { expanded: true };

      expect(() =>
        render(
          <DataGrid
            columns={mockColumns}
            data={mockData}
            subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
            subRowCondition="{{_local.expanded}}"
          />
        )
      ).not.toThrow();

      // 조건이 참이므로 모든 행에 서브 행이 렌더링된다
      expect(screen.getAllByTestId('sub-row-content')).toHaveLength(3);
      // 판정은 엔진에 위임됐다 (자체 평가기 부재 확인)
      expect(mockEvaluateCondition).toHaveBeenCalledWith(
        '{{_local.expanded}}',
        expect.objectContaining({ row: expect.any(Object) })
      );

      mockEngineState._local = {};
    });

    it('subRowCondition 의 _local 값이 거짓이면 서브 행이 숨겨진다', () => {
      mockEngineState._local = { expanded: false };

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowCondition="{{_local.expanded}}"
        />
      );

      expect(screen.queryAllByTestId('sub-row-content')).toHaveLength(0);

      mockEngineState._local = {};
    });

    it('G7Core.evaluateCondition 미노출 시에는 서브 행을 표시한다 (폴백 계약)', () => {
      const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
      const saved = (window as any).G7Core.evaluateCondition;
      delete (window as any).G7Core.evaluateCondition;

      render(
        <DataGrid
          columns={mockColumns}
          data={mockData}
          subRowRender={(row) => <span data-testid="sub-row-content">{row.name}</span>}
          subRowCondition="{{_local.expanded}}"
        />
      );

      // 엔진이 없으면 판정할 수 없으므로 감추지 않고 표시한다 (CardGrid 와 같은 방향)
      expect(screen.getAllByTestId('sub-row-content')).toHaveLength(3);

      (window as any).G7Core.evaluateCondition = saved;
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

  /**
   * 푸터 행(footerCells)의 `text` 는 반복 렌더 경로가 해석해야 한다.
   *
   * `components.json` 의 `skipBindingKeys` 에 `footerCells` 가 추가되면서 DynamicRenderer 가
   * 더 이상 이 키를 선평가하지 않는다. 그런데 DataGrid 의 푸터 렌더는 `cell.children` 만
   * `renderCellChildren` 으로 넘기고 `cell.text` 는 원문 그대로 출력해 왔다.
   * 그 결과 `{{order.data?.total_quantity ?? 0}}개` 같은 값이 화면에 **원본 문자열로 노출**된다.
   * (브라우저 실측: `/admin/ecommerce/orders/:orderNumber` 합계 행 3셀)
   */
  describe('푸터 행 text 바인딩 해석', () => {
    const footerColumns: DataGridColumn[] = [
      { field: 'name', header: '이름' },
      { field: 'qty', header: '수량' },
    ];
    const footerData = [
      { name: '상품A', qty: 2 },
      { name: '상품B', qty: 3 },
    ];

    it('footerCells[].text 의 바인딩 표현식이 원본 문자열로 노출되지 않는다', () => {
      render(
        <DataGrid
          columns={footerColumns}
          data={footerData}
          footerCells={[
            { field: 'name', text: '합계' },
            { field: 'qty', text: '{{order.data?.total_quantity ?? 0}}개' },
          ]}
        />
      );

      const tfoot = document.querySelector('tfoot');
      expect(tfoot).not.toBeNull();
      expect(tfoot!.textContent).toContain('합계');
      expect(tfoot!.textContent).not.toContain('{{');
    });

    it('파이프가 붙은 footerCells[].text 도 원본 문자열로 노출되지 않는다', () => {
      render(
        <DataGrid
          columns={footerColumns}
          data={footerData}
          footerCells={[
            { field: 'name', text: '합계' },
            { field: 'qty', text: '{{order.data?.total_earned_points_amount | number}}P' },
          ]}
        />
      );

      const tfoot = document.querySelector('tfoot');
      expect(tfoot!.textContent).not.toContain('{{');
      expect(tfoot!.textContent).not.toContain('| number');
    });

    it('바인딩이 없는 순수 문자열 text 는 그대로 출력된다', () => {
      render(
        <DataGrid
          columns={footerColumns}
          data={footerData}
          footerCells={[
            { field: 'name', text: '합계' },
            { field: 'qty', text: '5개' },
          ]}
        />
      );

      const tfoot = document.querySelector('tfoot');
      expect(tfoot!.textContent).toContain('합계');
      expect(tfoot!.textContent).toContain('5개');
    });
  });

  /**
   * 반복 렌더 서브트리(cellChildren/expandChildren/subRowChildren/footerCells)의 컴포넌트
   * 조회는 템플릿 레지스트리 전체를 대상으로 해야 한다.
   *
   * 종전에는 이 파일이 29개짜리 **손으로 박은 componentMap** 을 갖고 있어, 등록된 117개 중
   * 88개가 반복 경로에서만 조용히 사라졌다. 실측 확인: `/admin/identity/logs` 확장 행의
   * `Pre` 블록(부가 정보 JSON)이 라벨만 남고 내용이 통째로 렌더되지 않음
   * (콘솔: `renderItemChildren: 컴포넌트를 찾을 수 없습니다: Pre`).
   * 같은 `Pre` 가 모달(DynamicRenderer 경로)에서는 정상 렌더된다 — 경로 비대칭이다.
   */
  describe('반복 렌더 컴포넌트 레지스트리', () => {
    it('cellChildren 렌더 시 템플릿 레지스트리 전체(G7Core.getComponentMap)를 넘긴다', () => {
      const seen: Record<string, unknown>[] = [];
      (window as any).G7Core = {
        ...(window as any).G7Core,
        getComponentMap: () => ({ Div: 'div', Span: 'span', Pre: 'pre', Code: 'code' }),
        renderItemChildren: (_children: any, _ctx: any, componentMap: Record<string, unknown>) => {
          seen.push(componentMap);
          return null;
        },
      };

      render(
        <DataGrid
          columns={[
            { field: 'name', header: '이름' },
            { field: 'meta', header: '메타', cellChildren: [{ type: 'basic', name: 'Pre', text: '{{row.meta}}' }] },
          ]}
          data={[{ name: 'A', meta: '{"a":1}' }]}
        />
      );

      expect(seen.length).toBeGreaterThan(0);
      // 레지스트리에 등록된 컴포넌트는 반복 경로에서도 조회 가능해야 한다
      expect(Object.keys(seen[0])).toContain('Pre');
    });
  });
});
