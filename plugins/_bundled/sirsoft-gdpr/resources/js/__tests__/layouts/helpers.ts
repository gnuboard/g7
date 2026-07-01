/**
 * GDPR 플러그인 레이아웃 테스트 공통 헬퍼
 *
 * JSON 트리에서 ID/이름 검색 및 핸들러 추출을 위한 유틸리티.
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
 * 트리 노드에서 특정 id를 재귀 탐색
 *
 * 일반 레이아웃 구조(children/slots/modals)와 Layout Extension 구조(injections[].components[])
 * 양쪽을 모두 탐색합니다.
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

    // Layout Extension (Overlay): injections[].components[]
    const injections = (node as { injections?: AnyNode[] }).injections;
    if (Array.isArray(injections)) {
        for (const inj of injections) {
            const components = (inj as { components?: AnyNode[] }).components;
            if (Array.isArray(components)) {
                for (const c of components) {
                    const found = findById(c, id);
                    if (found) return found;
                }
            }
        }
    }

    // Layout Extension (Extension Point): root.components[]
    if (Array.isArray((node as { components?: AnyNode[] }).components)) {
        for (const c of (node as { components: AnyNode[] }).components) {
            const found = findById(c, id);
            if (found) return found;
        }
    }

    return null;
}

/**
 * 트리 노드에서 특정 name 컴포넌트를 모두 수집
 *
 * 일반 레이아웃 구조와 Layout Extension 구조 양쪽을 모두 탐색합니다.
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

    // Layout Extension (Overlay): injections[].components[]
    const injections = (node as { injections?: AnyNode[] }).injections;
    if (Array.isArray(injections)) {
        for (const inj of injections) {
            const components = (inj as { components?: AnyNode[] }).components;
            if (Array.isArray(components)) {
                for (const c of components) {
                    results.push(...findAllByName(c, name));
                }
            }
        }
    }

    // Layout Extension (Extension Point): root.components[]
    if (Array.isArray((node as { components?: AnyNode[] }).components)) {
        for (const c of (node as { components: AnyNode[] }).components) {
            results.push(...findAllByName(c, name));
        }
    }

    return results;
}

/**
 * 텍스트 검색용 직렬화 — 원본 JSON 파일(4-space indent) 형식과 동일하게 출력하여
 * `text.toContain('"target": "local"')` 같은 공백 포함 패턴이 그대로 매칭되도록 함.
 */
export function serializeForSearch(json: unknown): string {
    return JSON.stringify(json, null, 4);
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
 * JSON 문자열에서 사용된 i18n 키($t:sirsoft-gdpr.*) 모두 수집
 */
export function collectGdprI18nKeys(json: unknown): string[] {
    const text = JSON.stringify(json);
    const matches = text.match(/\$t:sirsoft-gdpr\.[a-zA-Z0-9_.]+/g) ?? [];
    return Array.from(new Set(matches));
}

/**
 * JSON 문자열에서 사용된 컴포넌트 name(대문자 시작) 수집
 */
export function collectComponentNames(json: unknown): string[] {
    const text = JSON.stringify(json);
    const matches = text.match(/"name":\s*"([A-Z][a-zA-Z0-9_]*)"/g) ?? [];
    const names = matches
        .map((m) => m.match(/"name":\s*"([A-Z][a-zA-Z0-9_]*)"/)?.[1] ?? '')
        .filter(Boolean);
    return Array.from(new Set(names));
}
