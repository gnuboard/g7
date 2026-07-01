/**
 * adaptExtensionToLayoutDocument.buildMergedRaw 테스트
 *
 * 호스트 raw + 확장 content + 병합 components 를 캔버스 렌더용 raw 로 합성 — 호스트
 * data_sources 보존 + 확장 data_sources 병합(호스트 바인딩·확장 조각 바인딩 모두 샘플 해석).
 */

import { describe, it, expect } from 'vitest';
import { buildMergedRaw } from '../../hooks/adaptExtensionToLayoutDocument';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const comps: EditorNode[] = [{ id: 'merged' }];

describe('buildMergedRaw (G3)', () => {
  it('호스트 raw 베이스 + components 교체 + data_sources 병합(중복 id 호스트 우선)', () => {
    const hostRaw = {
      meta: { title: 'host' },
      modals: [{ id: 'm' }],
      data_sources: [{ id: 'profile', endpoint: '/api/me' }, { id: 'shared', endpoint: '/host' }],
      components: [{ id: 'host-old' }],
    };
    const content = {
      extension_point: 'ep',
      data_sources: [{ id: 'shared', endpoint: '/ext' }, { id: 'ext_ds', endpoint: '/ext2' }],
      components: [{ id: 'frag' }],
    };
    const raw = buildMergedRaw(hostRaw, content, comps) as any;
    // 호스트 메타/모달 보존.
    expect(raw.meta.title).toBe('host');
    expect(raw.modals).toEqual([{ id: 'm' }]);
    // components = 병합 트리.
    expect(raw.components).toEqual([{ id: 'merged' }]);
    // data_sources = 호스트 + 확장 병합, shared 는 호스트 우선(/host).
    const ids = raw.data_sources.map((d: any) => d.id);
    expect(ids).toEqual(['profile', 'shared', 'ext_ds']);
    expect(raw.data_sources.find((d: any) => d.id === 'shared').endpoint).toBe('/host');
  });

  it('호스트 미병합(빈 hostRaw) — 확장 content 를 베이스로 디그레이드', () => {
    const content = {
      extension_point: 'ep',
      priority: 5,
      data_sources: [{ id: 'ext_ds' }],
      components: [{ id: 'frag' }],
    };
    const raw = buildMergedRaw({}, content, comps) as any;
    expect(raw.priority).toBe(5);
    expect(raw.components).toEqual([{ id: 'merged' }]);
    expect(raw.data_sources.map((d: any) => d.id)).toEqual(['ext_ds']);
  });

  it('data_sources 없으면 키 미포함', () => {
    const raw = buildMergedRaw({ components: [] }, { components: [] }, comps) as any;
    expect('data_sources' in raw).toBe(false);
    expect(raw.components).toEqual([{ id: 'merged' }]);
  });
});
