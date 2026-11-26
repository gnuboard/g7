// e2e:allow 콘솔 422 는 자격증명이 없는 서버에서만 보인다 — 이 시험은 실제 조각의 마운트 동작을 엔진 렌더로 돌려 요청 수를 센다.
/**
 * @description 알림 설정 [편집] 창 — 알림톡이 아닌 채널(메일, 사이트 알림)을 열면 카카오 조회(카테고리, 발신프로필)를 부르지 않는다
 *
 * 조각 루트의 `if`(channel === 'alimtalk')는 그리기만 가른다. 엔진은 `if` 가 거짓이어도 그 노드의 lifecycle.onMount 를 돌린다
 * (트러블슈팅 components-misc 사례 1). 그래서 메일 템플릿 [편집] 을 열 때마다 마운트 시퀀스가 카테고리, 발신프로필을 다시 받았고,
 * 비즈뿌리오 자격증명이 없는 서버에서는 두 요청이 422 로 끝나 브라우저 콘솔에 두 줄이 남았다(8차 2부 관찰 7).
 * 자격증명이 있는 서버에서는 편집 창을 열 때마다 카카오 쪽 조회 두 번이 나갔다. 마운트 시퀀스가 스스로 채널을 확인한다.
 *
 * @scenario-manifest tests/scenarios/edit-modal-non-alimtalk-no-kakao-lookup-alpha4.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import sections from '../../../extensions/notification_template_form_sections.json';

afterEach(() => {
    document.body.innerHTML = '';
});

/**
 * 조각 루트(자식 없이 `if` 와 lifecycle 만)를 그린 화면을 띄우고, 마운트 시퀀스가 다시 받은 데이터소스 id 를 돌려준다.
 *
 * 화면 시험 도구는 그릴 때 `window.G7Core` 를 새로 만든다. 데이터소스 다시 받기(`G7Core.dataSource.refetch`)를 세려고
 * 그 객체가 들어오는 순간 받기 함수를 끼운다.
 *
 * @param channel 편집 창이 연 템플릿의 채널
 * @returns 다시 받은 데이터소스 id 목록
 */
async function refetchedOnMount(channel: string): Promise<string[]> {
    const root = (sections as any).components[0];
    const node = JSON.parse(
        JSON.stringify({ id: root.id, type: root.type, name: root.name, if: root.if, lifecycle: root.lifecycle, props: {} })
            .split('extensionPointProps.').join('_global.ep.'),
    );
    const refetched: string[] = [];
    let g7core: any;
    Object.defineProperty(window, 'G7Core', {
        configurable: true,
        get: () => g7core,
        set: (value) => {
            g7core = value;
            if (value && typeof value === 'object') {
                value.dataSource = { ...(value.dataSource ?? {}), refetch: vi.fn(async (id: string) => { refetched.push(id); }) };
            }
        },
    });

    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/bz-non-alimtalk-mount', components: [node] } as any,
        {
            locale: 'ko',
            componentRegistry: createMockComponentRegistryWithBasics() as any,
            initialState: { _global: { ep: { channel, definition: { type: 'welcome', name: { ko: '회원가입 환영' }, templates: [] } } } },
            initialData: { bizppurioTemplates: { data: { templates: {} } } },
        },
    );
    try {
        await utils.render();
        await new Promise((r) => setTimeout(r, 50));
    } finally {
        utils.cleanup();
        delete (window as any).G7Core;
    }

    return refetched;
}

describe('알림톡이 아닌 채널의 [편집] 창은 카카오 조회를 부르지 않는다', () => {
    // @scenario channel=mail
    // @scenario channel=database
    // @effects non_alimtalk_edit_modal_skips_kakao_lookups
    it.each(['mail', 'database'])('%s 템플릿 창 — 카테고리, 발신프로필 요청 0', async (channel) => {
        expect(await refetchedOnMount(channel)).toEqual([]);
    });

    // @scenario channel=alimtalk
    // @effects alimtalk_edit_modal_still_loads_kakao_lookups
    it('alimtalk 템플릿 창 — 카테고리와 발신프로필을 한 번씩 받는다', async () => {
        expect(await refetchedOnMount('alimtalk')).toEqual(['bizppurioCategories', 'bizppurioProfiles']);
    });
});
