/**
 * 우편번호 검색 창 표시 방식 — 카카오 SDK 전환, 페이지 안에 펼치기, 좁은 화면 레이어
 *
 * 회귀 배경:
 * - 레이어 모드가 `width: 500px` 고정이라 폭 360px 휴대폰에서 검색 창 좌우가 잘렸다.
 * - 다음 우편번호 SDK 가 카카오 주소(`t1.kakaocdn.net`)·`kakao.Postcode` 로 옮겨 간다. 옛 `daum.Postcode` 는
 *   당분간 호환되지만 새 이름을 먼저 쓴다. 카카오 지도 SDK 처럼 `window.kakao` 만 있고 `Postcode` 가 없는
 *   경우를 확보로 오판하면 안 된다.
 * - 카카오 가이드는 모바일에서 위에 겹치는 레이어 대신 페이지에 끼워 넣기(embed)를 권한다 — 일반 쇼핑몰처럼
 *   주소 입력 줄 바로 아래에 검색 창을 펼친다.
 * - 표시 방식은 기기별로 고른다: PC(화면 폭 1024px 이상)는 popup·layer·inline, 모바일(엔진
 *   반응형 portable = 1023px 이하, 휴대폰+태블릿)은 inline·fullscreen. 판정은 클릭 시점 화면 폭이다.
 *
 * @scenario-manifest tests/scenarios/postcode-display-modes.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { openPostcodeHandler } from '../handlers/openPostcode';
import {
    DAUM_POSTCODE_SDK_URL,
    getPostcodeConstructor,
    isPostcodeSdkReady,
} from '../handlers/postcodeSdk';

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
 * 결제 화면 배송지와 같은 모양의 주소 입력 묶음을 세운다.
 *
 * 묶음(space-y-2) > 줄(flex: 우편번호 칸 + 확장점 래퍼 > 조각 컨테이너 > 버튼) + 기본주소 칸
 *
 * @return 주요 요소
 */
function mountAddressGroup(): {
    group: HTMLElement;
    row: HTMLElement;
    host: HTMLElement;
    container: HTMLElement;
    button: HTMLButtonElement;
    label: HTMLElement;
} {
    document.body.innerHTML = `
        <div id="group" class="space-y-2">
            <div id="row" class="flex gap-2">
                <input name="zipcode" />
                <div id="host">
                    <div id="container" class="inline-flex">
                        <button id="btn" type="button"><span id="label">주소 검색</span></button>
                    </div>
                </div>
            </div>
            <input name="address" id="address" />
        </div>`;

    return {
        group: document.getElementById('group')!,
        row: document.getElementById('row')!,
        host: document.getElementById('host')!,
        container: document.getElementById('container')!,
        button: document.getElementById('btn') as HTMLButtonElement,
        label: document.getElementById('label')!,
    };
}

/**
 * 엔진이 비동기 핸들러에 넘기는 클릭 이벤트 모양 — React 합성 이벤트는 위임이 끝나면 currentTarget 이 비고,
 * target 은 버튼 안쪽 글자(span)일 수 있다.
 *
 * @param target 실제 클릭된 요소
 * @return 이벤트 모양 객체
 */
function clickEvent(target: Element): any {
    return { type: 'click', currentTarget: null, target };
}

const TRANSLATIONS: Record<string, string> = {
    'sirsoft-daum_postcode.postcode.close': '주소 검색 닫기',
    'sirsoft-daum_postcode.postcode.search': '주소 검색',
};

/**
 * 클릭 시점의 화면 폭을 정한다.
 *
 * @param width 화면 폭(px)
 */
function setViewportWidth(width: number): void {
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
}

describe('우편번호 검색 창 표시 방식', () => {
    let kakaoInstances: FakeInstance[];
    let daumInstances: FakeInstance[];
    let setLocal: ReturnType<typeof vi.fn>;
    let scrollIntoView: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        kakaoInstances = [];
        daumInstances = [];
        setLocal = vi.fn();
        scrollIntoView = vi.fn();
        (Element.prototype as any).scrollIntoView = scrollIntoView;
        setViewportWidth(1280);
        (window as any).kakao = { Postcode: makeFakePostcode(kakaoInstances) };
        (window as any).G7Core = {
            plugin: { getSettings: () => ({}) },
            state: { setLocal },
            t: (key: string) => TRANSLATIONS[key] ?? key,
            assets: { notifyFailure: vi.fn(), clearFailure: vi.fn() },
        };
    });

    afterEach(() => {
        document.body.innerHTML = '';
        document.body.style.overflow = '';
        document.getElementById('g7-postcode-inline-style')?.remove();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        delete (window as any).kakao;
        delete (window as any).daum;
        delete (window as any).G7Core;
        delete (Element.prototype as any).scrollIntoView;
        vi.restoreAllMocks();
    });

    describe('카카오 SDK 전환', () => {
        /**
         * @scenario case=sdk_kakao
         * @effects postcode_sdk_loads_from_kakao_cdn
         */
        it('SDK 주소는 카카오 CDN(t1.kakaocdn.net)이다', () => {
            expect(DAUM_POSTCODE_SDK_URL).toBe('//t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js');
        });

        /**
         * @scenario case=sdk_kakao
         * @effects postcode_prefers_kakao_constructor
         */
        it('kakao.Postcode 만 있어도 확보로 판정하고, 둘 다 있으면 kakao 를 먼저 쓴다', async () => {
            expect(isPostcodeSdkReady()).toBe(true);

            (window as any).daum = { Postcode: makeFakePostcode(daumInstances) };
            expect(getPostcodeConstructor()).toBe((window as any).kakao.Postcode);

            await openPostcodeHandler({ handler: 'openPostcode', params: { displayModePc: 'popup' } }, {} as any);

            expect(kakaoInstances).toHaveLength(1);
            expect(daumInstances).toHaveLength(0);
            expect(kakaoInstances[0].open).toHaveBeenCalledTimes(1);
        });

        /**
         * @scenario case=sdk_daum
         * @effects postcode_falls_back_to_daum_constructor
         */
        it('옛 daum.Postcode 만 있으면 그것을 쓴다', async () => {
            delete (window as any).kakao;
            (window as any).daum = { Postcode: makeFakePostcode(daumInstances) };

            expect(isPostcodeSdkReady()).toBe(true);

            await openPostcodeHandler({ handler: 'openPostcode', params: { displayModePc: 'popup' } }, {} as any);

            expect(daumInstances).toHaveLength(1);
        });

        /**
         * @scenario case=sdk_kakao_without_postcode
         * @effects postcode_sdk_not_ready_without_constructor
         */
        it('카카오 지도 SDK 처럼 kakao 전역에 Postcode 가 없으면 미확보다', () => {
            (window as any).kakao = { maps: {} };

            expect(isPostcodeSdkReady()).toBe(false);
            expect(getPostcodeConstructor()).toBeNull();
        });
    });

    describe('페이지 안에 펼치기(inline)', () => {
        /**
         * @scenario case=inline
         * @effects inline_area_expands_after_address_row
         */
        it('주소 입력 줄 바로 뒤에 폭 100% 영역을 넣고 그 안에 embed 한다', async () => {
            const { row, label, button } = mountAddressGroup();

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(label) } as any
            );

            const area = row.nextElementSibling as HTMLElement;
            expect(area).not.toBeNull();
            expect(area.classList.contains('g7-postcode-inline')).toBe(true);
            expect(area.style.width).toBe('100%');

            expect(kakaoInstances).toHaveLength(1);
            const [embedTarget] = kakaoInstances[0].embed.mock.calls[0];
            expect(area.contains(embedTarget)).toBe(true);
            expect(kakaoInstances[0].config.width).toBe('100%');

            expect(button.getAttribute('aria-expanded')).toBe('true');
            expect(button.getAttribute('aria-controls')).toBe(area.id);
            expect(scrollIntoView).toHaveBeenCalled();
            expect(document.getElementById('daum-postcode-overlay')).toBeNull();
        });

        /**
         * @scenario case=inline
         * @effects inline_area_has_translated_close_button
         */
        it('닫기 단추는 번역된 접근 이름을 갖고, 누르면 영역을 걷고 버튼으로 초점을 돌린다', async () => {
            const { row, button } = mountAddressGroup();

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(button) } as any
            );

            const close = row.nextElementSibling!.querySelector('button') as HTMLButtonElement;
            expect(close.getAttribute('aria-label')).toBe('주소 검색 닫기');
            expect(close.type).toBe('button');

            close.click();

            expect(document.querySelector('.g7-postcode-inline')).toBeNull();
            expect(document.activeElement).toBe(button);
            expect(button.getAttribute('aria-expanded')).toBe('false');
        });

        /**
         * @scenario case=inline
         * @effects inline_area_height_follows_onresize
         */
        it('onresize 로 받은 높이에 맞춰 영역 높이를 바꾼다', async () => {
            const { row, button } = mountAddressGroup();

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(button) } as any
            );

            const [frame] = kakaoInstances[0].embed.mock.calls[0];
            kakaoInstances[0].config.onresize({ width: 320, height: 432 });

            expect((frame as HTMLElement).style.height).toBe('432px');
            expect(row.nextElementSibling!.contains(frame)).toBe(true);
        });

        /**
         * @scenario case=inline
         * @effects inline_area_removed_after_address_selected
         */
        it('주소를 고르면 영역을 걷고 대상 칸에 주소를 쓴다', async () => {
            const { button } = mountAddressGroup();

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(button) } as any
            );

            kakaoInstances[0].config.oncomplete({
                zonecode: '06236',
                roadAddress: '서울 강남구 테헤란로 152',
                sido: '서울',
                sigungu: '강남구',
            });

            expect(document.querySelector('.g7-postcode-inline')).toBeNull();
            expect(setLocal).toHaveBeenCalledWith({
                'shipping.zipcode': '06236',
                'shipping.address': '서울 강남구 테헤란로 152',
                'shipping.region': '서울',
                'shipping.city': '강남구',
            });
            expect(button.getAttribute('aria-expanded')).toBe('false');
        });

        /**
         * @scenario case=inline
         * @effects inline_area_toggles_on_same_button
         */
        it('같은 단추를 다시 누르면 접고, SDK 를 새로 띄우지 않는다', async () => {
            const { button } = mountAddressGroup();
            const action = { handler: 'openPostcode', params: { displayModePc: 'inline' } };

            await openPostcodeHandler(action, { event: clickEvent(button) } as any);
            expect(document.querySelectorAll('.g7-postcode-inline')).toHaveLength(1);

            await openPostcodeHandler(action, { event: clickEvent(button) } as any);

            expect(document.querySelectorAll('.g7-postcode-inline')).toHaveLength(0);
            expect(kakaoInstances).toHaveLength(1);
            expect(button.getAttribute('aria-expanded')).toBe('false');

            await openPostcodeHandler(action, { event: clickEvent(button) } as any);
            expect(document.querySelectorAll('.g7-postcode-inline')).toHaveLength(1);
            expect(kakaoInstances).toHaveLength(2);
        });

        /**
         * @scenario case=inline
         * @effects inline_area_uses_declared_anchor
         */
        it('가까운 조상에 data-postcode-anchor 가 있으면 그 뒤에 펼친다', async () => {
            const { group, button } = mountAddressGroup();
            group.setAttribute('data-postcode-anchor', '');

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(button) } as any
            );

            expect((group.nextElementSibling as HTMLElement)?.classList.contains('g7-postcode-inline')).toBe(true);
        });

        /**
         * @scenario case=inline
         * @effects inline_area_uses_declared_anchor
         */
        it('입력 칸을 가진 조상이 없으면 버튼 조각 컨테이너의 부모 뒤에 펼친다', async () => {
            document.body.innerHTML = `
                <div id="host"><div id="container"><button id="btn" type="button">검색</button></div></div>`;
            const host = document.getElementById('host')!;

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'inline' } },
                { event: clickEvent(document.getElementById('btn')!) } as any
            );

            expect((host.nextElementSibling as HTMLElement)?.classList.contains('g7-postcode-inline')).toBe(true);
        });

        /**
         * @scenario case=inline
         * @effects inline_area_visible_in_dark_mode
         */
        it('다크 모드용 테두리·배경 규칙을 한 번만 싣는다', async () => {
            const { button } = mountAddressGroup();
            const action = { handler: 'openPostcode', params: { displayModePc: 'inline' } };

            await openPostcodeHandler(action, { event: clickEvent(button) } as any);
            await openPostcodeHandler(action, { event: clickEvent(button) } as any);
            await openPostcodeHandler(action, { event: clickEvent(button) } as any);

            const styles = document.querySelectorAll('#g7-postcode-inline-style');
            expect(styles).toHaveLength(1);
            expect(styles[0].textContent).toContain('.dark .g7-postcode-inline');
            expect(styles[0].textContent).toMatch(/\.dark \.g7-postcode-inline\s*\{[^}]*border-color/);
            expect(styles[0].textContent).toMatch(/\.dark \.g7-postcode-inline\s*\{[^}]*background/);
        });

        /**
         * @scenario case=inline_without_event
         * @effects inline_falls_back_to_layer_without_event
         */
        it('클릭 이벤트가 없으면 레이어로 띄운다', async () => {
            mountAddressGroup();

            await openPostcodeHandler({ handler: 'openPostcode', params: { displayModePc: 'inline' } }, {} as any);

            expect(document.querySelector('.g7-postcode-inline')).toBeNull();
            expect(document.getElementById('daum-postcode-overlay')).not.toBeNull();
            expect(kakaoInstances[0].embed).toHaveBeenCalledTimes(1);
        });
    });

    describe('레이어(layer)', () => {
        /**
         * @scenario case=layer
         * @effects layer_fits_narrow_viewport
         */
        it('레이어 크기는 설정값과 화면 크기 중 작은 쪽이다', async () => {
            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'layer', width: 500, height: 600 } },
                {} as any
            );

            const layer = document.getElementById('daum-postcode-layer')!;
            const style = layer.getAttribute('style') ?? '';

            expect(style).toContain('width: min(500px, calc(100vw - 32px))');
            expect(style).toContain('height: min(600px, calc(100vh - 96px))');
            expect(kakaoInstances).toHaveLength(1);
            expect(kakaoInstances[0].embed).toHaveBeenCalledWith(layer);
        });

        /**
         * @scenario case=layer
         * @effects inline_area_has_translated_close_button
         */
        it('레이어 닫기 단추도 번역된 접근 이름을 갖는다', async () => {
            await openPostcodeHandler({ handler: 'openPostcode', params: { displayModePc: 'layer' } }, {} as any);

            const close = document.querySelector('#daum-postcode-overlay button') as HTMLButtonElement;
            expect(close.getAttribute('aria-label')).toBe('주소 검색 닫기');
        });
    });

    describe('기기별 표시 방식 (PC 1024px 이상 · 모바일 portable 1023px 이하)', () => {
        const both = (pc: string, mobile: string) => ({
            handler: 'openPostcode',
            params: { displayModePc: pc, displayModeMobile: mobile },
        });

        /**
         * @scenario case=pc_popup
         * @effects device_mode_follows_viewport_at_click
         */
        it('PC 에서 popup 이면 새 창으로 연다', async () => {
            setViewportWidth(1024);
            const { button } = mountAddressGroup();

            await openPostcodeHandler(both('popup', 'fullscreen'), { event: clickEvent(button) } as any);

            expect(kakaoInstances[0].open).toHaveBeenCalledTimes(1);
            expect(kakaoInstances[0].embed).not.toHaveBeenCalled();
            expect(document.getElementById('daum-postcode-fullscreen')).toBeNull();
        });

        /**
         * @scenario case=pc_layer
         * @effects device_mode_follows_viewport_at_click, layer_fits_narrow_viewport
         */
        it('PC 에서 layer 면 화면 크기에 맞춘 레이어로 띄운다', async () => {
            setViewportWidth(1440);
            const { button } = mountAddressGroup();

            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayModePc: 'layer', displayModeMobile: 'inline', width: 700, height: 800 } },
                { event: clickEvent(button) } as any
            );

            const style = document.getElementById('daum-postcode-layer')!.getAttribute('style') ?? '';
            expect(style).toContain('width: min(700px, calc(100vw - 32px))');
            expect(style).toContain('height: min(800px, calc(100vh - 96px))');
            expect(document.querySelector('.g7-postcode-inline')).toBeNull();
        });

        /**
         * @scenario case=pc_inline
         * @effects device_mode_follows_viewport_at_click, inline_area_expands_after_address_row
         */
        it('PC 에서 inline 이면 주소 입력 줄 아래에 펼친다', async () => {
            setViewportWidth(1920);
            const { row, button } = mountAddressGroup();

            await openPostcodeHandler(both('inline', 'fullscreen'), { event: clickEvent(button) } as any);

            expect((row.nextElementSibling as HTMLElement)?.classList.contains('g7-postcode-inline')).toBe(true);
            expect(document.getElementById('daum-postcode-overlay')).toBeNull();
        });

        /**
         * @scenario case=mobile_inline
         * @effects device_mode_follows_viewport_at_click, inline_area_expands_after_address_row
         */
        it('모바일에서 inline 이면 PC 설정과 상관없이 주소 입력 줄 아래에 펼친다', async () => {
            setViewportWidth(360);
            const { row, button } = mountAddressGroup();

            await openPostcodeHandler(both('popup', 'inline'), { event: clickEvent(button) } as any);

            expect((row.nextElementSibling as HTMLElement)?.classList.contains('g7-postcode-inline')).toBe(true);
            expect(kakaoInstances[0].open).not.toHaveBeenCalled();
        });

        /**
         * @scenario case=mobile_fullscreen
         * @effects device_mode_follows_viewport_at_click, fullscreen_covers_viewport_with_title_and_close
         */
        it('모바일에서 fullscreen 이면 화면 전체 창(제목 + 닫기)에 100% 높이로 embed 한다', async () => {
            setViewportWidth(390);
            const { button } = mountAddressGroup();

            await openPostcodeHandler(both('layer', 'fullscreen'), { event: clickEvent(button) } as any);

            const dialog = document.getElementById('daum-postcode-fullscreen')!;
            expect(dialog).not.toBeNull();
            expect(dialog.getAttribute('role')).toBe('dialog');
            expect(dialog.getAttribute('aria-modal')).toBe('true');
            const style = dialog.getAttribute('style') ?? '';
            expect(style).toContain('position: fixed');
            expect(style).toContain('inset: 0');

            expect(dialog.textContent).toContain('주소 검색');
            const close = dialog.querySelector('button') as HTMLButtonElement;
            expect(close.getAttribute('aria-label')).toBe('주소 검색 닫기');

            const [frame] = kakaoInstances[0].embed.mock.calls[0];
            expect(dialog.contains(frame)).toBe(true);
            expect(kakaoInstances[0].config.height).toBe('100%');
            expect(document.body.style.overflow).toBe('hidden');
            expect(document.getElementById('daum-postcode-overlay')).toBeNull();

            close.click();
            expect(document.getElementById('daum-postcode-fullscreen')).toBeNull();
            expect(document.body.style.overflow).toBe('');
            expect(document.activeElement).toBe(button);
        });

        /**
         * @scenario case=mobile_fullscreen
         * @effects fullscreen_closes_on_select_and_escape
         */
        it('전체 화면 창은 주소를 고르면 닫히고 대상 칸에 쓴다, ESC 로도 닫힌다', async () => {
            setViewportWidth(390);
            const { button } = mountAddressGroup();

            await openPostcodeHandler(both('layer', 'fullscreen'), { event: clickEvent(button) } as any);
            kakaoInstances[0].config.oncomplete({ zonecode: '06236', roadAddress: '서울 강남구 테헤란로 152', sido: '서울', sigungu: '강남구' });

            expect(document.getElementById('daum-postcode-fullscreen')).toBeNull();
            expect(setLocal).toHaveBeenCalledWith(expect.objectContaining({ 'shipping.zipcode': '06236' }));

            await openPostcodeHandler(both('layer', 'fullscreen'), { event: clickEvent(button) } as any);
            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();

            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            expect(document.getElementById('daum-postcode-fullscreen')).toBeNull();
            expect(document.body.style.overflow).toBe('');
        });

        /**
         * @scenario case=mobile_inline
         * @effects inline_falls_back_without_event
         */
        it('모바일 inline 인데 클릭 이벤트가 없으면 전체 화면으로 띄운다', async () => {
            setViewportWidth(360);

            await openPostcodeHandler(both('layer', 'inline'), {} as any);

            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();
            expect(document.getElementById('daum-postcode-overlay')).toBeNull();
        });

        /**
         * @scenario case=boundary
         * @effects device_mode_follows_viewport_at_click
         */
        it('경계는 엔진 portable 상한(1023px)이다 — 1023 은 모바일, 1024 는 PC', async () => {
            setViewportWidth(1023);
            await openPostcodeHandler(both('popup', 'fullscreen'), {} as any);
            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();
            expect(kakaoInstances[0].open).not.toHaveBeenCalled();
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            setViewportWidth(1024);
            await openPostcodeHandler(both('popup', 'fullscreen'), {} as any);
            expect(kakaoInstances[1].open).toHaveBeenCalledTimes(1);
        });

        /**
         * @scenario case=boundary
         * @effects device_mode_follows_viewport_at_click
         */
        it('엔진 반응형 관리자가 있으면 그 portable 범위 상한을 쓴다', async () => {
            (window as any).G7Core.ResponsiveManager = {
                parseRange: (key: string) => (key === 'portable' ? { min: 0, max: 1279 } : null),
            };
            setViewportWidth(1200);

            await openPostcodeHandler(both('popup', 'fullscreen'), {} as any);

            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();
        });

        /**
         * @scenario case=settings_fallback
         * @effects device_modes_fall_back_to_plugin_settings
         */
        it('매개변수가 없으면 플러그인 설정(display_mode_pc·display_mode_mobile)을 쓰고, 그것도 없으면 layer·inline 이다', async () => {
            (window as any).G7Core.plugin.getSettings = () => ({ display_mode_pc: 'popup', display_mode_mobile: 'fullscreen' });
            setViewportWidth(1280);
            await openPostcodeHandler({ handler: 'openPostcode', params: {} }, {} as any);
            expect(kakaoInstances[0].open).toHaveBeenCalledTimes(1);

            setViewportWidth(375);
            await openPostcodeHandler({ handler: 'openPostcode', params: {} }, {} as any);
            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            (window as any).G7Core.plugin.getSettings = () => ({});
            const { row, button } = mountAddressGroup();
            await openPostcodeHandler({ handler: 'openPostcode', params: {} }, { event: clickEvent(button) } as any);
            expect((row.nextElementSibling as HTMLElement)?.classList.contains('g7-postcode-inline')).toBe(true);

            setViewportWidth(1280);
            await openPostcodeHandler({ handler: 'openPostcode', params: {} }, {} as any);
            expect(document.getElementById('daum-postcode-overlay')).not.toBeNull();
        });

        /**
         * @scenario case=settings_fallback
         * @effects device_modes_fall_back_to_plugin_settings
         */
        it('옛 조각이 넘기는 displayMode 는 PC 값으로만 쓰고, 기기에 맞지 않는 값은 버린다', async () => {
            setViewportWidth(1280);
            await openPostcodeHandler({ handler: 'openPostcode', params: { displayMode: 'popup' } }, {} as any);
            expect(kakaoInstances[0].open).toHaveBeenCalledTimes(1);

            await openPostcodeHandler({ handler: 'openPostcode', params: { displayModePc: 'fullscreen' } }, {} as any);
            expect(document.getElementById('daum-postcode-overlay')).not.toBeNull();
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

            setViewportWidth(360);
            await openPostcodeHandler(
                { handler: 'openPostcode', params: { displayMode: 'popup', displayModeMobile: 'layer' } },
                {} as any
            );
            expect(kakaoInstances[2].open).not.toHaveBeenCalled();
            expect(document.getElementById('daum-postcode-fullscreen')).not.toBeNull();
        });
    });
});
