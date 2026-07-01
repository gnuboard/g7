/**
 * redirectToLoginWithReturn 핸들러
 *
 * 비로그인 회원전용 게시판/비밀글 진입 시 로그인 페이지로 보내되,
 * 현재 경로(pathname + querystring)를 redirect 파라미터로 보존한다.
 * 로그인 폼은 {{query.redirect ?? '/'}} 로 복원하므로(_login_form.json:155),
 * 로그인 성공 후 원래 위치로 복귀한다.
 *
 * 배경: 게시판 목록/상세 데이터소스 401(auth_mode: optional + 토큰 없음)은
 * 코어 ApiClient 자동가드를 우회하고 errorHandling 경로로 진입하는데,
 * 그 context 에는 route/query 가 없다(TemplateApp.ts setDefaultContext 는 navigate 만 주입).
 * 따라서 레이아웃 표현식으로는 현재 경로를 캡처할 수 없으므로, 핸들러가 window.location 을
 * 직접 읽어 우회한다(identityLauncher.ts 의 window.location.href 선례와 동일 패턴).
 *
 * window.location.pathname 은 항상 '/' 로 시작하는 same-origin path 이므로 외부 URL 주입이
 * 불가능하다(open redirect 안전).
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:RedirectToLoginWithReturn')) ?? {
  log: (...args: unknown[]) => console.log('[Handler:RedirectToLoginWithReturn]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:RedirectToLoginWithReturn]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:RedirectToLoginWithReturn]', ...args),
};

/**
 * 로그인 페이지로 이동하되 현재 경로를 redirect 로 보존하는 핸들러
 *
 * ActionDispatcher 는 handler(action, context) 형태로 호출하지만(ActionDispatcher.ts),
 * 이 핸들러는 action/context 를 사용하지 않는다 — 현재 경로는 window.location 에서 직접 읽는다.
 *
 * @param _action 액션 정의 (미사용)
 * @param _context 액션 컨텍스트 (미사용)
 * @return Promise<void>
 */
export async function redirectToLoginWithReturnHandler(
  _action?: any,
  _context?: any
): Promise<void> {
  const G7Core = (window as any).G7Core;

  if (!G7Core?.dispatch) {
    logger.error('G7Core.dispatch 가 초기화되지 않아 로그인 리다이렉트를 실행할 수 없습니다.');
    return;
  }

  // 핸들러는 표현식 화이트리스트 제약 밖 — window.location 직접 접근 가능.
  // pathname + search 로 현재 경로(쿼리스트링 포함)를 보존한다.
  const returnUrl = window.location.pathname + window.location.search;

  await G7Core.dispatch({
    handler: 'navigate',
    params: {
      path: '/login',
      // navigate 가 params.query 를 URLSearchParams 로 인코딩(ActionDispatcher.ts handleNavigate).
      // 로그인 폼은 {{query.redirect ?? '/'}} 로 복원(_login_form.json).
      query: { redirect: returnUrl },
    },
  });
}
