<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Web\Analytics;

use App\Presentation\Web\Analytics\AnalyticsTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyticsTagTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'typical' => ['G-ABCD1234'];
        yield 'e2e' => ['G-E2ETEST000'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'leading space' => [' G-ABCD1234'];
        yield 'lowercase' => ['g-abcd1234'];
        yield 'universal analytics' => ['UA-12345-1'];
        yield 'too short' => ['G-ABC'];
        yield 'too long' => ['G-'.str_repeat('A', 21)];
        yield 'markup injection' => ['G-ABCD1234"><script>'];
    }

    #[DataProvider('validProvider')]
    public function testValidIdIsEnabled(string $id): void
    {
        $tag = new AnalyticsTag($id);

        self::assertTrue($tag->isEnabled());
        self::assertSame($id, $tag->measurementId());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidIdIsDisabled(string $id): void
    {
        $tag = new AnalyticsTag($id);

        self::assertFalse($tag->isEnabled());
        $this->expectException(\LogicException::class);
        $tag->measurementId();
    }
}
