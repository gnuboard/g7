/**
 * assembleEditorSpecFixture.ts — 분할 editor-spec.json 합본 테스트 헬퍼
 *
 * editor-spec.json 이 S7(ce21ab9d)에서 상위 블록 단위로 분할되었다(manifest +
 * `$include` 맵 → `editor-spec/{block}.json`). 런타임은 `EditorSpecAssembler.php`
 * 가 합본하지만, 디스크 JSON 을 직접 읽던 테스트는 합본 로직이 없어 분할 후 controls/
 * componentCapabilities 등 블록 키가 비어 깨졌다.
 *
 * 본 헬퍼는 PHP 합본기와 동일한 규칙(manifest 의 `$include` 를 manifest 디렉토리 기준
 * 으로 해석 → top-level merge)을 JS 로 재현해, 분할/미분할 양쪽에서 동일한 단일 spec 을
 * 돌려준다. include 부재(구버전)면 manifest 원본 그대로(하위 호환).
 *
 */

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';

/**
 * manifest 절대 경로를 받아 `$include` 블록을 합본한 단일 spec 을 반환한다.
 * PHP `EditorSpecAssembler::assemble` 와 동일 규칙.
 *
 * @param manifestPath editor-spec.json manifest 의 절대 경로
 * @return 합본된 spec 객체
 */
export function assembleEditorSpec(manifestPath: string): Record<string, unknown> {
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf-8')) as Record<string, unknown>;
  const includes = manifest['$include'];
  delete manifest['$include'];

  if (!includes || typeof includes !== 'object') {
    return manifest; // 미분할(구버전) — 원본 그대로.
  }

  const baseDir = dirname(manifestPath);
  for (const [key, relative] of Object.entries(includes as Record<string, unknown>)) {
    if (typeof relative !== 'string' || relative === '') continue;
    const blockPath = resolve(baseDir, relative.split('/').join(sep));
    if (!existsSync(blockPath)) continue; // 무손실 디그레이드 — 해당 키 누락.
    try {
      manifest[key] = JSON.parse(readFileSync(blockPath, 'utf-8'));
    } catch {
      // 파싱 실패 — 해당 키 누락(디그레이드).
    }
  }

  return manifest;
}

/** 레포 루트 기준 상대 경로의 editor-spec.json manifest 를 합본해 반환. */
export function assembleEditorSpecFromRepo(repoRoot: string, relativeSpecPath: string): Record<string, unknown> {
  return assembleEditorSpec(resolve(repoRoot, relativeSpecPath));
}
