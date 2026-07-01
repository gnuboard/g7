/**
 * dataSourceConditionAdapter.test.ts — data_source ↔ ConditionBuilder 어댑터
 *
 * 검증:
 *  ① dataSourceToConditionNode: ds.if → 가짜 EditorNode(node.if)
 *  ② applyConditionNodeToDataSource: 역적용(빈 식 → if 키 제거)
 *  ③ 라운드트립(ds→node→편집→ds 동등성)
 *  ④ 기존 ds 보존 필드(__source/id 등) 무손실
 *  ⑤ conditions(레거시 배열) 보존
 */

import { describe, it, expect } from 'vitest';
import {
  dataSourceToConditionNode,
  applyConditionNodeToDataSource,
} from '../../spec/dataSourceConditionAdapter';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('dataSourceConditionAdapter', () => {
  it('ds.if 를 가짜 노드 node.if 로 노출', () => {
    const node = dataSourceToConditionNode({ id: 'products', if: '{{ route.id }}' });
    expect((node as Record<string, unknown>).if).toBe('{{ route.id }}');
  });

  it('if 없으면 node.if 부재', () => {
    const node = dataSourceToConditionNode({ id: 'x' });
    expect('if' in (node as Record<string, unknown>)).toBe(false);
  });

  it('역적용 — if 반영 + 다른 키 보존', () => {
    const ds = { id: 'products', endpoint: '/api/products', if: '{{ route.id }}', __source: { kind: 'route' } };
    const node = { if: '{{ query.q }}' } as EditorNode;
    const next = applyConditionNodeToDataSource(node, ds);
    expect(next.if).toBe('{{ query.q }}');
    expect(next.id).toBe('products');
    expect(next.endpoint).toBe('/api/products');
    expect(next.__source).toEqual({ kind: 'route' });
  });

  it('빈 식 → if 키 제거', () => {
    const next = applyConditionNodeToDataSource({} as EditorNode, { id: 'x', if: '{{ a }}' });
    expect('if' in next).toBe(false);
    expect(next.id).toBe('x');
  });

  it('라운드트립 동등성(ds → node → 편집 → ds)', () => {
    const ds = { id: 'p', if: '{{ route.id }}', conditions: [{ field: 'x' }] };
    const node = dataSourceToConditionNode(ds);
    const edited = { ...node, if: '{{ route.id && query.q }}' } as EditorNode;
    const next = applyConditionNodeToDataSource(edited, ds);
    expect(next.if).toBe('{{ route.id && query.q }}');
    // conditions(레거시 배열) 보존.
    expect(next.conditions).toEqual([{ field: 'x' }]);
  });
});
