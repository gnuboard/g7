// e2e:allow 레이아웃 정적 속성(aria-label 유무) 단언 전용 — 브라우저 축은 세션의 전수 크롤 재측정이 덮는다(시나리오 admin-range-input-names.yaml).
/**
 * @file adminRangeInputNames.test.ts
 * @description 관리자 레이아웃의 기간 입력칸(같은 부모 아래 날짜·일시 칸 둘 이상)이 칸마다 이름을 갖는지
 *
 * 관리자 템플릿은 라벨 바로 뒤에 입력칸이 하나일 때만 그 라벨을 칸 이름으로 잇는다. 라벨 하나 뒤에
 * 「시작일 ~ 종료일」 처럼 칸이 둘 이상 오면 어느 칸에도 잇지 않으므로(엉뚱한 칸에 이어 붙이던 결함을 막으려는 것이다),
 * 그런 칸은 화면 낭독기가 「날짜」 라고만 읽는다(이커머스 전수 실측, 2026-10-09 — 적립금 내역·쿠폰·주문 클레임 필터·
 * 리뷰·찜·장바구니·문의·회원 배송지·쿠폰 등록 화면). 칸마다 「{라벨} 시작일」·「{라벨} 종료일」 이름을 직접 단다.
 *
 * 모집단은 레이아웃 JSON 전체에서 파생한다 — 새 화면의 기간 칸에도 규칙이 그대로 걸린다.
 *
 * 기간 칸만이 아니다 — 라벨 하나 뒤에 날짜 기준·통화·단위·위치 선택칸이 숫자칸·날짜칸과 나란히 오면 그 선택칸도
 * 이름이 없다(정적 전수 20칸, 2026-10-09). 관리자 템플릿의 라벨 잇기 규칙을 시험 안 함수로 옮겨 관리자 레이아웃·
 * 관리자 확장 전체에서 「라벨 하나 뒤 칸 둘 이상이면 칸마다 이름(aria-label·aria-labelledby·placeholder·label[for])」을 잠근다.
 *
 * @scenario surface=admin_shared_label
 * @effects admin_controls_after_shared_label_have_own_names
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const LAYOUTS = resolve(__dirname, '../../../layouts');

const RANGE_TYPES = new Set(['date', 'datetime-local']);

/**
 * 디렉터리 아래 레이아웃 JSON 경로를 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @return JSON 경로 목록
 */
function layoutFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return layoutFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

/**
 * 노드가 이름을 가졌는지 봅니다 — aria-label·aria-labelledby·placeholder. data-ref 가 굽는 id 는 이름이 아니다.
 *
 * @param props 노드 props
 * @return 이름 유무
 */
function hasName(props: Record<string, any>): boolean {
    return Boolean(props['aria-label'] || props['aria-labelledby'] || props.placeholder);
}

/**
 * 같은 부모 아래 기간 칸이 둘 이상인데 이름이 없는 칸을 모읍니다.
 *
 * @param node 시작 노드
 * @param file 레이아웃 경로
 * @param acc 누적
 * @return 「파일: 칸」 목록
 */
function unnamedRangeInputs(node: any, file: string, acc: string[] = []): string[] {
    if (Array.isArray(node)) {
        node.forEach((child) => unnamedRangeInputs(child, file, acc));

        return acc;
    }
    if (node === null || typeof node !== 'object') return acc;

    if (Array.isArray(node.children)) {
        const ranges = node.children.filter(
            (c: any) => c?.name === 'Input' && RANGE_TYPES.has(c.props?.type),
        );
        if (ranges.length >= 2) {
            ranges
                .filter((c: any) => !hasName(c.props))
                .forEach((c: any) => acc.push(`${file}: ${c.props.name ?? c.props.value ?? c.props.type}`));
        }
    }

    for (const [key, value] of Object.entries(node)) {
        if (key !== 'props' && value && typeof value === 'object') unnamedRangeInputs(value, file, acc);
    }

    return acc;
}

const CONTROLS = new Set(['Input', 'Select', 'Textarea']);

/**
 * 노드가 이름을 가져야 하는 입력칸인지 봅니다 — Input·Select·Textarea 중 hidden·sr-only 가 아닌 것.
 *
 * @param n 노드
 * @return 입력칸 여부
 */
function isControl(n: any): boolean {
    return Boolean(
        n && CONTROLS.has(n.name) && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(String(n.props?.className ?? '')),
    );
}

/**
 * 노드가 곁 라벨인지 봅니다 — Label, 또는 class 에 form-label·filter-label·text-heading.
 *
 * @param n 노드
 * @return 라벨 여부
 */
function isLabelEl(n: any): boolean {
    return Boolean(n && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(String(n.props?.className ?? ''))));
}

/**
 * 하위(props 제외)에 조건을 만족하는 노드가 있는지 봅니다.
 *
 * @param n 시작 노드
 * @param pred 조건
 * @return 유무
 */
function hasDeep(n: any, pred: (x: any) => boolean): boolean {
    if (n === null || typeof n !== 'object') return false;
    if (Array.isArray(n)) return n.some((x) => hasDeep(x, pred));
    if (pred(n)) return true;

    return Object.entries(n).some(([k, v]) => k !== 'props' && v !== null && typeof v === 'object' && hasDeep(v, pred));
}

/**
 * 라벨 자리인지 봅니다 — 라벨 자신이거나, 입력칸 없이 라벨만 품은 감싸개.
 *
 * @param n 노드
 * @return 라벨 자리 여부
 */
function isLabelSlot(n: any): boolean {
    return isLabelEl(n) || (!hasDeep(n, isControl) && hasDeep(n, isLabelEl));
}

/**
 * 라벨 뒤 형제 하나에서 깊이 3 까지 입력칸을 모읍니다 — 안쪽 Label·라벨 자리를 만나면 그 갈래는 멈춘다.
 *
 * @param n 노드
 * @param depth 깊이
 * @param acc 누적
 * @return 입력칸 목록
 */
function controlsAfterLabel(n: any, depth: number, acc: any[] = []): any[] {
    if (n === null || typeof n !== 'object' || depth > 3) return acc;
    if (isControl(n)) {
        acc.push(n);

        return acc;
    }
    if (n.name === 'Label') return acc;
    const children = Array.isArray(n.children) ? n.children : [];
    if (children.some(isLabelSlot)) return acc;
    children.forEach((c: any) => controlsAfterLabel(c, depth + 1, acc));

    return acc;
}

/**
 * 곁 라벨 하나 뒤(다음 라벨 자리 전까지)에 입력칸이 둘 이상인데 이름이 없는 칸을 모읍니다.
 * 관리자 템플릿이 라벨을 잇지 않는 자리와 같은 규칙이다 — for 있는 라벨, 입력칸을 품은 라벨은 뺀다.
 *
 * @param root 레이아웃 JSON
 * @param file 레이아웃 경로
 * @return 「파일: 칸」 목록
 */
function unnamedControlsAfterSharedLabel(root: any, file: string): string[] {
    const fors = new Set<string>();
    const collectFors = (n: any): void => {
        if (Array.isArray(n)) return n.forEach(collectFors);
        if (n === null || typeof n !== 'object') return;
        const target = n.props?.htmlFor ?? n.props?.for;
        if (n.name === 'Label' && target) fors.add(target);
        Object.entries(n).forEach(([k, v]) => k !== 'props' && collectFors(v));
    };
    collectFors(root);

    const named = (c: any): boolean => hasName(c.props ?? {}) || Boolean(c.props?.id && fors.has(c.props.id));
    const acc: string[] = [];
    const walk = (n: any): void => {
        if (Array.isArray(n)) return n.forEach(walk);
        if (n === null || typeof n !== 'object') return;
        const children: any[] = Array.isArray(n.children) ? n.children : [];
        children.forEach((c, i) => {
            if (!isLabelSlot(c) || c.props?.htmlFor || c.props?.for || hasDeep(c, isControl)) return;
            const range: any[] = [];
            for (let k = i + 1; k < children.length && !isLabelSlot(children[k]); k++) range.push(children[k]);
            const controls = range.flatMap((r) => controlsAfterLabel(r, 1));
            if (controls.length >= 2) {
                controls
                    .filter((x) => !named(x))
                    .forEach((x) => acc.push(`${file}: ${x.name} ${x.props?.name ?? x.props?.value ?? x.props?.type}`));
            }
        });
        Object.entries(n).forEach(([k, v]) => k !== 'props' && v !== null && typeof v === 'object' && walk(v));
    };
    walk(root);

    return acc;
}

describe('관리자 기간 입력칸 이름', () => {
    // @scenario surface=admin_filter_range
    // @effects admin_range_inputs_have_own_names
    it('같은 부모 아래 날짜·일시 칸이 둘 이상이면 칸마다 이름이 있다', () => {
        const files = layoutFiles(LAYOUTS);
        expect(files.length).toBeGreaterThan(0);

        const missing = files.flatMap((path) =>
            unnamedRangeInputs(JSON.parse(readFileSync(path, 'utf-8')), relative(LAYOUTS, path).replace(/\\/g, '/')),
        );

        expect(missing).toEqual([]);
    });

    // @scenario surface=admin_filter_range
    // @effects admin_range_inputs_have_own_names
    it('기간 칸 이름은 공용 「{라벨} 시작일·종료일」 문구로 화면의 라벨을 감싼다', () => {
        const path = join(LAYOUTS, 'admin/partials/admin_ecommerce_mileage_transaction_index/_filters.json');
        const labels: string[] = [];
        const walk = (n: any): void => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n === null || typeof n !== 'object') return;
            if (n.name === 'Input' && n.props?.type === 'date') labels.push(n.props['aria-label']);
            Object.entries(n).forEach(([k, v]) => k !== 'props' && walk(v));
        };
        walk(JSON.parse(readFileSync(path, 'utf-8')));

        expect(labels).toEqual([
            "$t:common.range_start|label={{$t('sirsoft-ecommerce.admin.mileage_transactions.filter.label_date')}}",
            "$t:common.range_end|label={{$t('sirsoft-ecommerce.admin.mileage_transactions.filter.label_date')}}",
        ]);
    });

    // @scenario surface=admin_value_unit_pair
    // @effects admin_value_unit_inputs_have_own_names
    it.each([
        [
            'admin/partials/admin_ecommerce_product_form/_partial_product_options.json',
            [
                ['Input', '$t:sirsoft-ecommerce.admin.product.options.mileage_value_label'],
                ['Select', '$t:sirsoft-ecommerce.admin.product.options.mileage_type_label'],
                ['Input', '$t:sirsoft-ecommerce.admin.product.options.mileage_value_label'],
                ['Select', '$t:sirsoft-ecommerce.admin.product.options.mileage_type_label'],
            ],
            (n: any) => /mileage_(value|type)/.test(String(n.props?.value ?? '')),
        ],
        [
            'admin/partials/admin_ecommerce_settings/_tab_mileage_basic_card.json',
            [
                ['Select', '$t:sirsoft-ecommerce.admin.mileage_settings.basic.earn_trigger'],
                ['Input', '$t:sirsoft-ecommerce.admin.mileage_settings.basic.earn_delay_days'],
            ],
            (n: any) => ['mileage.earn_trigger', 'mileage.earn_delay_days'].includes(n.props?.name),
        ],
    ])('라벨 하나 뒤 「숫자칸 + 단위·시점 선택」 짝은 칸마다 이름이 있다 — %s', (file, expected, pick) => {
        const found: string[][] = [];
        const walk = (n: any): void => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n === null || typeof n !== 'object') return;
            if ((n.name === 'Input' || n.name === 'Select') && pick(n)) found.push([n.name, n.props['aria-label']]);
            Object.entries(n).forEach(([k, v]) => k !== 'props' && walk(v));
        };
        walk(JSON.parse(readFileSync(join(LAYOUTS, file), 'utf-8')));

        expect(found).toEqual(expected);
    });

    // @scenario surface=admin_extension_select
    // @effects admin_extension_selects_have_own_names
    it('관리자 확장이 덧붙이는 선택칸은 곁 라벨 없이도 자기 이름이 있다', () => {
        const extensions = resolve(__dirname, '../../../extensions');
        const files = readdirSync(extensions).filter((f) => f.startsWith('admin') && f.endsWith('.json'));
        expect(files.length).toBeGreaterThan(0);

        const missing: string[] = [];
        files.forEach((file) => {
            const walk = (n: any): void => {
                if (Array.isArray(n)) return n.forEach(walk);
                if (n === null || typeof n !== 'object') return;
                if (n.name === 'Select' && !hasName(n.props ?? {})) missing.push(`${file}: ${n.props?.name}`);
                Object.entries(n).forEach(([k, v]) => k !== 'props' && walk(v));
            };
            walk(JSON.parse(readFileSync(join(extensions, file), 'utf-8')));
        });

        expect(missing).toEqual([]);
    });

    // @scenario surface=admin_shared_label
    // @effects admin_controls_after_shared_label_have_own_names
    it('관리자 레이아웃·관리자 확장 전체에서 라벨 하나 뒤 칸이 둘 이상이면 칸마다 이름이 있다', () => {
        const extensions = resolve(__dirname, '../../../extensions');
        const files = [
            ...layoutFiles(join(LAYOUTS, 'admin')).map((path) => [path, relative(LAYOUTS, path)]),
            ...readdirSync(extensions)
                .filter((f) => f.startsWith('admin') && f.endsWith('.json'))
                .map((f) => [join(extensions, f), `extensions/${f}`]),
        ];
        expect(files.length).toBeGreaterThan(100);

        const missing = files.flatMap(([path, rel]) =>
            unnamedControlsAfterSharedLabel(JSON.parse(readFileSync(path, 'utf-8')), rel.replace(/\\/g, '/')),
        );

        expect(missing).toEqual([]);
    });
});
