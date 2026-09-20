<?php

namespace Nncodes\Meeting\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nncodes\MetaAttributes\MetaAttributesServiceProvider;
use Nncodes\Meeting\MeetingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            function (string $modelName) {
              return 'Nncodes\\Meeting\\Database\\Factories\\'.class_basename($modelName).'Factory';
            }
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            MeetingServiceProvider::class,
            MetaAttributesServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        include_once __DIR__.'/../database/migrations/create_meetings_table.php.stub';
        (new \CreateMeetingsTable())->up();
        include_once __DIR__.'/../vendor/nncodes/laravel-meta-attributes/database/migrations/create_meta_attributes_table.php.stub';
        (new \CreateMetaAttributesTable())->up();
    }
}
