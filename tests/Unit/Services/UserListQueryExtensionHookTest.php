<?php

namespace Tests\Unit\Services;

use App\Extension\HookManager;
use App\Http\Requests\User\UserListRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 회원 목록 확장 필터 훅 테스트 (#705 D5)
 *
 * 확장이 회원 목록에 자기 조건을 더하는 경로는 네 층이다 — 화면 확장점, `ext_filters[]` 값 채널,
 * 검증 훅(`core.user.list_validation_rules`), 쿼리 훅 2종. 이 테스트는 서버 쪽 세 층을 본다.
 *
 * - `core.user.filter_list_params` 가 파라미터를 바꿀 수 있다
 * - `core.user.list_query_extensions` 클로저가 코어 조건 **뒤에** 적용되어 결과를 좁힌다
 * - `ext_filters[]` 원소(`{확장id}.{키}={값}`)가 `ext.{확장id}.{키}` 중첩 배열로 펴진다
 */
#[Group('core')]
#[Group('hooks')]
class UserListQueryExtensionHookTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, array{0: string, 1: callable}> 이 테스트가 건 필터 훅 */
    private array $registered = [];

    /**
     * 테스트가 건 훅만 걷어냅니다 — 확장이 구독한 같은 이름의 훅은 건드리지 않는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->registered as [$name, $callback]) {
            HookManager::removeFilter($name, $callback);
        }

        parent::tearDown();
    }

    /**
     * 필터 훅을 걸고 tearDown 에서 걷도록 기록합니다.
     *
     * @param  string  $name  훅 이름
     * @param  callable  $callback  콜백
     * @return void 반환값 없음
     */
    private function hook(string $name, callable $callback): void
    {
        HookManager::addFilter($name, $callback);
        $this->registered[] = [$name, $callback];
    }

    /**
     * 파라미터 훅이 받은 값을 바꾸면 저장소가 바뀐 값으로 거른다.
     *
     * @scenario filter_axis=extension, outcome=narrows
     *
     * @effects user_list_filter_params_hook_fires
     *
     * @return void 반환값 없음
     */
    public function test_filter_list_params_hook_can_rewrite_filters(): void
    {
        $marker = '@hk'.Str::lower(Str::random(8)).'.test';
        $blocked = User::factory()->create(['email' => 'a'.$marker, 'status' => 'blocked']);
        User::factory()->create(['email' => 'b'.$marker, 'status' => 'active']);

        $seen = null;
        $this->hook('core.user.filter_list_params', function (array $filters) use (&$seen) {
            $seen = $filters;
            $filters['status'] = ['blocked'];

            return $filters;
        });

        $result = app(UserService::class)->getPaginatedUsers([
            'filters' => [['field' => 'email', 'value' => $marker]],
            'per_page' => 50,
        ]);

        $this->assertIsArray($seen);
        $this->assertSame([$blocked->id], $result->getCollection()->pluck('id')->all());
    }

    /**
     * 쿼리 확장 훅의 클로저가 빌더와 필터를 받아 결과를 좁힌다.
     *
     * @scenario filter_axis=extension, outcome=narrows
     *
     * @effects user_list_query_extension_closures_applied
     *
     * @return void 반환값 없음
     */
    public function test_list_query_extension_closures_are_applied(): void
    {
        $marker = '@hq'.Str::lower(Str::random(8)).'.test';
        $keep = User::factory()->create(['email' => 'keep'.$marker, 'nickname' => 'keep-me']);
        User::factory()->create(['email' => 'drop'.$marker, 'nickname' => 'drop-me']);

        $received = [];
        $this->hook('core.user.list_query_extensions', function (array $closures, array $filters) use (&$received) {
            $closures[] = function (Builder $query, array $f) use (&$received) {
                $received = $f;
                if (($f['ext']['test-ext']['nick'] ?? null) !== null) {
                    $query->where('nickname', $f['ext']['test-ext']['nick']);
                }
            };
            $closures[] = 'not-callable';

            return $closures;
        });

        $result = app(UserService::class)->getPaginatedUsers([
            'filters' => [['field' => 'email', 'value' => $marker]],
            'ext' => ['test-ext' => ['nick' => 'keep-me']],
            'per_page' => 50,
        ]);

        $this->assertSame([$keep->id], $result->getCollection()->pluck('id')->all());
        $this->assertSame('keep-me', $received['ext']['test-ext']['nick'] ?? null);
    }

    /**
     * 훅 구독자가 없으면 결과는 코어 조건만으로 정해진다(무회귀).
     *
     * @scenario filter_axis=extension, outcome=narrows
     *
     * @effects user_list_query_extension_closures_applied
     *
     * @return void 반환값 없음
     */
    public function test_without_subscribers_core_filters_only(): void
    {
        $marker = '@hn'.Str::lower(Str::random(8)).'.test';
        User::factory()->count(2)->create(['email' => fn () => Str::random(6).$marker]);

        $result = app(UserService::class)->getPaginatedUsers([
            'filters' => [['field' => 'email', 'value' => $marker]],
            'per_page' => 50,
        ]);

        $this->assertSame(2, $result->total());
    }

    /**
     * `ext_filters[]` 원소가 `ext.{확장id}.{키}` 로 펴지고, 형식이 어긋난 원소는 거부된다.
     *
     * @scenario filter_axis=extension, outcome=rejected
     *
     * @effects user_list_ext_filters_parsed
     *
     * @return void 반환값 없음
     */
    public function test_ext_filters_are_parsed_into_nested_ext(): void
    {
        $request = $this->makeRequest([
            'ext_filters' => ['sirsoft-ecommerce.mileage_min=1000', 'sirsoft-ecommerce.has_address=1', ''],
        ]);

        $this->assertSame(
            ['sirsoft-ecommerce' => ['mileage_min' => '1000', 'has_address' => '1']],
            $request->input('ext')
        );

        $bad = $this->makeRequest(['ext_filters' => ['DROP TABLE=1']]);
        $validator = Validator::make($bad->all(), $bad->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ext_filters.0', $validator->errors()->toArray());
    }

    /**
     * 확장 값은 `ext_filters[]` 채널로만 들어온다 — `ext[...]` 를 직접 보내면 원소 형식 검사를
     * 건너뛰고 임의 구조가 검증 결과에 실리므로 버린다.
     *
     * @scenario filter_axis=extension, outcome=rejected
     *
     * @effects user_list_ext_filters_parsed
     *
     * @return void 반환값 없음
     */
    public function test_direct_ext_input_is_discarded(): void
    {
        $direct = $this->makeRequest(['ext' => ['test-ext' => ['nick' => str_repeat('x', 300)]]]);
        $this->assertNull($direct->input('ext'));

        $mixed = $this->makeRequest([
            'ext' => ['test-ext' => ['nick' => 'smuggled']],
            'ext_filters' => ['sirsoft-ecommerce.has_address=1'],
        ]);
        $this->assertSame(['sirsoft-ecommerce' => ['has_address' => '1']], $mixed->input('ext'));
    }

    /**
     * 확장이 검증 훅으로 `ext.{확장id}.{키}` 에 건 규칙이 실제로 닿는다.
     *
     * @scenario filter_axis=extension, outcome=rejected
     *
     * @effects user_list_ext_filters_parsed
     *
     * @return void 반환값 없음
     */
    public function test_extension_validation_rule_reaches_nested_key(): void
    {
        $this->hook('core.user.list_validation_rules', function (array $rules) {
            $rules['ext.sirsoft-ecommerce.mileage_min'] = 'nullable|integer|min:0';

            return $rules;
        });

        $request = $this->makeRequest(['ext_filters' => ['sirsoft-ecommerce.mileage_min=abc']]);
        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ext.sirsoft-ecommerce.mileage_min', $validator->errors()->toArray());
    }

    /**
     * prepareForValidation 까지 거친 요청을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리
     * @return UserListRequest 요청
     */
    private function makeRequest(array $query): UserListRequest
    {
        $request = UserListRequest::create('/api/admin/users', 'GET', $query);
        $request->setContainer($this->app);

        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        return $request;
    }
}
