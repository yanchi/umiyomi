<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnalyticsUnconfiguredTest extends WebTestCase
{
    /** @var array{env: mixed, server: mixed} */
    private array $original = ['env' => null, 'server' => null];

    protected function tearDown(): void
    {
        $_ENV['GA_MEASUREMENT_ID'] = $this->original['env'];
        $_SERVER['GA_MEASUREMENT_ID'] = $this->original['server'];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unconfiguredProvider(): iterable
    {
        foreach (['empty' => '', 'universal analytics' => 'UA-12345-1', 'lowercase' => 'g-abcd1234'] as $label => $id) {
            foreach (['/', '/forecast?lat=27.75&lon=129.05', '/forecast?lat=abc&lon=1', '/feedback'] as $path) {
                yield $label.' '.$path => [$id, $path];
            }
        }
    }

    #[DataProvider('unconfiguredProvider')]
    public function testNothingIsLoaded(string $id, string $path): void
    {
        // %env()% は実行時に読まれるので、クライアントを作る前に差し替えれば未設定を再現できる
        $this->original = [
            'env' => $_ENV['GA_MEASUREMENT_ID'] ?? null,
            'server' => $_SERVER['GA_MEASUREMENT_ID'] ?? null,
        ];
        $_ENV['GA_MEASUREMENT_ID'] = $id;
        $_SERVER['GA_MEASUREMENT_ID'] = $id;
        $client = self::createClient();

        $crawler = $client->request('GET', $path);

        self::assertCount(0, $crawler->filter('meta[name="umiyomi-analytics"]'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('googletagmanager', $html);
        self::assertStringNotContainsString('google-analytics', $html);
        if (str_starts_with($path, '/forecast?lat=27')) {
            self::assertCount(1, $crawler->filter('table'));
        }
    }
}
