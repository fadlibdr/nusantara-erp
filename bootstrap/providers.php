<?php

$providers = [
    App\Providers\AppServiceProvider::class,
];

// Auto-discover module service providers: Modules/<Name>/Providers/<Name>ServiceProvider.php
// A module is picked up simply by existing on disk — no central registration to edit.
foreach (glob(dirname(__DIR__).'/Modules/*', GLOB_ONLYDIR) as $moduleDir) {
    $name = basename($moduleDir);

    if (is_file("{$moduleDir}/Providers/{$name}ServiceProvider.php")) {
        $providers[] = "Modules\\{$name}\\Providers\\{$name}ServiceProvider";
    }
}

return $providers;
