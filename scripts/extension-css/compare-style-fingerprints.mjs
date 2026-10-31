#!/usr/bin/env node
/**
 * 화면 스타일 지문 비교기
 *
 * `capture-style-fingerprints.mjs`(또는 `style-fingerprint.js` 를 evaluate 로 실행한 결과)로 뜬
 * 전후 지문을 DOM 경로별로 대조한다. 확장 CSS 전환은 모양을 바꾸지 않는 작업이므로 기대값은 0 건이다.
 *
 * 사용:
 *   node scripts/extension-css/compare-style-fingerprints.mjs <before> <after> [--json <보고서>] [--detail 20] [--boxes]
 *
 * `<before>`·`<after>` 는 지문 파일(.json / .json.gz) 둘이거나 디렉터리 둘이다. 디렉터리면 같은 상대 경로끼리
 * 짝을 짓고, 한쪽에만 있는 파일도 보고한다.
 *
 * 판정:
 * - 스타일 차이(같은 경로의 계산값이 다름)·요소 증감(한쪽에만 있는 경로)은 실패다.
 * - 상자 크기 차이는 데이터 길이·이미지 로딩에 흔들릴 수 있어 기본은 건수만 보고하고, `--boxes` 를 주면 실패로 센다.
 *
 * 종료 코드: 0 차이 없음, 1 차이 있음, 2 사용 오류.
 */
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { gunzipSync } from 'node:zlib';

/**
 * 지문 파일을 읽는다(gzip 여부는 확장자로 판단).
 *
 * @param {string} file 파일 경로
 * @returns {Record<string, any>} 지문
 */
function load(file) {
  const raw = readFileSync(file);
  return JSON.parse((file.endsWith('.gz') ? gunzipSync(raw) : raw).toString('utf8'));
}

/**
 * 디렉터리 아래 지문 파일의 상대 경로 목록.
 *
 * @param {string} dir 디렉터리
 * @returns {string[]} 상대 경로
 */
function listFingerprints(dir) {
  const out = [];
  const visit = (d) => {
    for (const name of readdirSync(d)) {
      const full = join(d, name);
      if (statSync(full).isDirectory()) visit(full);
      else if (/\.json(\.gz)?$/.test(name) && !name.startsWith('_')) out.push(relative(dir, full).replace(/\\/g, '/'));
    }
  };
  visit(dir);
  return out.sort();
}

/**
 * 지문 두 개를 비교한다.
 *
 * @param {Record<string, any>} a 전
 * @param {Record<string, any>} b 후
 * @param {number} detail 세부 항목 상한
 * @returns {Record<string, any>} 비교 결과
 */
function compare(a, b, detail) {
  const props = a.props ?? b.props ?? [];
  const result = { styleDiffs: 0, missing: 0, extra: 0, boxDiffs: 0, details: [] };
  const push = (item) => { if (result.details.length < detail) result.details.push(item); };
  for (const [path, value] of Object.entries(a.styles)) {
    if (!(path in b.styles)) {
      result.missing += 1;
      push({ kind: 'missing', path });
      continue;
    }
    const other = b.styles[path];
    if (other !== value) {
      result.styleDiffs += 1;
      const va = value.split('|');
      const vb = other.split('|');
      const changed = props.map((p, i) => (va[i] !== vb[i] ? `${p}: ${va[i]} → ${vb[i]}` : null)).filter(Boolean);
      push({ kind: 'style', path, changed });
    }
    if (a.boxes?.[path] !== b.boxes?.[path]) result.boxDiffs += 1;
  }
  for (const path of Object.keys(b.styles)) {
    if (!(path in a.styles)) {
      result.extra += 1;
      push({ kind: 'extra', path });
    }
  }
  return result;
}

/**
 * 진입점.
 *
 * @returns {void}
 */
function main() {
  const args = process.argv.slice(2);
  const positional = [];
  let jsonOut = null;
  let detail = 20;
  let strictBoxes = false;
  for (let i = 0; i < args.length; i += 1) {
    if (args[i] === '--json') { jsonOut = args[i + 1]; i += 1; } else if (args[i] === '--detail') { detail = Number(args[i + 1]); i += 1; } else if (args[i] === '--boxes') strictBoxes = true;
    else positional.push(args[i]);
  }
  if (positional.length !== 2) {
    process.stderr.write('사용: compare-style-fingerprints.mjs <before> <after> [--json 파일] [--detail N] [--boxes]\n');
    process.exitCode = 2;
    return;
  }
  const [before, after] = positional;
  const pairs = [];
  const onlyIn = [];
  if (statSync(before).isDirectory()) {
    const left = listFingerprints(before);
    const right = new Set(listFingerprints(after));
    for (const rel of left) {
      if (right.has(rel)) { pairs.push([rel, join(before, rel), join(after, rel)]); right.delete(rel); } else onlyIn.push({ side: 'before', file: rel });
    }
    for (const rel of right) onlyIn.push({ side: 'after', file: rel });
  } else {
    pairs.push([`${before} ↔ ${after}`, before, after]);
  }
  const report = { files: pairs.length, onlyIn, failed: 0, boxOnly: 0, results: [] };
  for (const [name, fa, fb] of pairs) {
    const r = compare(load(fa), load(fb), detail);
    const fail = r.styleDiffs + r.missing + r.extra > 0 || (strictBoxes && r.boxDiffs > 0);
    if (fail) report.failed += 1;
    else if (r.boxDiffs > 0) report.boxOnly += 1;
    if (fail || r.boxDiffs > 0) report.results.push({ file: name, ...r });
    if (fail) {
      process.stdout.write(`✗ ${name} — 스타일 ${r.styleDiffs} · 사라짐 ${r.missing} · 생김 ${r.extra} · 상자 ${r.boxDiffs}\n`);
      for (const d of r.details.slice(0, 5)) process.stdout.write(`    ${d.kind} ${d.path}${d.changed ? ` :: ${d.changed.join('; ')}` : ''}\n`);
    }
  }
  for (const o of onlyIn) process.stdout.write(`? ${o.side} 에만 있음: ${o.file}\n`);
  process.stdout.write(`비교 ${report.files}건 — 실패 ${report.failed}, 상자만 다름 ${report.boxOnly}, 짝 없음 ${onlyIn.length}\n`);
  if (jsonOut) writeFileSync(jsonOut, JSON.stringify(report, null, 2));
  process.exitCode = report.failed || onlyIn.length ? 1 : 0;
}

main();
