// e2e:allow편집기 위젯(icon-picker) 등록을 module-load 시점으로 이동(결함#3, 직접 하드로드 시 위젯 누락 해소). 라이브 검증은 Chrome MCP(편집기 직접 하드로드 후 icon-picker grid 1391셀 렌더 + 글리프 교체)로 수행, 단위 회귀는 registerEditorWidgets.test.ts(module-load 등록 + index.ts 최상위 호출 정적 가드).
/**
 * Sirsoft Admin Basic Template
 *
 * 그누보드7 템플릿 엔진용 컴포넌트 패키지
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Template:sirsoft-admin_basic')) ?? {
    log: (...args: unknown[]) => console.log('[Template:sirsoft-admin_basic]', ...args),
    warn: (...args: unknown[]) => console.warn('[Template:sirsoft-admin_basic]', ...args),
    error: (...args: unknown[]) => console.error('[Template:sirsoft-admin_basic]', ...args),
};

// Styles
import './styles/main.css';

// Basic Components
export * from './components/basic';

// Composite Components
export * from './components/composite';

// Layout Components
export * from './components/layout';

// Configuration
export * from './config/monaco.config';

// Template Metadata
import templateMetadata from '../template.json';

// Handlers
import { handlerMap } from './handlers';

// 데스크톱 사이드바 접힘 상태 복원 (새로고침 후 유지)
import { initSidebar } from './handlers/sidebarHandler';

// IDV Modal Launcher (engine-v1.46.0+)
import { registerSirsoftAdminBasicIdentityLauncher } from './handlers/identityLauncher';

// 레이아웃 편집기 커스텀 위젯(icon-picker 등) 등록
import { registerSirsoftAdminBasicEditorWidgets } from './layout-editor/registerEditorWidgets';

// handlerMap을 전역으로 노출 (로케일 변경 시 재등록용)
if (typeof window !== 'undefined') {
  (window as any).G7TemplateHandlers = handlerMap;
}

/**
 * 템플릿 메타데이터 export
 *
 * template.json 파일의 내용을 번들에 포함시켜 API 호출 없이
 * 코어 엔진에서 직접 접근 가능하도록 합니다.
 */
export { templateMetadata };

/**
 * 템플릿 초기화 함수
 *
 * 코어 엔진에 커스텀 핸들러를 등록합니다.
 */
export function initTemplate(): void {
  // ActionDispatcher가 로드될 때까지 대기 후 핸들러 등록
  if (typeof window !== 'undefined') {
    let retryCount = 0;
    const maxRetries = 50; // 최대 5초 대기 (50 * 100ms)

    const registerHandlers = () => {
      const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();

      if (actionDispatcher) {
        // handlerMap의 모든 핸들러를 자동으로 등록
        Object.entries(handlerMap).forEach(([name, handler]) => {
          actionDispatcher.registerHandler(name, handler);
        });

        logger.log(`${Object.keys(handlerMap).length} custom handler(s) registered:`, Object.keys(handlerMap));

        // IDV Modal Launcher 등록 (window.G7Core.identity.setLauncher 사용)
        registerSirsoftAdminBasicIdentityLauncher();

        // 데스크톱 사이드바 접힘 상태를 localStorage 에서 복원 (새로고침 후 유지)
        initSidebar();
      } else {
        retryCount++;
        if (retryCount <= maxRetries) {
          logger.warn(`ActionDispatcher not found, retrying... (${retryCount}/${maxRetries})`);
          setTimeout(registerHandlers, 100);
        } else {
          logger.error('Failed to register handlers: ActionDispatcher not available after maximum retries');
        }
      }
    };

    // window.load 이벤트 사용 (모든 리소스 로드 완료 후)
    if (document.readyState === 'complete') {
      registerHandlers();
    } else {
      window.addEventListener('load', registerHandlers);
    }
  }
}

// 레이아웃 편집기 커스텀 위젯(icon-picker) 등록 — 모듈 로드 시점에 즉시 실행한다.
//  ActionDispatcher 가용을 기다리는 `registerHandlers`(window.load
// 게이트) 안에서 등록하면, 편집기 URL 을 직접 하드로드한 경로에서 등록이 편집기 셸 마운트보다
// 늦어 icon-picker 위젯이 누락된다("Unsupported control"). `G7Core.layoutEditor` 예약 접수함
// (ready 큐 stub)은 편집기 로드 전 등록도 큐로 보존했다가 flush 하므로, 핸들러 등록 타이밍과
// 무관하게 모듈 로드 시 즉시 등록하는 것이 진입 경로(SPA 전환 / 직접 로드)와 무관하게 결정적이다.
registerSirsoftAdminBasicEditorWidgets();

// 템플릿 초기화 자동 실행
initTemplate();
