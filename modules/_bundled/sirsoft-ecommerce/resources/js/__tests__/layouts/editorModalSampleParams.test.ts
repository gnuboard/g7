/**
 * @file editorModalSampleParams.test.ts
 * @description 레이아웃 편집기에서 쇼핑몰 관리 창이 표본 상태로 채워져 열리는지(번역 인자 기준)
 *
 * 편집기 모달 목록 115개를 하나씩 열어 보니(2026-10-07) 몇 창이 문구 자리 표시자를 그대로 드러내거나 빈 채로 열렸다 —
 * 「{{number}} 신청서의 환불을 실행합니다」·「선택한 상품을 {{status}} 상태로…」·「'{{name}}' 프리셋을…」(클레임),
 * 「'false' 프리셋을 삭제…」(주문·상품 목록), 「'{name}' 공통정보를 복사…」, 본문 없는 「진행 방식 변경」,
 * 직권 실행 창의 대상·건수. 창 본문 문구의 인자는 목록 행 클릭 같은 앞선 동작이 `_global` 에 담는 값이라, 편집기는
 * `editor-spec.json` 의 창 상태 표본으로 그 값을 채워야 한다. 표본이 없거나 인자를 거짓값(false)·빈 문자열로 주면
 * 운영 화면에서는 생기지 않는 깨진 문구가 편집 화면에만 보인다.
 *
 * 쇼핑몰 관리 창 마크업에서 `|인자={{… _global …}}` 꼴의 번역 인자를 모두 찾아, 같은 창 id 의 기본 표본 상태로
 * 식을 계산했을 때 빈 값이 아닌 글자·숫자가 나오는지 본다. 같은 id 를 쓰는 창이 여럿이면 표본 하나가 모두를 채워야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve, relative } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');
const ADMIN_PARTIALS = join(MODULE_ROOT, 'resources/layouts/admin/partials');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/**
 * 디렉터리 아래 마크업 파일 경로를 모두 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @param acc 누적 목록
 * @return 마크업 파일 경로 목록
 */
function markupFiles(dir: string, acc: string[] = []): string[] {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            markupFiles(full, acc);
        } else if (full.endsWith('.g7.html')) {
            acc.push(full);
        }
    }

    return acc;
}

/**
 * 창 id 의 표본 전역 상태들을 찾습니다(기본 상태와 대안 상태 — 경고 줄처럼 조건부로만 보이는 문구는 대안 상태가 채운다).
 *
 * @param modalId 창 id
 * @return 표본 전역 상태 목록(없으면 공용 표본 하나)
 */
function sampleGlobalsFor(modalId: string): Json[] {
    const found: Json[] = [];
    const walk = (node: any): void => {
        if (Array.isArray(node)) {
            node.forEach(walk);

            return;
        }
        if (node && typeof node === 'object') {
            if (node.scope?.kind === 'modal' && node.scope?.match === modalId && Array.isArray(node.items)) {
                found.push(node);
            }
            Object.values(node).forEach(walk);
        }
    };
    walk(spec.states);
    const items: Json[] = found.flatMap((s) => s.items ?? []);
    if (items.length === 0) {
        return [{ ...(spec.sampleGlobal ?? {}) }];
    }

    return items.map((item) => ({ ...(spec.sampleGlobal ?? {}), ...(item?.initialState?.global ?? {}) }));
}

/**
 * 다국어 값을 한국어 글자로 고릅니다(편집기 `$localized` 와 같은 뜻).
 *
 * @param value 다국어 객체 또는 글자
 * @return 글자 값
 */
function localized(value: any): any {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value.ko ?? Object.values(value)[0];
    }

    return value;
}

type ParamCase = { file: string; modalId: string; param: string; expr: string };

const cases: ParamCase[] = [];
for (const file of markupFiles(ADMIN_PARTIALS)) {
    const text = readFileSync(file, 'utf8');
    const modalId = text.match(/<modal\b[^>]*\bdata-ref="([^"]+)"/)?.[1];
    if (!modalId) {
        continue;
    }
    for (const m of text.matchAll(/\|([a-z_]+)=\{\{((?:(?!\}\}).)*_global(?:(?!\}\}).)*)\}\}/g)) {
        cases.push({ file: relative(ADMIN_PARTIALS, file), modalId, param: m[1], expr: m[2] });
    }
}

describe('편집기 쇼핑몰 관리 창 — 번역 인자가 표본으로 채워진다', () => {
    /**
     * @scenario case=c01
     * @effects modal_sample_params_render
     */
    it('검사할 번역 인자가 있다', () => {
        expect(cases.length).toBeGreaterThan(10);
    });

    /**
     * @scenario case=c02
     * @effects modal_sample_params_render
     */
    it.each(cases)('$modalId ($file) 의 |$param 인자', ({ modalId, expr }) => {
        const values: any[] = [];
        for (const global of sampleGlobalsFor(modalId)) {
            try {
                // eslint-disable-next-line no-new-func
                values.push(new Function('_global', '$localized', `return (${expr});`)(global, localized));
            } catch {
                // `_global` 밖의 문맥(_local·route 등)을 함께 읽는 식은 이 검사의 대상이 아니다
                return;
            }
        }
        const filled = values.some((v) => (typeof v === 'string' || typeof v === 'number') && String(v).trim() !== '');
        expect(filled, `표본 값: ${JSON.stringify(values)}`).toBe(true);
    });

    /**
     * @scenario case=c03
     * @effects modal_sample_params_render
     */
    it('리뷰 이미지 미리보기 창 표본이 보여 줄 그림 주소를 채운다(빈 주소면 깨진 그림)', () => {
        const filled = sampleGlobalsFor('modal_image_preview').some((g) => {
            const url = (g.reviewForModal?.images ?? [])[Number(g.reviewImageIndex)]?.download_url;

            return typeof url === 'string' && url.trim() !== '';
        });
        expect(filled).toBe(true);
    });

    /**
     * @scenario case=c04
     * @effects modal_sample_params_render
     */
    it('표본 상태 이름에 쓴 모듈 번역 키가 한국어·영어 문구에 있다', () => {
        const labels = new Set<string>();
        JSON.stringify(spec.states).replace(/"label":"\$t:sirsoft-ecommerce\.([^"]+)"/g, (_m, key: string) => {
            labels.add(key);

            return '';
        });
        for (const locale of ['ko', 'en']) {
            const lang: Json = JSON.parse(readFileSync(join(MODULE_ROOT, `resources/lang/${locale}.json`), 'utf8'));
            for (const key of labels) {
                const value = key.split('.').reduce((acc: any, k) => (acc == null ? undefined : acc[k]), lang);
                expect(typeof value, `${locale}: sirsoft-ecommerce.${key}`).toBe('string');
            }
        }
    });
});
