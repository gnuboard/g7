/**
 * 비즈뿌리오 메시징 플러그인 레이아웃 테스트 공통 헬퍼
 *
 * JSON 트리에서 ID/이름 검색 및 핸들러·i18n 키 추출을 위한 유틸리티.
 */

export type AnyNode = Record<string, unknown> & {
    id?: string;
    name?: string;
    type?: string;
    children?: AnyNode[];
    slots?: Record<string, AnyNode[]>;
    modals?: Record<string, AnyNode> | AnyNode[];
    actions?: AnyNode[];
    iteration?: { source?: string; item_var?: string; index_var?: string };
    if?: string;
    props?: Record<string, unknown>;
    text?: string;
};

/**
 * 트리 노드에서 특정 id를 재귀 탐색 (children/slots/modals)
 */
export function findById(node: AnyNode | undefined | null, id: string): AnyNode | null {
    if (!node) return null;
    if (node.id === id) return node;

    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }

    if (node.slots && typeof node.slots === 'object') {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    const found = findById(child, id);
                    if (found) return found;
                }
            }
        }
    }

    if (node.modals) {
        const modalEntries = Array.isArray(node.modals) ? node.modals : Object.values(node.modals);
        for (const m of modalEntries) {
            const found = findById(m as AnyNode, id);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 트리 노드에서 특정 name 컴포넌트를 모두 수집 (children/slots/modals)
 */
export function findAllByName(node: AnyNode | undefined | null, name: string): AnyNode[] {
    const results: AnyNode[] = [];
    if (!node) return results;
    if (node.name === name) results.push(node);

    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllByName(child, name));
        }
    }
    if (node.slots && typeof node.slots === 'object') {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren) {
                    results.push(...findAllByName(child, name));
                }
            }
        }
    }
    if (node.modals) {
        const modalEntries = Array.isArray(node.modals) ? node.modals : Object.values(node.modals);
        for (const m of modalEntries) {
            results.push(...findAllByName(m as AnyNode, name));
        }
    }

    return results;
}

/**
 * 특정 name 컴포넌트 중 props.name 속성이 주어진 값인 첫 노드를 찾음 (폼 입력 필드 탐색용)
 */
export function findInputByName(node: AnyNode | undefined | null, inputName: string): AnyNode | null {
    for (const candidate of [...findAllByName(node, 'Input'), ...findAllByName(node, 'Select')]) {
        if ((candidate.props as { name?: string } | undefined)?.name === inputName) {
            return candidate;
        }
    }
    return null;
}

/**
 * JSON 문자열에서 사용된 핸들러 이름 모두 수집
 */
export function collectHandlers(json: unknown): string[] {
    const text = JSON.stringify(json);
    const matches = text.match(/"handler":\s*"([^"]+)"/g) ?? [];
    const names = matches
        .map((m) => m.match(/"handler":\s*"([^"]+)"/)?.[1] ?? '')
        .filter(Boolean);
    return Array.from(new Set(names));
}

/**
 * JSON 문자열에서 사용된 i18n 키($t:sirsoft-message_bizppurio.*) 모두 수집
 */
export function collectI18nKeys(json: unknown): string[] {
    const text = JSON.stringify(json);
    const matches = text.match(/\$t:sirsoft-message_bizppurio\.[a-zA-Z0-9_.]+/g) ?? [];
    return Array.from(new Set(matches));
}

/**
 * `{{...}}` 단일 바인딩 조건식을 주어진 자유변수 스코프로 실제 평가합니다.
 *
 * 문자열 포함/동일성 단언(`toContain`/`toBe`)은 조건을 잘못 고쳐도 기대 문자열을 함께
 * 고치면 통과하므로 회귀를 잡지 못한다. 식을 그대로 실행해 결과 boolean 을 본다.
 *
 * @param expr  `{{ ... }}` 형태의 조건식(감싸지 않은 식도 허용)
 * @param scope  식이 참조하는 자유변수 (예: `{ _global: {...} }`)
 * @returns 평가 결과의 boolean 캐스팅
 */
export function evalBinding(expr: string, scope: Record<string, unknown>): boolean {
    const body = String(expr).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(scope);
    // eslint-disable-next-line no-new-func
    const fn = new Function(...names, `return (${body});`);

    return Boolean(fn(...names.map((n) => scope[n])));
}
