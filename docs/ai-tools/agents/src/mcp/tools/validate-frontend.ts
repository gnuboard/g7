/**
 * 프론트엔드/레이아웃 검증 도구
 * TSX 컴포넌트 및 레이아웃 JSON 규정 검증
 */
import * as fs from 'fs';
import * as path from 'path';
import type { ValidationResult, ValidationIssue } from '../../types/index.js';

export function validateFrontendCode(
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

  if (filePath.endsWith('.json')) {
    validateLayoutJson(filePath, content, issues);
  } else if (filePath.endsWith('.tsx')) {
    validateTsxComponent(filePath, content, issues);
  }

  return {
    valid: issues.filter(i => i.severity === 'error').length === 0,
    issues,
  };
}

function validateLayoutJson(
  filePath: string,
  content: string,
  issues: ValidationIssue[]
): void {
  let json: any;

  try {
    json = JSON.parse(content);
  } catch (error) {
    issues.push({
      file: filePath,
      rule: 'valid-json',
      message: 'JSON 파싱 오류',
      severity: 'error',
    });
    return;
  }

  // 필수 필드 검사
  const requiredFields = ['version', 'layout_name', 'components'];
  for (const field of requiredFields) {
    if (!(field in json)) {
      issues.push({
        file: filePath,
        rule: 'required-fields',
        message: `필수 필드 누락: ${field}`,
        severity: 'error',
      });
    }
  }

  // 컴포넌트 검증
  if (Array.isArray(json.components)) {
    validateComponents(filePath, json.components, issues);
  }
}

function validateComponents(
  filePath: string,
  components: any[],
  issues: ValidationIssue[],
  parentPath = 'components'
): void {
  for (let i = 0; i < components.length; i++) {
    const comp = components[i];
    const compPath = `${parentPath}[${i}]`;

    // HTML 태그명 검사
    if (comp.name) {
      const htmlTags = ['div', 'span', 'button', 'input', 'form', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'ul', 'li', 'table', 'tr', 'td', 'th', 'thead', 'tbody', 'section', 'nav', 'img'];
      if (htmlTags.includes(comp.name.toLowerCase())) {
        issues.push({
          file: filePath,
          rule: 'no-html-tags',
          message: `HTML 태그 직접 사용 금지: "${comp.name}" (${compPath}). 기본 컴포넌트(${comp.name.charAt(0).toUpperCase() + comp.name.slice(1)})를 사용하세요.`,
          severity: 'error',
        });
      }
    }

    // props.children 사용 검사
    if (comp.props?.children && typeof comp.props.children === 'string') {
      issues.push({
        file: filePath,
        rule: 'no-props-children',
        message: `props.children 사용 금지 (${compPath}). "text" 속성을 사용하세요.`,
        severity: 'error',
      });
    }

    // 하드코딩 텍스트 검사 (한글 포함)
    if (comp.text && typeof comp.text === 'string') {
      const koreanPattern = /[가-힣]/;
      if (koreanPattern.test(comp.text) && !comp.text.startsWith('$t:') && !comp.text.includes('{{')) {
        issues.push({
          file: filePath,
          rule: 'i18n-text',
          message: `하드코딩된 텍스트 (${compPath}): "${comp.text}". $t: 접두사를 사용하세요.`,
          severity: 'warning',
        });
      }
    }

    // 다크 모드 클래스 검사
    if (comp.props?.className) {
      const className = comp.props.className;
      validateDarkModeClasses(filePath, className, compPath, issues);
    }

    // children 재귀 검증
    if (Array.isArray(comp.children)) {
      validateComponents(filePath, comp.children, issues, `${compPath}.children`);
    }
  }
}

function validateTsxComponent(
  filePath: string,
  content: string,
  issues: ValidationIssue[]
): void {
  const lines = content.split('\n');

  // HTML 태그 직접 사용 검사
  const htmlTagPatterns = [
    { pattern: /<div\s|<div>/, tag: 'div', replacement: 'Div' },
    { pattern: /<span\s|<span>/, tag: 'span', replacement: 'Span' },
    { pattern: /<button\s|<button>/, tag: 'button', replacement: 'Button' },
    { pattern: /<input\s|<input>|<input\/>/, tag: 'input', replacement: 'Input' },
    { pattern: /<form\s|<form>/, tag: 'form', replacement: 'Form' },
    { pattern: /<p\s|<p>/, tag: 'p', replacement: 'P' },
    { pattern: /<h1\s|<h1>/, tag: 'h1', replacement: 'H1' },
    { pattern: /<h2\s|<h2>/, tag: 'h2', replacement: 'H2' },
    { pattern: /<h3\s|<h3>/, tag: 'h3', replacement: 'H3' },
    { pattern: /<a\s|<a>/, tag: 'a', replacement: 'A' },
  ];

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 1;

    // 주석 무시
    if (line.trim().startsWith('//') || line.trim().startsWith('/*')) {
      continue;
    }

    for (const { pattern, tag, replacement } of htmlTagPatterns) {
      if (pattern.test(line)) {
        issues.push({
          file: filePath,
          line: lineNum,
          rule: 'no-html-tags',
          message: `HTML 태그 직접 사용 금지: <${tag}>. ${replacement} 컴포넌트를 사용하세요.`,
          severity: 'error',
        });
      }
    }

    // 다크 모드 클래스 검사
    const classNameMatch = line.match(/className=["'`]([^"'`]+)["'`]/);
    if (classNameMatch) {
      validateDarkModeClasses(filePath, classNameMatch[1], `line ${lineNum}`, issues);
    }
  }

  // 다국어 처리 검사
  if (!content.includes('G7Core') && !content.includes('window.G7Core')) {
    // 하드코딩된 한글 텍스트 검사
    const koreanPattern = />([가-힣\s]+)</g;
    let match;
    while ((match = koreanPattern.exec(content)) !== null) {
      // JSX 내의 한글 텍스트
      if (!match[1].trim()) continue;
      issues.push({
        file: filePath,
        rule: 'i18n-text',
        message: `하드코딩된 텍스트: "${match[1]}". G7Core.t()를 사용하세요.`,
        severity: 'warning',
      });
    }
  }

  // Font Awesome 외 아이콘 라이브러리 검사
  const iconLibraries = [
    '@heroicons/react',
    '@mui/icons-material',
    'react-icons',
    'lucide-react',
  ];

  for (const lib of iconLibraries) {
    if (content.includes(lib)) {
      issues.push({
        file: filePath,
        rule: 'icon-library',
        message: `허용되지 않은 아이콘 라이브러리: ${lib}. Font Awesome 6.4.0만 사용하세요.`,
        severity: 'error',
      });
    }
  }
}

function validateDarkModeClasses(
  filePath: string,
  className: string,
  location: string,
  issues: ValidationIssue[]
): void {
  // 배경색 검사
  const bgLightPatterns = ['bg-white', 'bg-gray-50', 'bg-gray-100'];
  const bgDarkPatterns = ['dark:bg-gray-800', 'dark:bg-gray-700', 'dark:bg-gray-900'];

  for (const lightClass of bgLightPatterns) {
    if (className.includes(lightClass)) {
      const hasDarkVariant = bgDarkPatterns.some(dc => className.includes(dc));
      if (!hasDarkVariant) {
        issues.push({
          file: filePath,
          rule: 'dark-mode-pair',
          message: `다크 모드 클래스 누락 (${location}): "${lightClass}"에 대한 dark: variant가 필요합니다.`,
          severity: 'warning',
        });
      }
    }
  }

  // 텍스트 색상 검사
  if (className.includes('text-gray-900') && !className.includes('dark:text-')) {
    issues.push({
      file: filePath,
      rule: 'dark-mode-pair',
      message: `다크 모드 클래스 누락 (${location}): "text-gray-900"에 대한 dark:text- variant가 필요합니다.`,
      severity: 'warning',
    });
  }

  // 테두리 색상 검사
  if (className.includes('border-gray-200') && !className.includes('dark:border-')) {
    issues.push({
      file: filePath,
      rule: 'dark-mode-pair',
      message: `다크 모드 클래스 누락 (${location}): "border-gray-200"에 대한 dark:border- variant가 필요합니다.`,
      severity: 'warning',
    });
  }
}
