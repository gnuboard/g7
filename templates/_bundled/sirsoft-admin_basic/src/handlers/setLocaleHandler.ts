/**
 * setLocale 핸들러
 *
 * 사용자가 선택한 언어로 변경합니다.
 * localStorage에 g7_locale 값을 저장하고 페이지를 새로고침합니다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:SetLocale')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:SetLocale]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:SetLocale]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:SetLocale]', ...args),
};

/**
 * 언어 변경 핸들러
 *
 * ActionDispatcher는 handler(action, context) 형태로 호출합니다.
 * context 객체에는 { data, event, props, state, setState, navigate } 등이 포함됩니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트 (ActionContext 타입)
 */
export async function setLocaleHandler(
  action: any,
  context?: any
): Promise<void> {
  // 1. 이벤트 데이터에서 locale 값 추출
  let locale: string | undefined;

  const eventData = context?.event;

  if (eventData?.target?.value !== undefined && eventData.target.value !== null) {
    // HTML Select onChange 이벤트
    locale = eventData.target.value;
  } else if (eventData?.value !== undefined && eventData.value !== null) {
    // React Select 등 커스텀 이벤트 대응
    locale = eventData.value;
  } else if (action?.target && typeof action.target === 'string' && !action.target.includes('{{')) {
    // action.target이 템플릿 문자열이 아닌 경우에만 사용
    locale = action.target;
  }

  // 2. 유효성 검증
  if (!locale || typeof locale !== 'string') {
    logger.warn('Invalid locale:', locale);
    return;
  }

  // 유효한 로케일 확인 (템플릿 메타데이터에서 가져오거나 기본값 사용)
  const validLocales = ['ko', 'en']; // TODO: 템플릿 메타데이터에서 가져오기
  if (!validLocales.includes(locale)) {
    logger.warn('Unsupported locale:', locale);
    return;
  }

  // 3. localStorage에 저장
  try {
    localStorage.setItem('g7_locale', locale);
  } catch (error) {
    logger.error('Failed to save locale to localStorage:', error);
    return;
  }

  // 4. 페이지 새로고침
  window.location.reload();
}
