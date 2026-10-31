/**
 * notificationTemplateSampleRecipientShape.test.ts — 알림 템플릿 편집 창 편집기 표본의 수신자 모양 가드
 *
 * 배경: 알림 템플릿 편집 창이 7.1.0 계약으로 바뀌었다 — 「관련 사용자」는 정의가 선언한
 * 관계(`relation`) 중에서 고르고, 역할·특정 회원은 여러 개를 고르는 칸이라 값이 배열이다. 그런데 편집기 표본은
 * 예전 모양(관련 사용자 `value`, 역할 `value` 문자열 + `display_name`)으로 남아, 레이아웃 편집기에서 이 창을 열면
 * 관련 사용자 규칙의 종류 칸이 비고 관계가 선택되지 않았으며 역할 규칙에는 고른 역할이 보이지 않았다.
 * 코어·게시판·쇼핑몰·환율 네 곳이 같은 표본을 복사해 쓰고 있었고, 지금은 GDPR 창까지 다섯 곳을 본다.
 *
 * 모집단은 저장소의 편집기 스펙에서 파생한다 — 손으로 적은 목록을 쓰지 않는다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';

const ROOT = process.cwd();
const BUNDLE_BASES = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

type Json = Record<string, any>;

/** 편집기 스펙 매니페스트와 `$include.states` 조각을 모은다. */
function discoverSpecFiles(): string[] {
  const found = new Set<string>();
  for (const base of BUNDLE_BASES) {
    const baseDir = resolve(ROOT, base);
    if (!existsSync(baseDir)) continue;
    for (const ext of readdirSync(baseDir)) {
      const manifestRel = `${base}/${ext}/editor-spec.json`;
      if (!existsSync(resolve(ROOT, manifestRel))) continue;
      found.add(manifestRel);
      const manifest = JSON.parse(readFileSync(resolve(ROOT, manifestRel), 'utf8'));
      const inc: string | string[] | undefined = manifest?.$include?.states;
      for (const one of inc ? (Array.isArray(inc) ? inc : [inc]) : []) {
        const rel = join(dirname(manifestRel), one).split('\\').join('/');
        if (existsSync(resolve(ROOT, rel))) found.add(rel);
      }
    }
  }
  return [...found].sort();
}

interface ModalSample {
  file: string;
  key: string;
  form: Json;
}

/** 알림 템플릿 편집 창 표본(`*notification_template_form_modal` 에 수신자 배열이 있는 것)을 모은다. */
function collectSamples(): ModalSample[] {
  const samples: ModalSample[] = [];
  const walk = (node: unknown, file: string): void => {
    if (Array.isArray(node)) {
      node.forEach((n) => walk(n, file));
      return;
    }
    if (!node || typeof node !== 'object') return;
    for (const [key, value] of Object.entries(node as Json)) {
      if (/notification_template_form_modal$/.test(key) && value && typeof value === 'object' && Array.isArray((value as Json).recipients)) {
        samples.push({ file, key, form: value as Json });
      }
      walk(value, file);
    }
  };
  for (const file of discoverSpecFiles()) {
    walk(JSON.parse(readFileSync(resolve(ROOT, file), 'utf8')), file);
  }
  return samples;
}

/** 표본 하나의 수신자 규칙이 7.1.0 계약과 어긋나는 곳을 문장으로 돌려준다. */
function violationsOf(sample: ModalSample): string[] {
  const out: string[] = [];
  const declared: unknown = sample.form.definition?.relations;
  (sample.form.recipients as Json[]).forEach((rule, i) => {
    const at = `${sample.file} ${sample.key}.recipients[${i}]`;
    if (rule.type === 'related_user') {
      if ('value' in rule) out.push(`${at}: 관련 사용자는 value 가 아니라 relation 을 쓴다`);
      if (typeof rule.relation !== 'string' || rule.relation === '') out.push(`${at}: relation 이 비었다`);
      else if (!Array.isArray(declared) || !declared.includes(rule.relation)) out.push(`${at}: relation "${rule.relation}" 이 정의 relations 에 없다`);
    }
    if (rule.type === 'role' || rule.type === 'specific_users') {
      if (rule.value !== undefined && !Array.isArray(rule.value)) out.push(`${at}: ${rule.type} 값은 배열이다`);
      if ('display_name' in rule) out.push(`${at}: display_name 이 아니라 display_names(배열)를 쓴다`);
      if (rule.display_names !== undefined && (!Array.isArray(rule.display_names) || (Array.isArray(rule.value) && rule.display_names.length !== rule.value.length))) {
        out.push(`${at}: display_names 는 value 와 같은 길이의 배열이다`);
      }
    }
  });
  return out;
}

describe('알림 템플릿 편집 창 편집기 표본 — 수신자 모양', () => {
  const samples = collectSamples();

  /** 확장 디렉터리 이름으로 그 확장의 표본 위반을 모은다. */
  const violationsFor = (ext: string): string[] => {
    const own = samples.filter((s) => s.file.split('/')[2] === ext);
    expect(own.length, `${ext} 표본`).toBeGreaterThan(0);

    return own.flatMap(violationsOf);
  };

  /**
   * @scenario host=core
   * @effects notification_template_sample_population_is_derived_from_repository
   */
  it('모집단이 저장소에서 파생된다(코어, 게시판, 쇼핑몰, 환율, GDPR)', () => {
    const owners = new Set(samples.map((s) => s.file.split('/')[2]));

    for (const ext of ['sirsoft-admin_basic', 'sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-koreaexim', 'sirsoft-gdpr']) {
      expect(owners.has(ext), ext).toBe(true);
    }
  });

  /**
   * @scenario host=core
   * @effects notification_template_sample_recipients_match_contract
   */
  it('코어 알림 템플릿 창 표본은 선언된 relation 과 배열 값을 쓴다', () => {
    expect(violationsFor('sirsoft-admin_basic')).toEqual([]);
  });

  /**
   * @scenario host=board
   * @effects notification_template_sample_recipients_match_contract
   */
  it('게시판 알림 템플릿 창 표본은 선언된 relation 과 배열 값을 쓴다', () => {
    expect(violationsFor('sirsoft-board')).toEqual([]);
  });

  /**
   * @scenario host=ecommerce
   * @effects notification_template_sample_recipients_match_contract
   */
  it('쇼핑몰 알림 템플릿 창 표본은 선언된 relation 과 배열 값을 쓴다', () => {
    expect(violationsFor('sirsoft-ecommerce')).toEqual([]);
  });

  /**
   * @scenario host=koreaexim
   * @effects notification_template_sample_recipients_match_contract
   */
  it('환율 알림 템플릿 창 표본은 배열 값을 쓰고 관계 없는 정의에 관련 사용자를 두지 않는다', () => {
    expect(violationsFor('sirsoft-koreaexim')).toEqual([]);
  });

  /**
   * @scenario host=gdpr
   * @effects notification_template_sample_recipients_match_contract
   */
  it('GDPR 알림 템플릿 창 표본은 배열 값을 쓰고 관계 없는 정의에 관련 사용자를 두지 않는다', () => {
    expect(violationsFor('sirsoft-gdpr')).toEqual([]);
  });
});
