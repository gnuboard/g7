/**
 * G7 MCP 도구 서버
 * AI Agent SDK와 통합되는 커스텀 MCP 서버
 */
import { createSdkMcpServer, tool } from '@anthropic-ai/claude-agent-sdk';
import { z } from 'zod';
import { validateBackendCode } from './tools/validate-code.js';
import { validateFrontendCode } from './tools/validate-frontend.js';
import { runTests, runTestFile } from './tools/run-tests.js';
import { searchDocs, readDoc, listDocs } from './tools/search-docs.js';

/**
 * G7 프로젝트 전용 MCP 서버 생성
 */
export function createG7McpServer(projectRoot: string) {
  return createSdkMcpServer({
    name: 'g7-tools',
    version: '1.0.0',
    tools: [
      // 백엔드 코드 검증
      tool(
        'validate-code',
        'Service, FormRequest, Repository, Listener, Controller 파일의 G7 규정 준수 여부를 검증합니다',
        {
          filePath: z.string().describe('검증할 파일 경로 (프로젝트 루트 기준 상대 경로)'),
        },
        async ({ filePath }) => {
          const result = validateBackendCode(projectRoot, filePath);

          return {
            content: [{
              type: 'text',
              text: formatValidationResult(result),
            }],
          };
        }
      ),

      // 프론트엔드/레이아웃 검증
      tool(
        'validate-frontend',
        '레이아웃 JSON 또는 TSX 컴포넌트의 G7 규정 준수 여부를 검증합니다',
        {
          filePath: z.string().describe('검증할 파일 경로 (프로젝트 루트 기준 상대 경로)'),
        },
        async ({ filePath }) => {
          const result = validateFrontendCode(projectRoot, filePath);

          return {
            content: [{
              type: 'text',
              text: formatValidationResult(result),
            }],
          };
        }
      ),

      // 테스트 실행
      tool(
        'run-tests',
        '백엔드(PHPUnit) 또는 프론트엔드(Vitest) 테스트를 실행합니다',
        {
          type: z.enum(['backend', 'frontend', 'all']).describe('테스트 타입'),
          filter: z.string().optional().describe('테스트 필터 (클래스명 또는 테스트 이름)'),
          template: z.string().optional().describe('프론트엔드 테스트 시 템플릿 이름'),
        },
        async ({ type, filter, template }) => {
          const result = runTests(projectRoot, { type, filter, template });

          return {
            content: [{
              type: 'text',
              text: result.success
                ? `✅ ${result.message}\n\n${result.data}`
                : `❌ ${result.message}\n\n${result.data}`,
            }],
            isError: !result.success,
          };
        }
      ),

      // 특정 테스트 파일 실행
      tool(
        'run-test-file',
        '특정 테스트 파일을 실행합니다',
        {
          testFilePath: z.string().describe('테스트 파일 경로'),
        },
        async ({ testFilePath }) => {
          const result = runTestFile(projectRoot, testFilePath);

          return {
            content: [{
              type: 'text',
              text: result.success
                ? `✅ ${result.message}\n\n${result.data}`
                : `❌ ${result.message}\n\n${result.data}`,
            }],
            isError: !result.success,
          };
        }
      ),

      // 규정 문서 검색
      tool(
        'search-docs',
        'G7 규정 문서(docs/)에서 관련 정보를 검색합니다',
        {
          query: z.string().describe('검색 키워드'),
          category: z.enum(['backend', 'frontend', 'extension', 'database', 'testing', 'all'])
            .optional()
            .describe('검색할 카테고리'),
          maxResults: z.number().optional().describe('최대 결과 수 (기본: 5)'),
        },
        async ({ query, category, maxResults }) => {
          const result = searchDocs(projectRoot, { query, category, maxResults });

          if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
            return {
              content: [{
                type: 'text',
                text: '검색 결과가 없습니다',
              }],
            };
          }

          const formatted = result.data.map((doc: any) =>
            `## ${doc.file}\n**${doc.title}**\n\`\`\`\n${doc.snippet}\n\`\`\``
          ).join('\n\n');

          return {
            content: [{
              type: 'text',
              text: `${result.message}\n\n${formatted}`,
            }],
          };
        }
      ),

      // 규정 문서 읽기
      tool(
        'read-doc',
        '특정 G7 규정 문서의 전체 내용을 읽습니다',
        {
          docPath: z.string().describe('문서 경로 (예: backend/service-repository.md)'),
        },
        async ({ docPath }) => {
          const result = readDoc(projectRoot, docPath);

          return {
            content: [{
              type: 'text',
              text: result.success
                ? result.data as string
                : `❌ ${result.message}`,
            }],
            isError: !result.success,
          };
        }
      ),

      // 규정 문서 목록
      tool(
        'list-docs',
        'G7 규정 문서 목록을 조회합니다',
        {
          category: z.enum(['backend', 'frontend', 'extension', 'database', 'testing', 'all'])
            .optional()
            .describe('조회할 카테고리'),
        },
        async ({ category }) => {
          const result = listDocs(projectRoot, category);

          if (!result.success || !Array.isArray(result.data)) {
            return {
              content: [{
                type: 'text',
                text: '문서 목록을 조회할 수 없습니다',
              }],
            };
          }

          const formatted = result.data.map((doc: any) =>
            `- ${doc.path}: ${doc.title}`
          ).join('\n');

          return {
            content: [{
              type: 'text',
              text: `${result.message}\n\n${formatted}`,
            }],
          };
        }
      ),
    ],
  });
}

/**
 * 검증 결과 포맷팅
 */
function formatValidationResult(result: { valid: boolean; issues: any[] }): string {
  if (result.valid && result.issues.length === 0) {
    return '✅ 검증 통과: G7 규정을 준수합니다.';
  }

  const errors = result.issues.filter(i => i.severity === 'error');
  const warnings = result.issues.filter(i => i.severity === 'warning');

  let output = result.valid
    ? '⚠️ 검증 통과 (경고 있음)\n\n'
    : '❌ 검증 실패\n\n';

  if (errors.length > 0) {
    output += '### 오류\n';
    for (const issue of errors) {
      output += `- [${issue.rule}] ${issue.file}`;
      if (issue.line) output += `:${issue.line}`;
      output += `\n  ${issue.message}\n`;
    }
    output += '\n';
  }

  if (warnings.length > 0) {
    output += '### 경고\n';
    for (const issue of warnings) {
      output += `- [${issue.rule}] ${issue.file}`;
      if (issue.line) output += `:${issue.line}`;
      output += `\n  ${issue.message}\n`;
    }
  }

  return output;
}
