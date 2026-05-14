# 그누보드7 자주 쓰는 명령어 치트시트

## TL;DR (5초 요약)

```text
1. _bundled에서 레이아웃 JSON만 수정 → 확장 업데이트(--force)만 실행 (빌드 불필요)
2. _bundled에서 TSX/TS 수정 → build 필수 + 확장 업데이트(--force) 필요
3. 프론트엔드 테스트 → PowerShell 래퍼 필수 (powershell -Command)
4. 백엔드 테스트 → Bash 직접 실행 (php artisan test)
5. 코드 스타일 → vendor/bin/pint --dirty
```

---

## 빌드 vs 확장 업데이트

```
_bundled에서 레이아웃 JSON만 수정한 경우 → 빌드 불필요, 확장 업데이트(--force)만 필요
_bundled에서 TSX/TS 파일 수정한 경우    → 빌드 후 확장 업데이트(--force) 필요
```

| 수정 파일 유형 | 필요한 작업 |
|---------------|-------------|
| `*.json` (레이아웃만) | `{type}:update {id} --force` 실행 |
| `*.tsx`, `*.ts` + `*.json` | `{type}:build` + `{type}:update {id} --force` |
| `*.tsx`, `*.ts`만 | `{type}:build` + `{type}:update {id} --force` |
| `modules/**/resources/js/**/*.ts` (핸들러) | `module:build` + `module:update {id} --force` |
| `templates/**/src/**/*.tsx` (컴포넌트) | `template:build` + `template:update {id} --force` |

---

## 확장 업데이트 (_bundled → 활성 반영)

```bash
# 템플릿 업데이트 (templates/_bundled/** → templates/**)
php artisan template:update sirsoft-admin_basic --force

# 모듈 업데이트 (modules/_bundled/** → modules/**)
php artisan module:update sirsoft-ecommerce --force

# 플러그인 업데이트 (plugins/_bundled/** → plugins/**)
php artisan plugin:update sirsoft-payment --force
```

---

## 빌드 (Artisan 명령어 사용)

> **기본 빌드 경로**: `_bundled` 디렉토리. `--active` 옵션으로 활성 디렉토리 빌드 가능.
> `--watch` 모드는 자동으로 활성 디렉토리 사용 (실시간 브라우저 확인).
> 빌드 후 활성 반영: `module:update` / `template:update` / `plugin:update` 커맨드 사용.

```bash
# 코어 템플릿 엔진 (resources/js/core/template-engine/**)
php artisan core:build                    # 기본: 템플릿 엔진만 빌드
php artisan core:build --full             # 전체 빌드 (npm run build)
php artisan core:build --watch            # 파일 감시 모드

# 모듈 빌드 (기본: _bundled)
php artisan module:build sirsoft-ecommerce          # _bundled에서 빌드
php artisan module:build --all                      # 모든 _bundled 모듈 빌드
php artisan module:build sirsoft-ecommerce --watch   # 활성에서 watch
php artisan module:build sirsoft-ecommerce --active   # 활성에서 빌드

# 템플릿 빌드 (기본: _bundled)
php artisan template:build sirsoft-admin_basic        # _bundled에서 빌드
php artisan template:build --all                      # 모든 _bundled 템플릿 빌드
php artisan template:build sirsoft-admin_basic --watch # 활성에서 watch
php artisan template:build sirsoft-admin_basic --active # 활성에서 빌드

# 플러그인 빌드 (기본: _bundled)
php artisan plugin:build sirsoft-payment              # _bundled에서 빌드
php artisan plugin:build --all                        # 모든 _bundled 플러그인 빌드
php artisan plugin:build sirsoft-payment --watch       # 활성에서 watch
php artisan plugin:build sirsoft-payment --active       # 활성에서 빌드
```

---

## 테스트

```powershell
# 프론트엔드 (PowerShell 래퍼 필수)
powershell -Command "npm run test:run"
powershell -Command "npm run test:run -- DataGrid"
powershell -Command "npm run test:run -- template-engine"
```

```bash
# 백엔드
php artisan test
php artisan test --filter=TestName

# _bundled 확장 테스트 (활성 디렉토리 복사 불필요)
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests
php vendor/bin/phpunit --filter=TestName modules/_bundled/sirsoft-ecommerce/tests
```

---

## 코드 스타일

```bash
vendor/bin/pint --dirty
```

---

## 마이그레이션

```bash
php artisan make:migration create_[table]_table
php artisan migrate
php artisan migrate:rollback
```

---

## 확장 시스템 Artisan

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--force] [--no-backup] [--no-maintenance] [--vendor-mode=auto|composer|bundled]
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--force]   # 업그레이드 스텝 단독 실행 (HANDOFF 안내 또는 수동 복구용 — 단독 호출 시 migration·resync·.env·캐시·번들 확장 일괄 업데이트 자동 수행. CoreUpdateCommand 내부 spawn 은 --skip-* 5개 옵션 자동 전달)

# 모듈
php artisan module:list
php artisan module:install [identifier] [--vendor-mode=auto|composer|bundled] [--force]
php artisan module:activate [identifier]
php artisan module:deactivate [identifier]
php artisan module:uninstall [identifier]
php artisan module:composer-install [identifier?] [--all]
php artisan module:cache-clear [identifier?]
php artisan module:seed [identifier] [--sample] [--count=key=value]
php artisan module:check-updates [identifier?]
php artisan module:update [identifier] [--force] [--vendor-mode=auto|composer|bundled] [--layout-strategy=overwrite|keep] [--source=auto|bundled|github]

# 플러그인
php artisan plugin:list
php artisan plugin:install [identifier] [--vendor-mode=auto|composer|bundled] [--force]
php artisan plugin:activate [identifier]
php artisan plugin:deactivate [identifier]
php artisan plugin:uninstall [identifier]
php artisan plugin:composer-install [identifier?] [--all]
php artisan plugin:cache-clear [identifier?]
php artisan plugin:seed [identifier] [--sample] [--count=key=value]
php artisan plugin:check-updates [identifier?]
php artisan plugin:update [identifier] [--force] [--vendor-mode=auto|composer|bundled] [--layout-strategy=overwrite|keep] [--source=auto|bundled|github]

# 템플릿
php artisan template:list
php artisan template:install [identifier] [--force]
php artisan template:activate [identifier]
php artisan template:deactivate [identifier]
php artisan template:uninstall [identifier]
php artisan template:cache-clear
php artisan template:check-updates [identifier?]
php artisan template:update [identifier] [--layout-strategy=overwrite] [--force] [--source=auto|bundled|github]

# 언어팩
php artisan language-pack:list
php artisan language-pack:install [identifier] [--source=bundled|github|url] [--no-activate]
php artisan language-pack:activate [identifier] [--force]
php artisan language-pack:deactivate [identifier]
php artisan language-pack:uninstall [identifier] [--cascade] [--force]
php artisan language-pack:cache-clear
php artisan language-pack:check-updates [identifier?]
php artisan language-pack:update [identifier] [--force] [--source=auto|bundled|github]

# Composer 의존성 (모듈/플러그인별 독립 vendor/)
php artisan extension:composer-install          # 모든 모듈+플러그인

# 오토로드
php artisan extension:update-autoload
```

### 단발성 결함 보정 (hotfix)

`hotfix:*` prefix 는 특정 버전의 결함 회복을 위해 신설되는 단발성 도구를 위한 표준 prefix 다. `core:*` (영구 운영 도구) 와 명확히 구분되며 dev-dashboard 자동 노출 면제 대상이다.

```bash
# 코어 자동 롤백 후 활성 디렉토리에 잔존한 신 파일 진단/정리 (7.0.0-beta.6 신설)
php artisan hotfix:rollback-stale-files                              # 진단 모드 (실제 삭제 없음)
php artisan hotfix:rollback-stale-files --prune                      # 정리 (확인 프롬프트 동반)
php artisan hotfix:rollback-stale-files --backup=<경로>              # 특정 백업 디렉토리 지정
```

진단 결과 / 정리 로그는 `storage/logs/hotfix_rollback_stale_files_<timestamp>.log` 에 기록.

### Vendor 번들 Artisan (공유 호스팅용 vendor/ 선탑재)

```bash
# 개별 빌드 (기존 *:build 패턴과 동일 — positional identifier + --all)
php artisan core:vendor-bundle [--check] [--force]
php artisan module:vendor-bundle [identifier] [--all] [--check] [--force]
php artisan plugin:vendor-bundle [identifier] [--all] [--check] [--force]

# 개별 무결성 검증
php artisan core:vendor-verify
php artisan module:vendor-verify [identifier] [--all]
php artisan plugin:vendor-verify [identifier] [--all]

# 일괄 알리아스 (운영/CI 전용)
php artisan vendor-bundle:build-all [--check] [--force]   # 코어 + 모든 _bundled
php artisan vendor-bundle:verify-all

# 옵션
# --check  : stale 여부만 확인 (CI 검증용, stale 시 종료 코드 1)
# --force  : 해시 체크 무시하고 강제 재빌드
# --all    : 모든 _bundled 확장 (식별자 대체, 미지정 시 명시적 에러)
```

> 상세 가이드: [docs/extension/vendor-bundle.md](extension/vendor-bundle.md)

### 학습용 샘플 확장 설치

```bash
# 학습용 최소 샘플 4종 (manifest.hidden=true → 관리자 UI 기본 제외)
php artisan module:install gnuboard7-hello_module
php artisan module:activate gnuboard7-hello_module
php artisan plugin:install gnuboard7-hello_plugin
php artisan plugin:activate gnuboard7-hello_plugin
php artisan template:install gnuboard7-hello_admin_template
php artisan template:install gnuboard7-hello_user_template

# 숨김 포함 목록 조회
php artisan module:list --hidden
php artisan plugin:list --hidden
php artisan template:list --hidden
```

> 상세: [extension/sample-extensions.md](extension/sample-extensions.md)

### SEO Artisan 커맨드

```bash
# SEO
php artisan seo:warmup              # SEO 캐시 워밍업
php artisan seo:warmup --layout=shop/show  # 특정 레이아웃만
php artisan seo:clear               # 전체 SEO 캐시 삭제
php artisan seo:clear --layout=home # 특정 레이아웃만
php artisan seo:stats               # 캐시 통계 출력
php artisan seo:generate-sitemap    # Sitemap 생성 (큐 디스패치)
php artisan seo:generate-sitemap --sync  # Sitemap 동기 생성
```

---

## 확장 업데이트 (CLI + API)

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--force] [--no-backup] [--no-maintenance] # 코어 업데이트 실행
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--force]   # 업그레이드 스텝 단독 실행 (HANDOFF 안내 또는 수동 복구용)

# CLI (Artisan 커맨드)
php artisan module:check-updates [identifier?]                   # 모듈 업데이트 확인
php artisan module:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 모듈 업데이트 실행
php artisan plugin:check-updates [identifier?]                   # 플러그인 업데이트 확인
php artisan plugin:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 플러그인 업데이트 실행
php artisan template:check-updates [identifier?]                 # 템플릿 업데이트 확인
php artisan template:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 템플릿 업데이트 실행

# API 엔드포인트
POST /api/admin/core-update/check                              # 코어 업데이트 확인
GET  /api/admin/core-update/changelog                          # 코어 변경 로그 조회
POST /api/admin/modules/check-updates                          # 모듈 업데이트 확인
POST /api/admin/modules/{moduleName}/update                    # 모듈 업데이트 실행
POST /api/admin/plugins/check-updates                          # 플러그인 업데이트 확인
POST /api/admin/plugins/{pluginName}/update                    # 플러그인 업데이트 실행
POST /api/admin/templates/check-updates                        # 템플릿 업데이트 확인
GET  /api/admin/templates/{templateName}/check-modified-layouts # 수정된 레이아웃 확인
POST /api/admin/templates/{templateName}/update                # 템플릿 업데이트 실행
```

> 상세: [extension/extension-update-system.md](extension/extension-update-system.md)

---
