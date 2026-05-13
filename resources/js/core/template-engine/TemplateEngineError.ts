import type { TranslationContext } from '../template-engine/TranslationEngine';

/**
 * 템플릿 엔진 에러 기본 클래스
 */
export class TemplateEngineError extends Error {
    public readonly code: string;
    public readonly userMessageKey: string;
    public readonly showStack: boolean;
    public readonly icon: string;
    public readonly recoverable: boolean;

    constructor(
        code: string,
        userMessageKey: string,
        options: {
            showStack?: boolean;
            icon?: string;
            recoverable?: boolean;
        } = {}
    ) {
        super(userMessageKey);
        this.name = 'TemplateEngineError';
        this.code = code;
        this.userMessageKey = userMessageKey;
        this.showStack = options.showStack ?? true;
        this.icon = options.icon ?? 'fa-circle-exclamation';
        this.recoverable = options.recoverable ?? true;

        // Maintains proper stack trace for where our error was thrown (only available on V8)
        if (Error.captureStackTrace) {
            Error.captureStackTrace(this, this.constructor);
        }
    }

    /**
     * 다국어가 적용된 사용자 메시지를 반환합니다.
     *
     * @param context 번역 컨텍스트
     * @param translationEngine 번역 엔진 (선택)
     */
    getUserMessage(
        context?: TranslationContext,
        translationEngine?: { translate: (key: string, context: TranslationContext) => string }
    ): string {
        // 번역 엔진이 없거나 컨텍스트가 없으면 키를 그대로 반환
        if (!translationEngine || !context) {
            return this.userMessageKey;
        }

        try {
            return translationEngine.translate(this.userMessageKey, context);
        } catch (e) {
            // 번역 실패 시 키를 그대로 반환
            return this.userMessageKey;
        }
    }
}

/**
 * 템플릿을 찾을 수 없을 때 발생하는 에러
 */
export class TemplateNotFoundError extends TemplateEngineError {
    constructor() {
        super(
            'TEMPLATE_NOT_FOUND',
            '$t:core.errors.template_not_found',
            {
                showStack: false,
                icon: 'fa-paint-brush',
                recoverable: false,
            }
        );
        this.name = 'TemplateNotFoundError';
    }
}

/**
 * 레이아웃 로딩 실패 에러
 */
export class LayoutLoadError extends TemplateEngineError {
    constructor(layoutId?: string) {
        const messageKey = layoutId
            ? '$t:core.errors.layout_load_failed_with_id|layoutId=' + layoutId
            : '$t:core.errors.layout_load_failed';

        super('LAYOUT_LOAD_FAILED', messageKey, {
            showStack: true,
            icon: 'fa-file-circle-xmark',
            recoverable: true,
        });
        this.name = 'LayoutLoadError';
    }
}

/**
 * 컴포넌트 레지스트리 에러
 */
export class ComponentRegistryError extends TemplateEngineError {
    constructor(componentName?: string) {
        const messageKey = componentName
            ? '$t:core.errors.component_load_failed_with_name|componentName=' + componentName
            : '$t:core.errors.component_load_failed';

        super('COMPONENT_REGISTRY_FAILED', messageKey, {
            showStack: true,
            icon: 'fa-cubes',
            recoverable: true,
        });
        this.name = 'ComponentRegistryError';
    }
}

/**
 * 번역 파일 로딩 에러
 */
export class TranslationLoadError extends TemplateEngineError {
    constructor(locale?: string) {
        const messageKey = locale
            ? '$t:core.errors.translation_load_failed_with_locale|locale=' + locale
            : '$t:core.errors.translation_load_failed';

        super('TRANSLATION_LOAD_FAILED', messageKey, {
            showStack: false,
            icon: 'fa-language',
            recoverable: true,
        });
        this.name = 'TranslationLoadError';
    }
}

/**
 * 일반 Error를 TemplateEngineError로 변환
 */
export function toTemplateEngineError(error: Error | unknown): TemplateEngineError {
    // 이미 TemplateEngineError인 경우 그대로 반환
    if (error instanceof TemplateEngineError) {
        return error;
    }

    // Error 객체인 경우
    if (error instanceof Error) {
        // 원본 에러 메시지를 그대로 사용 (다국어 키가 아님)
        const tempError = new TemplateEngineError(
            'TEMPLATE_INIT_ERROR',
            error.message || '$t:core.errors.template_init_error',
            {
                showStack: true,
                icon: 'fa-circle-exclamation',
                recoverable: true,
            }
        );
        // 원본 스택을 보존
        tempError.stack = error.stack;
        return tempError;
    }

    // 기타 타입인 경우
    return new TemplateEngineError(
        'TEMPLATE_INIT_ERROR',
        '$t:core.errors.template_init_error',
        {
            showStack: false,
            icon: 'fa-circle-exclamation',
            recoverable: true,
        }
    );
}
