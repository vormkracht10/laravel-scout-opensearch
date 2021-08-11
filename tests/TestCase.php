<?php

declare(strict_types=1);

namespace Zing\LaravelScout\OpenSearch\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Zing\LaravelScout\OpenSearch\OpenSearchServiceProvider;

class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class, OpenSearchServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set(
            'database',
            [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'foreign_key_constraints' => false,
                    ],
                    'mongodb' => [
                        'driver' => 'mongodb',
                        'host' => 'localhost',
                        'database' => 'testing',
                    ],
                ],
            ]
        );
        config()
            ->set('scout.driver', 'opensearch');
        config()
            ->set('scout.opensearch', [
                'access_key' => 'your-opensearch-access-key',
                'secret' => 'your-opensearch-secret',
                'host' => 'your-opensearch-host',
            ]);
    }

    protected function setUpDatabase(): void
    {
        DB::connection()->getSchemaBuilder()->create(
            'searchable_models',
            function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name')
                    ->default('');
                $table->boolean('is_visible')
                    ->default(true);
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }
}
