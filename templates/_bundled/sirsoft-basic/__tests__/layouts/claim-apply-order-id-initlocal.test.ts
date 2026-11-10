/**
 * @file claim-apply-order-id-initlocal.test.ts
 * @description 회원 클레임 신청서 셸의 주문 데이터소스 initLocal 이 주문 ID 를 실제로 읽는다 (#869)
 *
 * 엔진은 데이터소스 initLocal 의 경로를 응답 본문이 아니라 한 겹 벗긴 값(`data.data ?? data` — 주문 객체)에 대고 읽는다
 * (TemplateApp.processInitOptions). 신청 셸은 `claimOrderId` 를 `data.id` 로 적어 주문 객체의 `data.id`(없음)를 읽었고,
 * 그래서 이 항목은 언제나 undefined 를 내는 죽은 설정이었다. 같은 칸의 `claimApiBase` 는 식(`{{... + data.id}}`)이라
 * 식 문맥의 `data` 가 주문 객체여서 맞게 섰다. 주문 상세 셸(`mypage/orders/show`)은 같은 값을 `id` 로 적는다.
 *
 * @scenario-manifest tests/scenarios/claim-apply-order-id-initlocal.yaml
 *
 * 잰 효과는 각 시험의 주석에 적었다(매니페스트 effects 2종).
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = path.resolve(__dirname, '../../layouts');
const read = (file: string) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));

/**
 * 엔진 initLocal 의 일반 경로 읽기와 같은 규칙(점 경로)으로 값을 꺼낸다.
 *
 * @param obj 기준 값
 * @param dotPath 점 경로
 * @returns 찾은 값(없으면 undefined)
 */
const pick = (obj: any, dotPath: string): any => dotPath.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), obj);

/** 주문 조회 응답 본문 — 엔진은 `body.data ?? body` 를 initLocal 기준으로 쓴다 */
const body = { success: true, message: 'ok', data: { id: 481, order_number: 'O-481' } };
const engineBase = body.data ?? body;

describe('회원 클레임 신청서 — 주문 데이터소스 initLocal 의 주문 ID', () => {
    /**
     * @scenario shell=member_apply
     * @effects claim_apply_order_id_initlocal_reads_order_object
     */
    it('claimOrderId 경로가 엔진 기준값(주문 객체)에서 주문 ID 를 읽는다', () => {
        const apply = read('mypage/claims/apply.json');
        const order = apply.data_sources.find((d: any) => d.id === 'order');

        expect(pick(engineBase, order.initLocal.claimOrderId)).toBe(481);
    });

    /**
     * @scenario shell=member_apply_vs_order_show
     * @effects claim_apply_initlocal_matches_order_show
     */
    it('주문 상세 셸과 같은 경로로 주문 ID 를 읽는다', () => {
        const apply = read('mypage/claims/apply.json').data_sources.find((d: any) => d.id === 'order');
        const show = read('mypage/orders/show.json').data_sources.find((d: any) => d.id === 'order');

        expect(apply.initLocal.claimOrderId).toBe(show.initLocal.claimOrderId);
    });
});
