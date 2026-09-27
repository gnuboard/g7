/**
 * IdentityGuardInterceptor.redirectExternally 실행 스킴 차단 테스트
 *
 * 제공자 외부 인증 페이지(https) 이동은 정당하지만, `redirect_url` 이 실행 스킴이면
 * `window.location.href = …` 가 현재 origin 에서 코드를 돌리는 sink 가 된다
 * (KVE-2026-2348 과 같은 원인 — 이동 sink 무검증).
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

describe('IdentityGuardInterceptor.redirectExternally — 실행 스킴 차단', () => {
    let hrefSetter: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        IdentityGuardInterceptor.reset();
        hrefSetter = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new Proxy(window.location, {
                set(_target, prop, value) {
                    if (prop === 'href') {
                        hrefSetter(value);
                        return true;
                    }
                    return Reflect.set(_target, prop, value);
                },
            }),
        });
    });

    /**
     * @effects nav_sink_rejects_script_scheme
     */
    it('실행 스킴 redirect_url 은 이동하지 않고 실패를 반환한다', async () => {
        const result = await IdentityGuardInterceptor.redirectExternally({
            policy_key: 'k',
            purpose: 'signup',
            render_hint: 'external_redirect',
            redirect_url: ' JavaScript:alert(1)',
        });

        expect(result).toEqual(
            expect.objectContaining({ status: 'failed', failureCode: 'UNSAFE_REDIRECT_URL' }),
        );
        expect(hrefSetter).not.toHaveBeenCalled();
    });

    /**
     * @effects external_link_preserved
     */
    it('제공자 https 인증 페이지로는 그대로 이동한다 (과차단 방지)', () => {
        void IdentityGuardInterceptor.redirectExternally({
            policy_key: 'k',
            purpose: 'signup',
            render_hint: 'external_redirect',
            redirect_url: 'https://provider.example.com/auth?ch=abc',
        });

        expect(hrefSetter).toHaveBeenCalledWith('https://provider.example.com/auth?ch=abc');
    });
});
