<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must NEVER depend on a running Inertia SSR node server (127.0.0.1:13714)
        // or a freshly-built SSR bundle. Force client-side rendering so full-page Inertia
        // assertions are deterministic. This runtime override wins even when the app's
        // config is cached (bootstrap/cache/config.php), which otherwise defeats the
        // INERTIA_SSR_ENABLED env in phpunit.xml.
        config(['inertia.ssr.enabled' => false]);

        // Tests must NOT depend on a Vite build. app.blade.php @vite()s the per-page
        // "resources/js/pages/{component}.vue" entry, so a full-page Inertia render of a
        // page missing from public/build/manifest.json (e.g. a page added since the last
        // `npm run build`) would 500 with "Unable to locate file in Vite manifest".
        // withoutVite() stubs the directive so page-render assertions don't need assets.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
