/**
 * 규정 문서 검색 도구
 * docs 디렉토리에서 규정 문서 검색
 */
import * as fs from 'fs';
import * as path from 'path';
import type { ToolResult } from '../../types/index.js';

export type DocCategory = 'backend' | 'frontend' | 'extension' | 'database' | 'testing' | 'all';

export interface SearchOptions {
  query: string;
  category?: DocCategory;
  maxResults?: number;
}

export interface DocSearchResult {
  file: string;
  title: string;
  snippet: string;
  relevance: number;
}

export function searchDocs(
  projectRoot: string,
  options: SearchOptions
): ToolResult {
  const { query, category = 'all', maxResults = 5 } = options;
  const docsPath = path.join(projectRoot, 'docs');
  const results: DocSearchResult[] = [];

  const searchPaths = category === 'all'
    ? [docsPath]
    : [path.join(docsPath, category)];

  // 특수 파일 매핑
  const specialFiles: Record<string, string> = {
    database: path.join(docsPath, 'database-guide.md'),
    testing: path.join(docsPath, 'testing-guide.md'),
  };

  // 카테고리별 특수 파일 추가
  if (category !== 'all' && specialFiles[category]) {
    searchPaths.push(specialFiles[category]);
  }

  for (const searchPath of searchPaths) {
    if (fs.existsSync(searchPath)) {
      if (fs.statSync(searchPath).isDirectory()) {
        searchDirectory(searchPath, docsPath, query, results);
      } else {
        searchFile(searchPath, docsPath, query, results);
      }
    }
  }

  // 관련도 순으로 정렬
  results.sort((a, b) => b.relevance - a.relevance);

  // 결과 제한
  const limitedResults = results.slice(0, maxResults);

  if (limitedResults.length === 0) {
    return {
      success: true,
      message: '검색 결과가 없습니다',
      data: [],
    };
  }

  return {
    success: true,
    message: `${limitedResults.length}개의 문서를 찾았습니다`,
    data: limitedResults,
  };
}

function searchDirectory(
  dirPath: string,
  docsPath: string,
  query: string,
  results: DocSearchResult[]
): void {
  try {
    const entries = fs.readdirSync(dirPath, { withFileTypes: true });

    for (const entry of entries) {
      const fullPath = path.join(dirPath, entry.name);

      if (entry.isDirectory()) {
        searchDirectory(fullPath, docsPath, query, results);
      } else if (entry.name.endsWith('.md')) {
        searchFile(fullPath, docsPath, query, results);
      }
    }
  } catch (error) {
    // 디렉토리 접근 오류 무시
  }
}

function searchFile(
  filePath: string,
  docsPath: string,
  query: string,
  results: DocSearchResult[]
): void {
  try {
    const content = fs.readFileSync(filePath, 'utf-8');
    const relativePath = path.relative(docsPath, filePath);
    const lowerContent = content.toLowerCase();
    const lowerQuery = query.toLowerCase();

    // 쿼리가 포함되어 있는지 확인
    if (!lowerContent.includes(lowerQuery)) {
      return;
    }

    // 제목 추출 (첫 번째 # 라인)
    const titleMatch = content.match(/^#\s+(.+)$/m);
    const title = titleMatch ? titleMatch[1] : relativePath;

    // 관련도 계산
    let relevance = 0;

    // 파일명에 쿼리 포함
    if (relativePath.toLowerCase().includes(lowerQuery)) {
      relevance += 10;
    }

    // 제목에 쿼리 포함
    if (title.toLowerCase().includes(lowerQuery)) {
      relevance += 5;
    }

    // 쿼리 등장 횟수
    const matches = lowerContent.split(lowerQuery).length - 1;
    relevance += Math.min(matches, 10);

    // TL;DR 섹션에 포함
    if (content.includes('TL;DR') && content.split('TL;DR')[1]?.toLowerCase().includes(lowerQuery)) {
      relevance += 3;
    }

    // 스니펫 추출
    const snippet = extractSnippet(content, query);

    results.push({
      file: relativePath,
      title,
      snippet,
      relevance,
    });
  } catch (error) {
    // 파일 읽기 오류 무시
  }
}

function extractSnippet(content: string, query: string): string {
  const lowerContent = content.toLowerCase();
  const lowerQuery = query.toLowerCase();
  const index = lowerContent.indexOf(lowerQuery);

  if (index === -1) {
    // TL;DR 섹션이 있으면 그것을 반환
    const tldrMatch = content.match(/## TL;DR[^#]*(```[\s\S]*?```)/);
    if (tldrMatch) {
      return tldrMatch[1].substring(0, 300) + '...';
    }

    // 첫 문단 반환
    const lines = content.split('\n').filter(l => l.trim() && !l.startsWith('#'));
    return lines.slice(0, 3).join('\n').substring(0, 300) + '...';
  }

  // 쿼리 주변 컨텍스트 추출
  const start = Math.max(0, index - 100);
  const end = Math.min(content.length, index + query.length + 200);
  let snippet = content.substring(start, end);

  // 시작/끝 정리
  if (start > 0) {
    snippet = '...' + snippet.substring(snippet.indexOf(' ') + 1);
  }
  if (end < content.length) {
    snippet = snippet.substring(0, snippet.lastIndexOf(' ')) + '...';
  }

  return snippet;
}

/**
 * 특정 문서 읽기
 */
export function readDoc(
  projectRoot: string,
  docPath: string
): ToolResult {
  const fullPath = path.join(projectRoot, 'docs', docPath);

  if (!fs.existsSync(fullPath)) {
    return {
      success: false,
      message: `문서를 찾을 수 없습니다: ${docPath}`,
    };
  }

  try {
    const content = fs.readFileSync(fullPath, 'utf-8');

    return {
      success: true,
      message: `문서 로드 완료: ${docPath}`,
      data: content,
    };
  } catch (error: any) {
    return {
      success: false,
      message: `문서 읽기 오류: ${error.message}`,
    };
  }
}

/**
 * 규정 문서 목록 조회
 */
export function listDocs(
  projectRoot: string,
  category?: DocCategory
): ToolResult {
  const docsPath = path.join(projectRoot, 'docs');
  const docs: { path: string; title: string }[] = [];

  function scanDir(dir: string) {
    try {
      const entries = fs.readdirSync(dir, { withFileTypes: true });

      for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        const relativePath = path.relative(docsPath, fullPath);

        if (entry.isDirectory()) {
          if (!category || relativePath === category) {
            scanDir(fullPath);
          }
        } else if (entry.name.endsWith('.md')) {
          // 카테고리 필터링
          if (category && category !== 'all') {
            if (!relativePath.startsWith(category)) {
              continue;
            }
          }

          const content = fs.readFileSync(fullPath, 'utf-8');
          const titleMatch = content.match(/^#\s+(.+)$/m);
          const title = titleMatch ? titleMatch[1] : entry.name;

          docs.push({
            path: relativePath,
            title,
          });
        }
      }
    } catch (error) {
      // 오류 무시
    }
  }

  scanDir(docsPath);

  return {
    success: true,
    message: `${docs.length}개의 문서를 찾았습니다`,
    data: docs,
  };
}
