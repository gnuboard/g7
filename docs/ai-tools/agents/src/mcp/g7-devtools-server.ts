#!/usr/bin/env node
/**
 * G7 DevTools MCP 서버
 * AI 코딩 도구에서 G7 템플릿 엔진 디버깅 정보에 접근할 수 있는 MCP 서버
 *
 * 사용법:
 *   .vscode/mcp.json에 등록하여 AI 코딩 도구에서 자동으로 호출
 *
 * 제공 도구:
 *   - g7-state: 현재 상태 조회 (_global, _local, _computed)
 *   - g7-actions: 액션 실행 이력 조회 (resolvedParams 포함)
 *   - g7-cache: 캐시 통계 및 결정 추적 조회
 *   - g7-diagnose: 자동 진단
 *   - g7-lifecycle: 라이프사이클 정보 (마운트된 컴포넌트, 리스너)
 *   - g7-network: 네트워크 요청 정보
 *   - g7-form: Form 추적 정보
 *   - g7-expressions: 표현식 평가 이력
 *   - g7-datasources: 데이터소스 구조 및 경로 변환 추적
 *   - g7-handlers: 등록된 핸들러 목록 조회
 *   - g7-events: 컴포넌트 이벤트 구독/발생 이력
 *   - g7-performance: 렌더링 성능 정보
 *   - g7-conditionals: 조건부/반복 렌더링 정보
 *   - g7-websocket: WebSocket 정보
 *   - g7-renders: 상태-렌더링 추적 (setState → 렌더링 상관관계)
 *   - g7-layout: 현재 렌더링 중인 레이아웃 JSON 조회
 *   - g7-change-detection: 핸들러 실행 시 변경 감지 (early return, 상태 변경 없음 감지)
 *   - g7-sequence: Sequence 실행 추적 (각 액션별 상태 변화 추적)
 *   - g7-stale-closure: Stale Closure 감지 및 경고 조회
 *   - g7-nested-context: Nested Context 추적 (expandChildren, cellChildren, iteration, modal, slot)
 *   - g7-computed: Computed 의존성 추적 (재계산 트리거, 의존성 체인, 순환 감지)
 *   - g7-modal-state: 모달 상태 스코프 추적 (격리, 유출 감지, 중첩 모달 관계)
 */
import 'dotenv/config';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import * as fs from 'fs';
import * as path from 'path';
import { logger } from '../utils/logger.js';

// 프로젝트 루트 설정
const projectRoot = process.env.G7_PROJECT_ROOT || '.';
const debugDir = path.join(projectRoot, 'storage/debug-dump');

/**
 * MCP 응답 크기 제한 상수
 * MCP 프로토콜 자체에는 크기 제한이 없지만, 클라이언트별로 다름:
 * - Atlassian MCP: 25,000 토큰
 * - IBM MCP Context Forge: 응답 5MB, 청크 64KB
 * - 일반적인 안전 범위: 50KB ~ 100KB
 */
const MAX_RESPONSE_SIZE = 50000; // API 응답 데이터 최대 크기 (50KB)
const MAX_REQUEST_BODY_SIZE = 20000; // API 요청 본문 최대 크기 (20KB)
const MAX_STATE_VALUE_SIZE = 15000; // 상태 값 최대 크기 (15KB)

/**
 * 청크 분할 관련 상수
 */
const DEFAULT_CHUNK_SIZE = 20; // 기본 청크 크기

/**
 * 페이지네이션 정보 인터페이스
 */
interface PaginationInfo {
  total: number;
  offset: number;
  limit: number;
  hasMore: boolean;
  nextOffset: number | null;
}

/**
 * 배열에 페이지네이션 적용
 */
function applyPagination(
  items: any[],
  offset: number = 0,
  limit: number = DEFAULT_CHUNK_SIZE
): { items: any[]; pagination: PaginationInfo } {
  const total = items.length;
  const startIndex = Math.min(offset, total);
  const endIndex = Math.min(startIndex + limit, total);
  const paginatedItems = items.slice(startIndex, endIndex);
  const hasMore = endIndex < total;

  return {
    items: paginatedItems,
    pagination: {
      total,
      offset: startIndex,
      limit,
      hasMore,
      nextOffset: hasMore ? endIndex : null,
    },
  };
}

/**
 * 페이지네이션 정보를 출력 배열에 추가
 */
function addPaginationInfo(
  output: string[],
  pagination: PaginationInfo,
  itemName: string = '항목'
): void {
  const { total, offset, limit, hasMore, nextOffset } = pagination;
  const showing = Math.min(limit, total - offset);

  output.push('');
  output.push('---');
  output.push(`📄 **페이지네이션**: ${offset + 1}~${offset + showing} / 총 ${total}개 ${itemName}`);

  if (hasMore) {
    output.push(`➡️ **다음 페이지**: \`offset: ${nextOffset}, limit: ${limit}\``);
  }

  if (offset > 0) {
    const prevOffset = Math.max(0, offset - limit);
    output.push(`⬅️ **이전 페이지**: \`offset: ${prevOffset}, limit: ${limit}\``);
  }
}

// ============================================
// 멀티 Content Block 빌더
// ============================================

/**
 * 개별 content block 최대 크기
 * 이 크기를 넘으면 블록 내부에서 잘라냄
 */
const MAX_BLOCK_CHARS = 12000;

/**
 * content block 단위 truncation
 */
function truncateBlock(text: string, maxChars: number = MAX_BLOCK_CHARS): string {
  if (text.length <= maxChars) return text;
  const cut = text.substring(0, maxChars);
  const lastNl = cut.lastIndexOf('\n');
  return (lastNl > 0 ? cut.substring(0, lastNl) : cut) +
    '\n\n---\n⚠️ 이 블록이 잘렸습니다. `search`/`offset`/`limit`으로 범위를 좁혀주세요.';
}

interface ContentBlock {
  type: 'text';
  text: string;
}

interface SectionBuilder {
  /** 새 섹션 시작 — 이전 섹션을 별도 content block으로 확정 */
  section(title: string): void;
  /** 현재 섹션에 라인 추가 */
  push(...lines: string[]): void;
  /** JSON 데이터를 별도 content block으로 분리 (대용량 데이터의 주범) */
  pushJson(data: any, maxSize?: number): void;
  /** 페이지네이션 정보 추가 */
  pushPagination(pagination: PaginationInfo, itemName: string): void;
  /** 섹션별 content block 배열 반환 */
  build(): ContentBlock[];
}

/**
 * 멀티 content block 응답 빌더
 * 단일 거대 text 대신 의미론적 섹션별로 분리된 content block 배열을 생성
 *
 * @param header 최상위 제목 (예: '## G7 네트워크 정보')
 * @param timeAgo 덤프 경과 시간 (초). null이면 시간 표시 생략
 */
function createSectionBuilder(header: string, timeAgo: number | null): SectionBuilder {
  const blocks: ContentBlock[] = [];
  let currentLines: string[] = [];
  let headerEmitted = false;

  function emitHeader() {
    if (headerEmitted) return;
    headerEmitted = true;
    const lines = [header];
    if (timeAgo !== null) lines.push(`📅 덤프 시간: ${timeAgo}초 전`);
    blocks.push({ type: 'text', text: lines.join('\n') });
  }

  function flushCurrent() {
    if (currentLines.length === 0) return;
    blocks.push({ type: 'text', text: truncateBlock(currentLines.join('\n')) });
    currentLines = [];
  }

  return {
    section(title: string) {
      emitHeader();
      flushCurrent();
      currentLines = [title];
    },
    push(...lines: string[]) {
      if (!headerEmitted && currentLines.length === 0) {
        emitHeader();
      }
      currentLines.push(...lines);
    },
    pushJson(data: any, maxSize: number = MAX_RESPONSE_SIZE) {
      emitHeader();
      flushCurrent();
      const json = JSON.stringify(data, null, 2);
      const truncated = json.length > maxSize
        ? json.substring(0, maxSize) + '\n... (truncated)'
        : json;
      blocks.push({ type: 'text', text: '```json\n' + truncated + '\n```' });
    },
    pushPagination(pagination: PaginationInfo, itemName: string) {
      const lines: string[] = [];
      addPaginationInfo(lines, pagination, itemName);
      if (lines.length > 0) {
        currentLines.push(...lines);
      }
    },
    build(): ContentBlock[] {
      emitHeader();
      flushCurrent();
      return blocks.length > 0 ? blocks : [{ type: 'text', text: header }];
    }
  };
}

/**
 * 텍스트 기반 검색 필터 (배열용)
 * 각 항목을 JSON.stringify 후 search 문자열 포함 여부 확인
 */
function applySearch(items: any[], search: string | undefined): any[] {
  if (!search) return items;
  const lowerSearch = search.toLowerCase();
  return items.filter(item => {
    const text = JSON.stringify(item).toLowerCase();
    return text.includes(lowerSearch);
  });
}

/**
 * 텍스트 기반 검색 필터 (객체/JSON용 - g7-state, g7-layout 등)
 * 최상위 키/값에서 search 문자열을 검색하여 매칭되는 항목만 반환
 */
function filterObjectBySearch(obj: any, search: string): any {
  if (!search || typeof obj !== 'object' || obj === null) return obj;

  const lowerSearch = search.toLowerCase();
  const result: any = {};

  for (const [key, value] of Object.entries(obj)) {
    const keyMatch = key.toLowerCase().includes(lowerSearch);
    const valueStr = JSON.stringify(value).toLowerCase();
    const valueMatch = valueStr.includes(lowerSearch);

    if (keyMatch || valueMatch) {
      result[key] = value;
    }
  }

  return Object.keys(result).length > 0 ? result : null;
}

// MCP 서버 생성
const server = new McpServer({
  name: 'g7-devtools',
  version: '1.0.0',
});

/**
 * 디버그 파일 읽기 헬퍼
 */
function readDebugFile(filename: string): any {
  const filePath = path.join(debugDir, filename);

  if (!fs.existsSync(filePath)) {
    return null;
  }

  try {
    const content = fs.readFileSync(filePath, 'utf-8');
    return JSON.parse(content);
  } catch (error) {
    return null;
  }
}

/**
 * 파일 수정 시간 조회
 */
function getFileTimestamp(filename: string): number | null {
  const filePath = path.join(debugDir, filename);

  if (!fs.existsSync(filePath)) {
    return null;
  }

  try {
    const stats = fs.statSync(filePath);
    return stats.mtimeMs;
  } catch {
    return null;
  }
}

/**
 * 문자열 길이 제한 (truncate)
 */
function truncateString(str: string, maxLength: number): string {
  if (!str || str.length <= maxLength) {
    return str || '';
  }
  return str.substring(0, maxLength - 3) + '...';
}

/**
 * 객체를 문자열로 변환 후 길이 제한
 */
function truncateObject(obj: any, maxLength: number): any {
  if (obj === null || obj === undefined) {
    return obj;
  }
  const str = JSON.stringify(obj);
  if (str.length <= maxLength) {
    return obj;
  }
  // 너무 긴 경우 요약된 형태로 반환
  if (typeof obj === 'object') {
    if (Array.isArray(obj)) {
      return `[Array(${obj.length})]`;
    }
    const keys = Object.keys(obj);
    return `{${keys.slice(0, 3).join(', ')}${keys.length > 3 ? ', ...' : ''}}`;
  }
  return truncateString(String(obj), maxLength);
}

/**
 * 상태 덤프 안내 메시지
 */
const DUMP_INSTRUCTION = `
📋 상태 덤프 방법:
1. 브라우저에서 Ctrl+Shift+G로 DevTools 패널 열기
2. "서버로 덤프" 버튼 클릭
또는 브라우저 콘솔에서: G7DevTools.server.dumpState()
`;

// ============================================
// Tool: g7-state - 상태 조회
// ============================================
server.tool(
  'g7-state',
  'G7 템플릿 엔진의 현재 상태를 조회합니다 (_global, _local, _computed). 브라우저에서 상태 덤프 후 사용하세요.',
  {
    path: z.string().optional().describe('조회할 상태 경로 (예: "_global.user", "_local.form"). 생략 시 전체 상태 반환'),
    search: z.string().optional().describe('상태 키/값에서 텍스트 검색. 매칭되는 최상위 키만 반환'),
  },
  async ({ path: statePath, search }) => {
    const state = readDebugFile('state-latest.json');

    if (!state) {
      return {
        content: [{
          type: 'text',
          text: `❌ 상태 덤프 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('state-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let result = state;
    if (statePath) {
      const parts = statePath.split('.');
      for (const part of parts) {
        if (result && typeof result === 'object' && part in result) {
          result = result[part];
        } else {
          return {
            content: [{
              type: 'text',
              text: `❌ 경로 "${statePath}"를 찾을 수 없습니다.`,
            }],
            isError: true,
          };
        }
      }
    }

    // search 필터링
    if (search && typeof result === 'object' && result !== null) {
      const filtered = filterObjectBySearch(result, search);
      if (!filtered) {
        return {
          content: [{ type: 'text', text: `검색 결과 없음: "${search}"` }],
        };
      }
      result = filtered;
    }

    const sb = createSectionBuilder('## G7 상태', timeAgo);

    if (search) {
      sb.push(`🔍 검색: "${search}"`, '');
    }

    // 대용량 상태 자동 요약
    const stateStr = JSON.stringify(result, null, 2);
    if (!statePath && !search && stateStr.length > MAX_STATE_VALUE_SIZE) {
      sb.section('### 전체 상태 (요약)');
      sb.push('', '> ⚠️ 상태 데이터가 큽니다. `path` 또는 `search`로 범위를 좁혀주세요.', '');
      sb.push('| 키 | 타입 | 크기 |');
      sb.push('|-----|------|-----:|');
      for (const [key, value] of Object.entries(result as Record<string, any>)) {
        const size = JSON.stringify(value).length;
        const valueType = Array.isArray(value) ? `array(${value.length})` : typeof value;
        sb.push(`| \`${key}\` | ${valueType} | ${size.toLocaleString()}자 |`);
      }
    } else {
      sb.section(statePath ? `### ${statePath}` : '### 전체 상태');
      sb.pushJson(result, MAX_STATE_VALUE_SIZE);
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-actions - 액션 이력 조회
// ============================================
server.tool(
  'g7-actions',
  'G7 템플릿 엔진의 액션 실행 이력을 조회합니다. 핸들러 타입, 상태, 소요 시간, 해석된 params 등을 확인할 수 있습니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('조회할 최대 개수 (기본: 20)'),
    filter: z.string().optional().describe('필터링할 핸들러 타입 또는 상태 (예: "apiCall", "error", "setState")'),
    search: z.string().optional().describe('텍스트 검색 (항목 내 문자열 매칭)'),
    showParams: z.boolean().optional().describe('params 포함 여부 (기본: false)'),
  },
  async ({ offset = 0, limit = 20, filter, search, showParams = false }) => {
    const actions = readDebugFile('actions-latest.json');

    if (!actions || !Array.isArray(actions)) {
      return {
        content: [{
          type: 'text',
          text: `❌ 액션 이력 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    let filtered = actions;
    if (filter) {
      filtered = actions.filter((a: any) =>
        a.type === filter || a.status === filter || a.handler === filter
      );
    }
    filtered = applySearch(filtered, search);

    // 페이지네이션 적용
    const { items: paginatedActions, pagination } = applyPagination(
      filtered.reverse(), // 최신 순
      offset,
      limit
    );
    const timestamp = getFileTimestamp('actions-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const sb = createSectionBuilder('## G7 액션 이력', timeAgo);
    sb.push(`📊 전체: ${actions.length}개, 필터 결과: ${filtered.length}개`, '');

    for (const action of paginatedActions) {
      const statusIcon = action.status === 'success' ? '✅' :
                         action.status === 'error' ? '❌' :
                         action.status === 'started' ? '🔄' : '⏳';

      sb.section(`### ${statusIcon} ${action.type || action.handler}`);
      sb.push(`- ID: ${action.id}`);
      sb.push(`- 상태: ${action.status}`);
      if (action.duration != null) {
        sb.push(`- 소요 시간: ${action.duration.toFixed(2)}ms`);
      }
      if (action.error) {
        sb.push(`- 에러: ${action.error.message || action.error}`);
      }
      if (showParams) {
        if (action.params) {
          sb.push(`- **params** (원본 템플릿):`);
          sb.pushJson(action.params);
        }
        if (action.resolvedParams) {
          sb.push(`- **resolvedParams** (해석된 값):`);
          sb.pushJson(action.resolvedParams);
        }
      }
    }

    sb.pushPagination(pagination, '액션');

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-cache - 캐시 통계 및 결정 추적 조회
// ============================================
server.tool(
  'g7-cache',
  '바인딩 엔진의 캐시 통계 및 결정 과정을 조회합니다. Hit/Miss 비율, 캐시 스킵 이유, 결정 로그 등을 확인할 수 있습니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    showDecisions: z.boolean().optional().describe('캐시 결정 로그 표시 (기본: false)'),
    limit: z.number().optional().describe('결정 로그 표시 개수 (기본: 20)'),
    decision: z.enum(['all', 'cache_hit', 'cache_miss', 'skip_cache', 'invalidate']).optional().describe('결정 유형 필터 (기본: all)'),
    search: z.string().optional().describe('텍스트 검색 (결정 로그 내 문자열 매칭)'),
    showReasons: z.boolean().optional().describe('결정 이유별 통계 표시 (기본: true)'),
  },
  async ({ offset = 0, showDecisions = false, limit = 20, decision = 'all', search, showReasons = true }) => {
    const cache = readDebugFile('cache-latest.json');
    const cacheDecisions = readDebugFile('cache-decisions-latest.json');

    if (!cache && !cacheDecisions) {
      return {
        content: [{
          type: 'text',
          text: `❌ 캐시 통계 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('cache-latest.json') || getFileTimestamp('cache-decisions-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const sb = createSectionBuilder('# 📦 G7 캐시 분석', timeAgo);

    // 기본 캐시 통계
    if (cache) {
      const total = (cache.hits || 0) + (cache.misses || 0);
      const hitRate = total > 0 ? ((cache.hits / total) * 100).toFixed(1) : '0';

      sb.section('## 📊 기본 통계');
      sb.push('', '| 항목 | 값 |');
      sb.push('|------|-----:|');
      sb.push(`| Cache Hits | ${cache.hits || 0} |`);
      sb.push(`| Cache Misses | ${cache.misses || 0} |`);
      sb.push(`| Hit Rate | ${hitRate}% |`);
      sb.push(`| 엔트리 수 | ${cache.entries || 0} |`);
      sb.push('');

      sb.section('### 분석');
      if (parseFloat(hitRate) < 50) {
        sb.push('⚠️ **캐시 히트율이 낮습니다.** 동일한 표현식이 반복 평가되고 있을 수 있습니다.');
      } else if (parseFloat(hitRate) > 90) {
        sb.push('✅ 캐시 히트율이 좋습니다.');
      } else {
        sb.push('ℹ️ 캐시 히트율이 보통입니다.');
      }

      if ((cache.misses || 0) > 1000) {
        sb.push('⚠️ **캐시 미스가 많습니다.** 바인딩 표현식을 최적화하는 것을 고려하세요.');
      }
    }

    // 캐시 결정 추적 (확장)
    if (cacheDecisions) {
      const { decisions = [], stats = {} } = cacheDecisions;

      sb.section('## 📋 결정 추적 통계');
      sb.push(`- 총 결정: ${stats.totalDecisions || 0}`);
      sb.push(`- 캐시 히트: ${stats.cacheHits || 0}`);
      sb.push(`- 캐시 미스: ${stats.cacheMisses || 0}`);
      sb.push(`- 스킵 캐시: ${stats.skipCacheCount || 0}`);
      sb.push(`- 무효화: ${stats.invalidateCount || 0}`);
      sb.push(`- 평균 히트율: ${((stats.avgHitRate || 0) * 100).toFixed(1)}%`);

      // 결정 이유별 통계
      if (showReasons && stats.byReason && Object.keys(stats.byReason).length > 0) {
        sb.section('### 결정 이유별 분포');
        sb.push('', '| 이유 | 횟수 |');
        sb.push('|------|-----:|');
        const sortedReasons = Object.entries(stats.byReason as Record<string, number>)
          .sort(([, a], [, b]) => b - a)
          .slice(0, 10);
        for (const [reason, count] of sortedReasons) {
          sb.push(`| ${reason} | ${count} |`);
        }

        // 문제 패턴 분석
        const skipCount = stats.skipCacheCount || 0;
        const totalCount = stats.totalDecisions || 1;
        if (skipCount / totalCount > 0.3) {
          sb.push('', '⚠️ **캐시 스킵 비율이 높습니다.** iteration 또는 액션 실행 중 캐시 스킵이 많이 발생하고 있습니다.');
        }
      }

      // 결정 로그
      if (showDecisions && decisions.length > 0) {
        sb.section('### 최근 결정 로그');

        let filteredDecisions = decisions;
        if (decision !== 'all') {
          filteredDecisions = decisions.filter((d: any) => d.decision === decision);
        }
        filteredDecisions = applySearch(filteredDecisions, search);

        // 페이지네이션 적용
        const { items: paginatedDecisions, pagination: decisionsPagination } = applyPagination(
          filteredDecisions.reverse(),
          offset,
          limit
        );

        for (const d of paginatedDecisions) {
          const decisionIcon = d.decision === 'cache_hit' ? '✅' :
                              d.decision === 'cache_miss' ? '❌' :
                              d.decision === 'skip_cache' ? '⏭️' : '🔄';

          sb.push(`#### ${decisionIcon} ${d.decision}`);
          sb.push(`- **표현식**: \`${d.expression?.substring(0, 80) || 'N/A'}\``);
          sb.push(`- **이유**: ${d.reason}`);

          if (d.context) {
            const contextParts: string[] = [];
            if (d.context.isInIteration) contextParts.push('iteration');
            if (d.context.isInAction) contextParts.push('action');
            if (d.context.skipCacheOption) contextParts.push('skipCache option');
            if (contextParts.length > 0) {
              sb.push(`- **컨텍스트**: ${contextParts.join(', ')}`);
            }
          }

          if (d.valueMatch !== undefined) {
            sb.push(`- **값 일치**: ${d.valueMatch ? '✅' : '❌'}`);
          }

          if (d.duration !== undefined) {
            sb.push(`- **소요시간**: ${d.duration}ms`);
          }
          sb.push('');
        }

        sb.pushPagination(decisionsPagination, '결정 로그');
      }
    }

    // 사용 팁
    sb.section('## 💡 사용 팁');
    sb.push('- `showDecisions: true` - 개별 캐시 결정 로그 표시');
    sb.push('- `decision: "skip_cache"` - 캐시 스킵 결정만 필터링');
    sb.push('- `offset: 0, limit: 20` - 페이지네이션 (기본값)');
    sb.push('');
    sb.push('### 캐시 최적화 방법');
    sb.push('- iteration 내에서는 `skipCache: true` 사용');
    sb.push('- 동적 값이 많은 표현식은 캐시 효율이 낮음');
    sb.push('- 액션 실행 중에는 자동으로 캐시가 스킵됨');

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-diagnose - 자동 진단
// ============================================
server.tool(
  'g7-diagnose',
  '증상을 기반으로 G7 템플릿 엔진 문제를 자동 진단합니다. 트러블슈팅 가이드의 규칙을 적용합니다.',
  {
    symptoms: z.array(z.string()).describe('증상 목록 (예: ["이전 값이 전송됨", "모든 행이 같은 값"])'),
  },
  async ({ symptoms }) => {
    // 진단 규칙 (서버 사이드)
    const rules = [
      {
        id: 'stale-closure',
        name: 'Stale Closure',
        keywords: ['이전 값', '오래된', '첫 값만', '저장 시', '클로저'],
        solution: 'G7Core.state.get()._global 사용',
        doc: 'troubleshooting-state.md#stale-closure',
        example: '// ❌ "body": { "email": "{{_global.email}}" }\n// ✅ "body": { "email": "{{G7Core.state.get()._global.email}}" }',
      },
      {
        id: 'cache-iteration',
        name: '캐시 반복 렌더링',
        keywords: ['같은 값', '첫 항목만', '중복', 'DataGrid', 'iteration'],
        solution: 'skipCache: true 추가',
        doc: 'troubleshooting-cache.md#사례4',
        example: '"props": { "skipCache": true }',
      },
      {
        id: 'sequence-merge',
        name: 'Sequence setState 병합 충돌',
        keywords: ['sequence', 'setState', '누락', '마지막만', '병합'],
        solution: 'currentState 추적 패턴 사용',
        doc: 'troubleshooting-state.md#사례3',
      },
      {
        id: 'init-actions-timing',
        name: 'init_actions 타이밍 이슈',
        keywords: ['init_actions', '초기값', 'undefined', '렌더링 안됨'],
        solution: '_global 상태 병합 확인',
        doc: 'troubleshooting-state.md#init_actions-관련-이슈',
      },
      {
        id: 'datakey-global',
        name: 'dataKey="_global.xxx" 미동작',
        keywords: ['dataKey', '_global', 'Form', '바인딩 안됨', 'Input'],
        solution: 'init_actions에서 _global 초기화 필수',
        doc: 'troubleshooting-state.md#datakey-자동-바인딩-이슈',
      },
      {
        id: 'orphaned-listener',
        name: '정리되지 않은 이벤트 리스너',
        keywords: ['메모리', '이벤트', '여러 번', '리스너', '누수'],
        solution: 'useEffect cleanup 또는 removeEventListener 추가',
        doc: 'troubleshooting-components.md#lifecycle',
      },
      {
        id: 'excessive-rerenders',
        name: '과도한 리렌더링',
        keywords: ['느림', '버벅', '렌더링', '성능', '지연'],
        solution: 'React.memo, useMemo, useCallback 사용',
        doc: 'troubleshooting-components.md#performance',
      },
      {
        id: 'duplicate-requests',
        name: '중복 API 요청',
        keywords: ['중복', '요청', '여러 번', 'API', '반복'],
        solution: 'debounce 추가, 데이터소스 의존성 확인',
        doc: 'troubleshooting-cache.md#duplicate-request',
      },
      {
        id: 'iteration-item-var',
        name: 'iteration 변수명 오류',
        keywords: ['item', 'index', 'iteration', '빈값', '바인딩'],
        solution: 'item_var, index_var 사용 (item, index 아님)',
        doc: 'troubleshooting-guide.md#iteration-바인딩-문제',
      },
      {
        id: 'form-no-datakey',
        name: 'Form dataKey 미설정',
        keywords: ['Form', 'dataKey', '저장 안됨', '빈 값'],
        solution: 'Form에 dataKey prop 추가',
        doc: 'troubleshooting-components.md#form-datakey',
      },
      {
        id: 'expression-undefined',
        name: '표현식 결과 undefined',
        keywords: ['undefined', '표현식', '바인딩', '빈 텍스트'],
        solution: 'Optional Chaining (?.) 또는 기본값 (??) 사용',
        doc: 'data-binding.md#optional-chaining',
      },
      // === DevTools 통합 인터페이스 개선: 추가 규칙 (2026-01-11) ===
      {
        id: 'context-state-mismatch',
        name: 'componentContext 상태 불일치',
        keywords: ['expandChildren', 'dynamicState', '상태 업데이트', 'UI 미반영', '체크박스', '확장 행', 'DataGrid 체크', 'row 확장', '__componentContext'],
        solution: 'renderExpandedContent에서 __componentContext.state를 _local에 병합하거나, DynamicRenderer.renderItemChildren 호출 시 context.state 전달',
        doc: 'troubleshooting-state.md#context-state-mismatch',
        example: '// expandChildren에서 state 접근\n"if": "{{__componentContext.state.expanded}}"',
      },
      {
        id: 'tailwind-purging',
        name: 'Tailwind CSS purging으로 스타일 미적용',
        keywords: ['보이지 않음', '투명', '흰색', '스타일 미적용', 'bg-', 'text-', 'rgba(0,0,0,0)', 'CSS 누락', '동적 클래스', 'safelist'],
        solution: 'tailwind.config.js의 safelist에 동적 클래스 추가, 또는 전체 클래스명을 상수로 정의',
        doc: 'troubleshooting-cache.md#tailwind-purging',
        example: '// tailwind.config.js\nmodule.exports = {\n  safelist: ["bg-red-500", "text-blue-600"]\n}',
      },
      {
        id: 'invisible-button',
        name: '보이지 않는 버튼/요소',
        keywords: ['투명 버튼', '클릭 안됨', '안 보임', '영역만 있음', 'opacity', 'visibility', 'display', 'z-index', '덮어씌워짐'],
        solution: 'DevTools Elements 패널에서 computed styles 확인. opacity, visibility, display, z-index 속성 체크',
        doc: 'troubleshooting-components.md#invisible-element',
        example: '// 부모 요소의 overflow:hidden 또는 자식의 position 확인',
      },
      {
        id: 'auth-token-missing',
        name: '인증 토큰 누락',
        keywords: ['로그인 안됨', '401', '인증 실패', '토큰 없음', 'Unauthorized', '세션 만료'],
        solution: 'AuthManager.getAccessToken() 확인, localStorage/sessionStorage의 토큰 존재 여부 확인',
        doc: 'troubleshooting-backend.md#auth-token-missing',
        example: '// 토큰 확인\nconsole.log(G7Core.auth?.getAccessToken())\n// 또는\nlocalStorage.getItem("access_token")',
      },
      {
        id: 'auth-header-missing',
        name: 'Authorization 헤더 누락',
        keywords: ['API 401', '헤더 없음', 'Bearer 토큰', 'Authorization', '인증 헤더'],
        solution: 'API 요청 시 Authorization 헤더 자동 추가 확인. apiCall 핸들러 사용 또는 수동으로 헤더 추가',
        doc: 'troubleshooting-backend.md#auth-header-missing',
        example: '// apiCall은 자동으로 Authorization 헤더 추가\n"handler": "apiCall"\n// 직접 추가 시\n"headers": { "Authorization": "Bearer {{G7Core.auth?.getAccessToken()}}" }',
      },
      {
        id: 'token-refresh-failed',
        name: '토큰 갱신 실패',
        keywords: ['토큰 갱신', 'refresh token', '갱신 실패', '재로그인', '세션 연장'],
        solution: 'refresh_token 유효성 확인, 서버 측 토큰 갱신 API 응답 확인',
        doc: 'troubleshooting-backend.md#token-refresh-failed',
        example: '// 토큰 갱신 시도\nG7Core.auth?.refreshToken()',
      },
      {
        id: 'layout-cache-auth',
        name: '레이아웃 캐시 인증 문제',
        keywords: ['이전 사용자', '로그아웃 후', '캐시 문제', '다른 사용자 데이터', '세션 혼동'],
        solution: '로그아웃 시 레이아웃 캐시 클리어, G7Core.cache.clear() 호출',
        doc: 'troubleshooting-cache.md#layout-cache-auth',
        example: '// 로그아웃 시 캐시 클리어\n"onSuccess": [{ "handler": "clearCache" }, { "handler": "navigate", "params": { "to": "/login" } }]',
      },
      // === Logger-DevTools 통합: 로그 관련 규칙 (2026-01-11) ===
      {
        id: 'excessive-errors',
        name: '과도한 에러 로그',
        keywords: ['에러', '오류', 'error', '콘솔 에러', '빨간색', '반복 에러', '에러 폭발'],
        solution: 'g7-logs 도구로 에러 로그 분석, 에러 발생 원인 추적. 반복 패턴 확인',
        doc: 'troubleshooting-guide.md#excessive-errors',
        example: '// MCP 도구로 에러 로그 확인\ng7-logs --level error --limit 20',
      },
      {
        id: 'binding-engine-warnings',
        name: '바인딩 엔진 경고',
        keywords: ['binding', 'expression', '평가 실패', 'warn', '경고', 'undefined 접근', 'DataBindingEngine'],
        solution: 'g7-logs 도구로 DataBindingEngine 관련 경고 확인, g7-expressions와 함께 분석',
        doc: 'troubleshooting-state.md#binding-warnings',
        example: '// 바인딩 관련 경고 필터링\ng7-logs --prefix DataBindingEngine --level warn',
      },
      {
        id: 'handler-execution-errors',
        name: '핸들러 실행 에러',
        keywords: ['handler', 'ActionDispatcher', '핸들러 에러', '액션 실패', '실행 오류'],
        solution: 'g7-logs로 ActionDispatcher 에러 확인 후, g7-actions로 해당 액션 이력 분석',
        doc: 'troubleshooting-guide.md#handler-errors',
        example: '// 핸들러 에러 로그 확인\ng7-logs --prefix ActionDispatcher --level error',
      },
      {
        id: 'datasource-fetch-errors',
        name: '데이터소스 fetch 에러',
        keywords: ['DataSourceManager', 'fetch', 'API 에러', '네트워크', '요청 실패', 'datasource'],
        solution: 'g7-logs로 DataSourceManager 에러 확인, g7-network로 실패한 요청 분석',
        doc: 'troubleshooting-backend.md#datasource-errors',
        example: '// 데이터소스 관련 로그 확인\ng7-logs --prefix DataSourceManager --showStack',
      },
    ];

    // 매칭 계산
    const matches: any[] = [];
    for (const rule of rules) {
      let score = 0;
      for (const symptom of symptoms) {
        const lowerSymptom = symptom.toLowerCase();
        for (const keyword of rule.keywords) {
          if (lowerSymptom.includes(keyword.toLowerCase())) {
            score++;
          }
        }
      }
      if (score > 0) {
        matches.push({
          ...rule,
          confidence: Math.min(score * 0.25, 0.95),
        });
      }
    }

    matches.sort((a, b) => b.confidence - a.confidence);

    if (matches.length === 0) {
      return {
        content: [{
          type: 'text',
          text: `## 진단 결과\n\n입력한 증상과 일치하는 패턴을 찾지 못했습니다.\n\n증상: ${symptoms.join(', ')}\n\n더 구체적인 증상을 입력하거나, G7DevTools 패널에서 상태/액션 이력을 확인해보세요.`,
        }],
      };
    }

    const sb = createSectionBuilder('## G7 자동 진단 결과', null);
    sb.push(`입력 증상: ${symptoms.join(', ')}`, '');

    for (const match of matches.slice(0, 5)) {
      const confidencePercent = (match.confidence * 100).toFixed(0);
      sb.section(`### 🔍 ${match.name} (신뢰도: ${confidencePercent}%)`);
      sb.push(`- **해결책**: ${match.solution}`);
      sb.push(`- **문서**: docs/frontend/${match.doc}`);
      if (match.example) {
        sb.push('- **예시**:');
        sb.pushJson(match.example);
      }
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-lifecycle - 라이프사이클 정보
// ============================================
server.tool(
  'g7-lifecycle',
  '마운트된 컴포넌트 목록과 정리되지 않은 이벤트 리스너를 조회합니다.',
  {
    search: z.string().optional().describe('텍스트 검색 (컴포넌트명, 리스너 등 항목 내 문자열 매칭)'),
  },
  async ({ search }) => {
    const lifecycle = readDebugFile('lifecycle-latest.json');

    if (!lifecycle) {
      return {
        content: [{
          type: 'text',
          text: `❌ 라이프사이클 정보 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('lifecycle-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let mounted = lifecycle.mountedComponents || [];
    let orphaned = lifecycle.orphanedListeners || [];
    mounted = applySearch(mounted, search);
    orphaned = applySearch(orphaned, search);

    const sb = createSectionBuilder('## G7 라이프사이클 정보', timeAgo);

    sb.section(`### 마운트된 컴포넌트 (${mounted.length}개)`);
    if (mounted.length === 0) {
      sb.push('(없음)');
    } else {
      sb.push('| ID | 이름 | 타입 |');
      sb.push('|----|------|------|');
      for (const comp of mounted.slice(0, 30)) {
        sb.push(`| ${comp.id || '-'} | ${comp.name || '-'} | ${comp.type || '-'} |`);
      }
      if (mounted.length > 30) {
        sb.push(`... 외 ${mounted.length - 30}개`);
      }
    }

    sb.section(`### ⚠️ 정리되지 않은 리스너 (${orphaned.length}개)`);
    if (orphaned.length === 0) {
      sb.push('✅ 모든 이벤트 리스너가 정상적으로 정리되었습니다.');
    } else {
      sb.push('| 컴포넌트 | 이벤트 타입 | 대상 |');
      sb.push('|----------|------------|------|');
      for (const listener of orphaned) {
        sb.push(`| ${listener.componentId || '-'} | ${listener.type || '-'} | ${listener.target || '-'} |`);
      }
      sb.push('', '⚠️ **메모리 누수 위험**: 위 리스너들이 컴포넌트 언마운트 후에도 남아있습니다.');
      sb.push('useEffect cleanup 함수에서 removeEventListener를 호출하세요.');
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-network - 네트워크 정보
// ============================================
server.tool(
  'g7-network',
  'API 요청 이력과 진행 중인 요청, 데이터소스 상태를 조회합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('조회할 최대 요청 수 (기본: 20)'),
    status: z.string().optional().describe('필터링할 상태 (success, error, pending)'),
    showResponse: z.boolean().optional().describe('응답 데이터 포함 여부 (기본: false, 데이터가 클 수 있음)'),
    showQueryParams: z.boolean().optional().describe('쿼리 파라미터 상세 표시 (기본: true)'),
    showRequestBody: z.boolean().optional().describe('요청 본문 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (URL, 메서드 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, limit = 20, status, showResponse = false, showQueryParams = true, showRequestBody = false, search }) => {
    const network = readDebugFile('network-latest.json');

    if (!network) {
      return {
        content: [{
          type: 'text',
          text: `❌ 네트워크 정보 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('network-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const active = network.activeRequests || [];
    let history = network.requestHistory || [];
    const pending = network.pendingDataSources || [];

    if (status) {
      history = history.filter((r: any) => r.status === status);
    }
    history = applySearch(history, search);

    const sb = createSectionBuilder('## G7 네트워크 정보', timeAgo);

    // 진행 중인 요청
    if (active.length > 0) {
      sb.section(`### 🔄 진행 중인 요청 (${active.length}개)`);
      for (const req of active) {
        const displayUrl = req.fullUrl || req.url;
        sb.push(`- ${req.method || 'GET'} ${displayUrl}`);

        if (showQueryParams && req.queryParams && Object.keys(req.queryParams).length > 0) {
          sb.push('  - **쿼리 파라미터**:');
          for (const [key, value] of Object.entries(req.queryParams)) {
            if (Array.isArray(value)) {
              sb.push(`    - ${key}: ${JSON.stringify(value)}`);
            } else {
              sb.push(`    - ${key}: ${value}`);
            }
          }
        }
      }
    }

    // 대기 중인 데이터소스
    if (pending.length > 0) {
      sb.section(`### ⏳ 대기 중인 데이터소스 (${pending.length}개)`);
      for (const ds of pending) {
        sb.push(`- ${ds}`);
      }
    }

    // 요청 이력에 페이지네이션 적용
    const { items: paginatedHistory, pagination: historyPagination } = applyPagination(
      history.reverse(), // 최신 순
      offset,
      limit
    );

    sb.section(`### 📋 요청 이력`);
    if (paginatedHistory.length === 0) {
      sb.push('(없음)');
    } else {
      // 간략 모드 (showResponse, showQueryParams, showRequestBody 모두 false)
      const isSimpleMode = !showResponse && !showQueryParams && !showRequestBody;

      if (isSimpleMode) {
        sb.push('| 상태 | 메서드 | URL | 소요시간 |');
        sb.push('|------|--------|-----|----------|');
        for (const req of paginatedHistory) {
          const statusIcon = req.status === 'success' ? '✅' :
                            req.status === 'error' ? '❌' : '⏳';
          const url = (req.fullUrl || req.url || '').substring(0, 60);
          const duration = req.duration != null ? `${req.duration}ms` : '-';
          sb.push(`| ${statusIcon} | ${req.method || 'GET'} | ${url} | ${duration} |`);
        }
      } else {
        for (const req of paginatedHistory) {
          const statusIcon = req.status === 'success' ? '✅' :
                            req.status === 'error' ? '❌' : '⏳';
          const duration = req.duration != null ? `${req.duration}ms` : '-';

          const displayUrl = req.fullUrl || req.url;
          sb.push(`#### ${statusIcon} ${req.method || 'GET'} ${displayUrl}`);
          sb.push(`- 상태 코드: ${req.statusCode || '-'}`);
          sb.push(`- 소요 시간: ${duration}`);

          if (req.dataSourceId) {
            sb.push(`- 데이터소스: ${req.dataSourceId}`);
          }

          if (req.error) {
            sb.push(`- 에러: ${req.error}`);
          }

          if (showQueryParams && req.queryParams && Object.keys(req.queryParams).length > 0) {
            sb.push('- **쿼리 파라미터**:');
            for (const [key, value] of Object.entries(req.queryParams)) {
              if (Array.isArray(value)) {
                sb.push(`  - ${key}: ${JSON.stringify(value)}`);
              } else {
                sb.push(`  - ${key}: ${value}`);
              }
            }
          }

          // 요청 본문 표시 (showRequestBody가 true인 경우)
          if (showRequestBody && req.requestBody != null) {
            sb.push('- **요청 본문**:');
            sb.pushJson(req.requestBody, MAX_REQUEST_BODY_SIZE);
          }

          // 응답 데이터 표시 (showResponse가 true인 경우)
          if (showResponse && req.response != null) {
            sb.push('- **Response**:');
            sb.pushJson(req.response, MAX_RESPONSE_SIZE);
          } else if (showResponse) {
            sb.push('- **Response**: (없음)');
          }
          sb.push('');
        }
      }

      sb.pushPagination(historyPagination, '요청');
    }

    // 분석
    const errors = history.filter((r: any) => r.status === 'error');
    if (errors.length > 0) {
      sb.section(`### ⚠️ 오류 요청 ${errors.length}개 발견`);
      for (const err of errors.slice(-5)) {
        const errUrl = err.fullUrl || err.url;
        sb.push(`- ${err.method || 'GET'} ${errUrl}: ${err.error || err.statusCode || '알 수 없는 오류'}`);
      }
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-form - Form 추적 및 바인딩 검증 정보
// ============================================
server.tool(
  'g7-form',
  '추적된 Form 컴포넌트와 Input 바인딩 정보를 조회합니다. 검증 이슈도 함께 확인할 수 있습니다.',
  {
    dataKey: z.string().optional().describe('특정 Form의 dataKey로 필터링'),
    showValidation: z.boolean().optional().describe('바인딩 검증 이슈 표시 (기본: true)'),
    issuesOnly: z.boolean().optional().describe('이슈가 있는 Form만 표시 (기본: false)'),
    issueType: z.string().optional().describe('특정 이슈 유형만 필터링 (예: missing-datakey, context-not-propagated)'),
    search: z.string().optional().describe('텍스트 검색 (dataKey, Input 이름 등 항목 내 문자열 매칭)'),
  },
  async ({ dataKey, showValidation = true, issuesOnly = false, issueType, search }) => {
    const state = readDebugFile('state-latest.json');
    const formData = readDebugFile('form-latest.json');
    const validationData = readDebugFile('form-binding-validation-latest.json');

    // 배열 형태와 객체 형태 모두 지원
    let forms = Array.isArray(formData) ? formData : (formData?.forms || []);

    if (!forms || forms.length === 0) {
      return {
        content: [{
          type: 'text',
          text: `❌ Form 추적 정보가 없습니다.\n\nForm이 있는 페이지에서 상태 덤프를 실행해주세요.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    if (dataKey) {
      forms = forms.filter((f: any) => f.dataKey === dataKey || f.dataKey?.includes(dataKey));
    }
    forms = applySearch(forms, search);

    const timestamp = getFileTimestamp('form-latest.json') || getFileTimestamp('state-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const sb = createSectionBuilder('# 📝 G7 Form 추적 정보', timeAgo);
    sb.push(`📊 추적된 Form: ${forms.length}개`);

    // 검증 통계 표시
    if (showValidation && validationData?.stats) {
      const stats = validationData.stats;
      sb.section('## 📊 검증 통계');
      sb.push(
        `- 검증된 Form: ${stats.totalFormsValidated || 0}개`,
        `- ✅ 유효한 Form: ${stats.validForms || 0}개`,
        `- ⚠️ 이슈 있는 Form: ${stats.formsWithIssues || 0}개`,
        `- 총 이슈: ${stats.totalIssues || 0}개`,
      );

      if (stats.topIssues && stats.topIssues.length > 0) {
        sb.push('', '**주요 이슈**:');
        for (const issue of stats.topIssues.slice(0, 3)) {
          sb.push(`- \`${issue.type}\`: ${issue.count}회 - ${issue.description}`);
        }
      }
    }

    // 이슈 필터링
    let issues = validationData?.issues || [];
    if (issueType) {
      issues = issues.filter((i: any) => i.type === issueType);
    }

    // issuesOnly 모드에서는 이슈가 있는 Form만 표시
    if (issuesOnly) {
      const formsWithIssues = new Set(issues.map((i: any) => i.formId || i.formDataKey));
      forms = forms.filter((f: any) =>
        formsWithIssues.has(f.id) || formsWithIssues.has(f.dataKey) || !f.dataKey
      );
    }

    sb.section('## 📋 Form 목록');

    for (const form of forms) {
      const hasDataKey = !!form.dataKey;
      const formIssues = issues.filter((i: any) =>
        i.formId === form.id || i.formDataKey === form.dataKey
      );
      const hasIssues = formIssues.length > 0 || !hasDataKey;

      sb.push(`### ${hasIssues ? '⚠️' : '✅'} Form: ${form.dataKey || '(dataKey 없음)'}`);

      if (!hasDataKey) {
        sb.push('', '> ❌ **dataKey가 설정되지 않았습니다.** Form 바인딩이 동작하지 않습니다.');
      }

      // 검증 이슈 표시
      if (showValidation && formIssues.length > 0) {
        sb.push('', '**🔍 검증 이슈**:');
        for (const issue of formIssues) {
          const severityIcon = issue.severity === 'error' ? '❌' :
                              issue.severity === 'warning' ? '⚠️' : 'ℹ️';
          sb.push(`- ${severityIcon} \`${issue.type}\`: ${issue.description}`);
          if (issue.suggestion) {
            sb.push(`  - 💡 ${issue.suggestion}`);
          }
        }
      }

      if (form.inputs && form.inputs.length > 0) {
        sb.push('', '**Input 목록**:', '');
        sb.push('| Input Name | 타입 | 바인딩 경로 | 상태 |');
        sb.push('|------------|------|------------|------|');
        for (const input of form.inputs) {
          const hasName = !!input.name;
          const bindingPath = form.dataKey && input.name ? `${form.dataKey}.${input.name}` : '-';
          const status = hasName ? '✅' : '❌';
          sb.push(`| ${hasName ? input.name : '(name 없음)'} | ${input.type || '-'} | \`${bindingPath}\` | ${status} |`);
        }

        const noNameInputs = form.inputs.filter((i: any) => !i.name);
        if (noNameInputs.length > 0) {
          sb.push('', `> ⚠️ **name이 없는 Input ${noNameInputs.length}개**: 자동 바인딩이 동작하지 않습니다.`);
        }
      }

      // 현재 Form 값 표시
      if (form.dataKey && state) {
        const formState = getNestedValue(state, form.dataKey);
        if (formState) {
          sb.push('', '**현재 값**:');
          sb.pushJson(formState, 500);
        }
      }

      sb.push('', '---', '');
    }

    // 검증 이슈 요약
    if (showValidation && issues.length > 0) {
      sb.section('## 🚨 전체 검증 이슈');

      const issuesByType: Record<string, any[]> = {};
      for (const issue of issues) {
        if (!issuesByType[issue.type]) {
          issuesByType[issue.type] = [];
        }
        issuesByType[issue.type].push(issue);
      }

      for (const [type, typeIssues] of Object.entries(issuesByType)) {
        sb.push(`### \`${type}\` (${typeIssues.length}건)`, '');
        for (const issue of typeIssues.slice(0, 5)) {
          const severityIcon = issue.severity === 'error' ? '❌' :
                              issue.severity === 'warning' ? '⚠️' : 'ℹ️';
          sb.push(`- ${severityIcon} ${issue.description}`);
          if (issue.formDataKey) {
            sb.push(`  - Form: \`${issue.formDataKey}\``);
          }
        }
        if (typeIssues.length > 5) {
          sb.push(`- ... 외 ${typeIssues.length - 5}건`);
        }
        sb.push('');
      }
    }

    // 사용 팁
    sb.section('## 💡 Form 바인딩 팁');
    sb.push('### 일반적인 문제 해결', '');
    sb.push('**1. dataKey 누락**');
    sb.pushJson({ "type": "Form", "dataKey": "formData", "children": ["..."] });
    sb.push('', '**2. Sortable 내 컨텍스트 단절**');
    sb.pushJson({ "type": "Sortable", "children": [{ "type": "Form", "dataKey": "item", "parentFormContextProp": null }] });
    sb.push('', '**3. Input name 누락**');
    sb.pushJson({ "type": "Input", "name": "email" });
    sb.push(
      '', '### 필터 옵션',
      '- `dataKey: "formData"` - 특정 Form만 조회',
      '- `issuesOnly: true` - 이슈 있는 Form만 표시',
      '- `issueType: "context-not-propagated"` - 특정 이슈만 필터링',
      '- `showValidation: false` - 검증 정보 숨기기',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-expressions - 표현식 평가 이력
// ============================================
server.tool(
  'g7-expressions',
  '표현식({{...}}) 평가 이력과 경고를 조회합니다. 바인딩 문제 디버깅에 유용합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('조회할 최대 개수 (기본: 30)'),
    warningsOnly: z.boolean().optional().describe('경고가 있는 표현식만 조회 (기본: false)'),
    search: z.string().optional().describe('표현식 또는 컴포넌트 이름으로 검색'),
  },
  async ({ offset = 0, limit = 30, warningsOnly = false, search }) => {
    const exprData = readDebugFile('expressions-latest.json');

    if (!exprData) {
      return {
        content: [{
          type: 'text',
          text: `❌ 표현식 이력 파일이 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('expressions-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    // 배열 형태와 객체 형태 모두 지원
    let expressions: any[] = Array.isArray(exprData) ? exprData : (exprData.expressions || []);

    // 배열 형태인 경우 통계 자동 계산
    const computeStats = (exprs: any[]) => {
      const uniqueSet = new Set(exprs.map(e => e.expression));
      const warnings = exprs.filter(e => e.warning != null);
      const cacheHits = exprs.filter(e => e.fromCache === true).length;
      const totalDuration = exprs.reduce((sum, e) => sum + (e.duration || 0), 0);
      return {
        totalEvaluations: exprs.length,
        uniqueExpressions: uniqueSet.size,
        warningCount: warnings.length,
        cacheHitRate: exprs.length > 0 ? cacheHits / exprs.length : 0,
        averageDuration: exprs.length > 0 ? totalDuration / exprs.length : 0,
      };
    };

    const stats = Array.isArray(exprData) ? computeStats(expressions) : (exprData.stats || {});

    // 필터링
    if (warningsOnly) {
      expressions = expressions.filter((e: any) => e.warning != null);
    }

    if (search) {
      const lowerSearch = search.toLowerCase();
      expressions = expressions.filter((e: any) =>
        e.expression?.toLowerCase().includes(lowerSearch) ||
        e.componentName?.toLowerCase().includes(lowerSearch)
      );
    }

    const sb = createSectionBuilder('## G7 표현식 평가 이력', timeAgo);

    sb.section('### 통계');
    sb.push(
      `- 총 평가 횟수: ${stats.totalEvaluations || 0}`,
      `- 고유 표현식: ${stats.uniqueExpressions || 0}`,
      `- 경고 수: ${stats.warningCount || 0}`,
      `- 캐시 히트율: ${stats.cacheHitRate ? (stats.cacheHitRate * 100).toFixed(1) : 0}%`,
      `- 평균 소요 시간: ${stats.averageDuration ? stats.averageDuration.toFixed(2) : 0}ms`,
    );

    // 경고 유형별 집계
    if (stats.byWarning && Object.keys(stats.byWarning).length > 0) {
      sb.section('### 경고 유형');
      for (const [type, count] of Object.entries(stats.byWarning)) {
        sb.push(`- ${type}: ${count}회`);
      }
    }

    // 표현식 목록에 페이지네이션 적용
    const { items: paginatedExpressions, pagination } = applyPagination(
      expressions.reverse(), // 최신 순
      offset,
      limit
    );

    sb.section(`### 표현식 목록`);

    if (paginatedExpressions.length === 0) {
      sb.push(warningsOnly ? '(경고가 있는 표현식 없음)' : '(표현식 없음)');
    } else {
      for (const expr of paginatedExpressions) {
        const icon = expr.warning ? '⚠️' : expr.fromCache ? '📦' : '✅';
        const component = expr.componentName ? ` [${expr.componentName}]` : '';

        sb.push(`#### ${icon} \`${expr.expression}\`${component}`);
        sb.push(`- 결과: \`${JSON.stringify(expr.result)}\` (${expr.resultType})`);
        if (expr.fromCache) {
          sb.push(`- 캐시: ✅ 캐시에서 로드`);
        }
        if (expr.duration != null) {
          sb.push(`- 소요 시간: ${expr.duration.toFixed(2)}ms`);
        }
        if (expr.warning) {
          sb.push(`- ⚠️ **경고**: ${expr.warning.type}`);
          if (expr.warning.suggestion) {
            sb.push(`  - 제안: ${expr.warning.suggestion}`);
          }
        }
        sb.push('');
      }

      sb.pushPagination(pagination, '표현식');
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-datasources - 데이터소스 구조 및 경로 변환 추적
// ============================================
server.tool(
  'g7-datasources',
  '데이터소스 정의와 구조를 조회합니다. 각 데이터소스의 엔드포인트, 데이터 경로, 항목 수, 키 목록 및 경로 변환 추적을 확인할 수 있습니다.',
  {
    id: z.string().optional().describe('특정 데이터소스 ID로 필터링'),
    status: z.string().optional().describe('상태로 필터링 (idle, loading, loaded, error)'),
    showTransforms: z.boolean().optional().describe('데이터 경로 변환 단계 표시 (기본: false)'),
    showWarnings: z.boolean().optional().describe('변환 경고 표시 (기본: true)'),
    search: z.string().optional().describe('텍스트 검색 (ID, 엔드포인트 등 항목 내 문자열 매칭)'),
  },
  async ({ id, status, showTransforms = false, showWarnings = true, search }) => {
    const dataSources = readDebugFile('datasources-latest.json');
    const transformTracking = readDebugFile('data-path-transform-latest.json');

    if (!dataSources || !Array.isArray(dataSources) || dataSources.length === 0) {
      return {
        content: [{
          type: 'text',
          text: `❌ 데이터소스 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('datasources-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let filtered = dataSources;

    // ID 필터링
    if (id) {
      filtered = filtered.filter((ds: any) => ds.id === id || ds.id?.includes(id));
    }

    // 상태 필터링
    if (status) {
      filtered = filtered.filter((ds: any) => ds.status === status);
    }
    filtered = applySearch(filtered, search);

    const sb = createSectionBuilder('# 📦 G7 데이터소스 정보', timeAgo);
    sb.push(`📊 전체: ${dataSources.length}개, 필터 결과: ${filtered.length}개`);

    if (filtered.length === 0) {
      sb.push('', '(조건에 맞는 데이터소스 없음)');
    } else {
      for (const ds of filtered) {
        const statusIcon = ds.status === 'loaded' ? '✅' :
                          ds.status === 'loading' ? '🔄' :
                          ds.status === 'error' ? '❌' : '⏳';

        sb.section(`## ${statusIcon} ${ds.id}`);
        sb.push(`- **타입**: ${ds.type}`);

        if (ds.endpoint) {
          sb.push(`- **엔드포인트**: \`${ds.endpoint}\``);
        }
        if (ds.method) {
          sb.push(`- **메서드**: ${ds.method}`);
        }
        if (ds.status) {
          sb.push(`- **상태**: ${ds.status}`);
        }
        if (ds.dataPath) {
          sb.push(`- **데이터 경로**: \`${ds.dataPath}\``);
        }
        if (ds.itemCount != null) {
          sb.push(`- **항목 수**: ${ds.itemCount}`);
        }
        if (ds.keys && ds.keys.length > 0) {
          const displayKeys = ds.keys.length > 10
            ? ds.keys.slice(0, 10).join(', ') + `, ... (+${ds.keys.length - 10})`
            : ds.keys.join(', ');
          sb.push(`- **키**: ${displayKeys}`);
        }
        if (ds.autoFetch != null) {
          sb.push(`- **auto_fetch**: ${ds.autoFetch}`);
        }
        if (ds.initLocal) {
          sb.push(`- **initLocal**: \`${JSON.stringify(ds.initLocal)}\``);
        }
        if (ds.initGlobal) {
          sb.push(`- **initGlobal**: \`${JSON.stringify(ds.initGlobal)}\``);
        }
        if (ds.lastLoadedAt) {
          const loadedAgo = Math.round((Date.now() - ds.lastLoadedAt) / 1000);
          sb.push(`- **로드 시간**: ${loadedAgo}초 전`);
        }
        if (ds.error) {
          sb.push(`- **에러**: ${ds.error}`);
        }

        // 데이터 경로 변환 추적
        if (transformTracking) {
          const transforms = transformTracking.transforms || [];
          const dsTransform = transforms.find((t: any) => t.dataSourceId === ds.id);

          if (dsTransform) {
            sb.push('', '### 📍 경로 변환 추적');

            // 경고 표시
            if (showWarnings && dsTransform.warnings && dsTransform.warnings.length > 0) {
              sb.push('', '**⚠️ 경고**:');
              for (const warning of dsTransform.warnings) {
                sb.push(`- ${warning}`);
              }
            }

            // 변환 단계 표시
            if (showTransforms && dsTransform.transformSteps && dsTransform.transformSteps.length > 0) {
              sb.push('', '**변환 단계**:', '');
              sb.push('| 단계 | 입력 경로 | 출력 경로 |');
              sb.push('|------|----------|----------|');

              for (const step of dsTransform.transformSteps) {
                const stepIcon = step.step === 'api_response' ? '📥' :
                                step.step === 'extract_data' ? '🔍' :
                                step.step === 'apply_path' ? '📎' :
                                step.step === 'init_global' ? '🌐' :
                                step.step === 'init_local' ? '📍' : '❓';
                sb.push(`| ${stepIcon} ${step.step} | \`${step.inputPath || '-'}\` | \`${step.outputPath || '-'}\` |`);
              }
            }

            // 최종 바인딩 표시
            if (dsTransform.finalBinding) {
              sb.push('', '**최종 바인딩**:');
              sb.push(`- 표현식: \`${dsTransform.finalBinding.expression}\``);
              sb.push(`- 해석된 경로: \`${dsTransform.finalBinding.resolvedPath}\``);
              sb.push(`- 값 타입: \`${typeof dsTransform.finalBinding.value}\``);
            }
          }
        }
      }
    }

    // 요약 통계
    const stats = {
      loaded: dataSources.filter((ds: any) => ds.status === 'loaded').length,
      loading: dataSources.filter((ds: any) => ds.status === 'loading').length,
      error: dataSources.filter((ds: any) => ds.status === 'error').length,
      idle: dataSources.filter((ds: any) => ds.status === 'idle').length,
    };

    sb.section('## 📊 요약');
    sb.push('| 상태 | 개수 |');
    sb.push('|------|-----:|');
    sb.push(`| ✅ loaded | ${stats.loaded} |`);
    sb.push(`| 🔄 loading | ${stats.loading} |`);
    sb.push(`| ❌ error | ${stats.error} |`);
    sb.push(`| ⏳ idle | ${stats.idle} |`);

    // 변환 경고 통계
    if (transformTracking && transformTracking.stats) {
      const tStats = transformTracking.stats;
      if (tStats.warningsCount > 0) {
        sb.section('### ⚠️ 변환 경고 통계');
        sb.push(`총 경고: ${tStats.warningsCount}개`);

        if (tStats.commonWarnings && tStats.commonWarnings.length > 0) {
          sb.push('', '자주 발생하는 경고:');
          for (const cw of tStats.commonWarnings.slice(0, 5)) {
            sb.push(`- ${cw.warning} (${cw.count}회)`);
          }
        }
      }
    }

    // 사용 팁
    sb.section('## 💡 사용 팁');
    sb.push(
      '- `showTransforms: true` - 경로 변환 단계 상세 표시',
      '- `id: "products"` - 특정 데이터소스만 조회',
      '- `status: "error"` - 에러 상태 데이터소스만 조회',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-handlers - 등록된 핸들러 목록 조회
// ============================================
server.tool(
  'g7-handlers',
  '등록된 액션 핸들러 목록을 조회합니다. 빌트인, 커스텀, 모듈 핸들러를 카테고리별로 확인할 수 있습니다.',
  {
    category: z.string().optional().describe('카테고리로 필터링 (built-in, custom, module)'),
    name: z.string().optional().describe('핸들러 이름으로 검색'),
    search: z.string().optional().describe('텍스트 검색 (핸들러명, 설명 등 항목 내 문자열 매칭)'),
  },
  async ({ category, name, search }) => {
    const handlers = readDebugFile('handlers-latest.json');

    if (!handlers || !Array.isArray(handlers) || handlers.length === 0) {
      return {
        content: [{
          type: 'text',
          text: `❌ 핸들러 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('handlers-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let filtered = handlers;

    // 카테고리 필터링
    if (category) {
      filtered = filtered.filter((h: any) => h.category === category);
    }

    // 이름 검색
    if (name) {
      filtered = filtered.filter((h: any) =>
        h.name?.toLowerCase().includes(name.toLowerCase())
      );
    }
    filtered = applySearch(filtered, search);

    const sb = createSectionBuilder('## 📋 등록된 핸들러 목록', timeAgo);
    sb.push(`📊 전체: ${handlers.length}개, 필터 결과: ${filtered.length}개`);

    if (filtered.length === 0) {
      sb.push('', '(조건에 맞는 핸들러 없음)');
    } else {
      // 카테고리별 그룹화
      const grouped: Record<string, any[]> = {
        'built-in': [],
        'custom': [],
        'module': [],
      };

      for (const h of filtered) {
        const cat = h.category || 'custom';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(h);
      }

      // 카테고리별 출력
      const categoryLabels: Record<string, string> = {
        'built-in': '🔧 빌트인 핸들러',
        'custom': '✨ 커스텀 핸들러',
        'module': '📦 모듈 핸들러',
      };

      for (const [cat, categoryHandlers] of Object.entries(grouped)) {
        if (categoryHandlers.length === 0) continue;

        sb.section(`### ${categoryLabels[cat] || cat} (${categoryHandlers.length}개)`);
        sb.push('| 핸들러 | 설명 | 소스 |');
        sb.push('|--------|------|------|');

        for (const h of categoryHandlers) {
          const desc = h.description || '-';
          const source = h.source || '-';
          sb.push(`| \`${h.name}\` | ${desc} | ${source} |`);
        }
      }
    }

    // 요약 통계
    const stats = {
      builtIn: handlers.filter((h: any) => h.category === 'built-in').length,
      custom: handlers.filter((h: any) => h.category === 'custom').length,
      module: handlers.filter((h: any) => h.category === 'module').length,
    };

    sb.section('### 요약');
    sb.push(`- 🔧 빌트인: ${stats.builtIn}개`);
    sb.push(`- ✨ 커스텀: ${stats.custom}개`);
    sb.push(`- 📦 모듈: ${stats.module}개`);
    sb.push(`- **총계: ${handlers.length}개**`);

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-events - 컴포넌트 이벤트 조회
// ============================================
server.tool(
  'g7-events',
  '컴포넌트 이벤트(componentEvent) 구독 및 발생 이력을 조회합니다. 이벤트 기반 컴포넌트 간 통신 디버깅에 유용합니다.',
  {
    eventName: z.string().optional().describe('특정 이벤트 이름으로 필터링'),
    showHistory: z.boolean().optional().describe('emit 이력 표시 여부 (기본: true)'),
    limit: z.number().optional().describe('표시할 이력 개수 (기본: 20)'),
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    search: z.string().optional().describe('텍스트 검색 (이벤트명, 데이터 등 항목 내 문자열 매칭)'),
  },
  async ({ eventName, showHistory = true, limit = 20, offset = 0, search }) => {
    const componentEvents = readDebugFile('component-events-latest.json');

    if (!componentEvents) {
      return {
        content: [{
          type: 'text',
          text: `❌ 컴포넌트 이벤트 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('component-events-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { subscriptions = [], emitHistory = [], totalSubscribers = 0, totalEmits = 0 } = componentEvents;

    const sb = createSectionBuilder('## 📡 컴포넌트 이벤트 정보', timeAgo);
    sb.push(`📊 총 구독자: ${totalSubscribers}개, 총 emit: ${totalEmits}회`);

    // 구독 정보
    sb.section('### 📋 이벤트 구독 목록');
    if (subscriptions.length === 0) {
      sb.push('(등록된 이벤트 구독 없음)');
    } else {
      let filteredSubs = subscriptions;
      if (eventName) {
        filteredSubs = subscriptions.filter((s: any) =>
          s.eventName?.toLowerCase().includes(eventName.toLowerCase())
        );
      }

      sb.push('| 이벤트명 | 구독자 수 | 최초 구독 | 마지막 구독 |');
      sb.push('|----------|----------|----------|------------|');

      for (const sub of filteredSubs) {
        const firstTime = sub.firstSubscribedAt
          ? new Date(sub.firstSubscribedAt).toLocaleTimeString()
          : '-';
        const lastTime = sub.lastSubscribedAt
          ? new Date(sub.lastSubscribedAt).toLocaleTimeString()
          : '-';
        sb.push(`| \`${sub.eventName}\` | ${sub.subscriberCount} | ${firstTime} | ${lastTime} |`);
      }
    }

    // emit 이력
    if (showHistory) {
      sb.section('### 📜 emit 이력');
      if (emitHistory.length === 0) {
        sb.push('(emit 이력 없음)');
      } else {
        let filteredHistory = emitHistory;
        if (eventName) {
          filteredHistory = emitHistory.filter((e: any) =>
            e.eventName?.toLowerCase().includes(eventName.toLowerCase())
          );
        }
        filteredHistory = applySearch(filteredHistory, search);

        // 최신 순으로 정렬하고 페이지네이션 적용
        const sorted = [...filteredHistory]
          .sort((a: any, b: any) => b.timestamp - a.timestamp);

        const { items: paginatedHistory, pagination } = applyPagination(sorted, offset, limit);

        for (const emit of paginatedHistory) {
          const time = new Date(emit.timestamp).toLocaleTimeString();
          const statusIcon = emit.hasError ? '❌' : '✅';
          sb.push(`#### ${statusIcon} ${emit.eventName}`);
          sb.push(`- **시간**: ${time}`);
          sb.push(`- **리스너 수**: ${emit.listenerCount}`);
          if (emit.data !== undefined) {
            sb.push(`- **데이터**: \`${JSON.stringify(emit.data).slice(0, 100)}${JSON.stringify(emit.data).length > 100 ? '...' : ''}\``);
          }
          if (emit.hasError) {
            sb.push(`- **에러**: ${emit.errorMessage}`);
          }
          sb.push('');
        }

        sb.pushPagination(pagination, 'emit 이력');
      }
    }

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-performance - 성능 정보 조회
// ============================================
server.tool(
  'g7-performance',
  '렌더링 성능 정보를 조회합니다. 컴포넌트별 렌더링 횟수, 바인딩 평가 횟수, 메모리 경고 등을 확인할 수 있습니다.',
  {
    showWarnings: z.boolean().optional().describe('메모리 경고만 표시 (기본: false)'),
    minRenders: z.number().optional().describe('최소 렌더링 횟수 필터 (기본: 0)'),
    search: z.string().optional().describe('텍스트 검색 (컴포넌트명 등 항목 내 문자열 매칭)'),
  },
  async ({ showWarnings = false, minRenders = 0, search }) => {
    const performance = readDebugFile('performance-latest.json');

    if (!performance) {
      return {
        content: [{
          type: 'text',
          text: `❌ 성능 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('performance-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { renderCounts = {}, bindingEvalCount = 0, memoryWarnings = [] } = performance;

    const sb = createSectionBuilder('## ⚡ 성능 정보', timeAgo);
    sb.push(`📊 바인딩 평가 횟수: ${bindingEvalCount}`);

    // 메모리 경고
    if (memoryWarnings.length > 0) {
      sb.section('### ⚠️ 메모리 경고');
      for (const warning of memoryWarnings) {
        const time = warning.timestamp
          ? new Date(warning.timestamp).toLocaleTimeString()
          : '-';
        sb.push(`- **[${time}]** ${warning.type}: ${warning.message}`);
      }
    }

    if (!showWarnings) {
      // 렌더링 횟수
      sb.section('### 🔄 컴포넌트별 렌더링 횟수');
      let entries = Object.entries(renderCounts)
        .filter(([_, count]) => (count as number) >= minRenders)
        .sort((a, b) => (b[1] as number) - (a[1] as number));
      if (search) {
        const lowerSearch = search.toLowerCase();
        entries = entries.filter(([component]) => component.toLowerCase().includes(lowerSearch));
      }

      if (entries.length === 0) {
        sb.push('(렌더링 기록 없음)');
      } else {
        sb.push('| 컴포넌트 | 렌더링 횟수 | 상태 |');
        sb.push('|----------|------------|------|');

        for (const [component, count] of entries.slice(0, 30)) {
          const c = count as number;
          const status = c > 50 ? '🔴 과다' : c > 20 ? '🟡 주의' : '🟢 정상';
          sb.push(`| \`${component}\` | ${c} | ${status} |`);
        }

        if (entries.length > 30) {
          sb.push(`... 외 ${entries.length - 30}개`);
        }
      }
    }

    // 요약
    const totalRenders = Object.values(renderCounts).reduce((a: number, b: any) => a + b, 0);
    const componentCount = Object.keys(renderCounts).length;
    const avgRenders = componentCount > 0 ? Math.round(totalRenders / componentCount) : 0;

    sb.section('### 요약');
    sb.push(`- 총 렌더링 횟수: ${totalRenders}`);
    sb.push(`- 추적 컴포넌트 수: ${componentCount}`);
    sb.push(`- 평균 렌더링: ${avgRenders}회/컴포넌트`);
    sb.push(`- 메모리 경고: ${memoryWarnings.length}개`);

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-conditionals - 조건부 렌더링 조회
// ============================================
server.tool(
  'g7-conditionals',
  '조건부 렌더링(if) 및 반복 렌더링(iteration) 정보를 조회합니다.',
  {
    type: z.enum(['all', 'if', 'iteration']).optional().describe('조회 타입 (기본: all)'),
    showFalse: z.boolean().optional().describe('false로 평가된 조건만 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (조건식, 컴포넌트명 등 항목 내 문자열 매칭)'),
  },
  async ({ type = 'all', showFalse = false, search }) => {
    const conditionals = readDebugFile('conditionals-latest.json');

    if (!conditionals) {
      return {
        content: [{
          type: 'text',
          text: `❌ 조건부 렌더링 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('conditionals-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { ifConditions = [], iterations = [] } = conditionals;

    const sb = createSectionBuilder('## 🔀 조건부 렌더링 정보', timeAgo);

    // if 조건
    if (type === 'all' || type === 'if') {
      sb.section('### 📋 if 조건 목록');

      let filtered = ifConditions;
      if (showFalse) {
        filtered = ifConditions.filter((c: any) => !c.evaluatedValue);
      }
      filtered = applySearch(filtered, search);

      if (filtered.length === 0) {
        sb.push('(if 조건 없음)');
      } else {
        sb.push(
          '| ID | 표현식 | 결과 | 평가 횟수 |',
          '|----|--------|------|----------|',
        );

        for (const cond of filtered) {
          const resultIcon = cond.evaluatedValue ? '✅' : '❌';
          const expr = cond.expression?.length > 40
            ? cond.expression.slice(0, 40) + '...'
            : cond.expression;
          sb.push(`| ${cond.id} | \`${expr}\` | ${resultIcon} | ${cond.evaluationCount} |`);
        }
      }
    }

    // iteration
    if (type === 'all' || type === 'iteration') {
      sb.section('### 🔁 iteration 목록');

      const filteredIterations = applySearch(iterations, search);
      if (filteredIterations.length === 0) {
        sb.push('(iteration 없음)');
      } else {
        sb.push(
          '| ID | 소스 | 변수명 | 인덱스명 | 아이템 수 |',
          '|----|------|--------|----------|----------|',
        );

        for (const iter of filteredIterations) {
          const source = iter.source?.length > 30
            ? iter.source.slice(0, 30) + '...'
            : iter.source;
          sb.push(`| ${iter.id} | \`${source}\` | ${iter.itemVar} | ${iter.indexVar || '-'} | ${iter.sourceLength} |`);
        }
      }
    }

    // 요약
    const trueCount = ifConditions.filter((c: any) => c.evaluatedValue).length;
    const falseCount = ifConditions.filter((c: any) => !c.evaluatedValue).length;
    const totalItems = iterations.reduce((sum: number, i: any) => sum + (i.sourceLength || 0), 0);

    sb.section('### 요약');
    sb.push(
      `- if 조건: ${ifConditions.length}개 (✅ ${trueCount} / ❌ ${falseCount})`,
      `- iteration: ${iterations.length}개 (총 ${totalItems}개 아이템)`,
    );

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-websocket - WebSocket 정보 조회
// ============================================
server.tool(
  'g7-websocket',
  'WebSocket 연결 상태 및 메시지 이력을 조회합니다.',
  {
    showMessages: z.boolean().optional().describe('메시지 이력 표시 여부 (기본: true)'),
    limit: z.number().optional().describe('표시할 메시지 개수 (기본: 20)'),
  },
  async ({ showMessages = true, limit = 20 }) => {
    const network = readDebugFile('network-latest.json');

    // WebSocket 정보는 network 파일에 있거나 별도 파일에 있을 수 있음
    // 현재 구현에서는 lifecycle에서 WebSocket 정보를 가져옴
    const lifecycle = readDebugFile('lifecycle-latest.json');

    if (!network && !lifecycle) {
      return {
        content: [{
          type: 'text',
          text: `❌ WebSocket/네트워크 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('network-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const sb = createSectionBuilder('## 🔌 WebSocket 정보', timeAgo);

    // 네트워크 정보에서 WebSocket 관련 정보 추출
    if (network) {
      const { activeRequests = [], requestHistory = [], pendingDataSources = [] } = network;

      sb.section('### 📡 활성 요청');
      if (activeRequests.length === 0) {
        sb.push('(활성 요청 없음)');
      } else {
        for (const req of activeRequests) {
          sb.push(`- **${req.method}** ${req.url} (${req.status})`);
        }
      }

      if (pendingDataSources.length > 0) {
        sb.section('### ⏳ 대기 중인 데이터소스');
        for (const ds of pendingDataSources) {
          sb.push(`- ${ds}`);
        }
      }

      if (showMessages && requestHistory.length > 0) {
        sb.section('### 📜 요청 이력');
        const sorted = [...requestHistory]
          .sort((a: any, b: any) => b.startTime - a.startTime)
          .slice(0, limit);

        for (const req of sorted) {
          const statusIcon = req.status === 'success' ? '✅' :
                            req.status === 'error' ? '❌' : '⏳';
          const time = new Date(req.startTime).toLocaleTimeString();
          sb.push(`- ${statusIcon} **[${time}]** ${req.method} ${req.url} (${req.duration || 0}ms)`);
        }
      }
    }

    // 요약
    const successCount = network?.requestHistory?.filter((r: any) => r.status === 'success').length || 0;
    const errorCount = network?.requestHistory?.filter((r: any) => r.status === 'error').length || 0;

    sb.section('### 요약');
    sb.push(
      `- 활성 요청: ${network?.activeRequests?.length || 0}개`,
      `- 요청 이력: ${network?.requestHistory?.length || 0}개 (✅ ${successCount} / ❌ ${errorCount})`,
      `- 대기 데이터소스: ${network?.pendingDataSources?.length || 0}개`,
    );

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-renders - 상태-렌더링 추적 조회
// ============================================
server.tool(
  'g7-renders',
  'setState 호출 후 실제 렌더링이 발생했는지 추적합니다. 상태 변경과 컴포넌트 렌더링의 상관관계를 확인할 수 있습니다.',
  {
    limit: z.number().optional().describe('조회할 최대 개수 (기본: 20)'),
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    statePath: z.string().optional().describe('특정 상태 경로로 필터링 (예: "_global.user")'),
    showComponents: z.boolean().optional().describe('렌더링된 컴포넌트 상세 표시 (기본: true)'),
    noRenderOnly: z.boolean().optional().describe('렌더링이 발생하지 않은 상태 변경만 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (상태 경로, 컴포넌트명 등 항목 내 문자열 매칭)'),
  },
  async ({ limit = 20, offset = 0, statePath, showComponents = true, noRenderOnly = false, search }) => {
    const stateRendering = readDebugFile('state-rendering-latest.json');

    if (!stateRendering) {
      return {
        content: [{
          type: 'text',
          text: `❌ 상태-렌더링 정보가 없습니다.\n${DUMP_INSTRUCTION}`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('state-rendering-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { logs = [], stats = {}, componentRenderCounts = {}, stateToComponentMap = {} } = stateRendering;

    const sb = createSectionBuilder('## 🎨 상태-렌더링 추적 정보', timeAgo);

    // 통계 요약
    sb.section('### 📊 통계');
    sb.push(
      `- 총 상태 변경: ${stats.totalStateChanges || 0}회`,
      `- 총 렌더링: ${stats.totalRenders || 0}회`,
      `- 평균 렌더링 시간: ${stats.avgRenderDuration ? stats.avgRenderDuration.toFixed(2) : 0}ms`,
      `- 상태 변경당 평균 컴포넌트: ${stats.avgComponentsPerChange ? stats.avgComponentsPerChange.toFixed(1) : 0}개`,
    );

    // 가장 많이 렌더링된 컴포넌트 Top 5
    if (stats.topRenderedComponents && stats.topRenderedComponents.length > 0) {
      sb.section('### 🔥 가장 많이 렌더링된 컴포넌트');
      sb.push(
        '| 컴포넌트 | 렌더링 횟수 |',
        '|----------|------------|',
      );
      for (const item of stats.topRenderedComponents.slice(0, 5)) {
        sb.push(`| \`${item.name}\` | ${item.count} |`);
      }
    }

    // 가장 영향력 있는 상태 경로 Top 5
    if (stats.topInfluentialPaths && stats.topInfluentialPaths.length > 0) {
      sb.section('### 🎯 가장 영향력 있는 상태 경로');
      sb.push(
        '| 경로 | 영향받은 컴포넌트 |',
        '|------|-----------------|',
      );
      for (const item of stats.topInfluentialPaths.slice(0, 5)) {
        sb.push(`| \`${item.path}\` | ${item.affectedComponents} |`);
      }
    }

    // 로그 필터링
    let filteredLogs = logs;

    if (statePath) {
      filteredLogs = filteredLogs.filter((log: any) =>
        log.statePath?.includes(statePath)
      );
    }

    if (noRenderOnly) {
      filteredLogs = filteredLogs.filter((log: any) =>
        !log.renderedComponents || log.renderedComponents.length === 0
      );
    }
    filteredLogs = applySearch(filteredLogs, search);

    // 최신 순으로 정렬 후 페이지네이션 적용
    const sortedAll = [...filteredLogs]
      .sort((a: any, b: any) => b.timestamp - a.timestamp);

    const { items: sortedLogs, pagination } = applyPagination(sortedAll, offset, limit);

    sb.section(`### 📜 상태 변경 로그 (${pagination.offset + 1}~${pagination.offset + sortedLogs.length}/${pagination.total}개)`);

    if (sortedLogs.length === 0) {
      sb.push('(조건에 맞는 로그 없음)');
    } else {
      for (const log of sortedLogs) {
        const time = new Date(log.timestamp).toLocaleTimeString();
        const renderCount = log.renderedComponents?.length || 0;
        const hasRender = renderCount > 0;
        const icon = hasRender ? '✅' : '⚠️';

        sb.push(
          `#### ${icon} ${log.statePath}`,
          `- **시간**: ${time}`,
          `- **setStateId**: \`${log.setStateId}\``,
        );

        // 트리거 정보
        if (log.triggeredBy) {
          const trigger = log.triggeredBy;
          if (trigger.handlerType) {
            sb.push(`- **트리거**: ${trigger.handlerType}${trigger.actionId ? ` (액션: ${trigger.actionId})` : ''}`);
          }
          if (trigger.source) {
            sb.push(`- **소스**: ${trigger.source}`);
          }
        }

        // 값 변경
        sb.push(
          `- **이전 값**: \`${JSON.stringify(log.oldValue)?.slice(0, 50)}${JSON.stringify(log.oldValue)?.length > 50 ? '...' : ''}\``,
          `- **새 값**: \`${JSON.stringify(log.newValue)?.slice(0, 50)}${JSON.stringify(log.newValue)?.length > 50 ? '...' : ''}\``,
        );

        // 렌더링 정보
        if (hasRender) {
          sb.push(
            `- **렌더링된 컴포넌트**: ${renderCount}개`,
            `- **총 렌더링 시간**: ${log.totalRenderDuration?.toFixed(2) || 0}ms`,
          );

          if (showComponents && log.renderedComponents) {
            sb.push('- **컴포넌트 목록**:');
            for (const comp of log.renderedComponents.slice(0, 5)) {
              sb.push(`  - \`${comp.componentName}\` (${comp.renderDuration?.toFixed(2) || 0}ms)`);
            }
            if (log.renderedComponents.length > 5) {
              sb.push(`  - ... 외 ${log.renderedComponents.length - 5}개`);
            }
          }
        } else {
          sb.push('- **렌더링**: ⚠️ 렌더링 발생 안 함');
        }

        sb.push('');
      }
    }

    // 렌더링 없는 상태 변경 경고
    const noRenderLogs = logs.filter((log: any) =>
      !log.renderedComponents || log.renderedComponents.length === 0
    );

    if (noRenderLogs.length > 0 && !noRenderOnly) {
      sb.section('### ⚠️ 주의: 렌더링 없는 상태 변경');
      sb.push(
        `${noRenderLogs.length}개의 상태 변경에서 렌더링이 발생하지 않았습니다.`,
        '이는 다음을 의미할 수 있습니다:',
        '- 해당 상태를 바인딩하는 컴포넌트가 없음',
        '- 컴포넌트가 마운트되지 않은 상태',
        '- 상태 변경이 실제로 값을 변경하지 않음 (동일한 값으로 설정)',
        '',
        '`noRenderOnly: true` 옵션으로 해당 로그만 확인할 수 있습니다.',
      );
    }

    // === expectedButNotRendered: 상태 계층 충돌로 인한 렌더링 누락 감지 ===
    const stateHierarchy = readDebugFile('state-hierarchy-latest.json');

    if (stateHierarchy?.conflicts && stateHierarchy.conflicts.length > 0) {
      // 상태 충돌 중 렌더링에 영향을 미칠 수 있는 것들 표시
      const renderRelatedConflicts = stateHierarchy.conflicts.filter((conflict: any) =>
        conflict.notUsedBy && conflict.notUsedBy.length > 0
      );

      if (renderRelatedConflicts.length > 0) {
        sb.section('### 🚫 예상했지만 렌더링되지 않은 영역 (expectedButNotRendered)');
        sb.push(
          '상태 소스 불일치로 인해 예상과 다르게 렌더링되지 않았을 수 있는 컴포넌트입니다.',
          '',
        );

        for (const conflict of renderRelatedConflicts) {
          sb.push(
            `#### 🔴 ${conflict.path}`,
            `- **문제**: ${conflict.description}`,
          );

          // 상태 값 비교
          if (conflict.globalValue !== undefined) {
            sb.push(`- **global _local 값**: \`${JSON.stringify(conflict.globalValue)?.slice(0, 50) || 'undefined'}\``);
          }
          if (conflict.dynamicStateValue !== undefined) {
            sb.push(`- **dynamicState 값**: \`${JSON.stringify(conflict.dynamicStateValue)?.slice(0, 50) || 'undefined'}\``);
          }
          if (conflict.contextStateValue !== undefined) {
            sb.push(`- **componentContext 값**: \`${JSON.stringify(conflict.contextStateValue)?.slice(0, 50) || 'undefined'}\``);
          }
          sb.push(`- **실제 사용 값**: \`${JSON.stringify(conflict.effectiveValue)?.slice(0, 50) || 'undefined'}\``);

          // 영향받는 컴포넌트
          if (conflict.usedBy && conflict.usedBy.length > 0) {
            sb.push(`- **정상 렌더링된 컴포넌트**: ${conflict.usedBy.join(', ')}`);
          }
          if (conflict.notUsedBy && conflict.notUsedBy.length > 0) {
            sb.push(`- **렌더링 누락 가능성**: ${conflict.notUsedBy.join(', ')}`);
          }

          sb.push('');
        }

        sb.push(
          '**해결 방법**:',
          '1. expandChildren 내부에서는 `__componentContext.state`를 사용하세요',
          '2. `G7Core.state.get()._local` 대신 `{{_local.xxx}}` 바인딩을 사용하세요',
          '3. 상세 분석은 `g7-state-hierarchy` 도구를 사용하세요',
        );
      }
    }

    // 페이지네이션 정보 추가
    sb.pushPagination(pagination, '상태 변경 로그');

    return { content: sb.build() };
  }
);

/**
 * 중첩 객체에서 값 가져오기
 */
function getNestedValue(obj: any, path: string): any {
  if (!path || !obj) return undefined;

  const parts = path.split('.');
  let current = obj;

  for (const part of parts) {
    if (current == null || typeof current !== 'object') return undefined;
    current = current[part];
  }

  return current;
}

// ============================================
// Tool: g7-state-hierarchy - 상태 계층 시각화
// ============================================
server.tool(
  'g7-state-hierarchy',
  '상태 계층과 우선순위를 시각화하고 충돌을 감지합니다. global _local, dynamicState, componentContext 간의 상태 불일치 문제를 진단할 수 있습니다.',
  {
    showValues: z.boolean().optional().describe('각 레이어의 상태 값 표시 여부 (기본: false)'),
    conflictsOnly: z.boolean().optional().describe('충돌 정보만 표시 (기본: false)'),
    path: z.string().optional().describe('특정 상태 경로로 필터링 (예: "selectedOptionIds")'),
    search: z.string().optional().describe('텍스트 검색 (레이어명, 충돌 경로, 컴포넌트명 등 항목 내 문자열 매칭)'),
  },
  async ({ showValues = false, conflictsOnly = false, path: filterPath, search }) => {
    const stateHierarchy = readDebugFile('state-hierarchy-latest.json');

    if (!stateHierarchy) {
      return {
        content: [{
          type: 'text',
          text: `❌ 상태 계층 정보가 없습니다.\n${DUMP_INSTRUCTION}\n\n**참고**: 상태 계층 추적은 DevTools 활성화 후 컴포넌트 렌더링 시 수집됩니다.`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('state-hierarchy-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let { layers = [], conflicts = [], componentStateSources = [] } = stateHierarchy;

    // search 필터링
    if (search) {
      layers = applySearch(layers, search);
      conflicts = applySearch(conflicts, search);
      componentStateSources = applySearch(componentStateSources, search);
    }

    const sb = createSectionBuilder('## 📊 상태 계층 정보', timeAgo);

    // 충돌 요약
    const warningConflicts = conflicts.filter((c: any) => c.severity === 'warning' || c.severity === 'error');
    if (warningConflicts.length > 0) {
      sb.push(`⚠️ **${warningConflicts.length}개의 상태 충돌 감지됨**`);
    }

    // 레이어 정보 (conflictsOnly가 아닌 경우)
    if (!conflictsOnly) {
      sb.section('### 📚 상태 레이어');
      sb.push(
        '| 레이어 | 타입 | 우선순위 | 컴포넌트 |',
        '|--------|------|----------|----------|',
      );

      for (const layer of layers) {
        const priorityIcon = layer.priority === 3 ? '🔥' : layer.priority === 2 ? '⬆️' : '➡️';
        const componentInfo = layer.componentId || '-';
        sb.push(`| ${layer.name} | ${layer.type} | ${priorityIcon} ${layer.priority} | ${componentInfo} |`);
      }

      // 레이어 값 표시
      if (showValues) {
        sb.section('### 📋 레이어별 상태 값');

        for (const layer of layers) {
          let values = layer.values;

          // 경로 필터링
          if (filterPath && values) {
            const filtered: Record<string, any> = {};
            for (const [key, value] of Object.entries(values)) {
              if (key.includes(filterPath)) {
                filtered[key] = value;
              }
            }
            if (Object.keys(filtered).length > 0) {
              values = filtered;
            } else {
              continue; // 필터 매칭 없으면 스킵
            }
          }

          sb.section(`#### ${layer.name}`);
          sb.pushJson(values, MAX_STATE_VALUE_SIZE);
        }
      }
    }

    // 충돌 정보
    sb.section('### ⚠️ 상태 충돌');

    let filteredConflicts = conflicts;
    if (filterPath) {
      filteredConflicts = conflicts.filter((c: any) => c.path.includes(filterPath));
    }

    if (filteredConflicts.length === 0) {
      sb.push('✅ 감지된 상태 충돌이 없습니다.');
    } else {
      for (const conflict of filteredConflicts) {
        const severityIcon = conflict.severity === 'error' ? '🔴' :
                            conflict.severity === 'warning' ? '🟡' : '🔵';

        sb.push(
          `#### ${severityIcon} \`${conflict.path}\``,
          `- **심각도**: ${conflict.severity}`,
          `- **설명**: ${conflict.description}`,
          '',
          '| 소스 | 값 |',
          '|------|-----|',
          `| Global _local | \`${JSON.stringify(conflict.globalValue)?.slice(0, 50)}\` |`,
        );
        if (conflict.dynamicStateValue !== undefined) {
          sb.push(`| DynamicState | \`${JSON.stringify(conflict.dynamicStateValue)?.slice(0, 50)}\` |`);
        }
        if (conflict.contextStateValue !== undefined) {
          sb.push(`| ComponentContext | \`${JSON.stringify(conflict.contextStateValue)?.slice(0, 50)}\` |`);
        }
        sb.push(`| **Effective** | \`${JSON.stringify(conflict.effectiveValue)?.slice(0, 50)}\` |`, '');

        if (conflict.usedBy.length > 0) {
          sb.push(`✅ **사용하는 컴포넌트**: ${conflict.usedBy.join(', ')}`);
        }
        if (conflict.notUsedBy.length > 0) {
          sb.push(`⚠️ **사용하지 못하는 컴포넌트**: ${conflict.notUsedBy.join(', ')}`);
        }
        sb.push('');
      }
    }

    // 컴포넌트별 상태 소스 (conflictsOnly가 아닌 경우)
    if (!conflictsOnly && componentStateSources.length > 0) {
      sb.section('### 🔍 컴포넌트별 상태 소스');
      sb.push(
        '| 컴포넌트 | 상태 제공자 | _global | _local | context |',
        '|----------|-------------|---------|--------|---------|',
      );

      for (const source of componentStateSources.slice(0, 20)) {
        const providerIcon = source.stateProvider.type === 'dynamicState' ? '⚡' :
                            source.stateProvider.type === 'componentContext' ? '🔗' : '🌐';
        const globalCount = source.stateSource.global?.length || 0;
        const localCount = source.stateSource.local?.length || 0;
        const contextCount = source.stateSource.context?.length || 0;

        sb.push(`| ${source.componentName} | ${providerIcon} ${source.stateProvider.type} | ${globalCount} | ${localCount} | ${contextCount} |`);
      }

      if (componentStateSources.length > 20) {
        sb.push(`... 외 ${componentStateSources.length - 20}개`);
      }
    }

    // 진단 요약
    sb.section('### 📝 진단 요약');

    if (warningConflicts.length === 0) {
      sb.push('✅ 상태 계층에 문제가 감지되지 않았습니다.');
    } else {
      sb.push(
        `⚠️ ${warningConflicts.length}개의 상태 충돌이 있습니다.`,
        '',
        '**해결 방법**:',
        '1. `notUsedBy` 컴포넌트가 dynamicState를 사용하도록 수정',
        '2. renderExpandedContent에서 `__componentContext.state`를 `_local`에 병합',
        '3. 상태 경로 충돌을 피하도록 네이밍 변경',
        '',
        '**참조**: docs/frontend/troubleshooting-state.md#context-state-mismatch',
      );
    }

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-context-flow - componentContext 흐름 추적
// ============================================
server.tool(
  'g7-context-flow',
  'componentContext가 컴포넌트 트리에서 어떻게 전파되는지 추적합니다. context가 전달되었지만 사용되지 않는 문제를 감지할 수 있습니다.',
  {
    showUnused: z.boolean().optional().describe('context를 받았지만 사용하지 않는 컴포넌트만 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (컴포넌트명, context 키 등 항목 내 문자열 매칭)'),
  },
  async ({ showUnused = false, search }) => {
    const contextFlow = readDebugFile('context-flow-latest.json');

    if (!contextFlow) {
      return {
        content: [{
          type: 'text',
          text: `❌ context 흐름 정보가 없습니다.\n${DUMP_INSTRUCTION}\n\n**참고**: context 흐름 추적은 DevTools 활성화 후 컴포넌트 렌더링 시 수집됩니다.`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('context-flow-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { rootComponent, contextFlow: flowNodes } = contextFlow;

    const sb = createSectionBuilder('## 🔗 componentContext 흐름', timeAgo);
    sb.push(`🌳 루트 컴포넌트: ${rootComponent}`);

    // 트리 출력을 위한 임시 라인 수집
    const treeLines: string[] = [];

    // 트리 출력 함수
    const renderFlowTree = (nodes: any[], indent: string = ''): void => {
      for (let i = 0; i < nodes.length; i++) {
        const node = nodes[i];
        const isLast = i === nodes.length - 1;
        const prefix = isLast ? '└── ' : '├── ';
        const childIndent = indent + (isLast ? '    ' : '│   ');

        // 상태 아이콘
        const receivedIcon = node.contextReceived ? '✅' : '❌';
        const passedIcon = node.passedToChildren ? '➡️' : '⛔';
        const usedIcon = node.usedInRender ? '🔧' : '💤';

        // search 필터: 이 노드와 자식 중 매칭되는 것이 없으면 건너뜀
        if (search) {
          const nodeStr = JSON.stringify(node).toLowerCase();
          if (!nodeStr.includes(search.toLowerCase())) {
            continue;
          }
        }

        // showUnused 필터
        if (showUnused && (!node.contextReceived || node.usedInRender)) {
          // 자식은 계속 탐색
          if (node.children && node.children.length > 0) {
            renderFlowTree(node.children, childIndent);
          }
          continue;
        }

        const line = `${indent}${prefix}${node.component} [받음:${receivedIcon} 전달:${passedIcon} 사용:${usedIcon}]`;
        treeLines.push(line);

        // context를 받았지만 사용하지 않는 경우 경고
        if (node.contextReceived && !node.usedInRender) {
          treeLines.push(`${childIndent}⚠️ context를 받았지만 렌더링에 사용하지 않음`);
        }

        // 자식 노드 렌더링
        if (node.children && node.children.length > 0) {
          renderFlowTree(node.children, childIndent);
        }
      }
    };

    sb.section('### 🌲 Context 흐름 트리');

    if (!flowNodes || flowNodes.length === 0) {
      sb.push('```', '(context 흐름 데이터 없음)', '```');
    } else {
      renderFlowTree(flowNodes);
      sb.push('```', ...treeLines, '```');
    }

    // 아이콘 범례
    sb.section('### 📖 범례');
    sb.push(
      '- ✅ 받음: componentContext를 props로 받음',
      '- ➡️ 전달: 자식 컴포넌트에 context 전달',
      '- 🔧 사용: 렌더링에서 context.state 사용',
      '- 💤 미사용: context를 받았지만 사용하지 않음',
    );

    // 문제 컴포넌트 요약
    const countUnused = (nodes: any[]): number => {
      let count = 0;
      for (const node of nodes) {
        if (node.contextReceived && !node.usedInRender) {
          count++;
        }
        if (node.children) {
          count += countUnused(node.children);
        }
      }
      return count;
    };

    const unusedCount = flowNodes ? countUnused(flowNodes) : 0;

    if (unusedCount > 0) {
      sb.section('### ⚠️ 주의');
      sb.push(
        `${unusedCount}개의 컴포넌트가 context를 받았지만 사용하지 않습니다.`,
        '이는 상태 동기화 문제의 원인이 될 수 있습니다.',
        '',
        '**해결 방법**:',
        '- `__componentContext.state`를 렌더링에서 참조',
        '- 또는 context를 전달하지 않도록 수정',
      );
    } else {
      sb.push('✅ 모든 context가 정상적으로 사용되고 있습니다.');
    }

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-styles - CSS 스타일 검증
// ============================================
server.tool(
  'g7-styles',
  'CSS 스타일 이슈를 감지하고 Tailwind 클래스를 분석합니다. 보이지 않는 요소, 다크 모드 누락, Tailwind purging 문제를 찾을 수 있습니다.',
  {
    issuesOnly: z.boolean().optional().describe('이슈가 있는 컴포넌트만 표시 (기본: false)'),
    type: z.string().optional().describe('특정 이슈 타입으로 필터링 (invisible-element, tailwind-purging, dark-mode-missing)'),
    componentId: z.string().optional().describe('특정 컴포넌트 ID로 필터링'),
    search: z.string().optional().describe('텍스트 검색 (컴포넌트명, 클래스명, 이슈 내 문자열 매칭)'),
  },
  async ({ issuesOnly = false, type, componentId, search }) => {
    const styleValidation = readDebugFile('style-validation-latest.json');

    if (!styleValidation) {
      return {
        content: [{
          type: 'text',
          text: `❌ 스타일 검증 정보가 없습니다.\n${DUMP_INSTRUCTION}\n\n**참고**: 스타일 추적은 DevTools 활성화 후 컴포넌트 렌더링 시 수집됩니다.`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('style-validation-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    let { issues = [], componentStyles = [], stats = {} } = styleValidation;

    // 필터링
    if (type) {
      issues = issues.filter((i: any) => i.type === type);
    }
    if (componentId) {
      issues = issues.filter((i: any) => i.componentId === componentId);
      componentStyles = componentStyles.filter((c: any) => c.componentId === componentId);
    }
    if (search) {
      issues = applySearch(issues, search);
      componentStyles = applySearch(componentStyles, search);
    }

    const sb = createSectionBuilder('## 🎨 CSS 스타일 검증', timeAgo);

    sb.section('### 📊 통계');
    sb.push(
      `- 추적 컴포넌트: ${stats.totalComponents || 0}개`,
      `- 보이지 않는 요소: ${stats.invisibleCount || 0}개`,
      `- Tailwind 이슈: ${stats.tailwindIssueCount || 0}개`,
      `- 다크 모드 누락: ${stats.darkModeIssueCount || 0}개`,
    );

    // 이슈 목록
    if (issues.length > 0) {
      sb.section('### ⚠️ 스타일 이슈');

      for (const issue of issues) {
        const severityIcon = issue.severity === 'error' ? '🔴' :
                            issue.severity === 'warning' ? '🟡' : '🔵';

        sb.push(`#### ${severityIcon} ${issue.componentName} (${issue.type})`);
        sb.push(`- **컴포넌트 ID**: ${issue.componentId}`);
        sb.push(`- **속성**: ${issue.property}`);
        sb.push(`- **현재 값**: \`${issue.currentValue}\``);
        if (issue.expectedValue) {
          sb.push(`- **예상 값**: \`${issue.expectedValue}\``);
        }
        sb.push(`- **설명**: ${issue.description}`);
        if (issue.suggestion) {
          sb.push(`- **제안**: ${issue.suggestion}`);
        }
        sb.push('');
      }
    } else {
      sb.section('### ✅ 스타일 이슈 없음');
      sb.push('감지된 스타일 이슈가 없습니다.');
    }

    // 컴포넌트 스타일 정보 (issuesOnly가 false인 경우)
    if (!issuesOnly && componentStyles.length > 0) {
      sb.section('### 📋 컴포넌트 스타일 정보');

      for (const comp of componentStyles.slice(0, 20)) {
        sb.push(
          `#### ${comp.componentName}`,
          `- **ID**: ${comp.componentId}`,
          `- **클래스**: \`${comp.classes.slice(0, 10).join(' ')}${comp.classes.length > 10 ? '...' : ''}\``,
        );

        const { tailwindAnalysis } = comp;
        if (tailwindAnalysis) {
          if (tailwindAnalysis.darkClasses.length > 0) {
            sb.push(`- **다크 모드 클래스**: ${tailwindAnalysis.darkClasses.length}개`);
          }
          if (tailwindAnalysis.responsiveClasses.length > 0) {
            sb.push(`- **반응형 클래스**: ${tailwindAnalysis.responsiveClasses.length}개`);
          }
          if (tailwindAnalysis.dynamicClasses.length > 0) {
            sb.push(`- **동적 클래스**: ${tailwindAnalysis.dynamicClasses.join(', ')}`);
          }
        }
        sb.push('');
      }

      if (componentStyles.length > 20) {
        sb.push(`... 외 ${componentStyles.length - 20}개`);
      }
    }

    // 해결 가이드
    if (issues.length > 0) {
      sb.section('### 💡 해결 가이드');

      const hasInvisible = issues.some((i: any) => i.type === 'invisible-element');
      const hasTailwind = issues.some((i: any) => i.type === 'tailwind-purging');
      const hasDarkMode = issues.some((i: any) => i.type === 'dark-mode-missing');

      if (hasInvisible) {
        sb.push(
          '**보이지 않는 요소**:',
          '- 브라우저 DevTools에서 해당 요소의 computed styles 확인',
          '- opacity, visibility, display, width/height 값 체크',
          '- 부모 요소의 overflow: hidden 확인',
          '',
        );
      }

      if (hasTailwind) {
        sb.push(
          '**Tailwind Purging**:',
          '- `tailwind.config.js`의 safelist에 동적 클래스 추가',
          '```js',
          'module.exports = {',
          '  safelist: ["bg-red-500", "text-blue-600"]',
          '}',
          '```',
          '',
        );
      }

      if (hasDarkMode) {
        sb.push(
          '**다크 모드 누락**:',
          '- 각 배경/텍스트 클래스에 대응하는 dark: 클래스 추가',
          '- 예: `bg-white` → `bg-white dark:bg-gray-800`',
          '',
        );
      }
    }

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-auth - 인증 디버깅
// ============================================
server.tool(
  'g7-auth',
  '인증 상태, 토큰 정보, 인증 이벤트 이력을 조회합니다. 로그인 문제, 401 오류, 토큰 갱신 실패 디버깅에 유용합니다.',
  {
    showHeaders: z.boolean().optional().describe('API 인증 헤더 분석 표시 (기본: true)'),
    showEvents: z.boolean().optional().describe('인증 이벤트 이력 표시 (기본: true)'),
    limit: z.number().optional().describe('이벤트/헤더 이력 개수 제한 (기본: 20)'),
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    search: z.string().optional().describe('텍스트 검색 (이벤트, 헤더 등 항목 내 문자열 매칭)'),
  },
  async ({ showHeaders = true, showEvents = true, limit = 20, offset = 0, search }) => {
    const authDebug = readDebugFile('auth-debug-latest.json');

    if (!authDebug) {
      return {
        content: [{
          type: 'text',
          text: `❌ 인증 디버깅 정보가 없습니다.\n${DUMP_INSTRUCTION}\n\n**참고**: 인증 추적은 DevTools 활성화 후 로그인/API 요청 시 수집됩니다.`,
        }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('auth-debug-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { state = {}, events = [], headerAnalysis = [], stats = {} } = authDebug;

    const sb = createSectionBuilder('## 🔐 인증 디버깅 정보', timeAgo);

    // 현재 인증 상태
    sb.section('### 📊 현재 인증 상태');
    const authIcon = state.isAuthenticated ? '✅' : '❌';
    sb.push(
      `| 항목 | 값 |`,
      `|------|-----|`,
      `| 인증 상태 | ${authIcon} ${state.isAuthenticated ? '인증됨' : '미인증'} |`,
    );

    if (state.user) {
      sb.push(`| 사용자 ID | ${state.user.id} |`);
      sb.push(`| 이메일 | ${state.user.email} |`);
      if (state.user.name) {
        sb.push(`| 이름 | ${state.user.name} |`);
      }
      if (state.user.roles && state.user.roles.length > 0) {
        sb.push(`| 역할 | ${state.user.roles.join(', ')} |`);
      }
    }

    // 토큰 정보
    sb.section('### 🔑 토큰 정보');
    const { tokens = {} } = state;
    sb.push(
      `| 항목 | 상태 |`,
      `|------|------|`,
      `| Access Token | ${tokens.hasAccessToken ? '✅ 존재' : '❌ 없음'} |`,
      `| Refresh Token | ${tokens.hasRefreshToken ? '✅ 존재' : '❌ 없음'} |`,
      `| 저장 위치 | ${tokens.storage || '알 수 없음'} |`,
    );

    // 통계
    sb.section('### 📈 통계');
    sb.push(
      `| 항목 | 횟수 |`,
      `|------|------|`,
      `| 로그인 시도 | ${stats.loginAttempts || 0} |`,
      `| 성공 로그인 | ${stats.successfulLogins || 0} |`,
      `| 실패 로그인 | ${stats.failedLogins || 0} |`,
      `| 토큰 갱신 | ${stats.tokenRefreshes || 0} |`,
      `| 401 응답 | ${stats.unauthorizedResponses || 0} |`,
    );

    // 인증 이벤트 이력
    if (showEvents && events.length > 0) {
      // 검색 필터 적용 후 최신 순으로 정렬 후 페이지네이션 적용
      const searchFiltered = applySearch(events, search);
      const sortedEvents = [...searchFiltered].reverse();
      const { items: paginatedEvents, pagination: eventPagination } = applyPagination(sortedEvents, offset, limit);

      sb.section(`### 📜 인증 이벤트 이력 (${eventPagination.offset + 1}~${eventPagination.offset + paginatedEvents.length}/${eventPagination.total}개)`);

      for (const event of paginatedEvents) {
        const time = new Date(event.timestamp).toLocaleTimeString();
        const icon = event.success ? '✅' : '❌';
        const typeLabel: Record<string, string> = {
          'login': '로그인',
          'logout': '로그아웃',
          'token-refresh': '토큰 갱신',
          'token-expired': '토큰 만료',
          'session-restored': '세션 복원',
          'permission-denied': '권한 거부',
          'api-unauthorized': 'API 401',
        };

        sb.push(
          `#### ${icon} ${typeLabel[event.type] || event.type}`,
          `- **시간**: ${time}`,
          `- **결과**: ${event.success ? '성공' : '실패'}`,
        );
        if (event.error) {
          sb.push(`- **에러**: ${event.error}`);
        }
        if (event.details) {
          sb.push(`- **상세**: \`${JSON.stringify(event.details).slice(0, 100)}\``);
        }
        sb.push('');
      }

      // 이벤트 페이지네이션 정보 추가
      sb.pushPagination(eventPagination, '인증 이벤트');
    }

    // API 인증 헤더 분석
    if (showHeaders && headerAnalysis.length > 0) {
      // 최신 순으로 정렬 후 페이지네이션 적용
      const sortedHeaders = [...headerAnalysis].reverse();
      const { items: paginatedHeaders, pagination: headerPagination } = applyPagination(sortedHeaders, offset, limit);

      sb.section(`### 🔍 API 인증 헤더 분석 (${headerPagination.offset + 1}~${headerPagination.offset + paginatedHeaders.length}/${headerPagination.total}개)`);
      sb.push(
        '| 시간 | URL | Auth 헤더 | 응답 |',
        '|------|-----|-----------|------|',
      );

      for (const header of paginatedHeaders) {
        const time = new Date(header.timestamp).toLocaleTimeString();
        const url = header.url.length > 30 ? header.url.slice(0, 30) + '...' : header.url;
        const authIconH = header.hasAuthHeader ? '✅' : '❌';
        const statusIcon = header.responseStatus === 200 ? '✅' :
                          header.responseStatus === 401 ? '🔴' :
                          header.responseStatus ? '🟡' : '-';
        sb.push(`| ${time} | ${url} | ${authIconH} | ${statusIcon} ${header.responseStatus || '-'} |`);
      }

      // 헤더 분석 페이지네이션 정보 추가
      sb.pushPagination(headerPagination, 'API 헤더 분석');
    }

    // 문제 진단
    const hasAuthIssues = !state.isAuthenticated ||
                         !tokens.hasAccessToken ||
                         (stats.unauthorizedResponses || 0) > 0;

    if (hasAuthIssues) {
      sb.section('### 🔧 문제 진단');

      if (!tokens.hasAccessToken) {
        sb.push(
          '⚠️ **Access Token 없음**',
          '- 로그인이 필요합니다',
          '- localStorage/sessionStorage에서 토큰 확인',
          '',
        );
      }

      if ((stats.unauthorizedResponses || 0) > 0) {
        sb.push(
          '⚠️ **401 응답 감지됨**',
          '- 토큰이 만료되었을 수 있습니다',
          '- Authorization 헤더가 올바르게 전송되는지 확인',
          '- `G7Core.auth?.refreshToken()` 호출 시도',
          '',
        );
      }

      if ((stats.failedLogins || 0) > (stats.successfulLogins || 0)) {
        sb.push(
          '⚠️ **로그인 실패가 많음**',
          '- 자격 증명 확인',
          '- 서버 로그 확인 필요',
          '',
        );
      }
    }

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-tailwind - Tailwind 빌드 검증
// ============================================
server.tool(
  'g7-tailwind',
  'Tailwind CSS 빌드 검증 및 퍼지된 클래스 감지. 스타일 검증 데이터에서 Tailwind 관련 이슈를 분석합니다.',
  {
    checkPurging: z.boolean().default(true).describe('퍼지된 클래스 검사 여부 (기본: true)'),
    showDarkMode: z.boolean().default(true).describe('다크 모드 클래스 분석 표시 (기본: true)'),
    showResponsive: z.boolean().default(false).describe('반응형 클래스 분석 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (클래스명, 컴포넌트명 등 항목 내 문자열 매칭)'),
  },
  async ({ checkPurging, showDarkMode, showResponsive, search }) => {
    // 스타일 검증 데이터 로드
    const styleData = readDebugFile('style-validation-latest.json');

    if (!styleData) {
      const sb = createSectionBuilder('# 🎨 Tailwind CSS 빌드 검증', null);
      sb.push(
        '⚠️ 스타일 검증 데이터가 없습니다.',
        DUMP_INSTRUCTION,
      );
      sb.section('### 수동 분석 방법');
      sb.push(
        '브라우저 콘솔에서 다음 명령 실행:',
        '```js',
        '// 모든 요소의 클래스 수집',
        'const classes = new Set();',
        'document.querySelectorAll("[class]").forEach(el => {',
        '  el.className.split(" ").filter(Boolean).forEach(c => classes.add(c));',
        '});',
        'console.log([...classes].sort());',
        '```',
      );
      return { content: sb.build() };
    }

    const timestamp = styleData.timestamp ? new Date(styleData.timestamp).toLocaleString() : '알 수 없음';
    const sb = createSectionBuilder('# 🎨 Tailwind CSS 빌드 검증', null);
    sb.push(`📅 데이터 시간: ${timestamp}`);

    // 이슈 분석
    let issues = styleData.issues || [];
    let components = styleData.components || [];

    // search 필터링
    if (search) {
      issues = applySearch(issues, search);
      components = applySearch(components, search);
    }

    // Tailwind 관련 이슈 필터링
    const tailwindPurgingIssues = issues.filter((i: any) => i.type === 'tailwind-purging');
    const darkModeIssues = issues.filter((i: any) => i.type === 'dark-mode-missing');
    const responsiveIssues = issues.filter((i: any) => i.type === 'responsive-issue');

    // 요약
    sb.section('## 📊 검증 요약');
    sb.push(
      `| 항목 | 수 |`,
      `|------|---:|`,
      `| 추적된 컴포넌트 | ${components.length} |`,
      `| 전체 이슈 | ${issues.length} |`,
      `| 퍼지 가능성 | ${tailwindPurgingIssues.length} |`,
      `| 다크 모드 누락 | ${darkModeIssues.length} |`,
      `| 반응형 이슈 | ${responsiveIssues.length} |`,
    );

    // 퍼지 가능성 있는 클래스
    if (checkPurging && tailwindPurgingIssues.length > 0) {
      sb.section('## ⚠️ 퍼지 가능성 있는 클래스');
      sb.push(
        '다음 클래스들이 CSS 빌드에 포함되지 않았을 수 있습니다:',
        '',
        '| 컴포넌트 | 클래스 | 심각도 |',
        '|----------|--------|--------|',
      );

      for (const issue of tailwindPurgingIssues.slice(0, 20)) {
        const severity = issue.severity === 'error' ? '🔴' :
                        issue.severity === 'warning' ? '🟡' : '🔵';
        sb.push(`| ${issue.componentName || '-'} | \`${issue.currentValue}\` | ${severity} |`);
      }

      if (tailwindPurgingIssues.length > 20) {
        sb.push(`| ... | +${tailwindPurgingIssues.length - 20}개 더 | |`);
      }

      sb.section('### 💡 해결 방법');
      const safelistLines = tailwindPurgingIssues.slice(0, 5).map((i: any) => `    '${i.currentValue}',`);
      sb.push(
        '1. **tailwind.config.js의 safelist에 추가**:',
        '```js',
        'module.exports = {',
        '  safelist: [',
        ...safelistLines,
        '  ],',
        '};',
        '```',
        '',
        '2. **정적 클래스로 변경**: 동적 클래스 대신 조건부 렌더링 사용',
      );
    }

    // 다크 모드 분석
    if (showDarkMode) {
      sb.section('## 🌙 다크 모드 분석');

      if (darkModeIssues.length === 0) {
        sb.push('✅ 다크 모드 관련 이슈 없음');
      } else {
        sb.push(
          `⚠️ ${darkModeIssues.length}개 컴포넌트에서 다크 모드 변형 누락 가능성`,
          '',
          '| 컴포넌트 | 라이트 클래스 | 권장 추가 |',
          '|----------|--------------|----------|',
        );

        for (const issue of darkModeIssues.slice(0, 10)) {
          const lightClass = issue.currentValue || '-';
          // 간단한 다크 모드 추천
          let darkSuggestion = '-';
          if (lightClass.includes('bg-white')) {
            darkSuggestion = 'dark:bg-gray-800';
          } else if (lightClass.includes('bg-gray-50')) {
            darkSuggestion = 'dark:bg-gray-900';
          } else if (lightClass.includes('text-gray-900')) {
            darkSuggestion = 'dark:text-white';
          } else if (lightClass.includes('border-gray-200')) {
            darkSuggestion = 'dark:border-gray-700';
          }

          sb.push(`| ${issue.componentName || '-'} | \`${lightClass}\` | \`${darkSuggestion}\` |`);
        }

        if (darkModeIssues.length > 10) {
          sb.push(`| ... | +${darkModeIssues.length - 10}개 더 | |`);
        }
      }
    }

    // 반응형 분석
    if (showResponsive) {
      sb.section('## 📱 반응형 클래스 분석');

      // 컴포넌트별 반응형 클래스 사용 현황
      const responsiveStats = {
        withPortable: 0,
        withDesktop: 0,
        withSmMdLg: 0,
        noResponsive: 0,
      };

      for (const comp of components) {
        const classes = comp.classes || [];
        const hasPortable = classes.some((c: string) => c.startsWith('portable:'));
        const hasDesktop = classes.some((c: string) => c.startsWith('desktop:'));
        const hasSmMdLg = classes.some((c: string) =>
          c.startsWith('sm:') || c.startsWith('md:') || c.startsWith('lg:') || c.startsWith('xl:')
        );

        if (hasPortable) responsiveStats.withPortable++;
        if (hasDesktop) responsiveStats.withDesktop++;
        if (hasSmMdLg) responsiveStats.withSmMdLg++;
        if (!hasPortable && !hasDesktop && !hasSmMdLg) responsiveStats.noResponsive++;
      }

      sb.push(
        '| 반응형 타입 | 사용 컴포넌트 |',
        '|------------|-------------:|',
        `| portable: 접두사 | ${responsiveStats.withPortable} |`,
        `| desktop: 접두사 | ${responsiveStats.withDesktop} |`,
        `| sm:/md:/lg:/xl: | ${responsiveStats.withSmMdLg} |`,
        `| 반응형 없음 | ${responsiveStats.noResponsive} |`,
      );

      if (responsiveIssues.length > 0) {
        sb.section('### ⚠️ 반응형 이슈');
        for (const issue of responsiveIssues.slice(0, 5)) {
          sb.push(`- **${issue.componentName}**: ${issue.description}`);
          if (issue.suggestion) {
            sb.push(`  - 💡 ${issue.suggestion}`);
          }
        }
      }
    }

    // 클래스 사용 통계
    sb.section('## 📈 클래스 사용 통계');

    const allClasses: string[] = [];
    for (const comp of components) {
      if (comp.classes) {
        allClasses.push(...comp.classes);
      }
    }

    const classFrequency = new Map<string, number>();
    for (const cls of allClasses) {
      classFrequency.set(cls, (classFrequency.get(cls) || 0) + 1);
    }

    const sortedClasses = [...classFrequency.entries()]
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10);

    sb.push(
      '**가장 많이 사용된 클래스 (상위 10개):**',
      '',
      '| 클래스 | 사용 횟수 |',
      '|--------|----------:|',
    );

    for (const [cls, count] of sortedClasses) {
      sb.push(`| \`${cls}\` | ${count} |`);
    }

    sb.push('', `총 고유 클래스: ${classFrequency.size}개`);

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-logs - 로그 이력 조회
// ============================================
server.tool(
  'g7-logs',
  'Logger로 쌓인 로그 이력을 조회합니다. 레벨, prefix, 키워드로 필터링할 수 있습니다.',
  {
    level: z.string().optional().describe('로그 레벨로 필터링 (log, warn, error). 쉼표로 구분하여 여러 레벨 지정 가능'),
    prefix: z.string().optional().describe('Logger prefix로 필터링 (예: "DataBindingEngine", "TemplateApp")'),
    search: z.string().optional().describe('메시지 내용으로 검색'),
    limit: z.number().default(50).describe('표시할 최대 로그 수 (기본: 50)'),
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    showStats: z.boolean().default(true).describe('로그 통계 표시 (기본: true)'),
  },
  async ({ level, prefix, search, limit, offset = 0, showStats }) => {
    // 로그 데이터 로드
    const logsData = readDebugFile('logs-latest.json');

    // 상태 덤프에서도 로그 데이터 확인
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const logs = logsData || stateDumpData?.logs;

    if (!logs) {
      const sb = createSectionBuilder('# 📋 Logger 로그 이력', null);
      sb.push(
        '⚠️ 로그 데이터가 없습니다.',
        DUMP_INSTRUCTION,
      );
      sb.section('### 수동 로그 확인');
      sb.push(
        '브라우저 콘솔에서 다음 명령 실행:',
        '```js',
        '// DevTools 로그 조회',
        'G7DevTools?.logs?.get?.() || "DevTools not available"',
        '```',
      );
      return { content: sb.build() };
    }

    const sb = createSectionBuilder('# 📋 Logger 로그 이력', null);

    const entries = logs.entries || [];
    const stats = logs.stats;

    // 통계 표시
    if (showStats && stats) {
      sb.section('## 📊 통계');
      sb.push(
        '| 항목 | 값 |',
        '|------|---:|',
        `| 전체 로그 | ${stats.totalLogs}개 |`,
        `| Log | ${stats.byLevel?.log || 0}개 |`,
        `| Warn | ${stats.byLevel?.warn || 0}개 |`,
        `| Error | ${stats.byLevel?.error || 0}개 |`,
        `| Debug | ${stats.byLevel?.debug || 0}개 |`,
        `| Info | ${stats.byLevel?.info || 0}개 |`,
        `| 최근 1분 에러 | ${stats.recentErrors || 0}개 |`,
        `| 최근 1분 경고 | ${stats.recentWarnings || 0}개 |`,
      );

      // Prefix별 분포
      if (stats.byPrefix && Object.keys(stats.byPrefix).length > 0) {
        sb.section('### Prefix별 분포');
        sb.push(
          '| Prefix | 로그 수 |',
          '|--------|-------:|',
        );

        const sortedPrefixes = Object.entries(stats.byPrefix as Record<string, number>)
          .sort((a, b) => b[1] - a[1])
          .slice(0, 15);

        for (const [pfx, count] of sortedPrefixes) {
          sb.push(`| ${pfx} | ${count}개 |`);
        }
      }
    }

    // 필터링
    let filteredLogs = [...entries];

    if (level) {
      const levels = level.split(',').map(l => l.trim().toLowerCase());
      filteredLogs = filteredLogs.filter((log: any) => levels.includes(log.level));
    }

    if (prefix) {
      const prefixLower = prefix.toLowerCase();
      filteredLogs = filteredLogs.filter((log: any) =>
        log.prefix?.toLowerCase().includes(prefixLower)
      );
    }

    if (search) {
      const searchLower = search.toLowerCase();
      filteredLogs = filteredLogs.filter((log: any) =>
        log.message?.toLowerCase().includes(searchLower)
      );
    }

    // 최신 순으로 정렬 후 페이지네이션 적용
    const reversedLogs = [...filteredLogs].reverse();
    const { items: displayLogs, pagination } = applyPagination(reversedLogs, offset, limit);

    // 로그 목록 출력
    sb.section(`## 📝 로그 목록 (${pagination.offset + 1}~${pagination.offset + displayLogs.length}/${pagination.total}개)`);

    if (displayLogs.length === 0) {
      sb.push('_필터 조건에 맞는 로그가 없습니다._');
    } else {
      sb.push(
        '| 시간 | 레벨 | Prefix | 메시지 |',
        '|------|------|--------|--------|',
      );

      for (const log of displayLogs) {
        const time = log.timestamp ? new Date(log.timestamp).toLocaleTimeString() : '-';
        const levelEmoji: Record<string, string> = {
          log: '📝',
          warn: '⚠️',
          error: '❌',
          debug: '🔍',
          info: 'ℹ️',
        };
        const emoji = levelEmoji[log.level] || '📋';

        // 메시지 길이 제한 및 테이블 호환 처리
        let message = log.message || '';
        message = message.replace(/\|/g, '\\|').replace(/\n/g, ' ');
        if (message.length > 60) {
          message = message.substring(0, 57) + '...';
        }

        sb.push(`| ${time} | ${emoji} ${log.level || '-'} | ${log.prefix || '-'} | ${message} |`);
      }
    }

    // 에러 로그 상세 (스택 트레이스 포함)
    const errorLogs = displayLogs.filter((log: any) => log.level === 'error' && log.stack);
    if (errorLogs.length > 0 && errorLogs.length <= 5) {
      sb.section('## ❌ 에러 상세 (스택 트레이스)');

      for (const log of errorLogs.slice(0, 3)) {
        const msgPreview = (log.message || '').substring(0, 50);
        const stackLines = (log.stack || '').split('\n').slice(0, 5);
        sb.push(
          `### [${log.prefix}] ${msgPreview}`,
          '',
          '```',
          stackLines.join('\n'),
          '```',
          '',
        );
      }
    }

    // 페이지네이션 정보 추가
    sb.pushPagination(pagination, '로그');

    // 사용 팁
    sb.section('## 💡 필터링 팁');
    sb.push(
      '- `level=error` - 에러만 조회',
      '- `level=warn,error` - 경고와 에러 조회',
      '- `prefix=DataBindingEngine` - 특정 모듈 로그만',
      '- `search=undefined` - undefined 관련 로그 검색',
    );

    return { content: sb.build() };
  }
);

// ============================================
// Tool: g7-layout - 현재 렌더링 중인 레이아웃 JSON 조회
// ============================================
server.tool(
  'g7-layout',
  '현재 렌더링 중인 레이아웃의 전체 JSON을 조회합니다. 레이아웃 캐시 문제를 진단하거나 실제 렌더링되는 구조를 확인할 때 사용합니다.',
  {
    showFull: z.boolean().optional().describe('전체 레이아웃 JSON 표시 (기본: false, 요약만 표시)'),
    showHistory: z.boolean().optional().describe('레이아웃 로드 이력 표시 (기본: false)'),
    section: z.string().optional().describe('특정 섹션만 조회 (components, data_sources, computed, defines, slots, modals, init_actions, scripts, meta, permissions)'),
    search: z.string().optional().describe('레이아웃 JSON 내 텍스트 검색 (컴포넌트명, 속성값 등)'),
  },
  async ({ showFull = false, showHistory = false, section, search }) => {
    // 레이아웃 데이터 로드
    const layoutData = readDebugFile('layout-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const layout = layoutData || stateDumpData?.layout;

    if (!layout) {
      return {
        content: [{ type: 'text', text: '# 📐 현재 렌더링 중인 레이아웃\n\n⚠️ 레이아웃 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('layout-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { current, history = [], stats = {} } = layout;

    const sb = createSectionBuilder('# 📐 현재 렌더링 중인 레이아웃', timeAgo);

    // 기본 정보 표시
    sb.section('## 📊 통계');
    sb.push(
      `📂 총 로드 횟수: ${stats.totalLoads || 0}`,
      `💾 캐시 히트: ${stats.cacheHits || 0}`,
      `🌐 API 로드: ${stats.apiLoads || 0}`,
      '',
    );

    if (current) {
      sb.section('## 📋 현재 레이아웃');
      sb.push(
        `- **경로**: \`${current.layoutPath}\``,
        `- **템플릿**: \`${current.templateId}\``,
        `- **버전**: ${current.version || '-'}`,
        `- **이름**: ${current.layoutName || current.layoutJson?.layout_name || '-'}`,
        `- **소스**: ${current.source === 'cache' ? '💾 캐시' : '🌐 API'}`,
        `- **로드 시간**: ${current.loadedAt ? new Date(current.loadedAt).toLocaleTimeString() : '-'}`,
      );
      // 권한 표시
      const permissions = current.layoutJson?.permissions;
      if (permissions === undefined) {
        sb.push(`- **권한**: 미정의 (부모 상속)`);
      } else if (Array.isArray(permissions) && permissions.length === 0) {
        sb.push(`- **권한**: 🌐 공개`);
      } else if (Array.isArray(permissions)) {
        sb.push(`- **권한**: 🔒 ${permissions.join(', ')}`);
      }
      sb.push('');

      const layoutJson = current.layoutJson;

      if (layoutJson) {
        // 레이아웃 구조 요약
        sb.section('### 🏗️ 구조 요약');
        sb.push(
          `| 항목 | 개수 |`,
          `|------|-----:|`,
          `| components | ${Array.isArray(layoutJson.components) ? layoutJson.components.length : 0}개 |`,
          `| data_sources | ${Array.isArray(layoutJson.data_sources) ? layoutJson.data_sources.length : 0}개 |`,
          `| computed | ${layoutJson.computed ? Object.keys(layoutJson.computed).length : 0}개 |`,
          `| defines | ${layoutJson.defines ? Object.keys(layoutJson.defines).length : 0}개 |`,
          `| slots | ${layoutJson.slots ? Object.keys(layoutJson.slots).length : 0}개 |`,
          `| modals | ${layoutJson.modals ? Object.keys(layoutJson.modals).length : 0}개 |`,
          `| init_actions | ${Array.isArray(layoutJson.init_actions) ? layoutJson.init_actions.length : 0}개 |`,
          `| scripts | ${Array.isArray(layoutJson.scripts) ? layoutJson.scripts.length : 0}개 |`,
          `| warnings | ${Array.isArray(layoutJson.warnings) ? layoutJson.warnings.length : 0}개 |`,
          `| permissions | ${Array.isArray(layoutJson.permissions) ? layoutJson.permissions.length : 0}개 |`,
          '',
        );

        // search 필터링
        if (search) {
          sb.section(`### 🔍 검색: "${search}"`);
          const filtered = filterObjectBySearch(layoutJson, search);
          if (filtered) {
            sb.pushJson(filtered);
          } else {
            sb.push('검색 결과 없음');
          }
        } else if (section) {
          // 특정 섹션만 조회
          const sectionData = layoutJson[section];
          if (sectionData !== undefined) {
            sb.section(`### 📎 ${section} 섹션`);
            sb.pushJson(sectionData);
          } else {
            sb.push(
              `⚠️ "${section}" 섹션이 존재하지 않습니다.`,
              '',
              '사용 가능한 섹션: components, data_sources, computed, defines, slots, modals, init_actions, scripts, meta, errorHandling, permissions',
            );
          }
        } else if (showFull) {
          // 전체 JSON 표시
          sb.section('### 📄 전체 레이아웃 JSON');
          sb.pushJson(layoutJson);
        } else {
          // 요약 정보 - data_sources 표시
          if (Array.isArray(layoutJson.data_sources) && layoutJson.data_sources.length > 0) {
            sb.section('### 🔗 데이터 소스');
            sb.push(
              '| ID | 엔드포인트 | 로딩 전략 |',
              '|-----|----------|----------|',
            );
            for (const ds of layoutJson.data_sources.slice(0, 10)) {
              const id = ds.id || '-';
              const endpoint = ds.endpoint || '-';
              const strategy = ds.loadingStrategy || 'eager';
              sb.push(`| \`${id}\` | \`${endpoint.substring(0, 40)}${endpoint.length > 40 ? '...' : ''}\` | ${strategy} |`);
            }
            if (layoutJson.data_sources.length > 10) {
              sb.push(`| ... | +${layoutJson.data_sources.length - 10}개 더 | |`);
            }
            sb.push('');
          }

          // computed 표시
          if (layoutJson.computed && Object.keys(layoutJson.computed).length > 0) {
            sb.section('### 🧮 Computed 속성');
            const computedKeys = Object.keys(layoutJson.computed);
            for (const key of computedKeys.slice(0, 10)) {
              const value = layoutJson.computed[key];
              const valueStr = typeof value === 'string' ? value : JSON.stringify(value);
              sb.push(`- \`${key}\`: \`${valueStr.substring(0, 60)}${valueStr.length > 60 ? '...' : ''}\``);
            }
            if (computedKeys.length > 10) {
              sb.push(`... +${computedKeys.length - 10}개 더`);
            }
            sb.push('');
          }

          // slots 표시
          if (layoutJson.slots && Object.keys(layoutJson.slots).length > 0) {
            sb.section('### 📦 Slots');
            for (const slotName of Object.keys(layoutJson.slots)) {
              const slot = layoutJson.slots[slotName];
              const componentCount = Array.isArray(slot) ? slot.length : (slot?.components?.length || 0);
              sb.push(`- \`${slotName}\`: ${componentCount}개 컴포넌트`);
            }
            sb.push('');
          }

          sb.push(
            '💡 전체 JSON을 보려면 `showFull: true` 옵션을 사용하세요.',
            '💡 특정 섹션만 보려면 `section: "components"` 등을 사용하세요.',
          );
        }
      }
    } else {
      sb.push('⚠️ 현재 로드된 레이아웃이 없습니다.', '');
    }

    // 히스토리 표시
    if (showHistory && history.length > 0) {
      sb.section('## 📜 로드 이력');
      sb.push(
        '| 시간 | 레이아웃 | 템플릿 | 소스 |',
        '|------|---------|--------|------|',
      );

      const recentHistory = history.slice(-20).reverse();
      for (const entry of recentHistory) {
        const time = entry.loadedAt ? new Date(entry.loadedAt).toLocaleTimeString() : '-';
        const layoutPath = entry.layoutPath || '-';
        const templateId = entry.templateId || '-';
        const source = entry.source === 'cache' ? '💾' : '🌐';
        sb.push(`| ${time} | \`${layoutPath}\` | ${templateId} | ${source} |`);
      }
      sb.push('');
    }

    // 사용 팁
    sb.section('## 💡 사용 팁');
    sb.push(
      '- `showFull: true` - 전체 레이아웃 JSON 표시',
      '- `showHistory: true` - 레이아웃 로드 이력 표시',
      '- `section: "components"` - components 섹션만 조회',
      '- `section: "data_sources"` - data_sources 섹션만 조회',
      '',
      '### 레이아웃 캐시 확인 방법',
      '1. "소스" 항목이 `💾 캐시`인지 `🌐 API`인지 확인',
      '2. 캐시에서 로드되었다면 레이아웃 JSON이 최신 상태인지 확인',
      '3. 캐시 문제가 의심되면 `php artisan template:refresh-layout` 실행',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-change-detection - 변경 감지 조회
// ============================================
server.tool(
  'g7-change-detection',
  '핸들러 실행 시 상태/데이터소스 변경 여부를 추적합니다. 핸들러가 성공했지만 상태 변경이 없는 경우, early return, 기대 변경 미발생 등을 감지합니다.',
  {
    warningsOnly: z.boolean().optional().describe('경고/에러가 있는 항목만 표시 (기본: false)'),
    handlerName: z.string().optional().describe('특정 핸들러 이름으로 필터링'),
    limit: z.number().optional().describe('표시할 최대 개수 (기본: 20)'),
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    showStateChanges: z.boolean().optional().describe('상태 변경 상세 표시 (기본: true)'),
    showAlerts: z.boolean().optional().describe('알림 목록 표시 (기본: true)'),
    search: z.string().optional().describe('텍스트 검색 (핸들러명, 상태 경로 등 항목 내 문자열 매칭)'),
  },
  async ({ warningsOnly = false, handlerName, limit = 20, offset = 0, showStateChanges = true, showAlerts = true, search }) => {
    // 변경 감지 데이터 로드
    const changeDetectionData = readDebugFile('change-detection-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const changeDetection = changeDetectionData || stateDumpData?.changeDetection;

    if (!changeDetection) {
      return {
        content: [{ type: 'text', text: '# 🔍 변경 감지 정보\n\n⚠️ 변경 감지 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('change-detection-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { executionDetails = [], stateChangeHistory = [], alerts = [], stats = {} } = changeDetection;

    const sb = createSectionBuilder('# 🔍 변경 감지 정보', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `📋 총 핸들러 실행: ${stats.totalExecutions || 0}`,
      `✅ 상태 변경 있음: ${stats.executionsWithStateChange || 0}`,
      `⚠️ 상태 변경 없음: ${stats.executionsWithoutStateChange || 0}`,
      `⏎ Early Return: ${stats.earlyReturnCount || 0}`,
      `🔔 알림: ${stats.alertCount || 0}`,
      '',
    );

    // 알림 유형별 개수
    if (stats.alertsByType && Object.keys(stats.alertsByType).length > 0) {
      sb.push(
        '### 알림 유형별',
        '',
        '| 유형 | 개수 |',
        '|------|-----:|',
      );
      for (const [type, count] of Object.entries(stats.alertsByType)) {
        if (count as number > 0) {
          const icon = getAlertTypeIcon(type);
          sb.push(`| ${icon} ${type} | ${count} |`);
        }
      }
      sb.push('');
    }

    // 알림 목록 (경고/에러)
    if (showAlerts && alerts.length > 0) {
      let filteredAlerts = warningsOnly
        ? alerts.filter((a: any) => a.severity === 'error' || a.severity === 'warning')
        : alerts;
      filteredAlerts = applySearch(filteredAlerts, search);

      if (filteredAlerts.length > 0) {
        // 최신 순으로 정렬 후 페이지네이션 적용
        const sortedAlerts = [...filteredAlerts].reverse();
        const { items: paginatedAlerts, pagination: alertPagination } = applyPagination(sortedAlerts, offset, limit);

        sb.section(`## 🔔 알림 목록 (${alertPagination.offset + 1}~${alertPagination.offset + paginatedAlerts.length}/${alertPagination.total}개)`);

        for (const alert of paginatedAlerts) {
          const icon = getAlertTypeIcon(alert.type);
          const severity = alert.severity === 'error' ? '🔴' : alert.severity === 'warning' ? '🟡' : '🔵';
          sb.push(
            `### ${severity} ${icon} ${alert.message}`,
            '',
            `- **핸들러**: \`${alert.handlerName}\``,
          );
          if (alert.statePath) {
            sb.push(`- **상태 경로**: \`${alert.statePath}\``);
          }
          if (alert.dataSourceId) {
            sb.push(`- **데이터소스**: \`${alert.dataSourceId}\``);
          }
          sb.push(`- **설명**: ${alert.description}`);
          if (alert.suggestion) {
            sb.push(`- **💡 제안**: ${alert.suggestion}`);
          }
          if (alert.docLink) {
            sb.push(`- **📚 문서**: \`${alert.docLink}\``);
          }
          sb.push('');
        }

        // 알림 페이지네이션 정보 추가
        sb.pushPagination(alertPagination, '알림');
      }
    }

    // 핸들러 실행 이력
    sb.section('## 🔧 핸들러 실행 이력');

    let filteredExecutions = executionDetails;

    // 핸들러 이름 필터링
    if (handlerName) {
      filteredExecutions = filteredExecutions.filter((e: any) =>
        e.handlerName.toLowerCase().includes(handlerName.toLowerCase())
      );
    }

    // 경고만 필터링
    if (warningsOnly) {
      filteredExecutions = filteredExecutions.filter((e: any) =>
        e.alerts?.length > 0 || (e.stateChanges?.length === 0 && e.exitReason === 'normal')
      );
    }

    if (filteredExecutions.length === 0) {
      sb.push('표시할 핸들러 실행 이력이 없습니다.', '');
    } else {
      // 최신 순으로 정렬 후 페이지네이션 적용
      const sortedExecutions = [...filteredExecutions].reverse();
      const { items: paginatedExecutions, pagination: execPagination } = applyPagination(sortedExecutions, offset, limit);

      sb.push(
        `📄 실행 이력: ${execPagination.offset + 1}~${execPagination.offset + paginatedExecutions.length}/${execPagination.total}개`,
        '',
        '| 핸들러 | 종료 | 상태변경 | 소요시간 | 알림 |',
        '|--------|------|----------|----------|------|',
      );

      for (const exec of paginatedExecutions) {
        const exitIcon = getExitReasonIcon(exec.exitReason);
        const duration = exec.duration ? `${exec.duration}ms` : '-';
        const alertCount = exec.alerts?.length || 0;
        const alertBadge = alertCount > 0 ? `⚠️ ${alertCount}` : '-';
        const stateChangeCount = exec.stateChanges?.length || 0;

        sb.push(`| \`${exec.handlerName}\` | ${exitIcon} | ${stateChangeCount}개 | ${duration} | ${alertBadge} |`);
      }
      sb.push('');

      // 핸들러 실행 페이지네이션 정보 추가
      sb.pushPagination(execPagination, '핸들러 실행');

      // 상세 정보 (경고가 있는 항목)
      const execsWithIssues = paginatedExecutions.filter((e: any) =>
        e.alerts?.length > 0 || (e.stateChanges?.length === 0 && e.exitReason === 'normal')
      );

      if (execsWithIssues.length > 0 && showStateChanges) {
        sb.section('### 📋 문제 있는 실행 상세');

        for (const exec of execsWithIssues.slice(0, 5)) {
          sb.push(
            `#### ${getExitReasonIcon(exec.exitReason)} \`${exec.handlerName}\``,
            '',
            `- **종료 사유**: ${exec.exitReason || 'unknown'}`,
          );
          if (exec.exitLocation) {
            sb.push(`- **종료 위치**: \`${exec.exitLocation}\``);
          }
          if (exec.exitDescription) {
            sb.push(`- **설명**: ${exec.exitDescription}`);
          }

          // 상태 변경
          if (exec.stateChanges?.length > 0) {
            sb.push('- **상태 변경**:');
            for (const sc of exec.stateChanges.slice(0, 5)) {
              sb.push(`  - \`${sc.path}\` (${sc.changeType})`);
              if (sc.comparison?.changedKeys?.length > 0) {
                sb.push(`    - 변경된 키: ${sc.comparison.changedKeys.join(', ')}`);
              }
            }
          } else {
            sb.push('- **상태 변경**: 없음');
          }

          // 기대 변경
          if (exec.expectedChanges) {
            sb.push('- **기대 변경**:');
            if (exec.expectedChanges.expectedStatePaths?.length > 0) {
              sb.push(`  - 상태: ${exec.expectedChanges.expectedStatePaths.join(', ')}`);
            }
            if (exec.expectedChanges.expectedDataSources?.length > 0) {
              sb.push(`  - 데이터소스: ${exec.expectedChanges.expectedDataSources.join(', ')}`);
            }
          }

          // 알림
          if (exec.alerts?.length > 0) {
            sb.push('- **알림**:');
            for (const alert of exec.alerts) {
              const icon = getAlertTypeIcon(alert.type);
              sb.push(`  - ${icon} ${alert.message}`);
            }
          }

          sb.push('');
        }
      }
    }

    // 상태 변경 이력
    if (showStateChanges && stateChangeHistory.length > 0) {
      sb.section('## 📝 최근 상태 변경 이력');
      sb.push(
        '| 경로 | 변경유형 | 타입변경 | 동일 |',
        '|------|----------|----------|------|',
      );

      const recentChanges = stateChangeHistory.slice(-10);
      for (const sc of recentChanges) {
        const typeChanged = sc.comparison?.typeChanged ? '✓' : '-';
        const isDeepEqual = sc.comparison?.isDeepEqual ? '🔄' : '-';
        sb.push(`| \`${sc.path}\` | ${sc.changeType} | ${typeChanged} | ${isDeepEqual} |`);
      }
      sb.push('');
    }

    // 사용 팁
    sb.section('## 💡 사용 팁');
    sb.push(
      '- `warningsOnly: true` - 문제가 있는 항목만 표시',
      '- `handlerName: "updateProduct"` - 특정 핸들러만 필터링',
      '- `limit: 50` - 더 많은 항목 표시',
      '',
      '### 종료 사유 아이콘',
      '- ✅ normal: 정상 완료',
      '- ⏎ early-return-condition: 조건부 early return',
      '- ❌ early-return-validation: 검증 실패',
      '- 💥 error: 에러 발생',
      '- ❓ unknown: 알 수 없음',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-sequence - Sequence 실행 추적 조회
// ============================================
server.tool(
  'g7-sequence',
  'Sequence 핸들러 실행 시 각 액션별 상태 변화를 추적합니다. sequence 내 여러 setState 호출 시 중간 상태를 확인할 수 있습니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    sequenceId: z.string().optional().describe('특정 Sequence ID로 필터링'),
    limit: z.number().optional().describe('표시할 최대 개수 (기본: 10)'),
    showStateDiff: z.boolean().optional().describe('상태 diff 표시 (기본: true)'),
    showPendingState: z.boolean().optional().describe('pending 상태 표시 (기본: false)'),
    errorsOnly: z.boolean().optional().describe('에러가 발생한 sequence만 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (액션, 상태 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, sequenceId, limit = 10, showStateDiff = true, showPendingState = false, errorsOnly = false, search }) => {
    // Sequence 추적 데이터 로드
    const sequenceData = readDebugFile('sequence-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const sequenceTracking = sequenceData || stateDumpData?.sequenceTracking;

    if (!sequenceTracking) {
      return {
        content: [{ type: 'text', text: '# 🔄 Sequence 실행 추적\n\n⚠️ Sequence 추적 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('sequence-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { executions = [], currentExecution, stats = {} } = sequenceTracking;

    const sb = createSectionBuilder('# 🔄 Sequence 실행 추적', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `📋 총 Sequence 실행: ${stats.totalExecutions || 0}`,
      `✅ 성공: ${stats.successCount || 0}`,
      `❌ 실패: ${stats.errorCount || 0}`,
      `🎯 총 액션 실행: ${stats.totalActions || 0}`,
      `⏱️ 평균 소요 시간: ${Math.round(stats.avgDuration || 0)}ms`,
      '',
    );

    // 자주 사용되는 핸들러
    if (stats.topHandlers && stats.topHandlers.length > 0) {
      sb.push(
        '### 자주 사용된 핸들러',
        '',
        '| 핸들러 | 호출 횟수 |',
        '|--------|----------:|',
      );
      for (const { handler, count } of stats.topHandlers) {
        sb.push(`| \`${handler}\` | ${count} |`);
      }
      sb.push('');
    }

    // 현재 실행 중인 Sequence
    if (currentExecution) {
      sb.section('## 🔄 현재 실행 중');
      sb.push(
        `- **ID**: \`${currentExecution.sequenceId}\``,
        `- **시작**: ${new Date(currentExecution.startTime).toISOString()}`,
        `- **액션 수**: ${currentExecution.actions?.length || 0}`,
      );
      if (currentExecution.trigger?.eventType) {
        sb.push(`- **트리거**: ${currentExecution.trigger.eventType}`);
      }
      sb.push('');
    }

    // Sequence 실행 이력
    sb.section('## 📜 Sequence 실행 이력');

    let filteredExecutions = executions;

    // 특정 ID 필터링
    if (sequenceId) {
      filteredExecutions = filteredExecutions.filter((e: any) =>
        e.sequenceId.includes(sequenceId)
      );
    }

    // 에러만 필터링
    if (errorsOnly) {
      filteredExecutions = filteredExecutions.filter((e: any) => e.status === 'error');
    }
    filteredExecutions = applySearch(filteredExecutions, search);

    if (filteredExecutions.length === 0) {
      sb.push('표시할 Sequence 실행 이력이 없습니다.', '');
    } else {
      // 페이지네이션 적용
      const { items: paginatedExecutions, pagination } = applyPagination(
        filteredExecutions.reverse(), // 최신 순
        offset,
        limit
      );

      for (const exec of paginatedExecutions) {
        const statusIcon = exec.status === 'success' ? '✅' : exec.status === 'error' ? '❌' : '🔄';
        sb.push(
          `### ${statusIcon} Sequence: \`${exec.sequenceId}\``,
          '',
          `- **상태**: ${exec.status}`,
          `- **소요 시간**: ${exec.totalDuration}ms`,
          `- **액션 수**: ${exec.actions?.length || 0}`,
        );
        if (exec.trigger?.eventType) {
          sb.push(`- **트리거 이벤트**: ${exec.trigger.eventType}`);
        }
        if (exec.trigger?.callbackType) {
          sb.push(`- **콜백 타입**: ${exec.trigger.callbackType}`);
        }
        if (exec.error) {
          sb.push(`- **에러**: ${exec.error.message}`);
        }
        if (exec.failedAtIndex !== undefined) {
          sb.push(`- **실패 위치**: 액션 #${exec.failedAtIndex}`);
        }
        sb.push('');

        // 액션별 상세 정보
        if (exec.actions && exec.actions.length > 0) {
          sb.push(
            '#### 액션 실행 순서',
            '',
            '| # | 핸들러 | 상태 | 소요시간 |',
            '|--:|--------|------|----------|',
          );

          for (const action of exec.actions) {
            const actionStatus = action.status === 'success' ? '✅' : '❌';
            sb.push(`| ${action.index} | \`${action.handler}\` | ${actionStatus} | ${action.duration}ms |`);
          }
          sb.push('');

          // 상태 diff 표시
          if (showStateDiff) {
            sb.push('#### 상태 변화 추적', '');

            for (const action of exec.actions) {
              const hasDiff = action.stateDiff &&
                ((action.stateDiff.global?.changed?.length > 0) ||
                 (action.stateDiff.local?.changed?.length > 0) ||
                 (action.stateDiff.isolated?.changed?.length > 0));

              if (hasDiff || action.status === 'error') {
                sb.push(`##### 액션 #${action.index}: \`${action.handler}\``, '');

                // 파라미터
                if (action.params && Object.keys(action.params).length > 0) {
                  sb.push('**params**:');
                  sb.pushJson(action.params, 500);
                }

                // Global diff
                if (action.stateDiff?.global?.changed?.length > 0) {
                  sb.push('**_global 변경**:');
                  for (const change of action.stateDiff.global.changed.slice(0, 5)) {
                    sb.push(`- \`${change.path}\`: \`${JSON.stringify(change.oldValue).substring(0, 50)}\` → \`${JSON.stringify(change.newValue).substring(0, 50)}\``);
                  }
                }

                // Local diff
                if (action.stateDiff?.local?.changed?.length > 0) {
                  sb.push('**_local 변경**:');
                  for (const change of action.stateDiff.local.changed.slice(0, 5)) {
                    sb.push(`- \`${change.path}\`: \`${JSON.stringify(change.oldValue).substring(0, 50)}\` → \`${JSON.stringify(change.newValue).substring(0, 50)}\``);
                  }
                }

                // Isolated diff
                if (action.stateDiff?.isolated?.changed?.length > 0) {
                  sb.push('**_isolated 변경**:');
                  for (const change of action.stateDiff.isolated.changed.slice(0, 5)) {
                    sb.push(`- \`${change.path}\`: \`${JSON.stringify(change.oldValue).substring(0, 50)}\` → \`${JSON.stringify(change.newValue).substring(0, 50)}\``);
                  }
                }

                // Pending state
                if (showPendingState && action.pendingState) {
                  sb.push('**__g7PendingLocalState**:');
                  sb.pushJson(action.pendingState, 300);
                }

                // 에러 정보
                if (action.error) {
                  sb.push(
                    '**에러**:',
                    `- ${action.error.name}: ${action.error.message}`,
                  );
                  if (action.error.stack) {
                    sb.push('```', action.error.stack.split('\n').slice(0, 3).join('\n'), '```');
                  }
                }

                sb.push('');
              }
            }
          }
        }

        sb.push('---', '');
      }

      // 페이지네이션 정보 추가
      sb.pushPagination(pagination, 'Sequence 실행');
    }

    // 사용 팁
    sb.section('## 💡 사용 팁');
    sb.push(
      '- `errorsOnly: true` - 에러가 발생한 sequence만 표시',
      '- `showStateDiff: false` - 상태 diff 숨기기 (요약만 보기)',
      '- `showPendingState: true` - __g7PendingLocalState 표시',
      '- `offset: 0, limit: 10` - 페이지네이션 (기본값)',
      '',
      '### 주요 분석 포인트',
      '- sequence 내 setState 호출 후 다음 액션에서 상태가 올바르게 반영되는지 확인',
      '- 특정 액션에서 에러 발생 시 해당 시점의 상태 확인',
      '- _local vs _global 상태 변경 추적',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-stale-closure - Stale Closure 감지 조회
// ============================================
server.tool(
  'g7-stale-closure',
  'Stale Closure 경고를 조회합니다. async 작업, 콜백, setTimeout 등에서 캡처된 상태가 실제 상태와 다른 경우를 감지합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('표시할 최대 경고 개수 (기본: 20)'),
    severity: z.enum(['all', 'info', 'warning', 'error']).optional().describe('심각도 필터 (기본: all)'),
    type: z.string().optional().describe('경고 유형 필터 (예: async-state-capture, callback-state-capture)'),
    showSuggestions: z.boolean().optional().describe('해결 제안 표시 (기본: true)'),
    search: z.string().optional().describe('텍스트 검색 (컴포넌트, 상태 경로 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, limit = 20, severity = 'all', type, showSuggestions = true, search }) => {
    // Stale Closure 추적 데이터 로드
    const staleClosureData = readDebugFile('stale-closure-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const staleClosureTracking = staleClosureData || stateDumpData?.staleClosureTracking;

    if (!staleClosureTracking) {
      return {
        content: [{ type: 'text', text: '# 🔒 Stale Closure 감지\n\n⚠️ Stale Closure 추적 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('stale-closure-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { warnings = [], stats = {} } = staleClosureTracking;

    const sb = createSectionBuilder('# 🔒 Stale Closure 감지', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `⚠️ 총 경고: ${stats.totalWarnings || 0}`,
      `❌ 에러 수준: ${stats.errorCount || 0}`,
      `⚡ 경고 수준: ${stats.warningCount || 0}`,
      `ℹ️ 정보 수준: ${stats.infoCount || 0}`,
      '',
    );

    // 유형별 통계
    if (stats.byType && Object.keys(stats.byType).length > 0) {
      sb.push(
        '### 유형별 분포',
        '',
        '| 유형 | 횟수 |',
        '|------|-----:|',
      );
      for (const [warningType, count] of Object.entries(stats.byType)) {
        sb.push(`| \`${warningType}\` | ${count} |`);
      }
      sb.push('');
    }

    // 경고 필터링
    let filteredWarnings = warnings;

    if (severity !== 'all') {
      filteredWarnings = filteredWarnings.filter((w: any) => w.severity === severity);
    }

    if (type) {
      filteredWarnings = filteredWarnings.filter((w: any) => w.type === type);
    }
    filteredWarnings = applySearch(filteredWarnings, search);

    // 경고에 페이지네이션 적용
    const { items: paginatedWarnings, pagination } = applyPagination(
      filteredWarnings.reverse(), // 최신 순
      offset,
      limit
    );

    if (paginatedWarnings.length === 0) {
      sb.section('## ✅ 감지된 경고 없음');
      sb.push('현재 필터 조건에 맞는 Stale Closure 경고가 없습니다.');
    } else {
      sb.section('## 🚨 경고 목록');

      for (const warning of paginatedWarnings) {
        const severityIcon = warning.severity === 'error' ? '❌' :
                            warning.severity === 'warning' ? '⚠️' : 'ℹ️';

        sb.push(
          `### ${severityIcon} ${warning.type}`,
          '',
          `- **ID**: \`${warning.id}\``,
          `- **위치**: \`${warning.location || 'unknown'}\``,
          `- **시간 차이**: ${warning.timeDiff}ms`,
        );

        if (warning.description) {
          sb.push(`- **설명**: ${warning.description}`);
        }

        // 캡처된 상태 vs 현재 상태
        if (warning.capturedState && warning.currentState) {
          sb.push(
            '',
            '**상태 비교**:',
            `- 경로: \`${warning.capturedState.path}\``,
            `- 캡처된 값: \`${JSON.stringify(warning.capturedState.capturedValue).substring(0, 100)}\``,
            `- 현재 값: \`${JSON.stringify(warning.currentState.currentValue).substring(0, 100)}\``,
          );
        }

        // 해결 제안
        if (showSuggestions && warning.suggestion) {
          sb.push(
            '',
            '**💡 해결 제안**:',
            `> ${warning.suggestion}`,
          );

          if (warning.docLink) {
            sb.push(`> 📖 참조: ${warning.docLink}`);
          }
        }

        // 관련 액션 ID
        if (warning.actionId) {
          sb.push('', `**관련 액션**: \`${warning.actionId}\``);
        }

        sb.push('', '---', '');
      }

      // 페이지네이션 정보 추가
      sb.pushPagination(pagination, '경고');
    }

    // 사용 팁
    sb.section('## 💡 Stale Closure 해결 방법');
    sb.push(
      '### 패턴별 해결책',
      '',
      '**1. async-state-capture (비동기 상태 캡처)**',
      '```javascript',
      '// ❌ 문제: await 후 캡처된 상태 사용',
      'const { email } = _global;',
      'await apiCall();',
      'console.log(email); // stale!',
      '',
      '// ✅ 해결: await 후 상태 재조회',
      'await apiCall();',
      'const { email } = G7Core.state.get()._global;',
      '```',
      '',
      '**2. callback-state-capture (콜백 상태 캡처)**',
      '```javascript',
      '// ❌ 문제: 콜백에서 캡처된 상태 사용',
      '"onSuccess": [{ "handler": "setState", "params": { "data": "{{_local.data}}" } }]',
      '',
      '// ✅ 해결: 함수형 업데이트 또는 G7Core.state.getLocal() 사용',
      '"onSuccess": [{ "handler": "setState", "params": { "data": "{{response.data}}" } }]',
      '```',
      '',
      '**3. sequence-state-mismatch (시퀀스 상태 불일치)**',
      '```javascript',
      '// ❌ 문제: sequence 내 setState 후 다음 액션에서 이전 상태 참조',
      '',
      '// ✅ 해결: __g7PendingLocalState 또는 response 컨텍스트 사용',
      '```',
      '',
      '### 필터 옵션',
      '- `severity: "error"` - 심각한 경고만 표시',
      '- `type: "async-state-capture"` - 특정 유형만 필터링',
      '- `showSuggestions: false` - 해결 제안 숨기기',
      '',
      '### 페이지네이션',
      '- `offset: 0, limit: 20` - 처음 20개 조회 (기본값)',
      '- `offset: 20, limit: 20` - 21~40번째 항목 조회',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-nested-context - Nested Context 추적
// ============================================
server.tool(
  'g7-nested-context',
  'Nested Context(expandChildren, cellChildren, iteration, modal, slot) 추적 정보를 조회합니다. 부모 컨텍스트와 자식 컨텍스트 간 데이터 전달 및 접근 시도를 분석합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('표시할 최대 컨텍스트 개수 (기본: 20)'),
    type: z.enum(['all', 'expandChildren', 'cellChildren', 'iteration', 'modal', 'slot']).optional().describe('컨텍스트 타입 필터 (기본: all)'),
    showFailedOnly: z.boolean().optional().describe('실패한 접근 시도만 표시 (기본: false)'),
    componentId: z.string().optional().describe('특정 컴포넌트 ID로 필터링'),
    showValues: z.boolean().optional().describe('컨텍스트 값 상세 표시 (기본: false)'),
    search: z.string().optional().describe('텍스트 검색 (컴포넌트, 타입 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, limit = 20, type = 'all', showFailedOnly = false, componentId, showValues = false, search }) => {
    // Nested Context 추적 데이터 로드
    const nestedContextData = readDebugFile('nested-context-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const nestedContextTracking = nestedContextData || stateDumpData?.nestedContextTracking;

    if (!nestedContextTracking) {
      return {
        content: [{ type: 'text', text: '# 🔗 Nested Context 추적\n\n⚠️ Nested Context 추적 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('nested-context-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { contexts = [], stats = {} } = nestedContextTracking;

    const sb = createSectionBuilder('# 🔗 Nested Context 추적', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `📦 총 컨텍스트: ${stats.totalContexts || 0}`,
      `📏 최대 깊이: ${stats.maxDepth || 0}`,
      `❌ 접근 실패: ${stats.failedAccessCount || 0}`,
      '',
    );

    // 타입별 통계
    if (stats.byType && Object.keys(stats.byType).length > 0) {
      sb.push(
        '### 타입별 분포',
        '',
        '| 타입 | 횟수 |',
        '|------|-----:|',
      );
      for (const [contextType, count] of Object.entries(stats.byType)) {
        const icon = getNestedContextTypeIcon(contextType);
        sb.push(`| ${icon} \`${contextType}\` | ${count} |`);
      }
      sb.push('');
    }

    // 자주 실패하는 경로
    if (stats.commonFailedPaths && stats.commonFailedPaths.length > 0) {
      sb.push(
        '### ⚠️ 자주 실패하는 접근 경로',
        '',
        '| 경로 | 실패 횟수 |',
        '|------|----------:|',
      );
      for (const item of stats.commonFailedPaths.slice(0, 5)) {
        sb.push(`| \`${item.path}\` | ${item.count} |`);
      }
      sb.push('');
    }

    // 컨텍스트 필터링
    let filteredContexts = contexts;

    if (type !== 'all') {
      filteredContexts = filteredContexts.filter((c: any) => c.componentType === type);
    }

    if (componentId) {
      filteredContexts = filteredContexts.filter((c: any) =>
        c.componentId?.includes(componentId)
      );
    }

    if (showFailedOnly) {
      filteredContexts = filteredContexts.filter((c: any) =>
        c.accessAttempts?.some((a: any) => !a.found)
      );
    }
    filteredContexts = applySearch(filteredContexts, search);

    // 컨텍스트에 페이지네이션 적용
    const { items: paginatedContexts, pagination } = applyPagination(
      filteredContexts.reverse(), // 최신 순
      offset,
      limit
    );

    if (paginatedContexts.length === 0) {
      sb.section('## ✅ 표시할 컨텍스트 없음');
      sb.push('현재 필터 조건에 맞는 Nested Context가 없습니다.');
    } else {
      sb.section('## 🔍 컨텍스트 목록');

      for (const ctx of paginatedContexts) {
        const icon = getNestedContextTypeIcon(ctx.componentType);
        const hasFailedAccess = ctx.accessAttempts?.some((a: any) => !a.found);

        sb.push(
          `### ${icon} ${ctx.componentType} - \`${ctx.componentId || 'unknown'}\``,
          '',
          `- **ID**: \`${ctx.id}\``,
          `- **깊이**: ${ctx.depth}`,
        );
        if (ctx.parentId) {
          sb.push(`- **부모 컨텍스트**: \`${ctx.parentId}\``);
        }

        // 부모 컨텍스트 정보
        if (ctx.parentContext) {
          sb.push(
            '',
            '**부모 컨텍스트**:',
            `- 사용 가능 키: \`${(ctx.parentContext.available || []).join(', ') || '없음'}\``,
          );
          if (showValues && ctx.parentContext.values) {
            const truncated = truncateObject(ctx.parentContext.values, 200);
            sb.push(`- 값: \`${JSON.stringify(truncated)}\``);
          }
        }

        // 자체 컨텍스트 정보
        if (ctx.ownContext) {
          sb.push(
            '',
            '**자체 컨텍스트** (추가된 키):',
            `- 추가 키: \`${(ctx.ownContext.added || []).join(', ') || '없음'}\``,
          );
          if (showValues && ctx.ownContext.values) {
            const truncated = truncateObject(ctx.ownContext.values, 200);
            sb.push(`- 값: \`${JSON.stringify(truncated)}\``);
          }
        }

        // 병합된 컨텍스트
        if (ctx.mergedContext) {
          sb.push(
            '',
            '**병합된 컨텍스트**:',
            `- 전체 키: \`${(ctx.mergedContext.all || []).join(', ') || '없음'}\``,
          );
        }

        // 접근 시도
        if (ctx.accessAttempts && ctx.accessAttempts.length > 0) {
          sb.push(
            '',
            '**접근 시도**:',
            '',
            '| 경로 | 결과 | 값/에러 |',
            '|------|------|---------|',
          );
          for (const attempt of ctx.accessAttempts) {
            const resultIcon = attempt.found ? '✅' : '❌';
            const valueOrError = attempt.found
              ? truncateString(JSON.stringify(attempt.value), 50)
              : (attempt.error || 'not found');
            sb.push(`| \`${attempt.path}\` | ${resultIcon} | ${valueOrError} |`);
          }
        }

        if (hasFailedAccess) {
          sb.push('', '> ⚠️ 이 컨텍스트에서 접근 실패가 발생했습니다.');
        }

        sb.push('', '---', '');
      }

      // 페이지네이션 정보 추가
      sb.pushPagination(pagination, '컨텍스트');
    }

    // 사용 팁
    sb.section('## 💡 Nested Context 디버깅 팁');
    sb.push(
      '### 컨텍스트 타입별 특징',
      '',
      '| 타입 | 설명 | 주요 컨텍스트 변수 |',
      '|------|------|-------------------|',
      '| `expandChildren` | DataGrid 행 확장 | `row`, `rowIndex` |',
      '| `cellChildren` | DataGrid 셀 커스텀 렌더링 | `cell`, `row`, `column` |',
      '| `iteration` | 반복 렌더링 | `item`, `index` (또는 `item_var`, `index_var`) |',
      '| `modal` | 모달 컴포넌트 | 부모 상태 + 모달 로컬 상태 |',
      '| `slot` | 슬롯/Partial 렌더링 | 부모에서 전달된 props |',
      '',
      '### 일반적인 문제와 해결책',
      '',
      '**1. iteration 내 변수 접근 실패**',
      '```json',
      '// ❌ 잘못된 사용',
      '"iteration": { "source": "{{items}}", "item": "item", "index": "index" }',
      '',
      '// ✅ 올바른 사용 (item_var, index_var)',
      '"iteration": { "source": "{{items}}", "item_var": "item", "index_var": "index" }',
      '```',
      '',
      '**2. cellChildren에서 row 데이터 접근**',
      '```json',
      '// cellChildren 내에서 현재 행 데이터 접근',
      '"text": "{{row.name}}"',
      '```',
      '',
      '**3. modal에서 부모 상태 접근**',
      '```json',
      '// 모달은 자체 _local 상태를 가지므로 부모 _local 접근 불가',
      '// _global 상태는 접근 가능',
      '"text": "{{_global.selectedItem.name}}"',
      '```',
      '',
      '### 필터 옵션',
      '- `type: "iteration"` - 특정 타입만 필터링',
      '- `showFailedOnly: true` - 실패한 접근 시도가 있는 컨텍스트만',
      '- `componentId: "DataGrid"` - 특정 컴포넌트 필터링',
      '- `showValues: true` - 컨텍스트 값 상세 표시',
      '',
      '### 페이지네이션',
      '- `offset: 0, limit: 20` - 처음 20개 조회 (기본값)',
      '- `offset: 20, limit: 20` - 21~40번째 항목 조회',
    );

    return {
      content: sb.build(),
    };
  }
);

/**
 * Nested Context 타입별 아이콘
 */
function getNestedContextTypeIcon(type?: string): string {
  switch (type) {
    case 'expandChildren': return '📂';
    case 'cellChildren': return '📊';
    case 'iteration': return '🔄';
    case 'modal': return '🪟';
    case 'slot': return '🧩';
    default: return '📦';
  }
}

// ============================================
// Tool: g7-computed - Computed 의존성 추적
// ============================================
server.tool(
  'g7-computed',
  'Computed 속성의 의존성 추적 정보를 조회합니다. 재계산 트리거, 의존성 체인, 순환 의존성 감지 등을 분석합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('표시할 최대 속성/로그 개수 (기본: 20)'),
    name: z.string().optional().describe('특정 computed 속성 이름으로 필터링'),
    showCycles: z.boolean().optional().describe('순환 의존성이 있는 것만 표시 (기본: false)'),
    showUnnecessary: z.boolean().optional().describe('불필요한 재계산만 표시 (기본: false)'),
    trigger: z.string().optional().describe('트리거 유형 필터 (state-change, datasource-update, dependency-change, manual, initial)'),
    showProperties: z.boolean().optional().describe('속성 목록 표시 (기본: true). false로 설정하면 통계만 표시'),
    showLogs: z.boolean().optional().describe('재계산 로그 표시 (기본: true). false로 설정하면 속성만 표시'),
    search: z.string().optional().describe('텍스트 검색 (속성명, 의존성 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, limit = 20, name, showCycles = false, showUnnecessary = false, trigger, showProperties = true, showLogs = true, search }) => {
    // Computed 의존성 추적 데이터 로드
    const computedData = readDebugFile('computed-dependency-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const computedTracking = computedData || stateDumpData?.computedDependencyTracking;

    if (!computedTracking) {
      return {
        content: [{ type: 'text', text: '# 🧮 Computed 의존성 추적\n\n⚠️ Computed 의존성 추적 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('computed-dependency-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { properties = [], recalcLogs = [], dependencyChains = [], stats = {} } = computedTracking;

    const sb = createSectionBuilder('# 🧮 Computed 의존성 추적', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `📦 총 Computed 속성: ${stats.totalComputed || 0}`,
      `🔄 총 재계산 횟수: ${stats.totalRecalculations || 0}`,
      `⚠️ 불필요한 재계산: ${stats.unnecessaryRecalculations || 0}`,
      `⏱️ 평균 계산 시간: ${(stats.avgComputationTime || 0).toFixed(2)}ms`,
      `🔁 순환 의존성: ${stats.cycleDetectionCount || 0}`,
      '',
    );

    // 트리거별 통계
    if (stats.byTrigger && Object.keys(stats.byTrigger).length > 0) {
      sb.push(
        '### 트리거별 재계산',
        '',
        '| 트리거 | 횟수 |',
        '|--------|-----:|',
      );
      for (const [triggerType, count] of Object.entries(stats.byTrigger)) {
        const icon = getComputedTriggerIcon(triggerType);
        sb.push(`| ${icon} \`${triggerType}\` | ${count} |`);
      }
      sb.push('');
    }

    // 자주 재계산되는 속성
    if (stats.topRecalculated && stats.topRecalculated.length > 0) {
      sb.push(
        '### 🔥 자주 재계산되는 속성',
        '',
        '| 속성 | 재계산 횟수 |',
        '|------|------------:|',
      );
      for (const item of stats.topRecalculated.slice(0, 5)) {
        sb.push(`| \`${item.name}\` | ${item.count} |`);
      }
      sb.push('');
    }

    // 느린 계산
    if (stats.slowestComputed && stats.slowestComputed.length > 0) {
      sb.push(
        '### 🐢 느린 계산',
        '',
        '| 속성 | 평균 시간 |',
        '|------|----------:|',
      );
      for (const item of stats.slowestComputed.slice(0, 5)) {
        sb.push(`| \`${item.name}\` | ${item.avgTime.toFixed(2)}ms |`);
      }
      sb.push('');
    }

    // 순환 의존성 경고
    const cycleChains = dependencyChains.filter((c: any) => c.hasCycle);
    if (cycleChains.length > 0) {
      sb.section('## 🚨 순환 의존성 감지');
      for (const chain of cycleChains) {
        sb.push(
          `### ⚠️ \`${chain.root}\``,
          '',
          `순환 경로: \`${chain.cyclePath?.join(' → ')}\``,
          '',
        );
      }
    }

    // Computed 속성 목록
    let filteredProperties = properties;
    if (name) {
      filteredProperties = filteredProperties.filter((p: any) =>
        p.name?.includes(name)
      );
    }
    if (showCycles) {
      const cycleRoots = new Set(cycleChains.map((c: any) => c.root));
      filteredProperties = filteredProperties.filter((p: any) =>
        cycleRoots.has(p.name)
      );
    }
    filteredProperties = applySearch(filteredProperties, search);

    // 속성 목록에 페이지네이션 적용
    if (showProperties) {
      sb.section('## 📋 Computed 속성 목록');

      if (filteredProperties.length === 0) {
        sb.push('표시할 Computed 속성이 없습니다.');
      } else {
        // 페이지네이션 적용
        const { items: paginatedProps, pagination: propsPagination } = applyPagination(
          filteredProperties,
          offset,
          limit
        );

        for (const prop of paginatedProps) {
          const chain = dependencyChains.find((c: any) => c.root === prop.name);
          const hasCycle = chain?.hasCycle;

          sb.push(
            `### ${hasCycle ? '🔁' : '✅'} \`${prop.name}\``,
            '',
            `- **ID**: \`${prop.id}\``,
          );
          if (prop.componentId) {
            sb.push(`- **컴포넌트**: \`${prop.componentId}\``);
          }
          sb.push(
            `- **표현식**: \`${truncateString(prop.expression, 80)}\``,
            `- **마지막 계산**: ${new Date(prop.lastComputedAt).toLocaleTimeString()}`,
            `- **계산 시간**: ${prop.computationTime}ms`,
          );

          if (prop.dependencies && prop.dependencies.length > 0) {
            sb.push('', '**의존성**:');
            for (const dep of prop.dependencies.slice(0, 10)) {
              const depIcon = getComputedDependencyIcon(dep.type);
              sb.push(`- ${depIcon} \`${dep.path}\` (${dep.type})`);
            }
            if (prop.dependencies.length > 10) {
              sb.push(`- ... 외 ${prop.dependencies.length - 10}개`);
            }
          }

          if (prop.error) {
            sb.push('', `> ❌ **에러**: ${prop.error}`);
          }

          sb.push('', '---', '');
        }

        // 페이지네이션 정보 추가
        sb.pushPagination(propsPagination, 'Computed 속성');
      }
    }

    // 재계산 로그
    let filteredLogs = recalcLogs;
    if (name) {
      filteredLogs = filteredLogs.filter((l: any) => l.computedName?.includes(name));
    }
    if (showUnnecessary) {
      filteredLogs = filteredLogs.filter((l: any) => !l.valueChanged);
    }
    if (trigger) {
      filteredLogs = filteredLogs.filter((l: any) => l.trigger === trigger);
    }

    // 재계산 로그에 페이지네이션 적용
    const { items: paginatedLogs, pagination: logsPagination } = applyPagination(
      filteredLogs.reverse(), // 최신 순
      offset,
      limit
    );

    if (showLogs && paginatedLogs.length > 0) {
      sb.section('## 🔄 재계산 로그');

      for (const log of paginatedLogs) {
        const triggerIcon = getComputedTriggerIcon(log.trigger);
        const valueIcon = log.valueChanged ? '✅' : '⚠️';

        sb.push(
          `### ${triggerIcon} \`${log.computedName}\``,
          '',
          `- **트리거**: ${log.trigger}`,
          `- **값 변경**: ${valueIcon} ${log.valueChanged ? '예' : '아니오 (불필요한 재계산)'}`,
          `- **계산 시간**: ${log.computationTime}ms`,
        );

        if (log.triggeredBy) {
          sb.push(
            '',
            '**트리거 원인**:',
            `- 유형: \`${log.triggeredBy.type}\``,
            `- 경로: \`${log.triggeredBy.path}\``,
          );
        }

        sb.push('');
      }

      // 재계산 로그 페이지네이션 정보
      sb.pushPagination(logsPagination, '재계산 로그');
    }

    // 사용 팁
    sb.section('## 💡 Computed 최적화 팁');
    sb.push(
      '### 불필요한 재계산 방지',
      '',
      '**1. 의존성 최소화**',
      '```json',
      '// ❌ 전체 객체 의존',
      '"computed": { "total": "{{items.length}}" }',
      '',
      '// ✅ 필요한 속성만 의존',
      '"computed": { "total": "{{itemCount}}" }',
      '```',
      '',
      '**2. 메모이제이션 활용**',
      '- 복잡한 계산은 캐시 활용',
      '- 동일 입력 → 동일 출력 보장',
      '',
      '**3. 순환 의존성 해결**',
      '- computed A → B → A 패턴 피하기',
      '- 중간 상태 도입으로 의존성 끊기',
      '',
      '### 필터 옵션',
      '- `name: "total"` - 특정 속성만 조회',
      '- `showCycles: true` - 순환 의존성만 표시',
      '- `showUnnecessary: true` - 불필요한 재계산만 표시',
      '- `trigger: "state-change"` - 특정 트리거만 필터링',
      '',
      '### 페이지네이션',
      '- `offset: 0, limit: 20` - 처음 20개 조회 (기본값)',
      '- `offset: 20, limit: 20` - 21~40번째 항목 조회',
      '- `showProperties: false` - 속성 목록 숨기기 (통계만 표시)',
      '- `showLogs: false` - 재계산 로그 숨기기',
    );

    return {
      content: sb.build(),
    };
  }
);

/**
 * Computed 트리거 아이콘
 */
function getComputedTriggerIcon(trigger?: string): string {
  switch (trigger) {
    case 'state-change': return '📝';
    case 'datasource-update': return '📊';
    case 'dependency-change': return '🔗';
    case 'manual': return '✋';
    case 'initial': return '🚀';
    default: return '❓';
  }
}

/**
 * Computed 의존성 타입 아이콘
 */
function getComputedDependencyIcon(type?: string): string {
  switch (type) {
    case 'state': return '📝';
    case 'datasource': return '📊';
    case 'computed': return '🧮';
    case 'expression': return '📐';
    default: return '❓';
  }
}

// ============================================
// Tool: g7-modal-state - 모달 상태 스코프 추적
// ============================================
server.tool(
  'g7-modal-state',
  '모달의 상태 스코프 추적 정보를 조회합니다. 상태 격리, 유출 감지, 중첩 모달 관계 등을 분석합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    modalId: z.string().optional().describe('특정 모달 ID로 필터링'),
    showOpen: z.boolean().optional().describe('열려있는 모달만 표시 (기본: false)'),
    showIssues: z.boolean().optional().describe('이슈가 있는 모달만 표시 (기본: false)'),
    issueType: z.string().optional().describe('이슈 타입 필터 (state-leakage, isolation-violation, parent-mutation, orphaned-state, scope-mismatch, cleanup-failure)'),
    limit: z.number().optional().describe('표시할 최대 모달/로그 개수 (기본: 20)'),
    search: z.string().optional().describe('텍스트 검색 (모달 ID, 상태 등 항목 내 문자열 매칭)'),
  },
  async ({ offset = 0, modalId, showOpen = false, showIssues = false, issueType, limit = 20, search }) => {
    // 모달 상태 스코프 추적 데이터 로드
    const modalData = readDebugFile('modal-state-scope-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const modalTracking = modalData || stateDumpData?.modalStateScopeTracking;

    if (!modalTracking) {
      return {
        content: [{ type: 'text', text: '# 🪟 모달 상태 스코프 추적\n\n⚠️ 모달 상태 스코프 추적 데이터가 없습니다.\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('modal-state-scope-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { modals = [], issues = [], relations = [], changeLogs = [], stats = {} } = modalTracking;

    const sb = createSectionBuilder('# 🪟 모달 상태 스코프 추적', timeAgo);

    // 통계 표시
    sb.section('## 📊 통계');
    sb.push(
      `🪟 총 모달 수: ${stats.totalModals || 0}`,
      `📭 열려있는 모달: ${stats.openModals || 0}`,
      `📚 중첩 모달: ${stats.nestedModals || 0}`,
      `⚠️ 총 이슈: ${stats.totalIssues || 0}`,
      `🚨 유출 감지: ${stats.leakageDetectionCount || 0}`,
      `🧹 정리 실패: ${stats.cleanupFailureCount || 0}`,
      '',
    );

    // 스코프 타입별 통계
    if (stats.byScope && Object.keys(stats.byScope).length > 0) {
      sb.push(
        '### 스코프 타입별 모달',
        '',
        '| 스코프 | 모달 수 |',
        '|--------|-------:|',
      );
      for (const [scopeType, count] of Object.entries(stats.byScope)) {
        const icon = getModalScopeIcon(scopeType);
        sb.push(`| ${icon} \`${scopeType}\` | ${count} |`);
      }
      sb.push('');
    }

    // 이슈 심각도별 통계
    if (stats.issuesBySeverity && (stats.issuesBySeverity.warning > 0 || stats.issuesBySeverity.error > 0)) {
      sb.push(
        '### 이슈 심각도별',
        '',
        '| 심각도 | 횟수 |',
        '|--------|-----:|',
        `| ⚠️ Warning | ${stats.issuesBySeverity.warning || 0} |`,
        `| ❌ Error | ${stats.issuesBySeverity.error || 0} |`,
        '',
      );
    }

    // 이슈 타입별 통계
    if (stats.issuesByType && Object.keys(stats.issuesByType).length > 0) {
      const hasIssues = Object.values(stats.issuesByType).some((v: any) => v > 0);
      if (hasIssues) {
        sb.push(
          '### 이슈 타입별',
          '',
          '| 타입 | 횟수 |',
          '|------|-----:|',
        );
        for (const [type, count] of Object.entries(stats.issuesByType)) {
          if ((count as number) > 0) {
            const icon = getModalIssueTypeIcon(type);
            sb.push(`| ${icon} \`${type}\` | ${count} |`);
          }
        }
        sb.push('');
      }
    }

    // 이슈 목록
    let filteredIssues = issues;
    if (modalId) {
      filteredIssues = filteredIssues.filter((i: any) => i.modalId === modalId);
    }
    if (issueType) {
      filteredIssues = filteredIssues.filter((i: any) => i.type === issueType);
    }
    filteredIssues = applySearch(filteredIssues, search);

    if (filteredIssues.length > 0) {
      sb.section('## 🚨 이슈 목록');

      for (const issue of filteredIssues.slice(-20).reverse()) {
        const severityIcon = issue.severity === 'error' ? '❌' : '⚠️';
        const typeIcon = getModalIssueTypeIcon(issue.type);

        sb.push(
          `### ${severityIcon} ${typeIcon} \`${issue.modalName}\``,
          '',
          `- **ID**: \`${issue.id}\``,
          `- **타입**: \`${issue.type}\``,
          `- **시간**: ${new Date(issue.timestamp).toLocaleTimeString()}`,
          `- **설명**: ${issue.description}`,
        );

        if (issue.affectedStateKeys && issue.affectedStateKeys.length > 0) {
          sb.push(`- **영향 받는 상태 키**: \`${issue.affectedStateKeys.join(', ')}\``);
        }

        if (issue.leakedValue !== undefined) {
          sb.push(`- **유출된 값**: \`${truncateString(JSON.stringify(issue.leakedValue), 50)}\``);
        }

        sb.push('');
      }
    }

    // 모달 목록
    let filteredModals = modals;
    if (modalId) {
      filteredModals = filteredModals.filter((m: any) => m.modalId === modalId);
    }
    if (showOpen) {
      filteredModals = filteredModals.filter((m: any) => m.closedAt === null);
    }
    if (showIssues) {
      const modalIdsWithIssues = new Set(issues.map((i: any) => i.modalId));
      filteredModals = filteredModals.filter((m: any) => modalIdsWithIssues.has(m.modalId));
    }

    sb.section('## 🪟 모달 목록');

    if (filteredModals.length === 0) {
      sb.push('표시할 모달이 없습니다.');
    } else {
      // 모달 목록에 페이지네이션 적용
      const { items: paginatedModals, pagination: modalsPagination } = applyPagination(
        filteredModals,
        offset,
        limit
      );

      for (const modal of paginatedModals) {
        const statusIcon = modal.closedAt === null ? '📭' : '📪';
        const scopeIcon = getModalScopeIcon(modal.scopeType);
        const hasModalIssues = issues.some((i: any) => i.modalId === modal.modalId);

        sb.push(
          `### ${statusIcon} ${hasModalIssues ? '⚠️' : '✅'} \`${modal.modalName}\``,
          '',
          `- **ID**: \`${modal.modalId}\``,
          `- **스코프**: ${scopeIcon} \`${modal.scopeType}\``,
          `- **상태**: ${modal.closedAt === null ? '열림' : '닫힘'}`,
          `- **열린 시간**: ${new Date(modal.openedAt).toLocaleTimeString()}`,
        );
        if (modal.closedAt) {
          sb.push(`- **닫힌 시간**: ${new Date(modal.closedAt).toLocaleTimeString()}`);
        }
        sb.push(`- **상태 변경 횟수**: ${modal.stateChangeCount}`);

        if (modal.parentModalId) {
          sb.push(`- **부모 모달**: \`${modal.parentModalId}\``);
        }

        if (modal.isolatedStateKeys && modal.isolatedStateKeys.length > 0) {
          sb.push(`- **격리된 상태 키**: \`${modal.isolatedStateKeys.slice(0, 5).join(', ')}${modal.isolatedStateKeys.length > 5 ? '...' : ''}\``);
        }

        if (modal.sharedStateKeys && modal.sharedStateKeys.length > 0) {
          sb.push(`- **공유된 상태 키**: \`${modal.sharedStateKeys.slice(0, 5).join(', ')}${modal.sharedStateKeys.length > 5 ? '...' : ''}\``);
        }

        sb.push('');
      }

      // 모달 목록 페이지네이션 정보
      sb.pushPagination(modalsPagination, '모달');
    }

    // 모달 간 관계
    if (relations.length > 0) {
      sb.section('## 🔗 모달 관계');

      for (const relation of relations) {
        const relationIcon = relation.relationType === 'parent-child' ? '👨‍👦' : relation.relationType === 'sibling' ? '👫' : '🔲';

        sb.push(
          `### ${relationIcon} ${relation.relationType}`,
          '',
          `- **부모**: \`${relation.parentModalId}\``,
          `- **자식**: \`${relation.childModalId}\``,
        );

        if (relation.sharedKeys && relation.sharedKeys.length > 0) {
          sb.push(`- **공유 키**: \`${relation.sharedKeys.join(', ')}\``);
        }
        if (relation.isolatedKeys && relation.isolatedKeys.length > 0) {
          sb.push(`- **격리 키**: \`${relation.isolatedKeys.join(', ')}\``);
        }

        sb.push('');
      }
    }

    // 상태 변경 로그
    let filteredLogs = changeLogs;
    if (modalId) {
      filteredLogs = filteredLogs.filter((l: any) => l.modalId === modalId);
    }

    // 상태 변경 로그에 페이지네이션 적용
    const { items: paginatedLogs, pagination: logsPagination } = applyPagination(
      filteredLogs.reverse(), // 최신 순
      offset,
      limit
    );

    if (paginatedLogs.length > 0) {
      sb.section('## 📝 상태 변경 로그');

      for (const log of paginatedLogs) {
        const sourceIcon = getModalChangeSourceIcon(log.changeSource);
        const violationIcon = log.violatesIsolation ? '⚠️' : '';

        sb.push(
          `### ${sourceIcon} ${violationIcon} \`${log.modalName}\` → \`${log.stateKey}\``,
          '',
          `- **시간**: ${new Date(log.timestamp).toLocaleTimeString()}`,
          `- **변경 원인**: \`${log.changeSource}\``,
          `- **이전 값**: \`${truncateString(JSON.stringify(log.previousValue), 40)}\``,
          `- **새 값**: \`${truncateString(JSON.stringify(log.newValue), 40)}\``,
        );

        if (log.violatesIsolation) {
          sb.push('', '> ⚠️ **격리 위반**: 격리된 상태가 변경되었습니다.');
        }

        sb.push('');
      }

      // 상태 변경 로그 페이지네이션 정보
      sb.pushPagination(logsPagination, '상태 변경 로그');
    }

    // 사용 팁
    sb.section('## 💡 모달 상태 관리 팁');
    sb.push(
      '### 상태 유출 방지',
      '',
      '**1. 모달별 상태 격리**',
      '```json',
      '// ✅ 모달 전용 상태 사용',
      '"state": { "modalForm": {} }',
      '',
      '// ❌ 전역 상태 직접 수정',
      '"actions": [{ "handler": "setState", "params": { "path": "_global.data" }}]',
      '```',
      '',
      '**2. 모달 닫기 전 상태 정리**',
      '```json',
      '"actions": [',
      '  { "handler": "setState", "params": { "path": "modalForm", "value": null }},',
      '  { "handler": "closeModal" }',
      ']',
      '```',
      '',
      '**3. 중첩 모달 시 부모 상태 보존**',
      '- 자식 모달에서 부모 상태 직접 변경 금지',
      '- onClose 콜백으로 결과 전달',
      '',
      '### 필터 옵션',
      '- `modalId: "modal-1"` - 특정 모달만 조회',
      '- `showOpen: true` - 열려있는 모달만 표시',
      '- `showIssues: true` - 이슈가 있는 모달만 표시',
      '- `issueType: "state-leakage"` - 특정 이슈 타입만 필터링',
      '',
      '### 페이지네이션',
      '- `offset: 0, limit: 20` - 처음 20개 조회 (기본값)',
      '- `offset: 20, limit: 20` - 21~40번째 항목 조회',
    );

    return {
      content: sb.build(),
    };
  }
);

// ============================================
// Tool: g7-named-actions - Named Actions 추적
// ============================================
server.tool(
  'g7-named-actions',
  'named_actions 정의 목록, actionRef 해석 이력, 사용 통계를 조회합니다. 레이아웃에서 정의한 named_actions와 컴포넌트의 actionRef 참조 관계를 분석합니다.',
  {
    offset: z.number().optional().describe('시작 위치 (기본: 0). 페이지네이션에 사용'),
    limit: z.number().optional().describe('표시할 최대 항목 개수 (기본: 20)'),
    name: z.string().optional().describe('특정 named action 이름으로 필터링'),
    search: z.string().optional().describe('텍스트 검색 (액션 이름, 핸들러 등 문자열 매칭)'),
    mode: z.enum(['definitions', 'refs', 'stats']).optional().describe('조회 모드: definitions(정의 목록), refs(참조 이력), stats(통계). 기본: stats'),
  },
  async ({ offset = 0, limit = 20, name, search, mode = 'stats' }) => {
    const namedActionData = readDebugFile('named-action-tracking-latest.json');
    const stateDumpData = readDebugFile('state-dump-latest.json');
    const trackingInfo = namedActionData || stateDumpData?.namedActionTracking;

    if (!trackingInfo) {
      return {
        content: [{ type: 'text', text: '# 🏷️ Named Actions 추적\n\n⚠️ Named Actions 추적 데이터가 없습니다.\n\n**가능한 원인:**\n- 현재 레이아웃에 `named_actions`가 정의되지 않았습니다\n- 브라우저 상태 덤프가 아직 수행되지 않았습니다\n' + DUMP_INSTRUCTION }],
        isError: true,
      };
    }

    const timestamp = getFileTimestamp('named-action-tracking-latest.json') || getFileTimestamp('state-dump-latest.json');
    const timeAgo = timestamp ? Math.round((Date.now() - timestamp) / 1000) : null;

    const { definitions = {}, refLogs = [], stats = {} } = trackingInfo;

    // ─── 통계 모드 (기본) ───
    if (mode === 'stats') {
      const sb = createSectionBuilder('# 🏷️ Named Actions 추적', timeAgo);

      sb.section('## 📊 Named Actions 통계');
      sb.push(
        `| 항목 | 값 |`,
        `|------|-----|`,
        `| 정의 수 | ${stats.totalDefinitions ?? Object.keys(definitions).length} |`,
        `| 총 참조 수 | ${stats.totalRefs ?? refLogs.length} |`,
        `| 미사용 정의 | ${(stats.unusedDefinitions ?? []).length} |`,
        '',
      );

      // 정의별 참조 횟수
      const refCountByName = stats.refCountByName ?? {};
      const defNames = Object.keys(definitions);
      if (defNames.length > 0) {
        sb.section('## 📋 정의별 참조 현황');
        sb.push(
          '| Named Action | Handler | 참조 횟수 | 상태 |',
          '|-------------|---------|----------|------|',
        );
        for (const defName of defNames) {
          if (name && defName !== name) continue;
          if (search && !defName.toLowerCase().includes(search.toLowerCase()) &&
              !definitions[defName]?.handler?.toLowerCase().includes(search.toLowerCase())) continue;
          const def = definitions[defName];
          const refCount = refCountByName[defName] ?? 0;
          const status = refCount === 0 ? '⚠️ 미사용' : '✅ 사용중';
          sb.push(`| \`${defName}\` | ${def?.handler ?? 'N/A'} | ${refCount}회 | ${status} |`);
        }
        sb.push('');
      }

      // 미사용 정의 경고
      const unusedDefs = stats.unusedDefinitions ?? [];
      if (unusedDefs.length > 0) {
        sb.section('## ⚠️ 미사용 Named Actions');
        sb.push('다음 named_actions가 정의되어 있지만 아직 참조되지 않았습니다:', '');
        for (const unused of unusedDefs) {
          sb.push(`- \`${unused}\` → ${definitions[unused]?.handler ?? 'N/A'}`);
        }
        sb.push('');
      }

      return {
        content: sb.build(),
      };
    }

    // ─── 정의 목록 모드 ───
    if (mode === 'definitions') {
      const sb = createSectionBuilder('# 🏷️ Named Actions 추적', timeAgo);

      sb.section('## 📋 Named Actions 정의 목록');

      let defEntries = Object.entries(definitions);

      // 필터링
      if (name) {
        defEntries = defEntries.filter(([n]) => n === name);
      }
      if (search) {
        const s = search.toLowerCase();
        defEntries = defEntries.filter(([n, def]: [string, any]) =>
          n.toLowerCase().includes(s) || def?.handler?.toLowerCase().includes(s)
        );
      }

      if (defEntries.length === 0) {
        sb.push('검색 결과가 없습니다.');
        return {
          content: sb.build(),
        };
      }

      const { items: paginated, pagination } = applyPagination(defEntries, offset, limit);

      for (const [defName, def] of paginated as [string, any][]) {
        const refCount = (stats.refCountByName ?? {})[defName] ?? 0;
        sb.push(
          `### \`${defName}\``,
          '',
          `- **Handler**: \`${def?.handler ?? 'N/A'}\``,
          `- **참조 횟수**: ${refCount}회`,
        );
        if (def?.params) {
          sb.push('- **Params**:');
          sb.pushJson(def.params);
        }
        sb.push('');
      }

      sb.pushPagination(pagination, '정의');

      return {
        content: sb.build(),
      };
    }

    // ─── 참조 이력 모드 ───
    if (mode === 'refs') {
      const sb = createSectionBuilder('# 🏷️ Named Actions 추적', timeAgo);

      sb.section('## 📜 actionRef 해석 이력');

      let filteredLogs = [...refLogs];

      // 필터링
      if (name) {
        filteredLogs = filteredLogs.filter((log: any) => log.actionRefName === name);
      }
      if (search) {
        const s = search.toLowerCase();
        filteredLogs = filteredLogs.filter((log: any) =>
          log.actionRefName?.toLowerCase().includes(s) ||
          log.resolvedHandler?.toLowerCase().includes(s)
        );
      }

      // 최신순 정렬
      filteredLogs.reverse();

      if (filteredLogs.length === 0) {
        sb.push('actionRef 해석 이력이 없습니다.');
        return {
          content: sb.build(),
        };
      }

      const { items: paginated, pagination } = applyPagination(filteredLogs, offset, limit);

      sb.push(
        '| # | actionRef | 해석된 Handler | 시각 |',
        '|---|-----------|---------------|------|',
      );
      for (let i = 0; i < paginated.length; i++) {
        const log = paginated[i] as any;
        const time = log.timestamp ? new Date(log.timestamp).toLocaleTimeString() : 'N/A';
        sb.push(`| ${offset + i + 1} | \`${log.actionRefName}\` | ${log.resolvedHandler} | ${time} |`);
      }
      sb.push('');

      sb.pushPagination(pagination, '이력');

      return {
        content: sb.build(),
      };
    }

    // fallback for unknown mode
    const sb = createSectionBuilder('# 🏷️ Named Actions 추적', timeAgo);
    sb.push(
      `⚠️ 알 수 없는 모드: ${mode}`,
      '사용 가능한 모드: definitions, refs, stats',
    );

    return {
      content: sb.build(),
    };
  }
);

/**
 * 모달 스코프 타입 아이콘
 */
function getModalScopeIcon(scopeType?: string): string {
  switch (scopeType) {
    case 'isolated': return '🔒';
    case 'shared': return '🔓';
    case 'inherited': return '📥';
    default: return '❓';
  }
}

/**
 * 모달 이슈 타입 아이콘
 */
function getModalIssueTypeIcon(type?: string): string {
  switch (type) {
    case 'state-leakage': return '💧';
    case 'isolation-violation': return '🔓';
    case 'parent-mutation': return '👆';
    case 'orphaned-state': return '👻';
    case 'scope-mismatch': return '🔀';
    case 'cleanup-failure': return '🧹';
    default: return '❓';
  }
}

/**
 * 모달 상태 변경 원인 아이콘
 */
function getModalChangeSourceIcon(source?: string): string {
  switch (source) {
    case 'user-action': return '👆';
    case 'api-response': return '📡';
    case 'parent-sync': return '🔄';
    case 'init': return '🚀';
    case 'cleanup': return '🧹';
    default: return '❓';
  }
}

/**
 * 종료 사유별 아이콘
 */
function getExitReasonIcon(reason?: string): string {
  switch (reason) {
    case 'normal': return '✅';
    case 'early-return-condition': return '⏎';
    case 'early-return-validation': return '❌';
    case 'error': return '💥';
    default: return '❓';
  }
}

/**
 * 알림 유형별 아이콘
 */
function getAlertTypeIcon(type: string): string {
  switch (type) {
    case 'no-state-change': return '⚠️';
    case 'no-datasource-change': return '📊';
    case 'expected-not-fulfilled': return '❗';
    case 'object-reference-same': return '🔄';
    case 'early-return-detected': return '⏎';
    case 'async-timing-issue': return '⏱️';
    default: return '📢';
  }
}

// 서버 시작
async function main() {
  logger.info(`G7 DevTools MCP 서버 시작 (프로젝트: ${projectRoot})`);
  logger.info(`디버그 디렉토리: ${debugDir}`);

  const transport = new StdioServerTransport();
  await server.connect(transport);

  logger.info('MCP 서버가 stdio 모드로 실행 중입니다.');
}

main().catch((error) => {
  logger.error(`MCP 서버 시작 실패: ${error.message}`);
  process.exit(1);
});
