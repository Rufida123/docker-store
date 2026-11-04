<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Modules may only talk to each other through the single public class each
 * module exposes (App\Modules\<Name>\<Name>Module). Any reference from one
 * module to another module's controllers, services, repositories or routes
 * fails this test.
 */
class ModuleBoundariesTest extends TestCase
{
    public function test_modules_only_use_each_others_public_class(): void
    {
        $modulesDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules';

        if (! is_dir($modulesDir)) {
            $this->markTestSkipped('No modules exist yet.');
        }

        $violations = [];

        foreach (glob($modulesDir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) as $moduleDir) {
            $module = basename($moduleDir);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));

            foreach ($iterator as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = file_get_contents($file->getPathname());

                if (! preg_match_all('/App\\\\Modules\\\\(\w+)\\\\([\w\\\\]+)/', $code, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($matches as $match) {
                    [, $otherModule, $target] = $match;

                    if ($otherModule === $module) {
                        continue;
                    }

                    if ($target === $otherModule.'Module') {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s (module %s) references App\Modules\%s\%s',
                        $file->getPathname(),
                        $module,
                        $otherModule,
                        $target,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Cross-module access must go through the module's public class:\n".implode("\n", $violations),
        );
    }
}
