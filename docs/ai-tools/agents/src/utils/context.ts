/**
 * 그누보드7 프로젝트 컨텍스트 로더
 */
import * as fs from 'fs';
import * as path from 'path';
import { logger } from './logger.js';

const G7_PROJECT_ROOT = process.env.G7_PROJECT_ROOT || '.';

/**
 * AGENTS.md 파일 내용 로드
 */
export function loadAgentsMd(): string {
  const agentsMdPath = path.join(G7_PROJECT_ROOT, 'AGENTS.md');

  try {
    return fs.readFileSync(agentsMdPath, 'utf-8');
  } catch (error) {
    logger.warn('AGENTS.md 파일을 찾을 수 없습니다.');
    return '';
  }
}

/**
 * 규정 문서 로드
 */
export function loadDoc(docPath: string): string {
  const fullPath = path.join(G7_PROJECT_ROOT, 'docs', docPath);

  try {
    return fs.readFileSync(fullPath, 'utf-8');
  } catch (error) {
    logger.warn(`규정 문서를 찾을 수 없습니다: ${docPath}`);
    return '';
  }
}

/**
 * 여러 규정 문서 로드
 */
export function loadDocs(docPaths: string[]): Record<string, string> {
  const docs: Record<string, string> = {};

  for (const docPath of docPaths) {
    docs[docPath] = loadDoc(docPath);
  }

  return docs;
}

/**
 * 규정 문서 검색
 */
export function searchDocs(query: string, category?: string): string[] {
  const docsPath = path.join(G7_PROJECT_ROOT, 'docs');
  const searchPath = category ? path.join(docsPath, category) : docsPath;
  const results: string[] = [];

  function searchDir(dir: string) {
    try {
      const entries = fs.readdirSync(dir, { withFileTypes: true });

      for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
          searchDir(fullPath);
        } else if (entry.name.endsWith('.md')) {
          const content = fs.readFileSync(fullPath, 'utf-8');
          if (content.toLowerCase().includes(query.toLowerCase())) {
            results.push(path.relative(docsPath, fullPath));
          }
        }
      }
    } catch (error) {
      // 디렉토리 접근 오류 무시
    }
  }

  searchDir(searchPath);
  return results;
}

/**
 * 프로젝트 루트 경로 반환
 */
export function getProjectRoot(): string {
  return G7_PROJECT_ROOT;
}

/**
 * 파일 존재 여부 확인
 */
export function fileExists(relativePath: string): boolean {
  const fullPath = path.join(G7_PROJECT_ROOT, relativePath);
  return fs.existsSync(fullPath);
}

/**
 * 파일 내용 읽기
 */
export function readFile(relativePath: string): string | null {
  const fullPath = path.join(G7_PROJECT_ROOT, relativePath);

  try {
    return fs.readFileSync(fullPath, 'utf-8');
  } catch {
    return null;
  }
}
