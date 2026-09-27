/**
 * @vitest-environment jsdom
 *
 * 부트스트랩 회귀 잠금 — QR 이 실제로 DOM 에 그려지는지 잰다.
 *
 * ## 왜 이 파일이 필요한가
 *
 * 형제 테스트(`qr.test.ts`)는 `renderQrSvg()` 의 **반환 문자열**만 보고,
 * `layouts/totp-layouts.test.ts` 는 레이아웃에 `data-totp-qr` 이 **적혀 있는지**만 본다.
 * 둘 다 초록이어도 「등록 화면의 QR 자리가 빈 사각형」은 그대로 일어난다 — 그 둘 사이의
 * 연결(번들이 실행돼 관측자를 걸고 innerHTML 을 채우는 구간)을 아무도 재지 않았기 때문이다.
 * 게다가 이 플러그인의 vitest 기본 환경은 `node` 라 `typeof document !== 'undefined'`
 * 가드가 거짓이 되어 진입점 부트스트랩이 **한 줄도 실행되지 않는다**. 그래서 이 파일은
 * 파일 단위로 `jsdom` 을 지정한다.
 *
 * ## 무엇을 재는가 (실측 사고 재현)
 *
 * 2026-09-18 PO 실측: 등록 화면에서 QR 이 안 떴다. 원인은 진입점이 디스패처의
 * `registerHandler` 를 **변수로 떼어내 호출**한 것이었다 — 엔진 구현이 내부에서
 * `this.customHandlers` 를 쓰므로 수신자를 잃으면
 * `TypeError: Cannot read properties of undefined (reading 'customHandlers')` 로 터지고,
 * 그 예외가 `start()` 를 통째로 중단시켜 **QR 관측자가 설치되지 않았다**.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

/** 관측자 콜백이 돌 틈을 준다 — MutationObserver 는 마이크로태스크로 배달된다. */
async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/** 진입점을 매번 새로 적재한다 — 모듈 최상위 부트스트랩을 다시 돌리기 위해서다. */
async function loadEntry(): Promise<void> {
    vi.resetModules();
    await import('../index');
    await flush();
}

describe('진입점 부트스트랩', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        delete (window as Record<string, unknown>).G7Core;
    });

    it('디스패처가 없어도 QR 을 그린다', async () => {
        const host = document.createElement('div');
        host.setAttribute('data-totp-qr', 'otpauth://totp/T:a@b.c?secret=JBSWY3DPEHPK3PXP');
        document.body.appendChild(host);

        await loadEntry();

        expect(host.innerHTML).toContain('<svg');
    });

    it('나중에 붙은 노드도 관측자가 채운다', async () => {
        await loadEntry();

        const late = document.createElement('div');
        late.setAttribute('data-totp-qr', 'otpauth://totp/T:a@b.c?secret=JBSWY3DPEHPK3PXP');
        document.body.appendChild(late);
        await flush();

        expect(late.innerHTML).toContain('<svg');
    });

    it('속성이 빈 값에서 채워져도 그린다 — 등록 시작 시 실제 순서다', async () => {
        const host = document.createElement('div');
        host.setAttribute('data-totp-qr', '');
        document.body.appendChild(host);

        await loadEntry();
        expect(host.innerHTML).toBe('');

        host.setAttribute('data-totp-qr', 'otpauth://totp/T:a@b.c?secret=JBSWY3DPEHPK3PXP');
        await flush();

        expect(host.innerHTML).toContain('<svg');
    });

    /**
     * 이 케이스가 이번 사고의 정확한 재현이다 — 떼어낸 메서드가 `this` 를 잃고 터지는 상황.
     * 진입점이 수신자를 붙여 부르면(또는 등록 실패를 가둬 두면) QR 은 영향을 받지 않는다.
     */
    it('핸들러 등록이 터져도 QR 은 그려진다', async () => {
        const dispatcher = {
            customHandlers: new Map<string, unknown>(),
            registerHandler(this: { customHandlers: Map<string, unknown> }, name: string, handler: unknown): void {
                // 엔진과 같은 형태 — 수신자를 잃으면 여기서 TypeError 가 난다.
                this.customHandlers.set(name, handler);
            },
        };
        (window as Record<string, unknown>).G7Core = { getActionDispatcher: () => dispatcher };

        const host = document.createElement('div');
        host.setAttribute('data-totp-qr', 'otpauth://totp/T:a@b.c?secret=JBSWY3DPEHPK3PXP');
        document.body.appendChild(host);

        await loadEntry();

        expect(host.innerHTML).toContain('<svg');
        // 수신자를 붙여 불렀으므로 등록도 실제로 성공한다.
        expect(dispatcher.customHandlers.has('sirsoft-totp.copyToClipboard')).toBe(true);
    });
});
