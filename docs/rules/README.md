# 절대 금지 패턴 (CRITICAL RULES)

## TL;DR (5초 요약)

```text
1. 표의 왼쪽은 금지, 오른쪽이 올바른 사용 — 자동 검증이 대부분을 차단한다
2. 프론트 액션·바인딩·상태 → frontend-actions-binding.md
3. 라우팅·인증·보안 경계 → routing-security.md · 목록·검색 → list-context.md
4. 확장 에셋·문서·캐시 → extension-assets.md · 편집기 위젯 → editor-widgets.md
5. 백엔드 일반·DB·Listener → backend-misc.md
```

2026-09-10 까지 루트 AGENTS.md 한 파일(≈100 KB)에 있던 48개 규칙을 영역별로 나눴다. 제목과 표는 그대로이며 앵커도 바뀌지 않았다.

| 문서 | 영역 |
|---|---|
| [frontend-actions-binding.md](frontend-actions-binding.md) | 프론트엔드 액션·바인딩·상태 규칙 |
| [editor-widgets.md](editor-widgets.md) | 레이아웃 편집기·위젯 규칙 |
| [routing-security.md](routing-security.md) | 라우팅·인증·보안 경계 규칙 |
| [list-context.md](list-context.md) | 목록·검색·페이지 규칙 |
| [extension-assets.md](extension-assets.md) | 확장 에셋·문서·캐시·의존성 규칙 |
| [backend-misc.md](backend-misc.md) | 백엔드 일반 규칙 |
