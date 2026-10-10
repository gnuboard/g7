# 수출입은행 환율 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
환율 수신·환산·영속화를 분리합니다. API 클라이언트는 전송 오류와 응답 상태만 분류하고, 계산기는 양수 유효 환율로 교차환산합니다. UpdateService가 실행 잠금·호출 예산·이력·알림을 조율하여 실패 시 기존 환율이 유지되게 합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
Controller와 Command는 같은 실행 서비스를 사용합니다. 서비스의 이력 접근은 RateRunRepositoryInterface를 경유하며, 이커머스 설정 변경은 EcommerceSettingsService의 선택 제공자 가드를 통과합니다. 화면은 상태/이력 API와 코어 플러그인 설정/알림 API를 조합합니다.

환율 저장은 이커머스 서비스의 인스턴스·config 미러·통화 설정 및 변환 캐시 무효화를 재사용합니다. 환율은 SEO 메타 설정 키가 아니므로 SEO 설정 저장 훅을 발행하지 않습니다. 신규 플러그인 1.0.0은 최초 마이그레이션만 필요하며, 모듈 1.3.0 변경에는 기존 스키마나 데이터 변환이 없어 업그레이드 스텝을 추가하지 않습니다. 플러그인 커맨드는 확장이 등록하고, 코어 관리자 개발 대시보드의 커맨드 목록 대상은 아닙니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-koreaexim --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-koreaexim --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-koreaexim --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-koreaexim --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
