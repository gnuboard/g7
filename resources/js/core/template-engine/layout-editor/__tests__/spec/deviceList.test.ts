/**
 * deviceList.test.ts — 디바이스 목록 동적 수집 SSoT 단위
 *
 * 검증:
 *  - collectResponsiveKeys: base/분기 children 깊이 순회로 responsive 키 전수 수집
 *  - collectDeviceKeys: 프리셋 4 + 동적 키 병합·중복제거·순서
 *  - resolveDeviceWidth: 단일폭 프리셋 / 범위 상한값 / 무한대 폴백 / 알 수 없는 키
 */

import { describe, it, expect } from 'vitest';
import {
  collectResponsiveKeys,
  collectDeviceKeys,
  collectDefinedDeviceBranches,
  resolveDeviceWidth,
  PRESET_DEVICE_KEYS,
  DESKTOP_PRESET_WIDTH,
  TABLET_PRESET_WIDTH,
  MOBILE_PRESET_WIDTH,
} from '../../spec/deviceList';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('collectResponsiveKeys', () => {
  it('빈/없음 입력 → 빈 배열', () => {
    expect(collectResponsiveKeys(null)).toEqual([]);
    expect(collectResponsiveKeys(undefined)).toEqual([]);
    expect(collectResponsiveKeys([])).toEqual([]);
  });

  it('얕은 노드의 responsive 키 수집', () => {
    const nodes: EditorNode[] = [
      { name: 'Div', responsive: { portable: { props: {} } } },
      { name: 'Span', responsive: { '600-900': { children: [] } } },
    ];
    expect(collectResponsiveKeys(nodes)).toEqual(['portable', '600-900']);
  });

  it('base children 깊이 순회', () => {
    const nodes: EditorNode[] = [
      { name: 'Div', children: [{ name: 'Inner', responsive: { mobile: { props: {} } } }] },
    ];
    expect(collectResponsiveKeys(nodes)).toEqual(['mobile']);
  });

  it('분기(responsive[key].children) 안 노드의 중첩 responsive 도 수집', () => {
    const nodes: EditorNode[] = [
      {
        name: 'Div',
        responsive: {
          portable: {
            children: [{ name: 'Deep', responsive: { '0-480': { props: {} } } }],
          },
        },
      },
    ];
    expect(collectResponsiveKeys(nodes)).toEqual(['portable', '0-480']);
  });

  it('중복 키는 한 번만(발견 순)', () => {
    const nodes: EditorNode[] = [
      { name: 'A', responsive: { portable: {} } },
      { name: 'B', responsive: { portable: {}, mobile: {} } },
    ];
    expect(collectResponsiveKeys(nodes)).toEqual(['portable', 'mobile']);
  });
});

describe('collectDeviceKeys', () => {
  it('빈 레이아웃 → 프리셋 4개만', () => {
    expect(collectDeviceKeys([])).toEqual([...PRESET_DEVICE_KEYS]);
  });

  it('프리셋 먼저(고정 순) → 동적 커스텀 키(발견 순)', () => {
    const nodes: EditorNode[] = [
      { name: 'A', responsive: { '600-900': {} } },
      { name: 'B', responsive: { '0-480': {} } },
    ];
    expect(collectDeviceKeys(nodes)).toEqual([
      'desktop',
      'tablet',
      'mobile',
      'portable',
      '600-900',
      '0-480',
    ]);
  });

  it('레이아웃이 프리셋 키만 쓰면 프리셋 4개 그대로(중복 추가 없음)', () => {
    const nodes: EditorNode[] = [{ name: 'A', responsive: { portable: {}, mobile: {} } }];
    expect(collectDeviceKeys(nodes)).toEqual([...PRESET_DEVICE_KEYS]);
  });
});

describe('resolveDeviceWidth', () => {
  it('단일폭 프리셋은 기존 상수', () => {
    expect(resolveDeviceWidth('desktop')).toBe(DESKTOP_PRESET_WIDTH);
    expect(resolveDeviceWidth('tablet')).toBe(TABLET_PRESET_WIDTH);
    expect(resolveDeviceWidth('mobile')).toBe(MOBILE_PRESET_WIDTH);
  });

  it('portable(0~1023) → 상한값 1023', () => {
    expect(resolveDeviceWidth('portable')).toBe(1023);
  });

  it('커스텀 범위("600-900") → 상한값 900', () => {
    expect(resolveDeviceWidth('600-900')).toBe(900);
  });

  it('무한대 범위("1200-") → 데스크톱 기본 폭 폴백', () => {
    expect(resolveDeviceWidth('1200-')).toBe(DESKTOP_PRESET_WIDTH);
  });

  it('과대 상한은 1920 클램프', () => {
    expect(resolveDeviceWidth('100-5000')).toBe(1920);
  });

  it('알 수 없는 키 → 데스크톱 기본 폭 폴백', () => {
    expect(resolveDeviceWidth('not-a-range!!')).toBe(DESKTOP_PRESET_WIDTH);
  });
});

describe('collectDefinedDeviceBranches', () => {
  it('responsive 없음 → 빈 배열', () => {
    expect(collectDefinedDeviceBranches(null)).toEqual([]);
    expect(collectDefinedDeviceBranches({})).toEqual([]);
    expect(collectDefinedDeviceBranches({ responsive: {} })).toEqual([]);
  });

  it('children 교체 구성만 수집(props-only 분기 제외)', () => {
    const node = {
      responsive: {
        portable: { children: [{ name: 'A' }] },
        mobile: { props: { className: 'p-2' } }, // props-only → 제외
      },
    };
    expect(collectDefinedDeviceBranches(node)).toEqual(['portable']);
  });

  it('현재 보고 있는 키(currentBp)는 제외', () => {
    const node = {
      responsive: {
        portable: { children: [{ name: 'A' }] },
        mobile: { children: [{ name: 'B' }] },
      },
    };
    expect(collectDefinedDeviceBranches(node, 'portable')).toEqual(['mobile']);
  });

  // 커스텀 디바이스는 하나가 아니라 여러 개일 수 있다 → 전부 수집되어야 함.
  it('다중 커스텀 범위 children 구성을 모두 수집(단일 가정 없음)', () => {
    const node = {
      responsive: {
        '600-900': { children: [{ name: 'A' }] },
        '0-480': { children: [{ name: 'B' }] },
        '1200-': { children: [{ name: 'C' }] },
        tablet: { props: { className: 'x' } }, // props-only → 제외
      },
    };
    expect(collectDefinedDeviceBranches(node)).toEqual(['600-900', '0-480', '1200-']);
  });

  it('다중 커스텀 + currentBp 제외 동시 적용', () => {
    const node = {
      responsive: {
        '600-900': { children: [{ name: 'A' }] },
        '0-480': { children: [{ name: 'B' }] },
        portable: { children: [{ name: 'C' }] },
      },
    };
    expect(collectDefinedDeviceBranches(node, '0-480')).toEqual(['600-900', 'portable']);
  });
});
