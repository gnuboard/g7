<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * 게시판 요청 검증 문구의 칸 이름 회귀 테스트 (7.1.0 전수 실측 O-K4F-1)
 *
 * 요청이 attributes() 로 이름을 주지 않은 칸은 검증 문구의 :attribute 자리에 필드 키가 그대로 보였다
 * (「선택한 sort 값이 올바르지 않습니다.」「The board id field must be an integer.」). 게시판 요청 54개를
 * 실제 검증기로 만들어 규칙마다 문구를 조립하고, 칸 이름 자리에 필드 키가 남은 곳이 없는지 본다.
 * 칸 이름은 요청이 정한 것이 먼저고, 없으면 게시판 공용 칸 이름(validation.fallback_attributes)을 쓴다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/validation-attribute-name.yaml 참조.
 */
#[Group('board')]
class ValidationAttributeNameTest extends BoardTestCase
{
    /** 문구를 만들지 않는 규칙(검증 흐름만 바꾸는 규칙) */
    private const FLOW_RULES = ['Nullable', 'Sometimes', 'Bail', 'Exclude', 'ExcludeIf', 'ExcludeUnless', 'ExcludeWith', 'ExcludeWithout', 'Present'];

    /**
     * 모듈 루트 경로를 돌려줍니다.
     *
     * @return string 모듈 루트
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 번역을 이 테스트가 놓인 모듈 원본(src/lang)에서 읽게 합니다.
     *
     * @return void 반환값 없음
     */
    private function useModuleSourceTranslations(): void
    {
        $translator = $this->app['translator'];
        $translator->addNamespace('sirsoft-board', $this->moduleRoot().'/src/lang');
        $translator->setLoaded([]);
    }

    /**
     * 게시판 요청 클래스 이름을 모두 돌려줍니다 (Concerns 제외).
     *
     * @return array<int, class-string<FormRequest>> 요청 클래스
     */
    private function requestClasses(): array
    {
        $base = $this->moduleRoot().'/src/Http/Requests';
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_contains($path, '/Concerns/')) {
                continue;
            }
            $relative = substr($path, strlen(str_replace('\\', '/', $base)) + 1, -4);
            $classes[] = 'Modules\\Sirsoft\\Board\\Http\\Requests\\'.str_replace('/', '\\', $relative);
        }
        sort($classes);

        return $classes;
    }

    /**
     * 요청 하나의 검증기를 만듭니다 (와일드카드 칸은 첫 항목을 채워 펼친다).
     *
     * @param  class-string<FormRequest>  $class  요청 클래스
     * @return Validator 검증기
     */
    private function makeValidator(string $class): Validator
    {
        $slug = $this->board->slug;
        $routeResolver = function () use ($slug) {
            $route = new Route(['POST'], '/probe/{slug}/{id?}', []);
            $route->bind(Request::create("/probe/{$slug}/1", 'POST'));

            return $route;
        };

        $probe = $class::create('/probe', 'POST');
        $probe->setContainer($this->app)->setRouteResolver($routeResolver);
        $rules = $this->app->call([$probe, 'rules']);

        $data = [];
        foreach (array_keys($rules) as $key) {
            if (str_contains($key, '*')) {
                data_set($data, str_replace('*', '0', $key), 'x');
            }
        }

        $request = $class::create('/probe', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect'])->setRouteResolver($routeResolver);
        $method = new ReflectionMethod($request, 'getValidatorInstance');

        return $method->invoke($request);
    }

    /**
     * 현재 로케일에서 칸 이름 자리에 필드 키가 남은 문구를 모읍니다.
     *
     * @return array<int, string> 「요청 | 칸 | 규칙 | 문구」 목록
     */
    private function messagesShowingFieldKeys(): array
    {
        $found = [];
        foreach ($this->requestClasses() as $class) {
            $validator = $this->makeValidator($class);
            $getMessage = new ReflectionMethod($validator, 'getMessage');
            $ruleKeys = array_keys($validator->getRules());

            foreach ($validator->getRules() as $attribute => $rules) {
                foreach ($rules as $rule) {
                    [$name, $parameters] = ValidationRuleParser::parse($rule);
                    if (! is_string($name) || $name === '' || in_array($name, self::FLOW_RULES, true)) {
                        continue;
                    }

                    $raw = $getMessage->invoke($validator, $attribute, $name);
                    if (! is_string($raw) || ! preg_match('/:attribute|:other|:values|:date/i', $raw)) {
                        continue;
                    }

                    $display = $validator->getDisplayableAttribute($attribute);
                    $message = $validator->makeReplacements($raw, $attribute, $name, $parameters);
                    $leaked = preg_match('/:attribute/i', $raw)
                        && in_array($display, [$attribute, str_replace('_', ' ', Str::snake($attribute))], true);

                    // :other·:values·:date 자리에 다른 칸의 키가 들어간 경우 (여러 낱말 키만 — 일반 낱말과 겹치지 않게)
                    foreach ($ruleKeys as $key) {
                        if (! preg_match('/[_.]/', $key)) {
                            continue;
                        }
                        foreach ([$key, str_replace('_', ' ', $key)] as $form) {
                            if (str_contains($message, $form)) {
                                $leaked = true;
                            }
                        }
                    }

                    if ($leaked || str_contains($display, '::')) {
                        $found[] = class_basename($class)." | {$attribute} | {$name} | {$message}";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 게시판 요청 전부, ko·en 모두 칸 이름 자리에 필드 키가 남은 문구가 없다.
     *
     * @scenario case=request_scan
     *
     * @effects no_field_key_in_board_validation_messages
     *
     * @return void 반환값 없음
     */
    public function test_board_request_messages_never_show_field_keys(): void
    {
        $this->useModuleSourceTranslations();

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->app['translator']->setLoaded([]);

            $found = $this->messagesShowingFieldKeys();

            $this->assertSame([], $found, "{$locale}: 칸 이름 자리에 필드 키가 남은 문구 ".count($found)."건\n".implode("\n", $found));
        }
    }

    /**
     * 회원 「내 반응」 정렬과 관리자 게시판 목록 정렬의 거절 문구가 칸 이름을 쓴다 (API 응답).
     *
     * @scenario case=api_sort_message
     *
     * @effects sort_rejection_names_the_field
     *
     * @return void 반환값 없음
     */
    public function test_sort_rejection_messages_name_the_field(): void
    {
        $this->useModuleSourceTranslations();

        $member = User::factory()->create(['language' => 'ko']);
        $member->roles()->attach(Role::where('identifier', 'user')->value('id'));

        $expected = [
            'ko' => '선택한 정렬 값이 올바르지 않습니다.',
            'en' => 'The selected Sort Order is invalid.',
        ];
        foreach ($expected as $locale => $message) {
            $member->forceFill(['language' => $locale])->save();
            app()->setLocale($locale);
            $this->app['translator']->setLoaded([]);

            $this->actingAs($member->fresh())->withHeader('Accept-Language', $locale)
                ->getJson('/api/modules/sirsoft-board/me/reactions?sort=sideways')
                ->assertStatus(422)
                ->assertJsonPath('errors.sort.0', $message);
        }
    }

    /**
     * 일본어 팩이 게시판 공용 칸 이름과 설정 칸 이름을 ko 와 같은 키로 모두 갖는다
     * (빠지면 ja 에서 그 칸만 필드 키로 보인다 — 배열을 통째로 읽으므로 ko 로 폴백하지 않는다).
     *
     * @scenario case=ja_pack_parity
     *
     * @effects ja_pack_has_every_field_name
     *
     * @return void 반환값 없음
     */
    public function test_ja_pack_has_every_shared_field_name(): void
    {
        $ko = require $this->moduleRoot().'/src/lang/ko/validation.php';
        $jaPath = dirname($this->moduleRoot(), 3).'/lang-packs/_bundled/g7-module-sirsoft-board-ja/backend/ja/validation.php';
        $this->assertFileExists($jaPath);
        $ja = require $jaPath;

        foreach (['fallback_attributes' => $ko['fallback_attributes'], 'attributes.settings' => $ko['attributes']['settings']] as $group => $koNames) {
            $jaNames = data_get($ja, $group);
            $this->assertIsArray($jaNames, "ja {$group} 없음");
            $missing = array_keys(array_diff_key($koNames, $jaNames));
            $this->assertSame([], $missing, "ja {$group} 에 없는 칸: ".implode(', ', $missing));
        }
    }
}
