/**
 * G7 DevTools Tailwind 빌드 검증기
 *
 * 레이아웃 JSON에서 사용된 Tailwind 클래스와 빌드된 CSS를 비교하여
 * 퍼지되었을 수 있는 클래스를 감지합니다.
 *
 * @module TailwindValidator
 */

/**
 * 검증 결과
 */
export interface TailwindValidationResult {
  /** 검증 성공 여부 */
  valid: boolean;
  /** 총 사용된 클래스 수 */
  totalClasses: number;
  /** 유효한 클래스 수 */
  validClasses: number;
  /** 퍼지 가능성 있는 클래스 */
  potentiallyPurged: PurgedClassInfo[];
  /** 동적 클래스 (검증 스킵) */
  dynamicClasses: string[];
  /** 알 수 없는 클래스 */
  unknownClasses: string[];
  /** 검증 시간 */
  timestamp: number;
}

/**
 * 퍼지된 클래스 정보
 */
export interface PurgedClassInfo {
  /** 클래스명 */
  className: string;
  /** 사용된 위치 (파일 또는 컴포넌트) */
  usedIn: string[];
  /** 퍼지 가능성 이유 */
  reason: 'not-in-css' | 'dynamic-value' | 'custom-class';
  /** 권장 사항 */
  suggestion?: string;
}

/**
 * Tailwind 클래스 패턴
 */
const TAILWIND_PATTERNS = {
  // 레이아웃
  layout: /^(flex|grid|block|inline|hidden|container|box-|float-|clear-|object-|overflow-|overscroll-|z-)/,
  // 간격
  spacing: /^(p[xytblr]?-|m[xytblr]?-|space-[xy]-|gap-)/,
  // 크기
  sizing: /^(w-|h-|min-w-|min-h-|max-w-|max-h-|size-)/,
  // 타이포그래피
  typography: /^(text-|font-|tracking-|leading-|list-|placeholder-|truncate|break-|whitespace-|align-)/,
  // 배경
  background: /^(bg-|from-|via-|to-|gradient-)/,
  // 테두리
  border: /^(border|rounded|ring|outline|divide|shadow)/,
  // 효과
  effects: /^(opacity-|mix-|backdrop-|blur-|brightness-|contrast-|grayscale|hue-|invert|saturate|sepia|drop-)/,
  // 필터
  filters: /^(filter|blur|brightness|contrast)/,
  // 테이블
  table: /^(table|border-collapse|border-spacing)/,
  // 트랜지션
  transition: /^(transition|duration-|ease-|delay-|animate-)/,
  // 변환
  transform: /^(transform|scale-|rotate-|translate-|skew-|origin-)/,
  // 상호작용
  interactivity: /^(cursor-|pointer-|resize|select-|scroll-|snap-|touch-|will-)/,
  // 접근성
  accessibility: /^(sr-only|not-sr-only)/,
  // 색상
  colors: /^(text-|bg-|border-|ring-|outline-|fill-|stroke-)(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)/,
};

/**
 * 변형 접두사
 */
const VARIANTS = [
  'hover',
  'focus',
  'active',
  'visited',
  'disabled',
  'first',
  'last',
  'odd',
  'even',
  'group-hover',
  'peer-hover',
  'dark',
  'sm',
  'md',
  'lg',
  'xl',
  '2xl',
  'portable',
  'desktop',
  'print',
  'motion-safe',
  'motion-reduce',
];

/**
 * Tailwind 빌드 검증기
 */
export class TailwindValidator {
  private static instance: TailwindValidator | null = null;
  private loadedCssRules: Set<string> = new Set();
  private cssLoaded: boolean = false;

  private constructor() {
    // 싱글톤
  }

  /**
   * 싱글톤 인스턴스 반환
   */
  static getInstance(): TailwindValidator {
    if (!TailwindValidator.instance) {
      TailwindValidator.instance = new TailwindValidator();
    }
    return TailwindValidator.instance;
  }

  /**
   * 빌드된 CSS에서 클래스 목록 로드
   */
  async loadCssClasses(): Promise<void> {
    if (this.cssLoaded) return;

    this.loadedCssRules.clear();

    // 모든 스타일시트에서 클래스 추출
    try {
      const styleSheets = document.styleSheets;

      for (const sheet of styleSheets) {
        try {
          // CORS 제한된 스타일시트는 스킵
          if (!sheet.cssRules) continue;

          for (const rule of sheet.cssRules) {
            if (rule instanceof CSSStyleRule) {
              // 셀렉터에서 클래스 추출
              const classes = this.extractClassesFromSelector(rule.selectorText);
              classes.forEach(c => this.loadedCssRules.add(c));
            }
          }
        } catch {
          // 접근 불가 스타일시트 스킵
        }
      }

      this.cssLoaded = true;
      console.log(`[TailwindValidator] ${this.loadedCssRules.size}개 CSS 클래스 로드됨`);
    } catch (error) {
      console.error('[TailwindValidator] CSS 로드 실패:', error);
    }
  }

  /**
   * CSS 셀렉터에서 클래스명 추출
   */
  private extractClassesFromSelector(selector: string): string[] {
    const classRegex = /\.([a-zA-Z_-][a-zA-Z0-9_-]*)/g;
    const classes: string[] = [];
    let match;

    while ((match = classRegex.exec(selector)) !== null) {
      // 이스케이프된 문자 처리 (예: hover\:bg-blue-500)
      const className = match[1].replace(/\\/g, '');
      classes.push(className);
    }

    return classes;
  }

  /**
   * 레이아웃 JSON에서 클래스 추출
   */
  extractClassesFromLayout(layout: any): string[] {
    const classes = new Set<string>();

    const traverse = (node: any) => {
      if (!node) return;

      // props.className에서 추출
      if (node.props?.className) {
        const classNames = this.parseClassName(node.props.className);
        classNames.forEach(c => classes.add(c));
      }

      // props.class에서 추출 (일부 컴포넌트)
      if (node.props?.class) {
        const classNames = this.parseClassName(node.props.class);
        classNames.forEach(c => classes.add(c));
      }

      // children 순회
      if (Array.isArray(node.children)) {
        node.children.forEach(traverse);
      }

      // slots 순회
      if (node.slots) {
        Object.values(node.slots).forEach((slot: any) => {
          if (Array.isArray(slot)) {
            slot.forEach(traverse);
          }
        });
      }
    };

    traverse(layout);
    return Array.from(classes);
  }

  /**
   * className 문자열에서 개별 클래스 파싱
   */
  private parseClassName(className: string): string[] {
    if (!className) return [];

    // 바인딩 표현식 제거 ({{...}})
    const withoutBindings = className.replace(/\{\{[^}]+\}\}/g, '');

    // 공백으로 분리
    return withoutBindings
      .split(/\s+/)
      .filter(Boolean)
      .filter(c => !c.includes('{{') && !c.includes('}}'));
  }

  /**
   * 클래스가 Tailwind 패턴인지 확인
   */
  isTailwindClass(className: string): boolean {
    // 변형 접두사 제거
    let baseClass = className;
    for (const variant of VARIANTS) {
      if (className.startsWith(`${variant}:`)) {
        baseClass = className.slice(variant.length + 1);
        break;
      }
    }

    // 패턴 매칭
    for (const pattern of Object.values(TAILWIND_PATTERNS)) {
      if (pattern.test(baseClass)) {
        return true;
      }
    }

    // 일반적인 Tailwind 유틸리티 패턴
    if (/^-?[a-z]+-[a-z0-9/[\]]+$/.test(baseClass)) {
      return true;
    }

    return false;
  }

  /**
   * 동적 값이 포함된 클래스인지 확인
   */
  isDynamicClass(className: string): boolean {
    // 바인딩 표현식
    if (className.includes('{{') || className.includes('}}')) {
      return true;
    }

    // 대괄호 표기법 (arbitrary values)
    if (className.includes('[') && className.includes(']')) {
      return true;
    }

    return false;
  }

  /**
   * 클래스 검증
   */
  async validateClasses(classes: string[]): Promise<TailwindValidationResult> {
    await this.loadCssClasses();

    const potentiallyPurged: PurgedClassInfo[] = [];
    const dynamicClasses: string[] = [];
    const unknownClasses: string[] = [];
    let validCount = 0;

    for (const className of classes) {
      // 동적 클래스는 스킵
      if (this.isDynamicClass(className)) {
        dynamicClasses.push(className);
        continue;
      }

      // CSS에 존재하는지 확인
      if (this.loadedCssRules.has(className)) {
        validCount++;
        continue;
      }

      // Tailwind 패턴인지 확인
      if (this.isTailwindClass(className)) {
        // Tailwind 패턴이지만 CSS에 없음 = 퍼지됨
        potentiallyPurged.push({
          className,
          usedIn: [],
          reason: 'not-in-css',
          suggestion: `safelist에 추가하거나 정적 클래스로 사용하세요: '${className}'`,
        });
      } else {
        // Tailwind 패턴이 아님 = 커스텀 클래스
        unknownClasses.push(className);
      }
    }

    return {
      valid: potentiallyPurged.length === 0,
      totalClasses: classes.length,
      validClasses: validCount,
      potentiallyPurged,
      dynamicClasses,
      unknownClasses,
      timestamp: Date.now(),
    };
  }

  /**
   * DOM에서 사용 중인 클래스 검증
   */
  async validateDom(): Promise<TailwindValidationResult> {
    const usedClasses = new Set<string>();

    // 모든 요소의 클래스 수집
    document.querySelectorAll('[class]').forEach(el => {
      const className = el.getAttribute('class') || '';
      className.split(/\s+/).filter(Boolean).forEach(c => usedClasses.add(c));
    });

    return this.validateClasses(Array.from(usedClasses));
  }

  /**
   * 빠른 검증 (주요 클래스만)
   */
  quickValidate(classes: string[]): { purged: string[]; valid: string[] } {
    const purged: string[] = [];
    const valid: string[] = [];

    for (const className of classes) {
      if (this.isDynamicClass(className)) continue;

      if (this.loadedCssRules.has(className)) {
        valid.push(className);
      } else if (this.isTailwindClass(className)) {
        purged.push(className);
      }
    }

    return { purged, valid };
  }

  /**
   * CSS 클래스 캐시 초기화
   */
  clearCache(): void {
    this.loadedCssRules.clear();
    this.cssLoaded = false;
  }

  /**
   * 로드된 CSS 클래스 수 반환
   */
  getLoadedClassCount(): number {
    return this.loadedCssRules.size;
  }
}

/**
 * TailwindValidator 싱글톤 인스턴스 반환
 */
export function getTailwindValidator(): TailwindValidator {
  return TailwindValidator.getInstance();
}
