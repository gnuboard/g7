<?php

namespace Tests\Unit\Notifications;

use App\Http\Resources\NotificationDefinitionResource;
use App\Models\NotificationDefinition;
use App\Notifications\Identity\IdentityNotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * 알림 템플릿 편집 창 「사용 가능한 변수」 설명의 언어
 *
 * 알림 정의의 변수 설명은 선언(config/core.php·확장 getNotificationDefinitions())에 한국어 문자열로만
 * 적혀 있고 정의 응답이 그 문자열을 그대로 실었다. 그래서 영어·일본어 화면에서도 편집 창의 변수 설명이
 * 「— 수신자(관리자) 이름」 처럼 한국어로 보였다(7.1.0 실측 QE-O4 — 코어·게시판·이커머스·GDPR·문자 발송·
 * 환율 편집 창 공통). 변수 설명은 운영자가 고칠 수 없는 시스템 문구라 언어 파일 키로 옮기고, 정의 응답이
 * 요청 언어로 바꿔 싣는다. 언어 파일에 없는 변수는 선언 문자열을 그대로 둔다.
 */
class NotificationVariableDescriptionLocaleTest extends TestCase
{
    /** @var array<string, array{lang: string, class: string, source: string, pack: string}> 변수 설명을 선언한 확장 */
    private const EXTENSIONS = [
        'sirsoft-board' => [
            'lang' => 'modules/_bundled/sirsoft-board/src/lang',
            'class' => 'Modules\\Sirsoft\\Board\\Module',
            'source' => 'modules/_bundled/sirsoft-board/module.php',
            'pack' => 'lang-packs/_bundled/g7-module-sirsoft-board-ja/backend/ja/messages.php',
        ],
        'sirsoft-ecommerce' => [
            'lang' => 'modules/_bundled/sirsoft-ecommerce/src/lang',
            'class' => 'Modules\\Sirsoft\\Ecommerce\\Module',
            'source' => 'modules/_bundled/sirsoft-ecommerce/module.php',
            'pack' => 'lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja/messages.php',
        ],
        'sirsoft-gdpr' => [
            'lang' => 'plugins/_bundled/sirsoft-gdpr/lang',
            'class' => 'Plugins\\Sirsoft\\Gdpr\\Plugin',
            'source' => 'plugins/_bundled/sirsoft-gdpr/plugin.php',
            'pack' => 'lang-packs/_bundled/g7-plugin-sirsoft-gdpr-ja/backend/ja/messages.php',
        ],
        'sirsoft-koreaexim' => [
            'lang' => 'plugins/_bundled/sirsoft-koreaexim/lang',
            'class' => 'Plugins\\Sirsoft\\Koreaexim\\Plugin',
            'source' => 'plugins/_bundled/sirsoft-koreaexim/plugin.php',
            'pack' => 'lang-packs/_bundled/g7-plugin-sirsoft-koreaexim-ja/backend/ja/messages.php',
        ],
        'sirsoft-message_bizppurio' => [
            'lang' => 'plugins/_bundled/sirsoft-message_bizppurio/lang',
            'class' => 'Plugins\\Sirsoft\\MessageBizppurio\\Plugin',
            'source' => 'plugins/_bundled/sirsoft-message_bizppurio/plugin.php',
            'pack' => 'lang-packs/_bundled/g7-plugin-sirsoft-message_bizppurio-ja/backend/ja/messages.php',
        ],
    ];

    /** 비교한 변수 수의 하한 — 모집단 파생이 좁아지면(선언을 못 읽으면) 시험이 빈 채로 통과하지 않게 한다. */
    private const POPULATION_FLOOR = 300;

    /** 한글 음절·자모 — `\p{Hangul}` 은 문자 확장 속성 때문에 일본어 문장부호(「、」)까지 잡는다. */
    private const HANGUL = '/[\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7A3}]/u';

    /**
     * 확장 언어 파일 네임스페이스를 번들 원본 경로로 잇는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 번들 원본의 언어 파일을 읽는다 — 설치본(modules/{id})은 업데이트 전이면 옛 파일이다.
        foreach (self::EXTENSIONS as $identifier => $extension) {
            Lang::addNamespace($identifier, base_path($extension['lang']));
        }
    }

    /**
     * 시험이 바꾼 앱 언어를 기본 언어로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        App::setLocale((string) config('app.fallback_locale', 'ko'));

        parent::tearDown();
    }

    /**
     * 같은 변수라도 화면 언어로 설명이 나온다 — 영어 화면에 한국어가 섞이지 않는다.
     *
     * @scenario case=ecommerce_admin_variant
     *
     * @effects notification_variable_description_follows_locale
     *
     * @return void 반환값 없음
     */
    public function test_variable_description_follows_request_locale(): void
    {
        $definition = new NotificationDefinition([
            'type' => 'new_order_admin',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);

        App::setLocale('en');
        $this->assertSame('Recipient (administrator) name', $definition->variableDescription('name', '수신자(관리자) 이름'));
        $this->assertSame('Order number', $definition->variableDescription('order_number', '주문번호'));

        App::setLocale('ko');
        $this->assertSame('수신자(관리자) 이름', $definition->variableDescription('name', '수신자(관리자) 이름'));
    }

    /**
     * 언어 파일에 없는 변수(운영자·외부 확장이 새로 선언한 변수)는 선언 문자열을 그대로 둔다.
     *
     * @scenario case=undeclared_variable
     *
     * @effects notification_variable_description_falls_back_to_declaration
     *
     * @return void 반환값 없음
     */
    public function test_unknown_variable_keeps_declared_description(): void
    {
        App::setLocale('en');

        $core = new NotificationDefinition(['type' => 'welcome', 'extension_type' => 'core', 'extension_identifier' => 'core']);
        $this->assertSame('선언 그대로', $core->variableDescription('qa_unknown_variable', '선언 그대로'));

        $external = new NotificationDefinition(['type' => 'any', 'extension_type' => 'module', 'extension_identifier' => 'vendor-unknown']);
        $this->assertSame('외부 확장 설명', $external->variableDescription('name', '외부 확장 설명'));
    }

    /**
     * 정의 응답(편집 창이 읽는 자리)의 variables 가 요청 언어로 나간다.
     *
     * @scenario case=definition_response
     *
     * @effects notification_definition_response_localizes_variables
     *
     * @return void 반환값 없음
     */
    public function test_definition_resource_ships_localized_variable_descriptions(): void
    {
        App::setLocale('en');

        $definition = new NotificationDefinition([
            'type' => 'inquiry_replied',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '문의 답변', 'en' => 'Inquiry answered'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자(문의자) 이름'],
                ['key' => 'inquiry_url', 'description' => '문의 상세 URL (마이페이지)'],
                ['key' => 'qa_unknown_variable', 'description' => '선언 그대로'],
            ],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $array = (new NotificationDefinitionResource($definition))->toArray(Request::create('/test'));

        $this->assertSame([
            ['key' => 'name', 'description' => 'Recipient (inquirer) name'],
            ['key' => 'inquiry_url', 'description' => 'Inquiry details URL (My Page)'],
            ['key' => 'qa_unknown_variable', 'description' => '선언 그대로'],
        ], $array['variables']);
    }

    /**
     * 코어·번들 확장이 선언한 변수 설명 전부가 언어 파일에 있다 — ko 는 선언 문자열과 같고, en 은 한글이 없다.
     *
     * @scenario case=declared_population
     *
     * @effects every_declared_notification_variable_is_translated
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_variable_has_ko_and_en_description(): void
    {
        $compared = 0;
        $problems = [];

        foreach ($this->declaredVariables() as [$identifier, $type, $key, $declared]) {
            $definition = new NotificationDefinition([
                'type' => $type,
                'extension_type' => $identifier === 'core' ? 'core' : 'module',
                'extension_identifier' => $identifier,
            ]);
            $compared++;

            App::setLocale('ko');
            $ko = $definition->variableDescription($key, '__fallback__');
            App::setLocale('en');
            $en = $definition->variableDescription($key, '__fallback__');

            if ($ko !== $declared) {
                $problems[] = "{$identifier} {$type}.{$key} ko — 선언 「{$declared}」 / 언어 파일 「{$ko}」";
            }
            if ($en === '__fallback__' || preg_match(self::HANGUL, (string) $en)) {
                $problems[] = "{$identifier} {$type}.{$key} en — 「{$en}」";
            }
        }

        $this->assertGreaterThanOrEqual(self::POPULATION_FLOOR, $compared, '비교한 변수가 너무 적습니다 — 선언을 읽지 못했습니다');
        $this->assertSame([], $problems, "변수 설명 번역 누락·불일치:\n".implode("\n", $problems));
    }

    /**
     * 일본어 언어팩이 ko 와 같은 변수 설명 키를 모두 갖고, 값에 한글이 없다.
     *
     * @scenario case=ja_pack_parity
     *
     * @effects notification_variable_description_ja_pack_parity
     *
     * @return void 반환값 없음
     */
    public function test_ja_language_packs_cover_every_variable_description_key(): void
    {
        $pairs = [
            ['lang/ko/notification.php', 'lang-packs/_bundled/g7-core-ja/backend/ja/notification.php', 'variables'],
        ];
        foreach (self::EXTENSIONS as $extension) {
            $pairs[] = [$extension['lang'].'/ko/messages.php', $extension['pack'], 'notification_variables'];
        }

        $problems = [];
        foreach ($pairs as [$koPath, $jaPath, $root]) {
            $ko = $this->flatten((array) ((require base_path($koPath))[$root] ?? []));
            $ja = $this->flatten((array) ((require base_path($jaPath))[$root] ?? []));

            $this->assertNotEmpty($ko, "{$koPath}: {$root} 가 비었습니다");
            foreach (array_keys($ko) as $key) {
                if (! isset($ja[$key])) {
                    $problems[] = "{$jaPath} {$root}.{$key} 없음";
                } elseif (preg_match(self::HANGUL, $ja[$key])) {
                    $problems[] = "{$jaPath} {$root}.{$key} 한글 — 「{$ja[$key]}」";
                }
            }
            foreach (array_diff_key($ja, $ko) as $key => $_) {
                $problems[] = "{$jaPath} {$root}.{$key} ko 에 없는 키";
            }
        }

        $this->assertSame([], $problems, "ja 언어팩 변수 설명 키 불일치:\n".implode("\n", $problems));
    }

    /**
     * 코어·번들 확장이 선언한 (확장, 정의, 변수 키, 설명) 목록.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> [확장 식별자, 알림 정의 type, 변수 키, 선언된 한국어 설명] 행 목록
     */
    private function declaredVariables(): array
    {
        $rows = [];

        foreach ((array) config('core.notification_definitions', []) as $type => $definition) {
            foreach ((array) ($definition['variables'] ?? []) as $variable) {
                $rows[] = ['core', (string) $type, (string) $variable['key'], (string) $variable['description']];
            }
        }

        foreach (IdentityNotificationType::commonVariables() as $variable) {
            $rows[] = ['core', IdentityNotificationType::default(), (string) $variable['key'], (string) $variable['description']];
        }

        foreach (self::EXTENSIONS as $identifier => $extension) {
            $class = $extension['class'];
            if (! class_exists($class)) {
                require_once base_path($extension['source']);
            }

            foreach ((new $class)->getNotificationDefinitions() as $index => $definition) {
                $type = (string) ($definition['type'] ?? $index);
                foreach ((array) ($definition['variables'] ?? []) as $variable) {
                    $rows[] = [$identifier, $type, (string) $variable['key'], (string) $variable['description']];
                }
            }
        }

        return $rows;
    }

    /**
     * 중첩 배열을 점 경로 => 문자열 로 편다.
     *
     * @param  array<string, mixed>  $tree  언어 파일 하위 트리
     * @param  string  $prefix  앞에 붙일 점 경로(재귀 호출용, 처음에는 빈 문자열)
     * @return array<string, string> 점 경로 => 값
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}
