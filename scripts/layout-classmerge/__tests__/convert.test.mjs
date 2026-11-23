/**
 * 변환 도구 통합 시험 — 임시 폴더에 `.g7.html`+`.json` 짝을 만들고 실제 컴파일러로 돌린다.
 *
 * 짝 `.json` 은 `compile-batch.php` 의 write(= `layout:decompile --sync` 와 같은 LayoutJsonWriter)로 굽는다.
 * 쓰기 실행의 굽기는 실제 `php artisan layout:decompile <임시 파일> --sync` 를 부른다. 번들 파일은 읽지도 쓰지 않는다.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { run } from '../convert.mjs';
import { compileBatch } from '../lib/php.mjs';

const CARD = `<!-- 카드 조각: 모바일은 패딩만 줄인다 -->
<partial description="classMerge 변환 시험">
  <div class="w-full max-w-md p-8 bg-white rounded-lg shadow">
    <!-- 모바일 구간 — 이 주석은 그대로 남아야 한다 -->
    <responsive on="portable" class="w-full max-w-none p-6 bg-white rounded-lg shadow"/>
    <!-- <responsive on="portable" class="주석 안 태그는 세지 않는다"/> -->
    <div class="text-sm text-gray-500 flex">
      <responsive on="portable" class="text-xs flex"/>
    </div>
    <div class="flex p-4 {{_local.wide ? 'gap-4' : 'gap-2'}}">
      <responsive on="portable" class="flex p-2"/>
    </div>
    <div class="flex p-4 gap-2">
      <responsive on="portable" class="flex p-4"/>
    </div>
    <div class="flex p-4" title="기본">
      <responsive
        on="desktop"
        class="flex p-6"
        title="넓은 화면"/>
    </div>
    <div class="p-4 [&amp;&gt;svg]:w-4">
      <responsive on="portable" class="p-4 [&amp;&gt;svg]:w-4 [&amp;&gt;svg]:h-4"/>
    </div>
  </div>
</partial>
`;

const CARD_EXPECTED = CARD.replace('class="w-full max-w-none p-6 bg-white rounded-lg shadow"', 'class-merge="max-w-none p-6"')
  .replace('class="flex p-6"', 'class-merge="p-6"')
  .replace('class="p-4 [&amp;&gt;svg]:w-4 [&amp;&gt;svg]:h-4"', 'class-merge="[&amp;&gt;svg]:h-4"');

// 같은 (구간 키, class) 묶음에서 판정이 갈린다 — 문서 순서로 짝지어 확인 컴파일 후 변환
const AMBIGUOUS = `<partial description="같은 구간 값, 다른 기본">
  <div class="flex flex-col">
    <div class="flex-center flex-wrap gap-2 flex-1">
      <responsive on="portable" class="flex gap-2 w-full"/>
    </div>
    <div class="hidden">
      <responsive on="portable" class="flex gap-2 w-full"/>
    </div>
  </div>
</partial>
`;

// 짝 .json 을 손으로 어긋나게 만든다 — 파일 전체를 건너뛰어야 한다(변환 가능한 구간이 있어도)
const MISMATCH = `<partial description="1:1 불일치">
  <div class="flex p-4">
    <responsive on="portable" class="flex p-6"/>
    <div class="m-4 text-sm">
      <responsive on="portable" class="m-2 text-sm"/>
    </div>
  </div>
</partial>
`;

let dir;
const file = (name) => path.join(dir, name);
const read = (name) => fs.readFileSync(file(name), 'utf8');

function bakePairs(names) {
  const results = compileBatch(names.map((n) => ({ path: file(n), write: true })));
  for (const r of results) if (!r.ok) throw new Error(`픽스처 컴파일 실패: ${r.path} ${r.error}`);
}

function snapshot() {
  return Object.fromEntries(fs.readdirSync(dir).sort().map((n) => [n, fs.readFileSync(file(n), 'utf8')]));
}

beforeAll(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'g7-classmerge-'));
  fs.writeFileSync(file('card.g7.html'), CARD);
  fs.writeFileSync(file('ambiguous.g7.html'), AMBIGUOUS);
  fs.writeFileSync(file('mismatch.g7.html'), MISMATCH);
  fs.writeFileSync(file('orphan.g7.html'), '<partial description="짝 없음"><div class="p-4"><responsive on="portable" class="p-2"/></div></partial>\n');
  bakePairs(['card.g7.html', 'ambiguous.g7.html', 'mismatch.g7.html']);
  const mismatch = JSON.parse(read('mismatch.json'));
  mismatch.children[0].responsive.portable.props.className = 'm-2 text-base';
  fs.writeFileSync(file('mismatch.json'), JSON.stringify(mismatch, null, 4));
});

afterAll(() => {
  if (dir) fs.rmSync(dir, { recursive: true, force: true });
});

const fileReport = (report, name) => report.files.find((f) => f.file.endsWith(`/${name}`) || f.file.endsWith(`\\${name}`));

describe('dry-run', () => {
  let report;
  let before;
  beforeAll(async () => {
    before = snapshot();
    report = await run({ root: dir, dryRun: true, verify: true, report: path.join(dir, '..', `${path.basename(dir)}-report.json`) });
  });
  afterAll(() => fs.rmSync(path.join(dir, '..', `${path.basename(dir)}-report.json`), { force: true }));

  it('파일을 하나도 쓰지 않는다', () => {
    expect(snapshot()).toEqual(before);
  });

  it('구간 판정과 건너뜀 사유를 센다', () => {
    const card = fileReport(report, 'card.g7.html');
    expect(card.status).toBe('changed');
    expect(card.segments.map((s) => [s.key, s.status, s.reason])).toEqual([
      ['portable', 'convertible', null],
      ['portable', 'skip', 'removed-base-token'],
      ['portable', 'skip', 'binding'],
      ['portable', 'skip', 'pure-removal'],
      ['desktop', 'convertible', null],
      ['portable', 'convertible', null],
    ]);
    expect(card.segments[4].otherProps).toEqual(['title']);
    expect(report.summary.segmentSkips).toMatchObject({ 'removed-base-token': 2, binding: 1, 'pure-removal': 1 });
  });

  it('1:1 이 맞지 않는 파일은 변환 가능한 구간이 있어도 통째로 건너뛴다', () => {
    const mismatch = fileReport(report, 'mismatch.g7.html');
    expect(mismatch.status).toBe('skipped');
    expect(mismatch.reason).toBe('count-mismatch');
    expect(mismatch.segments.some((s) => s.status === 'convertible')).toBe(true);
    expect(mismatch.segments.some((s) => s.converted)).toBe(false);
    expect(report.summary.fileSkips).toMatchObject({ 'count-mismatch': 1, 'no-json': 1 });
  });

  it('같은 묶음에서 판정이 갈린 파일은 순서로 짝지어 확인 컴파일을 통과한 뒤 바꾼다', () => {
    const ambiguous = fileReport(report, 'ambiguous.g7.html');
    expect(ambiguous.status).toBe('changed');
    expect(ambiguous.orderAligned).toHaveLength(1);
    expect(ambiguous.segments.map((s) => s.converted)).toEqual([false, true]);
  });

  it('메모리 컴파일 verify 가 0 차이', () => {
    expect(report.summary.verify).toMatchObject({ ran: true, source: 'compiled-in-memory', files: 2, diffs: 0, structuralDiffs: 0, compileErrors: 0 });
    expect(report.exitCode).toBe(0);
  });

  it('--min-overlap 미만 구간은 같은 결과여도 바꾸지 않는다', async () => {
    const strict = await run({ root: dir, dryRun: true, minOverlap: 0.8 });
    const card = fileReport(strict, 'card.g7.html');
    expect(card.segments.map((s) => [s.overlap, s.reason])).toEqual([
      [0.667, 'below-min-overlap'],
      [0.333, 'removed-base-token'],
      [null, 'binding'],
      [0.667, 'pure-removal'],
      [0.5, 'below-min-overlap'],
      [1, null],
    ]);
    expect(card.segments.filter((s) => s.converted)).toHaveLength(1);
    expect(snapshot()).toEqual(before);
  });

  it('보고서 파일을 쓴다', () => {
    const written = JSON.parse(fs.readFileSync(path.join(dir, '..', `${path.basename(dir)}-report.json`), 'utf8'));
    expect(written.summary.converted).toBe(report.summary.converted);
    expect(Object.keys(written.reasons.segment)).toContain('removed-base-token');
  });
});

describe('--no-sync 쓰기', () => {
  let copy;
  beforeAll(() => {
    copy = fs.mkdtempSync(path.join(os.tmpdir(), 'g7-classmerge-nosync-'));
    for (const n of ['card.g7.html', 'card.json']) fs.copyFileSync(file(n), path.join(copy, n));
  });
  afterAll(() => fs.rmSync(copy, { recursive: true, force: true }));

  it('.g7.html 만 고치고 .json 은 그대로 두며, verify 는 메모리 컴파일로 0 차이', async () => {
    const jsonBefore = fs.readFileSync(path.join(copy, 'card.json'), 'utf8');
    const report = await run({ root: copy, verify: true, noSync: true });
    expect(fs.readFileSync(path.join(copy, 'card.g7.html'), 'utf8')).toBe(CARD_EXPECTED);
    expect(fs.readFileSync(path.join(copy, 'card.json'), 'utf8')).toBe(jsonBefore);
    expect(report.summary.sync.ran).toBe(false);
    expect(report.summary.verify).toMatchObject({ source: 'compiled-in-memory', diffs: 0, structuralDiffs: 0 });
  });
});

describe('쓰기 + artisan 굽기 + verify', () => {
  let report;
  beforeAll(async () => {
    report = await run({ root: dir, verify: true, jobs: 2 });
  });

  it('바꾸는 것은 class 속성 하나뿐 — 주석·줄 배치·다른 속성은 그대로', () => {
    expect(read('card.g7.html')).toBe(CARD_EXPECTED);
    expect(read('ambiguous.g7.html')).toBe(AMBIGUOUS.replace(/(<div class="hidden">\s*<responsive on="portable" )class="flex gap-2 w-full"/, '$1class-merge="flex gap-2 w-full"'));
    expect(read('mismatch.g7.html')).toBe(MISMATCH);
  });

  it('짝 .json 을 artisan 으로 다시 굽는다', () => {
    expect(report.summary.sync).toMatchObject({ ran: true, files: 2, failed: 0 });
    const json = JSON.parse(read('card.json'));
    expect(json.responsive.portable).toEqual({ comment: '모바일 구간 — 이 주석은 그대로 남아야 한다', classMerge: 'max-w-none p-6' });
    expect(json.children[3].responsive.desktop).toMatchObject({ classMerge: 'p-6', props: { title: '넓은 화면' } });
    expect(json.children[3].responsive.desktop.props.className).toBeUndefined();
    expect(json.children[4].responsive.portable.classMerge).toBe('[&>svg]:h-4');
  });

  it('다시 구운 .json 으로 verify 가 0 차이', () => {
    expect(report.summary.verify).toMatchObject({ ran: true, source: 'disk', files: 2, diffs: 0, structuralDiffs: 0, compileErrors: 0 });
    expect(report.exitCode).toBe(0);
  });

  it('두 번 돌려도 같다(멱등)', async () => {
    const before = snapshot();
    const again = await run({ root: dir, verify: true });
    expect(snapshot()).toEqual(before);
    expect(again.summary.filesChanged).toBe(0);
    expect(again.summary.converted).toBe(0);
    expect(again.summary.convertible).toBe(fileReport(again, 'mismatch.g7.html').segments.filter((s) => s.status === 'convertible').length);
  });
});
