/**
 * scrollToSection 핸들러
 *
 * 특정 섹션으로 부드럽게 스크롤 이동합니다.
 * - Sticky 헤더를 고려한 offset 적용 지원
 * - React 조건부 렌더링 대응 (재시도 로직)
 * - 페이지/컨테이너 스크롤 자동 감지
 *
 * @param action 액션 정의 (params: targetId, offset, delay, scrollContainerId)
 * @param _context 액션 컨텍스트 (미사용)
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:ScrollToSection')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:ScrollToSection]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:ScrollToSection]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:ScrollToSection]', ...args),
};

export async function scrollToSectionHandler(
  action: any,
  _context?: any
): Promise<void> {
  const params = action.params || {};
  const {
    targetId,
    offset = 120,
    delay = 100,
    scrollContainerId,
  } = params as {
    targetId?: string;
    offset?: number;
    delay?: number;
    scrollContainerId?: string;
  };

  if (!targetId) {
    logger.warn('targetId is required');
    return;
  }

  // 1. 대상 요소 찾기 (React 렌더링 대기 + 재시도)
  const element = await findElementWithRetry(targetId, delay);
  if (!element) {
    logger.warn(`Element not found: ${targetId}`);
    return;
  }

  // 2. 스크롤 컨테이너 결정 (명시적 지정 or 자동 검색 or window)
  const scrollContainer = findScrollContainer(element, scrollContainerId);

  // 3. 스크롤 위치 계산 및 실행
  const offsetPosition = calculateScrollPosition(element, scrollContainer, offset);
  performScroll(scrollContainer, offsetPosition);
}

/**
 * 요소를 찾을 때까지 재시도
 * React 조건부 렌더링으로 요소가 늦게 생성될 수 있음
 */
async function findElementWithRetry(
  targetId: string,
  initialDelay: number
): Promise<HTMLElement | null> {
  await new Promise((resolve) => setTimeout(resolve, initialDelay));

  const maxRetries = 10;
  const retryInterval = 50;

  for (let i = 0; i < maxRetries; i++) {
    const element = document.getElementById(targetId);
    if (element) {
      // 요소 발견 후 추가 렌더링 대기
      await new Promise((resolve) => setTimeout(resolve, 50));
      return element;
    }
    await new Promise((resolve) => setTimeout(resolve, retryInterval));
  }

  return null;
}

/**
 * 실제 스크롤 컨테이너 찾기
 * 1. scrollContainerId 파라미터가 있으면 해당 요소 검증 후 사용
 * 2. scrollContainerId가 없거나 유효하지 않으면 부모 요소 중 스크롤 가능한 요소 검색
 * 3. 찾지 못하면 페이지 스크롤 사용 (window)
 */
function findScrollContainer(
  element: HTMLElement,
  scrollContainerId?: string
): HTMLElement | Window {
  // 1. 명시적으로 지정된 경우 - 검증 후 사용
  if (scrollContainerId) {
    const container = document.getElementById(scrollContainerId);
    if (container && isScrollable(container)) {
      return container;
    }
    logger.warn(
      `Container '${scrollContainerId}' ${container ? 'has no scroll' : 'not found'}, trying auto-detect`
    );
  }

  // 2. 부모 요소 중 스크롤 가능한 요소 검색 (최대 10단계)
  let currentElement = element.parentElement;
  let depth = 0;
  const maxDepth = 10;

  while (currentElement && currentElement !== document.body && depth < maxDepth) {
    if (isScrollable(currentElement)) {
      return currentElement;
    }
    currentElement = currentElement.parentElement;
    depth++;
  }

  // 3. 스크롤 컨테이너를 찾지 못했으면 window 사용
  return window;
}

/**
 * 요소가 스크롤 가능한지 확인
 */
function isScrollable(element: HTMLElement): boolean {
  const computedStyle = window.getComputedStyle(element);
  return (
    (computedStyle.overflowY === 'auto' || computedStyle.overflowY === 'scroll') &&
    element.scrollHeight > element.clientHeight
  );
}

/**
 * 스크롤 위치 계산
 */
function calculateScrollPosition(
  element: HTMLElement,
  scrollContainer: HTMLElement | Window,
  offset: number
): number {
  if (scrollContainer === window || !('getBoundingClientRect' in scrollContainer)) {
    // Window 스크롤
    const viewportTop = element.getBoundingClientRect().top;
    const documentTop = viewportTop + window.pageYOffset;
    return documentTop - offset;
  } else {
    // 컨테이너 내부 스크롤
    const containerRect = scrollContainer.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    return scrollContainer.scrollTop + (elementRect.top - containerRect.top) - offset;
  }
}

/**
 * 스크롤 실행
 */
function performScroll(
  scrollContainer: HTMLElement | Window,
  offsetPosition: number
): void {
  if (scrollContainer === window || !('scrollTo' in scrollContainer)) {
    window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
  } else {
    scrollContainer.scrollTo({ top: offsetPosition, behavior: 'smooth' });
  }
}