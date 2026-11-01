/**
 * 그누보드7 인스톨러 - 확장 선택 해석기 (브라우저 사본)
 *
 * 서버 해석기 `public/install/includes/extension-selection.php` 의 `installer_resolve_extension_selection()` 과
 * **같은 규칙·같은 출력 구조**다. 확장 저장 API·설치 직전 사전 검증·터미널 설치는 PHP 쪽을 쓰고,
 * 설치 화면(installer.js)은 이 파일로 의존 확장 자동 선택·버전 제약을 판정한다.
 * 두 구현은 공용 픽스처(tests/fixtures/installer/extension-resolver-cases.json)로 같은 결과를 내도록 잠근다 —
 * 한쪽 규칙을 바꾸면 다른 쪽과 픽스처 기대값을 함께 바꾼다.
 *
 * 순수 함수만 둔다(DOM·전역 상태 없음). 브라우저에서는 window.G7ExtensionResolver, 테스트에서는 module.exports.
 */
(function (root) {
    'use strict';

    // 종류 기본 순서 — 관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿 → 언어팩. 의존이 있으면 앞당긴다(installOrder)
    const CATEGORIES = ['admin_templates', 'modules', 'plugins', 'user_templates', 'language_packs'];

    /**
     * 버전 문자열을 [major, minor, patch] 숫자 배열로 바꾼다.
     *
     * @param {string} v 버전
     * @returns {number[]} 세 자리 숫자
     */
    function parseVersion(v) {
        const parts = String(v).replace(/^[v=]/, '').split('.').map((p) => parseInt(p, 10) || 0);
        return [parts[0] || 0, parts[1] || 0, parts[2] || 0];
    }

    /**
     * 버전 제약 충족 여부. 연산자: >=, >, <=, <, =, ==, !=, ^(메이저 고정), ~(마이너 고정), 없으면 >=.
     *
     * @param {string} available 사용 가능한 버전
     * @param {string|null} constraint 제약 (빈 값·* 은 항상 충족)
     * @returns {boolean} 충족 여부
     */
    function satisfiesVersionConstraint(available, constraint) {
        if (!constraint || constraint === '*') return true;

        const match = String(constraint).match(/^([><=^~!]*)\s*(.+)$/);
        if (!match) return true;

        const op = match[1] || '>=';
        const avail = parseVersion(available);
        const req = parseVersion(match[2]);
        let cmp = 0;
        for (let i = 0; i < 3; i++) {
            if (avail[i] < req[i]) { cmp = -1; break; }
            if (avail[i] > req[i]) { cmp = 1; break; }
        }

        switch (op) {
            case '>=': return cmp >= 0;
            case '>': return cmp > 0;
            case '<=': return cmp <= 0;
            case '<': return cmp < 0;
            case '=':
            case '==': return cmp === 0;
            case '!=': return cmp !== 0;
            case '^': return cmp >= 0 && avail[0] === req[0];
            case '~': return cmp >= 0 && avail[0] === req[0] && avail[1] === req[1];
            default: return cmp >= 0;
        }
    }

    /**
     * 확장 표시 이름 — {ko, en} 이면 ko → en → 식별자.
     *
     * @param {Object} item 스캔 항목
     * @returns {string} 표시 이름
     */
    function displayName(item) {
        const name = item && item.name;
        if (name && typeof name === 'object') {
            return String(name.ko || name.en || item.identifier || '');
        }
        return typeof name === 'string' && name !== '' ? name : String((item && item.identifier) || '');
    }

    /**
     * 의존 항목이 가리키는 카테고리 — 선언된 종류(modules/plugins)를 먼저, 옛 평면 배열(종류 없음)만 식별자 역인덱스로.
     *
     * @param {Object} dep dependencies_detailed 항목
     * @param {Map<string, string>} idToCategory 식별자 → 처음 나온 카테고리
     * @returns {string|null} 카테고리
     */
    function dependencyCategory(dep, idToCategory) {
        const type = String((dep && dep.type) || '');
        if (type === 'modules' || type === 'plugins') return type;
        return idToCategory.get(String((dep && dep.identifier) || '')) || null;
    }

    /**
     * 설치 순서 — 관리자 템플릿·모듈·플러그인·사용자 템플릿을 한 그래프로 놓고 종류를 넘는 의존까지 반영한 안정 위상 정렬.
     * 기본 순서(종류 → 스캔)대로 깊이 우선으로 돌며 선택 안의 의존을 먼저 놓는다. 순환이면 `종류:식별자` 경로로 오류를 남기고 기본 순서.
     * 언어팩은 코어 범위 먼저, 나머지는 스캔 순서로 맨 뒤. pulled_by = 종류 경계를 넘어 앞당겨진 노드 → 요구한 선택 노드 목록.
     *
     * @param {Object} selected 카테고리별 선택 식별자
     * @param {Object} items 카테고리별 Map(식별자 → 항목, 스캔 순서)
     * @param {Map<string, string>} idToCategory 식별자 → 처음 나온 카테고리
     * @param {Array} errors 오류 목록 (추가됨)
     * @returns {{order: string[], pulled_by: Object}} `카테고리:식별자` 설치 순서와 앞당김 기록
     */
    function installOrder(selected, items, idToCategory, errors) {
        const graphCategories = ['admin_templates', 'modules', 'plugins', 'user_templates'];
        const rank = new Map(graphCategories.map((cat, idx) => [cat, idx]));
        const scanIndex = (cat) => {
            const m = new Map();
            Array.from(items[cat].keys()).forEach((id, idx) => m.set(id, idx));
            return m;
        };
        const byScan = (m) => (a, b) => (m.has(a) ? m.get(a) : Infinity) - (m.has(b) ? m.get(b) : Infinity);

        const base = [];
        graphCategories.forEach((cat) => {
            selected[cat].slice().sort(byScan(scanIndex(cat))).forEach((id) => base.push(cat + ':' + id));
        });
        const set = new Set(base);

        const dependencyKeys = (key) => {
            const sep = key.indexOf(':');
            const item = items[key.slice(0, sep)].get(key.slice(sep + 1));
            const keys = [];
            ((item && item.dependencies_detailed) || []).forEach((dep) => {
                const depCat = dependencyCategory(dep, idToCategory);
                const depKey = depCat + ':' + String((dep && dep.identifier) || '');
                // 자기 자신 의존은 순환이 아니라 잘못 적은 선언이다 — 건너뛴다. 선택 밖 의존은 설치 여부 검사가 따로 본다.
                if (depCat !== null && depKey !== key && set.has(depKey)) keys.push(depKey);
            });
            return keys;
        };

        let order = [];
        const state = new Map();
        const visit = (key, path) => {
            if (state.get(key) === 2) return true;
            if (state.get(key) === 1) {
                errors.push({ key: 'error_extension_dependency_cycle', params: { group: 'extensions', cycle: path.concat([key]).join(' → ') } });
                return false;
            }
            state.set(key, 1);
            for (const depKey of dependencyKeys(key)) {
                if (!visit(depKey, path.concat([key]))) return false;
            }
            state.set(key, 2);
            order.push(key);
            return true;
        };

        const pulledBy = {};
        let sorted = true;
        for (const key of base) {
            if (!visit(key, [])) { sorted = false; break; }
        }
        if (sorted) {
            base.forEach((key) => {
                const ownerRank = rank.get(key.slice(0, key.indexOf(':')));
                dependencyKeys(key).forEach((depKey) => {
                    if (ownerRank < rank.get(depKey.slice(0, depKey.indexOf(':')))) {
                        (pulledBy[depKey] = pulledBy[depKey] || []).push(key);
                    }
                });
            });
        } else {
            order = base.slice();
        }

        const packScan = scanIndex('language_packs');
        const isCore = (id) => (String((items.language_packs.get(id) || {}).scope || 'core') === 'core' ? 0 : 1);
        selected.language_packs.slice()
            .sort((a, b) => (isCore(a) - isCore(b)) || byScan(packScan)(a, b))
            .forEach((id) => order.push('language_packs:' + id));

        return { order: order, pulled_by: pulledBy };
    }

    /**
     * 요청한 확장 선택을 해석한다 — 존재·의존 자동 포함(고정점 10회)·버전 제약·단일 선택·언어팩 대상 연동·설치 순서.
     *
     * @param {Object} scan 카테고리별 스캔 항목 (scan-extensions.php data)
     * @param {Object} requested 카테고리별 요청 식별자
     * @returns {{selection: Object, auto_added: Array, excluded_language_packs: string[], errors: Array, install_order: string[], pulled_by: Object}}
     */
    function resolveExtensionSelection(scan, requested) {
        const errors = [];
        const autoAdded = [];
        const items = {};
        const idToCategory = new Map();

        CATEGORIES.forEach((cat) => {
            items[cat] = new Map();
            ((scan && scan[cat]) || []).forEach((item) => {
                const id = String((item && item.identifier) || '');
                if (id === '') return;
                items[cat].set(id, item);
                if (!idToCategory.has(id)) idToCategory.set(id, cat);
            });
        });

        const selected = {};
        CATEGORIES.forEach((cat) => {
            selected[cat] = [];
            ((requested && requested[cat]) || []).forEach((raw) => {
                const id = String(raw).trim();
                if (id === '' || selected[cat].includes(id)) return;
                if (!items[cat].has(id)) {
                    errors.push({ key: 'error_extension_not_found', params: { identifier: id, group: cat } });
                    return;
                }
                selected[cat].push(id);
            });
        });

        const unavailable = new Map();
        for (let iteration = 0; iteration < 10; iteration++) {
            let changed = false;
            CATEGORIES.forEach((cat) => {
                selected[cat].slice().forEach((ownerId) => {
                    const owner = items[cat].get(ownerId);
                    ((owner && owner.dependencies_detailed) || []).forEach((dep) => {
                        const depId = String((dep && dep.identifier) || '');
                        if (depId === '') return;
                        const targetCat = dependencyCategory(dep, idToCategory);

                        if (targetCat === null || !items[targetCat].has(depId)) {
                            unavailable.set(ownerId + '>' + depId, { owner: displayName(owner), dependency: depId });
                            return;
                        }
                        if (selected[targetCat].includes(depId)) return;

                        if (targetCat === 'admin_templates' || targetCat === 'user_templates') {
                            selected[targetCat] = [depId];
                        } else {
                            selected[targetCat].push(depId);
                        }
                        autoAdded.push({ category: targetCat, identifier: depId, required_by: ownerId });
                        changed = true;
                    });
                });
            });
            if (!changed) break;
        }

        unavailable.forEach((entry) => errors.push({ key: 'error_extension_dependency_unavailable', params: entry }));

        CATEGORIES.forEach((cat) => {
            selected[cat].forEach((ownerId) => {
                const owner = items[cat].get(ownerId);
                ((owner && owner.dependencies_detailed) || []).forEach((dep) => {
                    const depId = String((dep && dep.identifier) || '');
                    const constraint = String((dep && dep.version) || '*');
                    // 선언된 종류를 먼저 본다 — 같은 식별자가 모듈·플러그인 양쪽에 있어도 섞지 않는다
                    const depCat = dependencyCategory(dep, idToCategory);
                    if (!depCat || !selected[depCat].includes(depId)) return;
                    const available = String(items[depCat].get(depId).version || '0.0.0');
                    if (!satisfiesVersionConstraint(available, constraint)) {
                        errors.push({ key: 'error_extension_dependency_version', params: { owner: displayName(owner), dependency: depId, required: constraint, available: available } });
                    }
                });
            });
        });

        if (selected.admin_templates.length === 0) {
            errors.push({ key: 'error_admin_template_required', params: {} });
        } else if (selected.admin_templates.length > 1) {
            errors.push({ key: 'error_admin_template_single', params: {} });
        }
        if (selected.user_templates.length > 1) {
            errors.push({ key: 'error_user_template_single', params: {} });
        }

        const excluded = [];
        selected.language_packs = selected.language_packs.filter((id) => {
            const pack = items.language_packs.get(id);
            const scope = String((pack && pack.scope) || 'core');
            const target = String((pack && pack.target_identifier) || '');
            if (scope === 'core' || target === '') return true;
            let matched = false;
            if (scope === 'module') matched = selected.modules.includes(target);
            else if (scope === 'plugin') matched = selected.plugins.includes(target);
            else if (scope === 'template') matched = selected.admin_templates.includes(target) || selected.user_templates.includes(target);
            if (!matched) excluded.push(id);
            return matched;
        });

        // 설치 순서 — 종류를 넘는 의존까지 한 그래프로 정렬(기본 순서는 종류 → 스캔, 의존만 앞당김)
        const ordered = installOrder(selected, items, idToCategory, errors);
        CATEGORIES.forEach((cat) => { selected[cat] = []; });
        ordered.order.forEach((key) => {
            const sep = key.indexOf(':');
            selected[key.slice(0, sep)].push(key.slice(sep + 1));
        });

        return { selection: selected, auto_added: autoAdded, excluded_language_packs: excluded, errors: errors, install_order: ordered.order, pulled_by: ordered.pulled_by };
    }

    const api = { CATEGORIES, satisfiesVersionConstraint, resolveExtensionSelection };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.G7ExtensionResolver = api;
    }
})(typeof window !== 'undefined' ? window : null);
