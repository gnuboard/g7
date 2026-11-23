/**
 * PHP 호출 — 컴파일 도우미(한 번 부팅으로 여러 파일)와 `php artisan layout:decompile --sync`(파일마다).
 */
import { spawn, spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { REPO_ROOT } from './merge-engine.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const PHP = process.env.PHP_BINARY || 'php';
const COMPILE_BATCH = path.resolve(here, '..', 'compile-batch.php');
const CHUNK = 150;

/**
 * 컴파일 도우미를 부른다.
 *
 * @param {Array<{path: string, source?: string, write?: boolean}>} requests 요청 목록(절대 경로)
 * @returns {Array<{path: string, ok: boolean, json: unknown, outcome: string|null, error: string|null}>}
 */
export function compileBatch(requests) {
  const out = [];
  for (let i = 0; i < requests.length; i += CHUNK) {
    const chunk = requests.slice(i, i + CHUNK);
    const r = spawnSync(PHP, [COMPILE_BATCH], {
      input: JSON.stringify(chunk),
      cwd: REPO_ROOT,
      encoding: 'utf8',
      maxBuffer: 1024 * 1024 * 1024,
    });
    if (r.error) throw new Error(`compile-batch 실행 실패: ${r.error.message}`);
    if (r.status !== 0) throw new Error(`compile-batch 실패(exit ${r.status}): ${(r.stderr || r.stdout || '').slice(0, 2000)}`);
    const start = r.stdout.indexOf('[');
    out.push(...JSON.parse(start > 0 ? r.stdout.slice(start) : r.stdout));
  }
  return out;
}

/**
 * `php artisan layout:decompile <파일> --sync` 를 파일마다 돌린다 (동시 실행 jobs 개).
 *
 * @param {string[]} files `.g7.html` 절대 경로
 * @param {{jobs?: number}} [options]
 * @returns {Promise<Array<{file: string, ok: boolean, status: number|null, output: string}>>}
 */
export async function artisanSync(files, options = {}) {
  const jobs = Math.max(1, options.jobs ?? 4);
  const results = new Array(files.length);
  let next = 0;
  const worker = async () => {
    while (next < files.length) {
      const index = next++;
      results[index] = await runOne(files[index]);
    }
  };
  await Promise.all(Array.from({ length: Math.min(jobs, files.length) }, worker));
  return results;
}

function runOne(file) {
  return new Promise((resolve) => {
    const child = spawn(PHP, ['artisan', 'layout:decompile', file, '--sync'], { cwd: REPO_ROOT });
    let output = '';
    child.stdout.on('data', (d) => (output += d));
    child.stderr.on('data', (d) => (output += d));
    child.on('error', (error) => resolve({ file, ok: false, status: null, output: `${output}${error.message}` }));
    child.on('close', (status) => resolve({ file, ok: status === 0, status, output: output.replace(/\x1b\[[0-9;]*m/g, '').trim() }));
  });
}
