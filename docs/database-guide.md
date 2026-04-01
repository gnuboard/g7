# 그누보드7 데이터베이스 개발 가이드

> 이 문서는 그누보드7의 데이터베이스 마이그레이션, 시더, 다국어 처리 규칙을 상세히 설명합니다.

---

## TL;DR (5초 요약)

```text
1. 마이그레이션: 한국어 comment 필수, down() 구현 필수
2. 네이밍: create_[table]_table, add_[col]_to_[table]_table
3. 로케일: config('app.supported_locales') 사용, 하드코딩 금지
4. 다국어 필드: JSON 구조 {"ko": "한글", "en": "English"}
5. 시더: 콘솔 메시지 필수, truncate() 대신 delete() 사용
6. 모듈/플러그인 down(): 테이블/컬럼/인덱스 존재 확인 후 삭제 (방어적 코딩 필수)
```

---

## 목차

- [마이그레이션](#마이그레이션)
- [시더](#시더)
- [로케일 설정](#로케일-설정)
- [다국어 (Backend)](#다국어-backend)
- [코어 테이블 다국어 지원](#코어-테이블-다국어-지원)

---

## 마이그레이션

**규칙**:
- 한국어 comment 필수
- enum/boolean은 값 설명 포함
- 외래키 제약조건 명시
- down() 메서드 구현

**파일명 네이밍 규칙**:

- **테이블 생성**: `create_[table_name]_table`
- **컬럼 추가**: `add_[column1]_and_[column2]_to_[table_name]_table`
  - 단일 컬럼: `add_[column_name]_to_[table_name]_table`
  - 다중 컬럼: 모든 컬럼명을 `_and_`로 연결
- **컬럼 삭제**: `remove_[column1]_and_[column2]_from_[table_name]_table`
  - 단일 컬럼: `remove_[column_name]_from_[table_name]_table`
  - 다중 컬럼: 모든 컬럼명을 `_and_`로 연결
- **컬럼 변경**: `modify_[column1]_and_[column2]_in_[table_name]_table`
  - 단일 컬럼: `modify_[column_name]_in_[table_name]_table`
  - 다중 컬럼: 모든 컬럼명을 `_and_`로 연결
- **복합 변경** (추가+삭제+변경): `update_[main_feature]_fields_in_[table_name]_table`
  - 예: `update_user_profile_fields_in_users_table`

**예시**:

```bash
# 단일 컬럼 추가
add_email_to_users_table

# 다중 컬럼 추가
add_first_name_and_last_name_and_phone_to_users_table

# 단일 컬럼 삭제
remove_nickname_from_users_table

# 다중 컬럼 삭제
remove_old_status_and_legacy_flag_from_products_table

# 단일 컬럼 변경
modify_price_in_products_table

# 다중 컬럼 변경
modify_name_and_description_in_categories_table

# 복합 변경 (3개 이상의 컬럼 또는 추가/삭제/변경 혼합)
update_template_version_fields_in_templates_table
```

**패턴**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id()->comment('상품 ID');
            $table->string('name')->comment('상품명');
            $table->text('description')->comment('상품 설명');
            $table->decimal('price', 10, 2)->comment('상품 가격');
            $table->foreignId('category_id')
                ->comment('카테고리 ID')
                ->constrained('product_categories')
                ->onDelete('cascade');
            $table->boolean('is_active')
                ->default(true)
                ->comment('활성화 여부 (1: 활성화, 0: 비활성화)');
            $table->enum('status', ['draft', 'published', 'archived'])
                ->default('draft')
                ->comment('상품 상태 (draft: 임시저장, published: 게시, archived: 보관)');
            $table->foreignId('created_by')->nullable()->comment('생성자 ID');
            $table->foreignId('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();
            $table->softDeletes();

            // 인덱스
            $table->index('category_id');
            $table->index('is_active');
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

### 모듈/플러그인 마이그레이션 down() 메서드

```
주의: 모듈/플러그인은 삭제 시 마이그레이션 롤백이 빈번하게 발생
필수: down() 메서드에서 테이블/컬럼/인덱스 존재 여부 확인 후 삭제
✅ 필수: Laravel 11+ 에서는 Schema::getIndexes() 사용 (getDoctrineSchemaManager 미지원)
✅ 필수: NOT NULL 변경 시 NULL 데이터 존재 여부 확인
```

#### 왜 방어적 코딩이 필요한가?

모듈/플러그인 삭제 시 `delete_data: true` 옵션으로 마이그레이션 롤백이 실행됩니다. 이때:

| 문제 상황 | 발생 원인 | 결과 |
|----------|----------|------|
| 테이블 미존재 | 이전 롤백 실패, 수동 삭제 | SQL 오류 발생 |
| 인덱스 미존재 | 부분 롤백, 스키마 불일치 | SQL 오류 발생 |
| NULL 데이터 존재 | NOT NULL 변경 시도 | SQL 오류 발생 |

**오류 발생 시 해당 마이그레이션 롤백이 실패하고, 이후 마이그레이션도 롤백되지 않습니다.**

#### down() 메서드 작성 규칙

**1. 테이블 생성 마이그레이션 (create_*_table)**

```php
// ✅ DO: dropIfExists 사용 (기본 패턴)
public function down(): void
{
    Schema::dropIfExists('products');
}

// ❌ DON'T: drop 사용 (테이블 미존재 시 오류)
public function down(): void
{
    Schema::drop('products');  // 금지
}
```

**2. 컬럼/인덱스 추가 마이그레이션 (add_*_to_*_table)**

```php
// ✅ DO: 테이블, 인덱스, 컬럼 존재 여부 확인 후 삭제
public function down(): void
{
    // 1. 테이블 존재 여부 확인
    if (! Schema::hasTable('ecommerce_product_images')) {
        return;
    }

    // 2. 인덱스 존재 여부 확인 (Laravel 11+)
    $tableName = Schema::getConnection()->getTablePrefix().'ecommerce_product_images';
    $indexes = Schema::getIndexes('ecommerce_product_images');
    $indexNames = array_column($indexes, 'name');

    Schema::table('ecommerce_product_images', function (Blueprint $table) use ($tableName, $indexNames) {
        // 인덱스 존재 시에만 삭제
        if (in_array($tableName.'_temp_key_index', $indexNames)) {
            $table->dropIndex(['temp_key']);
        }

        // 컬럼 존재 시에만 삭제
        if (Schema::hasColumn('ecommerce_product_images', 'temp_key')) {
            $table->dropColumn('temp_key');
        }
    });
}

// ❌ DON'T: 존재 여부 확인 없이 삭제 (금지)
public function down(): void
{
    Schema::table('ecommerce_product_images', function (Blueprint $table) {
        $table->dropIndex(['temp_key']);  // 인덱스 미존재 시 오류
        $table->dropColumn('temp_key');   // 컬럼 미존재 시 오류
    });
}
```

**3. nullable 변경 마이그레이션**

```php
// ✅ DO: NULL 데이터 존재 여부 확인 후 NOT NULL 변경
public function down(): void
{
    if (! Schema::hasColumn('products', 'category_id')) {
        return;
    }

    // NULL 데이터가 있으면 NOT NULL 변경 불가
    $hasNullData = DB::table('products')
        ->whereNull('category_id')
        ->exists();

    if (! $hasNullData) {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')
                ->nullable(false)
                ->comment('카테고리 ID')
                ->change();
        });
    }
}

// ❌ DON'T: NULL 데이터 확인 없이 NOT NULL 변경 (금지)
public function down(): void
{
    Schema::table('products', function (Blueprint $table) {
        // NULL 데이터 존재 시 SQL 오류 발생
        $table->unsignedBigInteger('category_id')->nullable(false)->change();
    });
}
```

#### down() 메서드 체크리스트

모듈/플러그인 마이그레이션 `down()` 작성 시 반드시 확인:

```
□ 1. 테이블 존재 여부: Schema::hasTable() 사용
□ 2. 컬럼 존재 여부: Schema::hasColumn() 사용
□ 3. 인덱스 존재 여부: Schema::getIndexes() 사용 (Laravel 11+)
□ 4. NOT NULL 변경 시: NULL 데이터 존재 여부 확인
□ 5. 외래키 삭제 시: 외래키 존재 여부 확인
□ 6. dropIfExists() 사용: 테이블 삭제 시
```

#### Laravel 버전별 인덱스 확인 방법

```php
// Laravel 11+ (권장)
$indexes = Schema::getIndexes('table_name');
$indexNames = array_column($indexes, 'name');
if (in_array('index_name', $indexNames)) {
    // 인덱스 존재
}

// Laravel 10 이하 (Doctrine DBAL 필요)
$sm = Schema::getConnection()->getDoctrineSchemaManager();
$indexes = $sm->listTableIndexes($tableName);
if (isset($indexes['index_name'])) {
    // 인덱스 존재
}
```

---

### 외래 키 및 삭제 정책

```
필수: Service에서 명시적 삭제 (DB CASCADE에 의존한 삭제 금지)
필수: 어플리케이션(Service)에서 명시적으로 연관 데이터 삭제
✅ 필수: 삭제 순서 보장, 훅 실행, 로깅, 파일 삭제 등 어플리케이션 로직 처리
```

#### 왜 DB CASCADE에 의존하면 안 되는가?

DB CASCADE를 사용하면 다음 문제가 발생합니다:

| 문제 | 설명 |
|------|------|
| **훅 미실행** | `before_delete`, `after_delete` 훅이 실행되지 않음 |
| **파일 미삭제** | Storage에 저장된 파일이 남음 (이미지, 첨부파일 등) |
| **로깅 불가** | 삭제 이력을 추적할 수 없음 |
| **순서 미보장** | 삭제 순서를 제어할 수 없음 |
| **디버깅 어려움** | 어떤 데이터가 삭제되었는지 파악 어려움 |

#### 마이그레이션 작성 규칙

**마이그레이션에서 CASCADE 설정은 허용**되지만, **삭제 시에는 어플리케이션에서 명시적으로 처리**해야 합니다:

```php
// ✅ 마이그레이션: CASCADE 설정 가능 (안전망 역할)
$table->foreignId('product_id')
    ->comment('상품 ID')
    ->constrained('products')
    ->onDelete('cascade');  // DB 레벨 안전망

// ✅ 마이그레이션: RESTRICT 설정 (삭제 차단이 필요한 경우)
$table->foreignId('product_id')
    ->comment('상품 ID')
    ->constrained('products')
    ->restrictOnDelete();  // 참조 중이면 삭제 불가
```

#### Service 삭제 메서드 작성 규칙

**모든 연관 데이터는 Service에서 명시적으로 삭제**해야 합니다:

```php
// ✅ DO: 명시적 삭제 (필수)
public function delete(Product $product): bool
{
    HookManager::doAction('module.entity.before_delete', $product);

    return DB::transaction(function () use ($product) {
        // 1. 파일 물리적 삭제 (Storage)
        $this->deleteProductImageFiles($product);

        // 2. 연관 데이터 명시적 삭제 (순서 중요)
        $product->images()->delete();
        $product->options()->delete();
        $product->additionalOptions()->delete();
        $product->labelAssignments()->delete();
        $product->logs()->delete();
        $product->notice()->delete();
        $product->categories()->detach();  // 중간 테이블

        // 3. 메인 레코드 삭제
        $result = $this->repository->delete($product);

        HookManager::doAction('module.entity.after_delete', $product);

        return $result;
    });
}

// ❌ DON'T: CASCADE에 의존 (금지)
public function delete(Product $product): bool
{
    // 연관 데이터 삭제 없이 바로 삭제 - 금지!
    return $this->repository->delete($product);
}
```

#### 삭제 메서드 체크리스트

Service에서 `delete()` 메서드 작성 시 반드시 확인:

```
□ 1. 훅 실행 (before_delete, after_delete)
□ 2. DB 트랜잭션 사용
□ 3. 파일 삭제 (Storage에 저장된 이미지, 첨부파일 등)
□ 4. 모든 HasMany 관계 명시적 삭제
□ 5. 모든 HasOne 관계 명시적 삭제
□ 6. 모든 BelongsToMany 중간 테이블 detach
□ 7. TODO 주석으로 미구현 연관 데이터 명시
□ 8. 메인 레코드 삭제
```

#### 예외: RESTRICT 사용 케이스

비즈니스 규칙상 삭제를 차단해야 하는 경우 `RESTRICT`를 사용합니다:

```php
// 주문 이력이 있는 상품은 삭제 불가
$table->foreignId('product_id')
    ->constrained('products')
    ->restrictOnDelete();  // ✅ 적절한 사용

// Service에서 삭제 전 체크
public function checkCanDelete(Product $product): array
{
    $ordersCount = OrderOption::where('product_id', $product->id)->count();

    return [
        'canDelete' => $ordersCount === 0,
        'reason' => $ordersCount > 0
            ? __('messages.has_order_history', ['count' => $ordersCount])
            : null,
    ];
}
```

### 시더

**규칙**:
- 콘솔 메시지 (시작/완료/삭제 건수) 필수
- 메서드 분리 (delete*, create*)
- `truncate()` 대신 `delete()` 사용
- PHPDoc 주석 한국어

#### 시더 디렉토리 구조

```
설치 필수 시더와 샘플(개발용) 시더는 반드시 분리
✅ 설치 시더: database/seeders/ 루트에 배치
✅ 샘플 시더: database/seeders/Sample/ 하위에 배치
✅ 기본 실행(db:seed, module:seed, plugin:seed)은 설치 시더만 실행
✅ --sample 옵션 시에만 샘플 시더 추가 실행
```

**디렉토리 구조**:
```
database/seeders/
├── DatabaseSeeder.php         # 설치 시더 호출 + 조건부 샘플 호출
├── AdminUserSeeder.php        # 설치 필수
├── RolePermissionSeeder.php   # 설치 필수
└── Sample/                    # 샘플(개발용) 시더
    ├── DummyUserSeeder.php
    └── TemplateSeeder.php
```

**`HasSampleSeeders` 트레이트** (`app/Traits/HasSampleSeeders.php`):
- DatabaseSeeder에서 `use HasSampleSeeders;` 사용
- `shouldIncludeSample()`: `--sample` 옵션 여부 확인
- 커맨드에서 `setIncludeSample()` 호출로 전파

**DatabaseSeeder 패턴**:
```php
use App\Traits\HasSampleSeeders;

class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;

    public function run(): void
    {
        // 설치 필수 시더 (항상 실행)
        $this->call([
            AdminUserSeeder::class,
        ]);

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->call([
                Sample\DummyUserSeeder::class,
            ]);
        }
    }
}
```

**실행 명령어**:
```bash
# 설치 시더만 (기본)
php artisan db:seed
php artisan module:seed sirsoft-ecommerce

# 설치 + 샘플
php artisan db:seed --sample
php artisan module:seed sirsoft-ecommerce --sample

# 개별 샘플 시더
php artisan db:seed --class="Database\Seeders\Sample\DummyUserSeeder"
php artisan module:seed sirsoft-ecommerce --class=Sample\\ProductSeeder
```

**패턴**:
```php
<?php

namespace Database\Seeders\Core;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * 기본 관리자 정보
     */
    private array $defaultAdminUser = [
        'name' => '관리자',
        'email' => 'admin@example.com',
        'password' => 'password',
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('기본 관리자 사용자 생성을 시작합니다.');

        // 기존 데이터 삭제
        $this->deleteExistingUsers();

        // 새 데이터 생성
        $this->createAdminUser($this->defaultAdminUser);

        $this->command->info('기본 관리자 사용자가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 사용자 삭제
     *
     * @return void
     */
    private function deleteExistingUsers(): void
    {
        $deletedCount = User::where('email', $this->defaultAdminUser['email'])->delete();

        if ($deletedCount > 0) {
            $this->command->warn("기존 관리자 사용자 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 관리자 사용자 생성
     *
     * @param array $userData 사용자 데이터
     * @return void
     */
    private function createAdminUser(array $userData): void
    {
        $adminRole = Role::where('name', 'admin')->first();

        $user = User::create([
            'name' => $userData['name'],
            'email' => $userData['email'],
            'password' => bcrypt($userData['password']),
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($adminRole->id);

        $this->command->info("관리자 사용자가 생성되었습니다: {$user->email}");
    }
}
```

### 로케일 설정

```
MANDATORY: supported_locales와 translatable_locales는 config/app.php에서 관리
✅ 필수: 하드코딩 금지, config() 함수 사용 필수
```

#### supported_locales vs translatable_locales

**위치**: `config/app.php`

```php
// config/app.php
'supported_locales' => ['ko', 'en'],        // UI 언어 전환에 사용
'translatable_locales' => ['ko', 'en'],     // 다국어 필드 데이터 저장에 사용
```

#### supported_locales

**용도**: 시스템에서 지원하는 모든 UI 언어 목록

**사용처**:
- `SetLocale` 미들웨어: 사용자 언어 검증
- 템플릿 엔진: 언어 선택자 옵션 생성 (`$locales` 전역 변수)
- 프론트엔드: `template.json`의 `locales` 필드 (템플릿별 지원 언어)

**규칙**:
- 반드시 `config('app.supported_locales')`로 접근
- 하드코딩(`['ko', 'en']`) 금지
- 번역 파일(`/lang/{locale}/`)이 존재해야 함

**예시**:
```php
// ✅ DO: config 함수 사용
$supportedLocales = config('app.supported_locales', ['ko', 'en']);
if (in_array($locale, $supportedLocales)) {
    // ...
}

// ❌ DON'T: 하드코딩
if (in_array($locale, ['ko', 'en'])) {  // 금지
    // ...
}
```

#### translatable_locales

**용도**: 다국어 필드(JSON 타입)에서 허용하는 언어 목록

**사용처**:
- 다국어 테이블 필드 (`permissions.name`, `roles.description` 등)
- FormRequest 검증 (예: `UpdateModuleRequest`)
- Model Accessor/Mutator (`getLocalizedName()` 등)

**규칙**:
- 반드시 `config('app.translatable_locales')`로 접근
- 번역 파일이 없어도 데이터 저장은 허용 (DB 데이터만)
- 새 언어 추가 시 이 배열에 언어 코드 추가

**예시**:
```php
// ✅ DO: FormRequest에서 사용
public function rules(): array
{
    $locales = config('app.translatable_locales', ['ko', 'en']);
    $rules = [];

    foreach ($locales as $locale) {
        $rules["name.{$locale}"] = ['required', 'string'];
    }

    return $rules;
}

// ✅ DO: Model에서 사용
public function getLocalizedName(?string $locale = null): string
{
    $locale = $locale ?? app()->getLocale();
    $translatable = config('app.translatable_locales', ['ko', 'en']);

    // Locale별 폴백 처리
    // ...
}
```

#### 두 설정의 차이점

| 구분 | supported_locales | translatable_locales |
|------|------------------|---------------------|
| **용도** | UI 언어 전환 | 다국어 필드 데이터 |
| **번역 파일** | 필수 (`/lang/{locale}/`) | 선택 (없어도 됨) |
| **사용 예** | 언어 선택자, 미들웨어 | DB JSON 필드, FormRequest |
| **검증 위치** | 런타임 (미들웨어) | 데이터 입력 시 (FormRequest) |

#### 새 언어 추가 절차

1. **`config/app.php` 업데이트**:
   ```php
   'supported_locales' => ['ko', 'en', 'ja'],  // 일본어 추가
   'translatable_locales' => ['ko', 'en', 'ja'],
   ```

2. **번역 파일 생성** (supported_locales에 추가한 경우):
   ```bash
   mkdir -p lang/ja
   # 모든 번역 파일 복사 및 번역
   ```

3. **템플릿 메타데이터 업데이트**:
   ```json
   // templates/sirsoft-admin_basic/template.json
   {
     "locales": ["ko", "en", "ja"]
   }
   ```

### 다국어 (Backend)

**기본 지원 언어**:
- **한국어 (ko)**: 기본 언어
- **영어 (en)**: 필수 지원 언어

**규칙**:
- 모든 다국어 파일은 `ko`, `en` 두 언어를 필수로 제공
- 새로운 기능 추가 시 반드시 두 언어 모두 작성
- **언어 목록은 `config('app.supported_locales')`에서 관리**

**위치**:
- 코어: `/lang/{ko,en}/`
- 모듈: `/modules/[vendor-module]/src/lang/{ko,en}/`
- 플러그인: `/plugins/[vendor-plugin]/src/lang/{ko,en}/`

**네이밍**:
- 코어: 접두사 없음
- 모듈: `[vendor-module]::` 접두사 (예: `sirsoft-ecommerce::`)
- 플러그인: `[vendor-plugin]::` 접두사

**예시**:
```php
// modules/_bundled/sirsoft-ecommerce/src/lang/ko/products.php

return [
    'title' => '상품 관리',
    'create' => '상품 생성',
    'edit' => '상품 수정',
    'delete_confirm' => '이 상품을 삭제하시겠습니까?',
    'messages' => [
        'created' => '상품이 생성되었습니다.',
        'updated' => '상품이 수정되었습니다.',
        'deleted' => '상품이 삭제되었습니다.',
    ],
];

// 사용
__('sirsoft-ecommerce::products.title');
__('sirsoft-ecommerce::products.messages.created');
```

### 코어 테이블 다국어 지원

```
MANDATORY: permissions, roles, menus, modules, plugins 테이블 다국어 필수
✅ 필수: JSON 구조 사용 {"ko": "한국어", "en": "English"}
```

**지원 테이블 및 필드**:

| 테이블 | 다국어 필드 |
|--------|------------|
| permissions | name, description |
| roles | name, description |
| menus | name |
| modules | name, description |
| plugins | name, description |

**JSON 구조**:
```json
{
  "ko": "한국어 텍스트",
  "en": "English text"
}
```

**모델 사용법**:

```php
// 현재 로케일의 이름 반환
$permission->getLocalizedName();      // 현재 로케일
$permission->getLocalizedName('ko');  // 한국어
$permission->getLocalizedName('en');  // 영어

// Accessor 사용
$permission->localized_name;          // 현재 로케일
$permission->localized_description;    // 현재 로케일

// 예시
app()->setLocale('ko');
echo $permission->getLocalizedName();  // "사용자 조회"

app()->setLocale('en');
echo $permission->getLocalizedName();  // "View Users"
```

**시더 작성 규칙**:

```php
// ✅ DO: 다국어 배열 구조
Permission::create([
    'identifier' => 'users.read',
    'name' => [
        'ko' => '사용자 조회',
        'en' => 'View Users',
    ],
    'description' => [
        'ko' => '사용자 목록을 조회할 수 있습니다.',
        'en' => 'Can view user list.',
    ],
]);

Menu::create([
    'name' => [
        'ko' => '대시보드',
        'en' => 'Dashboard',
    ],
    'slug' => 'dashboard',
]);

// ❌ DON'T: 문자열 사용 (레거시, 권장하지 않음)
Permission::create([
    'identifier' => 'users.read',
    'name' => '사용자 조회',  // 지양
]);
```

**ModuleInterface, PluginInterface 다국어 지원**:

```php
// ✅ DO: 다국어 배열 반환 (권장)
public function getName(): array
{
    return [
        'ko' => '이커머스 모듈',
        'en' => 'Ecommerce Module',
    ];
}

public function getDescription(): array
{
    return [
        'ko' => '온라인 쇼핑몰 기능을 제공합니다.',
        'en' => 'Provides online shopping features.',
    ];
}

// 역호환: 문자열 반환 (자동 변환됨)
public function getName(): string
{
    return 'Ecommerce Module';  // 자동으로 ['ko' => '...', 'en' => '...']로 변환
}
```

**FormRequest 역호환성 처리**:

```php
// UpdateModuleRequest.php 예시
protected function prepareForValidation(): void
{
    $locales = config('app.translatable_locales', ['ko', 'en']);

    // name이 문자열로 들어온 경우 배열로 자동 변환
    if ($this->has('name') && is_string($this->name)) {
        $nameArray = [];
        foreach ($locales as $locale) {
            $nameArray[$locale] = $this->name;
        }
        $this->merge(['name' => $nameArray]);
    }
}
```

**폴백 동작**:

1. 요청한 로케일에 번역이 있으면 반환
2. 없으면 'ko' (기본 로케일)로 폴백
3. 'ko'도 없으면 'en'으로 폴백
4. 둘 다 없으면 빈 문자열 반환

**엣지 케이스**:

```php
// 한국어만 있는 경우
$permission->name = ['ko' => '테스트'];
$permission->getLocalizedName('en');  // "테스트" (ko로 폴백)

// 영어만 있는 경우
$role->name = ['en' => 'Test'];
$role->getLocalizedName('ko');  // "Test" (en으로 폴백)

// 빈 번역
$menu->name = ['ko' => '메뉴', 'en' => ''];
$menu->getLocalizedName('en');  // "" (빈 문자열 반환)

// NULL 값
$module->description = null;
$module->getLocalizedDescription();  // "" (빈 문자열 반환)
```

---

