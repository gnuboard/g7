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
 * override 클래스가 있으면 같은 그룹의 base 클래스는 제거됩니다.
 *
 * 그룹은 Tailwind 의 실제 충돌 단위다 (@since engine-v1.83.0) — 축·변이 다른 유틸리티(`p`·`px`·`pt`, `top`·`left`,
 * `border`·`border-t`, `overflow`·`overflow-x` …)는 서로 다른 그룹이다. 종전에는 한 그룹으로 묶어 `px-2` 가 기본 `p-4` 를
 * 통째로 지우는 등 지우면 안 되는 기본 클래스를 지웠다. 상위 유틸리티가 하위 축을 덮는 관계는 `CONFLICTING_CLASS_GROUPS` 다.
 * 첫 일치 그룹을 쓰므로 좁은 패턴을 넓은 패턴보다 앞에 둔다.
 */
const TAILWIND_CLASS_GROUPS: Record<string, RegExp> = {
  // Flexbox & Grid
  justify: /^justify-(start|end|center|between|around|evenly)$/,
  items: /^items-(start|end|center|baseline|stretch)$/,
  content: /^content-(start|end|center|between|around|evenly|stretch)$/,
  self: /^self-(auto|start|end|center|stretch|baseline)$/,
  flexDirection: /^flex-(row|row-reverse|col|col-reverse)$/,
  flexWrap: /^flex-(wrap|wrap-reverse|nowrap)$/,
  flex: /^flex-(1|auto|initial|none)$/,
  grow: /^(grow|grow-0)$/,
  shrink: /^(shrink|shrink-0)$/,
  basis: /^basis-/,
  order: /^-?order-/,
  gapX: /^gap-x-/,
  gapY: /^gap-y-/,
  gap: /^gap-/,
  gridCols: /^grid-cols-/,
  gridRows: /^grid-rows-/,
  colSpan: /^col-(span-|start-|end-)/,
  rowSpan: /^row-(span-|start-|end-)/,

  // Display
  display: /^(block|inline-block|inline|flex|inline-flex|table|inline-table|table-caption|table-cell|table-column|table-column-group|table-footer-group|table-header-group|table-row-group|table-row|flow-root|grid|inline-grid|contents|list-item|hidden)$/,

  // Position
  position: /^(static|fixed|absolute|relative|sticky)$/,
  insetX: /^-?inset-x-/,
  insetY: /^-?inset-y-/,
  inset: /^-?inset-/,
  top: /^-?top-/,
  right: /^-?right-/,
  bottom: /^-?bottom-/,
  left: /^-?left-/,
  zIndex: /^z-/,

  // Sizing
  width: /^w-/,
  minWidth: /^min-w-/,
  maxWidth: /^max-w-/,
  height: /^h-/,
  minHeight: /^min-h-/,
  maxHeight: /^max-h-/,

  // Spacing
  padding: /^p-/,
  paddingX: /^px-/,
  paddingY: /^py-/,
  paddingS: /^ps-/,
  paddingE: /^pe-/,
  paddingT: /^pt-/,
  paddingR: /^pr-/,
  paddingB: /^pb-/,
  paddingL: /^pl-/,
  margin: /^-?m-/,
  marginX: /^-?mx-/,
  marginY: /^-?my-/,
  marginS: /^-?ms-/,
  marginE: /^-?me-/,
  marginT: /^-?mt-/,
  marginR: /^-?mr-/,
  marginB: /^-?mb-/,
  marginL: /^-?ml-/,
  spaceXReverse: /^space-x-reverse$/,
  spaceYReverse: /^space-y-reverse$/,
  spaceX: /^-?space-x-/,
  spaceY: /^-?space-y-/,

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
  // break-after·before·inside 는 아래 Layout 의 별도 그룹이다 — word-break 값만 (@since engine-v1.83.0)
  wordBreak: /^break-(normal|words|all|keep)$/,

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
  borderWidth: /^border(-0|-2|-4|-8)?$/,
  borderWidthX: /^border-x(-0|-2|-4|-8)?$/,
  borderWidthY: /^border-y(-0|-2|-4|-8)?$/,
  borderWidthS: /^border-s(-0|-2|-4|-8)?$/,
  borderWidthE: /^border-e(-0|-2|-4|-8)?$/,
  borderWidthT: /^border-t(-0|-2|-4|-8)?$/,
  borderWidthR: /^border-r(-0|-2|-4|-8)?$/,
  borderWidthB: /^border-b(-0|-2|-4|-8)?$/,
  borderWidthL: /^border-l(-0|-2|-4|-8)?$/,
  borderColor: /^border-(inherit|current|transparent|black|white|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-/,
  borderStyle: /^border-(solid|dashed|dotted|double|hidden|none)$/,
  borderRadius: /^rounded(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusS: /^rounded-s(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusE: /^rounded-e(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusT: /^rounded-t(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusR: /^rounded-r(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusB: /^rounded-b(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusL: /^rounded-l(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusSS: /^rounded-ss(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusSE: /^rounded-se(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusEE: /^rounded-ee(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusES: /^rounded-es(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusTL: /^rounded-tl(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusTR: /^rounded-tr(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusBR: /^rounded-br(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
  borderRadiusBL: /^rounded-bl(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-full)?$/,
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
  scaleX: /^-?scale-x-/,
  scaleY: /^-?scale-y-/,
  scale: /^-?scale-/,
  rotate: /^-?rotate-/,
  translateX: /^-?translate-x-/,
  translateY: /^-?translate-y-/,
  skewX: /^-?skew-x-/,
  skewY: /^-?skew-y-/,
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
  overflow: /^overflow-(auto|hidden|clip|visible|scroll)$/,
  overflowX: /^overflow-x-(auto|hidden|clip|visible|scroll)$/,
  overflowY: /^overflow-y-(auto|hidden|clip|visible|scroll)$/,
  overscroll: /^overscroll-(auto|contain|none)$/,
  overscrollX: /^overscroll-x-(auto|contain|none)$/,
  overscrollY: /^overscroll-y-(auto|contain|none)$/,
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
 * 상위 그룹 → 그 그룹이 함께 덮는 하위 그룹 (@since engine-v1.83.0)
 *
 * override 클래스가 상위 그룹이면 같은 변이의 하위 그룹 base 클래스도 지운다(`p-0` 은 `px-4`·`pt-2` 를 덮는다). 반대 방향은
 * 지우지 않는다 — `px-2` 와 `p-4` 는 함께 있어야 세로 여백이 남는다(Tailwind 는 축 유틸리티를 전체 유틸리티 뒤에 둔다).
 * tailwind-merge 의 conflictingClassGroups 와 같은 규칙이다.
 */
const CONFLICTING_CLASS_GROUPS: Record<string, string[]> = {
  padding: ['paddingX', 'paddingY', 'paddingS', 'paddingE', 'paddingT', 'paddingR', 'paddingB', 'paddingL'],
  paddingX: ['paddingR', 'paddingL'],
  paddingY: ['paddingT', 'paddingB'],
  margin: ['marginX', 'marginY', 'marginS', 'marginE', 'marginT', 'marginR', 'marginB', 'marginL'],
  marginX: ['marginR', 'marginL'],
  marginY: ['marginT', 'marginB'],
  inset: ['insetX', 'insetY', 'top', 'right', 'bottom', 'left'],
  insetX: ['right', 'left'],
  insetY: ['top', 'bottom'],
  gap: ['gapX', 'gapY'],
  borderWidth: ['borderWidthX', 'borderWidthY', 'borderWidthS', 'borderWidthE', 'borderWidthT', 'borderWidthR', 'borderWidthB', 'borderWidthL'],
  borderWidthX: ['borderWidthR', 'borderWidthL'],
  borderWidthY: ['borderWidthT', 'borderWidthB'],
  borderRadius: [
    'borderRadiusS', 'borderRadiusE', 'borderRadiusT', 'borderRadiusR', 'borderRadiusB', 'borderRadiusL',
    'borderRadiusSS', 'borderRadiusSE', 'borderRadiusEE', 'borderRadiusES',
    'borderRadiusTL', 'borderRadiusTR', 'borderRadiusBR', 'borderRadiusBL',
  ],
  borderRadiusS: ['borderRadiusSS', 'borderRadiusES'],
  borderRadiusE: ['borderRadiusSE', 'borderRadiusEE'],
  borderRadiusT: ['borderRadiusTL', 'borderRadiusTR'],
  borderRadiusR: ['borderRadiusTR', 'borderRadiusBR'],
  borderRadiusB: ['borderRadiusBR', 'borderRadiusBL'],
  borderRadiusL: ['borderRadiusTL', 'borderRadiusBL'],
  overflow: ['overflowX', 'overflowY'],
  overscroll: ['overscrollX', 'overscrollY'],
  scale: ['scaleX', 'scaleY'],
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

  // override 클래스들의 그룹+variant 조합을 수집 — 상위 그룹이면 덮는 하위 그룹도 함께 (@since engine-v1.83.0)
  const overrideGroupVariants = new Map<string, string>();
  for (const cls of overrideArr) {
    const group = findClassGroup(cls);
    if (group) {
      const variant = extractVariant(cls);
      overrideGroupVariants.set(`${group}:${variant}`, cls);
      for (const covered of CONFLICTING_CLASS_GROUPS[group] ?? []) {
        overrideGroupVariants.set(`${covered}:${variant}`, cls);
      }
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
