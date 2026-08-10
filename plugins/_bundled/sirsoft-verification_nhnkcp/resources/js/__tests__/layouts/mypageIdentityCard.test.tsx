/**
 * 마이페이지 본인확인 카드 렌더링 테스트.
 *
 * 확장 조각(injections)의 컴포넌트를 최소 레이아웃 슬롯에 얹어 실제로 렌더한다 —
 * 인증 정보가 있을 때/없을 때의 카드 분기, 마스킹된 값 표시, 원문 키 미노출을 잠근다.
 *
 * @since 1.0.0
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import cardExtension from '../../../extensions/mypage_identity_card.json';
import pluginKo from '../../../lang/ko.json';

const TRANSLATIONS = { 'sirsoft-verification_nhnkcp': pluginKo };

/** 확장 조각의 주입 컴포넌트를 슬롯에 얹은 최소 레이아웃 */
const CARD_LAYOUT = {
    layout_name: 'mypage_identity_card_fragment',
    data_sources: (cardExtension as any).data_sources,
    slots: {
        content: (cardExtension as any).injections[0].components,
    },
};

let registry: MockComponentRegistry;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
});

afterEach(() => {
    vi.clearAllMocks();
});

/**
 * 카드 조각을 지정한 API 응답으로 렌더한다.
 *
 * @param record `/me/identity/nhnkcp` 응답의 data (null 이면 미인증 상태)
 */
async function renderCard(record: Record<string, unknown> | null) {
    const utils = createLayoutTest(CARD_LAYOUT as any, {
        componentRegistry: registry as any,
        translations: TRANSLATIONS,
        locale: 'ko',
        auth: { isAuthenticated: true, user: { id: 1, name: '테스터' } },
        initialData: { nhnkcpRecord: { data: record } },
    });
    utils.mockApi('nhnkcpRecord', { response: { data: record } });
    const { container } = await utils.render();
    return { utils, container };
}

const VERIFIED_RECORD = {
    method: 'NHN KCP 휴대폰 본인확인',
    provider_label: 'NHN KCP',
    verified_at: '2026-07-28 14:00:00',
    name_masked: '홍*동',
    birthday_masked: '1990-**-**',
    phone_masked: '010-****-1234',
    is_adult: true,
    is_adult_known: true,
    is_foreigner: false,
};

/** 인증기관이 생년월일을 주지 않은 응답 — 성인 여부를 계산할 수 없다. */
const RECORD_WITHOUT_BIRTHDAY = {
    ...VERIFIED_RECORD,
    birthday_masked: '',
    is_adult: false,
    is_adult_known: false,
};

describe('마이페이지 본인확인 카드 렌더링', () => {
    // @scenario record_exists=true,auth=authenticated
    // @effects card_renders_masked_fields
    it('인증 정보가 있으면 마스킹된 값이 표시된다', async () => {
        const { container, utils } = await renderCard(VERIFIED_RECORD);

        expect(container.textContent).toContain('본인확인 정보');
        expect(container.textContent).toContain('홍*동');
        expect(container.textContent).toContain('010-****-1234');
        expect(container.textContent).toContain('1990-**-**');
        expect(container.textContent).toContain('2026-07-28 14:00:00');

        utils.cleanup?.();
    });

    // @scenario record_exists=true,auth=authenticated
    // @effects card_shows_provider_badge
    it('인증 정보가 있으면 제공사 배지가 카드 상단에 표시된다', async () => {
        const { container, utils } = await renderCard(VERIFIED_RECORD);

        expect(container.textContent).toContain('NHN KCP');

        utils.cleanup?.();
    });

    // @scenario record_exists=true,auth=authenticated
    // @effects card_shows_adult_status
    it('성인 확인 결과가 표시된다', async () => {
        const { container, utils } = await renderCard(VERIFIED_RECORD);

        expect(container.textContent).toContain('성인 여부');
        expect(container.textContent).toContain('확인됨');

        utils.cleanup?.();
    });

    /**
     * 생년월일을 받지 못하면 성인 여부는 "아님" 이 아니라 "모름" 이다. 두 상태를 같은 문구로
     * 보여주면 성인인 사용자가 미성년으로 잘못 안내된다.
     */
    // @scenario record_exists=true,auth=authenticated
    // @effects card_distinguishes_unknown_adult_from_minor
    it('생년월일이 없으면 미성년이 아니라 확인 불가로 표시된다', async () => {
        const { container, utils } = await renderCard(RECORD_WITHOUT_BIRTHDAY);

        expect(container.textContent).toContain('제공되지 않음');
        expect(container.textContent).toContain('확인 불가 (생년월일 미제공)');
        expect(container.textContent).not.toContain('미확인 (만 19세 미만)');

        utils.cleanup?.();
    });

    // @scenario record_exists=false,auth=authenticated
    // @effects card_shows_empty_state_without_record
    it('인증 정보가 없으면 빈 상태 카드가 표시된다', async () => {
        const { container, utils } = await renderCard(null);

        expect(container.textContent).toContain('아직 본인확인 내역이 없습니다');
        // 인증 완료 카드의 값 행은 렌더되지 않아야 한다
        expect(container.textContent).not.toContain('010-****-1234');

        utils.cleanup?.();
    });

    // @scenario record_exists=true,auth=authenticated
    // @effects card_never_renders_identity_identifiers
    it('식별값(CI/DI)은 어떤 상태에서도 화면에 나타나지 않는다', async () => {
        const { container, utils } = await renderCard(VERIFIED_RECORD);

        expect(container.textContent).not.toContain('CI');
        expect(container.textContent?.includes('DI ')).toBe(false);

        utils.cleanup?.();
    });

    // @scenario record_exists=true,auth=authenticated
    // @effects card_resolves_all_translation_keys
    it('다국어 원문 키가 화면에 남지 않는다', async () => {
        const { container, utils } = await renderCard(VERIFIED_RECORD);

        expect(container.textContent).not.toContain('$t:');
        expect(container.textContent).not.toContain('sirsoft-verification_nhnkcp.card');

        utils.cleanup?.();
    });
});
