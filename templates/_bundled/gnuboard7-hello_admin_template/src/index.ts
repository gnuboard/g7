/**
 * Gnuboard7 Hello Admin Template
 *
 * 학습용 최소 Admin 템플릿 스켈레톤 — Basic 8개 컴포넌트만 포함
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Template:gnuboard7-hello_admin_template')) ?? {
    log: (...args: unknown[]) => console.log('[Template:gnuboard7-hello_admin_template]', ...args),
    warn: (...args: unknown[]) => console.warn('[Template:gnuboard7-hello_admin_template]', ...args),
    error: (...args: unknown[]) => console.error('[Template:gnuboard7-hello_admin_template]', ...args),
};

// Basic Components
export * from './components/basic';

import { Div } from './components/basic/Div';
import { Button } from './components/basic/Button';
import { H1 } from './components/basic/H1';
import { H2 } from './components/basic/H2';
import { H3 } from './components/basic/H3';
import { A } from './components/basic/A';
import { Span } from './components/basic/Span';
import { Img } from './components/basic/Img';

// Template Metadata
import templateMetadata from '../template.json';

export { templateMetadata };

/**
 * 템플릿 초기화 함수
 *
 * 코어 엔진의 ComponentRegistry 에 Basic 8개 컴포넌트를 등록합니다.
 */
export function initTemplate(): void {
  if (typeof window === 'undefined') return;

  let retryCount = 0;
  const maxRetries = 50;

  const registerComponents = () => {
    const registry = (window as any).G7Core?.getComponentRegistry?.()
      ?? (window as any).__G7_COMPONENTS__;

    if (registry && typeof registry.register === 'function') {
      registry.register('Div', Div, { type: 'basic', name: 'Div' });
      registry.register('Button', Button, { type: 'basic', name: 'Button' });
      registry.register('H1', H1, { type: 'basic', name: 'H1' });
      registry.register('H2', H2, { type: 'basic', name: 'H2' });
      registry.register('H3', H3, { type: 'basic', name: 'H3' });
      registry.register('A', A, { type: 'basic', name: 'A' });
      registry.register('Span', Span, { type: 'basic', name: 'Span' });
      registry.register('Img', Img, { type: 'basic', name: 'Img' });

      logger.log('8 basic component(s) registered');
      return;
    }

    retryCount++;
    if (retryCount <= maxRetries) {
      setTimeout(registerComponents, 100);
    } else {
      logger.error('Failed to register components: ComponentRegistry not available after maximum retries');
    }
  };

  if (document.readyState === 'complete') {
    registerComponents();
  } else {
    window.addEventListener('load', registerComponents);
  }
}

// 템플릿 초기화 자동 실행
initTemplate();
