/**
 * @file TemplateApp.skeletonOverlayA11y.test.ts
 * @description 스켈레톤 전환 덮개의 상태 영역 이름이 화면 언어이고, 안쪽 컴포넌트와 겹치지 않는다 (engine-v1.79.0)
 *
 * 버그 (7.1.0 원 세션 실측 O-C3b-2):
 *   스켈레톤 덮개 컨테이너(#g7-skeleton-overlay)가 role="status" + aria-label="Loading..." 영문 고정이었다.
 *   그 안에 PageSkeleton 이 번역된 role="status" 를 다시 그려 상태 영역이 둘 겹쳤다(ko/en/ja 모두 바깥 「Loading...」).
 *
 * 수정: 바깥 이름은 템플릿 번역 nav.loading(스피너 덮개와 같은 키), 안쪽이 상태 영역을 그리면 바깥의 역할·이름을 걷는다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';

const { registryComponents } = vi.hoisted(() => ({ registryComponents: {} as Record<string, any> }));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  updateTemplateData: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(null),
  getState: vi.fn().mockReturnValue({ currentLayoutJson: null, currentDataContext: {} }),
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    this.on = vi.fn();
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn((name: string) => registryComponents[name] ?? null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';

const layoutData = { components: [{ id: 'tab_content', name: 'Div', children: [] }] };

describe('스켈레톤 전환 덮개 접근성 (engine-v1.79.0, O-C3b-2)', () => {
  let app: TemplateApp;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"><div id="tab_content"></div></div>';
    (window as any).G7Core = { t: (key: string) => (key === 'nav.loading' ? '로딩 중...' : key) };
    app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko' } as any);
  });

  afterEach(() => {
    (app as any).hideTransitionOverlay();
    delete (window as any).G7Core;
    for (const k of Object.keys(registryComponents)) delete registryComponents[k];
  });

  // @scenario case=skeleton_without_own_status
  // @effects skeleton_overlay_label_translated
  it('안쪽 컴포넌트가 상태 영역을 그리지 않으면 바깥 덮개가 화면 언어 이름의 status 다', () => {
    registryComponents.PlainSkeleton = () => React.createElement('div', { className: 'animate-pulse' });
    (app as any).renderSkeletonOverlay('tab_content', { component: 'PlainSkeleton' }, layoutData);
    const overlay = document.getElementById('g7-skeleton-overlay')!;
    expect(overlay.getAttribute('role')).toBe('status');
    expect(overlay.getAttribute('aria-label')).toBe('로딩 중...');
  });

  // @scenario case=skeleton_with_own_status
  // @effects skeleton_status_not_duplicated
  it('안쪽 컴포넌트(PageSkeleton)가 상태 영역을 그리면 바깥은 역할·이름을 갖지 않아 한 번만 읽힌다', () => {
    registryComponents.PageSkeleton = () => React.createElement('div', { role: 'status', 'aria-label': '로딩 중...' });
    (app as any).renderSkeletonOverlay('tab_content', { component: 'PageSkeleton' }, layoutData);
    const overlay = document.getElementById('g7-skeleton-overlay')!;
    expect(overlay.hasAttribute('role')).toBe(false);
    expect(overlay.hasAttribute('aria-label')).toBe(false);
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(1);
  });

  // @scenario case=skeleton_without_translation
  // @effects skeleton_overlay_label_translated
  it('번역이 없으면 영문 기본 이름을 쓴다', () => {
    (window as any).G7Core = { t: (key: string) => key };
    registryComponents.PlainSkeleton = () => React.createElement('div');
    (app as any).renderSkeletonOverlay('tab_content', { component: 'PlainSkeleton' }, layoutData);
    expect(document.getElementById('g7-skeleton-overlay')!.getAttribute('aria-label')).toBe('Loading...');
  });
});
