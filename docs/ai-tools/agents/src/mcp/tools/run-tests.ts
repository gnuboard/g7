/**
 * 테스트 실행 도구
 * PHPUnit (백엔드) 및 Vitest (프론트엔드) 테스트 실행
 */
import { execSync } from 'child_process';
import type { ToolResult } from '../../types/index.js';

export type TestType = 'backend' | 'frontend' | 'all';

export interface TestOptions {
  type: TestType;
  filter?: string;
  template?: string;
}

export function runTests(
  projectRoot: string,
  options: TestOptions
): ToolResult {
  const { type, filter, template } = options;
  const results: string[] = [];
  let success = true;

  try {
    // 백엔드 테스트
    if (type === 'backend' || type === 'all') {
      const backendResult = runBackendTests(projectRoot, filter);
      results.push('=== Backend Tests ===');
      results.push(backendResult.output);
      if (!backendResult.success) {
        success = false;
      }
    }

    // 프론트엔드 테스트
    if (type === 'frontend' || type === 'all') {
      const frontendResult = runFrontendTests(projectRoot, filter, template);
      results.push('=== Frontend Tests ===');
      results.push(frontendResult.output);
      if (!frontendResult.success) {
        success = false;
      }
    }

    return {
      success,
      message: success ? '모든 테스트 통과' : '일부 테스트 실패',
      data: results.join('\n\n'),
    };
  } catch (error: any) {
    return {
      success: false,
      message: '테스트 실행 중 오류 발생',
      data: error.message,
    };
  }
}

function runBackendTests(
  projectRoot: string,
  filter?: string
): { success: boolean; output: string } {
  try {
    const cmd = filter
      ? `php artisan test --filter=${filter}`
      : 'php artisan test';

    const output = execSync(cmd, {
      cwd: projectRoot,
      encoding: 'utf-8',
      timeout: 300000, // 5분 타임아웃
    });

    // 테스트 결과 파싱
    const passMatch = output.match(/Tests:\s*(\d+)\s*passed/);
    const failMatch = output.match(/(\d+)\s*failed/);

    const passed = passMatch ? parseInt(passMatch[1]) : 0;
    const failed = failMatch ? parseInt(failMatch[1]) : 0;

    return {
      success: failed === 0,
      output: `Passed: ${passed}, Failed: ${failed}\n${output}`,
    };
  } catch (error: any) {
    return {
      success: false,
      output: error.stdout || error.message,
    };
  }
}

function runFrontendTests(
  projectRoot: string,
  filter?: string,
  template?: string
): { success: boolean; output: string } {
  try {
    let cwd = projectRoot;
    let cmd = 'npm run test:run';

    // 템플릿 지정 시 해당 디렉토리에서 실행
    if (template) {
      cwd = `${projectRoot}/templates/${template}`;
    }

    if (filter) {
      cmd += ` -- ${filter}`;
    }

    // Windows에서는 PowerShell 래퍼 필수
    const fullCmd = `powershell -Command "${cmd}"`;

    const output = execSync(fullCmd, {
      cwd,
      encoding: 'utf-8',
      timeout: 300000, // 5분 타임아웃
    });

    // Vitest 결과 파싱
    const passMatch = output.match(/(\d+)\s*passed/);
    const failMatch = output.match(/(\d+)\s*failed/);

    const passed = passMatch ? parseInt(passMatch[1]) : 0;
    const failed = failMatch ? parseInt(failMatch[1]) : 0;

    return {
      success: failed === 0,
      output: `Passed: ${passed}, Failed: ${failed}\n${output}`,
    };
  } catch (error: any) {
    return {
      success: false,
      output: error.stdout || error.message,
    };
  }
}

/**
 * 특정 테스트 파일 실행
 */
export function runTestFile(
  projectRoot: string,
  testFilePath: string
): ToolResult {
  const isPhp = testFilePath.endsWith('.php');
  const isTs = testFilePath.endsWith('.ts') || testFilePath.endsWith('.tsx');

  if (isPhp) {
    // PHPUnit 테스트
    const testClass = testFilePath
      .replace(/\//g, '\\')
      .replace('.php', '')
      .replace('tests\\', 'Tests\\');

    return runTests(projectRoot, {
      type: 'backend',
      filter: testClass,
    });
  } else if (isTs) {
    // Vitest 테스트
    const testName = testFilePath.split('/').pop()?.replace(/\.(test|spec)\.(ts|tsx)$/, '');

    return runTests(projectRoot, {
      type: 'frontend',
      filter: testName,
    });
  }

  return {
    success: false,
    message: '지원하지 않는 테스트 파일 형식입니다',
  };
}
