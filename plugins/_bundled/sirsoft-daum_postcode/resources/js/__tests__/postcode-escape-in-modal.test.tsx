// e2e:allow 실제 사용자·관리자 템플릿 Modal 안에서 핸들러가 띄운 레이어·전체 화면 창에 Esc 를 눌러 창 유지·초점 복귀를 잰다 — 브라우저 축은 출시 전 화면 재실측(마이페이지 › 배송지 › 새 주소 추가 › 주소 검색)이 덮는다
/**
 * 우편번호 레이어·전체 화면 창의 Esc 는 그 창만 닫는다 — 아래에 열린 창(배송지 등록 등)은 남는다
 *
 * 회귀 배경:
 * - 마이페이지 「새 주소 추가」 창(템플릿 Modal) 안에서 주소 검색을 레이어로 연 뒤 Esc 를 한 번 누르면 우편번호 레이어와
 *   그 아래 배송지 등록 창이 함께 닫혀, 이미 고른 우편번호·입력한 주소가 사라졌다.
 * - 레이어·전체 화면 창과 템플릿 Modal 이 각자 document keydown 리스너로 Esc 를 받는다. Modal 은 창 안에 펼친 팝업
 *   (`aria-haspopup` + `aria-expanded="true"`)이 있으면 Esc 를 양보하지만, 우편번호 창은 body 에 붙어 그 규칙에 잡히지
 *   않는다. 먼저 등록된 Modal 리스너가 창을 닫고, 뒤이어 우편번호 리스너가 레이어를 닫았다.
 * - 레이어는 닫힌 뒤 초점을 돌려주지 않아, 창이 남더라도 초점이 문서 본문(BODY)으로 떨어졌다.
 *
 * 우편번호 창이 열려 있는 동안 Esc 는 맨 위 창인 우편번호 창 몫이다 — 그 창이 받아 처리하고 다른 리스너에는 넘기지 않는다.
 * 템플릿마다 창 구현이 달라도(제3자 템플릿 포함) 같은 결과가 나와야 하므로 템플릿 규칙에 기대지 않는다.
 *
 * @scenario-manifest tests/scenarios/postcode-escape-in-modal.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, screen, cleanup } from '@testing-library/react';
import { Modal as BasicModal } from '../../../../../../templates/_bundled/sirsoft-basic/src/components/composite/Modal';
import { Modal as AdminModal } from '../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/Modal';
import { openPostcodeHandler } from '../handlers/openPostcode';

/** 가짜 SDK 인스턴스 기록 */
interface FakeInstance {
    config: any;
    embed: ReturnType<typeof vi.fn>;
    open: ReturnType<typeof vi.fn>;
}

/**
 * 생성 기록을 남기는 가짜 우편번호 생성자를 만든다.
 *
 * @param instances 생성된 인스턴스를 담을 배열
 * @return 생성자
 */
function makeFakePostcode(instances: FakeInstance[]): any {
    return function FakePostcode(this: any, config: any) {
        const instance: FakeInstance = { config, embed: vi.fn(), open: vi.fn() };
        instances.push(instance);
        this.embed = instance.embed;
        this.open = instance.open;
    };
}

/**
 * 클릭 시점의 화면 폭을 정한다.
 *
 * @param width 화면 폭(px)
 */
function setViewportWidth(width: number): void {
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
}

/**
 * 엔진이 비동기 핸들러에 넘기는 클릭 이벤트 모양 — 위임이 끝나 currentTarget 이 비고 target 이 버튼 안 글자다.
 *
 * @param target 실제 클릭된 요소
 * @return 이벤트 모양 객체
 */
function clickEvent(target: Element): any {
    return { type: 'click', currentTarget: null, target };
}

/**
 * 지금 초점이 있는 요소에서 Esc 를 누른다 — 실제 브라우저처럼 초점 요소에서 시작해 document 까지 거슬러 오른다.
 */
function pressEscape(): void {
    const target = (document.activeElement as HTMLElement | null) ?? document.body;
    target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
}

/**
 * 배송지 등록 창과 같은 모양 — 창 안에 우편번호 칸과 「주소 검색」 버튼.
 *
 * @param ModalComponent 템플릿 Modal
 * @param onClose 창 닫기 처리기
 */
function AddressModal({ ModalComponent, onClose }: { ModalComponent: React.FC<any>; onClose: () => void }) {
    return (
        <ModalComponent isOpen onClose={onClose} title="새 주소 추가">
            <div className="flex gap-2">
                <input name="zipcode" aria-label="우편번호" defaultValue="06236" />
                <div>
                    <div className="inline-flex">
                        <button type="button" data-testid="search"><span data-testid="search-label">주소 검색</span></button>
                    </div>
                </div>
            </div>
            <input name="address" aria-label="주소" />
        </ModalComponent>
    );
}

/**
 * 창 안 「주소 검색」 버튼으로 우편번호 창을 연다.
 *
 * @param params 액션 매개변수
 * @return 누른 버튼
 */
async function openFromModal(params: Record<string, unknown>): Promise<HTMLElement> {
    const button = screen.getByTestId('search');
    button.focus();
    await openPostcodeHandler(
        { handler: 'sirsoft-daum_postcode.openPostcode', params },
        { event: clickEvent(screen.getByTestId('search-label')) } as any,
    );

    return button;
}

const MODALS: Array<[string, React.FC<any>]> = [
    ['사용자 템플릿(sirsoft-basic) Modal', BasicModal as React.FC<any>],
    ['관리자 템플릿(sirsoft-admin_basic) Modal', AdminModal as React.FC<any>],
];

describe('우편번호 창의 Esc 는 그 창만 닫는다', () => {
    let instances: FakeInstance[];

    beforeEach(() => {
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        instances = [];
        setViewportWidth(1280);
        (window as any).kakao = { Postcode: makeFakePostcode(instances) };
        (window as any).G7Core = {
            plugin: { getSettings: () => ({}) },
            state: { setLocal: vi.fn() },
            t: (key: string) => key,
            assets: { notifyFailure: vi.fn(), clearFailure: vi.fn() },
        };
    });

    afterEach(() => {
        cleanup();
        document.getElementById('daum-postcode-overlay')?.remove();
        document.getElementById('daum-postcode-fullscreen')?.remove();
        document.getElementById('g7-postcode-inline-style')?.remove();
        document.body.style.overflow = '';
        delete (window as any).kakao;
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    describe.each(MODALS)('%s 안에서', (_label, ModalComponent) => {
        /**
         * @scenario host=modal, mode=pc_layer
         * @effects escape_closes_only_postcode_window, focus_returns_to_trigger
         */
        it('PC 레이어에서 Esc 한 번은 레이어만 닫고 창은 남기며 초점을 「주소 검색」 버튼으로 돌린다', async () => {
            const onClose = vi.fn();
            render(<AddressModal ModalComponent={ModalComponent} onClose={onClose} />);

            const trigger = await openFromModal({ displayModePc: 'layer' });
            expect(document.getElementById('daum-postcode-layer')).not.toBeNull();

            pressEscape();

            expect(document.getElementById('daum-postcode-layer')).toBeNull();
            expect(onClose).not.toHaveBeenCalled();
            expect(screen.getByRole('dialog')).not.toBeNull();
            expect(document.activeElement).toBe(trigger);

            // 우편번호 창이 닫힌 뒤의 Esc 는 다시 창 몫이다 — 리스너가 남아 Esc 를 삼키지 않는다
            pressEscape();
            expect(onClose).toHaveBeenCalledTimes(1);
        });

        /**
         * @scenario host=modal, mode=mobile_fullscreen
         * @effects escape_closes_only_postcode_window, focus_returns_to_trigger
         */
        it('모바일 전체 화면 창에서 Esc 한 번은 전체 화면 창만 닫고 창은 남기며 초점을 버튼으로 돌린다', async () => {
            setViewportWidth(390);
            const onClose = vi.fn();
            render(<AddressModal ModalComponent={ModalComponent} onClose={onClose} />);

            const trigger = await openFromModal({ displayModeMobile: 'fullscreen' });
            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();

            pressEscape();

            expect(document.getElementById('daum-postcode-fullscreen')).toBeNull();
            expect(onClose).not.toHaveBeenCalled();
            expect(document.activeElement).toBe(trigger);

            pressEscape();
            expect(onClose).toHaveBeenCalledTimes(1);
        });
    });

    /**
     * 펼친 팝업 규칙을 모르는 창 구현(제3자 템플릿 등)도 Esc 를 받지 않는다 — 템플릿 규칙에 기대지 않는다.
     *
     * @scenario host=generic_document_listener, mode=pc_layer
     * @effects escape_closes_only_postcode_window
     */
    it('먼저 등록된 다른 document Esc 리스너는 우편번호 레이어가 받은 Esc 를 보지 않는다', async () => {
        const otherEscape = vi.fn();
        const listener = (e: KeyboardEvent) => {
            if (e.key === 'Escape') otherEscape();
        };
        document.addEventListener('keydown', listener);
        document.body.innerHTML = '<div><input name="zipcode" /><div><div><button id="btn" type="button">주소 검색</button></div></div></div>';

        try {
            const button = document.getElementById('btn')!;
            button.focus();
            await openPostcodeHandler(
                { handler: 'sirsoft-daum_postcode.openPostcode', params: { displayModePc: 'layer' } },
                { event: clickEvent(button) } as any,
            );

            pressEscape();

            expect(document.getElementById('daum-postcode-layer')).toBeNull();
            expect(otherEscape).not.toHaveBeenCalled();

            pressEscape();
            expect(otherEscape).toHaveBeenCalledTimes(1);
        } finally {
            document.removeEventListener('keydown', listener);
        }
    });

    /**
     * @scenario host=modal, mode=pc_layer
     * @effects focus_returns_to_trigger
     */
    it('레이어를 닫기 단추로 닫거나 주소를 골라 닫아도 초점이 「주소 검색」 버튼으로 돌아온다', async () => {
        const onClose = vi.fn();
        render(<AddressModal ModalComponent={BasicModal as React.FC<any>} onClose={onClose} />);

        // 닫기 단추
        const trigger = await openFromModal({ displayModePc: 'layer' });
        const closeButton = document.querySelector<HTMLButtonElement>('#daum-postcode-overlay button')!;
        closeButton.focus();
        closeButton.click();

        expect(document.getElementById('daum-postcode-overlay')).toBeNull();
        expect(document.activeElement).toBe(trigger);

        // 주소 선택
        await openFromModal({ displayModePc: 'layer' });
        document.body.focus();
        instances[instances.length - 1].config.oncomplete({ zonecode: '06236', roadAddress: '서울 강남구 테헤란로 1' });

        expect(document.getElementById('daum-postcode-overlay')).toBeNull();
        expect(document.activeElement).toBe(trigger);
        expect(onClose).not.toHaveBeenCalled();
    });
});
