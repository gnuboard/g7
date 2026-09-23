# 그누보드7 GDPR (일반 데이터 보호 규정) 플러그인 개발자 문서

> plugins/_bundled/sirsoft-gdpr · 플러그인

<!-- @generated:stats START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**훅 수**: 4 · **구독 훅 수**: 8 · **라우트 수**: 19 · **모델 수**: 4 · **테이블 수**: 4 · **마이그레이션 수**: 5 · **레이아웃 수**: 9 · **핸들러 수**: 1
<!-- @generated:stats END -->

## 문서 목차

<!-- @generated:doc-toc START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 |
|---|---|
| [architecture.md](architecture.md) | 설계 의도·계층 지도·디렉토리 맵 |
| [extension-points.md](extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 |
| [data-model.md](data-model.md) | 모델·소유 테이블·마이그레이션·Enum |
| [settings.md](settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 |
| [frontend.md](frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 |
| [editor-spec.md](editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 |
| [api/](api/README.md) | API 레퍼런스 |
| [../AGENTS.md](../AGENTS.md) | 에이전트·확장개발자 진입점 |
| [../README.md](../README.md) | 사람(도입검토자·운영자) 진입점 |
<!-- @generated:doc-toc END -->

### 기능 문서

| 문서 | 내용 |
|---|---|
| [annual-notice.md](annual-notice.md) | 개인정보 연간 통지 — 설정·스케줄·대상 선정·접수/결과 이력·시험 발송·`core.privacy.notice_sections` 소비·운영 한계 |
| [api/annual-notice.md](api/annual-notice.md) · [api/notice-log.md](api/notice-log.md) | 시험 발송·통지 이력 API |
