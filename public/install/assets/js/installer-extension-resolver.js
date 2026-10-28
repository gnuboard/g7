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

    // 설치 순서이기도 하다 — 관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿 → 언어팩
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
     * 같은 카테고리 안 의존을 반영한 설치 순서 — 스캔 순서 기준 안정 위상 정렬. 순환이면 오류 기록 후 스캔 순서.
     *
     * @param {string} category 카테고리
     * @param {string[]} ids 선택 식별자
     * @param {Map<string, Object>} items 카테고리 스캔 항목 (스캔 순서)
     * @param {Array} errors 오류 목록 (추가됨)
     * @returns {string[]} 설치 순서
     */
    function topologicalOrder(category, ids, items, errors) {
        const scanOrder = new Map();
        Array.from(items.keys()).forEach((id, idx) => scanOrder.set(id, idx));
        const sortedIds = ids.slice().sort((a, b) => (scanOrder.has(a) ? scanOrder.get(a) : Infinity) - (scanOrder.has(b) ? scanOrder.get(b) : Infinity));

        const set = new Set(sortedIds);
        const sorted = [];
        const state = new Map();

        const visit = (id, path) => {
            if (state.get(id) === 2) return true;
            if (state.get(id) === 1) {
                errors.push({ key: 'error_extension_dependency_cycle', params: { group: category, cycle: path.concat([id]).join(' → ') } });
                return false;
            }
            state.set(id, 1);
            const deps = (items.get(id) && items.get(id).dependencies_detailed) || [];
            for (const dep of deps) {
                const depId = String((dep && dep.identifier) || '');
                // 자기 자신 의존은 순환이 아니라 잘못 적은 선언이다 — 건너뛴다
                if (depId !== '' && depId !== id && set.has(depId) && !visit(depId, path.concat([id]))) return false;
            }
            state.set(id, 2);
            sorted.push(id);
            return true;
        };

        for (const id of sortedIds) {
            if (!visit(id, [])) return sortedIds;
        }
        return sorted;
    }

    /**
     * 요청한 확장 선택을 해석한다 — 존재·의존 자동 포함(고정점 10회)·버전 제약·단일 선택·언어팩 대상 연동·설치 순서.
     *
     * @param {Object} scan 카테고리별 스캔 항목 (scan-extensions.php data)
     * @param {Object} requested 카테고리별 요청 식별자
     * @returns {{selection: Object, auto_added: Array, excluded_language_packs: string[], errors: Array, install_order: string[]}}
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
                        const depType = String((dep && dep.type) || '');
                        const targetCat = (depType === 'modules' || depType === 'plugins') ? depType : (idToCategory.get(depId) || null);

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
                    const depCat = idToCategory.get(depId);
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

        const installOrder = [];
        CATEGORIES.forEach((cat) => {
            selected[cat] = topologicalOrder(cat, selected[cat], items[cat], errors);
            selected[cat].forEach((id) => installOrder.push(cat + ':' + id));
        });

        return { selection: selected, auto_added: autoAdded, excluded_language_packs: excluded, errors: errors, install_order: installOrder };
    }

    const api = { CATEGORIES, satisfiesVersionConstraint, resolveExtensionSelection };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.G7ExtensionResolver = api;
    }
})(typeof window !== 'undefined' ? window : null);
