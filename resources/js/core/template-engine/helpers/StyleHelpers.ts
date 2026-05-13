/**
 * 스타일 관련 헬퍼 함수 모듈
 *
 * Tailwind CSS 클래스 병합, 조건부 클래스 적용 등
 * 스타일 관련 유틸리티 함수들을 제공합니다.
 *
 * @packageDocumentation
 */

/**
 * Tailwind CSS 속성 그룹 정의
 *
 * 같은 CSS 속성을 제어하는 클래스들을 그룹화합니다.
 * override 클래스가 있으면 base 클래스는 제거됩니다.
 */
const TAILWIND_CLASS_GROUPS: Record<string, RegExp> = {
  // Flexbox & Grid
  justify: /^justify-(start|end|center|between|around|evenly)$/,
  items: /^items-(start|end|center|baseline|stretch)$/,
  content: /^content-(start|end|center|between|around|evenly|stretch)$/,
  self: /^self-(auto|start|end|center|stretch|baseline)$/,
  flex: /^flex-(row|row-reverse|col|col-reverse|wrap|wrap-reverse|nowrap|1|auto|initial|none)$/,
  grow: /^(grow|grow-0)$/,
  shrink: /^(shrink|shrink-0)$/,
  basis: /^basis-/,
  order: /^(order-|-)order-/,
  gap: /^gap(-x|-y)?-/,
  gridCols: /^grid-cols-/,
  gridRows: /^grid-rows-/,
  colSpan: /^col-(span-|start-|end-)/,
  rowSpan: /^row-(span-|start-|end-)/,

  // Display
  display: /^(block|inline-block|inline|flex|inline-flex|table|inline-table|table-caption|table-cell|table-column|table-column-group|table-footer-group|table-header-group|table-row-group|table-row|flow-root|grid|inline-grid|contents|list-item|hidden)$/,

  // Position
  position: /^(static|fixed|absolute|relative|sticky)$/,
  inset: /^(inset|top|right|bottom|left)-/,
  zIndex: /^z-/,

  // Sizing
  width: /^w-/,
  minWidth: /^min-w-/,
  maxWidth: /^max-w-/,
  height: /^h-/,
  minHeight: /^min-h-/,
  maxHeight: /^max-h-/,

  // Spacing
  padding: /^p[xytblr]?-/,
  margin: /^-?m[xytblr]?-/,
  space: /^space-(x|y)-/,

  // Typography
  fontSize: /^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|8xl|9xl)$/,
  fontWeight: /^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$/,
  fontStyle: /^(italic|not-italic)$/,
  fontFamily: /^font-(sans|serif|mono)/,
  textAlign: /^text-(left|center|right|justify|start|end)$/,
  textColor: /^text-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-/,
  textDecoration: /^(underline|overline|line-through|no-underline)$/,
  textTransform: /^(uppercase|lowercase|capitalize|normal-case)$/,
  lineHeight: /^leading-/,
  letterSpacing: /^tracking-/,
  textOverflow: /^(truncate|text-ellipsis|text-clip)$/,
  whitespace: /^whitespace-/,
  wordBreak: /^break-/,

  // Background
  bgColor: /^bg-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-/,
  bgGradient: /^bg-gradient-/,
  bgSize: /^bg-(auto|cover|contain)$/,
  bgPosition: /^bg-(bottom|center|left|left-bottom|left-top|right|right-bottom|right-top|top)$/,
  bgRepeat: /^bg-(repeat|no-repeat|repeat-x|repeat-y|repeat-round|repeat-space)$/,
  bgAttachment: /^bg-(fixed|local|scroll)$/,
  bgClip: /^bg-clip-/,
  bgOrigin: /^bg-origin-/,

  // Border
  borderWidth: /^border(-[xytblr])?(-0|-2|-4|-8)?$/,
  borderColor: /^border-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-/,
  borderStyle: /^border-(solid|dashed|dotted|double|hidden|none)$/,
  borderRadius: /^rounded(-[tblrse]{1,2})?(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  ringWidth: /^ring(-0|-1|-2|-4|-8|-inset)?$/,
  ringColor: /^ring-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-/,
  ringOffset: /^ring-offset-/,

  // Effects
  shadow: /^shadow(-sm|-md|-lg|-xl|-2xl|-inner|-none)?$/,
  opacity: /^opacity-/,
  mixBlend: /^mix-blend-/,
  bgBlend: /^bg-blend-/,

  // Filters
  blur: /^blur(-none|-sm|-md|-lg|-xl|-2xl|-3xl)?$/,
  brightness: /^brightness-/,
  contrast: /^contrast-/,
  grayscale: /^grayscale(-0)?$/,
  hueRotate: /^-?hue-rotate-/,
  invert: /^invert(-0)?$/,
  saturate: /^saturate-/,
  sepia: /^sepia(-0)?$/,
  backdropBlur: /^backdrop-blur-/,
  backdropBrightness: /^backdrop-brightness-/,
  backdropContrast: /^backdrop-contrast-/,
  backdropGrayscale: /^backdrop-grayscale-/,
  backdropHueRotate: /^backdrop-hue-rotate-/,
  backdropInvert: /^backdrop-invert-/,
  backdropOpacity: /^backdrop-opacity-/,
  backdropSaturate: /^backdrop-saturate-/,
  backdropSepia: /^backdrop-sepia-/,

  // Transforms
  scale: /^scale(-x|-y)?-/,
  rotate: /^-?rotate-/,
  translate: /^-?translate-[xy]-/,
  skew: /^-?skew-[xy]-/,
  transformOrigin: /^origin-/,

  // Transitions & Animation
  transition: /^transition(-none|-all|-colors|-opacity|-shadow|-transform)?$/,
  duration: /^duration-/,
  ease: /^ease-(linear|in|out|in-out)$/,
  delay: /^delay-/,
  animate: /^animate-/,

  // Interactivity
  cursor: /^cursor-/,
  userSelect: /^select-/,
  pointerEvents: /^pointer-events-/,
  resize: /^resize(-none|-x|-y)?$/,
  scrollBehavior: /^scroll-(auto|smooth)$/,
  touchAction: /^touch-/,

  // Layout
  overflow: /^overflow(-x|-y)?-(auto|hidden|clip|visible|scroll)$/,
  overscroll: /^overscroll(-x|-y)?-(auto|contain|none)$/,
  visibility: /^(visible|invisible|collapse)$/,
  aspectRatio: /^aspect-/,
  columns: /^columns-/,
  breakAfter: /^break-after-/,
  breakBefore: /^break-before-/,
  breakInside: /^break-inside-/,
  boxDecorationBreak: /^box-decoration-/,
  boxSizing: /^box-(border|content)$/,
  float: /^float-(right|left|none)$/,
  clear: /^clear-(left|right|both|none)$/,
  isolation: /^(isolate|isolation-auto)$/,
  objectFit: /^object-(contain|cover|fill|none|scale-down)$/,
  objectPosition: /^object-/,
};

/**
 * 클래스가 속한 그룹을 찾습니다.
 *
 * @param className - 검사할 클래스명
 * @returns 그룹명 또는 null
 */
function findClassGroup(className: string): string | null {
  // dark: 등의 variant prefix 제거
  const baseClass = className.replace(/^(dark:|hover:|focus:|active:|disabled:|group-hover:|sm:|md:|lg:|xl:|2xl:)+/, '');

  for (const [groupName, pattern] of Object.entries(TAILWIND_CLASS_GROUPS)) {
    if (pattern.test(baseClass)) {
      return groupName;
    }
  }
  return null;
}

/**
 * variant prefix를 추출합니다 (dark:, hover:, sm: 등)
 *
 * @param className - 클래스명
 * @returns variant prefix 또는 빈 문자열
 */
function extractVariant(className: string): string {
  const match = className.match(/^((dark:|hover:|focus:|active:|disabled:|group-hover:|sm:|md:|lg:|xl:|2xl:)+)/);
  return match ? match[1] : '';
}

/**
 * Tailwind CSS 클래스를 런타임에서 병합합니다.
 *
 * 같은 CSS 속성을 제어하는 클래스가 충돌하면 override 클래스를 우선 적용합니다.
 * variant prefix (dark:, hover:, sm: 등)가 같은 경우에만 충돌로 처리합니다.
 *
 * @param baseClasses - 기본 클래스 문자열
 * @param overrideClasses - 오버라이드할 클래스 문자열
 * @returns 병합된 클래스 문자열
 *
 * @example
 * ```typescript
 * // 기본 사용
 * mergeClasses('justify-center items-center', 'justify-between')
 * // 결과: 'items-center justify-between'
 *
 * // variant가 다르면 충돌하지 않음
 * mergeClasses('text-gray-900 dark:text-white', 'text-blue-500')
 * // 결과: 'dark:text-white text-blue-500'
 *
 * // 같은 variant는 충돌
 * mergeClasses('dark:text-white dark:bg-gray-800', 'dark:text-gray-100')
 * // 결과: 'dark:bg-gray-800 dark:text-gray-100'
 * ```
 */
export function mergeClasses(baseClasses: string, overrideClasses?: string): string {
  if (!overrideClasses || overrideClasses.trim() === '') {
    return baseClasses;
  }

  if (!baseClasses || baseClasses.trim() === '') {
    return overrideClasses;
  }

  const baseArr = baseClasses.split(/\s+/).filter(Boolean);
  const overrideArr = overrideClasses.split(/\s+/).filter(Boolean);

  // override 클래스들의 그룹+variant 조합을 수집
  const overrideGroupVariants = new Map<string, string>();
  for (const cls of overrideArr) {
    const group = findClassGroup(cls);
    if (group) {
      const variant = extractVariant(cls);
      const key = `${group}:${variant}`;
      overrideGroupVariants.set(key, cls);
    }
  }

  // base 클래스 중 override와 충돌하지 않는 것만 유지
  const filteredBase = baseArr.filter((cls) => {
    const group = findClassGroup(cls);
    if (!group) return true; // 그룹을 못 찾으면 유지

    const variant = extractVariant(cls);
    const key = `${group}:${variant}`;

    // override에 같은 그룹+variant 조합이 있으면 제거
    return !overrideGroupVariants.has(key);
  });

  return [...filteredBase, ...overrideArr].join(' ');
}

/**
 * 조건에 따라 클래스를 적용합니다.
 *
 * @param conditions - 조건과 클래스의 맵
 * @returns 조건이 truthy인 클래스들을 합친 문자열
 *
 * @example
 * ```typescript
 * conditionalClass({
 *   'bg-blue-500': isPrimary,
 *   'bg-gray-500': !isPrimary,
 *   'opacity-50': isDisabled,
 * })
 * // isPrimary=true, isDisabled=true인 경우: 'bg-blue-500 opacity-50'
 * ```
 */
export function conditionalClass(conditions: Record<string, boolean | undefined | null>): string {
  return Object.entries(conditions)
    .filter(([, condition]) => condition)
    .map(([className]) => className)
    .join(' ');
}

/**
 * 여러 클래스 문자열을 하나로 합칩니다.
 *
 * falsy 값(null, undefined, '', false)은 무시됩니다.
 *
 * @param classes - 합칠 클래스 문자열들
 * @returns 합쳐진 클래스 문자열
 *
 * @example
 * ```typescript
 * joinClasses('flex', isActive && 'bg-blue-500', 'p-4')
 * // isActive=true: 'flex bg-blue-500 p-4'
 * // isActive=false: 'flex p-4'
 * ```
 */
export function joinClasses(...classes: (string | boolean | null | undefined)[]): string {
  return classes.filter((cls): cls is string => typeof cls === 'string' && cls.length > 0).join(' ');
}
