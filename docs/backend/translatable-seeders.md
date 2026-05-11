# 다국어 시더 인터페이스 (Translatable Seeders)

## TL;DR (5초 요약)

```text
1. 다국어 JSON 컬럼(name 등)을 시드하는 확장 entity 시더는 TranslatableSeederInterface 구현 필수
2. App\Concerns\Seeder\HasTranslatableSeeder trait 사용 → resolveTranslatedDefaults() 가 활성 lang pack 머지 자동 처리
3. 시더는 getDefaults() 에 ko/en 만 정의, ja 등은 lang pack seed/{entity}.json 자동 빌드 (build-language-pack-ja.cjs)
4. audit 룰 seeder-translation-filter 가 누락 검출 (error severity)
5. 코어 시더(NotificationDefinitionSeeder 등)는 별도 LoadsConfigSeedWithLangPackFilter trait 패턴 (config 기반)
```

## 배경

활성 언어팩의 `seed/{entity}.json` 파일을 entity 시더가 머지하는 인프라가 7.0.0-beta.4 부터 정식화되었으나, 시더가 직접 `HookManager::applyFilters()` 를 호출하는 패턴이라 누락 시 회귀가 발생했다. (예: 7.0.0-beta.4 직전 `ShippingCarrierSeeder` 에서 ja 라벨 fallback 회귀)

7.0.0-beta.5 부터 `TranslatableSeederInterface` + `HasTranslatableSeeder` trait 로 컴파일 타임 / audit 단계에서 강제하도록 인프라화.

## 인터페이스

`App\Contracts\Seeder\TranslatableSeederInterface`:

```php
public function getExtensionIdentifier(): string;  // 'sirsoft-ecommerce' (코어는 '')
public function getTranslatableEntity(): string;   // 'shipping_carriers'
public function getMatchKey(): string;             // 'code' | 'slug' | 'key' | 'identifier' | 'id'
public function getDefaults(): array;              // ko/en 다국어 JSON 포함 entry 배열
```

## 트레이트

`App\Concerns\Seeder\HasTranslatableSeeder`:

```php
protected function resolveTranslatedDefaults(): array  // run() 안에서 호출, ja 등 활성 lang pack 자동 머지
protected function resolveTranslationFilterName(): string  // 'seed.{ext}.{entity}.translations'
```

## 표준 사용 패턴

```php
use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\Helpers\GenericEntitySyncHelper;
use Illuminate\Database\Seeder;

class ShippingCarrierSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    public function getExtensionIdentifier(): string { return 'sirsoft-ecommerce'; }
    public function getTranslatableEntity(): string { return 'shipping_carriers'; }
    public function getMatchKey(): string { return 'code'; }

    public function getDefaults(): array
    {
        return [
            ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'], /* ... */],
        ];
    }

    public function run(): void
    {
        $helper = app(GenericEntitySyncHelper::class);
        $codes = [];

        foreach ($this->resolveTranslatedDefaults() as $row) {
            $helper->sync(ShippingCarrier::class, ['code' => $row['code']], $row);
            $codes[] = $row['code'];
        }

        $helper->cleanupStale(ShippingCarrier::class, [], 'code', $codes);
    }
}
```

## 시더 메서드 계약

언어팩 시드(`seed/{entity}.json`) 작성·재생성 시점에 시더 인스턴스의 `getTranslatableEntity()` / `getMatchKey()` / `getDefaults()` 가 호출된다. 시더 측에서:

- `getTranslatableEntity()` 가 시드 파일명 prefix 와 일치해야 한다 (불일치 시 매칭 실패).
- `getMatchKey()` 가 반환하는 키 조합이 시드 항목의 식별자로 사용된다.
- `getDefaults()` 가 ko 기준 다국어 필드를 반환한다.

## audit 룰

`seeder-translation-filter` (severity: error):
- 코어 시더 (NotificationDefinitionSeeder/IdentityMessageDefinitionSeeder) — `applyFilters('seed.X.translations', ...)` 발화 필수
- ModuleManager/PluginManager — `sync*Definitions/*Messages` 본문에 `applyFilters` 발화 필수
- 확장 entity 시더 (`(modules|plugins)/_bundled/<id>/database/seeders/<X>Seeder.php`) — 다국어 JSON 컬럼 시드 시 `TranslatableSeederInterface` 구현 + `HasTranslatableSeeder` trait 사용 필수

면제 대상 (단일 entity 패턴 미적용):
- `DatabaseSeeder.php` (시더 진입점)
- `TestingSeeder.php` (테스트 픽스처)
- `*SampleSeeder.php` (샘플 데이터)

## 코어 시더와의 차이

- **확장 entity 시더** (BoardType/ShippingCarrier 등): 시더 자체가 데이터 SSoT → `TranslatableSeederInterface` + `HasTranslatableSeeder`
- **코어 시더** (NotificationDefinition/IdentityMessageDefinition/Permission/Role/Menu): `config/core.php` 가 데이터 SSoT → 시더는 `LoadsConfigSeedWithLangPackFilter` trait 의 `loadConfigSeed(<configKey>, <filterName>)` 호출

두 패턴 모두 `seed.{X}.translations` 필터를 발화하므로 audit 룰 통과는 동일.

## 참고

- 인터페이스: [TranslatableSeederInterface](../../app/Contracts/Seeder/TranslatableSeederInterface.php)
- 트레이트: [HasTranslatableSeeder](../../app/Concerns/Seeder/HasTranslatableSeeder.php)
- 단위 테스트: [HasTranslatableSeederTest](../../tests/Unit/Concerns/Seeder/HasTranslatableSeederTest.php)
- 라이프사이클 회귀 가드: [BoardLanguagePackSeederTriggerTest](../../modules/_bundled/sirsoft-board/tests/Feature/LanguagePack/BoardLanguagePackSeederTriggerTest.php), `EcommerceLanguagePackSeederTriggerTest`
