/**
 * G7 DevTools 스타일 추적기
 *
 * 렌더링된 컴포넌트의 DOM 요소를 추적하고 computed styles를 수집합니다.
 * 시각적 이상(보이지 않는 요소, z-index 충돌 등)을 자동으로 감지합니다.
 *
 * @module StyleTracker
 */

import { G7DevToolsCore } from './G7DevToolsCore';
import type { StyleIssue, StyleIssueType } from './types';

/**
 * 스타일 추적에 필요한 computed styles 속성
 */
const TRACKED_STYLE_PROPERTIES = [
  'display',
  'visibility',
  'opacity',
  'position',
  'zIndex',
  'overflow',
  'width',
  'height',
  'backgroundColor',
  'color',
] as const;

/**
 * 컴포넌트 스타일 정보
 */
interface ComponentStyleData {
  componentId: string;
  componentName: string;
  classes: string[];
  computedStyles: Record<string, string>;
  element: WeakRef<HTMLElement>;
  timestamp: number;
}

/**
 * 스타일 추적기 클래스
 *
 * MutationObserver와 ResizeObserver를 사용하여
 * DOM 변경 시 자동으로 스타일 이상을 감지합니다.
 */
export class StyleTracker {
  private static instance: StyleTracker | null = null;
  private enabled: boolean = false;
  private trackedComponents: Map<string, ComponentStyleData> = new Map();
  private mutationObserver: MutationObserver | null = null;
  private scanInterval: ReturnType<typeof setInterval> | null = null;
  private devTools: G7DevToolsCore;

  private constructor() {
    this.devTools = G7DevToolsCore.getInstance();
  }

  /**
   * 싱글톤 인스턴스 반환
   */
  static getInstance(): StyleTracker {
    if (!StyleTracker.instance) {
      StyleTracker.instance = new StyleTracker();
    }
    return StyleTracker.instance;
  }

  /**
   * 스타일 추적 활성화
   */
  enable(): void {
    if (this.enabled) return;
    this.enabled = true;

    // DOM 변경 감지
    this.setupMutationObserver();

    // 주기적 스캔 (5초마다)
    this.scanInterval = setInterval(() => {
      this.scanVisibleComponents();
    }, 5000);

    console.log('[StyleTracker] 스타일 추적 활성화됨');
  }

  /**
   * 스타일 추적 비활성화
   */
  disable(): void {
    if (!this.enabled) return;
    this.enabled = false;

    if (this.mutationObserver) {
      this.mutationObserver.disconnect();
      this.mutationObserver = null;
    }

    if (this.scanInterval) {
      clearInterval(this.scanInterval);
      this.scanInterval = null;
    }

    this.trackedComponents.clear();
    console.log('[StyleTracker] 스타일 추적 비활성화됨');
  }

  /**
   * 컴포넌트 스타일 추적 시작
   *
   * DynamicRenderer에서 컴포넌트 마운트 시 호출됩니다.
   */
  trackComponent(
    componentId: string,
    componentName: string,
    element: HTMLElement | null
  ): void {
    if (!this.enabled || !this.devTools.isEnabled() || !element) return;

    const classes = this.extractClasses(element);
    const computedStyles = this.getComputedStyles(element);

    // DevTools에 추적
    this.devTools.trackComponentStyle(componentId, componentName, classes, computedStyles);

    // 내부 맵에 저장 (WeakRef로 메모리 누수 방지)
    this.trackedComponents.set(componentId, {
      componentId,
      componentName,
      classes,
      computedStyles,
      element: new WeakRef(element),
      timestamp: Date.now(),
    });

    // 즉시 이상 감지
    this.detectIssues(componentId, componentName, element, classes, computedStyles);
  }

  /**
   * 컴포넌트 추적 해제
   *
   * 컴포넌트 언마운트 시 호출됩니다.
   */
  untrackComponent(componentId: string): void {
    this.trackedComponents.delete(componentId);
  }

  /**
   * 모든 보이는 컴포넌트 스캔
   */
  scanVisibleComponents(): void {
    if (!this.enabled || !this.devTools.isEnabled()) return;

    // 1. DevTools에 마운트된 컴포넌트 목록 가져오기
    const lifecycleInfo = this.devTools.getLifecycleInfo();
    const mountedComponents = lifecycleInfo.mountedComponents || [];

    // 2. 마운트된 컴포넌트 ID로 DOM 요소 찾기
    mountedComponents.forEach((comp) => {
      const componentId = comp.id;
      const componentName = comp.name;

      // ID로 DOM 요소 찾기 (여러 방식 시도)
      let element: HTMLElement | null = null;

      // 1) 정확한 ID로 찾기
      if (componentId) {
        element = document.getElementById(componentId);
      }

      // 2) data-component-id 속성으로 찾기
      if (!element && componentId) {
        element = document.querySelector(`[data-component-id="${componentId}"]`) as HTMLElement;
      }

      // 3) data-editor-id 속성으로 찾기
      if (!element && componentId) {
        element = document.querySelector(`[data-editor-id="${componentId}"]`) as HTMLElement;
      }

      if (!element) return;

      const classes = this.extractClasses(element);
      const computedStyles = this.getComputedStyles(element);

      // DevTools에 추적
      this.devTools.trackComponentStyle(componentId, componentName, classes, computedStyles);

      // 이슈 감지
      this.detectIssues(componentId, componentName, element, classes, computedStyles);

      // 맵 업데이트
      this.trackedComponents.set(componentId, {
        componentId,
        componentName,
        classes,
        computedStyles,
        element: new WeakRef(element),
        timestamp: Date.now(),
      });
    });

    // 3. 기존 셀렉터로도 스캔 (하위 호환성)
    const additionalElements = document.querySelectorAll('[data-editor-id], [id^="comp-"]');

    additionalElements.forEach((element) => {
      if (!(element instanceof HTMLElement)) return;

      const componentId = element.getAttribute('data-editor-id') || element.id;
      const componentName = element.getAttribute('data-editor-name') || element.tagName;

      if (!componentId || this.trackedComponents.has(componentId)) return;

      const classes = this.extractClasses(element);
      const computedStyles = this.getComputedStyles(element);

      this.devTools.trackComponentStyle(componentId, componentName, classes, computedStyles);
      this.detectIssues(componentId, componentName, element, classes, computedStyles);

      this.trackedComponents.set(componentId, {
        componentId,
        componentName,
        classes,
        computedStyles,
        element: new WeakRef(element),
        timestamp: Date.now(),
      });
    });
  }

  /**
   * 특정 요소의 시각적 이상 감지
   */
  private detectIssues(
    componentId: string,
    componentName: string,
    element: HTMLElement,
    classes: string[],
    styles: Record<string, string>
  ): void {
    // 1. 보이지 않는 요소 감지
    if (styles.display === 'none') {
      this.addIssue({
        type: 'invisible-element',
        componentId,
        componentName,
        property: 'display',
        currentValue: 'none',
        severity: 'info',
        description: '요소가 display:none으로 숨겨져 있습니다',
        suggestion: '의도적인 숨김이 아니라면 조건부 렌더링(if)을 확인하세요',
      });
    }

    if (styles.visibility === 'hidden') {
      this.addIssue({
        type: 'invisible-element',
        componentId,
        componentName,
        property: 'visibility',
        currentValue: 'hidden',
        severity: 'info',
        description: '요소가 visibility:hidden으로 숨겨져 있습니다',
        suggestion: '공간은 유지되지만 보이지 않습니다. 의도적인지 확인하세요',
      });
    }

    if (styles.opacity === '0') {
      this.addIssue({
        type: 'invisible-element',
        componentId,
        componentName,
        property: 'opacity',
        currentValue: '0',
        severity: 'warning',
        description: '요소가 opacity:0으로 투명합니다',
        suggestion: '애니메이션 상태가 아니라면 스타일을 확인하세요',
      });
    }

    // 2. 크기가 0인 요소
    if (styles.width === '0px' || styles.height === '0px') {
      this.addIssue({
        type: 'invisible-element',
        componentId,
        componentName,
        property: styles.width === '0px' ? 'width' : 'height',
        currentValue: '0px',
        severity: 'warning',
        description: '요소의 크기가 0입니다',
        suggestion: '컨텐츠가 있는데 크기가 0이라면 레이아웃 문제일 수 있습니다',
      });
    }

    // 3. overflow:hidden으로 잘린 컨텐츠
    if (styles.overflow === 'hidden') {
      const hasOverflow = element.scrollWidth > element.clientWidth ||
                          element.scrollHeight > element.clientHeight;
      if (hasOverflow) {
        this.addIssue({
          type: 'overflow-hidden',
          componentId,
          componentName,
          property: 'overflow',
          currentValue: 'hidden',
          severity: 'warning',
          description: '컨텐츠가 overflow:hidden으로 잘리고 있습니다',
          suggestion: 'overflow-auto 또는 컨테이너 크기를 확인하세요',
        });
      }
    }

    // 4. 다크 모드 클래스 누락 감지
    const hasDarkVariant = classes.some(c => c.startsWith('dark:'));
    const hasLightOnlyBg = classes.some(c =>
      (c.startsWith('bg-white') || c.startsWith('bg-gray-')) && !c.startsWith('dark:')
    );

    if (hasLightOnlyBg && !hasDarkVariant) {
      this.addIssue({
        type: 'dark-mode-missing',
        componentId,
        componentName,
        property: 'className',
        currentValue: classes.filter(c => c.startsWith('bg-')).join(' '),
        severity: 'info',
        description: '다크 모드 변형 클래스가 누락되었을 수 있습니다',
        suggestion: 'bg-white 사용 시 dark:bg-gray-800 등을 함께 지정하세요',
      });
    }

    // 5. z-index 충돌 가능성
    const zIndex = parseInt(styles.zIndex, 10);
    if (!isNaN(zIndex) && zIndex > 50 && styles.position !== 'static') {
      this.addIssue({
        type: 'z-index-conflict',
        componentId,
        componentName,
        property: 'z-index',
        currentValue: styles.zIndex,
        severity: 'info',
        description: `높은 z-index(${zIndex})가 설정되어 있습니다`,
        suggestion: 'z-index 충돌을 피하려면 10, 20, 30, 40, 50 단위를 사용하세요',
      });
    }

    // 6. 반응형 클래스 누락 (모바일 전용 또는 데스크톱 전용)
    const hasResponsive = classes.some(c =>
      c.startsWith('sm:') || c.startsWith('md:') || c.startsWith('lg:') || c.startsWith('xl:')
    );
    const hasHiddenClass = classes.includes('hidden');
    const hasMobileOnly = classes.includes('portable:block') || classes.includes('portable:flex');
    const hasDesktopOnly = classes.includes('desktop:block') || classes.includes('desktop:flex');

    if (hasHiddenClass && !hasResponsive && !hasMobileOnly && !hasDesktopOnly) {
      // hidden 클래스만 있고 반응형 표시가 없으면
      this.addIssue({
        type: 'responsive-issue',
        componentId,
        componentName,
        property: 'className',
        currentValue: 'hidden',
        severity: 'info',
        description: '반응형 표시 클래스 없이 hidden이 설정되어 있습니다',
        suggestion: '조건에 따라 표시하려면 sm:block, desktop:flex 등을 추가하세요',
      });
    }
  }

  /**
   * 이슈 추가
   */
  private addIssue(issue: Omit<StyleIssue, 'id'>): void {
    this.devTools.addStyleIssue(issue);
  }

  /**
   * DOM 요소에서 CSS 클래스 추출
   */
  private extractClasses(element: HTMLElement): string[] {
    const className = element.getAttribute('class') || '';
    return className.split(/\s+/).filter(Boolean);
  }

  /**
   * computed styles 가져오기
   */
  private getComputedStyles(element: HTMLElement): Record<string, string> {
    const computed = window.getComputedStyle(element);
    const result: Record<string, string> = {};

    for (const prop of TRACKED_STYLE_PROPERTIES) {
      result[prop] = computed.getPropertyValue(
        prop.replace(/([A-Z])/g, '-$1').toLowerCase()
      );
    }

    return result;
  }

  /**
   * MutationObserver 설정
   */
  private setupMutationObserver(): void {
    if (typeof MutationObserver === 'undefined') return;

    this.mutationObserver = new MutationObserver((mutations) => {
      let shouldScan = false;

      for (const mutation of mutations) {
        if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
          shouldScan = true;
          break;
        }
        if (mutation.type === 'attributes' &&
            (mutation.attributeName === 'class' || mutation.attributeName === 'style')) {
          shouldScan = true;
          break;
        }
      }

      if (shouldScan) {
        // 디바운스
        setTimeout(() => this.scanVisibleComponents(), 100);
      }
    });

    this.mutationObserver.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'style'],
    });
  }

  /**
   * 수동 전체 스캔
   */
  fullScan(): StyleIssue[] {
    this.scanVisibleComponents();
    return this.devTools.getStyleValidationInfo().issues;
  }

  /**
   * 이슈 목록 조회
   */
  getIssues(): StyleIssue[] {
    return this.devTools.getStyleValidationInfo().issues;
  }

  /**
   * 특정 컴포넌트 이슈 조회
   */
  getIssuesForComponent(componentId: string): StyleIssue[] {
    return this.getIssues().filter(i => i.componentId === componentId);
  }

  /**
   * 데이터 초기화
   */
  clear(): void {
    this.trackedComponents.clear();
    this.devTools.clearStyleValidationData();
  }
}

/**
 * StyleTracker 싱글톤 인스턴스 반환
 */
export function getStyleTracker(): StyleTracker {
  return StyleTracker.getInstance();
}
