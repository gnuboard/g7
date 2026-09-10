# 그누보드7 DevTools MCP 서버 소스

이 디렉터리는 [DevTools MCP 서버](../devtools/README.md)의 구현과 의존성만 담는다.

## 구성

```
docs/ai-tools/agents/
├── src/
│   ├── mcp/
│   │   └── g7-devtools-server.ts   # MCP 서버 본체 (storage/debug-dump 읽기)
│   └── utils/
│       └── logger.ts               # stderr 전용 최소 로거
├── package.json                    # @modelcontextprotocol/sdk · zod · tsx
└── tsconfig.json
```

## 설치·실행

```bash
cd docs/ai-tools/agents
npm install
npm run mcp:devtools   # 수동 실행 (보통은 AI 코딩 도구의 MCP 설정이 자동으로 띄운다)
```

환경 변수: `G7_PROJECT_ROOT`(프로젝트 루트, 기본 `.`) · `LOG_LEVEL`(debug|info|warn|error) · `MCP_STDIO=1`(stdio 전송).

도구 목록·사용법·트러블슈팅은 [devtools/README.md](../devtools/README.md)를 본다.

## 이력

- 2026-09-10: 멀티에이전트 오케스트레이터(g7-agents MCP 서버)와 그 의존성을 제거했다. 이 디렉터리는 DevTools MCP 서버만 담는다.

## 라이선스

MIT License
