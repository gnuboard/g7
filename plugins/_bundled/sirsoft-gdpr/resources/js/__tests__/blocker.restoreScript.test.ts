// e2e:allow 차단 엔진 복원 분기 단위. 실제 동의 후 추적 스크립트 실행은 브라우저 실측(이력 §8 「T7 B2」 행)이 덮는다.
/**
 * 동의 후 복원하는 <script> 는 실행 가능한 새 요소여야 한다 (#158 부수 결함 B2)
 *
 * innerHTML 로 되살린 <script> 는 문서에 붙여도 실행되지 않는다 — 동의를 받아도 MutationObserver 경로로 막혔던
 * 추적 스크립트가 돌지 않았다. 새 script 요소를 만들어 허용 속성(src·type·async·defer·crossorigin·integrity·
 * referrerpolicy·nomodule·data-*)만 옮기고, 본문 텍스트(인라인 코드)는 옮기지 않는다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setBlockedDomains, setCurrentConsent, startBlocker, stopBlocker } from '../blocker';

beforeEach(() => {
    Object.defineProperty(window, 'location', {
        value: { hostname: 'mysite.test', href: 'https://mysite.test/' },
        writable: true,
    });
    document.body.innerHTML = '';
    setBlockedDomains({});
    setCurrentConsent({});
    startBlocker();
});

afterEach(() => {
    stopBlocker();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('blocker 복원 — script 재생성 (#158 B2)', () => {
    /**
     * @scenario restore=script_with_src
     * @effects blocker_restores_script_as_fresh_element
     */
    it('src 있는 script 는 createElement 로 새로 만들고 허용 속성만 옮긴다', async () => {
        const script = document.createElement('script');
        script.src = 'https://tracker.example.test/t.js';
        script.setAttribute('async', ''); // 프로퍼티 대입은 jsdom 에서 속성을 남기지 않아 outerHTML 보존에서 빠진다
        script.setAttribute('data-gdpr-category', 'analytics');
        script.setAttribute('crossorigin', 'anonymous');
        script.setAttribute('onload', 'alert(1)');
        document.body.appendChild(script);
        await flush();
        expect(document.querySelector('script')).toBeNull();

        const createSpy = vi.spyOn(document, 'createElement');
        setCurrentConsent({ analytics: true });
        await flush();

        const restored = document.querySelector('script') as HTMLScriptElement;
        expect(restored).not.toBeNull();
        expect(createSpy).toHaveBeenCalledWith('script');
        expect(restored.getAttribute('src')).toBe('https://tracker.example.test/t.js');
        expect(restored.hasAttribute('async')).toBe(true);
        expect(restored.getAttribute('crossorigin')).toBe('anonymous');
        expect(restored.getAttribute('data-gdpr-category')).toBe('analytics');
        // 이벤트 핸들러 속성은 옮기지 않는다
        expect(restored.hasAttribute('onload')).toBe(false);
    });

    /**
     * @scenario restore=inline_script
     * @effects blocker_never_restores_inline_script_body
     */
    it('src 없는(인라인) script 는 되살리지 않고 placeholder 만 지운다', async () => {
        const script = document.createElement('script');
        script.textContent = 'window.__inline = 1;';
        script.setAttribute('data-gdpr-category', 'analytics');
        document.body.appendChild(script);
        await flush();
        expect(document.querySelector('.gdpr-blocked-embed')).not.toBeNull();

        setCurrentConsent({ analytics: true });
        await flush();

        expect(document.querySelector('script')).toBeNull();
        expect(document.querySelector('.gdpr-blocked-embed')).toBeNull();
    });

    /**
     * @scenario restore=non_script
     * @effects blocker_restores_script_as_fresh_element
     */
    it('script 가 아닌 요소(iframe)는 종전대로 그대로 복원한다', async () => {
        const iframe = document.createElement('iframe');
        iframe.src = 'https://video.example.test/embed/1';
        iframe.setAttribute('data-gdpr-category', 'marketing');
        document.body.appendChild(iframe);
        await flush();
        expect(document.querySelector('iframe')).toBeNull();

        setCurrentConsent({ marketing: true });
        await flush();

        expect(document.querySelector('iframe')?.getAttribute('src')).toBe('https://video.example.test/embed/1');
    });
});
