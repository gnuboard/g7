/**
 * CardGrid — 카드 안 ActionMenu 의 문자열 onClick(액션 ID) 실행 경로
 *
 * 카드 셀의 ActionMenu 가 `actions` 배열과 `items[].onClick: "<액션 id>"` 로 선언되면 CardGrid 가
 * onClick 을 함수로 바꿔 그 액션을 실행한다. 종전 구현은 코어에 없는 `G7Core.ActionDispatcher` 를
 * 찾아 `?.` 가드에 걸려 조용히 건너뛰었다 — 메뉴를 눌러도 아무 일도 일어나지 않았다(D3-2).
 * 실행은 코어 공개 API `G7Core.dispatch(action, { componentContext })` 로 하고, 카드 행 컨텍스트
 * (`row`·`$value`·`index`)를 바인딩 데이터로 넘긴다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { CardGrid, CardGridCellChild } from '../CardGrid';

describe('CardGrid — 문자열 onClick 액션은 코어 G7Core.dispatch 로 실행된다', () => {
  let captured: CardGridCellChild[] = [];
  const dispatch = vi.fn();
  let previous: any;

  beforeEach(() => {
    captured = [];
    dispatch.mockReset();
    previous = (window as any).G7Core;
    (window as any).G7Core = {
      ...(previous ?? {}),
      dispatch,
      evaluateCondition: () => true,
      renderItemChildren: (children: CardGridCellChild[]) => {
        captured = children;
        return null;
      },
    };
  });

  afterEach(() => {
    (window as any).G7Core = previous;
  });

  const actionDef = { id: 'open_detail', handler: 'navigate', params: { path: '/admin/items/{{row.id}}' } };
  const cellChildren: CardGridCellChild[] = [
    {
      type: 'composite',
      name: 'ActionMenu',
      actions: [actionDef],
      props: { items: [{ id: 'detail', label: '상세', onClick: 'open_detail' }] },
    } as unknown as CardGridCellChild,
  ];

  // @scenario surface=card_action_menu_string_onclick
  // @effects card_action_runs_via_core_dispatch_with_row_context
  it('메뉴 항목을 누르면 G7Core.dispatch 에 액션 정의와 행 컨텍스트를 넘긴다', () => {
    const row = { id: 7, name: '항목 7' };
    render(<CardGrid data={[row]} cardColumns={[{ id: 'c', cellChildren }]} />);

    const menu = captured[0] as any;
    expect(typeof menu.props.items[0].onClick).toBe('function');

    menu.props.items[0].onClick();

    expect(dispatch).toHaveBeenCalledTimes(1);
    const [action, options] = dispatch.mock.calls[0];
    expect(action).toBe(actionDef);
    expect(options.componentContext.data).toMatchObject({ row, $value: row, index: 0 });
  });

  // @scenario surface=card_action_menu_string_onclick
  // @effects card_action_left_untouched_without_core_dispatch
  it('코어 dispatch 가 없으면 항목을 바꾸지 않는다(존재하지 않는 멤버를 찾지 않는다)', () => {
    (window as any).G7Core = { ...(window as any).G7Core, dispatch: undefined, ActionDispatcher: { dispatch: vi.fn() } };
    render(<CardGrid data={[{ id: 1 }]} cardColumns={[{ id: 'c', cellChildren }]} />);

    const menu = captured[0] as any;
    expect(menu.props.items[0].onClick).toBe('open_detail');
  });
});
