/**
 * ComponentRegistry 격리 인스턴스 가드
 *
 * 회귀 원인: 레이아웃 편집기 캔버스(`PreviewCanvas`) 가 호스트 페이지의
 * ComponentRegistry 싱글톤을 점유한 채 다른 템플릿(`sirsoft-basic`) 을 렌더
 * 하려고 해 `Header`/`Footer` 컴포넌트 미발견 회귀. 본 테스트는 격리 인스턴스
 * 가 싱글톤과 독립적으로 동작함을 가드.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { ComponentRegistry } from '../ComponentRegistry';

describe('ComponentRegistry.createIsolatedInstance', () => {
  beforeEach(() => {
    ComponentRegistry.resetInstance();
  });

  it('격리 인스턴스는 싱글톤과 다른 객체', () => {
    const singleton = ComponentRegistry.getInstance();
    const isolated = ComponentRegistry.createIsolatedInstance();

    expect(isolated).not.toBe(singleton);
  });

  it('격리 인스턴스를 두 번 호출하면 매번 새 인스턴스', () => {
    const a = ComponentRegistry.createIsolatedInstance();
    const b = ComponentRegistry.createIsolatedInstance();
    expect(a).not.toBe(b);
  });

  it('싱글톤 getInstance() 는 동일 인스턴스 (격리 호출이 영향 주지 않음)', () => {
    const singletonBefore = ComponentRegistry.getInstance();
    ComponentRegistry.createIsolatedInstance();
    ComponentRegistry.createIsolatedInstance();
    const singletonAfter = ComponentRegistry.getInstance();
    expect(singletonBefore).toBe(singletonAfter);
  });

  it('격리 인스턴스의 registry/manifest 초기 상태는 비어 있고 loadingState 는 idle', () => {
    const isolated = ComponentRegistry.createIsolatedInstance();
    expect(isolated.getComponentMap()).toEqual({});
    expect(isolated.getLoadingState()).toBe('idle');
    expect(isolated.getTemplateId()).toBeNull();
  });
});
