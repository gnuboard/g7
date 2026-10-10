<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\Module\IndexModuleRequest;
use App\Http\Requests\Plugin\IndexPluginRequest;
use App\Http\Requests\Template\IndexTemplateRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 목록의 「숨김 항목 포함」(include_hidden) 은 최고관리자 전용이다.
 *
 * 화면은 체크박스를 최고관리자에게만 보이지만, 7.1.0 부터 체크 값이 주소(`?include_hidden=1`)에 실리므로
 * 그 주소로 들어온 위임 관리자에게 숨김 확장이 보이고 끌 체크박스도 없었다. 요청 단계에서
 * 최고관리자가 아니면 include_hidden 을 거짓으로 고정한다.
 */
class ExtensionIndexIncludeHiddenSuperOnlyTest extends TestCase
{
    /**
     * 목록 요청 3종.
     *
     * @return array<string, array{0: class-string<FormRequest>}> 확장 종류별 목록 요청 클래스
     */
    public static function requestClasses(): array
    {
        return [
            'module' => [IndexModuleRequest::class],
            'plugin' => [IndexPluginRequest::class],
            'template' => [IndexTemplateRequest::class],
        ];
    }

    /**
     * 주어진 사용자로 include_hidden=1 요청을 검증하고 validated 값을 돌려준다.
     *
     * @param  class-string<FormRequest>  $class  검증할 목록 요청 클래스
     * @param  User|null  $user  요청을 보낸 사용자(비로그인은 null)
     * @return mixed 검증을 통과한 include_hidden 값
     */
    private function validatedIncludeHidden(string $class, ?User $user): mixed
    {
        $base = Request::create('/api/admin/x', 'GET', ['include_hidden' => '1']);
        /** @var FormRequest $request */
        $request = $class::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        return $request->validated()['include_hidden'] ?? null;
    }

    /**
     * 최고관리자가 아닌 관리자가 보낸 include_hidden=1 은 거짓이 된다.
     *
     * @scenario viewer=admin_not_super
     *
     * @effects include_hidden_ignored_for_non_super
     *
     * @param  class-string<FormRequest>  $class  검증할 목록 요청 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('requestClasses')]
    public function test_non_super_admin_cannot_include_hidden(string $class): void
    {
        $user = new User;
        $user->is_super = false;

        $this->assertFalse((bool) $this->validatedIncludeHidden($class, $user));
    }

    /**
     * 사용자 없이 들어온 include_hidden=1 도 거짓이 된다.
     *
     * @scenario viewer=guest
     *
     * @effects include_hidden_ignored_for_non_super
     *
     * @param  class-string<FormRequest>  $class  검증할 목록 요청 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('requestClasses')]
    public function test_guest_cannot_include_hidden(string $class): void
    {
        $this->assertFalse((bool) $this->validatedIncludeHidden($class, null));
    }

    /**
     * 최고관리자가 보낸 include_hidden=1 은 그대로 참이다.
     *
     * @scenario viewer=super_admin
     *
     * @effects include_hidden_kept_for_super
     *
     * @param  class-string<FormRequest>  $class  검증할 목록 요청 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('requestClasses')]
    public function test_super_admin_can_include_hidden(string $class): void
    {
        $user = new User;
        $user->is_super = true;

        $this->assertTrue((bool) $this->validatedIncludeHidden($class, $user));
    }
}
