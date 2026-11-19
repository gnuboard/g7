<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 회원 항목(이름, 닉네임, 휴대폰 등)의 이름과 예시 글이 언어팩 seed 로 번역되는지 검증한다 (7.2.0).
 *
 * 코어 항목의 이름은 `config('core.fields')` 에 ko, en 만 적혀 있고 다른 언어는 활성 코어 언어팩의
 * `lang-packs/_bundled/g7-core-ja/seed/fields.json` 이 시딩 필터(`core.fields.config`)로 더한다. 번들 팩에 그 파일이 없으면 일본어 사이트의
 * 가입 화면, 내 정보, 관리자 회원 폼, 환경설정의 가입 항목 표가 코어 항목만 한국어로 보인다.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 member-field-language-pack-seed.yaml).
 */
class LanguagePackFieldSeedTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 테스트가 만든 팩 디렉터리 */
    private array $packDirs = [];

    /**
     * 만든 팩 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->packDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /**
     * seed/fields.json 을 가진 활성 코어 ja 팩을 만듭니다.
     *
     * @param  array<string, array<string, array<string, string>>>  $seed  집합 식별자와 항목 키별 번역
     * @return LanguagePack 생성된 팩
     */
    private function makeCorePack(array $seed): LanguagePack
    {
        $identifier = 'test-core-ja-fields';
        $dir = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($dir.'/seed');
        File::put($dir.'/seed/fields.json', json_encode($seed, JSON_UNESCAPED_UNICODE));
        $this->packDirs[] = $dir;

        $pack = LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->forgetInstance(LanguagePackSeedInjector::class);

        return $pack;
    }

    /**
     * 시딩 필터가 활성 코어 ja 팩의 seed 로 코어 항목의 이름과 예시 글에 ja 를 더하고, 선언에 없는 키는 버린다.
     *
     * @scenario case=filter_injects
     *
     * @effects core_pack_injects_member_field_names
     *
     * @return void 반환값 없음
     */
    public function test_core_seed_filter_injects_field_names(): void
    {
        $this->makeCorePack([
            'core.user' => [
                'name' => ['name' => '名前', 'placeholder' => '名前を入力してください'],
                'not_declared' => ['name' => '無視'],
            ],
            'other.set' => ['name' => ['name' => '別の集合']],
        ]);

        $fields = collect($this->app->make(LanguagePackSeedInjector::class)->injectCoreFields((array) config('core.fields')))
            ->keyBy('key');

        $this->assertSame('名前', $fields['name']['name']['ja']);
        $this->assertSame('名前を入力してください', $fields['name']['placeholder']['ja']);
        $this->assertSame('이름', $fields['name']['name']['ko'], '기존 언어의 글자는 그대로다');
        $this->assertArrayNotHasKey('ja', $fields['nickname']['name'], 'seed 에 없는 항목은 건드리지 않는다');
        $this->assertFalse($fields->has('not_declared'));
    }

    /**
     * 번들 ja 코어 팩의 seed/fields.json 이 코어 항목 전부의 이름을, 예시 글이 있는 항목은 예시 글도 싣는다.
     *
     * 코어에 항목을 더하고 팩에 번역을 넣지 않으면 여기서 드러난다.
     *
     * @scenario case=bundled_pack_covers_core_fields
     *
     * @effects bundled_ja_pack_translates_every_core_member_field
     *
     * @return void 반환값 없음
     */
    public function test_bundled_ja_pack_covers_every_core_field(): void
    {
        $file = base_path('lang-packs/_bundled/g7-core-ja/seed/fields.json');
        $this->assertFileExists($file);
        $seed = json_decode((string) File::get($file), true);
        $this->assertIsArray($seed);

        $declared = (array) config('core.fields');
        $this->assertNotSame([], $declared, '코어 항목 선언이 비었다 — 아래 단언이 공허하게 참이 된다');

        $missing = [];
        foreach ($declared as $field) {
            $entry = $seed[$field['field_set']][$field['key']] ?? [];
            foreach (['name', 'placeholder', 'description'] as $attribute) {
                $hasSource = is_array($field[$attribute] ?? null) && ($field[$attribute]['ko'] ?? '') !== '';
                if ($hasSource && (! is_string($entry[$attribute] ?? null) || $entry[$attribute] === '')) {
                    $missing[] = $field['field_set'].'.'.$field['key'].'.'.$attribute;
                }
            }
        }

        $this->assertSame([], $missing, '번들 ja 코어 팩의 seed/fields.json 에 번역이 없는 코어 항목 글자가 있다: '.implode(', ', $missing));

        // 선언에서 사라진 항목의 번역이 남아 있지 않다
        $declaredKeys = collect($declared)->map(fn (array $field) => $field['field_set'].'.'.$field['key'])->all();
        $stale = [];
        foreach ($seed as $set => $entries) {
            foreach (array_keys((array) $entries) as $key) {
                if (! in_array($set.'.'.$key, $declaredKeys, true)) {
                    $stale[] = $set.'.'.$key;
                }
            }
        }
        $this->assertSame([], $stale, '선언에 없는 항목의 번역이 seed 에 남아 있다: '.implode(', ', $stale));
    }
}
