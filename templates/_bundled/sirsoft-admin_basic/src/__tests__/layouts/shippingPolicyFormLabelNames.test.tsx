// e2e:allow 이커머스 전수 실측 후속 B7 — 전용 Chrome 접근성 트리로 배송정책 등록 화면 재실측(이력 「Chrome MCP 정밀 점검 매트릭스」). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file shippingPolicyFormLabelNames.test.tsx
 * @description 배송정책 등록 화면의 입력칸이 보이는 라벨 이름으로 읽히는지 — 이 템플릿의 실제 basic 부품으로 그린다
 *
 * 배송정책 폼은 `<label class="form-label">우편번호</label><input …>` 처럼 라벨과 칸을 형제로만 둔다.
 * 교환·반품 회수지의 우편번호·기본주소·상세주소와 배송비·반품/교환 배송비 숫자칸은 안내 글자도 없어
 * 화면 낭독기가 이름 없는 칸으로 읽었다(이커머스 전수 실측 S5, 2026-10-08). 부품이 앞 라벨을 잇는
 * 규칙을 이 화면으로 고정한다 — 레이아웃은 이커머스 모듈의 구운 `.json` 을 그대로 읽는다.
 *
 * @vitest-environment jsdom
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// 엔진(코어)은 저장소 루트의 react 를, 이 템플릿의 부품은 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 —
// 훅을 쓰는 실제 부품을 엔진 아래에서 그리면 「Invalid hook call」 이 난다. 설정에서 전역 alias 로 합치면 다른
// 레이아웃 테스트가 깨지므로(DataGrid.test.tsx 주석 실측) 이 파일에서만 템플릿 쪽 react 를 루트 사본으로 바꿔 낀다.
vi.mock('react', async () => {
    const p = await import('path');
    const u = await import('url');
    const dir = p.dirname(u.fileURLToPath(import.meta.url));

    return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../../node_modules/react/index.js')).href);
});
import { screen, within } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import * as basic from '../../components/basic';

const here = path.dirname(fileURLToPath(import.meta.url));
/** 저장소 루트 (`templates/_bundled/sirsoft-admin_basic/src/__tests__/layouts` 기준 여섯 단계 위) */
const ROOT = path.resolve(here, '../../../../../..');
const MODULE = path.join(ROOT, 'modules/_bundled/sirsoft-ecommerce');
const PARTIALS = path.join(MODULE, 'resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form');

/**
 * 이커머스 모듈의 구운 JSON 을 읽는다.
 *
 * @param file 절대 경로
 * @returns 파싱된 JSON
 */
function readJson(file: string): any {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

const mainLayout = readJson(path.join(MODULE, 'resources/layouts/admin/admin_ecommerce_shipping_policy_form.json'));
const lang = readJson(path.join(MODULE, 'resources/lang/partial/ko/admin/shipping_policy.json'));
const form = lang.form as Record<string, string>;

/** 덮어쓰기 전 레지스트리 원본 (afterEach 에서 되돌린다) */
let savedRegistry: unknown = null;

/**
 * 이 템플릿의 basic 부품 전부를 실제 구현으로 등록한다 — 이름 연결은 부품 안에서 일어나므로 대역을 쓰면 시험이 빈다.
 *
 * @returns 테스트 레지스트리
 */
function setupRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    savedRegistry ??= (registry as any).registry;
    const entries: Record<string, unknown> = {};
    for (const [name, component] of Object.entries(basic)) {
        if (typeof component === 'function' || (component && typeof component === 'object' && '$$typeof' in (component as object))) {
            entries[name] = { component, metadata: { name, type: 'basic' } };
        }
    }
    (registry as any).registry = entries;

    return registry;
}

/**
 * 국가 설정 하나(한국, 고정 배송비)를 가진 신규 등록 화면 상태.
 *
 * @returns `_local` 초기값
 */
function newPolicyState(): Record<string, any> {
    return {
        ...mainLayout.state,
        activeCountryTab: 0,
        showBaseFee: true,
        form: {
            ...(mainLayout.state?.form ?? {}),
            country_settings: [
                {
                    country_code: 'KR',
                    shipping_method: 'parcel',
                    charge_policy: 'fixed',
                    base_fee: 3000,
                    claim_fee_mode: 'fixed',
                    return_fixed_fee: 3000,
                    exchange_fixed_fee: 6000,
                },
            ],
        },
        claimAddresses: { _loaded: true, addresses: { exchange: null, return: null } },
    };
}

/**
 * 배송정책 폼의 파셜 하나를 실제 부품으로 그린다.
 *
 * @param partialFile 파셜 파일명
 * @returns 그려진 컨테이너
 */
async function renderPartial(partialFile: string): Promise<HTMLElement> {
    const partial = readJson(path.join(PARTIALS, partialFile));
    const utils = createLayoutTest({ version: '1.0.0', layout_name: 'shipping_policy_label_probe', components: [partial] } as any, {
        componentRegistry: setupRegistry(),
        locale: 'ko',
        translations: { 'sirsoft-ecommerce': { admin: { shipping_policy: lang } } },
        // 신규 등록 화면은 목록 응답(`policy_abilities`)의 수정 권한이 있어야 회수지 입력 묶음을 연다(`lacksUpdate`,
        // 응답 전·권한 없음은 감춤 — 회수지는 정책 생성 뒤 수정 권한으로 따로 저장된다). 수정 권한이 있는 관리자로 그린다.
        initialData: {
            _computedDefinitions: mainLayout.computed,
            policy_abilities: { data: { abilities: { can_create: true, can_update: true } } },
        },
        initialState: { _local: newPolicyState() },
    });
    const { container } = await utils.render();

    return container;
}

/**
 * 컨테이너 안에서 id 가 두 번 이상 쓰였는지 센다.
 *
 * @param root 컨테이너
 * @returns 겹친 id 목록
 */
function duplicateIds(root: HTMLElement): string[] {
    const counts = new Map<string, number>();
    root.querySelectorAll('[id]').forEach((el) => counts.set(el.id, (counts.get(el.id) ?? 0) + 1));

    return [...counts.entries()].filter(([, n]) => n > 1).map(([id]) => id);
}

/**
 * `aria-labelledby` 가 가리키는 id 가 모두 문서에 있는지 본다.
 *
 * @param root 컨테이너
 * @returns 없는 id 목록
 */
function danglingLabelledBy(root: HTMLElement): string[] {
    const missing: string[] = [];
    root.querySelectorAll('[aria-labelledby]').forEach((el) => {
        for (const id of (el.getAttribute('aria-labelledby') ?? '').split(/\s+/).filter(Boolean)) {
            if (!document.getElementById(id)) missing.push(id);
        }
    });

    return missing;
}

describe('배송정책 등록 — 입력칸이 라벨 이름으로 읽힌다', () => {
    beforeEach(() => {
        savedRegistry = null;
    });

    afterEach(() => {
        if (savedRegistry) (ComponentRegistry.getInstance() as any).registry = savedRegistry;
    });

    /**
     * @scenario section=claim_addresses
     * @effects shipping_policy_claim_address_named
     */
    it('교환·반품 회수지의 우편번호·기본주소·상세주소 칸', async () => {
        const container = await renderPartial('_partial_claim_addresses.json');
        const exchange = container.querySelector('[id="claim_address_block_exchange"]') as HTMLElement;
        const ret = container.querySelector('[id="claim_address_block_return"]') as HTMLElement;

        for (const block of [exchange, ret]) {
            expect(within(block).getByRole('textbox', { name: form.claim_address_zipcode })).toBeInTheDocument();
            expect(within(block).getByRole('textbox', { name: new RegExp(`^${form.claim_address_address1}`) })).toBeInTheDocument();
            expect(within(block).getByRole('textbox', { name: form.claim_address_address2 })).toBeInTheDocument();
            // 안내 글자가 있던 칸도 이제 라벨 이름으로 읽힌다(안내 글자는 설명으로 남는다)
            expect(within(block).getByRole('textbox', { name: new RegExp(`^${form.claim_address_recipient_name}`) })).toBeInTheDocument();
            expect(within(block).getByRole('textbox', { name: form.claim_address_phone })).toBeInTheDocument();
        }
        expect(duplicateIds(container)).toEqual([]);
        expect(danglingLabelledBy(container)).toEqual([]);
    });

    /**
     * @scenario section=charge_settings
     * @effects shipping_policy_fee_named
     */
    it('국가별 배송비 — 부과 정책 선택 상자와 배송비 숫자칸', async () => {
        const container = await renderPartial('_partial_charge_settings.json');

        expect(screen.getByRole('spinbutton', { name: form.base_fee })).toBeInTheDocument();
        // 선택 상자 버튼은 라벨 + 고른 값으로 읽힌다
        const policyButton = screen.getByRole('button', { name: new RegExp(`^${form.charge_policy}`) });
        expect(policyButton).toBeInTheDocument();
        expect(duplicateIds(container)).toEqual([]);
        expect(danglingLabelledBy(container)).toEqual([]);
    });

    /**
     * @scenario section=claim_country_fee
     * @effects shipping_policy_fee_named
     */
    it('반품·교환 배송비 숫자칸, 산정 방식 라디오는 제 이름을 지킨다', async () => {
        const container = await renderPartial('_partial_claim_country_fee.json');

        expect(screen.getByRole('spinbutton', { name: form.return_fixed_fee })).toBeInTheDocument();
        expect(screen.getByRole('spinbutton', { name: form.exchange_fixed_fee })).toBeInTheDocument();
        // 라디오는 감싼 라벨(선택지 글자)로 읽히던 이름을 그대로 유지한다 — 그룹 라벨로 덮지 않는다
        for (const radio of screen.getAllByRole('radio')) {
            expect(radio.getAttribute('aria-labelledby')).toBeNull();
        }
        expect(duplicateIds(container)).toEqual([]);
        expect(danglingLabelledBy(container)).toEqual([]);
    });
});
