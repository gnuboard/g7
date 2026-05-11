# 그누보드7 Multi-Agent System

AI Agent SDK 기반 그누보드7 전용 멀티에이전트 협업 시스템입니다.

## 개요

5명의 전문 에이전트가 협업하여 그누보드7 프로젝트의 개발 작업을 수행합니다:

| 에이전트 | 역할 | 담당 영역 |
|---------|------|----------|
| **Coordinator** | 작업 분배 및 조율 | 전체 오케스트레이션 |
| **Backend** | 백엔드 개발 | Service, Repository, Controller, Migration |
| **Frontend** | 프론트엔드 개발 | TSX 컴포넌트, 상태 관리, G7Core API |
| **Layout** | 레이아웃 개발 | JSON 스키마, 데이터 바인딩, 반응형 |
| **Template** | 템플릿 개발 | 빌드, 컴포넌트 등록, 다국어 |
| **Reviewer** | 코드 검수 | 규정 준수, 테스트, 문서화 |

## 설치

```bash
cd docs/ai-tools/agents
npm install
```

## 환경 설정

`.env.example`을 `.env`로 복사하고 API 키를 설정하세요:

```bash
cp .env.example .env
```

```env
# AI API Key (Required)
ANTHROPIC_API_KEY=your_api_key_here

# 그누보드7 Project Root Path
G7_PROJECT_ROOT=/path/to/your/g7/project

# Log Level
LOG_LEVEL=info
```

## 사용법

### 인터랙티브 모드

```bash
npm run interactive
```

대화형으로 작업을 요청합니다:

```
🤖 > /feature 상품 할인 기능 추가
🤖 > /bugfix Form 저장 시 데이터 사라짐
🤖 > /review #123
🤖 > ProductService의 할인 로직 설명해줘
```

### 단일 명령어 모드

```bash
# 기능 개발
npm run agent -- feature "상품 할인 기능 추가"

# 버그 수정
npm run agent -- bugfix "Form 저장 시 데이터 사라짐"

# PR 검수
npm run agent -- pr-review "#123"

# 자유 형식
npm run agent -- "ProductService에 할인 로직 추가해줘"
```

## 워크플로우

### 1. 기능 개발 (Feature)

새로운 기능을 개발할 때 사용합니다:

```
Backend → Layout → Frontend → Template → Reviewer
```

1. **Backend**: 마이그레이션, Model, Repository, Service, Controller
2. **Layout**: 레이아웃 JSON, 데이터 소스, 컴포넌트 구조
3. **Frontend**: 필요한 TSX 컴포넌트 개발
4. **Template**: 컴포넌트 등록, 빌드
5. **Reviewer**: 전체 검수

### 2. 버그 수정 (Bugfix)

TDD 기반으로 버그를 수정합니다:

1. 트러블슈팅 가이드 확인
2. 버그 분석
3. **RED**: 버그 재현 테스트 작성
4. **GREEN**: 버그 수정
5. **REFACTOR**: 테스트 통과 확인
6. 검수

### 3. PR 검수 (PR Review)

변경된 파일 유형에 따라 적절한 에이전트가 검수합니다:

- `*.php` → Backend 에이전트
- `*.tsx` → Frontend 에이전트
- `*.json` (레이아웃) → Layout 에이전트
- 최종 → Reviewer 에이전트

## MCP 도구

그누보드7 프로젝트 전용 검증 도구를 제공합니다:

| 도구 | 설명 |
|------|------|
| `validate-backend` | 백엔드 코드 규정 검증 |
| `validate-frontend` | 프론트엔드/레이아웃 규정 검증 |
| `run-tests` | 테스트 실행 (PHPUnit, Vitest) |
| `search-docs` | 규정 문서 검색 |
| `read-doc` | 규정 문서 읽기 |
| `list-docs` | 규정 문서 목록 |

## 그누보드7 규정 준수

모든 에이전트는 `docs/`의 규정을 준수합니다:

### 백엔드
- Service-Repository 패턴 (RepositoryInterface 주입)
- FormRequest 검증 (Service 검증 금지)
- HookManager 훅 실행 (before_*/after_*)
- ResponseHelper 응답 처리
- 다국어 처리 (__() 함수)

### 프론트엔드
- HTML 태그 직접 사용 금지 (Div, Button 등 사용)
- 다크 모드 클래스 쌍 필수 (bg-white dark:bg-gray-800)
- G7Core.t() 다국어 처리
- Font Awesome 아이콘

### 레이아웃
- text 속성 사용 (props.children 금지)
- 데이터 바인딩 문법 ({{path}}, $t:key)
- 기본 컴포넌트만 사용

## 프로젝트 구조

```
docs/ai-tools/agents/
├── src/
│   ├── index.ts           # 메인 엔트리포인트
│   ├── cli.ts             # CLI 인터페이스
│   ├── coordinator/
│   │   └── Coordinator.ts # 코디네이터
│   ├── agents/
│   │   ├── definitions.ts # 에이전트 정의
│   │   └── index.ts
│   ├── prompts/           # 시스템 프롬프트
│   │   ├── coordinator.ts
│   │   ├── backend.ts
│   │   ├── frontend.ts
│   │   ├── layout.ts
│   │   ├── template.ts
│   │   └── reviewer.ts
│   ├── mcp/               # MCP 도구
│   │   ├── g7-tools-server.ts
│   │   └── tools/
│   ├── workflows/         # 워크플로우
│   │   ├── feature.ts
│   │   ├── bugfix.ts
│   │   └── pr-review.ts
│   ├── types/
│   │   └── index.ts
│   └── utils/
│       ├── logger.ts
│       └── context.ts
├── package.json
├── tsconfig.json
├── .env.example
└── README.md
```

## 라이선스

MIT License
