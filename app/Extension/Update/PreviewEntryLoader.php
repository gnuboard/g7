<?php

namespace App\Extension\Update;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 업데이트 미리보기용 — 새 판의 진입 클래스(`module.php`·`plugin.php`)를 지금 실행 중인 판과 겹치지 않게 읽습니다.
 *
 * 같은 FQCN 의 지금 판 클래스가 이미 메모리에 있으므로, 새 판 파일의 첫 `namespace` 를 미리보기 전용 접두로 바꾼 사본을
 * 임시 경로에 써서 읽는다. 식별자·경로는 새 판 디렉토리로 맞춘다(매니페스트·선언 파일을 새 판에서 읽게). 새 판 선언
 * (`getPermissions()`·`getAdminMenus()`·`getRoles()`·`getNotificationDefinitions()`·`getIdentityPolicies()`·`getSchedules()`)
 * 을 읽는 데만 쓴다 — 설치·활성화 같은 수명주기 메서드는 부르지 않는다.
 *
 * 같은 이름공간의 다른 클래스를 짧은 이름으로 참조하는 선언은 미리보기 이름공간에서 찾지 못할 수 있다 — 그때는 null 을
 * 돌려주고 경고 로그를 남긴다(미리보기는 선언 항목 판정을 건너뛴다).
 */
final class PreviewEntryLoader
{
    /**
     * 새 판 진입 클래스 인스턴스를 만듭니다.
     *
     * @param  string  $type  확장 종류 (`module`·`plugin`)
     * @param  string  $identifier  확장 식별자
     * @param  string  $root  새 판 디렉토리
     * @return ModuleInterface|PluginInterface|null 인스턴스 (읽지 못하면 null)
     */
    public function load(string $type, string $identifier, string $root): ModuleInterface|PluginInterface|null
    {
        $entry = rtrim($root, '/\\').DIRECTORY_SEPARATOR.($type === 'module' ? 'module.php' : 'plugin.php');

        if (! is_file($entry)) {
            return null;
        }

        $source = (string) file_get_contents($entry);

        if (! preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $m)) {
            return null;
        }

        $prefix = 'G7UpdatePreview\\P'.substr(hash('sha256', $root.'|'.$source), 0, 16);
        $class = $prefix.'\\'.$m[1].'\\'.($type === 'module' ? 'Module' : 'Plugin');

        try {
            if (! class_exists($class, false)) {
                $rewritten = preg_replace('/^namespace\s+'.preg_quote($m[1], '/').'\s*;/m', 'namespace '.$prefix.'\\'.$m[1].';', $source, 1);
                $dir = storage_path('framework/cache/g7-update-preview');
                File::ensureDirectoryExists($dir);
                $tmp = $dir.DIRECTORY_SEPARATOR.md5($class).'.php';
                File::put($tmp, (string) $rewritten);
                require_once $tmp;
                File::delete($tmp);
            }

            if (! class_exists($class, false)) {
                return null;
            }

            $instance = new $class;
            $base = $type === 'module' ? AbstractModule::class : AbstractPlugin::class;
            $pathProperty = $type === 'module' ? 'modulePath' : 'pluginPath';

            if ($instance instanceof $base) {
                Closure::bind(function () use ($root, $identifier, $pathProperty): void {
                    $this->{$pathProperty} = rtrim($root, '/\\');
                    $this->identifier = $identifier;
                }, $instance, $base)();
            }

            return $instance instanceof ModuleInterface || $instance instanceof PluginInterface ? $instance : null;
        } catch (\Throwable $e) {
            Log::warning('업데이트 미리보기: 새 판 진입 클래스를 읽지 못해 선언 항목 판정을 건너뜁니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
