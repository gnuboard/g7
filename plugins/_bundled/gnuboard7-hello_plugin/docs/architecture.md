# Hello 플러그인 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"플러그인은 무엇을 하는가" 에 대한 답을 **가장 짧게** 보이는 것이 목표입니다. 플러그인의 핵심은
**다른 확장의 코드를 고치지 않고 그 동작에 끼어드는 것**이며, 그 방식이 둘(Action·Filter)
이므로 리스너도 둘입니다.

거기에 두 가지를 덧붙였습니다:

- **부가 동작은 설정으로 끌 수 있어야 한다** — `log_enabled` 가 그 본보기입니다. 리스너가
  무조건 동작하면 그 확장을 설치한 사이트는 멈출 방법이 없습니다.
- **구독한 확장이 다시 발행할 수 있다** — `log.written` 이 그 예입니다. 훅은 한 번 받고 끝나는
  것이 아니라 연쇄를 이룹니다.

**플러그인의 경계**도 구조로 드러납니다. 완전한 페이지 레이아웃을 등록할 수 없고, 설정 화면
(`plugin_settings.json`)과 `layout_extensions`(다른 화면에 끼워 넣는 조각)만 허용됩니다. 이
샘플에는 설정 화면 하나가 있고 `layout_extensions` 는 없습니다.

**의도적으로 하지 않는 것**: 모델·테이블·마이그레이션·API 라우트·권한·메뉴. 플러그인이 자기
데이터를 가질 수는 있지만(실제 플러그인들이 그렇습니다), 이 샘플은 훅만 보이면 되므로 두지
않았습니다. `manifest.hidden = true` 로 관리자 UI 목록에서 제외되며 CLI 로는 정상 동작합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
plugin.php                       진입 클래스 — 설정 스키마 · 발행 훅 선언 · 리스너 등록
     │
     ├─ Listeners/LogMemoCreatedListener      Action 구독
     │      gnuboard7-hello_module.memo.created 를 받아
     │      설정(log_enabled) 확인 → 로그 기록 → log.written 발행
     │
     └─ Listeners/FilterMemoTitleListener     Filter 구독 ('type' => 'filter')
            gnuboard7-hello_module.memo.title.filter 의 값을 가공해 반환

config/settings/defaults.json    설정 기본값
resources/layouts/admin/plugin_settings.json   설정 화면 (파일 이름이 계약)
src/routes/web.php               web 라우트 — 플러그인도 라우트를 가질 수 있음을 보이는 예시
resources/lang/{ko,en}.json      프론트 다국어 (백엔드 PHP 다국어는 없음)
```

**계층이 얕은 것이 정상**입니다. 플러그인은 자기 도메인을 갖지 않고 남의 흐름에 붙으므로,
Controller → Service → Repository → Model 사슬이 필요 없습니다. 실제 플러그인 중 자기 데이터를
갖는 것들(결제·GDPR 등)은 그 사슬을 갖지만, 그것은 플러그인의 필수 구조가 아니라 그 도메인의
필요입니다.

`plugin_settings.json` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면 자체가 사라집니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update gnuboard7-hello_plugin --force` (빌드 불필요) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
