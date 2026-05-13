/**
 * setTheme 핸들러
 *
 * 사용자가 선택한 테마(auto/light/dark)로 변경합니다.
 * localStorage에 g7_color_scheme 값을 저장하고 즉시 적용합니다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:SetTheme')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:SetTheme]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:SetTheme]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:SetTheme]', ...args),
};

/**
 * 테마 모드 타입
 */
export type ThemeMode = 'auto' | 'light' | 'dark';

/**
 * 유효한 테마 목록
 */
const VALID_THEMES: ThemeMode[] = ['auto', 'light', 'dark'];

/**
 * localStorage 키
 */
const STORAGE_KEY = 'g7_color_scheme';

/**
 * 실제 적용될 테마 결정 (auto인 경우 시스템 설정 반영)
 */
const getEffectiveTheme = (mode: ThemeMode): 'light' | 'dark' => {
  if (mode === 'auto') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  }
  return mode;
};

/**
 * 테마 적용
 *
 * document.documentElement에 data-theme 속성과 dark 클래스를 설정합니다.
 */
const applyTheme = (mode: ThemeMode): void => {
  const effectiveTheme = getEffectiveTheme(mode);
  document.documentElement.setAttribute('data-theme', effectiveTheme);

  // dark 클래스 토글 (Tailwind CSS dark: 모드 지원)
  if (effectiveTheme === 'dark') {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
};

/**
 * 저장된 테마를 로드하여 적용합니다.
 *
 * 템플릿 초기화 시 호출되어 localStorage에 저장된 테마를 즉시 적용합니다.
 */
export function initTheme(): void {
  try {
    const savedTheme = localStorage.getItem(STORAGE_KEY) as ThemeMode | null;
    const theme = savedTheme && VALID_THEMES.includes(savedTheme) ? savedTheme : 'auto';
    applyTheme(theme);
    // _global.theme에 저장하여 Segmented Control 활성 상태 반영
    try {
      (window as any).G7Core?.state?.setGlobal?.({ theme });
    } catch (e) {
      logger.warn('Failed to set global theme state:', e);
    }
    logger.log('Initial theme applied:', theme);
  } catch (error) {
    logger.warn('Failed to load initial theme:', error);
    applyTheme('auto');
  }
}

/**
 * initTheme 핸들러
 *
 * 레이아웃의 init_actions에서 호출되어 초기 테마를 적용합니다.
 * ActionDispatcher의 커스텀 핸들러 형식을 따릅니다.
 *
 * target 파라미터가 전달되면 해당 테마로 초기화합니다.
 * target이 없거나 유효하지 않으면 localStorage 또는 'auto'를 사용합니다.
 *
 * @param action 액션 정의 (target에 테마 값 포함 가능)
 * @param _context 액션 컨텍스트 (사용하지 않음)
 */
export async function initThemeHandler(
  action: any,
  _context?: any
): Promise<void> {
  // action.target이 바인딩 해결된 값으로 전달됨 (ActionDispatcher에서 resolveValue 호출)
  const targetTheme = action?.target;

  // target이 유효한 테마 값인 경우 해당 테마 적용
  if (targetTheme && typeof targetTheme === 'string' && VALID_THEMES.includes(targetTheme as ThemeMode)) {
    applyTheme(targetTheme as ThemeMode);
    // _global.theme에 저장하여 Segmented Control 활성 상태 반영
    try {
      (window as any).G7Core?.state?.setGlobal?.({ theme: targetTheme });
    } catch (e) {
      logger.warn('Failed to set global theme state:', e);
    }
    return;
  }

  // target이 없거나 유효하지 않으면 기존 로직 (localStorage 또는 auto)
  initTheme();
}

/**
 * 테마 변경 핸들러
 *
 * ActionDispatcher는 handler(action, context) 형태로 호출합니다.
 * context 객체에는 { data, event, props, state, setState, navigate } 등이 포함됩니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트 (ActionContext 타입)
 */
export async function setThemeHandler(
  action: any,
  _context?: any
): Promise<void> {
  // 1. 테마 값 추출
  let theme: string | undefined;

  // action.target이 바인딩 해결된 값으로 전달됨 (ActionDispatcher에서 resolveValue 호출)
  if (action?.target && typeof action.target === 'string' && !action.target.includes('{{')) {
    theme = action.target;
  }

  // 2. 유효성 검증
  if (!theme || typeof theme !== 'string') {
    logger.warn('Invalid theme:', theme);
    return;
  }

  if (!VALID_THEMES.includes(theme as ThemeMode)) {
    logger.warn('Unsupported theme:', theme);
    return;
  }

  const validTheme = theme as ThemeMode;

  // 3. localStorage에 저장
  try {
    localStorage.setItem(STORAGE_KEY, validTheme);
  } catch (error) {
    logger.error('Failed to save theme to localStorage:', error);
    return;
  }

  // 4. 테마 즉시 적용 (페이지 새로고침 없이)
  applyTheme(validTheme);

  // 5. _global.theme에 저장하여 Segmented Control 활성 상태 반영
  try {
    (window as any).G7Core?.state?.setGlobal?.({ theme: validTheme });
  } catch (e) {
    logger.warn('Failed to set global theme state:', e);
  }

  logger.log('Theme changed to:', validTheme);
}