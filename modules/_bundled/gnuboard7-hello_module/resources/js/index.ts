/**
 * Gnuboard7 Hello Module — 프론트엔드 엔트리
 *
 * 학습용 최소 샘플. **모듈이 컴포넌트를 제공하는 법**을 보여 준다.
 *
 * 컴포넌트는 액션 핸들러와 같은 방식으로 등록한다 — 모듈 번들이 스스로 등록한다(self-register).
 * 템플릿 컴포넌트처럼 매니페스트 + 전역 변수 경로로 실릴 자리가 모듈에는 없기 때문이다.
 *
 * 등록 시점은 안전하다: 코어가 모듈 번들 로드를 초기 라우트 처리 **전에** 기다리므로, 여기서
 * 하는 등록은 첫 렌더보다 반드시 먼저 끝난다.
 */

const MODULE_IDENTIFIER = 'gnuboard7-hello_module';

const logger = (window as any).G7Core?.createLogger?.(`Module:${MODULE_IDENTIFIER}`) ?? {
    log: (...args: unknown[]) => console.log(`[Module:${MODULE_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Module:${MODULE_IDENTIFIER}]`, ...args),
};

import { MemoCard } from './components/composite/MemoCard';
import { MemoCard as MemoCardForAdmin } from './components/for/gnuboard7-hello_admin_template/MemoCard';

export { MemoCard, type MemoCardProps } from './components/composite/MemoCard';

/**
 * 이 모듈이 제공하는 컴포넌트를 코어 레지스트리에 등록합니다.
 */
function initModule(): void {
    const G7Core = (window as any).G7Core;
    const registry = G7Core?.getComponentRegistry?.();

    if (!registry?.registerExtensionComponent) {
        logger.warn('ComponentRegistry 를 찾지 못해 컴포넌트 등록을 건너뜁니다');
        return;
    }

    // 기본 판본 — 이름에 모듈 식별자 접두어가 필수다. 접두어가 없으면 템플릿 소유 컴포넌트를
    // 덮어쓸 수 있고, 작성자가 태그만 보고 출처를 알 수 없다.
    registry.registerExtensionComponent(
        `${MODULE_IDENTIFIER}.MemoCard`,
        MemoCard,
        { name: `${MODULE_IDENTIFIER}.MemoCard`, type: 'composite' }
    );

    // 템플릿 전용 판본 — "그 템플릿용" 이라고 **선언만** 한다. 활성 템플릿과 대조해 고르는
    // 판정은 코어가 전담하므로, 여기서 활성 템플릿을 읽어 분기하지 않는다.
    registry.registerExtensionComponent(
        `${MODULE_IDENTIFIER}.MemoCard`,
        MemoCardForAdmin,
        { name: `${MODULE_IDENTIFIER}.MemoCard`, type: 'composite' },
        { forTemplate: 'gnuboard7-hello_admin_template' }
    );

    logger.log('컴포넌트 등록 완료');
}

initModule();
