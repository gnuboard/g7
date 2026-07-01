// e2e:allow 레이아웃 편집기 캔버스(base 모드) 슬롯 표시 변환 — Chrome MCP 라이브 매트릭스(관리자/사용자 base + route + content 슬롯)로 실측 검증 + 단위 테스트로 잠금. 편집기 캔버스는 Playwright 환경과 별개.
/**
 * baseEditorSlotMarkers.test.ts — base 편집 모드 슬롯 표시 변환 회귀 테스트
 *
 * 배경: base 편집 모드에서 슬롯 노드가 주입 앵커 원위치(헤더 밖)에 마커로 치환
 * 표시되어, 모듈이 주입한 통화 셀렉터가 헤더 위 별도 박스로 나타나던 결함. 소비처
 * (SlotContainer)가 같은 base 레이아웃에 있는 슬롯은 치환하지 않고 슬롯 메커니즘에 위임해
 * 실제 위치(헤더 안 SlotContainer)에서 렌더되게 한다. content 처럼 소비처가 없는 슬롯만
 * 원위치 마커 치환을 유지한다.
 */

import { describe, it, expect } from 'vitest';
import {
  buildBaseEditorComponents,
  collectContainerSlotIds,
} from '../../utils/baseEditorSlotMarkers';

describe('collectContainerSlotIds', () => {
  it('SlotContainer(props.slotId) 노드의 slotId 를 수집한다 (중첩 포함)', () => {
    const tree = [
      { name: 'Div', children: [{ name: 'SlotContainer', props: { slotId: 'header_currency' } }] },
      { name: 'SlotContainer', props: { slotId: 'footer_widgets' } },
    ];
    const ids = collectContainerSlotIds(tree);
    expect(ids.has('header_currency')).toBe(true);
    expect(ids.has('footer_widgets')).toBe(true);
    expect(ids.size).toBe(2);
  });

  it('SlotContainer 가 없으면 빈 집합', () => {
    const ids = collectContainerSlotIds([{ name: 'Div', children: [{ name: 'Span' }] }]);
    expect(ids.size).toBe(0);
  });

  it('slotId 가 문자열이 아니면 무시', () => {
    const ids = collectContainerSlotIds([{ name: 'SlotContainer', props: {} }]);
    expect(ids.size).toBe(0);
  });
});

describe('buildBaseEditorComponents — 소비처 유무에 따른 슬롯 처리', () => {
  it('소비처(SlotContainer) 있는 슬롯은 slot 키 보존(치환 스킵 → 슬롯 메커니즘 위임)', () => {
    const raw = [
      // 주입 앵커 원위치 (헤더 밖) 의 슬롯 노드
      { id: 'anchor', name: 'Div', children: [{ id: 'sel', name: 'Div', slot: 'header_currency' }] },
      // 헤더 안 소비처
      { id: 'header', name: 'Div', children: [{ name: 'SlotContainer', props: { slotId: 'header_currency' } }] },
    ];
    const out = buildBaseEditorComponents<Record<string, any>>(raw);
    const selNode = out[0].children[0];
    expect(selNode.slot).toBe('header_currency'); // slot 키 보존
    expect(selNode.__editorSlotName).toBeUndefined(); // 마커 치환 안 됨
  });

  it('소비처 없는 슬롯(content)은 __editorSlotName 마커로 치환(원위치 점선 박스)', () => {
    const raw = [{ id: 'main', name: 'Div', slot: 'content' }];
    const out = buildBaseEditorComponents<Record<string, any>>(raw);
    expect(out[0].slot).toBeUndefined(); // slot 키 제거
    expect(out[0].__editorSlotName).toBe('content'); // 마커로 치환
  });

  it('한 트리에 두 종류 슬롯 공존 — 각각 다르게 처리', () => {
    const raw = [
      { id: 'currency', name: 'Div', slot: 'header_currency' },
      { id: 'content', name: 'Div', slot: 'content' },
      { id: 'hdr', name: 'Div', children: [{ name: 'SlotContainer', props: { slotId: 'header_currency' } }] },
    ];
    const out = buildBaseEditorComponents<Record<string, any>>(raw);
    expect(out[0].slot).toBe('header_currency'); // 소비처 있음 → 보존
    expect(out[0].__editorSlotName).toBeUndefined();
    expect(out[1].slot).toBeUndefined(); // 소비처 없음 → 치환
    expect(out[1].__editorSlotName).toBe('content');
  });

  it('원본 raw 를 변형하지 않는다(표시용 사본만 생성)', () => {
    const raw = [{ id: 'main', name: 'Div', slot: 'content' }];
    buildBaseEditorComponents(raw);
    expect((raw[0] as Record<string, unknown>).slot).toBe('content'); // 원본 보존
    expect((raw[0] as Record<string, unknown>).__editorSlotName).toBeUndefined();
  });

  it('노드 구조(개수/순서)는 불변 — data-editor-path 정합 유지', () => {
    const raw = [
      { id: 'a', name: 'Div', slot: 'header_currency' },
      { id: 'b', name: 'Div', children: [{ name: 'SlotContainer', props: { slotId: 'header_currency' } }] },
      { id: 'c', name: 'Div', slot: 'content' },
    ];
    const out = buildBaseEditorComponents<Record<string, any>>(raw);
    expect(out.length).toBe(3);
    expect(out.map((n) => n.id)).toEqual(['a', 'b', 'c']);
  });
});
