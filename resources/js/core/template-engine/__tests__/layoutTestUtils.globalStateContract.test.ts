/**
 * 공용 레이아웃 테스트 하네스의 `G7Core.state.get` 목 계약 회귀.
 *
 * 운영 계약: `TemplateApp.getGlobalState()` 가 `{ ...globalState }` 를 돌려주므로
 * `G7Core.state.get()` 의 반환값이 **곧 `_global` 의 내용물**이고, `_global` 키는 그 안에 없다.
 * 목이 구계약(`{ _global, _local }`)을 유지하면 렌더 시점 바인딩은 통과하고 액션 params 안의
 * `{{_global.*}}` 만 조용히 undefined 가 되어, 목이 아니라 레이아웃을 의심하게 만든다.
 */
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest } from './utils/layoutTestUtils';

let utils: ReturnType<typeof createLayoutTest> | null = null;
afterEach(() => { utils?.cleanup(); utils = null; });

/** 목을 심고 한 번 렌더한 뒤 `G7Core.state.get()` 스냅샷을 돌려준다. */
async function snapshot(): Promise<any> {
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'global_state_contract', components: [] } as any,
    { initialState: { _global: { siteName: 'G7', count: 3 }, _local: { open: true } } }
  );
  await utils.render();
  return (window as any).G7Core.state.get();
}

describe('createLayoutTest 의 G7Core.state 목', () => {
  /**
   * @scenario read_path=top_level_global, legacy_wrapper=absent
   * @effects state_get_returns_global_contents_at_top_level, state_get_has_no_legacy_global_wrapper_key
   */
  it('반환값이 곧 _global 의 내용물이다 — ._global 로 한 겹 더 감싸지 않는다', async () => {
    const state = await snapshot();
    expect(state.siteName).toBe('G7');
    expect(state.count).toBe(3);
    expect(state._global).toBeUndefined();
  });

  /**
   * @scenario read_path=nested_local, legacy_wrapper=absent
   * @effects state_get_exposes_local_under_local_key
   */
  it('_local 은 그 안의 한 키로 남는다', async () => {
    expect((await snapshot())._local).toEqual({ open: true });
  });
});
