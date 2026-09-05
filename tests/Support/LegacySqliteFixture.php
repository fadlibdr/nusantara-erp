<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A SQLite file holding the demo seed — the "legacy" database the Fase 0
 * tools read (erp:sqlite-to-mysql, erp:migration-verify).
 *
 * Built ONCE per PHP process by running `migrate` and `db:seed` through the
 * `sqlite_legacy` connection (both commands switch the default connection for
 * their duration, so every module migration and seeder lands in the file, not
 * in the test database). ~5 s. Each test then takes a private COPY of the
 * file, so a test that tampers with rows or drops a table never affects the
 * next one. Files live under storage/framework/testing and are removed when
 * the process ends.
 */
final class LegacySqliteFixture
{
    public const CONNECTION = 'sqlite_legacy';

    private static ?string $seeded = null;

    /** @var list<string> */
    private static array $created = [];

    /**
     * A private copy of the seeded file; the caller decides which connection
     * reads it (see use() and connection()).
     */
    public static function copy(string $label): string
    {
        self::build();

        $path = self::dir().'/'.$label.'-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        copy((string) self::$seeded, $path);
        self::$created[] = $path;

        return $path;
    }

    /**
     * Point the sqlite_legacy connection at a file.
     */
    public static function use(string $path): void
    {
        self::connection(self::CONNECTION, $path);
    }

    /**
     * Define (or re-point) a named SQLite connection at a file.
     */
    public static function connection(string $name, string $path): void
    {
        config(["database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 5000,
        ]]);
        DB::purge($name);
    }

    public static function forget(string $name, ?string $path = null): void
    {
        DB::purge($name);

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private static function build(): void
    {
        if (self::$seeded !== null && is_file(self::$seeded)) {
            return;
        }

        $path = self::dir().'/legacy-seed-'.getmypid().'.sqlite';
        @unlink($path);
        touch($path);

        self::use($path);

        // --force: both commands refuse to run in "production" without it,
        // and APP_ENV is whatever the suite says.
        if (Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]) !== 0) {
            throw new \RuntimeException('LegacySqliteFixture: migrate failed: '.Artisan::output());
        }

        if (Artisan::call('db:seed', ['--database' => self::CONNECTION, '--force' => true]) !== 0) {
            throw new \RuntimeException('LegacySqliteFixture: db:seed failed: '.Artisan::output());
        }

        DB::purge(self::CONNECTION);

        self::$seeded = $path;
        self::$created[] = $path;

        register_shutdown_function(static function (): void {
            foreach (self::$created as $file) {
                @unlink($file);
            }
        });
    }

    private static function dir(): string
    {
        $dir = storage_path('framework/testing/legacy-sqlite');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
