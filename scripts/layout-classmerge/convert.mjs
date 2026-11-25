#!/usr/bin/env node
/**
 * responsive 구간 className 재작성 → classMerge 변환 도구 (계획 R-5, 감사 P10)
 *
 * 구간 `responsive.{key}.props.className` 이 기본 className 을 거의 다시 적은 경우, 바뀐 토큰만
 * `class-merge` 로 남기도록 `.g7.html` 원본을 고친다. 엔진 병합 함수(StyleHelpers.ts 원본 번들)로
 * 「바꿔도 같은 결과」가 증명된 구간만 바꾸고, 바꾸는 것은 `<responsive>` 의 `class="V"` 속성 하나뿐이다.
 *
 * 사용:
 *   node scripts/layout-classmerge/convert.mjs <루트> [--dry-run] [--report <json>] [--verify] [--no-sync] [--jobs N] [--min-overlap R]
 *
 *   --dry-run   파일을 쓰지 않는다 (--verify 와 함께 주면 변환 후 원문을 메모리에서 실제 컴파일해 대조)
 *   --report    보고서 JSON 경로
 *   --verify    다시 구운(또는 메모리 컴파일한) JSON 으로 구간마다 실효 className 이 같은지 다시 계산
 *   --no-sync   바꾼 파일의 짝 .json 을 `php artisan layout:decompile <파일> --sync` 로 다시 굽지 않는다
 *   --jobs N    artisan 동시 실행 수 (기본 4)
 *   --min-overlap R  기본 토큰 재기재 비율이 R(0~1) 미만인 구간은 같은 결과여도 바꾸지 않는다 (기본 0 = 제한 없음, P10 은 0.8)
 *
 * 종료 코드: 0 정상, 1 verify 차이·굽기 실패·컴파일 실패, 2 사용법 오류.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { REPO_ROOT, loadExtensionPrefixes, loadMergeEngine } from './lib/merge-engine.mjs';
import { analyzeOverride, applyConversions, collectOverrides, effectiveClassNames, firstDifference, sameTokenSet } from './lib/segments.mjs';
import { applyEdits, attrOf, decodeReferences, encodeForAttribute, scanResponsiveTags } from './lib/markup.mjs';

/** `class="V"` 를 `class-merge="diff"` 로 바꾸는 교체 하나 (같은 따옴표, 같은 문자 참조 관례) */
function classMergeEdit(cls, diff) {
  return { start: cls.start, end: cls.end, text: `class-merge=${cls.quote}${encodeForAttribute(diff, cls.quote, cls.value)}${cls.quote}` };
}
import { artisanSync, compileBatch } from './lib/php.mjs';

/** 구간 건너뜀 사유 (보고서 `summary.segmentSkips` 키) */
export const SEGMENT_REASONS = {
  binding: '기본 또는 구간 className 에 바인딩({{) 이 있다',
  'non-string': 'className 이 문자열이 아니다(조건부 값 객체 등)',
  'has-class-merge': '구간에 이미 classMerge 가 있다',
  'same-as-base': '구간 className 이 기본과 토큰 집합이 같다(diff 가 비어 class-merge 로 쓸 수 없다)',
  'pure-removal': '구간이 기본 토큰을 지우기만 한다(diff 가 비었다)',
  'removed-base-token': '구간에서 지운 기본 토큰이 병합 후에도 남는다(같은 축 토큰이 diff 에 없다)',
  'merge-drops-token': '병합 규칙이 구간에 있어야 할 토큰을 지운다',
  'class-map-binding': '노드/구간 classMap 값에 바인딩이 있어 결과를 셀 수 없다',
  'class-map-mismatch': 'classMap 결과가 diff 와 충돌해 실효 className 이 달라진다',
  'below-min-overlap': '같은 결과는 증명됐으나 기본 토큰 재기재 비율(overlap)이 --min-overlap 미만이다',
};

/** 파일 건너뜀 사유 (보고서 `summary.fileSkips` 키) */
export const FILE_REASONS = {
  'no-json': '짝 .json 이 없다',
  'json-parse-error': '짝 .json 을 읽지 못했다',
  'count-mismatch': '.g7.html 의 <responsive on class> 와 .json 구간의 개수·값이 1:1 로 맞지 않는다',
  'ambiguous-group': '같은 (구간 키, class 값) 묶음 안에서 판정이 갈려 문서 순서로 짝지었으나, 변환 후 원문의 확인 컴파일이 verify 를 통과하지 못했다',
};

function rel(file) {
  const r = path.relative(REPO_ROOT, file);
  return (r.startsWith('..') || path.isAbsolute(r) ? file : r).split(path.sep).join('/');
}

function walkMarkup(root) {
  const out = [];
  const stack = [root];
  while (stack.length) {
    const dir = stack.pop();
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const e of entries) {
      if (e.isDirectory()) {
        if (e.name === 'node_modules' || e.name === '.git' || e.name === 'vendor') continue;
        stack.push(path.join(dir, e.name));
      } else if (e.isFile() && e.name.toLowerCase().endsWith('.g7.html')) {
        out.push(path.join(dir, e.name));
      }
    }
  }
  return out.sort();
}

function bump(map, key, by = 1) {
  map[key] = (map[key] ?? 0) + by;
}

/**
 * 파일 하나를 분석해 변환 계획을 세운다 (쓰지 않는다).
 */
function planFile(markupFile, engine, minOverlap = 0) {
  const jsonFile = markupFile.replace(/\.g7\.html$/i, '.json');
  const plan = { file: rel(markupFile), json: rel(jsonFile), abs: markupFile, absJson: jsonFile, status: 'unchanged', reason: null, detail: null, segments: [] };
  if (!fs.existsSync(jsonFile)) {
    plan.status = 'skipped';
    plan.reason = 'no-json';
    return plan;
  }
  try {
    plan.before = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
  } catch (error) {
    plan.status = 'skipped';
    plan.reason = 'json-parse-error';
    plan.detail = error.message;
    return plan;
  }

  const segments = collectOverrides(plan.before)
    .map((entry) => analyzeOverride(entry, engine))
    .filter(Boolean);
  for (const seg of segments) {
    if (seg.status === 'convertible' && minOverlap > 0 && seg.overlap < minOverlap) {
      seg.status = 'skip';
      seg.reason = 'below-min-overlap';
    }
  }
  plan.segments = segments;
  if (segments.length === 0) {
    plan.status = 'no-segments';
    return plan;
  }

  const src = fs.readFileSync(markupFile, 'utf8');
  plan.src = src;

  // (구간 키, class 원문) 묶음별로 1:1 대조 — 마크업 class 속성은 언제나 문자열 className 으로 컴파일된다
  const groups = new Map();
  const groupOf = (key, value) => {
    const id = `${key}\u0000${value}`;
    if (!groups.has(id)) groups.set(id, { key, value, json: [], markup: [] });
    return groups.get(id);
  };
  for (const seg of segments) {
    if (typeof seg.segment === 'string') groupOf(seg.key, seg.segment).json.push(seg);
  }
  for (const tag of scanResponsiveTags(src)) {
    const on = attrOf(tag, 'on');
    const cls = attrOf(tag, 'class');
    if (!on || on.value === null || !cls || cls.value === null) continue;
    // 컴파일러는 세미콜론으로 끝나는 문자 참조를 풀어 읽는다 — 같은 방식으로 풀어 JSON 값과 비교한다
    groupOf(decodeReferences(on.value), decodeReferences(cls.value)).markup.push({ tag, cls });
  }

  const mismatches = [];
  for (const g of groups.values()) {
    if (g.json.length !== g.markup.length) mismatches.push({ on: g.key, class: g.value, json: g.json.length, markup: g.markup.length });
  }
  if (mismatches.length > 0) {
    plan.status = 'skipped';
    plan.reason = 'count-mismatch';
    plan.detail = mismatches.slice(0, 5);
    return plan;
  }

  const edits = [];
  const conversions = new Map();
  const ambiguous = [];
  for (const g of groups.values()) {
    const signatures = new Set(g.json.map((s) => (s.status === 'convertible' ? `C:${s.diff}` : 'S')));
    if (signatures.size > 1) {
      // 같은 (키, class) 묶음 안에서 판정이 갈린다 — 문서 순서(JSON 깊이 우선 ↔ 마크업 위치)로 짝을 짓고,
      // run() 이 변환 후 원문을 실제 컴파일해 verify 가 통과할 때만 받아들인다(어긋나면 파일 건너뜀).
      ambiguous.push({ on: g.key, class: g.value, outcomes: [...signatures] });
      g.json.forEach((seg, i) => {
        if (seg.status !== 'convertible') return;
        const { cls } = g.markup[i];
        conversions.set(seg.pointer, seg.diff);
        edits.push(classMergeEdit(cls, seg.diff));
      });
      continue;
    }
    if (g.json[0]?.status !== 'convertible') continue;
    const diff = g.json[0].diff;
    for (const seg of g.json) conversions.set(seg.pointer, diff);
    for (const { cls } of g.markup) {
      edits.push(classMergeEdit(cls, diff));
    }
  }
  if (ambiguous.length > 0) plan.orderAligned = ambiguous;

  if (edits.length > 0) {
    plan.status = 'changed';
    plan.newSrc = applyEdits(src, edits);
    plan.conversions = conversions;
    for (const seg of segments) if (conversions.has(seg.pointer)) seg.converted = true;
  }
  return plan;
}

/**
 * 다시 구운 JSON(after)이 변환 전 JSON(before)과 실효 className 이 같은지 대조한다.
 */
function verifyPlan(plan, after, engine) {
  const result = { overrides: 0, diffs: [], structural: null };
  const beforeList = collectOverrides(plan.before);
  const afterMap = new Map(collectOverrides(after).map((e) => [e.pointer, e]));
  if (afterMap.size !== beforeList.length) result.diffs.push({ pointer: '/', reason: `구간 수가 다르다 (${beforeList.length} → ${afterMap.size})` });
  for (const b of beforeList) {
    result.overrides++;
    const a = afterMap.get(b.pointer);
    if (!a) {
      result.diffs.push({ pointer: b.pointer, reason: '변환 후 구간이 없다' });
      continue;
    }
    const eb = effectiveClassNames(b, engine);
    const ea = effectiveClassNames(a, engine);
    if (eb.binding || ea.binding) {
      if (eb.raw !== ea.raw) result.diffs.push({ pointer: b.pointer, reason: '바인딩 className 이 바뀌었다', before: eb.raw, after: ea.raw });
      continue;
    }
    if (eb.values.length !== ea.values.length || eb.values.some((v, i) => !sameTokenSet(v, ea.values[i]))) {
      result.diffs.push({ pointer: b.pointer, reason: '실효 className 토큰 집합이 다르다', before: eb.values, after: ea.values });
    }
  }
  result.structural = firstDifference(applyConversions(plan.before, plan.conversions), after);
  return result;
}

/**
 * 변환을 실행한다.
 *
 * @param {{root: string, dryRun?: boolean, report?: string, verify?: boolean, noSync?: boolean, jobs?: number, log?: (line: string) => void}} options
 * @returns {Promise<object>} 보고서
 */
export async function run(options) {
  const log = options.log ?? (() => {});
  const root = path.resolve(options.root);
  if (!fs.existsSync(root)) throw new Error(`루트가 없습니다: ${root}`);
  const prefixes = options.prefixes ?? loadExtensionPrefixes();
  const engine = await loadMergeEngine({ prefixes });
  const dryRun = !!options.dryRun;
  const sync = !dryRun && !options.noSync;

  const markupFiles = walkMarkup(root);
  const minOverlap = options.minOverlap ?? 0;
  const plans = markupFiles.map((file) => planFile(file, engine, minOverlap));

  // 순서로 짝지은 파일은 변환 후 원문을 실제 컴파일해 확인한 뒤에만 받아들인다
  const aligned = plans.filter((p) => p.status === 'changed' && p.orderAligned);
  if (aligned.length > 0) {
    log(`순서로 짝지은 파일 확인 컴파일 — ${aligned.length}개`);
    const compiled = compileBatch(aligned.map((p) => ({ path: p.abs, source: p.newSrc })));
    aligned.forEach((p, i) => {
      const r = compiled[i];
      const v = r.ok ? verifyPlan(p, r.json, engine) : null;
      p.orderCheck = r.ok ? { ok: v.diffs.length === 0 && v.structural === null, diffs: v.diffs.length, structural: v.structural } : { ok: false, error: r.error };
      if (p.orderCheck.ok) return;
      p.status = 'skipped';
      p.reason = 'ambiguous-group';
      p.detail = { groups: p.orderAligned, orderCheck: p.orderCheck };
      delete p.newSrc;
      delete p.conversions;
      for (const s of p.segments) delete s.converted;
    });
  }
  const changed = plans.filter((p) => p.status === 'changed');

  if (!dryRun) {
    for (const p of changed) fs.writeFileSync(p.abs, p.newSrc, 'utf8');
  }

  let syncFailed = 0;
  if (sync && changed.length > 0) {
    log(`짝 .json 다시 굽기 — php artisan layout:decompile --sync, 파일 ${changed.length}개`);
    const results = await artisanSync(changed.map((p) => p.abs), { jobs: options.jobs });
    results.forEach((r, i) => {
      changed[i].sync = { ok: r.ok, status: r.status, output: r.ok ? undefined : r.output.slice(0, 2000) };
      if (!r.ok) syncFailed++;
    });
  }

  const verify = { ran: !!options.verify, source: null, files: 0, overridesChecked: 0, diffs: 0, structuralDiffs: 0, compileErrors: 0 };
  if (options.verify && changed.length > 0) {
    verify.source = sync ? 'disk' : 'compiled-in-memory';
    let afterJsons;
    if (sync) {
      afterJsons = changed.map((p) => {
        if (!p.sync?.ok) return { error: '굽기 실패' };
        try {
          return { json: JSON.parse(fs.readFileSync(p.absJson, 'utf8')) };
        } catch (error) {
          return { error: error.message };
        }
      });
    } else {
      log(`변환 후 원문을 메모리에서 컴파일 — 파일 ${changed.length}개`);
      afterJsons = compileBatch(changed.map((p) => ({ path: p.abs, source: p.newSrc }))).map((r) => (r.ok ? { json: r.json } : { error: r.error }));
    }
    changed.forEach((p, i) => {
      verify.files++;
      const after = afterJsons[i];
      if (after.error) {
        verify.compileErrors++;
        p.verify = { error: after.error };
        return;
      }
      const v = verifyPlan(p, after.json, engine);
      verify.overridesChecked += v.overrides;
      verify.diffs += v.diffs.length;
      if (v.structural !== null) verify.structuralDiffs++;
      p.verify = { overrides: v.overrides, diffs: v.diffs, structural: v.structural };
    });
  }

  // 집계
  const summary = {
    markupFiles: markupFiles.length,
    filesWithSegments: 0,
    segments: 0,
    convertible: 0,
    converted: 0,
    segmentSkips: {},
    filesChanged: changed.length,
    filesOrderAligned: changed.filter((p) => p.orderAligned).length,
    filesSkipped: 0,
    fileSkips: {},
    segmentsInSkippedFiles: 0,
    convertibleInSkippedFiles: 0,
    sync: { ran: sync && changed.length > 0, files: sync ? changed.length : 0, failed: syncFailed },
    verify,
  };
  for (const p of plans) {
    if (p.status === 'skipped') {
      summary.filesSkipped++;
      bump(summary.fileSkips, p.reason);
    }
    if (p.segments.length === 0) continue;
    summary.filesWithSegments++;
    for (const s of p.segments) {
      summary.segments++;
      if (s.status === 'convertible') summary.convertible++;
      else bump(summary.segmentSkips, s.reason);
      if (s.converted) summary.converted++;
      if (p.status === 'skipped') {
        summary.segmentsInSkippedFiles++;
        if (s.status === 'convertible') summary.convertibleInSkippedFiles++;
      }
    }
  }

  const report = {
    tool: 'layout-classmerge',
    generatedAt: new Date().toISOString(),
    root: rel(root),
    mode: { dryRun, sync, verify: !!options.verify, minOverlap },
    prefixes,
    reasons: { segment: SEGMENT_REASONS, file: FILE_REASONS },
    summary,
    files: plans
      .filter((p) => p.segments.length > 0 || p.status === 'skipped')
      .map((p) => ({
        file: p.file,
        json: p.json,
        status: p.status,
        reason: p.reason,
        detail: p.detail,
        orderAligned: p.status === 'changed' && p.orderAligned ? p.orderAligned : undefined,
        segments: p.segments.map((s) => ({
          key: s.key,
          pointer: s.pointer,
          status: s.status,
          reason: s.reason,
          converted: !!s.converted,
          base: s.base,
          segment: s.segment,
          diff: s.diff,
          overlap: s.overlap,
          extra: s.extra.length ? s.extra : undefined,
          missing: s.missing.length ? s.missing : undefined,
          otherProps: s.otherProps.length ? s.otherProps : undefined,
          classMapValue: s.classMapValue,
        })),
        sync: p.sync,
        verify: p.verify,
      })),
  };

  if (options.report) {
    fs.mkdirSync(path.dirname(path.resolve(options.report)), { recursive: true });
    fs.writeFileSync(options.report, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  }
  report.exitCode = syncFailed > 0 || verify.diffs > 0 || verify.structuralDiffs > 0 || verify.compileErrors > 0 ? 1 : 0;
  return report;
}

function printSummary(report) {
  const s = report.summary;
  const lines = [
    `루트: ${report.root} (${report.mode.dryRun ? 'dry-run' : '쓰기'}${report.mode.sync ? ', 굽기' : ''}${report.mode.verify ? ', verify' : ''})`,
    `.g7.html ${s.markupFiles}개, 구간 className 있는 파일 ${s.filesWithSegments}개`,
    `구간 ${s.segments}개 — 변환 가능 ${s.convertible}, ${report.mode.dryRun ? '변환 예정' : '변환'} ${s.converted}`,
  ];
  for (const [reason, count] of Object.entries(s.segmentSkips).sort((a, b) => b[1] - a[1])) lines.push(`  건너뜀 ${reason}: ${count}`);
  lines.push(`파일 — ${report.mode.dryRun ? '바뀔' : '바뀐'} 파일 ${s.filesChanged}(순서 짝 확인 ${s.filesOrderAligned}), 건너뛴 파일 ${s.filesSkipped} (그 안의 구간 ${s.segmentsInSkippedFiles}, 변환 가능 ${s.convertibleInSkippedFiles})`);
  for (const [reason, count] of Object.entries(s.fileSkips).sort((a, b) => b[1] - a[1])) lines.push(`  파일 건너뜀 ${reason}: ${count}`);
  if (s.sync.ran) lines.push(`굽기 — ${s.sync.files}개, 실패 ${s.sync.failed}`);
  if (s.verify.ran) {
    lines.push(`verify(${s.verify.source ?? '대상 없음'}) — 파일 ${s.verify.files}, 구간 ${s.verify.overridesChecked}, 차이 ${s.verify.diffs}, 구조 차이 ${s.verify.structuralDiffs}, 컴파일 실패 ${s.verify.compileErrors}`);
  }
  console.log(lines.join('\n'));
}

function parseArgs(argv) {
  const opts = { root: null };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--dry-run') opts.dryRun = true;
    else if (a === '--verify') opts.verify = true;
    else if (a === '--no-sync') opts.noSync = true;
    else if (a === '--report') opts.report = argv[++i];
    else if (a.startsWith('--report=')) opts.report = a.slice('--report='.length);
    else if (a === '--jobs') opts.jobs = Number(argv[++i]);
    else if (a.startsWith('--jobs=')) opts.jobs = Number(a.slice('--jobs='.length));
    else if (a === '--min-overlap') opts.minOverlap = Number(argv[++i]);
    else if (a.startsWith('--min-overlap=')) opts.minOverlap = Number(a.slice('--min-overlap='.length));
    else if (a.startsWith('--')) throw new Error(`알 수 없는 옵션: ${a}`);
    else if (opts.root === null) opts.root = a;
    else throw new Error(`루트는 하나만 받습니다: ${a}`);
  }
  if (!opts.root) throw new Error('루트 경로가 필요합니다');
  if (opts.report === undefined && argv.includes('--report')) throw new Error('--report 다음에 경로가 필요합니다');
  if (opts.jobs !== undefined && !(opts.jobs >= 1)) throw new Error('--jobs 는 1 이상의 수');
  if (opts.minOverlap !== undefined && !(opts.minOverlap >= 0 && opts.minOverlap <= 1)) throw new Error('--min-overlap 은 0~1 사이의 수');
  return opts;
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  let opts;
  try {
    opts = parseArgs(process.argv.slice(2));
  } catch (error) {
    console.error(`${error.message}\n사용: node scripts/layout-classmerge/convert.mjs <루트> [--dry-run] [--report <json>] [--verify] [--no-sync] [--jobs N] [--min-overlap R]`);
    process.exit(2);
  }
  try {
    const report = await run({ ...opts, log: (line) => console.log(line) });
    printSummary(report);
    process.exit(report.exitCode);
  } catch (error) {
    console.error(error.stack || error.message);
    process.exit(1);
  }
}
