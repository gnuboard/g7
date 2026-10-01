<?php

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MissingValue;
use Tests\TestCase;

/**
 * UserResource 의 커스텀 배열 메서드가 when()/whenLoaded() 의 빈 값 객체를 흘리지 않는지 검증합니다 (#703).
 *
 * toArray() 안의 when()/whenLoaded() 는 Laravel 이 응답을 만들 때만 걸러진다. 커스텀 메서드가 toArray() 를
 * 직접 부르면 걸러지지 않은 MissingValue 가 남아 JSON 에 `{}` 로 나갔다 — 로그인 사용자 응답
 * (`modules_count`·`consents`·`terms_consent` 등 9개)과 관리자 회원 상세가 해당했다.
 *
 * 시나리오 매니페스트: tests/scenarios/user-resource-custom-arrays.yaml
 */
class UserResourceCustomArrayTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 커스텀 배열 메서드마다 MissingValue 가 남은 키를 모은다.
     *
     * @param  string  $method  UserResource 메서드 이름
     * @return array<int, string> MissingValue 가 남은 키 목록
     */
    private function leakedKeys(string $method): array
    {
        $user = User::factory()->create();
        request()->setUserResolver(fn () => $user);

        $data = (new UserResource($user))->{$method}(request());

        return array_keys(array_filter($data, fn ($value) => $value instanceof MissingValue));
    }

    /**
     * 로그인 사용자 응답·관리자 로그인 응답·관리자 회원 상세 배열에 빈 값 객체가 남지 않는다.
     *
     * @scenario method=to_auth_array
     *
     * @effects custom_array_has_no_missing_value
     *
     * @return void 반환값 없음
     */
    public function test_custom_arrays_do_not_leak_missing_values(): void
    {
        foreach (['toAuthArray', 'toAdminAuthArray', 'withAdminInfo'] as $method) {
            $this->assertSame([], $this->leakedKeys($method), $method);
        }
    }
}
