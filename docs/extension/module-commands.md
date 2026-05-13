# 모듈 Artisan 커맨드

> **관련 문서**: [index.md](index.md) | [module-basics.md](module-basics.md) | [module-routing.md](module-routing.md)

---

## TL;DR (5초 요약)

```text
1. 목록: php artisan module:list
2. 설치: php artisan module:install [identifier] (Composer 의존성 자동 설치 포함)
3. 활성화: php artisan module:activate [identifier]
4. 비활성화: php artisan module:deactivate [identifier]
5. Composer: php artisan module:composer-install [identifier] (수동 의존성 설치)
6. 삭제: php artisan module:uninstall [identifier] (--delete-data 시 vendor/ 삭제 포함)
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [사용 가능한 커맨드](#사용-가능한-커맨드)
- [커맨드 구현 규칙](#커맨드-구현-규칙)
- [커맨드 테스트](#커맨드-테스트)

---

## 핵심 원칙

### 모듈 Artisan 커맨드 규칙

```
필수: 모듈 관련 모든 작업은 Artisan 커맨드로 수행
✅ 규칙: 직접 DB 조작 또는 파일 시스템 변경 금지
```

모듈의 설치, 활성화, 비활성화, 삭제 등 모든 생명주기 관리는 반드시 Artisan 커맨드를 통해 수행해야 합니다. 이를 통해:
- 일관된 상태 관리
- 적절한 이벤트 발생
- 권한/메뉴 등 연관 데이터 관리
- 로그 기록

---

## 사용 가능한 커맨드

### 모듈 관리 커맨드

#### 모듈 목록 조회

```bash
php artisan module:list
```

**옵션**:
- `--status=active`: 활성화된 모듈만 표시
- `--status=inactive`: 비활성화된 모듈만 표시
- `--status=uninstalled`: 미설치 모듈만 표시

**출력 예시**:
```
+--------------------+----------+---------+
| Identifier         | Version  | Status  |
+--------------------+----------+---------+
| sirsoft-ecommerce  | 1.0.0    | active  |
| sirsoft-blog       | 1.2.0    | inactive|
| vendor-sample      | 0.1.0    | -       |
+--------------------+----------+---------+
```

#### 모듈 설치

```bash
php artisan module:install [identifier]
```

**수행 작업**:
- `_pending/_bundled` 디렉토리에서 활성 디렉토리로 복사 (ExtensionPendingHelper)
- `modules` 테이블에 모듈 등록
- 역할(Role) 등록
- 권한(Permission) 등록
- 메뉴(Menu) 등록
- 마이그레이션 실행 (있는 경우)
- 시더 실행 (있는 경우)
- Composer 의존성 설치 (외부 패키지가 있는 경우, Phase 4.5)

**예시**:
```bash
php artisan module:install sirsoft-ecommerce
```

#### 모듈 활성화

```bash
php artisan module:activate [identifier]
```

**수행 작업**:
- `modules.status`를 `active`로 변경
- soft deleted된 레이아웃 복원
- 모듈 레이아웃 등록 (admin 템플릿에)
- 모듈의 라우트 활성화
- 모듈의 훅 리스너 활성화
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**

#### 모듈 비활성화

```bash
php artisan module:deactivate [identifier]
```

**수행 작업**:
- `modules.status`를 `inactive`로 변경
- 모듈 레이아웃 soft delete
- 모듈의 라우트 비활성화
- 모듈의 훅 리스너 비활성화
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**

#### 모듈 삭제

```bash
php artisan module:uninstall [identifier]
```

**옵션**:
- `--force`: 확인 프롬프트 없이 즉시 삭제
- `--delete-data`: 데이터도 함께 삭제 (동적 테이블 + 마이그레이션 롤백 + 스토리지)

**수행 작업**:
- 확인 프롬프트 표시 (--force 미사용 시)
- `--delete-data` 사용 시 (트랜잭션 외부, DDL):
  - `getDynamicTables()` 반환 테이블 삭제 (Manager가 처리)
  - 마이그레이션 롤백 (정적 테이블 삭제)
  - 모듈 스토리지 디렉토리 삭제 (`storage/app/modules/{identifier}/`)
  - Composer vendor/ 디렉토리 및 composer.lock 삭제
- 역할(Role) 삭제
- 권한(Permission) 삭제
- 메뉴(Menu) 삭제
- 레이아웃(Layout) 영구 삭제
- 활성 디렉토리 삭제 (`_bundled` 원본은 보존)
- `modules` 테이블에서 완전 삭제 (hard delete)
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**

**예시**:
```bash
# 확인 프롬프트 표시
php artisan module:uninstall sirsoft-ecommerce

# 강제 삭제
php artisan module:uninstall sirsoft-ecommerce --force
```

#### 모듈 Composer 의존성 설치

```bash
php artisan module:composer-install [identifier]
```

**옵션**:

- `--all`: 모든 설치된 모듈의 Composer 의존성 설치
- `--no-dev`: dev 의존성 제외

**예시**:

```bash
# 특정 모듈 의존성 설치
php artisan module:composer-install sirsoft-ecommerce

# 모든 모듈 의존성 설치
php artisan module:composer-install --all

# dev 의존성 제외
php artisan module:composer-install sirsoft-ecommerce --no-dev
```

**수행 작업**:

- 모듈의 `composer.json`에 외부 패키지(`require`)가 있는지 확인
- `modules/{identifier}/` 디렉토리에서 `composer install` 실행
- `modules/{identifier}/vendor/` 디렉토리 생성
- 오토로드 캐시 파일 갱신 (`vendor_autoloads` 반영)

**사용 시점**:

- 모듈 설치 시 자동 실행되지만, 수동으로 재설치가 필요한 경우
- vendor/ 디렉토리가 손상되거나 삭제된 경우
- 새로운 패키지를 composer.json에 추가한 후

#### 모듈 업데이트 확인

```bash
php artisan module:check-updates [identifier?]
```

**인수**:
- `identifier` (선택): 특정 모듈만 확인

**기능**:

- **전체 확인** (인수 없음): 설치된 모든 모듈의 업데이트 가용 여부를 테이블로 출력
  - 감지 우선순위: GitHub > `_bundled` (2단계, `_pending` 미참여)
  - DB에 `update_available`, `latest_version`, `update_source` 갱신
- **단일 확인** (인수 있음): 특정 모듈의 업데이트 가용 여부 확인
  - `checkModuleUpdate()`는 DB 갱신 안 함 → 커맨드에서 `updateByIdentifier()` 직접 호출

**예시**:
```bash
# 모든 모듈 업데이트 확인
php artisan module:check-updates

# 특정 모듈만 확인
php artisan module:check-updates sirsoft-ecommerce
```

#### 모듈 업데이트

```bash
php artisan module:update [identifier] [--force] [--source=auto|bundled|github] [--layout-strategy=overwrite|keep] [--vendor-mode=auto|composer|bundled]
```

**인수**:
- `identifier` (필수): 업데이트할 모듈 식별자

**옵션**:
- `--force`: 버전 비교 없이 강제 업데이트 (파일 손상, 수동 수정 복원 시 사용)
- `--source`: 업데이트 소스 강제 (`auto` 기본 = GitHub > `_bundled` 우선순위, `bundled` = `_bundled` 단독, `github` = GitHub 단독)
- `--layout-strategy`: 사용자 수정 레이아웃 처리 (`overwrite` = 전체 덮어쓰기, `keep` = 수정분 보존)
- `--vendor-mode`: vendor 설치 방식 (`auto` 기본, `composer`, `bundled`)

**수행 작업**:
- 업데이트 가용 여부 확인 (없으면 info + SUCCESS, `--force` 시 무시하고 진행)
- 확인 프롬프트 → 업데이트 실행
- 상태 가드 확인 → 백업 → status: Updating → 파일 교체 → 마이그레이션 → 업그레이드 스텝 → 복원
- 실패 시 백업 복원 안내

**예시**:
```bash
php artisan module:update sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --force  # 강제 재설치
php artisan module:update sirsoft-ecommerce --source=bundled  # GitHub 장애/잘못된 태그 우회
php artisan module:update sirsoft-ecommerce --source=github --force  # 긴급 핫픽스 태그로만 강제 설치
```

#### 모듈 캐시 초기화

```bash
php artisan module:cache-clear
```

**옵션**:
- `[identifier]`: 특정 모듈만 캐시 삭제

**예시**:
```bash
# 모든 모듈 캐시 삭제
php artisan module:cache-clear

# 특정 모듈 캐시만 삭제
php artisan module:cache-clear sirsoft-ecommerce
```

#### 모듈 프론트엔드 에셋 빌드

```bash
php artisan module:build [identifier]
```

> **기본 빌드 경로**: `_bundled` 디렉토리. 빌드 결과물은 빌드 경로 내에만 남음.
> 활성 디렉토리 반영은 `module:update` 커맨드로만 수행.

**옵션**:

- `--all`: 모든 모듈의 에셋 빌드 (_bundled 기준, --active 시 활성 기준)
- `--watch`: 파일 변경 감시 모드 (자동으로 활성 디렉토리 사용)
- `--production`: 프로덕션 최적화 빌드
- `--active`: 활성 디렉토리에서 빌드 (_bundled 대신)

**예시**:
```bash
# _bundled에서 빌드 (기본값)
php artisan module:build sirsoft-ecommerce

# 모든 _bundled 모듈 빌드
php artisan module:build --all

# 프로덕션 빌드 (_bundled)
php artisan module:build sirsoft-ecommerce --production

# 파일 감시 모드 (활성 디렉토리에서 자동 실행)
php artisan module:build sirsoft-ecommerce --watch

# 활성 디렉토리에서 빌드
php artisan module:build sirsoft-ecommerce --active

# 빌드 후 활성 디렉토리 반영
php artisan module:update sirsoft-ecommerce
```

**수행 작업**:

- 빌드 경로 결정: _bundled 우선 → 활성 폴백 (--active 시 활성만)
- npm 의존성 설치 (node_modules 없는 경우)
- Vite를 통한 IIFE 번들 빌드
- `dist/js/module.iife.js` 및 `dist/css/module.css` 생성
- 빌드 결과 파일 크기 출력
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**
  - 주의: `--watch` 모드에서는 캐시 버전이 증가하지 않음

**참고**: [module-assets.md](./module-assets.md) 문서에서 에셋 빌드 설정 상세 참조

#### 모듈 시더 실행

```bash
php artisan module:seed [identifier]
```

**옵션**:

- `--class=SeederClass`: 특정 시더 클래스만 실행
- `--sample`: 샘플 데이터 시더도 함께 실행 (기본: 설치 시더만)
- `--count=key=value`: 시더에 카운트 옵션 전달 (반복 가능)
- `--force`: 프로덕션 환경에서 강제 실행

**예시**:

```bash
# 단일 모듈 시더 실행 (설치 시더만)
php artisan module:seed sirsoft-ecommerce

# 설치 + 샘플 시더 실행
php artisan module:seed sirsoft-ecommerce --sample

# 모든 활성 모듈 시더 실행
php artisan module:seed

# 특정 시더 클래스 실행
php artisan module:seed sirsoft-ecommerce --class=Sample\\ProductSeeder

# 샘플 + 카운트 옵션
php artisan module:seed sirsoft-ecommerce --sample --count=products=1000

# 프로덕션 환경에서 강제 실행
php artisan module:seed sirsoft-ecommerce --force
```

**수행 작업**:

- 활성화된 모듈만 실행 가능 (비활성 모듈은 에러)
- `modules/{identifier}/database/seeders/DatabaseSeeder.php` 실행
- 기본 실행 시 설치 필수 시더만 실행, `--sample` 옵션 시 Sample/ 하위 시더도 실행
- `--class` 옵션 사용 시 해당 시더만 실행
- `--sample` 옵션은 `HasSampleSeeders` 트레이트를 통해 시더에 전파

**주의사항**:

- 활성화되지 않은 모듈은 시더 실행 불가
- 프로덕션 환경에서는 `--force` 옵션 필수
- 샘플 시더는 `database/seeders/Sample/` 하위 디렉토리에 위치

---

## 커맨드 구현 규칙

### 1. 다국어 메시지 필수

```php
// ✅ DO: 다국어 메시지 사용
$this->info(__('modules.commands.install.success', ['module' => $identifier]));

// ❌ DON'T: 하드코딩 메시지
$this->info('모듈 설치 성공');
```

### 2. 로그 기록 필수

```php
use Illuminate\Support\Facades\Log;

// 성공 로그
Log::info(__('modules.commands.install.success', ['module' => $identifier]));

// 에러 로그
Log::error('모듈 설치 실패', [
    'module' => $identifier,
    'error' => $e->getMessage(),
]);
```

### 3. 예외 처리

```php
public function handle(): int
{
    try {
        // 커맨드 로직

        $this->info('✅ ' . __('modules.commands.install.success', ['module' => $identifier]));
        return Command::SUCCESS;
    } catch (\Exception $e) {
        $this->error('❌ ' . $e->getMessage());
        Log::error('모듈 설치 실패', [
            'module' => $identifier,
            'error' => $e->getMessage(),
        ]);
        return Command::FAILURE;
    }
}
```

### 4. 상세 정보 출력

```php
// 모듈 정보 출력
$this->info('   - ' . __('modules.commands.install.version', ['version' => $module->version]));
$this->info('   - ' . __('modules.commands.install.permissions_created', ['count' => $permissionsCount]));
$this->info('   - ' . __('modules.commands.install.menus_created', ['count' => $menusCount]));
```

---

## 커맨드 테스트

### Feature 테스트 작성 필수

모든 커맨드에 대해 Feature 테스트를 작성해야 합니다.

#### 설치 테스트

```php
public function test_can_install_module_via_command(): void
{
    // Arrange
    $identifier = 'sirsoft-sample';

    // Act
    $this->artisan('module:install', ['identifier' => $identifier])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => $identifier,
        'status' => ExtensionStatus::Inactive->value,
    ]);
}
```

#### 삭제 확인 프롬프트 테스트

```php
public function test_uninstall_requires_confirmation(): void
{
    // Arrange
    $module = Module::factory()->create(['identifier' => 'test-module']);

    // Act & Assert
    $this->artisan('module:uninstall', ['identifier' => 'test-module'])
        ->expectsConfirmation(
            __('modules.commands.uninstall.confirm', ['module' => 'test-module']),
            'no'
        )
        ->assertSuccessful();

    // Module should still exist
    $this->assertDatabaseHas('modules', ['identifier' => 'test-module']);
}
```

#### 활성화/비활성화 테스트

```php
public function test_can_activate_module(): void
{
    // Arrange
    $module = Module::factory()->create([
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Inactive,
    ]);

    // Act
    $this->artisan('module:activate', ['identifier' => 'test-module'])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Active->value,
    ]);
}

public function test_can_deactivate_module(): void
{
    // Arrange
    $module = Module::factory()->create([
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Active,
    ]);

    // Act
    $this->artisan('module:deactivate', ['identifier' => 'test-module'])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Inactive->value,
    ]);
}
```

---

## 모듈 업데이트 (API + CLI)

모듈 업데이트는 **API 엔드포인트** 또는 **Artisan 커맨드**를 통해 수행됩니다.

### Artisan 커맨드

```bash
# 업데이트 확인
php artisan module:check-updates              # 전체
php artisan module:check-updates [identifier]  # 단일

# 업데이트 실행
php artisan module:update [identifier]
```

### API 엔드포인트

```text
POST /api/admin/modules/check-updates
POST /api/admin/modules/{moduleName}/update
```

- 감지 우선순위: GitHub > `_bundled` (2단계, `_pending` 미참여)
- 상태 가드 확인 → 백업 → status: Updating → 파일 교체 → 마이그레이션 → 업그레이드 스텝 → 복원
- 에러 발생 시 자동 롤백 (백업에서 복원)

> 상세: [extension-update-system.md](extension-update-system.md)

---

## 관련 문서

- [모듈 개발 기초](module-basics.md) - AbstractModule, 디렉토리 구조
- [모듈 라우트 규칙](module-routing.md) - 라우트 네이밍, 자동 Prefix
- [모듈 레이아웃 시스템](module-layouts.md) - 레이아웃 등록, 오버라이드
- [권한 시스템](permissions.md) - 모듈 Role/Permission 자동 관리
- [확장 업데이트 시스템](extension-update-system.md) - 업데이트 감지/실행 전체 흐름
