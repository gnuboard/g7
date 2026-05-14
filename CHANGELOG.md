# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [7.0.0-beta.6] - 2026-05-14

### Fixed

- 코어 업데이트 자동 롤백 시 신 버전이 추가한 신규 파일이 활성 디렉토리에 잔존하여 `BindingResolutionException` 등 부정합 부팅 실패가 발생하던 결함 수정. 백업 디렉토리에 신규 파일 manifest 를 기록하고 롤백 시 정확히 그 목록만 정리합니다. 사용자가 코어 영역에 직접 추가한 파일과 모든 symlink (`public/storage` 등) 는 보존됩니다. (#34 @bigmsg 님께서 제보해주셨습니다.)
- 자동 롤백 후 `bootstrap/cache` 잔존 PHP 캐시로 인한 추가 부팅 실패 차단 — 롤백 직후 캐시를 자동으로 정리합니다.
- beta.5 이전 자동 롤백으로 인해 활성 디렉토리에 잔존했을 수 있는 ServiceProvider 후보를 beta.6 업그레이드 스텝이 진단 로그로 식별하여 운영자 수동 검토를 안내합니다. 자동 삭제하지 않으므로 사용자가 직접 추가한 ServiceProvider 는 보존됩니다.
- 업그레이드 스텝 단독 실행 명령(`core:execute-upgrade-steps`) 이 마이그레이션·코어 재동기화·버전 갱신·캐시 정리·번들 확장 일괄 업데이트를 자동으로 함께 수행하도록 보강. 자동 업데이트 중단 후 안내된 수동 명령을 그대로 실행하면 별도 보조 작업 없이 업그레이드가 완료됩니다.
- 인스톨러 Step 3 (PHP CLI / Composer / 자동 감지 / 코어 _pending 경로 검증) 의 프론트엔드 catch 분기에서 사용하는 다국어 키가 언어 파일에 정의되어 있지 않아, 서버 응답 실패 시 화면에 다국어 키 문자열이 그대로 노출되던 결함 수정.
- 인스톨러 다국어 파일에 중복 정의된 키 4건 정리. POST 전용 API 의 메서드 거부 메시지가 일반 문구로 덮여 표시되던 문제, 데이터베이스 연결 실패 로그에서 placeholder 가 치환되지 않던 문제, HTTPS 카드 라벨이 안내 문장으로 표시되던 문제가 함께 해결됩니다.
- 인스톨러 Step 3 의 PHP CLI 및 Composer 절대경로 검증이 공유 호스팅 환경(시놀로지 DSM 등 `open_basedir` 가 시스템 binary 영역을 차단하는 환경)과 Windows 환경에서 정상 경로임에도 거부되던 결함 수정. 셸 인자 escape 와 메타문자 차단은 유지하고, 파일 시스템 권한에 의존하던 사전 검사를 실제 실행 결과로 판정하도록 변경합니다. Windows 의 백슬래시 경로 구분자도 정상 입력으로 인식됩니다 (단, `C:\Program Files\...` 같은 공백 포함 디렉토리는 공백 없는 경로 또는 8.3 short path 사용 권장). (#33 @glitter-gim 님께서 제보해주셨습니다.)
- 코어 운영 단계의 composer 바이너리 자동 인식이 같은 `open_basedir` 환경에서 실패하여 모듈/플러그인 설치 시 vendor-bundle 모드만 사용 가능하던 결함 수정. composer 가 정상 설치되어 PATH 에 존재하는 환경에서도 인식되도록 검출 로직을 개선했습니다.
- 멀티 PHP 버전 환경(시놀로지 DSM Web Station, cPanel/Plesk multi-PHP 등) 에서 Composer 를 특정 PHP 인터프리터로 실행하려는 "PHP 절대경로 + Composer 절대경로" 공백 분리 입력 형식이 거부되던 결함 수정. 입력을 두 토큰으로 분리한 뒤 각 토큰을 개별 escape 처리하여 안전을 유지하면서 멀티 PHP 운영 시나리오를 지원합니다.
- 인스톨러 세션 쿠키가 일부 환경(비표준 포트 + dynamic DNS 도메인 + 브라우저 추적 보호 등) 에서 브라우저에 의해 차단되어 첫 화면 "설치하기" 클릭이 동작하지 않고 새로고침처럼 보이던 결함을 보완. 세션 쿠키 SameSite 정책을 Strict → Lax 로 완화하여 차단 케이스 자체를 줄이고, Step 0 진입 시 쿠키 round-trip 사전 진단을 수행하여 차단이 감지되면 사용자에게 회피 방법(다른 브라우저 / 쿠키 차단 해제 / IP·localhost 접속) 을 명시 안내합니다.

### Added

- `hotfix:rollback-stale-files` Artisan 커맨드 추가 — 자동 롤백 후 활성 디렉토리에 잔존할 수 있는 신 파일을 운영자가 명시 실행하여 진단/정리할 수 있는 단발성 회복 도구. 기본은 진단 모드 (실제 삭제 없음), `--prune` 옵션 시 확인 프롬프트 후 정리. symlink 와 보호 경로(`storage/`, `vendor/`, `.env*` 등) 는 자동 제외.
- 본인인증 메시지 정의 목록 API 응답에 `can_create` 권한 키 추가 — 운영자의 수정 권한 보유 여부에 따라 관리자 화면이 "정의 추가" 버튼을 정확히 활성화/비활성화할 수 있도록 보강.

## [7.0.0-beta.5] - 2026-05-12

### Upgrade Notice

beta.4 → beta.5 업그레이드 시 beta.4 의 결함으로 인해 활성 확장 디렉토리 일부가 손실됩니다. (#34 @bigmsg 님께서 제보해주셨습니다.)

- 손실 항목 1: 운영자가 활성 디렉토리(`modules/{id}`, `plugins/{id}`, `templates/{id}`, `lang-packs/{id}`)에서 직접 수정한 코드
- 손실 항목 2: 외부 install 한 확장 (GitHub 등 `_bundled` 에 포함되지 않은 모듈/플러그인/템플릿/언어팩)

beta.5 의 자동 복구 스텝이 `_bundled` 기반으로 번들 확장은 복원하지만, 위 두 항목은 `_bundled` 에 없어 자동 복구가 불가능합니다.

업그레이드 전에 다음 디렉토리 전체를 별도 위치(외장 디스크, 별도 git 브랜치, tar.gz 파일)로 백업해두실 것을 권고합니다.

- `modules/`
- `plugins/`
- `templates/`
- `lang-packs/`

업그레이드 후 자동 복구된 활성 디렉토리에 본인의 수정 사항/외부 확장이 누락되어 있다면, 사전 백업에서 복원하거나 외부 확장은 원본 소스(GitHub 등)에서 재설치하세요. 실패한 외부 확장 식별자는 업그레이드 로그(`storage/logs/core_update_success_*.log`)에 명시됩니다.

#### 3가지 사용자군별 업그레이드 절차

- **사용자군 B — 정상 beta.4 사용자 (대다수)**: `php artisan core:update` 표준 절차. beta.5 의 자동 복구 스텝이 활성 확장 디렉토리 + lang-packs 정합성을 보장합니다.

- **사용자군 A — beta.3 → beta.4 업그레이드 도중 `Call to undefined method` 류 fatal 후 stuck 한 사용자**: `.env` `APP_VERSION` 을 변경하지 마시고 그대로 `php artisan core:update --force` 를 재실행하면 됩니다. 디스크는 이미 beta.4 이므로 새 자식 프로세스가 beta.5 메모리로 부팅되어 남은 업그레이드 스텝이 멱등 적용됩니다. 단, 활성 디렉토리에서 직접 수정한 코드 또는 `_bundled` 에 없는 외부 확장은 자동 복구 대상이 아니므로 업그레이드 전 위 디렉토리 백업이 동일하게 권장됩니다.

- **사용자군 C — beta.1 또는 beta.2 사용자**: `php artisan core:update` 1회 호출로 beta.5 까지 직접 업그레이드는 권장하지 않습니다. 중간 버전의 업그레이드 스텝이 이전 버전 메모리에 없는 신규 코드에 의존하여 fatal 위험이 있습니다. 다음 단계적 업그레이드를 권장합니다:

  ```text
  beta.1 사용자: beta.1 → beta.2 → beta.3 → beta.4 → beta.5   (4 회)
  beta.2 사용자: beta.2 → beta.3 → beta.4 → beta.5             (3 회)
  ```

  각 단계마다 `.env` `APP_VERSION` 이 자동 갱신되므로 다음 호출은 그대로 `php artisan core:update` 실행하면 됩니다. 가까운 릴리즈 zip 으로 `--source=` 또는 `--zip=` 옵션을 사용해 단계별 진행 가능합니다.

#### 자동 롤백 후 `public/storage` 회복

업그레이드 도중 자동 롤백 후 화면에서 업로드 파일이 보이지 않거나 미디어 경로가 깨졌다면 `public/storage` symlink 가 일반 디렉토리로 변질된 상태일 수 있습니다.

**우선 시도 — beta.5 의 자동 복구**: beta.4 → beta.5 업그레이드 자체는 부모 beta.4 메모리 결함으로 symlink 가 깨질 수 있으나, beta.5 의 업그레이드 스텝이 다음 패턴을 자동 감지하여 복구합니다:

- 감지 조건: `public/storage` 가 일반 디렉토리 + `storage/app/public` 이 존재 (Laravel 표준 구성)
- 복구 동작: 손상된 디렉토리를 `public/storage.broken.YYYYMMDDHHMMSS` 로 보존 후 symlink 재생성
- 운영자 조치: 자동 복구 후 `.broken` 디렉토리의 콘텐츠를 검증한 뒤 수동 삭제 (false positive 방어를 위해 자동 삭제하지 않음)

**자동 복구가 작동하지 않은 경우 매뉴얼 회복**:

```bash
rm -rf public/storage
php artisan storage:link
```

beta.5 부터 백업/복원 과정에서도 symlink 가 보존되므로 beta.5 → beta.6 이후로는 본 결함이 자동 차단됩니다. Windows 환경에서는 PHP `symlink()` 가 `SeCreateSymbolicLink` 권한을 요구하므로 자동 복구가 silent skip 될 수 있어 매뉴얼 회복 절차가 여전히 필요합니다.

#### spawn 자식 프로세스 실패 시 동작 모드

beta.5 부터 코어 업데이트의 자식 프로세스 spawn 이 실패하면 즉시 abort 가 기본 동작입니다 (`G7_UPDATE_SPAWN_FAILURE_MODE=abort`). 다음 4가지 상황에서 부모 프로세스 메모리의 stale 클래스로 인한 fatal 위험을 차단합니다:

1. `proc_open` 비활성 (일부 공유 호스팅)
2. `proc_open` 자원 생성 실패
3. 자식 비정상 종료 (uncaught exception)
4. 자식 exit=0 이지만 업그레이드 스텝 0건 실행 (silent skip)

abort 발생 시 운영자에게 수동 재개 명령 (`php artisan core:execute-upgrade-steps --from=... --to=...`) 을 안내합니다. `proc_open` 비활성 환경 등 호환성이 필요한 경우 `.env` 에 `G7_UPDATE_SPAWN_FAILURE_MODE=fallback` 을 설정하여 in-process fallback 으로 진행할 수 있으나, 부모 메모리 stale 로 인한 fatal 위험이 잔존합니다.

### Added

- 본인인증 관리자 권한을 조회/수정으로 분리 — "프로바이더 설정 조회" / "정책 조회" 권한 신설로 단순 조회 권한만 부여받은 운영자도 환경설정 본인인증 화면에 접근 가능 (기존에는 수정 권한이 없으면 조회 화면도 403)
- 환경설정의 본인인증 정책/메시지 탭에 권한 기반 버튼 비활성화 적용 — 수정 권한이 없는 운영자에게는 "정책 추가" / "수정" / "삭제" / "활성 토글" 버튼이 자동으로 비활성화 (이커머스/게시판 본인인증 탭 동일)
- 코어 업데이트의 spawn 자식 프로세스 실패 시 abort/fallback 동작 모드 선택 (`G7_UPDATE_SPAWN_FAILURE_MODE`, 기본 `abort`) — 부모 메모리 stale 로 인한 upgrade step fatal 위험을 fail-fast 로 차단 (#28 @bigmsg 님께서 제보해주셨습니다.)
- 업그레이드 스텝에 "버전별 데이터 스냅샷" 규약 도입 — 멀티 버전 점프 시에도 사용자가 단계별로 업그레이드한 것과 동등한 결과를 보장하도록 각 스텝의 시드 카탈로그·적용 로직·단발성 핫픽스를 해당 버전 디렉토리로 격리. 외부 확장 작성자가 beta.5+ 신규 업그레이드 스텝 작성 시 적용 (상세: 업그레이드 스텝 가이드) (#29 @bigmsg 님께서 건의해주셨습니다.)
- 언어팩 일괄 활성화 검증 규칙에 동적 확장 훅 추가 (`core.language_packs.bulk_activate_validation_rules`) — 모듈/플러그인이 호스트 확장 재활성화 cascade 흐름에서 추가 검증 필드를 동적으로 등록 가능

### Changed

#### Breaking

- 본인인증 관리자 권한 키 정리 — 기존 `core.admin.identity.manage` / `core.admin.identity.policies.manage` 가 각각 `core.admin.identity.providers.update` / `core.admin.identity.policies.update` 로 변경. beta.4 환경에서 기존 권한을 부여받은 운영자 역할은 업그레이드 시 자동으로 새 권한 키로 마이그레이션 (부여 시점/부여자/스코프 메타데이터 모두 보존). 외부 확장 또는 운영 도구가 옛 키 문자열을 하드코딩한 경우 새 키로 갱신 필요

### Fixed

- 코어 업데이트 시 활성 모듈/플러그인/템플릿/언어팩 디렉토리가 삭제되던 문제 수정 — beta.4 → beta.5 업그레이드 시 번들 원본에서 활성 디렉토리를 자동 복원하고, 외부 설치 확장은 업데이트 로그로 재설치 안내
- 코어 업데이트의 단계 전환 신호를 비정상 입력으로부터 차단하도록 검증 강화
- 본인인증 정책 응답의 생성/수정 일시가 사용자 타임존이 아닌 UTC ISO 문자열로 노출되던 문제 수정
- 백업/롤백 시 `public/storage` 등 symlink 가 target 디렉토리 내용으로 추적 복사되어 symlink 가 일반 디렉토리로 변질되던 문제 수정 (Linux 환경 한정 — Windows 는 권한 한계로 일반 디렉토리 폴백 + 수동 회복 안내 유지)
- root/super user 환경에서 Composer 검증·설치가 비대화형 컨텍스트에서 실패하던 문제 수정 (코어 업데이트, 확장 의존성 설치, 인스톨러 포함) (#31 @glitter-gim 님께서 제보해주셨습니다.)
- 본인인증 활동 로그의 "액션" 열에 `identity.verify` / `identity.verify_failed` 가 번역되지 않은 키 그대로 노출되던 문제 수정 — 코어 액션 라벨 매핑이 누락되어 있던 부분을 추가
- 본인인증 단계에서 운영자가 지정한 본인인증 수단이 무시되고 항상 기본 수단(메일)으로 발행되던 문제 수정 — 가입 정책에 설정한 본인인증 수단과 API 요청에서 명시한 수단이 실제 인증 발행에 반영되도록 변경

## [7.0.0-beta.4] - 2026-05-11

### Changed

#### Breaking

- 레이아웃 `navigate` 액션의 기본 스크롤 동작이 페이지 이동 후 최상단 이동으로 변경됨 — 일반 하이퍼링크 이동 UX 와 일치. 이전처럼 스크롤 위치를 유지하려면 `scroll: "preserve"` 명시 필요. 검색 필터/페이지네이션 등 위치 유지가 필요한 화면에서 회귀가 의심되면 해당 옵션 추가 (engine-v1.45.0)

#### 언어팩 매니페스트 정합화

- 언어팩 매니페스트(`language-pack.json`) 를 모듈/플러그인/템플릿 매니페스트와 동일한 필드 구조로 정렬 — 외부 작성자가 다른 확장과 동일한 표준으로 언어팩을 만들 수 있도록 개선
- 언어팩 매니페스트에 GitHub 저장소 필드 추가로 GitHub 기반 업데이트 경로 지원 (모듈/플러그인 매니페스트와 동일한 동작)
- 관리자 환경설정의 언어팩 카드와 상세 모달이 모듈/플러그인/템플릿 카드와 동일한 형식으로 다국어 이름 · 설명 · GitHub 링크를 노출하도록 변경

### Added

#### Generator 메타 태그

- 관리자 환경설정 → SEO 탭에 Generator 메타 태그 카드 추가 — 토글로 노출 여부를 제어하고 내용 입력으로 W3Techs 등 CMS 시장 점유율 측정 도구가 인식하는 `<meta name="generator">` 태그를 SEO 봇 페이지·SPA·관리자 셸 모두에 출력. 내용 미입력 시 "GnuBoard7 {버전}" 자동 적용, 운영자가 버전 노출을 원치 않으면 "GnuBoard7" 만 입력 가능 (#26 @Lastorder-DC 님께서 건의해주셨습니다.)

#### 웹 인스톨러 — 번들 언어팩 동반 선택 · 설치

- 인스톨러 4단계 확장 선택 화면에 "언어팩" 카드 신설 — 번들 언어팩을 locale 별 서브헤딩으로 노출하고, 사용자가 모듈/플러그인/템플릿을 선택하면 그 확장에 종속된 번들 언어팩 카드가 즉시 활성화. 종속 확장을 해제하면 그 언어팩 카드는 자동으로 비활성화 + 선택 해제
- 5단계 설치 진행 시 모든 확장의 install/activate 가 끝난 뒤 선택된 번들 언어팩을 일괄 설치 — 1건 실패는 best-effort 처리로 전체 설치를 중단하지 않음
- 코어/확장당 다수 locale(일본어 + 중국어 등)이 동시에 번들된 시나리오 대응 — 각 locale 은 독립된 서브헤딩 + 카드로 노출

#### 인스톨러 SSE 호환성 자동 감지

- 인스톨러 시작 시 SSE 호환성을 사전 점검하여 환경에 맞는 모드(SSE 또는 폴링)로 단방향 진입 — 워커 동시 실행 race(테이블 / unique 키 중복 에러) 차단
- SSE 비호환 환경 사용자에게 폴링 모드 진행 여부를 명시적 다이얼로그로 확인 후 시작

#### ActivityLog 다국어 영역 분리

- 활동 로그의 액션 라벨이 모듈/플러그인 자체 다국어 파일에서 우선 해석되도록 변경 — 그동안 코어에 일괄 등록되어야 했던 모듈 origin 라벨(이커머스 주문·상품·마일리지, 게시판 게시물·댓글·신고, 페이지 등) 이 각 영역으로 이전되어 모듈/플러그인이 자기 도메인 라벨을 자기 영역에서 자기설명. 미정의 시 코어 라벨로 자동 fallback 하여 회귀 없음
- 모듈/플러그인이 발화하는 활동 로그가 호출자/대상 모델의 영역을 자동으로 인식하여 자기 다국어 파일로 라우팅 — 새 활동 로그를 추가할 때 별도 분기 코드 없이 자기 lang 만 채우면 정합

#### 다국어 시더 인프라

- 확장 entity 시더가 활성 언어팩의 다국어 데이터를 자동 머지하도록 다국어 시더 인터페이스 도입 — 신규 시더 작성 시 회귀를 자동 차단
- 번들 일본어 언어팩 빌드 스크립트가 시더 메타데이터를 일관된 경로로 조회하도록 단순화

#### 다국어 라벨 helper + Provider/Registry 페이로드 정합화

- 다국어 라벨 표시 시 활성 언어팩의 lang key fallback 을 자동으로 처리하는 다국어 라벨 보강 helper 추가 — Provider/Registry 등록 페이로드(알림 채널·결제 PG 등) 와 settings JSON 다국어 데이터를 단일 시그니처로 처리
- 알림 채널(`config/notification.php` 의 `default_channels`) 의 라벨이 활성 언어팩(일본어 등) 으로 자동 보강되도록 변경 — 일본어 활성 시 한국어 fallback 으로 노출되던 회귀 차단
- 토스페이먼츠 PG 프로바이더 등록 페이로드가 lang key 기반으로 라벨을 선언하도록 변경 — 활성 언어팩으로 자동 보강
- Provider/Registry 등록 페이로드가 다국어 JSON(`['ko' => ..., 'en' => ...]`) 을 직접 보유하지 않도록 audit 룰 신설 (회귀 자동 차단)
- settings JSON 다국어 entry 의 식별 키(code/id/key) 보유 검증 audit 룰 신설 (lang pack fallback 키 조립 가능성 보장)
- 프론트엔드 `$localized()` 표현식이 두 번째 인수로 lang key fallback 을 받도록 확장 — 활성 로케일 라벨 부재 시 모듈/플러그인 언어팩의 키로 자동 보강
- 이커머스 환경설정 카탈로그(배송 가능 국가 / 통화 / 결제수단) 표시 시 활성 언어팩의 라벨이 자동 적용되도록 변경 — 일본어 활성 시 카탈로그 라벨이 한국어로 노출되던 회귀 차단 (다음 일본어 언어팩 빌드 시 자동 적용)
- audit 룰이 `getDefault*Channels/Providers/Methods` 등 registry 기본값 반환 메서드의 다국어 JSON 직접 보유도 감지하도록 강화

#### Settings 카탈로그 다국어 자동 보강

- 환경설정 카탈로그(결제수단·통화·배송 가능 국가 등) 의 다국어 라벨이 카탈로그 빌드 시점에 활성 언어팩으로 자동 보강되도록 변경 — 사용자 체크아웃 + 관리자 환경설정 양쪽 모두 일본어 등 활성 시 한국어 fallback 으로 노출되던 회귀 차단
- 모듈/플러그인 개발자가 카탈로그를 추가할 때 누락하지 않도록 audit 룰이 helper 호출 누락 검출
- [docs/backend/settings-multilingual-enrichment.md](docs/backend/settings-multilingual-enrichment.md) 에 단순 helper 사용 패턴 문서화

#### 확장 시스템

- manifest `hidden: true` 플래그 추가 — 관리자 UI 목록에서 학습용/내부용 확장을 숨김 (CLI 는 정상 노출). 관리자 UI 에 슈퍼관리자 전용 "숨김 포함" 토글, artisan `module:list` / `plugin:list` / `template:list` 에 `--hidden` 플래그, 관리자 API `/api/admin/{modules,plugins,templates}` 에 `include_hidden` 쿼리 파라미터 지원
- 학습용 최소 샘플 확장 4종 번들 추가
  - 모듈: `gnuboard7-hello_module` (Memo CRUD + 훅 발행 시연)
  - 플러그인: `gnuboard7-hello_plugin` (Action/Filter 훅 구독 시연)
  - Admin 템플릿: `gnuboard7-hello_admin_template` (Basic 컴포넌트 최소 셋)
  - User 템플릿: `gnuboard7-hello_user_template` (홈 + Memo 리스트 연동)
- 모듈/플러그인/템플릿 정보 모달에 "지원 언어" 섹션 추가 — 코어/번들/사용자설치 출처 배지로 한눈에 확인
- 모듈/플러그인/템플릿 인스톨러에 의존 확장 + 동반 번들 언어팩 동반선택 UI 추가 — 미선택 의존성에 종속된 언어팩은 자동 비활성화
- 인스톨러 요구사항 검증 단계에 언어팩 디렉토리 쓰기 권한 점검 + 권한 부여 안내 추가
- 사용자 수동 비활성화와 코어 버전 호환성으로 인한 자동 비활성화를 DB 수준에서 구분 — 자동 비활성화된 확장만 재호환 감지/원클릭 복구 대상이 되도록 분리 (#18 @laelbe 님께서 제보해주셨습니다.)
- 코어 업그레이드 후 자동 비활성화 확장이 다시 호환되면 관리자 대시보드에 "다시 활성화" 알림 표시 + 원클릭 복구 버튼 제공 (자동 재활성화는 하지 않음 — 운영자가 명시적으로 복구)
- 모듈/플러그인/템플릿 목록 화면 상단에 자동 비활성화 확장 안내 배너 추가 (코어 업그레이드 가이드 링크 동반)
- 업데이트 모달에 코어 버전 호환성 안내 + "위험을 이해하고 강제로 진행" 체크박스 추가 — 운영자가 위험을 인지하면 비호환 확장도 강제 설치 가능
- 관리자 대시보드 "시스템 알림" 카드 노출 — 코어 호환성 자동 비활성화/재호환 알림이 분기 렌더되며 개별 dismiss 지원

#### 코어 업데이트 가시성

- 코어 업데이트 마무리 단계에서 sudo 환경 결함(파일시스템 ACL · immutable 비트 · NFS 권한 거부 등) 으로 일부 경로의 소유권/그룹 쓰기 권한을 정상화하지 못한 경우 즉시 콘솔에 실패 경로 + 운영자 수동 복구 명령(`sudo chown -R …` / `sudo chmod -R g+w …`) 안내 — 이전에는 silent fail 로 묻혀 운영자가 후속 권한 거부 발생 후에야 인지하던 문제 해소

#### 알림 시스템

- 권한 기반 수신자 타입 추가 — `permission` 타입으로 특정 권한을 가진 모든 사용자에게 알림 발송 가능 (예: 게시판 신고 알림은 신고 관리 권한자에게 자동 발송)
- 모듈 환경설정에서 알림이 비활성화된 경우 발송 자체를 사전 차단하는 정책 게이트 도입 — 이전에는 수신자 해석 단계가 정책을 우회하여 발송되던 문제 해소
- 게시판 환경설정 → 신고 정책 탭에 신고 알림 채널 선택 UI 추가 (이메일 / 사이트 알림 다중 선택)
- 모듈/플러그인이 자기가 발송하는 알림 정의를 manifest 에서 직접 선언하도록 통일 — 활성화/업데이트 시 운영자 편집값을 보존하면서 자동 등록되고, 제거 시 함께 정리됨. 권한·메뉴·본인인증과 동일한 declarative getter 패턴
- 코어 기본 알림(회원가입 환영·비밀번호 재설정·비밀번호 변경) 의 다국어 제목/본문/변수/채널 정의를 `config/core.php` 로 통합 — 운영자가 코드 수정 없이도 향후 표면 변경을 추적할 수 있는 단일 소스 확보

#### 본인인증

- 본인인증(IdentityVerification) 인프라 도입 — 코어에 범용 IDV 프로바이더 계약을 마련하고, 기본 메일 프로바이더를 내장. 플러그인이 KCP·이니시스·SMS 등 다른 경로를 동일 계약으로 붙일 수 있도록 확장점 제공
- 외부 본인인증 provider (KCP·PortOne·토스인증·Stripe Identity 등) 가 G7 표준 Extension Point 패턴으로 자기 SDK UI 를 주입할 수 있는 슬롯 도입
- 비동기 검증 흐름을 위한 백엔드 폴링/콜백 엔드포인트 추가 — `GET /api/identity/challenges/{id}` (상태 폴링), `POST /api/identity/callback/{providerId}` (외부 redirect 콜백 수신)
- 본인인증 정책 시스템 신설 — 회원가입·비밀번호 재설정·민감 작업에 적용되는 모든 본인인증 시점을 라우트/훅 단위로 선언형 정책으로 통합 관리. 관리자 화면에서 정책 활성/유예 시간/프로바이더/실패 모드/단계(가입 제출 전 vs 가입 후 활성화 전)·적용 대상(self/admin/both)을 조정할 수 있으며, 운영자 수정값은 업데이트 재시딩 시 보존됨
- 본인인증 정책의 enable 토글이 라우트 코드 수정 없이 즉시 적용 — 모든 API 라우트가 정책 DB 와 자동 매칭되어 운영자가 admin UI 에서 정책을 켜는 즉시 본인인증이 강제됨. 코어/모듈/플러그인 어떤 라우트의 응답 처리 패턴에서도 본인인증 흐름이 일관되게 모달까지 도달하도록 처리
- 회원가입 단계 정책 2종 시드 — 가입 제출 전 동기 검증, 가입 후 활성화 전 비동기 challenge (기본값 비활성, 운영자 opt-in)
- 본인인증 정책이 활성화된 모든 강제 지점(회원가입·비밀번호 재설정·민감 작업·게시판/이커머스 정책 등)에서 사용자/관리자 화면에 동일한 모달 UX 가 자동으로 표시되도록 코어 인터셉터와 공통 모달 인프라 도입
- 전역 프론트엔드 인터셉트 — 서버가 HTTP 428 본인인증 요구 응답을 반환하면 자동으로 인증 모달을 띄우고 사용자가 인증에 성공하면 원 요청을 자동 재실행
- 본인인증 가드가 모듈/플러그인이 선언한 훅에도 자동 적용되도록 동적 구독 도입 — 결제 직전·민감 액션 직전 등 도메인 특화 가드를 운영자 토글 한 번으로 활성화
- 관리자 화면에 본인인증 정책 관리 페이지 추가 (정책 목록 DataGrid + 편집)
- 관리자 화면에 본인인증 이력 페이지 추가 — 알림 발송 이력과 동일한 수준의 UI 제공: 인증 수단(Provider) 탭(활성화된 프로바이더에 따라 자동 갱신), 통합 검색(자동 감지/사용자 ID/대상 식별자/IP/정책 키), 상태·인증 목적·채널·발생 유형 다중선택 OR 필터, 날짜 범위 + 고급 검색(발생 유형·출처·정책 키) 토글 영역, 정렬·페이지 사이즈 선택, 행 펼침으로 인라인 상세 표시, 모바일 반응형, 사용자 타임존 기준 시각, 보관주기(180일) 일괄 파기, 인증 수단/발생 위치 다국어 라벨 표시
- 본인인증 목적(purpose) 의 출처(source) 추적 — 코어/모듈/플러그인이 선언한 목적을 구분해 환경설정 화면에서 분리 표시하며, 코어 정책 화면 상단에 "코어가 제공하는 본인인증 목적" 칩 섹션(목적 코드/라벨 + 설명·허용 채널 툴팁) 추가
- 모듈 환경설정의 본인인증 정책 탭을 코어 정책 관리 화면과 동일한 UI/UX 로 통일 — 검색·필터(강제 시점/출처)·페이지네이션·정책 추가/편집 모달 직접 호출(코어 화면으로 이동 X)·이 모듈이 등록한 본인인증 목적 칩 섹션(없으면 안내 메시지). 정책 목록의 "인증 목적" 컬럼이 코드명 대신 사람이 읽을 수 있는 라벨로 표시
- 모듈/플러그인이 자기 컨텍스트의 본인인증 정책·목적·메시지 정의를 manifest 에서 직접 선언하도록 통일 — `module.php::getIdentityPolicies()` / `getIdentityPurposes()` / `getIdentityMessages()` 선언만으로 코어 정책 관리에 자동 연동되며, 활성화/업데이트 시 운영자 편집값을 보존하면서 자동 등록·정리됨. 권한·메뉴·알림과 동일한 declarative getter 패턴
- 본인인증 정책/이력 목록 API 가 source 컨텍스트별 필터를 받아 모듈 환경설정 탭이 자기 정책/이력만 조회 가능하며, 운영자 정의 정책을 특정 모듈/플러그인 컨텍스트에 귀속시켜 추가할 수 있도록 source_identifier 입력 허용 (`admin` / `module:{id}` / `plugin:{id}`)
- 본인인증 메일 메시지 템플릿 시스템 도입 — 프로바이더와 (목적/정책)별로 다국어 제목/본문을 개별 정의 가능하며, 메시지 발송 시 정책 → 목적 → 프로바이더 기본값 순서로 fallback 해석. 회원가입/계정 변경/중요 작업/비밀번호 재설정 + 프로바이더 기본값 등 5종 메일 템플릿이 한국어/영어로 시드되어 즉시 발송 가능
- 본인인증 challenge 발급/검증/취소 라우트에 권한 미들웨어 + scope=self 가드 적용 — 게스트는 `core.identity.{request,verify,cancel}` 권한으로 비로그인 가입(Mode B) 흐름 진입, 로그인 사용자는 본인 challenge 만 다룰 수 있으며 관리자는 임의 challenge 도 처리 가능. 모달 취소 시 서버 cancel API 를 호출해 challenge 가 audit log 에 cancelled 상태로 즉시 기록되도록 정합화
- 환경설정 → 본인인증 탭에 "메시지 템플릿" 서브탭 신설 — 알림 템플릿 관리와 동일한 UX (채널 서브탭·페이지당 항목 수 셀렉터·카드 펼침 본문 미리보기·활성/기본 배지·페이지네이션) 로 정의 목록·활성 토글·다국어 편집(변수 가이드 + 기본값 복원) 제공. 운영자가 추가한 정책에 전용 메일 메시지 정의를 화면에서 직접 등록·삭제 가능 (시드 기본값 보호 + 정책 키 매칭 검증). 외부 본인인증 프로바이더 플러그인이 자기 메시지 기본값을 코어 복원 로직에 기여할 수 있는 필터 훅 노출
- 알림 발송 이력 / 본인인증 이력 샘플 시더를 코어/모듈별로 분리 — 코어 시더는 코어 정의·정책만, 각 모듈 시더는 자기 영역만 채우도록 영역 격리. 모듈은 `module:seed {id} --sample` 으로 자기 영역 이력만 독립 생성 가능. 샘플 데이터는 실제 등록된 사용자·정의·정책·프로바이더 기반으로 생성되어 운영 데이터와 동일한 스키마/분포 유지
- 모듈/플러그인 제거 시 코어 공유 테이블에 적재된 해당 확장의 데이터(권한·관리자 메뉴·알림 정의·본인인증 정책·본인인증 메시지 정의·본인인증 목적) 가 함께 정리되며, 제거 모달의 "삭제될 데이터" 에도 항목별 건수가 표시되도록 개선
- IDV 도메인 분류 데이터(목적·채널·트리거 출처·정책 범위·정책 실패 모드·정책 적용 대상·정책 출처·메시지 스코프) 8종을 PHP Backed Enum 으로 정의하여 정책 등록/수정 시 일관된 검증 적용

#### 인스톨러

- 인스톨러 설치 완료 화면에 설치된 코어 버전 표시 — 사용자가 어떤 버전이 설치되었는지 즉시 확인할 수 있도록 개선

#### 언어팩 시스템

- 새 언어(일본어/중국어 등)를 코어 수정 없이 추가할 수 있는 언어팩(Language Pack) 시스템 도입
- ZIP 업로드 또는 GitHub URL로 언어팩 설치/제거/활성화 지원
- 동일 언어 슬롯에 여러 벤더의 언어팩 공존 가능 — 라디오 전환으로 즉시 활성 변경
- 코어/모듈/플러그인/템플릿 별도 적용 — 모듈 언어팩은 해당 코어 언어팩이 활성일 때만 활성화 가능
- 관리자 메뉴: 환경설정 > 언어팩 관리(통합) + 모듈/플러그인/템플릿별 진입점 추가
- 사용자가 직접 수정한 다국어 키는 언어팩이 덮어쓰지 않도록 보존 정책 적용
- 보안: 언어 번역 외의 PHP 실행 코드 포함 시 설치 차단
- 언어팩 관리 화면을 모듈 관리와 동일 수준의 운영 도구로 보강 — 검색(식별자/벤더/언어), 업데이트 확인, 캐시 갱신, 다중 선택 일괄 제거 지원
- 활성/비활성 상태를 토글 스위치로 일원화 (보호된 팩은 비활성화된 토글로 표시)
- 업데이트 가능 항목에 "업데이트 가능" 배지와 행 단위 업데이트 실행 버튼 노출
- 정보 모달을 모듈 정보 모달과 동일한 4섹션 구조(기본정보 / 호환성 / 소스 정보 / 변경로그)로 제공하며, CHANGELOG.md 를 자동 파싱하여 버전별 카테고리로 표시
- 설치 모달에서 ZIP 업로드 전에 manifest 와 검증 결과를 사전 확인할 수 있는 미리보기 제공
- 모듈/플러그인/템플릿별 언어팩 페이지 진입 시 대상 확장 안내 배너와 환경설정 탭으로 회귀하는 링크 노출
- 언어팩 업데이트 권한을 별도 권한 키로 분리하여 설치/관리 권한과 독립적으로 부여 가능
- 언어팩 운영 풀 패리티 — 모듈/플러그인/템플릿 관리와 동일한 안전 장치/확장성 적용
- 공식 일본어(ja) 번들 언어팩 12종 추가 — 코어, 주요 모듈(전자상거래/게시판/페이지), 주요 플러그인(CKEditor5/마케팅/토스페이먼츠), 기본 템플릿(admin/user)을 일본어로 즉시 사용 가능
- 언어팩 관리 화면에서 번들 언어팩을 모듈/플러그인 관리와 동일하게 "미설치" 상태로 노출 — 행별 "설치" 버튼으로 즉시 설치 가능
- 언어팩 목록 필터에 "미설치 (번들)" 상태 옵션 추가
- 본인인증 메일 메시지 정의의 다국어 키를 언어팩으로 주입할 수 있도록 확장 — 코어 수정 없이 언어팩 ZIP 만으로 IDV 메일 본문/제목을 다국어화 가능
- 모듈/플러그인이 선언한 알림/본인인증 메시지 정의의 다국어 키도 언어팩으로 주입되도록 정합 — 이전에는 hook 발화 누락으로 코어 정의에만 적용되던 동작 정상화
  - 업데이트 시 자동 백업 + 실패 시 직전 버전으로 자동 복구
  - 동시성 가드 — 진행 중인 작업 동안 활성/비활성/제거/재업데이트 진입 차단
  - 번들 디렉토리(`lang-packs/_bundled/{identifier}`) 에서 외부 다운로드 없이 (재)설치하는 경로 추가 — 코어 언어팩 복구/재배포 단순화
  - 라이프사이클 훅 명명을 모듈/플러그인/템플릿과 통일 — `core.language_packs.{installed|updated|uninstalled|activated|deactivated}` 발행 (확장 가능성 확대)
  - Artisan 커맨드 신규 — `language-pack:list`, `language-pack:install`, `language-pack:update`, `language-pack:uninstall`
- 코어와 번들 확장(모듈/플러그인/템플릿)에 내장된 한국어/영어를 가상 보호 언어팩으로 자동 노출 — 별도 설치 없이 언어팩 관리자에서 항상 활성/보호 상태로 확인 가능, 수정/제거 차단
- 호스트 확장 비활성화 시 그에 종속된 언어팩이 함께 비활성화되며, 재활성화 시 "다음 언어팩도 활성화하시겠습니까" 모달로 사용자 의사 확인 후 일괄 활성화
- 여러 언어팩을 한 번에 활성화하는 `POST /api/admin/language-packs/bulk-activate` API 추가 (의존성/버전 호환성 자동 검사)
- 언어팩 활성화 시 의존성 + 호스트 확장 버전 호환성 검사 자동 수행 — 호스트 확장이 비활성/미설치이거나 버전 미달이면 활성화 차단
- 언어팩 업데이트 우선순위를 모듈/플러그인 패턴으로 정합화 — GitHub 1순위 + bundled 폴백, 강제 업데이트 시 bundled 우선
- 언어팩 상세 모달에서 코어/번들 확장 내장 항목 클릭 시 "별도 언어팩이 아닌 내장 번역" 안내 배너 노출
- 모듈/플러그인/템플릿 상세 모달의 닫기 버튼이 콘텐츠 길이와 무관하게 모달 하단에 항상 보이도록 sticky 처리

#### SEO

- 관리자 환경설정 > SEO 탭의 Sitemap 카드에서 sitemap 을 즉시 재생성하고 마지막 생성 시각을 확인할 수 있는 "지금 생성" 버튼 추가 — 큐 드라이버와 무관하게 동기 실행되며 결과를 즉시 표시

#### SEO 봇 감지

- 봇 감지 엔진을 `jaybizzle/crawler-detect` 라이브러리로 교체. 기본 약 1,000종의 봇(검색엔진·링크 미리보기·AI 검색 등)이 자동 감지됨 — 링크를 슬랙·페이스북·LinkedIn·트위터·디스코드·텔레그램 등에 붙여넣으면 제목·설명·이미지 미리보기가 즉시 동작
- 라이브러리가 놓치는 봇 3종(`kakaotalk-scrap`·`Meta-ExternalAgent`·`ChatGPT-User`) 을 G7 보강 패턴으로 기본 포함
- 봇 감지 확장 훅 `core.seo.resolve_is_bot` 신설 — 플러그인이 IP 범위 검증·역방향 DNS·Cloudflare 봇 점수 등을 주입할 수 있는 슬롯
- "봇 라이브러리 사용" 관리자 토글 추가 (기본 on, 비활성 시 운영자 커스텀 목록만 사용하는 레거시 모드)

#### SEO OG / Twitter 카드 / 도메인 ownership

- 슬랙·페이스북 링크 미리보기에 이미지·카드가 표시되도록 OG 보강 태그를 자동 출력하도록 개선 — og:site_name, og:image:width, og:image:height, og:image:secure_url, og:image:type, og:image:alt, og:locale 추가
- Twitter 카드 메타태그(twitter:card / twitter:site / twitter:title / twitter:image 등) 출력 신설 — 슬랙 unfurl 폴백 경로 정상화
- 운영자 환경설정 SEO 탭에 "OG / Twitter 카드 기본값" 카드 추가 — 사이트 이름·이미지 기본 가로/세로·Twitter 카드 타입·Twitter 사이트 핸들 5개 입력
- 모듈·플러그인이 자기 도메인의 OG/Twitter/JSON-LD 를 직접 선언하도록 SEO declaration API 신설 — 이커머스 상품(Product/Offer/AggregateRating)·게시판 게시글(Article) 등 도메인 스키마가 레이아웃에서 확장 코드로 owned 되어 데이터에 따라 정확한 부속 태그 생성
- SEO 메타 확장 훅을 분기별·통합 모두 제공 — OG·Twitter·구조화 데이터 각각의 hook 슬롯과 통합 hook 모두 지원하여 확장이 원하는 단계에서 선택 변경 가능
- 모듈 설정 타이틀 템플릿에서 변수가 비어있을 때 인접 구분자(- – · |) 가 자동 정리되도록 개선 — 옵셔널 그룹 `[ ... ]` 표기도 지원하여 페이지별 구성 명시 가능

### Changed

- 콘솔 confirm 입력 처리 통일 — yes/y, no/n 외 입력 시 안내 메시지 출력 후 재질문, empty 입력 시 default 사용. 코어 업데이트·매니저 커맨드(module/plugin/template install·update·uninstall)·설정 마이그레이션의 모든 yes/no 프롬프트에 동일 규칙 적용 (#15 @laelbe 님께서 건의해주셨습니다.)
- 비밀번호 재설정 정책 기본값을 비활성으로 변경 — 본인인증 인프라가 미구성된 사이트에서도 기본 동작이 영향받지 않도록 운영자 opt-in 으로 전환
- 본인인증 정책의 인증 조건(`conditions`) 운영자 편집 허용 — 회원가입 단계 등 정책 조건을 코드 수정 없이 관리자 화면에서 조정 가능. 모듈 업데이트 시 운영자 수정값 보존
- 토큰 만료 등으로 권한 없는 레이아웃 진입 시 "페이지 로딩 실패" 에러 화면 대신 로그인 페이지로 자동 이동 — 로그인 화면에서 "세션이 만료되었습니다. 다시 로그인해 주세요." 토스트로 사용자에게 안내. 템플릿이 자체 로그인 경로를 사용하는 경우 부트스트랩에서 인증 설정을 커스터마이즈할 수 있는 공개 API 도 함께 제공 (#19 @abc101 님께서 건의해주셨습니다.)
- 템플릿 다국어 데이터 로딩 시 활성 언어팩의 다국어가 가장 높은 우선순위로 병합되도록 변경
- 권한·역할·메뉴·알림 등 코어 기본 데이터와 배송유형·클레임 사유·게시판 유형 등 모듈 기본 데이터에 활성 언어팩의 다국어가 자동 반영되도록 개선
- 사용자 수정 보존(user_overrides) 정책을 다국어 JSON 컬럼은 sub-key 단위(`name.ko` 등) 로 기록하도록 개선 — 운영자가 한 언어 라벨만 수정해도 그 언어만 보존되며, 신규 활성 언어팩(예: 일본어 추가) 의 라벨은 자동 동기화됨. 기존 컬럼 단위(`name`) 기록은 업그레이드 시 활성 locale dot-path 로 자동 변환
- 언어팩 활성/비활성 시점에 영향받는 모듈/플러그인의 entity 시더가 자동 재실행되어 신규 언어 라벨이 즉시 DB 에 반영되도록 라이프사이클 통합 — scope 별 라우팅 (코어 언어팩 → 모든 활성 확장, 모듈 언어팩 → 해당 모듈만, 플러그인 언어팩 → 해당 플러그인만)
- 환경설정 → SEO → "추가 봇 패턴" 필드의 역할 변경 — 기존에는 유일한 봇 매칭 소스(기본 5종)였으나, 이제는 라이브러리가 놓치는 조직별 커스텀 봇만 추가하는 보강 레이어로 동작. 기존 설치의 운영자 커스텀 값은 모두 보존되며, 신규 설치 기본값은 jaybizzle 미커버 3종으로 변경

### Security

- 회원가입·비밀번호 재설정 라우트에 본인인증 정책 강제 미들웨어 부착 — 정책이 활성화된 경우 미인증 요청을 라우트 단계에서 차단 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 플러그인이 정책 해석 필터 훅에서 잘못된 타입을 반환해도 원본 정책이 유지되도록 우회 차단 강화 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 웹 인스톨러의 Composer/PHP 바이너리 경로 검증에서 사용자 입력이 그대로 shell 명령으로 실행될 수 있던 문제 수정 — 입력은 실행 가능한 단일 파일 경로로만 허용하고 모든 분기에서 인자 escape 강제. 설치 워커가 동일한 입력을 사용하던 내부 헬퍼도 같은 정책으로 정렬 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 설치 단계 4 의 확장 기능 선택 API 가 사용자가 보낸 모듈/플러그인/템플릿/언어팩 식별자에 셸 메타문자 검증을 적용하도록 강화 — 부적절한 식별자는 400 응답으로 거부되어 이후 설치 명령에 도달하지 않음 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 인스톨러의 코어 업데이트 _pending 경로 검증이 `..` 등 부모 디렉토리 우회 시도를 거부하고 응답 메시지를 단일화하여 임의 디렉토리 enumeration 신호 차단 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 설치 시 `.env` 작성 헬퍼가 입력값에 포함된 개행 문자를 제거하도록 변경 — DB 비밀번호 등 사용자 입력으로 새로운 환경 변수 라인이 주입되는 시나리오 차단 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 데이터베이스 연결 정보의 host/port/database 값에 DSN 키-밸류 구분자(`;`, `=`) 또는 NUL/CRLF 가 포함되면 연결을 거부하도록 추가 검증 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)
- 설치가 완료된 시스템에서 `public/install/` 하위 모든 엔드포인트가 비즈니스 로직 진입 전 HTTP 410 으로 차단되도록 공통 가드 도입 — 운영 환경에서 인스톨러 노출형 결함의 공격 표면 제거. 운영자가 인스톨러를 다시 사용해야 하는 경우 설치 완료 마커(`storage/app/g7_installed`) 와 `.env` 의 `INSTALLER_COMPLETED` 를 모두 제거 (KISA 측에서 제보해주셨습니다 — KVE-2026-0851)

### Fixed

- 코어 업데이트 후 일부 환경에서 모듈/플러그인이 저장한 사용자 데이터(상품 이미지·첨부파일 등)에 PHP 가 접근하지 못해 "찾을 수 없음" 오류가 발생하던 문제 수정 — 업데이트 종료 시점 권한 복원 범위를 PHP 쓰기 영역(storage/logs·storage/framework·bootstrap/cache·storage/app/core_pending)으로 한정하고 항목별 정확 복원으로 정합화. 이전 버전에서 본 릴리즈로 업그레이드한 환경에서는 업그레이드 시점에 storage/app 디렉토리/파일 권한을 PHP 가 접근 가능한 형태로 자동 정상화함. 향후 업데이트에서는 사용자 데이터 디렉토리에 자동 생성되는 보존 마커로 권한이 영구 보호됨
- PHP 8.5 환경에서 모든 페이지 응답이 손상되어 Firefox 에서는 "Content Encoding Error", Edge/Chromium 에서는 빈 화면으로 표시되던 문제 수정
- `php artisan serve` 환경에서 인스톨러 진행 중 .env 파일이 변경되면 개발 서버가 워커를 재시작하면서 설치 단계가 중도에 끊기던 문제 수정 — 설치 진행 중에는 .env 를 건드리지 않고 완료 화면 노출 후 한 번에 반영하도록 변경
- 관리자 환경설정 "시스템 사양" 카드의 메모리 항목이 서버 물리 메모리가 아닌 현재 PHP 프로세스가 사용 중인 메모리(수 MB 단위)로 표시되던 문제 수정 — 디스크 사용량과 동일한 형식(사용량/전체/백분율)으로 실제 서버 RAM 을 표시하도록 개선
- 관리자 환경설정 "시스템 사양" 카드의 CPU 항목이 Windows 11 / Windows Server 2025 에서 "operable program or batch file." 로 표시되던 문제 수정 — 해당 OS 에서 제거된 wmic 의존을 걷어내고 PowerShell 기반 조회로 전환, 구형 Windows 환경에서는 기존 방식으로 자동 폴백
- 관리자 환경설정 "정보" 탭을 한 번 조회한 뒤 다른 탭으로 전환할 때마다 수 초 지연이 반복되던 문제 수정 — 시스템 정보 조회 결과를 1시간 캐싱하여 탭 전환 시 대기 시간 제거 (시스템 캐시 초기화 시 함께 무효화)
- 큐 워커가 훅 페이로드의 enum 값(주문 상태 등)을 복원하지 못해 활동 로그·알림 등 일부 후속 처리가 실패하던 문제 수정
- 항목 삭제 후 큐 워커가 처리하는 후속 훅에서 대상 데이터를 복원하지 못해 처리가 중단되던 문제 수정 — 소프트 삭제 페이지·첨부파일, 하드 삭제 주문 옵션 등 포함
- 페이지 키워드 검색 결과의 전체 건수가 발행된 모든 페이지 수로 부풀려지던 문제 수정
- 사용자가 댓글을 단 게시글 활동 조회 시 500 에러가 발생하던 문제 수정
- 사용자 활동 통계에 삭제된 게시글의 댓글 수가 포함되던 문제 수정
- 일부 주문에서 옵션 정보 직렬화 시 500 에러가 발생하던 문제 수정
- 상품 옵션 삭제 검증 시 런타임 에러가 발생하던 문제 수정
- 설치 직후 또는 활성 모듈 디렉토리 부재 시 이커머스 환경설정 기본값이 비어있던 문제 수정
- 검색 가능 드롭다운(SearchableDropdown)에서 빠른 모달 전환 시 race condition 가능성 차단
- 반응형 설정과 반복 렌더링이 결합된 레이아웃에서 무한 재귀가 발생할 수 있던 엔진 결함 수정 (engine-v1.43.1)
- 슬롯 치환 시 텍스트 노드를 컴포넌트로 가정하여 발생할 수 있던 레이아웃 렌더링 오류 차단
- 인스톨러 실행 시 보안 키 생성 단계에서 개발용 패키지 ServiceProvider 를 찾지 못해 설치가 중단되던 문제 수정 — 이전 환경에서 남은 컴파일 캐시를 vendor 교체 직후와 보안 키 생성 직전에 자동 정리하도록 개선
- 폴링 모드 인스톨러가 큰 확장(레이아웃·테스트 수천 파일) 설치 도중 멈추던 문제 수정 — 명령 출력을 파일 기반으로 처리하여 OS 파이프 버퍼 한도와 무관하게 동작
- PHP 8.5 + Apache + mod_fcgid 환경에서 폴링 모드 진행 상황이 실시간 반영되지 않고 설치 완료 시점에 일괄 표시되던 문제 수정 — 어느 모드로도 진행 상황이 즉시 표시되도록 보정. Apache 환경별 권장 설정은 INSTALL.md 와 시스템 요구사항 문서에 명시
- 설치 완료 후 임시 파일이 자동 정리되지 않던 문제 수정
- 알림 템플릿 편집 모달의 입력 필드에서 글자를 입력할 때마다 화면이 심하게 버벅이던 문제 수정
- 슈퍼관리자가 다른 관리자 계정을 삭제할 수 없던 문제 수정 — 관리자 계정 삭제 가능 여부를 역할/권한/스코프 설정에 따라 판단하도록 개선 (슈퍼관리자 본인 보호는 유지)
- `seo:generate-sitemap` 커맨드가 큐 드라이버 설정과 무관하게 항상 "큐에 디스패치" 안내를 출력하던 문제 수정 — 동기 드라이버에서는 즉시 생성으로 동작하고 그에 맞는 안내를 표시하도록 변경
- 관리자 템플릿을 활성화해도 즉시 반영되지 않아 사용자가 직접 새로고침해야 하던 문제 수정 — 관리자 템플릿 활성화 시 자동으로 페이지를 갱신하여 새 템플릿이 즉시 적용되도록 개선
- 보안 환경설정의 "최대 로그인 시도 횟수 / 차단 시간" 설정이 실제로 적용되지 않아 무제한 로그인 시도가 가능하던 문제 수정 — 임계 도달 시 계정 잠금(HTTP 423), 잠금 해제 시각 안내 토스트, per-IP 백업 throttle, 활동 로그 기록까지 통합 구현
- 게시판 글쓰기 화면을 URL 로 직접 진입하거나 강제 새로고침했을 때 업로드한 첨부파일이 게시글에 연결되지 않던 문제 수정 (engine-v1.49.2) (#24 @minyho 님께서 제보해주셨습니다.)
- 코어 업그레이드 후 새 버전에서 추가된 권한·메뉴·알림 정의가 등록되지 않아 관리자 화면에서 "해당 권한이 없습니다" 가 반복 표시되거나 신규 메일 템플릿이 비어있던 문제 수정 — 업그레이드 시 새 버전 설정 파일을 정확히 인식하도록 보정. 본 릴리즈로 업그레이드하면 누락분이 자동 등록됨
- PHP 8.5 환경에서 관리자 로그인 시 `PDO::MYSQL_ATTR_SSL_CA` 등 PDO 드라이버 상수가 deprecation 경고를 발생시키던 문제 수정 — `Pdo\Mysql::ATTR_SSL_CA` 형태의 신규 상수로 전환하고, PHP 8.5 미만 환경에서는 기존 상수를 그대로 사용하도록 분기 처리 (#23 @yks118 님께서 제보해주셨습니다.)
- 회원가입 시 일부 환경에서 동의 항목 메타데이터가 누락되어 활동 로그 처리에서 예외가 발생, 후속 훅 체인이 중단되며 신규 회원에게 `user` 역할이 자동 부여되지 않던 문제 수정 — 동의 메타데이터를 nullable 로 받아 누락 케이스에서도 후속 권한 부여가 정상 동작하도록 개선 (#25 @comtylove-netizen 님께서 제보해주셨습니다.)

## [7.0.0-beta.3] - 2026-04-23

### Fixed

- CKEditor5 플러그인 활성 상태에서 게시판 글쓰기 저장 시 "제목은 필수입니다" 422 오류가 발생하던 호환성 문제 수정 — 제목·내용 입력 순서와 무관하게 정상 저장되도록 개선 (#17 @laelbe 님께서 제보해주셨습니다.)
- 코어 업데이트가 sudo 로 실행된 환경에서 캐시·세션·확장 디렉토리의 그룹 쓰기 권한이 일부 손실되어 업데이트 직후 "Permission denied" 또는 플러그인 제거 검증 실패가 발생하던 문제 수정 — 업데이트 종료 시점에 그룹 쓰기 권한을 자동 정상화하며 기존 손실 분은 1회성 복구 스텝으로 회수
- 업데이트 완료 후 Laravel 런타임이 새로 만드는 캐시·세션 하위 디렉토리가 기본 umask(022) 때문에 다시 그룹 쓰기 권한을 잃어 재차 "Permission denied" 가 발생하던 문제 수정 — 업그레이드 스텝이 업데이트 진행 프로세스의 umask 를 그룹 쓰기 친화적으로 전환하고, 이후 부팅 시점에도 `storage/` 의 현재 그룹 쓰기 설정을 감지해 프로세스 umask 를 자동 동조 (운영자가 그룹 공유를 비활성화한 환경은 그대로 보존)
- 코어 업데이트 마지막 단계의 진행 표시줄이 끝난 뒤 줄바꿈 없이 다음 셸 프롬프트가 같은 줄에 붙어 표시되던 출력 문제 수정

### Notes

- 7.0.0-beta.1 에서 7.0.0-beta.3 로 직접 업그레이드하는 경우, 환경(opcache CLI 활성 등)에 따라 권한 복구 스텝이 자동 실행되지 않고 건너뛰어질 수 있습니다. 업데이트 후 `storage/framework/cache` 등에서 Permission denied 가 발생하면 아래 명령을 수동 실행해 주세요 — 7.0.0-beta.2 에서 올라오는 경로에서는 해당 없음
  - `php artisan core:execute-upgrade-steps --from=7.0.0-beta.2 --to=7.0.0-beta.3 --force`
- 시스템 레벨에서도 일관된 그룹 공유 권한을 원하면 php-fpm pool 설정에 `umask = 002` (또는 systemd unit 의 `UMask=0002`) 추가를 권장합니다. 코드 레벨 동조와 병행하면 외부 프로세스(cron, composer 등) 도 동일 권한으로 파일을 생성합니다

## [7.0.0-beta.2] - 2026-04-20

### Added

#### 알림 시스템 재설계

- 알림 시스템을 Definition(정의) × Template(채널별 템플릿) × Recipients(수신자) 3계층으로 재설계
- 채널별 독립 수신자 설정 — trigger_user, related_user, role, specific_users 4종 타입 지원
- 채널 Readiness 검증 — 미설정 채널(SMTP 미구성 등)의 발송을 사전 차단
- 알림 발송 공통 디스패처 — 채널별 독립 발송, 발송 전후 훅 자동 실행, 모든 채널 자동 로깅
- 알림 클릭 URL 지원 — database 채널 알림에 클릭 시 이동할 URL 패턴 설정 가능
- 사용자/관리자 알림 전체 삭제 API 추가
- 비밀번호 변경 시 `password_changed` 알림 자동 발송
- 코어 권한에 사용자 알림 전용 카테고리 분리 (관리자/사용자 권한 의미 분리)
- 알림 정의 일괄 초기화 기능 — 커스터마이징된 알림의 모든 채널 템플릿을 기본값으로 복원 (확인 모달 + 스피너 UX 포함)
- 모듈/플러그인이 자신의 기본 알림 정의를 코어 리셋 로직에 제공할 수 있는 필터 훅 제공
- 템플릿 편집 시 편집 상태가 자동 추적되어 리셋 버튼이 자동 노출
- 알림 정의/템플릿 응답에 편집/삭제 권한 정보(abilities) 포함으로 UI가 권한에 따라 자동 조정

#### Vendor 번들 시스템

- Composer 실행 불가 환경(공유 호스팅 등)을 위한 vendor 번들 시스템 추가
- vendor-bundle Artisan 커맨드 — 빌드, 검증, 일괄 처리
- 모듈/플러그인 설치·업데이트에 `--vendor-mode=auto|composer|bundled` 옵션 추가
- 인스톨러 Step 3에 Vendor 설치 방식 선택 UI 추가 (환경 자동 감지)

#### GeoIP 및 타임존

- MaxMind GeoLite2 자동 다운로드 스케줄러 + 관리자 환경설정 UI 추가
- IANA 타임존 전체(약 425개) 지원 — 기존 7개 하드코딩 화이트리스트 폐기 (#8 @abc101 님께서 건의해주셨습니다.)
- Select 컴포넌트에 `searchable` prop 추가 — 타임존 등 대량 옵션에서 검색 가능

#### 인스톨러 개선

- SSE/폴링 듀얼 모드 지원 — Nginx 프록시 + Apache 환경에서 SSE 문제 시 폴링 모드로 전환 가능
- 확장 의존성 자동 해결 — 템플릿 선택 시 필요한 모듈/플러그인을 즉시 자동 선택, 전이적 의존성까지 해결 (#10 @glitter-gim 님께서 건의해주셨습니다.)
- 자동 선택된 항목을 시각적으로 구분하고 요구한 확장 이름을 함께 표시
- 다른 확장이 의존하는 항목은 선택 해제 차단 (의존 관계 안내 메시지 포함)
- 의존성 버전 제약 사전 검증 — 버전 불일치 시 설치 진행 전 경고 (semver 비교: `>=`, `^`, `~` 등 지원) (#3 @laelbe 님께서 제보해주셨습니다.)
- 기존 DB 테이블 감지 및 안전한 재설치 지원 — 백업 안내 + 명시적 동의 후 진행 (#5 @laelbe 님께서 건의해주셨습니다.)
- 권한 안내 단순화 — `chmod -R 755` 단일 명령어로 통합 (업계 표준 정렬)
- 소유자 불일치(`ownership_mismatch`) 감지 — 전통적 Apache 환경에서 3가지 해결 옵션 제시
- Step 5에 "설치 시작" 버튼 도입 — 모드 선택 후 사용자 클릭으로 설치 시작

#### 공통 캐시 시스템

- 코어/모듈/플러그인 격리 캐시 시스템 도입 (접두사 자동 부여)
- 모델 변경 시 태그 기반 자동 캐시 무효화 트레이트 추가
- 환경설정에서 캐시 TTL 중앙 관리 (7개 설정 키)

#### 어드민 UI

- 페이지 진입/탭 전환 시 로딩 spinner 표시 — 데이터 fetch 완료까지 유지 (#6 @laelbe 님께서 제보해주셨습니다.)
- 목록 페이지네이션 시 DataGrid body 영역 한정 spinner (pagination 버튼 가림 방지)
- DataGrid 컴포넌트에 `id` prop 추가

#### 기타

- HookManager 공통 큐 디스패치 — 환경설정 하나로 모든 훅 리스너 자동 비동기 실행, 큐 워커에서도 Auth/Request/Locale 컨텍스트 자동 복원
- WebSocket 클라이언트/서버 endpoint 분리 설정 지원 — 리버스 프록시 환경 대응, SSL 검증 옵션 추가
- SEO 변수 시스템 — 모듈/플러그인이 페이지별 SEO 변수를 제공할 수 있도록 개선
- 코어 드라이버 확장 시스템 — 플러그인이 필터 훅으로 스토리지/캐시/세션/큐 등 드라이버를 등록 가능
- `HasUserOverrides` 트레이트 — 시더 데이터와 사용자 수정을 자동으로 분리 추적
- 템플릿 엔진 engine-v1.42.0 — `render: false` 선택적 리렌더 제어
- 템플릿 엔진 engine-v1.41.0 — setLocal/dispatch debounce API + stale 값 오염 방지
- 템플릿 엔진 engine-v1.40.0 — navigate fallback 옵션 (교차 템플릿 경로 대응)
- 템플릿 엔진 engine-v1.30~38 — sortable wrapper 요소 지정, React.memo 자동 래핑, resolvedProps 참조 안정화, transition_overlay spinner 시스템, reloadExtensions 통합 핸들러

#### 확장 업데이트

- 코어 업데이트 완료 후 `_bundled` 에 새 버전이 있는 확장을 감지하고 일괄 업데이트 여부를 묻는 인터랙티브 프롬프트 제공
- 일괄 업데이트 시 전역 레이아웃 전략(overwrite / keep) 1회 질의 후 확장별 예외 지정 가능
- 모듈/플러그인 업데이트에도 `--layout-strategy=overwrite|keep` CLI 옵션 및 관리자 UI 모달 선택 지원 (기존 템플릿 전용 → 확장 전체 일관)
- 관리자 모듈/플러그인 업데이트 모달에 사용자가 수정한 레이아웃 감지 및 목록 표시 기능 추가
- 모듈/플러그인 업데이트 시 각 upgrade step 버전을 콘솔에 실시간 출력 (기존에는 파일 로그에만 기록)
- 확장 업데이트 메서드 파라미터 순서를 3종(템플릿·모듈·플러그인) 공통 prefix(id, force, onProgress) 로 정렬해 일관성 확보
- 모듈/플러그인이 런타임에 동적으로 생성한 권한·역할·메뉴(예: 게시판별 권한 세트)도 업데이트 시 보존되도록 개선
- 모듈/플러그인/템플릿 업데이트 커맨드에 업데이트 소스 선택 옵션(`--source=auto|bundled|github`) 추가 — GitHub 원격 장애·태그 롤백 등 상황에서 번들 소스로 강제 설치 가능
- GitHub 원격에 존재하지 않는 태그로 업데이트 시 "아카이브 추출 실패" 로 불명확한 오류가 나던 문제 개선 — 태그 존재 여부를 먼저 검증해 "업데이트 소스 없음" 안내로 명확화
- 관리자 모듈/플러그인 업데이트 모달의 _global 상태키를 `hasModifiedTemplateLayouts`, `hasModifiedModuleLayouts`, `hasModifiedPluginLayouts` 로 통일해 네이밍 충돌 방지
- `module:install` / `plugin:install` / `template:install` 에 `--force` 옵션 추가 — 이미 설치된 확장도 `_bundled`/`_pending` 원본으로 활성 디렉토리를 덮어써서 재설치 (불완전 설치 복구용)
- 확장 활성 디렉토리 무결성 검사 추가 — `module.php`/`module.json` (모듈), `plugin.php`/`plugin.json` (플러그인), `template.json` (템플릿) 중 하나라도 누락된 활성 디렉토리를 로드 시 경고 로그 + `install --force` 복구 힌트 제공
- 코어 업데이트 커맨드에 외부 ZIP 파일 직접 지정 옵션(`--zip=/path/to/g7.zip`) 추가 — GitHub 다운로드 대신 지정 ZIP 을 사용해 오프라인/수동 배포 가능
- 모듈/플러그인/템플릿 업데이트 커맨드에 외부 ZIP 파일 직접 지정 옵션(`--zip=/path/to/ext.zip`) 추가 — manifest 식별자와 대상 확장이 일치하는지 검증 후 manifest 의 버전으로 업데이트
- 외부 ZIP 은 GitHub 릴리스 zipball 의 래퍼 디렉토리와 평탄 루트 구조를 모두 자동 감지해 지원

### Changed

#### 알림 시스템

- MailTemplate 시스템을 NotificationDefinition + NotificationTemplate 체계로 전면 전환
- 코어 알림 3종의 직접 발송을 훅 기반 발송으로 전환
- 알림 수신자를 definition 레벨에서 template 레벨로 이동 (채널별 독립 수신자)
- 알림 broadcast 채널을 정수 User ID에서 UUID 기반으로 변경 (보안 강화)
- 알림 시각 표시를 ISO 8601에서 사용자 타임존 기준으로 변경

#### 캐시 시스템

- 24개 서비스의 캐시 호출을 `Cache::` 파사드에서 `CacheInterface` DI로 전환
- 13개 서비스의 TTL을 환경설정 중앙 관리로 통일 (하드코딩 제거)
- 캐시 키 접두사를 `g7:core:`, `g7:module.{id}:`, `g7:plugin.{id}:` 체계로 통일
- `CacheService` 클래스 삭제 — `CacheInterface`로 완전 대체

#### 인스톨러

- 권한 검증을 비트 값 비교에서 실제 읽기/쓰기 가능 여부 기반으로 단순화
- `.env` 생성 안내를 소유자 일치 여부에 따라 1-step으로 통합

#### 기타

- 확장 정보 모달에 의존성 정보 노출 — 코어 요구 버전(`g7_version`), 의존 모듈/플러그인 목록과 각각의 요구 버전·설치 버전·충족 상태 뱃지를 모듈/플러그인/템플릿 정보 모달에서 확인 가능
- `locale_names` 설정 추가 — 언어 표시명을 중앙에서 관리
- 매 요청 `Schema::hasTable()` 호출 제거 — 설치 완료 환경에서 10~14회의 불필요한 스키마 쿼리 제거로 응답 시간 단축
- SeoRenderer에 모듈/플러그인 SEO 변수 자동 해석 기능 추가
- ResponseHelper — 디버그 모드에서 예외 상세 자동 포함, 프로덕션에서 내부 메시지 노출 차단
- 환경설정 드라이버 변경 시 큐 워커 자동 재시작
- `allow_url_fopen=Off` 환경 지원 — GitHub 연동 HTTP 호출을 Laravel Http 파사드로 전면 교체
- 코어 업데이트 완료 후 안내 메시지를 "수동 업데이트 명령 나열"에서 "일괄 업데이트 인터랙티브 프롬프트"로 변경
- 확장 삭제 시 권한·메뉴·역할은 "데이터도 함께 삭제" 옵션을 체크했을 때만 삭제되도록 변경 — 기본 삭제에서는 보존되어 재설치 시 사용자 역할 할당이 복원

### Fixed

- WebSocket 데이터소스가 progressive 목록에 포함되어 영구 블러되던 문제 수정
- WebSocket 채널/이벤트 표현식 미평가로 인증 실패하던 문제 수정
- WebSocket broadcast HTTP API가 클라이언트 host로 POST되어 실패하던 문제 수정
- WebSocket 활성화 시 필수 필드 미검증 문제 수정
- WebSocket 비활성화 시에도 Reverb 연결을 시도하던 문제 수정
- `--force` 업데이트 시 번들 없는 외부 확장이 업데이트 소스를 찾지 못하던 문제 수정
- 확장 의존성 데이터 구조 불일치 수정 — manifest JSON 기반으로 통일
- 모듈/플러그인/템플릿 install/activate/deactivate/uninstall 직후 라우트 미반영 수정
- 다국어 번역 캐시 경합으로 raw key 노출되던 문제 수정 (engine-v1.38.1)
- 조건부 `$t:` 표현식에서 번역 실패하던 문제 수정 (engine-v1.38.2)
- 테스트 환경과 개발 환경이 동일한 Redis 캐시를 공유하여 상호 오염되던 문제 수정
- 대시보드 브로드캐스트 스케줄이 무한 락에 걸리던 문제 수정
- 환경설정 저장 후 `config:cache` 상태에서 변경값 미반영 수정
- Windows 환경에서 파일 잠금 감지 시 30초+ hang 수정 — 0.5초로 단축
- 개발 의존성 없이 설치된 프로덕션 환경에서 샘플 시더와 팩토리가 실행되지 않던 문제 수정 — 한국어 샘플 데이터 생성기가 자동으로 대체 동작하도록 개선
- `sudo php artisan core:update` 실행 시 vendor 디렉토리 전체가 root 소유로 오염되던 문제 수정
- 코어 업데이트 후 신규 권한·메뉴가 DB에 반영되지 않아 관리자 환경설정 페이지 접근이 거부되던 문제 수정
- beta.1에서 업그레이드 시 제거된 메일 템플릿 모델 참조로 Fatal 오류가 발생하던 문제 수정
- 업그레이드 후 `bootstrap/cache` 등 일부 디렉토리가 root 소유로 남아 확장 설치·업데이트가 거부되던 문제 수정 — 소유권 복원 범위를 설치 안내 경로 전체로 확장
- 코어 업데이트 진행률 표시와 upgrade step 실행 로그가 같은 줄에 뒤섞이던 문제 수정 — 각 step 안내가 별도 줄로 깔끔하게 출력됨
- 템플릿/레이아웃 공개 API가 확장 활성화 전·설치 중에 받은 "찾을 수 없음" 에러 응답을 영구 캐시하여 복구 후에도 같은 오류를 반환하던 문제 수정 — 에러 응답은 캐시에서 제외
- `_pending`/`_bundled` 에 업데이트 중 남은 임시 디렉토리(`{id}_YYYYMMDD_HHMMSS`, `{id}_updating_*` 등) 의 원본 manifest 때문에 `install` 이 존재하지 않는 표준 경로로 접근해 실패하던 문제 수정 — 디렉토리명과 identifier 불일치 시 스캔에서 제외
- 업그레이드 후 일부 코어 권한이 DB에 반영되지 않아 사용자 역할의 알림 기능 권한이 비어있던 문제 수정 — 별도 프로세스 실행으로 최신 로직 기반 재동기화 보장
- 코어·확장 설정에서 제거된 메뉴·권한·역할·알림 정의·알림 템플릿·게시판 유형·클레임 사유가 업데이트 후에도 DB에 잔존하던 문제 수정 — 고아 레코드 자동 정리
- 관리자 UI에서 메뉴·역할·알림·게시판 유형·클레임 사유·배송 유형 등을 수정해도 다음 업데이트 시 기본값으로 덮어써지던 문제 수정 — 사용자가 수정한 필드는 모든 저장 경로에서 자동 추적되어 업데이트 후에도 보존
- 알림 정의·템플릿도 업데이트 기준으로 동기화 — 새 버전에서 제거된 알림은 DB에서도 삭제
- 관리자 모듈/플러그인 목록의 "작성자" 표기가 manifest 의 `vendor` 필드 대신 식별자 prefix 를 사용하던 문제 수정 — `vendor` 가 정의된 확장은 정확한 작성자명으로 표시 (#9 @glitter-gim 님께서 제보해주셨습니다.)

### Removed

- MailTemplate 시스템 일괄 제거 — 모델, 컨트롤러, 서비스, 리스너, 시더, 팩토리, 다국어 파일 등. NotificationDefinition + NotificationTemplate 체계로 완전 대체

> 참고: beta.1에서 업그레이드하는 운영 환경의 데이터 이관은 `Upgrade_7_0_0_beta_2`가 자동 처리합니다.

## [7.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [7.0.0-alpha.21] - 2026-03-30

### Added

- 인스톨러 PHP CLI/Composer 필수 검증 기능 — 기본 `php` 미감지 시 CLI 설정 필수 전환, Composer 실행 확인 및 미설치 시 설치 안내, 둘 다 검증 완료 전 다음 단계 진행 차단
- 템플릿 레이아웃 수정 감지 시스템 — `original_content_hash`/`original_content_size` 컬럼 추가, SHA-256 해시 기반 수정 감지로 `updated_by` 방식 대체 (TemplateManager, LayoutRepository, 마이그레이션)
- 관리자 로그인 화면 개선 — 비밀번호 찾기/재설정 플로우 추가, 테마 Segmented Control, 언어 셀렉트 확대 (PasswordResetController, PasswordResetNotification, ForgotPasswordRequest, ResetPasswordRequest)
- `{{raw:expression}}` 바인딩 번역 면제 마커 시스템 — `$t:` 자동 번역 대상에서 특정 바인딩을 제외하는 마커 도입 (rawMarkers.ts)
- 레이아웃 프리뷰 모드 — 관리자 레이아웃 편집기에서 실시간 미리보기 지원 (LayoutPreviewController, LayoutPreviewService, 마이그레이션)
- 활동 로그 이력 조회 메뉴 신설 — ActivityLogController, ActivityLogResource, ActivityLogService, ActivityLogRepository, config/core.php 메뉴/권한 등록, 관리자 레이아웃 추가

### Changed

- `window.G7Config` 설정 노출 최소화 Phase 1 — 프론트엔드 미참조 설정의 브라우저 소스 노출 차단
  - SettingsService: 필드 레벨 `expose: false` 지원 추가 (formatCategorySettings)
  - FiltersFrontendSchema: `fields: {}` (빈 객체) 시 전체 차단 안전 기본값 적용
  - View Composers: TemplateComposer/UserTemplateComposer에 ModuleSettingsService 주입 — frontend_schema 기반 필터링 적용 (기존 config() 직접 참조 우회 수정)
  - 코어 defaults.json: general 4개 필드, security 전체, seo 전체, advanced 9개 필드(debug_mode 제외), upload 2개 필드, drivers 전체를 `expose: false` 처리
- 캐시 무효화 로직 정비 — 016862cb 이전의 단순한 방식으로 복귀 (버전 증가 + TTL 자연 만료), `extension_cache_previous_versions` 추적 및 `getCacheVersionsToInvalidate()`/`getCacheVersionsForLayoutInvalidation()` 제거, Cache Tags 드라이버 분기 제거로 캐시 드라이버 비의존성 확보

### Fixed

- MariaDB를 `DB_CONNECTION=mysql` 드라이버로 연결 시 `WITH PARSER ngram` 에러로 인스톨러 설치 실패 수정 — `isMariaDb()` 메서드 추가하여 서버 버전 문자열 기반 실제 DBMS 감지 (DatabaseFulltextEngine)
- 캐시 무효화 버전 키 누락 수정 — `warmTemplateCache()`가 버전 포함 키(`.v{version}`)로 캐시를 생성하나 `clearTemplateCache()`가 레거시 키만 삭제하던 문제, routes/language 캐시에 버전 포함 키 삭제 추가
- 캐시 버전 0 무효화 누락 수정 — `array_filter()`가 버전 `0`을 falsy로 제거하여 `.v0` 캐시 키가 삭제 대상에서 누락되던 버그 (ClearsTemplateCaches, InvalidatesLayoutCache)
- 테스트 활성 디렉토리 보호 강화 — `ProtectsExtensionDirectories`에 `moveDirectory` spy 추가, `copyToActive()` 원자적 교체가 활성 디렉토리를 rename으로 파괴하는 것 방지
- 활동 로그 하위 호환 — `ActivityLogResource`에서 기존 DB 레코드(`type: 'text'`)의 enum 필드를 모델의 현재 `$activityLogFields` 기준으로 동적 번역 + 일괄 변경 `:count` 미치환 보정
- 활동 로그 enum 미번역 전수 수정 — 14개 모델 필드의 `$activityLogFields` `type: 'enum'` 전환 + 14개 Enum 클래스에 `labelKey()` 메서드 추가 (User, Order, OrderOption, Product, Coupon, Post, Comment, Board)
- 활동 로그 삭제/일괄삭제 `description_key` 및 샘플 시더 누락 추가 — 코어 시더 `activity_log_descriptions` 테이블에 delete/bulk_delete 키 보충 + 다국어 파일 동기화
- 관리자 다크모드 품질 전수 조사 및 수정 — 레이아웃 JSON ~546건, TSX ~37건, CSS 5건의 누락된 `dark:` variant 추가 (이커머스 101파일, 게시판 19파일, 페이지 2파일, 마케팅 1파일, 템플릿 컴포넌트 ~20파일)
- 레이아웃 편집 캐시 무효화 및 버전 히스토리 수정 — LayoutService의 저장/복원 시 PublicLayoutController 서빙 캐시 무효화 + `extension_cache_version` 증가 누락 수정
- 사용자 관리에서 관리자/슈퍼관리자 삭제 시 토스트 메시지 및 어빌리티 처리 — CannotDeleteAdminException 추가, UserResource에 `can_delete` 어빌리티 반영
- ActivityLog 핸들러 `$result` 타입 불일치 수정 — CoreActivityLogListener에서 `bool` → `array` 반환 타입 정합성 보정
- 환경설정 사이트 로고 저장 시 Attachment 객체 정수 검증 오류 수정 — SaveSettingsRequest에서 Attachment JSON 객체를 정수로 검증하던 문제 수정
- 관리자 SPA 네비게이션 로고 깜빡임 수정 — extends base 컴포넌트 불필요 remount 방지 (`_fromBase` 마킹 기반 stable key 패턴)
- 인스톨러 다크모드 적색 배경 가독성 개선 — `alert-title`, `alert-message`, `permission-badge`, `test-result` 다크모드 색상 override 추가
- 인스톨러 `BASE_PATH` 심볼릭 링크 미해석 — `realpath()` 적용 + 절대경로/상대경로 병기로 호스팅 환경 대응
- 인스톨러 403 에러 페이지 다크모드 미지원 — `prefers-color-scheme: dark` 미디어쿼리 추가
- 템플릿 업데이트 수정 감지 실패 — `LayoutRepository::update()`가 `updated_by`를 설정하지 않아 `hasModifiedLayouts()`가 항상 "수정 없음" 반환하던 문제 수정 (hash 비교 방식으로 전환)
- 템플릿 업데이트 "수정 유지" 전략 미작동 — `layoutStrategy === 'keep'` 시 `refreshTemplateLayouts()` 미호출 → 양쪽 전략 모두 호출하되 `preserveModified` 플래그로 분기
- 템플릿 업데이트 API 응답 키 불일치 — 백엔드 `has_modified` → 프론트엔드 기대 `has_modified_layouts`/`modified_count` 키 정렬
- 템플릿/모듈/플러그인 설치 시 `incrementExtensionCacheVersion()` 누락 수정 — TemplateManager(install/activate/deactivate/uninstall) + ModuleManager(install) + PluginManager(install) 총 7곳 추가
- 캐시 버전 변경 시 프론트엔드 다국어 미갱신 수정 — TemplateApp.ts에서 캐시 버전 변경 감지 시 TranslationEngine 재로드 추가
- `warmTemplateCache()` 다국어 캐시 워밍 시 `$partial` 디렉티브 미해석 수정 — `json_decode`만 수행하던 코드를 `TemplateService::getLanguageDataWithModules()` 호출로 교체하여 fragment 해석 및 모듈/플러그인 다국어 병합 정상화
- `ActivateTemplateCommand` 의존성 미충족 시 성공으로 보고하는 버그 수정 — `if ($result)` → `if ($result['success'])` 변경 및 의존성 경고 메시지 출력, `--force` 옵션 추가
- `ActivateModuleCommand`/`ActivatePluginCommand` 의존성 미충족 시 경고 메시지 미출력 수정 — 의존성 경고 표시 및 `--force` 옵션 전달 추가

## [7.0.0-alpha.20] - 2026-03-30

### Added

- Laravel Scout 통합 및 MySQL FULLTEXT(ngram) 검색엔진 드라이버 확장 시스템 도입 — 커스텀 `DatabaseFulltextEngine`으로 `MATCH...AGAINST IN BOOLEAN MODE` 지원, `core.search.engine_drivers` 필터 훅으로 Meilisearch/Elasticsearch 등 외부 엔진 플러그인 등록 가능
- `DatabaseFulltextEngine` 다중 DBMS 호환 — FULLTEXT 미지원 DBMS(PostgreSQL, SQLite)에서 LIKE fallback 자동 전환, `whereFulltext()` 정적 헬퍼(관계 검색용), `addFulltextIndex()` 마이그레이션 헬퍼(DBMS별 조건부 DDL)
- `config/scout.php` 설정 파일 추가 — 기본 드라이버 `mysql-fulltext`, `SCOUT_DRIVER` 환경변수로 전환 가능
- `FulltextSearchable` 인터페이스 — FULLTEXT 검색 대상 컬럼 및 가중치 정의 계약
- `AsUnicodeJson` 커스텀 캐스트 — JSON 컬럼에 한글을 `\uXXXX` 이스케이프 없이 실제 UTF-8로 저장하여 FULLTEXT ngram 토크나이저 정상 동작 보장
- 환경설정 > 드라이버 탭에 검색엔진 설정 카드 추가 — 기본 MySQL FULLTEXT(ngram) 드라이버 표시, 플러그인 설치 시 추가 드라이버 자동 표시
- `SaveSettingsRequest`에 검색엔진 드라이버 동적 validation 추가 — `core.search.engine_drivers` 필터 훅 기반 허용 목록

## [7.0.0-alpha.19] - 2026-03-29

### Added

- 검색/필터/정렬 성능 향상을 위한 누락 인덱스 일괄 추가 — activity_logs(description_key), users(created_at), mail_send_logs(status), template_layouts(template_id), schedules(created_at)

## [7.0.0-alpha.18] - 2026-03-26

### Added

- SEO ExpressionEvaluator 산술 연산자 확장 — `*`, `/`, `%` 추가 (기존 `+`, `-`만 지원)
- SEO PipeRegistry 파이프 함수 엔진 구현 — 프론트엔드 PipeRegistry.ts 빌트인 파이프 15종 PHP 미러링 (date, datetime, relativeTime, number, truncate, uppercase, lowercase, stripHtml, default, fallback, first, last, join, length, filterBy, keys, values, json, localized)
- ActivityLog `description_params` ID→이름 변환 필터 훅 (`core.activity_log.filter_description_params`) — `ActivityLog::getLocalizedDescriptionAttribute()`에서 실행
- 코어 모델 `$activityLogFields` 정의 — `User`, `Role`, `Menu`, `Schedule`, `MailTemplate` (5개 모델)
- ActivityLog ChangeDetector 필드 라벨 다국어 키 추가 (`lang/ko/activity_log.php`, `lang/en/activity_log.php` — `fields` 섹션)
- `module_helpers.php` — `getModuleSetting()` 헬퍼 함수 추가
- CoreActivityLogListener: bulk_update per-User/per-Schedule 전환 + bulk_delete per-Schedule 전환 (건별 loggable_id 기록)
- ActivityLogHandler: 삭제된 엔티티용 loggable_type/loggable_id 직접 지정 fallback 지원
- 메뉴 관리 크로스 depth 이동 지원 — `UpdateMenuOrderRequest`에 `moved_items` 검증 추가, `MenuRepository`에 크로스 depth reorder 로직 구현
- `NotCircularParent` 검증 규칙 추가 — 메뉴 순환 참조 방지

### Changed

- ActivityLog 규정 문서 대폭 보강 (`docs/backend/activity-log.md`) — `description_params` 저장 정책, `ActivityLogDescriptionResolver` 패턴, Bulk Update ChangeDetector 패턴, 개발자 체크리스트 추가

## [7.0.0-alpha.17] - 2026-03-26

### Added

- ActivityLog `description_params` ID→이름 변환 필터 훅 (`core.activity_log.filter_description_params`) — `ActivityLog::getLocalizedDescriptionAttribute()`에서 실행
- 코어 모델 `$activityLogFields` 정의 — `User`, `Role`, `Menu`, `Schedule`, `MailTemplate` (5개 모델)
- ActivityLog ChangeDetector 필드 라벨 다국어 키 추가 (`lang/ko/activity_log.php`, `lang/en/activity_log.php` — `fields` 섹션)
- `module_helpers.php` — `getModuleSetting()` 헬퍼 함수 추가
- CoreActivityLogListener: bulk_update per-User/per-Schedule 전환 + bulk_delete per-Schedule 전환 (건별 loggable_id 기록)
- ActivityLogHandler: 삭제된 엔티티용 loggable_type/loggable_id 직접 지정 fallback 지원

### Changed

- ActivityLog 규정 문서 대폭 보강 (`docs/backend/activity-log.md`) — `description_params` 저장 정책, `ActivityLogDescriptionResolver` 패턴, Bulk Update ChangeDetector 패턴, 개발자 체크리스트 추가

## [7.0.0-alpha.17] - 2026-03-26

### Added

- Monolog 기반 ActivityLog 아키텍처: `Log::channel('activity')` → `ActivityLogHandler` → DB (3단계)
- `ActivityLogChannel` (커스텀 Monolog 채널), `ActivityLogHandler`, `ActivityLogProcessor` 신규
- ActivityLog i18n 지원: `description_key` + `description_params` 기반 실시간 다국어 번역
- ActivityLog 구조화된 변경 이력: `changes` JSON 컬럼 (필드별 `label_key`, `old`/`new`, `type` 포함)
- `ChangeDetector` 유틸리티 (모델 스냅샷 비교 → 구조화된 변경 이력 생성)
- `CoreActivityLogListener` 전면 확장: 모든 코어 Service 훅 구독 (User/Role/Menu/Settings/Schedule/Auth/Module/Plugin/Template/Layout/MailTemplate/Attachment — 66개 훅)
- 활동 로그 다국어 키 105개 정의 (`lang/ko/activity_log.php`, `lang/en/activity_log.php`)
- `config/logging.php`에 `activity` 채널 추가
- `config/activity_log.php` 전용 설정 파일 신규
- `activity_logs` 테이블 복합 인덱스 추가 (`loggable_type`+`loggable_id`+`created_at`, `log_type`+`action`+`created_at`)

### Fixed

- `resolveLogType()` 사용자 역할 기반 → 요청 경로 기반으로 변경: 관리자가 사용자 화면에서 수행한 액션이 `admin`으로 기록되던 문제 수정 (ResolvesActivityLogType)

### Changed

- `ActivityLog` 모델: `description` 컬럼 삭제 → `description_key`/`description_params` 기반 다국어 전환
- `ActivityLogService`: 기록 메서드 전면 제거 → 조회 전용으로 축소
- `ActivityLogResource`: `description` → `localized_description` (실시간 번역)
- 모든 Controller에서 `logAdminActivity()` 호출 전면 제거 → Listener 경로로 전환

### Removed

- `activity_logs.description` 컬럼 (DB 삭제)
- `ActivityLogManager`, `ActivityLogDriverInterface`, `DatabaseActivityLogDriver`, `NullActivityLogDriver`
- `ActivityLogListener` (이중 훅 계층 — Monolog Handler로 대체)
- `ActivityLogService.log`/`logAdmin`/`logUser`/`logSystem` (Monolog 채널로 대체)
- `AdminBaseController.logAdminActivity()`, `generateActivityDescription()`, `flattenDataForTranslation()`

### Fixed

- 마이페이지 프로필 저장 시 국가·언어 미선택 상태에서 오류가 발생하던 문제 수정 — 해당 필드를 선택 사항으로 변경
- 인스톨러 Step 2 .env 복사 명령어 안내 수정 — `.env.example.production` → `.env.example` (functions.php 2곳, installer.js 4곳)

## [7.0.0-alpha.16] - 2026-03-23

### Fixed

- 코어 업데이트 롤백 시 vendor 디렉토리 복원 불가 수정 — 백업 targets에 vendor 포함, excludes에서 vendor 제거
- 코어 백업 복원 시 개별 target 실패가 전체 복원을 중단하는 문제 수정 — 개별 try-catch로 나머지 target 복원 계속 진행
- 코어 업데이트 롤백 실패 시 수동 복구 안내 미출력 수정 — composer install 등 복구 단계 안내 추가
- 코어 업데이트 완전 복원 성공 시 유지보수 모드 자동 해제 추가
- 코어 업데이트 시 vendor 디렉토리 이중 처리로 인한 마이그레이션 실패 수정 — `backup_only` 설정 분리 (applyUpdate 제외, 백업/복원 전용)

## [7.0.0-alpha.15] - 2026-03-23

### Added

- Users UUID 전환 — 외부 노출 ID를 UUID v7으로 전환, 정수 `id`는 API 응답에서 숨김
- UniqueIdService 코어 서비스 추가 (UUID v7 + NanoID 생성)
- 코어 업그레이드 스텝 추가 (Upgrade_7_0_0_beta_15)

### Changed

- User 모델: `getRouteKeyName()` → 'uuid', `$hidden`에 'id' 추가
- 공개 프로필 API: Route Model Binding 전환 (`{userId}` → `{user}`)
- 사용자 벌크 상태변경: 정수 ID → UUID 기반
- API Resource: user.id → user.uuid 전환 (UserResource, UserCollection 외 7개)
- Activity Log 메타데이터: user_id → uuid 전환
- FormRequest: 정수 검증 → UUID 검증 전환

### Fixed

- `_global.currentUser?.id` → `?.uuid` 전환 (sirsoft-basic 템플릿 12개 파일)
- 글쓰기 버튼 abilities 비활성화 누락 수정
- 코어 업그레이드 스텝 raw SQL 테이블 프리픽스 미적용 수정 — `UpgradeContext::table()` 헬퍼 추가 (Upgrade_7_0_0_beta_15)

## [7.0.0-alpha.14] - 2026-03-20

### Fixed

- 확장 업데이트 시 임시 디렉토리(`_updating_*`, `_old_*`) 오토로드 오염 방지 — 임시 디렉토리를 `_pending/` 하위에 생성하여 IDE 잠금 등으로 잔존 시에도 Fatal Error 방지 (ExtensionPendingHelper)

### Added

- Windows 파일잠금 감지/해제 기능 — 확장 업데이트 시 IDE 등이 파일 핸들을 보유한 경우 자동 감지 및 해제 시도 (FileHandleHelper, ExtensionPendingHelper)

- SEO 렌더러 훅 시스템: `core.seo.filter_context`, `core.seo.filter_meta`, `core.seo.filter_view_data` — 확장이 SEO 렌더링 파이프라인에 런타임 데이터 변환으로 개입 가능
- `seo.blade.php` 확장 슬롯: `extraHeadTags` (`</head>` 직전), `extraBodyEnd` (`</body>` 직전) — `filter_view_data` 훅을 통해 커스텀 스크립트/스타일 주입
- `ComponentHtmlMapper` pagination 렌더 모드 — Pagination 컴포넌트에서 SEO용 페이지 링크 자동 생성 (currentPage/totalPages props 기반)
- `ComponentHtmlMapper` text_format dot notation 지원 — `{author.nickname}` 형태로 객체 prop의 중첩 필드 접근
- `ExpressionEvaluator::evaluateRaw()` — 표현식 결과를 원본 타입(배열 등)으로 반환하는 메서드
- `SeoRenderer` SEO 컨텍스트에 `_global`/`_local` 빈 객체 추가 — 프론트엔드 전용 상태 참조 시 null 대신 빈 객체 제공
- `ComponentHtmlMapper` fields 렌더 모드 — 컴포지트 컴포넌트(ProductCard 등)의 객체 prop에서 SEO용 HTML 필드 자동 생성 (조건부/반복/속성 기반)
- `SeoRenderer` seoVars 주입 — `meta.seo.vars` 선언을 해석하여 ComponentHtmlMapper format 모드에서 `{key}` 플레이스홀더 치환
- `ExpressionEvaluator` 리터럴 값 감지 — 숫자, 문자열, boolean, null/undefined 리터럴을 경로 해석 없이 직접 반환
- `ExpressionEvaluator` $t: 파라미터 `{{}}` 표현식 해석 — 번역 키 파라미터 값에 포함된 바인딩 표현식을 컨텍스트에서 평가
- `TemplateManager` seo-config 검증에 `fields` 타입 추가
- `ExpressionEvaluator` seo_overrides — seo-config.json에서 `_local`/`_global` 상태 오버라이드 선언 (와일드카드 매칭으로 접혀있는 콘텐츠 SEO 강제 펼침)
- `TemplateManager` seo-config 검증에 `pagination` 타입 및 `seo_overrides` 검증 추가
- `SeoConfigMerger` — 모듈/플러그인/템플릿의 seo-config.json을 수집·병합하는 동적 확장 시스템 (우선순위: 모듈 → 플러그인 → 템플릿, 24시간 TTL 캐싱)
- `SeoRenderer` `_global` 컨텍스트 주입 — SettingsService/PluginSettingsService 프론트엔드 설정을 `_global`에 주입 + `initGlobal` 매핑으로 데이터소스 응답을 `_global` 경로에 바인딩
- `AbstractModule`/`AbstractPlugin` SEO 기여 메서드 — `getSeoConfig()`, `getSeoDataSources()` 인터페이스 추가
- `ModuleManager`/`PluginManager`/`TemplateManager` install/activate/update 시 훅 발행 추가 — Artisan 커맨드에서도 SEO 캐시 자동 무효화 보장
- SEO Artisan 커맨드 다국어 파일 추가 (`lang/ko/seo.php`, `lang/en/seo.php`)
- `ComponentHtmlMapper` fields 모드 `$all_props` source — 모든 props 표현식을 해석하여 데이터 객체로 사용 (Header/Footer 등 다수 props 컴포넌트용)
- `ComponentHtmlMapper` fields 모드 `$t:` 번역 키 지원 — content 패턴에서 `$t:key` → 다국어 텍스트 렌더링
- `ComponentHtmlMapper` fields iterate `item_attrs` — 아이템별 동적 HTML 속성 (예: `{ "href": "/board/{slug}" }`)
- `ExpressionEvaluator` `evaluateRaw()` `??` null coalescing 지원 — 원본 타입(배열/객체) 유지하면서 null coalescing 수행
- 인스톨러 Windows 환경 명령어 대응 — `chmod`/`chown` 스킵, 미존재 디렉토리 안내 메시지 추가
- `ExpressionEvaluator` 삼항 연산자 (`a ? b : c`) — JS 우선순위 준수, `?.`/`??` 자동 구분, 중첩 우측 결합
- `ExpressionEvaluator` `$t()` 함수 호출 구문 — 삼항 내부에서 `$t('key')` 형태로 번역 키 사용 가능 (기존 `$t:key` 방식 확장)
- `ExpressionEvaluator` `$localized()` 전역 함수 — 다국어 객체에서 현재 로케일 값 추출 (`{ko: "상품", en: "Product"}` → `"상품"`)
- `ExpressionEvaluator` 객체 리터럴 파서 — `{key: value, ...obj, [dynamicKey]: value}` 구문 지원
- `ExpressionEvaluator` 스프레드 연산자 — 배열 `[...arr, item]` 및 객체 `{...obj, key: value}` 스프레드 지원
- `SeoRenderer` computed 속성 해석 — 레이아웃 `computed` 섹션을 `_computed`/`$computed`에 저장 (문자열 표현식 + `$switch` 형식)
- `ComponentHtmlMapper` classMap 지원 — `base`/`variants`/`key`/`default`로 조건부 CSS 클래스 선언적 적용

### Fixed

- Redis 캐시 DB가 환경설정 값(`REDIS_CACHE_DB`)을 따르도록 수정 — `config/database.php` cache 연결 DB 반영

- `ExpressionEvaluator` 배열 리터럴 파싱 지원 — `['gallery','card'].includes(...)` 표현식에서 배열 리터럴을 PHP 배열로 변환하여 `includes` 등 배열 메서드 정상 동작 (SEO 게시판 타입 분기 조건 중복 렌더링 수정)
- `ExpressionEvaluator` null 비교 JavaScript 시맨틱 적용 — `null !== value` → `true`, `null === null` → `true` (SEO 컨텍스트에서 `_global` 미존재 경로 비교 시 빈 문자열 대신 올바른 boolean 반환)
- `ExpressionEvaluator` $t: 번역에서 `{{param}}` 형식 파라미터 치환 지원 — 템플릿 번역 파일의 `{{param}}` + Laravel 표준 `:param` 형식 모두 처리
- `ExpressionEvaluator` 비교 연산 좌측 optional chaining 경로 타입 보존 — `?.` 포함 경로가 `evaluateExpression`으로 불필요 라우팅되어 boolean 타입이 문자열로 변환되던 문제 수정

## [7.0.0-alpha.13] - 2026-03-18

### Added

- `SeoCacheRegenerator` — 단건 URL 캐시 즉시 재생성 서비스 (다국어 로케일별 렌더링 + 캐시 저장)
- `SeoSettingsCacheListener` — 코어 SEO 설정 변경 시 전체 SEO 캐시 + 사이트맵 삭제

### Fixed

- `SeoMiddleware`에서 `put()` 대신 `putWithLayout()` 사용 — `invalidateByLayout()`이 레이아웃명 미저장으로 항상 0건 매칭되던 근본 버그 수정
- `SeoRenderer`에서 레이아웃명을 request attribute로 저장 — `putWithLayout()` 연동

## [7.0.0-alpha.12] - 2026-03-17

### Changed

- API 인증을 토큰 전용으로 전환 — 세션 기반 인증 의존 제거, Bearer 토큰 단일 방식으로 통일
- README 업데이트 — 표기 통일, 섹션 정비, 문서 링크 연결

## [7.0.0-alpha.11] - 2026-03-16

### Added

- 역할(Role) 상태 토글 API 추가 — `PATCH /api/admin/roles/{role}/toggle-status` 엔드포인트, 훅 지원 (`core.role.before_toggle_status`, `core.role.after_toggle_status`)
- RoleResource에 `can_toggle_status` ability 추가 — 역할별 토글 권한 제어
- RoleResource에 `extension_name` 필드 추가 — 확장 출처별 로케일 이름 표시
- 역할 생성 시 `identifier` 직접 입력 기능 추가 — 미입력 시 name 기반 자동 생성 유지
- 역할 관련 다국어 키 추가 (ko/en) — identifier 검증 메시지
- `replaceUrl` 핸들러 추가 — refetch 없이 URL만 변경 (페이지네이션 등 브라우저 히스토리 관리용)
- 사용자 검색 API에 `id` 파라미터 지원 추가 — 특정 사용자 ID로 직접 조회 가능
- `TimezoneHelper` 유틸리티 클래스 추가 — 사용자/서버 타임존 간 변환 헬퍼
- `HasSampleSeeders` 트레이트 추가 — 모듈/플러그인 시더에서 `--sample` 옵션으로 샘플 데이터만 분리 실행
- `ComponentRegistry.getComponentNames()` 메서드 추가 — 등록된 컴포넌트 이름 목록 조회

### Fixed

- 템플릿 에러 페이지에서 `:identifier`가 리터럴로 표시되는 버그 수정 — Blade `@` 이스케이프 처리
- ActionDispatcher onChange raw value fallback 제거 — 마운트/리렌더 시 의도치 않은 setState 실행으로 상품 폼 회귀 유발
- Form 자동 바인딩 setState 경합 수정 — 자동 바인딩과 수동 setState가 동시 실행 시 stale 값으로 덮어쓰이는 문제 해결
- Form 자동 바인딩 bindingType 메타데이터 기반 boolean 바인딩 수정 — Toggle/Checkbox 등 boolean 컴포넌트에서 문자열 변환 대신 boolean 값 유지
- SPA 네비게이션 시 `_global._local`에 이전 페이지 상태가 잔존하는 버그 수정 — 페이지 전환 시 `_local` 초기화 처리
- DynamicRenderer `_computed` 참조 prop에서 캐시된 stale 값이 사용되는 버그 수정 — `_computed`/`$computed` 참조 시 `skipCache: true` 적용

### Changed

- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
- 시더 디렉토리 분리 — 설치 시더와 샘플 시더를 `Sample/` 하위로 분리, `--sample` 옵션 추가
- 라이선스 프로그램 명칭 정비
- 일정 관리 메뉴 기본 비활성화 (`config/core.php`)
- Composer 의존성 업데이트 — Laravel Framework v12.54.1, Reverb v1.8.0, Symfony v7.4.6~7 등

## [7.0.0-alpha.9] - 2026-03-13

### Added

- 그누보드7 커스텀 에러 페이지 도입 — Laravel 기본 에러 페이지(401, 403, 404, 500, 503)를 그누보드7 스타일로 교체, 다크 모드 지원, 접근 경로 기반 홈 링크 분기 (admin → `/admin`, 기타 → `/`)
- 환경설정 > 고급 디버그 모드 활성화 시 개발 대시보드(`/dev`) 바로가기 버튼 추가
- 루트 LICENSE 파일 생성 (MIT 라이선스, 한국어 번역 + 영문 원문)
- 코어 라이선스/Changelog API 엔드포인트 추가 (`GET /api/admin/license`, `GET /api/admin/changelog`)
- 확장 라이선스 API 엔드포인트 추가 (`GET /api/admin/modules/{id}/license`, `GET /api/admin/plugins/{id}/license`, `GET /api/admin/templates/{id}/license`)
- Admin 푸터 copyright 클릭 → 코어 라이선스 모달, 버전 클릭 → Changelog 모달 표시 기능
- 확장 상세 모달에서 라이선스 클릭 시 전문 모달 표시 기능 (모듈/플러그인/템플릿)
- 각 번들 확장에 LICENSE 파일 및 manifest `license` 필드 추가

### Changed

- 설치 화면 라이선스를 루트 LICENSE 파일로 통합 (`public/install/lang/license-ko.txt`, `license-en.txt` 삭제)
- `/dev` 라우트 뷰 이름을 `dev-dashboard`로 변경

## [7.0.0-alpha.8] - 2026-03-13

### Changed

- `.env.example.develop`과 `.env.example.production`을 `.env.example`로 통합 — 설치형 솔루션에 환경별 분리 불필요, Laravel/Vite 표준 준수
- 인스톨러에서 `.env.production` 백업 파일 생성 로직 제거 — Vite mode 기반 로딩 충돌 방지

### Improved

- 코어/확장 업데이트 시 composer install 스킵 최적화 — composer.json/composer.lock 미변경 시 composer install 및 vendor 디렉토리 교체를 건너뛰어 업데이트 시간 단축

### Fixed

- 확장 수동 설치 모달에서 설치 실패 시 상세 에러 사유(`errors.error`) 미표시 문제 수정 — 3개 모달에 상세 에러 P 요소 추가
- `checkDependencies()` 복수 의존성 에러 수집 — 첫 번째 미충족 의존성에서 즉시 throw 대신 전체 수집 후 줄바꿈 연결 (ModuleManager, PluginManager, TemplateManager)
- ModuleController/PluginController 에러 반환 형식을 TemplateController와 통일 — `['error' => $e->getMessage()]` 형태
- 확장 설치 실패 시 `_pending/{identifier}` 디렉토리 자동 정리 — ModuleService, PluginService, TemplateService에 try-catch 추가

## [7.0.0-alpha.7] - 2026-03-13

### Added

- 템플릿 엔진 `multipart/form-data` 지원 — apiCall 핸들러 및 DataSourceManager에서 `contentType: "multipart/form-data"` 설정 시 params를 FormData로 자동 변환
  - ActionDispatcher: `fetchWithOptions()`에 FormData 변환 로직 추가, Content-Type 헤더 자동 생략 (브라우저 boundary 설정)
  - DataSourceManager: `toFormData()` 메서드 추가, 인증/비인증 경로 모두 multipart 지원
  - File/Blob 원본 유지, null/undefined 제외, 객체/배열 JSON.stringify 변환
- `deepMergeWithState()` non-plain 객체(File/Blob/Date) 보호 — spread 복사로 인한 내부 데이터 소실 방지
- `resolveParams()` non-plain 객체 재귀 해석 스킵 — File 객체가 빈 객체로 변환되는 문제 방지
- 컴포넌트 onChange raw value fallback — FileInput, Toggle 등 Event가 아닌 값을 전달하는 컴포넌트 지원

### Fixed

- DataSourceManager `isMultipart` 변수 TDZ(Temporal Dead Zone) 버그 수정 — 선언 전 참조로 인한 ReferenceError 해결

## [7.0.0-alpha.6] - 2026-03-13

### Fixed

- 플러그인 환경설정 페이지 진입 시 404 오류 수정 — `registerPluginLayouts()` admin/user 분기 도입 후 루트 `settings.json`이 스킵되던 문제
  - 플러그인 설정 레이아웃을 `resources/layouts/settings.json` → `resources/layouts/admin/plugin_settings.json`으로 이동하여 모듈과 동일한 구조로 통일
  - `AbstractPlugin::getSettingsLayout()` 경로 변경
  - `PluginSettingsService` 오버라이드 경로 및 주석 수정
  - 영향받는 플러그인: sirsoft-daum_postcode, sirsoft-marketing, sirsoft-tosspayments

### Changed

- 플러그인 설정 레이아웃 규정 문서(`plugin-development.md`) 경로/설명 업데이트

## [7.0.0-alpha.5] - 2026-03-12

### Added

- 확장(모듈/플러그인/템플릿) changelog GitHub 원격 소스 지원 — `source=github` 시 GitHub에서 CHANGELOG.md 조회, 실패 시 bundled 폴백
- `ChangelogParser::parseFromString()`, `getVersionRangeFromString()` 문자열 기반 파싱 메서드 추가
- `ChangelogRequest` validation 에러 다국어 메시지 추가 (source, version format, required_with)
- 플러그인 GitHub/ZIP 설치 기능 추가 — 모듈/템플릿에는 있지만 플러그인에 누락되어 있던 기능 신규 구현
  - `PluginService::installFromGithub()`, `installFromZipFile()`, `findPluginJson()` 메서드 추가
  - `PluginController::installFromFile()`, `installFromGithub()` 엔드포인트 추가
  - `InstallPluginFromGithubRequest`, `InstallPluginFromFileRequest` FormRequest 추가
  - `install-from-file`, `install-from-github` API 라우트 추가
- `ExtensionManager::hasComposerDependenciesAt(string $path)` 메서드 추가 — 임의 경로의 Composer 의존성 확인
- 모듈/플러그인 설치 시 `_pending` Composer 선행 설치 로직 추가 — 활성 디렉토리 이관 전 의존성 설치

### Changed

- 확장 수동 설치 모달 3개(모듈/플러그인/템플릿) UI 통일 — TabNavigation underline, 에러 배너, 필드별 적색 테두리
- 확장 GitHub/ZIP 설치 공통 로직을 `GithubHelper`, `ZipInstallHelper`로 추출하여 3개 Service 중복 제거
- `CoreUpdateService` GitHub 관련 protected 메서드를 `GithubHelper` 위임으로 리팩토링
- 확장 목록 PageHeader에서 새로고침 버튼 제거, 업데이트 확인 버튼으로 통일
- 코어 업데이트 `core_pending` 고정 경로 → `core_{Ymd_His}` 타임스탬프 기반 격리 디렉토리로 변경
- 확장(모듈/플러그인/템플릿) 업데이트에 `_pending/{identifier}_{timestamp}/` 스테이징 패턴 도입
- 확장 업데이트 시 스테이징 내에서 composer install 실행 (활성 디렉토리 무영향)
- 확장 GitHub 다운로드 코드를 코어와 동일한 패턴으로 통합 (인증 헤더, 폴백 체인, 타임아웃)
- GitHub 다운로드 공용 로직을 `ExtensionManager`로 추출하여 3개 Manager 중복 제거
- `config/app.php`에서 `preserves` 설정 키 제거 (타임스탬프 격리로 불필요)
- 모듈/플러그인 GitHub/ZIP 설치 흐름을 `temp → _pending → composer install → 활성 디렉토리` 패턴으로 통일

### Fixed

- 코어 업데이트 결과 모달에서 from/to 버전 파라미터가 전달되지 않는 버그 수정 — `params.params` → `params.query`
- 플러그인에 GitHub/ZIP 설치 기능이 누락되어 있던 결함 수정
- 파일 복사 시 퍼미션/소유자/소유그룹 미보존 문제 수정 — `File::copy()` → `FilePermissionHelper::copyFile()` 교체 (6개 위치)
- Windows 환경에서 확장/코어 업데이트 후 `_pending` 하위에 빈 디렉토리가 잔존하는 문제 수정 — `cleanupStaging()`에 3단계 retry 로직 추가
- 코어 업데이트 후 vendor 교체 시 stale `packages.php`/`services.php`로 인한 500 오류 수정 — `clearAllCaches()`에 컴파일 캐시 삭제 + `package:discover` + `extension:update-autoload` 추가
- 코어 업데이트 시 `bootstrap/cache` 디렉토리가 소스에서 덮어씌워지는 문제 수정 — excludes에 `bootstrap/cache` 추가

## [7.0.0-alpha.4] - 2026-03-12

### Added

- 코어 업그레이드 스텝 검증용 샘플 마이그레이션 및 업그레이드 스텝 추가
- 코어 업그레이드 스텝 경로를 `database/upgrades/` → `upgrades/`로 변경
- 업그레이드 스텝 실행을 프로그레스바 별도 단계로 분리 및 터미널 피드백 추가

### Changed

- `--source` 모드에서 원본 소스 디렉토리를 `_pending`으로 복제 후 작업 (원본 보호)
- Step 8: 운영 디렉토리 `composer install` 재실행 → `_pending/vendor/` 복사로 변경 (효율화)

### Fixed

- `--source` 모드에서 소스 버전 감지 시 현재 `env()` 대신 `config/app.php` default 값 파싱
- 코어 업데이트 targets에 `upgrades` 디렉토리 누락 수정

## [7.0.0-alpha.3] - 2026-03-12

### Fixed

- `.gitattributes`의 `CHANGELOG.md export-ignore`가 모든 CHANGELOG 파일을 릴리스 아카이브에서 제외하던 문제 수정
- PharData(tar ustar) 100바이트 경로 제한으로 324개 파일이 누락되어 orphan 삭제가 발생하던 버그 수정

### Removed

- PharData 아카이브 추출 전략 제거 — tar ustar 형식의 100바이트 경로 제한은 근본적 해결 불가

### Added

- `core:update --source=` 옵션 추가 — ZipArchive/unzip 불가 환경에서 수동 업데이트 지원
  - 상대경로, 절대경로, Windows 경로 모두 지원
  - 소스 디렉토리의 그누보드7 프로젝트 유효성 검증 (`config/app.php` + `version` 키)
- 업데이트 안내 모달에 수동 업데이트 가이드 섹션 추가
- 시스템 요구사항 미충족 시 `--source` 옵션 안내 메시지 추가

## [7.0.0-alpha.2] - 2026-03-12

### Fixed
- 코어 업데이트 확인 시 업데이트할 버전이 없으면 피드백 없던 문제 수정
  - `openModal` 호출 형식 수정 (`params.id` → `target`)
  - 모달 데이터 바인딩 경로 수정 (`_local` → `$parent._local`)
- 코어 업데이트 명령어 에러 미출력 수정
- `.env` 예제 파일에서 `G7_UPDATE_TARGETS` 하드코딩 제거

### Added
- 코어 업데이트 targets 확장 및 orphan 삭제 로직 추가
- 코어 업데이트 `--local` 옵션 및 설정 기반 제외/보존 추가
- 환경설정 고급 탭 코어 업데이트 설정 섹션 추가

## [7.0.0-alpha.1] - 2026-03-07

### Added

#### 코어 아키텍처

- Laravel 12 기반 CMS 플랫폼 초기 구조 설계 및 구현
- Service-Repository 패턴 기반 계층 분리 아키텍처 구축
- CoreServiceProvider를 통한 인터페이스-구현체 바인딩 시스템
- ResponseHelper를 통한 통일된 API 응답 형식 (success/error/paginated)
- AdminBaseController / AuthBaseController / PublicBaseController 컨트롤러 계층 구조
- FormRequest + Custom Rule 기반 검증 시스템
- BaseApiResource 상속 기반 API 리소스 패턴
- PHP 8.2+ Backed Enum 기반 상태/타입/분류 관리

#### 확장 시스템 (Extension System)

- 모듈(Module) 시스템: 디렉토리 스캔 기반 자동 발견, 설치/활성화/비활성화/삭제 관리
- 플러그인(Plugin) 시스템: 모듈 의존 기반 기능 확장, 설정 UI(settings.json) 지원
- 템플릿(Template) 시스템: Admin/User 타입 분리, JSON 기반 레이아웃, 컴포넌트 레지스트리
- ExtensionManager: 모듈/플러그인/템플릿 통합 관리 (설치, 업데이트, 삭제)
- HookManager: Action/Filter 훅 시스템 (doAction, applyFilters, HookListenerInterface)
- 확장 업데이트 시스템: _bundled/_pending 디렉토리 구조, GitHub/로컬 업데이트 감지 및 적용
- 확장 백업/복원 시스템 (ExtensionBackupHelper)
- 확장 상태 가드 (ExtensionStatusGuard): Installing/Updating/Uninstalled 상태 관리
- 확장 권한 동기화 (ExtensionRoleSyncHelper): 설치/삭제 시 역할-권한 자동 동기화
- 확장 메뉴 동기화 (ExtensionMenuSyncHelper): 모듈 메뉴 자동 등록/해제
- 확장 오토로드 시스템: 런타임 Composer 오토로드 (composer.json 수정 불필요)
- 확장 Composer 의존성 관리 (extension:composer-install 커맨드)
- 확장 업그레이드 스텝 시스템 (UpgradeStepInterface, UpgradeContext)
- 확장 설정 시스템 (SettingsMigrator): 모듈/플러그인별 독립 설정 관리
- 확장 소유권 시스템: extension_type/extension_identifier 기반 리소스 귀속
- 확장 에셋 시스템: module.json 매니페스트 기반 JS/CSS 자동 로딩
- 확장 Changelog 시스템: ChangelogParser 헬퍼 + API 엔드포인트 + 관리 화면 인라인 표시
- 확장 빌드 시스템: Artisan 커맨드 기반 (module:build, template:build, plugin:build)
- 확장 캐시 관리: 확장별 독립 캐시 + 일괄 클리어 커맨드

#### 템플릿 엔진 (Template Engine)

- DynamicRenderer: JSON 레이아웃 기반 React 컴포넌트 동적 렌더링
- DataBindingEngine: `{{expression}}` 문법, Optional Chaining(`?.`), Nullish Coalescing(`??`) 지원
- ActionDispatcher: 20+ 내장 핸들러 (navigate, apiCall, setState, openModal, closeModal, sequence, condition, replaceUrl, scrollTo, copyToClipboard, downloadFile, debounce, emit, showToast, confirm, validate, submit, reset, filter, sort 등)
- ComponentRegistry: 컴포넌트 등록/검색/해석 시스템 (기본/집합/레이아웃 타입)
- TranslationEngine: `$t:key` 즉시 평가 / `$t:defer:key` 지연 평가 다국어 바인딩
- LayoutLoader: JSON 레이아웃 로딩, 캐싱, ETag 기반 조건부 요청
- Router: SPA 라우팅, 동적 경로 파라미터(`{{route.id}}`), 쿼리스트링 관리
- 레이아웃 상속 시스템: `extends` 기반 베이스 상속 + `type: "slot"` 위치에 컨텐츠 삽입
- Partial 시스템: 레이아웃 모듈화, 컴포넌트 치환 (data_sources/computed/modals/state 미지원)
- 조건부 렌더링: `if` 속성 기반 표현식 평가 (type: "conditional" 미지원)
- 반복 렌더링: `iteration` 설정 (source, item_var, index_var, key)
- 반응형 레이아웃: `responsive` 속성 기반 breakpoint 오버라이드 (portable/compact/wide)
- 다크 모드: Tailwind `dark:` variant 기반 자동 전환
- classMap: 조건부 CSS 클래스 매핑 (key → variants)
- computed: 계산된 속성 시스템 (의존성 추적, 자동 재계산)
- 모달 시스템: `modals` 섹션 + openModal/closeModal 핸들러 (`_global.modalStack` 기반)
- init_actions: 레이아웃 초기화 시 자동 실행 액션 (루트 레이아웃 레벨)
- Named Actions: 액션 재사용 시스템 (DRY 패턴)
- errorHandling: 전역/데이터소스별 에러 핸들링 설정
- scripts: 레이아웃 레벨 커스텀 스크립트 로딩
- globalHeaders: API 호출 공통 헤더 설정 (pattern 기반 매칭)
- blur_until_loaded: 데이터 로딩 전 블러 처리
- lifecycle: 컴포넌트 생명주기 훅 (onMount, onUnmount)
- slots: 컴포넌트 슬롯 시스템
- layout_extensions: 모듈/플러그인의 동적 UI 주입 포인트
- isolatedState: 컴포넌트 상태 격리
- 데이터소스 조건부 로딩: `if` 표현식 기반 활성화/비활성화

#### 컴포넌트 시스템

- Basic 컴포넌트 (27+): Div, Button, Input, Select, Form, A, H1~H6, Span, P, Img, Label, Textarea, Table, Thead, Tbody, Tr, Th, Td, Ul, Ol, Li, Hr, Strong, Em, Small, Pre, Code, Blockquote, Nav, Header, Footer, Main, Section, Article, Aside, Figure, FigCaption
- Composite 컴포넌트: DataGrid, CardGrid, Pagination, Modal, SearchBar, Tabs, TabPanel, Accordion, Badge, Breadcrumb, Card, Checkbox, CheckboxGroup, DatePicker, Dropdown, FileUpload, Icon, Notification, Radio, RadioGroup, RangeSlider, Rating, Select (enhanced), Sidebar, Stepper, Switch, Tag, Timeline, Toast, Tooltip, PasswordInput, DynamicFieldList, SortableList, ColorPicker, NumberInput, TreeView, DateRangePicker
- Layout 컴포넌트: FlexLayout, GridLayout, ScrollLayout, StickyLayout, Spacer, Container, AspectRatio
- HtmlEditor: TinyMCE 기반 HTML 에디터 컴포넌트
- Icon 컴포넌트: Font Awesome 6.4.x Free 아이콘 지원 (Solid 1,390개 / Regular 163개 / Brands 472개)
- Alert, EmptyState, LoadingSpinner, Skeleton, StatusBadge, CopyButton 유틸리티 컴포넌트

#### 상태 관리

- 전역 상태 (`_global`): 앱 전체 공유, 페이지 이동 시 유지
- 로컬 상태 (`_local`): 레이아웃 단위 격리
- 계산된 상태 (`_computed`): 의존성 기반 자동 재계산
- 폼 자동 바인딩: Form 컴포넌트 `stateKey` 기반 자동 상태 연동
- setState 핸들러: target(global/local), 함수형 업데이트, 배열 조작 (push/filter/map)
- 상태 구독: `G7Core.state.subscribe` 기반 반응형 업데이트
- initGlobal: 전역 상태 초기값 선언
- 모달 스코프 상태: `$parent._local` 스냅샷 기반 데이터 전달

#### 인증 시스템

- Laravel Sanctum 하이브리드 인증 (세션 + Bearer 토큰)
- AuthManager: 싱글톤 인증 상태 관리
- 자동 토큰 갱신: 401 응답 시 자동 리프레시 후 재시도
- OptionalSanctumMiddleware: 선택적 인증 지원 (비인증 사용자 허용)
- 로그인/로그아웃 3단계 프로토콜 (토큰 삭제 → 세션 무효화 → Auth::logout)
- 비밀번호 재설정: 이메일 인증 기반 토큰 발급 및 검증
- 회원가입: 약관 동의, 이메일 인증 지원

#### 권한 시스템

- Role 기반 권한 관리 (User → Role → Permission 3계층)
- permission 미들웨어 체인 기반 접근 제어 (FormRequest authorize() 사용 금지)
- 확장별 권한 자동 등록/해제
- 역할별 메뉴 접근 제어 (role_menus 피벗 테이블)
- 슈퍼 관리자 (superadmin) 전체 권한 자동 부여

#### 메뉴 시스템

- 계층형 메뉴 구조 (parent_id 기반)
- 역할별 메뉴 가시성 제어
- 모듈 메뉴 자동 등록 (getAdminMenus 인터페이스)
- 메뉴 순서 관리 (드래그 앤 드롭 SortableList)
- 다국어 메뉴명 지원 (JSON 배열 형식)

#### 데이터 소스 (Data Sources)

- API 엔드포인트 선언적 정의 (id, endpoint, method, params)
- loading_strategy: immediate/lazy/manual 3가지 로딩 전략
- 데이터소스 의존성: depends_on 기반 연쇄 로딩
- 폴링: poll_interval 기반 주기적 갱신
- 조건부 로딩: `if` 표현식 기반 활성화
- transform: 응답 데이터 변환 함수
- cache_duration: 응답 캐싱

#### 관리자 기능 (Admin)

- 대시보드: 시스템 정보, 통계 위젯, 최근 활동
- 사용자 관리: CRUD, 역할 할당, 상태 관리 (활성/비활성/차단)
- 역할 관리: CRUD, 권한 할당, 다국어 역할명
- 권한 관리: 카테고리별 권한 목록, 역할별 권한 할당
- 메뉴 관리: 계층형 메뉴 편집, 순서 변경, 역할별 가시성 설정
- 모듈 관리: 설치/활성화/비활성화/삭제, 업데이트 확인, 상세 정보 모달, changelog 표시
- 플러그인 관리: 설치/활성화/비활성화/삭제, 설정 UI, 업데이트 확인, changelog 표시
- 템플릿 관리: 설치/활성화/비활성화/삭제, 업데이트 확인, changelog 표시
- 환경설정: 사이트 기본 정보, SEO 설정, 메일 설정, 보안 설정, 탭 레이아웃
- 일정 관리: 스케줄 CRUD, 캘린더 뷰, 카테고리 분류
- 메일 템플릿 관리: DB 기반 메일 템플릿 CRUD, 변수 치환, 미리보기 기능
- 메일 발송 로그: 발송 이력 조회, 상태 추적, 상세 정보 모달
- 시스템 정보: PHP/Laravel/DB 버전, 디스크 사용량, 확장 현황 표시
- 코어 업데이트: 버전 확인, 업데이트 가이드, changelog 인라인 표시, 백업 생성

#### 사용자 기능 (User/Public)

- 로그인/회원가입/비밀번호 재설정 페이지
- 마이페이지: 프로필 수정, 비밀번호 변경
- 통합 검색 기능
- 게시판 뷰: 목록/상세/작성/수정 (board 모듈 연동)
- 에러 페이지: 403, 404, 500, 503 커스텀 에러 페이지
- 점검 모드(maintenance) 페이지

#### 설치 프로그램 (Installer)

- 다단계 웹 설치 마법사 (환경 체크 → DB 설정 → 관리자 생성 → 완료)
- SSE(Server-Sent Events) 기반 실시간 설치 진행 상태 표시
- 설치 롤백 기능: 실패 시 자동 복원 (마이그레이션/시더 롤백)
- 언어 선택 지원 (한국어/영어)
- 다크 모드 지원
- 환경 요구사항 자동 검증 (PHP 버전, 확장, 디렉토리 권한)

#### 모듈: sirsoft-board (게시판)

- 게시판 관리: CRUD, 카테고리, 스킨 설정, 권한 설정
- 게시글 관리: CRUD, 검색, 정렬, 페이지네이션
- 댓글 시스템: CRUD, 대댓글 (계층형), 답글 알림
- 신고 시스템: 게시글/댓글 신고, 관리자 처리 (승인/거절)
- 첨부파일: 이미지/파일 업로드, 다운로드, 인라인 표시
- 비밀글: 작성자/관리자만 열람 가능
- 블라인드/복원: 관리자 블라인드 처리 및 복원 기능
- 카드/갤러리 레이아웃: 다양한 목록 표시 형태 지원
- 게시판 권한: 읽기/쓰기/댓글/관리 권한 분리
- 인기글/최신글 위젯
- SEO 메타 태그 자동 생성

#### 모듈: sirsoft-ecommerce (이커머스)

- 상품 관리: CRUD, 옵션(사이즈/색상), 라벨, SEO 메타, 이미지 갤러리
- 상품 카테고리: 계층형 카테고리, 순서 관리, TreeView 편집
- 브랜드 관리: CRUD, 로고, 설명
- 주문 관리: 주문 목록, 상태 변경, 상세 정보, 주문 타임라인
- 쿠폰 시스템: 정액/정률 할인, 사용 조건 (최소 금액, 특정 상품), 유효기간, 사용 횟수 제한
- 배송 정책: 무료/유료/조건부 배송, 지역별 요금 설정
- 공통 정보 관리: 배송/교환/환불 안내, 판매자 정보
- 장바구니: 추가/수량 변경/삭제, 옵션별 관리, 품절 상품 알림
- 체크아웃: 주소 입력 (다음 우편번호 연동), 배송지 저장 체크박스, 결제수단 선택, 쿠폰 적용
- 주문 완료: 주문 번호, 결제 정보 요약, 장바구니 자동 비우기
- 상품 상세 페이지: 이미지 갤러리, 옵션 선택, 수량 입력, 장바구니 담기
- 상품 목록: 필터링 (카테고리/브랜드/가격), 정렬, 페이지네이션, 카드 그리드
- 위시리스트: 찜하기/해제 기능
- 상품 검색: 키워드 검색, 카테고리 필터 연동

#### 모듈: sirsoft-page (페이지 관리)

- CMS 페이지: CRUD, 슬러그 기반 URL 매핑
- 페이지 버전 관리: 버전 이력 조회, 이전 버전 복원
- 첨부파일: 이미지/파일 업로드 지원
- 검색 통합: 페이지 내용 통합 검색 지원
- SEO: 메타 태그, Open Graph 설정

#### 플러그인: sirsoft-daum_postcode (다음 우편번호)

- 다음 우편번호 검색 API 연동
- 주소 선택 후 폼 자동 입력
- 체크아웃 배송지 입력 연동

#### 플러그인: sirsoft-tosspayments (토스페이먼츠)

- 토스페이먼츠 결제 API 연동
- 카드/계좌이체/가상계좌/무통장입금 결제 지원
- 결제 확인 (confirm) 프로세스
- 결제 성공/실패 콜백 처리
- 관리자 설정 UI (API 키 관리)

#### 플러그인: sirsoft-marketing (마케팅 동의)

- 마케팅 동의 관리: 이메일 구독, 마케팅 동의, 제3자 제공 동의
- MarketingConsent / MarketingConsentHistory 모델 및 서비스
- MarketingConsentListener 훅 리스너 (회원가입/프로필 연동)
- 사용자 관리 화면 레이아웃 확장 (마케팅 동의 상세/폼/프로필)
- 회원가입 폼 마케팅 동의 항목 확장
- 플러그인 설정 UI
- 역할 기반 접근 제어 (마케팅 관리자)
- 다국어 지원 (ko, en)

#### 플러그인: sirsoft-verification (본인인증)

- 휴대폰 인증, 아이핀 인증 등 본인인증 기능 제공
- 사용자 관리 화면 레이아웃 확장 (본인인증 상세/폼)
- 역할 기반 접근 제어 (본인인증 관리자)
- 다국어 지원 (ko, en)

#### 템플릿: sirsoft-admin_basic (관리자)

- 관리자 대시보드 레이아웃
- 사이드바 네비게이션: 접기/펼치기, 계층형 메뉴, 활성 상태 표시
- 상단바: 사용자 정보, 알림 벨, 다크 모드 전환 토글
- 반응형 레이아웃 (데스크톱/태블릿/모바일)
- 관리자 전용 컴포넌트: DataGrid, CardGrid, SearchBar, 필터 패널 등
- CRUD 화면 표준 레이아웃 (목록/생성/수정/상세)
- 모달 기반 상세 보기/수정 기능
- 토스트 알림 시스템
- 확인 다이얼로그 (삭제 확인 등)
- 환경설정 탭 레이아웃
- 모듈/플러그인/템플릿 관리 화면 (설치/업데이트/상세 모달)
- 사용자/역할/권한 관리 화면
- 메뉴 관리 화면 (드래그 앤 드롭 순서 변경)
- 일정 관리 캘린더 화면
- 메일 템플릿 관리 화면
- 메일 발송 로그 화면
- 시스템 정보 화면
- 코어 업데이트 가이드/결과 모달

#### 템플릿: sirsoft-basic (사용자)

- 사용자 메인 페이지 레이아웃
- 헤더/푸터 공통 레이아웃 (반응형)
- 로그인/회원가입/비밀번호 재설정 화면
- 마이페이지 레이아웃
- 게시판 목록/상세/작성/수정 화면
- 상품 목록/상세 화면
- 장바구니/체크아웃/주문완료 화면
- 검색 결과 화면
- CMS 페이지 표시 화면
- 에러 페이지 (403, 404, 500, 503)
- 점검 모드(maintenance) 전용 페이지
- 반응형 레이아웃 (데스크톱/태블릿/모바일)

#### 다국어 시스템 (i18n)

- 백엔드 다국어: `__()` 함수 기반, `lang/{locale}/*.php` 파일 구조
- 프론트엔드 다국어: `$t:key` 즉시 평가 바인딩, `$t:defer:key` 지연 평가 바인딩
- 컴포넌트 다국어: `G7Core.t()` API 제공
- 모듈 다국어: 모듈별 독립 언어 파일, 네임스페이스 분리 (`__('vendor-module::key')`)
- 템플릿 다국어: partial 언어 파일 분할 (admin.json, errors.json 등)
- DB 다국어 필드: JSON 배열 형식 (`{"ko": "...", "en": "..."}`)
- 지원 로케일: 한국어(ko), 영어(en)
- TranslatableField 트레이트: 모델 다국어 필드 자동 해석

#### 보안 (Security)

- ValidLayoutStructure: JSON 레이아웃 구조 검증 Custom Rule
- WhitelistedEndpoint: API 엔드포인트 화이트리스트 검증 Custom Rule
- NoExternalUrls: 외부 URL 차단 검증 Custom Rule
- ComponentExists: 컴포넌트 존재 여부 검증 Custom Rule
- CSRF 보호: Laravel 기본 CSRF 토큰 검증
- XSS 방지: 출력 이스케이프, HTML sanitize 처리
- SQL Injection 방지: Eloquent ORM, 파라미터 바인딩 사용
- Rate Limiting: API 요청 제한 미들웨어

#### 메일 시스템

- DB 기반 메일 템플릿: 제목/본문 DB 관리, 변수 치환 (Blade 문법)
- Notification + Mailable 통합 패턴: BaseNotification 상속으로 보일러플레이트 제거
- 메일 발송 로그: 발송 이력 DB 기록, 수신자/상태/발송 시간 추적
- 메일 템플릿 사용자 오버라이드 추적 (user_overrides 필드)

#### 알림 시스템 (Notification)

- BaseNotification: 모든 알림의 베이스 클래스 (via() 보일러플레이트 제거)
- 알림 채널: 메일, 데이터베이스, 브로드캐스트 지원
- 실시간 알림: Laravel Reverb (WebSocket) 기반 Broadcasting

#### 스토리지 시스템

- StorageInterface: 모든 파일 저장의 추상화 인터페이스 (Storage::disk() 직접 호출 금지)
- CoreStorageDriver: 코어 스토리지 구현체
- 확장별 독립 스토리지 공간 할당

#### 코어 업데이트 시스템

- CoreUpdateService: GitHub API 기반 코어 버전 확인 및 업데이트 감지
- CoreUpdateController: 업데이트 상태 확인, 가이드 표시 API 엔드포인트
- CoreBackupHelper: 업데이트 전 코어 파일 백업 생성
- FilePermissionHelper: 파일/디렉토리 쓰기 권한 사전 검증
- MaintenanceModePage 미들웨어: 점검 모드 시 전용 페이지 표시
- 업데이트 가이드 모달: changelog 인라인 표시, 단계별 안내

#### 그누보드7 DevTools

- MCP 서버: 20+ 디버깅 도구 제공
  - 기본 도구: g7-state, g7-actions, g7-cache, g7-diagnose, g7-lifecycle, g7-network, g7-form, g7-expressions, g7-logs
  - 고급 분석: g7-datasources, g7-handlers, g7-events, g7-performance, g7-conditionals, g7-websocket
  - 상태 계층/스타일: g7-renders, g7-state-hierarchy, g7-context-flow, g7-styles, g7-auth, g7-tailwind, g7-layout
  - Phase 8 심화: g7-computed, g7-nested-context, g7-modal-state, g7-sequence, g7-stale-closure, g7-change-detection
- 브라우저 상태 덤프: 런타임 상태 캡처 및 MCP 서버 전송
- UI 패널: 브라우저 내 DevTools 패널 (실시간 상태/액션/로그 확인)
- 페이지네이션: offset/limit 기반 대용량 데이터 조회 지원

#### WYSIWYG 에디터

- 비주얼 레이아웃 에디터: 드래그 앤 드롭 기반 레이아웃 편집
- PropertyPanel: 컴포넌트 속성 편집 UI
- 컴포넌트 팔레트: 사용 가능한 컴포넌트 드래그 목록
- 실시간 미리보기: 편집 즉시 렌더링 결과 확인

#### 성능 최적화

- 레이아웃 캐싱: 파싱된 레이아웃 JSON + 상속 병합 결과 캐시
- 확장 캐싱: 모듈/플러그인/템플릿 목록 및 메타데이터 캐시
- 번역 캐싱: 언어 파일 파싱 결과 캐시
- ETag 지원: API 응답 조건부 캐시 검증 (304 Not Modified)
- Gzip 압축: API 응답 자동 압축
- Debounce 액션: 연속 이벤트 디바운스 처리 핸들러
- Lazy 로딩: 데이터소스 지연 로딩 (스크롤/이벤트 트리거)
- 순환 참조 방지 메커니즘: 레이아웃 상속 무한 루프 감지

#### 데이터베이스

- 코어 테이블: users, roles, permissions, role_has_permissions, role_menus, menus, settings, mail_templates, mail_send_logs, schedules, modules, plugins, templates, template_layouts, template_layout_versions
- 모든 컬럼 한국어 comment 필수 규칙 적용
- down() 메서드 완전 롤백 구현 필수 규칙 적용
- 다국어 필드 JSON 배열 형식 표준
- MariaDB 호환성 지원
- boolean/enum 컬럼 값 설명 comment 포함 규칙

#### 테스트 인프라

- PHPUnit 11.x: 백엔드 단위 테스트 (Unit) 및 기능 테스트 (Feature)
- Vitest: 프론트엔드 컴포넌트/템플릿 엔진 테스트
- createLayoutTest(): 레이아웃 JSON 렌더링 테스트 유틸리티 (Vitest + jsdom)
- mockApi(): API 응답 모킹 유틸리티 (fetch 자동 모킹)
- 트러블슈팅 회귀 테스트: 해결된 사례별 자동 검증
- 테스트 커버리지: 모델, 서비스, 컨트롤러, 컴포넌트, 레이아웃 렌더링

#### 빌드 시스템

- Vite 기반 프론트엔드 빌드 (코드 분할, 트리 쉐이킹)
- Artisan 커맨드 기반 빌드 관리:
  - `core:build`: 코어 템플릿 엔진 빌드 (--full, --watch 옵션)
  - `module:build`: 모듈 프론트엔드 빌드 (--all, --watch, --active 옵션)
  - `template:build`: 템플릿 빌드 (--all, --watch, --active 옵션)
  - `plugin:build`: 플러그인 빌드 (--all, --watch, --active 옵션)
- _bundled 디렉토리 기본 빌드, --active 옵션으로 활성 디렉토리 빌드
- --watch 모드: 파일 감시 기반 실시간 빌드 (활성 디렉토리 자동 사용)

#### Artisan 커맨드

- 모듈 관리: module:list, module:install, module:activate, module:deactivate, module:uninstall, module:composer-install, module:cache-clear, module:seed, module:check-updates, module:update, module:build
- 플러그인 관리: plugin:list, plugin:install, plugin:activate, plugin:deactivate, plugin:uninstall, plugin:composer-install, plugin:cache-clear, plugin:seed, plugin:check-updates, plugin:update, plugin:build
- 템플릿 관리: template:list, template:install, template:activate, template:deactivate, template:uninstall, template:cache-clear, template:check-updates, template:update, template:build
- 확장 공통: extension:composer-install, extension:update-autoload
- 코어: core:build

#### 개발 도구 및 문서

- AI 에이전트 개발 가이드 (AGENTS.md): 핵심 원칙, 코딩 규칙, 디버깅 프로토콜
- 규정 문서 체계 (docs/): 백엔드 14개, 프론트엔드 59개, 확장 23개, 공통 4개 (총 100개)
- AI 에이전트 자동화 도구: 검증/분석/구현 스킬 30+개, 인덱스 자동 생성 스크립트
- 트러블슈팅 가이드: 상태 관리, 캐시, 컴포넌트, 백엔드 문제 해결 사례집
- 코드 스타일: Laravel Pint (PSR-12) 자동 적용
