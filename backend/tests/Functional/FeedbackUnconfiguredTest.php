<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeedbackUnconfiguredTest extends WebTestCase
{
    private const array VARIABLES = ['FEEDBACK_FORM_URL', 'FEEDBACK_FORM_PREFILL_FIELD'];

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $original = [];

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $values) {
            $_ENV[$name] = $values['env'];
            $_SERVER[$name] = $values['server'];
        }
        $this->original = [];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function unconfiguredProvider(): iterable
    {
        yield 'both empty' => [['FEEDBACK_FORM_URL', 'FEEDBACK_FORM_PREFILL_FIELD']];
        yield 'url only empty' => [['FEEDBACK_FORM_URL']];
        yield 'field only empty' => [['FEEDBACK_FORM_PREFILL_FIELD']];
    }

    /**
     * @param list<string> $emptied
     */
    #[DataProvider('unconfiguredProvider')]
    public function testNoLinkAndNotFound(array $emptied): void
    {
        // %env()% は実行時に読まれるので、クライアントを作る前に空にすれば未設定を再現できる
        foreach (self::VARIABLES as $name) {
            $this->original[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
        }
        foreach ($emptied as $name) {
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
        $client = self::createClient();

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.site-footer__feedback'));

        $crawler = $client->request('GET', '/forecast?lat=27.75&lon=129.05');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('a.site-footer__feedback'));
        self::assertCount(1, $crawler->filter('table'));

        $client->request('GET', '/feedback');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/feedback?lat=27.75&lon=129.05');
        self::assertResponseStatusCodeSame(404);
    }
}
