/**
 * @file ColumnSelector.dynamicColumns.test.tsx
 * @description 열 선택기의 동적 열(dynamicColumns)과 저장값 (#490 6단계: 회원 목록이 첫 사용, 0단계 S9)
 *
 * 0단계 스파이크 S9 가 남긴 것: 「열 선택 저장값이 있는 운영자에게는 새 동적 열이 자동으로 켜지지 않는다」.
 * 처음 켜진 동적 열이 저장값을 만들기 때문에, 운영자가 두 번째로 켠 항목 열부터는 선택기를 열어 직접 켜야만 보였다.
 * 선택기가 「이미 본 동적 열」을 저장값 옆(`{저장 키}::dynamic`)에 남기도록 바꿔 다음을 잠근다.
 *
 *  - 처음 보는 동적 열은 저장값이 있든 없든 스스로 켜지고(출처 `dynamic`), 켜진 결과가 저장된다.
 *  - 사용자가 끈 동적 열은 이미 본 것이라 새로고침 뒤에도 다시 켜지지 않는다.
 *  - `visible: false` 인 동적 열은 꺼진 채 선택지에만 나온다(S9: 종전에는 켜졌다).
 *  - 선택지 순서는 정적 열에 동적 열을 지정한 자리 뒤로 끼운 순서다.
 *  - 동적 열이 없으면 저장소에 아무것도 남기지 않는다.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ColumnSelector } from '../ColumnSelector';

const ID = 'test_dynamic_columns';
const KEY = `g7_columns_${ID}`;
const KNOWN_KEY = `${KEY}::dynamic`;

const columns = [
  { field: 'name', header: '이름', required: true },
  { field: 'nickname', header: '닉네임' },
  { field: 'status', header: '상태' },
];
const JOB = { field: 'custom_fields.job', header: '직업' };
const BIRTH = { field: 'custom_fields.birth', header: '생년월일' };

/**
 * 저장소의 JSON 배열을 읽습니다.
 *
 * @param key 저장 키
 * @returns 배열 또는 null
 */
const stored = (key: string): string[] | null => {
  const raw = localStorage.getItem(key);
  return raw ? JSON.parse(raw) : null;
};

/**
 * 선택기를 그립니다(레이아웃처럼 닉네임 뒤에 동적 열을 끼운다).
 *
 * @param dynamic 동적 열
 * @param onChange 표시 열 콜백
 * @returns 렌더 결과
 */
const mount = (dynamic: any[] | undefined, onChange = vi.fn()) =>
  render(
    <ColumnSelector
      id={ID}
      columns={columns as any}
      defaultColumns={['name']}
      dynamicColumns={dynamic as any}
      dynamicColumnsInsertAfter="nickname"
      onColumnVisibilityChange={onChange}
    />,
  );

/**
 * 선택지를 열어 체크박스의 이름과 켜짐을 순서대로 읽습니다.
 *
 * @returns [이름, 켜짐] 목록
 */
const options = (): Array<[string, boolean]> => {
  if (screen.queryAllByRole('checkbox').length === 0) fireEvent.click(screen.getByRole('button'));
  return screen.getAllByRole('checkbox').map((box) => [box.getAttribute('aria-label') ?? '', (box as HTMLInputElement).checked]);
};

/**
 * 콜백 호출 중 마지막 것을 읽습니다.
 *
 * @param onChange 표시 열 콜백
 * @returns [표시 열, 출처]
 */
const lastCall = (onChange: ReturnType<typeof vi.fn>): [string[], string | undefined] => {
  const call = onChange.mock.calls.at(-1)!;
  return [call[0], call[1]?.source];
};

beforeEach(() => {
  localStorage.clear();
});

describe('ColumnSelector 동적 열', () => {
  /**
   * @scenario case=selector_auto_show
   * @effects selector_lists_dynamic_columns_after_anchor
   */
  it('선택지는 정적 열에 동적 열을 지정한 열 뒤로 끼운 순서다', () => {
    mount([JOB, BIRTH]);

    expect(options().map(([name]) => name)).toEqual(['이름', '닉네임', '직업', '생년월일', '상태']);
  });

  /**
   * @scenario case=selector_auto_show
   * @effects first_seen_dynamic_column_auto_shown_and_saved
   */
  it('저장값이 없을 때 처음 보는 동적 열은 스스로 켜지고 결과와 본 목록이 저장된다', () => {
    const onChange = vi.fn();
    mount([JOB, BIRTH], onChange);

    expect(lastCall(onChange)).toEqual([['name', JOB.field, BIRTH.field], 'dynamic']);
    expect(stored(KEY)).toEqual(['name', JOB.field, BIRTH.field]);
    expect(stored(KNOWN_KEY)).toEqual([JOB.field, BIRTH.field]);
    expect(options()).toEqual([['이름', true], ['닉네임', false], ['직업', true], ['생년월일', true], ['상태', false]]);
  });

  /**
   * @scenario case=selector_auto_show
   * @effects saved_selection_still_gets_new_dynamic_column
   */
  it('열 선택 저장값이 있는 사용자에게도 처음 보는 동적 열은 켜진다 (S9)', () => {
    // 동적 열이 생기기 전에 사용자가 고른 열
    localStorage.setItem(KEY, JSON.stringify(['name', 'status']));
    const onChange = vi.fn();
    mount([JOB], onChange);

    expect(onChange.mock.calls[0]).toEqual([['name', 'status'], { source: 'init' }]);
    expect(lastCall(onChange)).toEqual([['name', 'status', JOB.field], 'dynamic']);
    expect(stored(KEY)).toEqual(['name', 'status', JOB.field]);
    expect(stored(KNOWN_KEY)).toEqual([JOB.field]);
  });

  /**
   * @scenario case=selector_auto_show
   * @effects second_new_dynamic_column_auto_shown_after_reload
   */
  it('동적 열 하나를 이미 본 뒤 새로 생긴 두 번째 동적 열도 다음 방문에서 켜진다 (S9 의 실제 경로)', () => {
    const first = mount([JOB]);
    expect(stored(KEY)).toEqual(['name', JOB.field]);
    first.unmount();

    // 운영자가 항목 하나를 더 목록 열로 켠 뒤 다시 들어온다
    const onChange = vi.fn();
    mount([JOB, BIRTH], onChange);

    expect(lastCall(onChange)).toEqual([['name', JOB.field, BIRTH.field], 'dynamic']);
    expect(stored(KNOWN_KEY)).toEqual([JOB.field, BIRTH.field]);
    expect(options()).toEqual([['이름', true], ['닉네임', false], ['직업', true], ['생년월일', true], ['상태', false]]);
  });

  /**
   * @scenario case=selector_persistence
   * @effects user_hidden_dynamic_column_stays_hidden_after_reload
   */
  it('사용자가 끈 동적 열은 새로고침 뒤에도 꺼져 있고, 켜 둔 동적 열은 켜져 있다', () => {
    const first = mount([JOB, BIRTH]);
    options();
    fireEvent.click(screen.getByRole('checkbox', { name: '직업' }));
    expect(stored(KEY)).toEqual(['name', BIRTH.field]);
    first.unmount();

    const onChange = vi.fn();
    mount([JOB, BIRTH], onChange);

    // 저장값으로 맞추는 init 한 번뿐이다. 이미 본 열이라 dynamic 은 오지 않는다
    expect(onChange.mock.calls).toEqual([[['name', BIRTH.field], { source: 'init' }]]);
    expect(options()).toEqual([['이름', true], ['닉네임', false], ['직업', false], ['생년월일', true], ['상태', false]]);
    expect(stored(KEY)).toEqual(['name', BIRTH.field]);
  });

  /**
   * @scenario case=selector_persistence
   * @effects user_toggle_of_dynamic_column_saved
   */
  it('동적 열도 켜고 끄면 출처 user 로 알리고 저장된다', () => {
    const onChange = vi.fn();
    mount([JOB], onChange);
    options();

    fireEvent.click(screen.getByRole('checkbox', { name: '직업' }));
    expect(lastCall(onChange)).toEqual([['name'], 'user']);
    expect(stored(KEY)).toEqual(['name']);

    fireEvent.click(screen.getByRole('checkbox', { name: '직업' }));
    expect(lastCall(onChange)).toEqual([['name', JOB.field], 'user']);
    expect(stored(KEY)).toEqual(['name', JOB.field]);
  });

  /**
   * @scenario case=selector_auto_show
   * @effects late_dynamic_columns_handled_like_first_seen
   */
  it('동적 열이 늦게 와도(응답 도착 뒤) 같은 규칙으로 켜진다', () => {
    const onChange = vi.fn();
    const view = mount([], onChange);
    expect(onChange.mock.calls).toEqual([[['name'], { source: 'init' }]]);
    expect(stored(KNOWN_KEY)).toBeNull();

    view.rerender(
      <ColumnSelector
        id={ID}
        columns={columns as any}
        defaultColumns={['name']}
        dynamicColumns={[JOB] as any}
        dynamicColumnsInsertAfter="nickname"
        onColumnVisibilityChange={onChange}
      />,
    );

    expect(lastCall(onChange)).toEqual([['name', JOB.field], 'dynamic']);
    expect(stored(KNOWN_KEY)).toEqual([JOB.field]);
  });

  /**
   * @scenario case=selector_auto_show
   * @effects visible_false_dynamic_column_listed_but_off
   */
  it('visible: false 인 동적 열은 스스로 켜지지 않고 선택지에 꺼진 채 나온다. 본 것으로는 남는다', () => {
    const onChange = vi.fn();
    mount([JOB, { ...BIRTH, visible: false }], onChange);

    expect(lastCall(onChange)).toEqual([['name', JOB.field], 'dynamic']);
    expect(stored(KNOWN_KEY)).toEqual([JOB.field, BIRTH.field]);
    expect(options()).toEqual([['이름', true], ['닉네임', false], ['직업', true], ['생년월일', false], ['상태', false]]);
  });

  /**
   * @scenario case=selector_persistence
   * @effects removed_dynamic_column_keeps_user_choice_when_back
   */
  it('목록에서 빠졌다가 다시 생긴 동적 열은 사용자가 마지막에 둔 상태(끔)를 지킨다', () => {
    const first = mount([JOB, BIRTH]);
    options();
    fireEvent.click(screen.getByRole('checkbox', { name: '직업' }));
    first.unmount();

    // 운영자가 「직업」의 목록 열을 껐다
    const second = mount([BIRTH]);
    expect(options().map(([name]) => name)).toEqual(['이름', '닉네임', '생년월일', '상태']);
    second.unmount();

    // 다시 켰다
    mount([JOB, BIRTH]);
    expect(options()).toEqual([['이름', true], ['닉네임', false], ['직업', false], ['생년월일', true], ['상태', false]]);
  });

  /**
   * @scenario case=selector_listing
   * @effects no_dynamic_columns_writes_nothing
   */
  it('동적 열이 없으면 선택지는 정적 열 그대로이고 저장소에 아무것도 남기지 않는다', () => {
    const onChange = vi.fn();
    mount(undefined, onChange);

    expect(options().map(([name]) => name)).toEqual(['이름', '닉네임', '상태']);
    expect(onChange.mock.calls).toEqual([[['name'], { source: 'init' }]]);
    expect(stored(KEY)).toBeNull();
    expect(stored(KNOWN_KEY)).toBeNull();
  });

  /**
   * @scenario case=selector_auto_show
   * @effects known_dynamic_key_shares_declared_storage_prefix
   */
  it('본 목록의 저장 키는 표시 열 저장 키와 같은 접두(g7_columns_)라 필수 저장 선언에 든다', () => {
    mount([JOB]);
    const keys = Array.from({ length: localStorage.length }, (_, i) => localStorage.key(i)!);

    expect(keys.sort()).toEqual([KEY, KNOWN_KEY].sort());
    expect(keys.every((key) => key.startsWith('g7_columns_'))).toBe(true);
  });
});
