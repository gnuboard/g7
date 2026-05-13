# 그누보드7 DevTools MCP 서버

그누보드7 템플릿 엔진의 런타임 디버깅 정보를 MCP 지원 AI 코딩 도구에서 조회할 수 있는 [MCP(Model Context Protocol)](https://modelcontextprotocol.io/) 서버입니다.

## 아키텍처

```
┌─────────────────────┐     HTTP POST      ┌─────────────────────┐
│   브라우저 (프론트)    │  ─────────────▶   │   Laravel 백엔드     │
│   G7DevToolsCore     │   상태 덤프        │   /_boost/g7-debug   │
│   (코어 빌드에 포함)   │                   │   → storage/debug-dump/   │
└─────────────────────┘                    └──────────┬──────────┘
                                                      │ 파일 저장
                                                      ▼
┌─────────────────────┐    파일 읽기       ┌─────────────────────┐
│   AI 코딩 도구        │  ◀─────────────   │   MCP 서버           │
│   (AI 코딩 도구)      │   MCP 프로토콜    │   g7-devtools-server │
└─────────────────────┘                    └─────────────────────┘
```

**브라우저 측 (G7DevToolsCore)**: 코어 템플릿 엔진에 내장된 디버깅 수집기. 디버그 모드 활성화 시 상태, 액션, 캐시, 네트워크 등 30여 개 카테고리의 런타임 정보를 수집하여 서버로 전송합니다.

**MCP 서버 (g7-devtools-server)**: 서버에 저장된 디버그 데이터를 읽어 AI 도구에게 구조화된 형태로 제공합니다.

---

## 전제 조건

- **Node.js** 18 이상
- **그누보드7 프로젝트**가 설치되어 있고, 개발 서버(`php artisan serve` 등)가 실행 중
- **Laravel Boost** (`laravel/boost`) — `require-dev`에 포함되어 개발 환경에서 자동 설치됨
- **AI 코딩 도구** — Cursor, VS Code Copilot 등 MCP를 지원하는 도구

---

## 설치 및 설정

### Step 1. 의존성 설치

```bash
cd docs/ai-tools/agents
npm install
```

### Step 2. MCP 서버 등록

사용하는 AI 도구에 따라 등록 방법이 다릅니다.

#### MCP 지원 AI 도구 (CLI 기반)

프로젝트 루트에 `.mcp.json` 파일을 생성합니다:

```json
{
  "mcpServers": {
    "g7-devtools": {
      "type": "stdio",
      "command": "npx",
      "args": [
        "tsx",
        "docs/ai-tools/agents/src/mcp/g7-devtools-server.ts"
      ]
    }
  }
}
```

#### VS Code Copilot / Cursor

`.vscode/mcp.json` 파일에 등록합니다:

```json
{
  "servers": {
    "g7-devtools": {
      "command": "npx",
      "args": [
        "tsx",
        "docs/ai-tools/agents/src/mcp/g7-devtools-server.ts"
      ]
    }
  }
}
```

> **Windows 환경**: `npx`가 동작하지 않으면 `docs/ai-tools/agents/node_modules/.bin/tsx.cmd`를 command로 직접 지정하세요.

### Step 3. 환경 변수 설정

`docs/ai-tools/agents/.env` 파일을 생성하여 프로젝트 루트 경로를 설정합니다:

```env
# 그누보드7 프로젝트 루트 경로
G7_PROJECT_ROOT=/path/to/your/g7/project
```

> 이 설정을 생략하면 MCP 서버가 올바른 디버그 디렉토리를 찾지 못할 수 있으므로 반드시 설정하세요.

### Step 4. 활성화 확인

AI 도구를 리로드(VS Code: `Ctrl+Shift+P` → `Developer: Reload Window`)한 후, MCP 서버 목록에서 `g7-devtools`가 연결됨(Connected) 상태인지 확인합니다.

---

## 디버그 모드 활성화

그누보드7 DevTools가 데이터를 수집하려면 **디버그 모드**가 활성화되어 있어야 합니다.

| 방법 | 설정 | 비고 |
|------|------|------|
| `.env` 파일 | `APP_DEBUG=true` | 개발 환경 기본 설정 |
| 환경설정 UI | 관리자 > 고급 설정 > 디버그 모드 | 런타임 토글 가능 |

> **주의**: 프로덕션 환경에서는 반드시 디버그 모드를 비활성화하세요. 디버그 데이터에 민감한 정보가 포함될 수 있습니다.

---

## 사용 방법

### 1. 상태 덤프 (브라우저 → 서버)

디버그 모드가 활성화된 상태에서 브라우저 개발자 도구(F12) 콘솔에서:

```javascript
// 현재 상태를 서버로 덤프
window.__G7_DEVTOOLS__.connector.dumpState();
```

또는 그누보드7 DevTools 패널(우측 하단 플로팅 버튼)에서 "상태 덤프" 버튼을 클릭합니다.

덤프된 데이터는 `storage/debug-dump/` 디렉토리에 카테고리별 JSON 파일로 저장됩니다:

```
storage/debug-dump/
├── state-latest.json              # 상태 스냅샷
├── actions-latest.json            # 액션 이력
├── cache-latest.json              # 캐시 통계
├── network-latest.json            # 네트워크 요청
├── expressions-latest.json        # 표현식 평가
├── lifecycle-latest.json          # 컴포넌트 생명주기
├── form-latest.json               # 폼 바인딩
├── conditionals-latest.json       # 조건부/반복 렌더링
├── datasources-latest.json        # 데이터소스 구조
├── handlers-latest.json           # 등록된 핸들러
├── component-events-latest.json   # 컴포넌트 이벤트
├── performance-latest.json        # 렌더링 성능
├── state-rendering-latest.json    # 상태-렌더링 추적
├── state-hierarchy-latest.json    # 상태 계층
├── context-flow-latest.json       # 컨텍스트 전파
├── style-validation-latest.json   # 스타일 이슈
├── auth-debug-latest.json         # 인증 상태
├── layout-latest.json             # 레이아웃 JSON
├── computed-dependency-latest.json # Computed 의존성
├── nested-context-latest.json     # 중첩 컨텍스트
├── modal-state-scope-latest.json  # 모달 상태 스코프
├── sequence-latest.json           # Sequence 실행
├── stale-closure-latest.json      # Stale Closure 감지
├── change-detection-latest.json   # 변경 감지
├── logs-latest.json               # Logger 로그
└── sessions/                      # 세션별 이력
```

### 2. AI 도구에서 조회

MCP 서버가 등록되어 있으면, AI 코딩 도구에서 자연어로 요청하거나 도구를 직접 호출할 수 있습니다.

**자연어 요청 예시**:
- "현재 _global 상태 확인해줘"
- "에러가 난 액션이 있는지 확인해"
- "네트워크 요청 중 실패한 것 있어?"
- "폼 바인딩 상태 보여줘"

---

## MCP 도구 레퍼런스

### 기본 디버깅 도구

| 도구 | 설명 | 주요 파라미터 |
|------|------|-------------|
| `g7-state` | 현재 상태 조회 (`_global`, `_local`, `_computed`) | `path` (경로), `search` (검색) |
| `g7-actions` | 액션 실행 이력 | `filter` (핸들러 타입), `showParams`, `search` |
| `g7-cache` | 캐시 통계 (Hit/Miss 비율) | - |
| `g7-diagnose` | 증상 기반 자동 진단 | `symptoms` (증상 배열, 필수) |
| `g7-lifecycle` | 컴포넌트 생명주기 (마운트/언마운트) | - |
| `g7-network` | API 요청 이력 및 데이터소스 상태 | `status` (success/error), `showResponse`, `search` |
| `g7-form` | Form/Input 바인딩 정보 | - |
| `g7-expressions` | 표현식 평가 이력 및 경고 | - |
| `g7-logs` | Logger 로그 이력 | `search` |

### 고급 분석 도구

| 도구 | 설명 | 주요 파라미터 |
|------|------|-------------|
| `g7-datasources` | 데이터소스 정의 및 구조, 경로 변환 추적 | `search` |
| `g7-handlers` | 등록된 액션 핸들러 목록 | `search` |
| `g7-events` | 컴포넌트 이벤트 구독/발행 이력 | - |
| `g7-performance` | 렌더링 성능, 메모리 경고 | - |
| `g7-conditionals` | 조건부(`if`)/반복(`iteration`) 렌더링 평가 정보 | - |
| `g7-websocket` | WebSocket 연결 상태 및 메시지 | - |
| `g7-named-actions` | named_actions 정의 및 참조 현황 | `search` |

### 상태 계층 및 스타일 도구

| 도구 | 설명 | 주요 파라미터 |
|------|------|-------------|
| `g7-renders` | setState 호출 → 렌더링 상관관계 | - |
| `g7-state-hierarchy` | 상태 계층 및 우선순위 시각화, 충돌 감지 | `conflictsOnly`, `path`, `showValues` |
| `g7-context-flow` | componentContext 전파 추적 | - |
| `g7-styles` | CSS 스타일 이슈 감지 (보이지 않는 요소, 다크모드 누락) | - |
| `g7-auth` | 인증 상태 및 토큰 정보 | - |
| `g7-tailwind` | Tailwind CSS 빌드/퍼지 검증 | - |
| `g7-layout` | 현재 렌더링 중인 레이아웃 JSON | - |

### 상태 추적 심화 도구

| 도구 | 설명 | 주요 파라미터 |
|------|------|-------------|
| `g7-computed` | Computed 속성 의존성 추적, 순환 감지 | - |
| `g7-nested-context` | 중첩 컨텍스트 전파 (expandChildren, iteration, modal, slot) | - |
| `g7-modal-state` | 모달 스코프 상태 추적 (격리, 유출 감지, 중첩 모달) | - |
| `g7-sequence` | Sequence 핸들러 실행 추적 (각 액션별 상태 변화) | - |
| `g7-stale-closure` | Stale Closure 감지 및 경고 | - |
| `g7-change-detection` | 핸들러 실행 시 변경 감지 (상태 변경 없는 핸들러, early return) | - |

### 페이지네이션

모든 도구는 `offset`과 `limit` 파라미터를 지원합니다:

```
g7-logs limit=20 offset=0     # 첫 페이지 (1~20)
g7-logs limit=20 offset=20    # 두 번째 페이지 (21~40)
g7-actions limit=10 offset=30 # 31~40번째 항목
```

출력 예시:
```
📄 페이지네이션: 21~40 / 총 249개 로그
➡️ 다음 페이지: offset: 40, limit: 20
⬅️ 이전 페이지: offset: 0, limit: 20
```

---

## 디버깅 워크플로우

### 기본 흐름

```
1. 디버그 모드 활성화 (APP_DEBUG=true)
2. 브라우저에서 문제가 발생하는 페이지 열기
3. 문제 재현 후, 개발자 도구 콘솔에서 상태 덤프 실행
4. AI 도구에서 g7-diagnose 호출 (증상 목록 전달)
5. 진단 결과에 따라 세부 도구로 심층 분석
```

### 증상별 도구 선택 가이드

| 증상 | 1차 도구 | 2차 도구 |
|------|---------|---------|
| 상태가 반영되지 않음 | `g7-state` | `g7-state-hierarchy`, `g7-stale-closure` |
| 액션 실행 후 변화 없음 | `g7-actions` | `g7-change-detection`, `g7-handlers` |
| API 데이터 미표시 | `g7-network` | `g7-datasources`, `g7-expressions` |
| 폼 값이 전송되지 않음 | `g7-form` | `g7-state`, `g7-actions` |
| 조건부 렌더링 오류 | `g7-conditionals` | `g7-expressions`, `g7-computed` |
| 모달 내 상태 이상 | `g7-modal-state` | `g7-nested-context`, `g7-state-hierarchy` |
| 스타일/CSS 문제 | `g7-styles` | `g7-tailwind` |
| 렌더링 성능 저하 | `g7-performance` | `g7-renders`, `g7-computed` |
| 이전 값이 남아있음 | `g7-stale-closure` | `g7-state`, `g7-change-detection` |
| 인증/401 오류 | `g7-auth` | `g7-network` |
| Sequence 중간 실패 | `g7-sequence` | `g7-actions` |

---

## 프로젝트 내 관련 파일

```
docs/ai-tools/agents/
└── src/mcp/
    └── g7-devtools-server.ts       # MCP 서버 (도구 정의 및 데이터 파싱)

resources/js/core/devtools/         # 브라우저 측 DevTools 코어
├── G7DevToolsCore.ts               # 싱글톤 디버깅 수집기
├── ServerConnector.ts              # 서버 전송 (상태 덤프)
├── DiagnosticEngine.ts             # 자동 진단 엔진
├── StyleTracker.ts                 # 스타일 이슈 추적
├── TailwindValidator.ts            # Tailwind CSS 검증
├── types.ts                        # 타입 정의
├── index.ts                        # 엔트리포인트
└── ui/                             # DevTools 패널 UI (React)

routes/devtools.php                 # 상태 덤프 API 엔드포인트
storage/debug-dump/                      # 디버그 데이터 저장 (자동 생성, Git 제외)
```

---

## 트러블슈팅

### MCP 서버가 시작되지 않는 경우

1. `docs/ai-tools/agents/node_modules/`가 존재하는지 확인 → `cd docs/ai-tools/agents && npm install`
2. Node.js 18 이상인지 확인 → `node --version`
3. AI 도구를 리로드했는지 확인 (VS Code: `Ctrl+Shift+P` → `Developer: Reload Window`)

### Windows에서 `npx` 실행 오류

Windows 환경에서 AI 도구가 `npx`를 찾지 못하는 경우:

```json
{
  "g7-devtools": {
    "command": "docs/ai-tools/agents/node_modules/.bin/tsx.cmd",
    "args": [
      "docs/ai-tools/agents/src/mcp/g7-devtools-server.ts"
    ]
  }
}
```

### 상태 덤프가 실패하는 경우

1. **디버그 모드 확인**: `.env`에서 `APP_DEBUG=true`인지 확인
2. **서버 실행 확인**: 개발 서버(`php artisan serve` 등)가 실행 중인지 확인
3. **콘솔 로그 확인**: 브라우저 콘솔에서 `[G7DevTools]` 로그로 오류 원인 파악
4. **엔드포인트 확인**: `/_boost/g7-debug/dump-state`에 POST 요청이 정상 도달하는지 확인

### 도구 호출 시 "데이터 없음"

1. **상태 덤프 선행 필수**: 브라우저에서 상태 덤프를 먼저 실행해야 합니다
2. **파일 존재 확인**: `storage/debug-dump/` 디렉토리에 `*-latest.json` 파일이 있는지 확인
3. **경로 확인**: `G7_PROJECT_ROOT` 환경 변수가 올바른 프로젝트 경로를 가리키는지 확인
4. **덤프 시간 확인**: 도구 출력의 "📅 덤프 시간" 표시가 너무 오래된 경우 재덤프 필요

### 대용량 데이터 조회 시 응답 잘림

- 모든 도구에서 `limit`과 `offset` 파라미터로 페이지네이션 사용
- `g7-state`는 `path` 파라미터로 특정 경로만 조회 (예: `path: "_global.user"`)
- `g7-actions`는 `filter`로 핸들러 타입 필터링 (예: `filter: "apiCall"`)
