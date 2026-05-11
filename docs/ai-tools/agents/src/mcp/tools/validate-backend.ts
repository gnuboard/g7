/**
 * 백엔드 코드 검증 도구
 * Service, FormRequest, Repository, Listener 규정 검증
 */
import * as fs from 'fs';
import * as path from 'path';
import type { ValidationResult, ValidationIssue } from '../../types/index.js';

export function validateBackendCode(
  projectRoot: string,
  filePath: string
): ValidationResult {
  const fullPath = path.join(projectRoot, filePath);
  const issues: ValidationIssue[] = [];

  if (!fs.existsSync(fullPath)) {
    return {
      valid: false,
      issues: [{
        file: filePath,
        rule: 'file-exists',
        message: '파일이 존재하지 않습니다',
        severity: 'error',
      }],
    };
  }

  const content = fs.readFileSync(fullPath, 'utf-8');
  const lines = content.split('\n');

  // Service 파일 검증
  if (filePath.includes('/Services/')) {
    validateService(filePath, content, lines, issues);
  }

  // Repository 파일 검증
  if (filePath.includes('/Repositories/')) {
    validateRepository(filePath, content, lines, issues);
  }

  // FormRequest 파일 검증
  if (filePath.includes('/Requests/')) {
    validateFormRequest(filePath, content, lines, issues);
  }

  // Controller 파일 검증
  if (filePath.includes('/Controllers/')) {
    validateController(filePath, content, lines, issues);
  }

  // Listener 파일 검증
  if (filePath.includes('/Listeners/')) {
    validateListener(filePath, content, lines, issues);
  }

  return {
    valid: issues.filter(i => i.severity === 'error').length === 0,
    issues,
  };
}

function validateService(
  filePath: string,
  content: string,
  lines: string[],
  issues: ValidationIssue[]
): void {
  // Repository 구체 클래스 직접 주입 검사
  const constructorMatch = content.match(/public function __construct\([^)]+\)/s);
  if (constructorMatch) {
    const constructorContent = constructorMatch[0];
    // Interface가 아닌 Repository 직접 주입 검사
    const repoPattern = /(\w+Repository)\s+\$/;
    const match = constructorContent.match(repoPattern);
    if (match && !match[1].includes('Interface')) {
      const lineNum = findLineNumber(lines, match[0]);
      issues.push({
        file: filePath,
        line: lineNum,
        rule: 'repository-interface',
        message: `Repository 구체 클래스 직접 주입 금지. ${match[1]}Interface를 사용하세요.`,
        severity: 'error',
      });
    }
  }

  // Service에서 Validator 사용 검사
  if (content.includes('Validator::') || content.includes('\\Validator')) {
    const lineNum = findLineNumber(lines, 'Validator');
    issues.push({
      file: filePath,
      line: lineNum,
      rule: 'no-validation-in-service',
      message: 'Service에서 검증 로직 금지. FormRequest를 사용하세요.',
      severity: 'error',
    });
  }

  // 훅 사용 검사 (create, update, delete 메서드에서)
  const methodPatterns = [
    /public function create\w*\([^)]*\)/,
    /public function update\w*\([^)]*\)/,
    /public function delete\w*\([^)]*\)/,
  ];

  for (const pattern of methodPatterns) {
    if (pattern.test(content)) {
      if (!content.includes('HookManager::doAction') && !content.includes('HookManager::applyFilters')) {
        issues.push({
          file: filePath,
          rule: 'hook-usage',
          message: 'Service의 CUD 메서드에서 훅 미사용. before_*/after_* 훅을 추가하세요.',
          severity: 'warning',
        });
        break;
      }
    }
  }

  // 다국어 처리 검사
  if (content.includes('throw new') && !content.includes('__(')) {
    const lineNum = findLineNumber(lines, 'throw new');
    issues.push({
      file: filePath,
      line: lineNum,
      rule: 'i18n-exception',
      message: '예외 메시지 하드코딩 금지. __() 함수를 사용하세요.',
      severity: 'warning',
    });
  }
}

function validateRepository(
  filePath: string,
  content: string,
  lines: string[],
  issues: ValidationIssue[]
): void {
  // Interface 구현 검사
  if (!content.includes('implements') || !content.includes('Interface')) {
    issues.push({
      file: filePath,
      rule: 'repository-implements-interface',
      message: 'Repository는 해당 Interface를 구현해야 합니다.',
      severity: 'warning',
    });
  }
}

function validateFormRequest(
  filePath: string,
  content: string,
  lines: string[],
  issues: ValidationIssue[]
): void {
  // authorize() 검사 - 권한 체크 금지
  const authorizeMatch = content.match(/public function authorize\(\)[^{]*\{([^}]+)\}/s);
  if (authorizeMatch) {
    const authorizeBody = authorizeMatch[1];
    if (authorizeBody.includes('->can(') || authorizeBody.includes('Gate::')) {
      const lineNum = findLineNumber(lines, 'authorize');
      issues.push({
        file: filePath,
        line: lineNum,
        rule: 'no-auth-in-form-request',
        message: 'FormRequest.authorize()에서 권한 체크 금지. 라우트 미들웨어를 사용하세요.',
        severity: 'warning',
      });
    }
  }

  // 다국어 메시지 검사
  if (content.includes('messages()') && !content.includes('__(')) {
    issues.push({
      file: filePath,
      rule: 'i18n-validation-messages',
      message: '검증 메시지 다국어 처리 필요. __() 함수를 사용하세요.',
      severity: 'warning',
    });
  }
}

function validateController(
  filePath: string,
  content: string,
  lines: string[],
  issues: ValidationIssue[]
): void {
  // Repository 직접 주입 검사
  if (content.includes('Repository $') && !content.includes('Service $')) {
    const lineNum = findLineNumber(lines, 'Repository $');
    issues.push({
      file: filePath,
      line: lineNum,
      rule: 'controller-uses-service',
      message: 'Controller에서 Repository 직접 주입 금지. Service를 주입하세요.',
      severity: 'error',
    });
  }

  // 인라인 검증 검사
  if (content.includes('$request->validate(') || content.includes('Validator::make')) {
    const lineNum = findLineNumber(lines, 'validate');
    issues.push({
      file: filePath,
      line: lineNum,
      rule: 'no-inline-validation',
      message: 'Controller에서 인라인 검증 금지. FormRequest를 사용하세요.',
      severity: 'error',
    });
  }

  // ResponseHelper 사용 검사
  if (content.includes('return response()->json') && !content.includes('ResponseHelper')) {
    const lineNum = findLineNumber(lines, 'response()->json');
    issues.push({
      file: filePath,
      line: lineNum,
      rule: 'use-response-helper',
      message: 'response()->json 대신 ResponseHelper를 사용하세요.',
      severity: 'warning',
    });
  }
}

function validateListener(
  filePath: string,
  content: string,
  lines: string[],
  issues: ValidationIssue[]
): void {
  // HookListenerInterface 구현 검사
  if (!content.includes('HookListenerInterface')) {
    issues.push({
      file: filePath,
      rule: 'listener-interface',
      message: 'Listener는 HookListenerInterface를 구현해야 합니다.',
      severity: 'error',
    });
  }

  // getSubscribedHooks 메서드 검사
  if (!content.includes('getSubscribedHooks')) {
    issues.push({
      file: filePath,
      rule: 'subscribed-hooks-method',
      message: 'getSubscribedHooks() 메서드가 필요합니다.',
      severity: 'error',
    });
  }

  // Filter 훅의 type 검사
  if (content.includes('applyFilters') || content.includes('filter_')) {
    if (!content.includes("'type' => 'filter'") && !content.includes('"type" => "filter"')) {
      issues.push({
        file: filePath,
        rule: 'filter-hook-type',
        message: "Filter 훅은 'type' => 'filter'를 명시해야 합니다.",
        severity: 'warning',
      });
    }
  }
}

function findLineNumber(lines: string[], searchText: string): number {
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].includes(searchText)) {
      return i + 1;
    }
  }
  return 0;
}
