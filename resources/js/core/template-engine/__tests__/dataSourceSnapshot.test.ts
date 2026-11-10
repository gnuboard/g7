/**
 * 더보기 스냅샷 모듈 (engine-v1.85.0) — 기억·가져가기·첫 묶음 반영의 입력 모양별 잠금
 *
 * @scenario-manifest tests/scenarios/client-router-scroll-restoration.yaml
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  captureGrownDataSources,
  markDataSourceGrown,
  mergeSnapshotWithFresh,
  peekDataSourceSnapshotIds,
  resetDataSourceSnapshotsForTesting,
  takeDataSourceSnapshots,
  unmarkDataSourceGrown,
} from '../dataSourceSnapshot';

const list = (ids: number[], suffix = '') => ({ data: { data: ids.map((id) => ({ id, name: `상품 ${id}${suffix}` })) }, meta: { current_page: 1 } });

describe('dataSourceSnapshot', () => {
  beforeEach(() => resetDataSourceSnapshotsForTesting());

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_restores_more_loaded_list
  it('늘어난 데이터소스만 그 항목 키로 기억하고, 함께 적은 상태 경로 값도 기억한다', () => {
    markDataSourceGrown('products', 'data.data');
    captureGrownDataSources('entry:a', (id) => (id === 'products' ? list([1, 2, 3]) : list([9])), ['_global.infiniteScroll'], () => ({ currentPage: 2 }), 'ko|guest');

    const taken = takeDataSourceSnapshots('entry:a', 600, 'ko|guest');
    expect([...taken.sources.keys()]).toEqual(['products']);
    expect(taken.state).toEqual({ '_global.infiniteScroll': { currentPage: 2 } });
    // 한 번 가져가면 지운다
    expect(peekDataSourceSnapshotIds('entry:a')).toEqual([]);
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_discarded_on_context_change
  it('언어나 계정이 바뀌었으면(문맥 불일치) 되살리지 않는다', () => {
    markDataSourceGrown('products', 'data.data');
    captureGrownDataSources('entry:a', () => list([1, 2]), [], () => undefined, 'ko|7');

    expect(takeDataSourceSnapshots('entry:a', 600, 'en|7').sources.size).toBe(0);
    markDataSourceGrown('products', 'data.data');
    captureGrownDataSources('entry:b', () => list([1, 2]), [], () => undefined, 'ko|7');
    expect(takeDataSourceSnapshots('entry:b', 600, 'ko|8').sources.size).toBe(0);
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_discarded_on_context_change
  it('유효 시간이 지난 스냅샷은 버리고 상태도 되돌리지 않는다', () => {
    markDataSourceGrown('products', 'data.data');
    captureGrownDataSources('entry:a', () => list([1, 2]), ['_global.x'], () => 5, 'ko|guest');

    const taken = takeDataSourceSnapshots('entry:a', 0 - 1, 'ko|guest');
    expect(taken.sources.size).toBe(0);
    expect(taken.state).toEqual({});
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_restores_more_loaded_list
  it('가져가기만 해서는 늘어남 표시가 생기지 않는다 — 심을 때 표시한다(밀린 화면 처리가 앞 화면 값을 이 항목으로 잘못 기억하지 않게)', () => {
    markDataSourceGrown('products', 'data.data');
    captureGrownDataSources('entry:a', () => list([1, 2]), [], () => undefined, 'c');
    takeDataSourceSnapshots('entry:a', 600, 'c');

    // 심지 못한 채 다음 이동 — 남은 앞 화면 값이 entry:a 로 기억되면 안 된다
    captureGrownDataSources('entry:a', () => list([7, 8, 9]), [], () => undefined, 'c');
    expect(peekDataSourceSnapshotIds('entry:a')).toEqual([]);

    // 심었으면(표시) 다시 떠날 때 기억된다, 실패로 표시를 지우면 기억되지 않는다
    markDataSourceGrown('products', 'data.data');
    unmarkDataSourceGrown('products');
    captureGrownDataSources('entry:a', () => list([1, 2]), [], () => undefined, 'c');
    expect(peekDataSourceSnapshotIds('entry:a')).toEqual([]);
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_ttl_counts_from_first_capture
  it('심을 때 처음 기억한 시각을 넘기면, 다시 떠날 때 그 시각으로 기억한다 — 이어서 더보기를 해도 유지된다', () => {
    const realNow = Date.now.bind(Date);
    let offsetMs = 0;
    const spy = vi.spyOn(Date, 'now').mockImplementation(() => realNow() + offsetMs);
    try {
      markDataSourceGrown('products', 'data.data');
      captureGrownDataSources('entry:a', () => list([1, 2, 3]), [], () => undefined, 'c');
      const firstSavedAt = takeDataSourceSnapshots('entry:a', 600, 'c').sources.get('products')!.savedAt;

      offsetMs = 500_000;
      markDataSourceGrown('products', 'data.data', firstSavedAt); // 심을 때
      markDataSourceGrown('products', 'data.data'); // 돌아와서 더보기를 한 번 더
      captureGrownDataSources('entry:a', () => list([1, 2, 3, 4]), [], () => undefined, 'c');

      offsetMs = 700_000;
      expect(takeDataSourceSnapshots('entry:a', 600, 'c').sources.size).toBe(0);
    } finally {
      spy.mockRestore();
    }
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_restores_more_loaded_list
  it('첫 묶음 반영 — 같은 id 는 새 값, 첫 묶음 앞쪽의 새 항목은 앞에 붙이고, 목록 밖 필드는 스냅샷 것을 유지한다', () => {
    const snapshot = { id: 'products', path: 'data.data', data: list([3, 2, 1, 0]), savedAt: Date.now() };
    const fresh = { data: { data: [{ id: 5, name: '새 상품' }, { id: 3, name: '상품 3 (변경)' }, { id: 2, name: '상품 2' }] }, meta: { current_page: 1, total: 99 } };

    const merged = mergeSnapshotWithFresh(snapshot, fresh, 'id') as any;
    expect(merged.data.data.map((i: any) => i.id)).toEqual([5, 3, 2, 1, 0]);
    expect(merged.data.data[1].name).toBe('상품 3 (변경)');
    expect(merged.meta).toEqual({ current_page: 1 });
  });

  // @scenario entry=popstate, content_timing=grows_late, config_layer=layout_meta
  // @effects snapshot_restores_more_loaded_list
  it('첫 묶음이 스냅샷과 하나도 겹치지 않으면(정렬·필터가 다른 응답) 새 항목을 붙이지 않고, 모양이 다르면 스냅샷 그대로', () => {
    const snapshot = { id: 'products', path: 'data.data', data: list([3, 2, 1]), savedAt: Date.now() };

    expect((mergeSnapshotWithFresh(snapshot, list([10, 11]), 'id') as any).data.data.map((i: any) => i.id)).toEqual([3, 2, 1]);
    expect((mergeSnapshotWithFresh(snapshot, { data: null }, 'id') as any).data.data).toHaveLength(3);
    // 경로가 없으면 값 자체가 배열
    const flat = { id: 'n', path: null, data: [{ id: 2 }, { id: 1 }], savedAt: Date.now() };
    expect(mergeSnapshotWithFresh(flat, [{ id: 3 }, { id: 2, x: 1 }], 'id')).toEqual([{ id: 3 }, { id: 2, x: 1 }, { id: 1 }]);
  });
});
