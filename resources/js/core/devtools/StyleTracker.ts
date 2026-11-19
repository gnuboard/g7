/**
 * G7 DevTools 스타일 추적기
 *
 * 렌더링된 컴포넌트의 DOM 요소를 추적하고 computed styles를 수집합니다.
 * 시각적 이상(보이지 않는 요소, z-index 충돌 등)을 자동으로 감지합니다.
 *
 * @module StyleTracker
 */

/// <reference lib="es2021.weakref" />
// WeakRef 는 ES2021 표준이라 코어 lib(ES2020)에 없다. DevTools 를 켠 개발 환경에서만 이 추적기가 돌므로
// 이 파일에서만 WeakRef 타입을 싣는다(#869).
import { G7DevToolsCore } from './G7DevToolsCore';
import type { ComponentStyleInfo, StyleIssue } from './types';
import { breakpointVariantOf } from './TailwindValidator';

/**
 * 컴포넌트 스타일 정보
 */
interface ComponentStyleData {
  componentId: string;
  componentName: string;
  classes: string[];
  computedStyles: ComponentStyleInfo['computedStyles'];
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
  /** 레이아웃 JSON → (id → 컴포넌트 정의) — 레이아웃이 바뀌면 새로 만든다 */
  private definitionIndex: WeakMap<object, Map<string, Record<string, unknown>>> = new WeakMap();

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

    // 6. 폭 조건 없는 hidden — 폭 조건은 엔진 responsive 덮어쓰기로만 둔다.
    // 엔진은 지금 폭의 className 만 DOM 에 남기고 표식을 달지 않으므로, 폭 조건 유무는 노드 정의(responsive 키)로 본다.
    // 정의를 찾을 수 없으면(레이아웃 정보 없음, 반복 사본 id, 레이아웃 밖 요소) 판정하지 않는다 — 엔진 responsive 로 숨긴 요소를
    // 「폭 조건 없음」으로 오판하지 않게. Tailwind 폭 접두가 있으면 그 경고는 TailwindValidator 가 낸다.
    // `portable:` `desktop:` 은 이 프로젝트에 정의된 변형이 아니라 폭 조건으로 치지 않는다.
    if (classes.includes('hidden') && !classes.some((c) => {
      const variant = breakpointVariantOf(c);
      return variant !== null && variant !== 'portable' && variant !== 'desktop';
    })) {
      const definition = this.findDefinition(componentId);
      if (definition && !this.hasResponsiveOverride(definition)) {
        this.addIssue({
          type: 'responsive-issue',
          componentId,
          componentName,
          property: 'className',
          currentValue: 'hidden',
          severity: 'info',
          description: '폭 조건 없이 hidden이 설정되어 있습니다',
          // Tailwind 폭 접두(sm: md: lg: …)는 금지 규정이다 — 폭 판정은 엔진 responsive 하나로 모은다(docs/frontend/responsive-layout.md 2절)
          suggestion: '폭에 따라 표시하려면 엔진 responsive(<responsive on="desktop" class="…"/> 등)로 그 구간의 className 을 통째로 바꾸세요. Tailwind 폭 접두는 쓰지 않습니다',
        });
      }
    }
  }

  /**
   * 지금 레이아웃에서 컴포넌트 정의를 id 로 찾습니다.
   *
   * @param componentId 컴포넌트 id
   * @returns 정의 (레이아웃 정보가 없거나 찾지 못하면 null)
   */
  private findDefinition(componentId: string): Record<string, unknown> | null {
    const layoutJson = (this.devTools as { getCurrentLayout?: () => { layoutJson?: unknown } | null }).getCurrentLayout?.()?.layoutJson;
    if (!layoutJson || typeof layoutJson !== 'object') return null;

    let index = this.definitionIndex.get(layoutJson as object);
    if (!index) {
      index = new Map();
      const seen = new Set<unknown>();
      const walk = (node: unknown): void => {
        if (!node || typeof node !== 'object' || seen.has(node)) return;
        seen.add(node);
        if (Array.isArray(node)) {
          node.forEach(walk);
          return;
        }
        const record = node as Record<string, unknown>;
        if (typeof record.id === 'string' && (typeof record.name === 'string' || typeof record.type === 'string') && !index!.has(record.id)) {
          index!.set(record.id, record);
        }
        Object.values(record).forEach(walk);
      };
      walk(layoutJson);
      this.definitionIndex.set(layoutJson as object, index);
    }

    return index.get(componentId) ?? null;
  }

  /**
   * 정의가 엔진 responsive 덮어쓰기(`responsive` 키, 마크업 `<responsive on>`)를 갖는지.
   *
   * @param definition 컴포넌트 정의
   * @returns 구간 덮어쓰기가 하나라도 있으면 true
   */
  private hasResponsiveOverride(definition: Record<string, unknown>): boolean {
    const responsive = definition.responsive;
    return !!responsive && typeof responsive === 'object' && Object.keys(responsive as object).length > 0;
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
  private getComputedStyles(element: HTMLElement): ComponentStyleInfo['computedStyles'] {
    const computed = window.getComputedStyle(element);
    // camelCase 속성명을 CSS 속성명(kebab-case)으로 바꿔 읽는다
    const read = (prop: keyof ComponentStyleInfo['computedStyles']): string =>
      computed.getPropertyValue(prop.replace(/([A-Z])/g, '-$1').toLowerCase());

    // 추적 속성 목록은 ComponentStyleInfo['computedStyles'] 키와 같다(빠지면 타입 검사가 잡는다)
    return {
      display: read('display'),
      visibility: read('visibility'),
      opacity: read('opacity'),
      position: read('position'),
      zIndex: read('zIndex'),
      overflow: read('overflow'),
      width: read('width'),
      height: read('height'),
      backgroundColor: read('backgroundColor'),
      color: read('color'),
    };
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
