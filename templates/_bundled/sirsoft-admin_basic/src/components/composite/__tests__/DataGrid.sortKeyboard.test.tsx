// e2e:allow 부품 렌더 단언(Tab·Enter·Space 로 정렬 머리 누르기·aria-sort) — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(마일리지 내역 「일시」·「금액」 등 정렬 머리 키보드 조작)이 덮는다.
/**
 * @file DataGrid.sortKeyboard.test.tsx
 * @description DataGrid 정렬 머리를 키보드로 누를 수 있고 정렬 상태를 aria-sort 로 알린다 (7.1.1)
 *
 * 정렬 머리가 `<th onClick>` 이라 Tab 이 닿지 않고 Enter·Space 로 정렬할 수 없었으며, 지금 어느 열로 어느 방향 정렬인지도
 * 화면 낭독기에 알리지 않았다(화살표 글자만). 정렬 가능한 모든 관리자 목록 공통(7.1.1 판정 — 이커머스 마일리지 내역 「일시」·「금액」 등).
 *
 * 고침(WAI-ARIA 정렬 표 패턴): 정렬 가능한 머리 칸 안에 `<button type="button">` 을 두고 칸에 `aria-sort`(ascending·descending·none).
 * 정렬 콜백은 종전 그대로 머리 칸의 클릭에 있고 단추는 자기 동작 없이 클릭을 칸으로 올려 보낸다 — 칸 여백을 눌러도 정렬되는 종전
 * 마우스 동작과, 단추를 눌러도 정렬이 한 번만 일어나는 것을 함께 지킨다.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { useCallback, useEffect, useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataGrid, DataGridColumn } from '../DataGrid';

const columns: DataGridColumn[] = [
    { field: 'created_at', header: '일시', sortable: true },
    { field: 'amount', header: '금액', sortable: true },
    { field: 'memo', header: '메모', sortable: false },
];
const rows = [
    { id: 1, created_at: '2026-10-01', amount: 300, memo: 'a' },
    { id: 2, created_at: '2026-10-02', amount: 100, memo: 'b' },
];

/**
 * 머리 칸(columnheader)을 이름으로 찾는다.
 *
 * @param name 머리 글자
 * @returns 머리 칸
 */
const header = (name: string): HTMLElement => screen.getAllByRole('columnheader').find((th) => th.textContent?.includes(name))!;

/**
 * 런타임 `G7Core.useControllableState` 와 같은 계약의 시험용 구현(DataGrid.test.tsx 와 같은 이유 — 코어 훅을 직접 들이면 React 사본이 둘이 된다).
 * 주입하지 않으면 비제어 정렬이 상태를 바꾸지 않아 자체 정렬 시험이 공허해진다.
 *
 * @param controlledValue 외부 값
 * @param defaultValue 기본값
 * @param onChange 바뀜 콜백
 * @returns [값, 설정기]
 */
function useControllableState<T>(controlledValue: T | undefined, defaultValue: T, onChange?: (value: T) => void): [T, (value: T | ((prev: T) => T)) => void] {
    const [internal, setInternal] = useState<T>(controlledValue !== undefined ? controlledValue : defaultValue);
    const prevControlled = useRef(controlledValue);
    useEffect(() => {
        if (controlledValue !== undefined && controlledValue !== prevControlled.current) setInternal(controlledValue);
        prevControlled.current = controlledValue;
    }, [controlledValue]);
    const setValue = useCallback(
        (next: T | ((prev: T) => T)) => {
            const resolved = typeof next === 'function' ? (next as (prev: T) => T)(internal) : next;
            if (resolved === internal) return;
            setInternal(resolved);
            onChange?.(resolved);
        },
        [internal, onChange],
    );

    return [internal, setValue];
}

describe('DataGrid 정렬 머리 — 키보드 조작과 aria-sort (7.1.1)', () => {
    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario control=datagrid_sort_header
     * @effects datagrid_sort_header_keyboard_operable
     */
    it('정렬 가능한 머리는 칸 안 type=button 단추이고 칸에 aria-sort 가 있다(정렬 불가 열은 단추·aria-sort 없음)', () => {
        render(<DataGrid columns={columns} data={rows} idField="id" sortable sortField="created_at" sortDirection="asc" onSortChange={vi.fn()} />);
        const dateBtn = within(header('일시')).getByRole('button');
        expect(dateBtn.getAttribute('type')).toBe('button');
        expect(dateBtn.textContent).toContain('일시');
        expect(header('일시').getAttribute('aria-sort')).toBe('ascending');
        expect(header('금액').getAttribute('aria-sort')).toBe('none');
        expect(within(header('메모')).queryByRole('button')).toBeNull();
        expect(header('메모').hasAttribute('aria-sort')).toBe(false);
    });

    /**
     * @scenario control=datagrid_sort_header
     * @effects datagrid_sort_header_keyboard_operable
     */
    it('Tab 으로 머리 단추에 닿고 Enter·Space 로 정렬을 한 번씩 바꾼다(controlled)', async () => {
        const user = userEvent.setup();
        const onSortChange = vi.fn();
        const { rerender } = render(
            <DataGrid columns={columns} data={rows} idField="id" sortable sortField="created_at" sortDirection="asc" onSortChange={onSortChange} />,
        );
        await user.tab();
        expect(document.activeElement).toBe(within(header('일시')).getByRole('button'));
        await user.keyboard('{Enter}');
        expect(onSortChange).toHaveBeenCalledTimes(1);
        expect(onSortChange).toHaveBeenLastCalledWith('created_at', 'desc');

        rerender(<DataGrid columns={columns} data={rows} idField="id" sortable sortField="created_at" sortDirection="desc" onSortChange={onSortChange} />);
        expect(header('일시').getAttribute('aria-sort')).toBe('descending');

        await user.tab();
        expect(document.activeElement).toBe(within(header('금액')).getByRole('button'));
        await user.keyboard(' ');
        expect(onSortChange).toHaveBeenCalledTimes(2);
        expect(onSortChange).toHaveBeenLastCalledWith('amount', 'asc');
    });

    /**
     * @scenario control=datagrid_sort_header
     * @effects datagrid_sort_header_keyboard_operable
     */
    it('마우스 — 단추를 눌러도, 칸 여백(칸 자체)을 눌러도 정렬은 한 번만 바뀐다', async () => {
        const user = userEvent.setup();
        const onSortChange = vi.fn();
        render(<DataGrid columns={columns} data={rows} idField="id" sortable onSortChange={onSortChange} />);
        await user.click(within(header('금액')).getByRole('button'));
        expect(onSortChange).toHaveBeenCalledTimes(1);
        await user.click(header('일시'));
        expect(onSortChange).toHaveBeenCalledTimes(2);
    });

    /**
     * @scenario control=datagrid_sort_header
     * @effects datagrid_sort_header_keyboard_operable
     */
    it('비제어(자체 정렬) — Enter 로 줄 순서와 aria-sort 가 바뀐다', async () => {
        (window as any).G7Core = { useControllableState, shallowArrayEqual: (a: any[], b: any[]) => a === b || (a.length === b.length && a.every((x, i) => x === b[i])) };
        const user = userEvent.setup();
        render(<DataGrid columns={columns} data={rows} idField="id" sortable />);
        within(header('금액')).getByRole('button').focus();
        await user.keyboard('{Enter}');
        expect(header('금액').getAttribute('aria-sort')).toBe('ascending');
        const firstAmount = screen.getAllByRole('row')[1].textContent;
        expect(firstAmount).toContain('100');
        await user.keyboard('{Enter}');
        expect(header('금액').getAttribute('aria-sort')).toBe('descending');
        expect(screen.getAllByRole('row')[1].textContent).toContain('300');
    });
});

