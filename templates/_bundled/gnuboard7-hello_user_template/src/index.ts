/**
 * Gnuboard7 Hello User Template
 *
 * 학습용 최소 샘플 사용자 템플릿.
 * Basic 8개 컴포넌트만 포함 (Div, Button, H1, H2, H3, A, Span, Img).
 */

// Logger (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Template:gnuboard7-hello_user_template')) ?? {
    log: (...args: unknown[]) => console.log('[Template:gnuboard7-hello_user_template]', ...args),
    warn: (...args: unknown[]) => console.warn('[Template:gnuboard7-hello_user_template]', ...args),
    error: (...args: unknown[]) => console.error('[Template:gnuboard7-hello_user_template]', ...args),
};

// Basic 8개 컴포넌트
export { Div, type DivProps } from './components/basic/Div';
export { Button, type ButtonProps } from './components/basic/Button';
export { H1, type H1Props } from './components/basic/H1';
export { H2, type H2Props } from './components/basic/H2';
export { H3, type H3Props } from './components/basic/H3';
export { A, type AProps } from './components/basic/A';
export { Span, type SpanProps } from './components/basic/Span';
export { Img, type ImgProps } from './components/basic/Img';

// 컴포넌트 레지스트리 자동 등록
import { Div } from './components/basic/Div';
import { Button } from './components/basic/Button';
import { H1 } from './components/basic/H1';
import { H2 } from './components/basic/H2';
import { H3 } from './components/basic/H3';
import { A } from './components/basic/A';
import { Span } from './components/basic/Span';
import { Img } from './components/basic/Img';

const registry = (window as any).G7Core?.templateEngine?.ComponentRegistry?.getInstance?.();
if (registry) {
    const meta = (name: string) => ({ name, type: 'basic' as const });
    registry.register({ component: Div, metadata: meta('Div') });
    registry.register({ component: Button, metadata: meta('Button') });
    registry.register({ component: H1, metadata: meta('H1') });
    registry.register({ component: H2, metadata: meta('H2') });
    registry.register({ component: H3, metadata: meta('H3') });
    registry.register({ component: A, metadata: meta('A') });
    registry.register({ component: Span, metadata: meta('Span') });
    registry.register({ component: Img, metadata: meta('Img') });
    logger.log('Registered 8 basic components');
} else {
    logger.warn('ComponentRegistry not available — skipping auto-registration');
}
