<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderType;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;

/**
 * 소셜 로그인 제공자 설정 저장소 구현.
 *
 * @since 1.0.0
 */
class ProviderRepository implements ProviderRepositoryInterface
{
    /**
     * 모든 제공자 행을 표시 순서대로 반환합니다.
     *
     * @return Collection<int, SocialLoginProvider> 제공자 목록
     */
    public function all(): Collection
    {
        return SocialLoginProvider::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * 키로 제공자 행을 찾습니다.
     *
     * @param  string  $key  제공자 키
     * @return SocialLoginProvider|null 제공자 행, 없으면 null
     */
    public function findByKey(string $key): ?SocialLoginProvider
    {
        return SocialLoginProvider::query()->where('key', $key)->first();
    }

    /**
     * 키가 없으면 만들고, 있으면 비어 있는 기본값만 채웁니다.
     *
     * 재설치·업데이트 때 다시 불려도 운영자가 넣은 자격증명·순서·활성 여부를 되돌리지 않는다.
     *
     * @param  string  $key  제공자 키
     * @param  array<string, mixed>  $defaults  새로 만들 때의 값
     * @return SocialLoginProvider 저장된 행
     */
    public function seedIfMissing(string $key, array $defaults): SocialLoginProvider
    {
        $row = $this->findByKey($key);

        if ($row === null) {
            return SocialLoginProvider::query()->create(['key' => $key] + $defaults);
        }

        $fill = [];
        foreach ($defaults as $column => $value) {
            if ($row->getAttribute($column) === null && $value !== null) {
                $fill[$column] = $value;
            }
        }

        if ($fill !== []) {
            $row->forceFill($fill)->save();
        }

        return $row;
    }

    /**
     * 커스텀 제공자 수를 반환합니다.
     *
     * @return int 커스텀 행 수
     */
    public function countCustom(): int
    {
        return SocialLoginProvider::query()->where('type', ProviderType::Custom->value)->count();
    }

    /**
     * 마지막 성공·오류 정보를 기록합니다.
     *
     * @param  string  $key  제공자 키
     * @param  string|null  $errorCode  오류 코드 — null 이면 성공 기록
     * @return void 반환값 없음
     */
    public function recordOutcome(string $key, ?string $errorCode): void
    {
        $values = $errorCode === null
            ? ['last_success_at' => now()]
            : ['last_error_at' => now(), 'last_error_code' => substr($errorCode, 0, 50)];

        SocialLoginProvider::query()->where('key', $key)->update($values);
    }

    /**
     * 제공자 행을 만듭니다 (보호 컬럼 포함 — 호출자가 값을 정한다).
     *
     * @param  array<string, mixed>  $attributes  컬럼 값
     * @return SocialLoginProvider 만든 행 (저장 뒤 다시 읽은 값)
     */
    public function create(array $attributes): SocialLoginProvider
    {
        $row = new SocialLoginProvider;
        $row->forceFill($attributes)->save();

        return $row->refresh();
    }

    /**
     * 행에 값을 넣어 저장하고 다시 읽습니다 (바뀐 값이 없으면 저장하지 않는다).
     *
     * @param  SocialLoginProvider  $row  행 (다시 읽은 값으로 갱신된다)
     * @param  array<string, mixed>  $values  컬럼 값 (보호 컬럼 포함 — 호출자가 정한다)
     * @return array<int, string> 실제로 바뀐 컬럼 이름
     */
    public function update(SocialLoginProvider $row, array $values): array
    {
        $row->forceFill($values);
        $changed = array_keys($row->getDirty());

        if ($changed !== []) {
            $row->save();
            $row->refresh();
        }

        return $changed;
    }

    /**
     * 행을 지웁니다.
     *
     * @param  SocialLoginProvider  $row  행
     * @return void 반환값 없음
     */
    public function delete(SocialLoginProvider $row): void
    {
        $row->delete();
    }

    /**
     * 가장 큰 표시 순서를 반환합니다.
     *
     * @return int 표시 순서 (행이 없으면 0)
     */
    public function maxSortOrder(): int
    {
        return (int) SocialLoginProvider::query()->max('sort_order');
    }
}
