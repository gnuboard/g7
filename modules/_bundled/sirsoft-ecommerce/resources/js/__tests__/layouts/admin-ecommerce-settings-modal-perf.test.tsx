/**
 * @file admin-ecommerce-settings-modal-perf.test.tsx
 * @description 이커머스 환경설정 모달 회귀 (옵션 K — _global 단일 진실)
 *
 * namespace 키 분리 — 이커머스: ecommerce_notification_template_form_modal
 * 정책 모달은 동일 키 (identity_policy_form_modal) 사용 — 코어와 ecommerce/board 가
 * 동시 마운트되지 않으므로 키 충돌 없음 (각 모듈 환경설정 페이지에서만 노출)
 */

import { describe, it, expect } from 'vitest';

const policyTab = require('../../../layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.json');
const policyModal = require('../../../layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_form.json');
const notifTab = require('../../../layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json');
const notifModal = require('../../../layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_edit.json');
const notifPreview = require('../../../layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_preview.json');

interface AnyJson { [k: string]: any }

const collectChangeTargets = (node: AnyJson, acc: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) { node.forEach(item => collectChangeTargets(item, acc)); return acc; }
  if (Array.isArray(node.actions)) {
    for (const a of node.actions) {
      const isChange = a?.type === 'change' || a?.event === 'onChange' || a?.event === 'onSearch';
      if (isChange && a?.handler === 'setState' && a?.params?.target) {
        acc.push(a.params.target);
      }
    }
  }
  for (const k of Object.keys(node)) collectChangeTargets(node[k], acc);
  return acc;
};

describe('이커머스 본인인증 정책 모달 회귀 (옵션 K)', () => {
  it('lifecycle.onMount 미사용', () => {
    expect(policyModal.lifecycle).toBeUndefined();
  });

  it('change 액션은 모두 target:"global"', () => {
    const targets = collectChangeTargets(policyModal);
    expect(targets.length).toBeGreaterThan(0);
    expect(targets.every(t => t === 'global')).toBe(true);
  });

  it('list 측 setState 가 _global.identity_policy_form_modal 통째 set', () => {
    const tabStr = JSON.stringify(policyTab);
    expect(tabStr).toContain('"target":"global"');
    expect(tabStr).toContain('"identity_policy_form_modal":{');
  });

  it('표시/입력 모두 _global.identity_policy_form_modal 경로', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain('_global.identity_policy_form_modal?.form?.key');
    expect(modalStr).toContain('"identity_policy_form_modal.form.key":');
  });

  it('apiCall body source_identifier=sirsoft-ecommerce, dataSourceId=ecommerceIdentityPolicies', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain("'sirsoft-ecommerce'");
    expect(modalStr).toContain('"dataSourceId":"ecommerceIdentityPolicies"');
  });

  it('저장 onSuccess 에 namespace null 정리', () => {
    const modalStr = JSON.stringify(policyModal);
    expect(modalStr).toContain('"identity_policy_form_modal":null');
  });
});

describe('이커머스 알림 템플릿 모달 회귀 (옵션 K)', () => {
  it('lifecycle.onMount 미사용', () => {
    expect(notifModal.lifecycle).toBeUndefined();
  });

  it('change/onChange/onSearch 액션은 모두 target:"global"', () => {
    const targets = collectChangeTargets(notifModal);
    expect(targets.length).toBeGreaterThan(0);
    expect(targets.every(t => t === 'global')).toBe(true);
  });

  it('list 측 setState 가 _global.ecommerce_notification_template_form_modal 통째 set', () => {
    const tabStr = JSON.stringify(notifTab);
    expect(tabStr).toContain('"ecommerce_notification_template_form_modal":{');
  });

  it('표시 표현식이 ecommerce_notification_template_form_modal 경로', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('_global.ecommerce_notification_template_form_modal?.template?.subject');
    expect(modalStr).toContain('_global.ecommerce_notification_template_form_modal?.recipients');
  });

  it('subject/body — Object.assign + dot path 통째 set', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"ecommerce_notification_template_form_modal.template.subject":');
    expect(modalStr).toContain('Object.assign({}, _global.ecommerce_notification_template_form_modal?.template?.subject');
  });

  it('recipients — .map/.filter 통째 교체', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"ecommerce_notification_template_form_modal.recipients":');
  });

  it('preview 모달이 _global.ecommerce_notification_template_form_modal?.preview 참조', () => {
    const previewStr = JSON.stringify(notifPreview);
    expect(previewStr).toContain('_global.ecommerce_notification_template_form_modal?.preview?.subject');
    expect(previewStr).toContain('_global.ecommerce_notification_template_form_modal?.preview?.body');
  });

  it('저장 onSuccess 에 dataSourceId=ecommerceNotificationDefinitions + namespace null 정리', () => {
    const modalStr = JSON.stringify(notifModal);
    expect(modalStr).toContain('"dataSourceId":"ecommerceNotificationDefinitions"');
    expect(modalStr).toContain('"ecommerce_notification_template_form_modal":null');
  });
});
