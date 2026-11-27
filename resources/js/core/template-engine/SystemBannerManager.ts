/**
 * SystemBannerManager.ts
 *
 * 시스템 배너 관리자
 *
 * 프리뷰 모드, 유지보수 모드 등 시스템 상태를 사용자에게 알리는
 * 고정 배너를 관리합니다.
 *
 * @module SystemBannerManager
 * @since engine-v1.26.1
 */

import { createLogger } from '../utils/Logger';
import { pickLocalized } from '../support/localeTag';

const logger = createLogger('SystemBannerManager');

/**
 * 시스템 배너 옵션
 */
export interface SystemBannerOptions {
    /** 배너 고유 ID */
    id: string;
    /** 표시할 메시지 (다국어: { ko, en } 객체 또는 단일 string) */
    message: string | Record<string, string>;
    /** 배너 배경색 (CSS gradient 또는 단색) */
    background?: string;
    /** 배너 텍스트 색 */
    color?: string;
    /** 배너 순서 (낮을수록 위에 표시, 기본: 0) */
    order?: number;
}

/**
 * 시스템 배너 관리자
 *
 * 프리뷰 모드, 유지보수 모드 등 시스템 상태를 사용자에게 알리는
 * 고정 배너를 관리합니다.
 *
 * @since engine-v1.26.1
 */
export class SystemBannerManager {
    private static banners: Map<string, SystemBannerOptions> = new Map();
    private static containerId = 'g7-system-banners';

    /**
     * 시스템 배너를 추가합니다.
     *
     * @param options 배너 옵션
     */
    static show(options: SystemBannerOptions): void {
        this.banners.set(options.id, options);
        this.render();
        logger.log(`Banner shown: ${options.id}`);
    }

    /**
     * 배너를 제거합니다.
     *
     * @param id 배너 고유 ID
     */
    static hide(id: string): void {
        if (this.banners.delete(id)) {
            this.render();
            logger.log(`Banner hidden: ${id}`);
        }
    }

    /**
     * 모든 배너를 제거합니다.
     */
    static hideAll(): void {
        this.banners.clear();
        this.render();
        logger.log('All banners hidden');
    }

    /**
     * 현재 브라우저 로케일을 감지합니다.
     *
     * @returns 로케일 코드 (ko, en 등)
     */
    private static detectLocale(): string {
        // G7Core에서 로케일 가져오기
        // G7Core.locale은 객체: { current(), supported(), change() }
        try {
            const G7Core = (window as any).G7Core;
            if (G7Core?.locale?.current) {
                return G7Core.locale.current();
            }
        } catch {
            // ignore
        }

        // fallback: 브라우저 언어 감지
        const lang = navigator.language || 'ko';
        return lang.split('-')[0];
    }

    /**
     * 메시지를 로케일에 따라 해석합니다.
     *
     * @param message 단일 문자열 또는 다국어 객체
     * @param locale 로케일 코드
     * @returns 해석된 메시지 문자열
     */
    private static resolveMessage(message: string | Record<string, string>, locale: string): string {
        if (typeof message === 'string') {
            return message;
        }
        // 화면 언어 → 사이트 기본 언어 → en → 첫 값 (engine-v1.81.0)
        return pickLocalized(message, locale) ?? '';
    }

    /**
     * 모든 배너를 DOM에 렌더링합니다.
     */
    private static render(): void {
        if (typeof document === 'undefined') {
            return;
        }

        let container = document.getElementById(this.containerId);

        // 배너가 없으면 컨테이너 제거
        if (this.banners.size === 0) {
            if (container) {
                container.remove();
                this.adjustAppPadding(0);
            }
            return;
        }

        // 컨테이너 생성 (없으면)
        if (!container) {
            container = document.createElement('div');
            container.id = this.containerId;
            container.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;';
            document.body.prepend(container);
        }

        // 배너를 order 기준으로 정렬
        const sortedBanners = Array.from(this.banners.values()).sort(
            (a, b) => (a.order ?? 0) - (b.order ?? 0)
        );

        const locale = this.detectLocale();

        // 배너 항목 렌더링 — 고정 모양은 코어 정적 CSS 의 `g7-system-banner` 클래스, 배너마다 다른 배경·글자색은
        // CSSOM 대입으로 준다. HTML 문자열의 style 속성은 엄격한 스타일 정책에서 막혀 배너가 무스타일이 된다(공개 #163).
        // 메시지는 종전 계약대로 HTML 문자열을 허용한다(innerHTML).
        container.replaceChildren(...sortedBanners.map(banner => {
            const item = document.createElement('div');
            item.dataset.bannerId = String(banner.id);
            item.className = 'g7-system-banner';
            item.style.background = banner.background || '#f59e0b';
            item.style.color = banner.color || 'white';
            item.innerHTML = this.resolveMessage(banner.message, locale);

            return item;
        }));

        // #app 컨테이너에 paddingTop 적용 (배너 높이만큼)
        // requestAnimationFrame으로 렌더링 후 높이 측정
        requestAnimationFrame(() => {
            if (container) {
                this.adjustAppPadding(container.offsetHeight);
            }
        });
    }

    /**
     * #app 컨테이너의 paddingTop을 조정합니다.
     *
     * @param height 배너 컨테이너 높이 (px)
     */
    private static adjustAppPadding(height: number): void {
        const app = document.getElementById('app');
        if (app) {
            app.style.paddingTop = height > 0 ? `${height}px` : '';
        }
    }
}
