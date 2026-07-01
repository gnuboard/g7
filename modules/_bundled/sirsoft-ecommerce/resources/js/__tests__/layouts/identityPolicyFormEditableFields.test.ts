/**
 * 이커머스 본인인증 정책 편집 모달 — 선언형 정책의 편집 가능/잠금 필드 회귀 테스트.
 *
 * 선언형 정책(source_type != admin)은 키(key)/시점(scope)/위치(target) 만 readonly 이며,
 * 인증 목적(purpose)·적용 대상(applies_to) 을 포함한 그 외 필드는 운영자가 자유로이
 * 편집할 수 있어야 한다. 과거 purpose/applies_to 까지 disabled 였던 회귀를 차단한다.
 */

import { describe, it, expect } from 'vitest';

const modalPartial = require('../../../layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_form.json');

const LOCK_PREDICATE =
    "!_global.identity_policy_form_modal?.isNew && _global.identity_policy_form_modal?.form?.source_type !== 'admin'";

function findFieldControl(node: any, labelKey: string): any | null {
    if (!node || typeof node !== 'object') return null;
    if (Array.isArray(node)) {
        for (const item of node) {
            const r = findFieldControl(item, labelKey);
            if (r) return r;
        }
        return null;
    }
    if (Array.isArray(node.children)) {
        const hasLabel = node.children.some((c: any) => c?.name === 'Label' && c?.text === labelKey);
        if (hasLabel) {
            const control = node.children.find((c: any) => c?.name === 'Input' || c?.name === 'Select');
            if (control) return control;
        }
    }
    for (const k of Object.keys(node)) {
        const r = findFieldControl(node[k], labelKey);
        if (r) return r;
    }
    return null;
}

describe('이커머스 본인인증 정책 모달 — 편집 가능/잠금 필드', () => {
    const lockedFields = [
        ['정책 키', '$t:admin.identity.policies.form.key'],
        ['강제 시점', '$t:admin.identity.policies.form.scope'],
        ['강제 위치', '$t:admin.identity.policies.form.target'],
    ] as const;

    it.each(lockedFields)('선언형 잠금 필드 "%s" 는 disabled 바인딩 보유', (_label, labelKey) => {
        const control = findFieldControl(modalPartial, labelKey);
        expect(control).not.toBeNull();
        expect(control!.props?.disabled).toBe(`{{${LOCK_PREDICATE}}}`);
    });

    const editableFields = [
        ['인증 목적', '$t:admin.identity.policies.form.purpose'],
        ['적용 대상', '$t:admin.identity.policies.form.applies_to'],
    ] as const;

    it.each(editableFields)('편집 가능 필드 "%s" 는 disabled 미부여 (선언형도 편집 가능)', (_label, labelKey) => {
        const control = findFieldControl(modalPartial, labelKey);
        expect(control).not.toBeNull();
        expect(control!.props?.disabled).toBeUndefined();
    });

    it('purpose Select 옵션은 전체 purpose 목록 매핑 (필터 없이 커스텀 purpose 포함 가능)', () => {
        const purpose = findFieldControl(modalPartial, '$t:admin.identity.policies.form.purpose');
        expect(purpose!.props?.options).toContain('ecommerceIdentityPurposes');
        expect(purpose!.props?.options).not.toContain('filter');
    });

    it('우선순위(priority) 입력칸이 존재하고 form.priority 에 바인딩된다', () => {
        const priority = findFieldControl(modalPartial, '$t:admin.identity.policies.form.priority');
        expect(priority).not.toBeNull();
        expect(priority!.name).toBe('Input');
        expect(priority!.props?.type).toBe('number');
        expect(priority!.props?.value).toContain('identity_policy_form_modal?.form?.priority');
        expect(priority!.props?.disabled).toBeUndefined();
    });
});
