<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class OpenMeteoFixture
{
    public static function body(string $name): string
    {
        $body = file_get_contents(__DIR__.'/Fixtures/OpenMeteo/'.$name);
        if (false === $body) {
            throw new \RuntimeException(\sprintf('Fixture "%s" not found.', $name));
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $name): array
    {
        $data = json_decode(self::body($name), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('Fixture "%s" is not a JSON object.', $name));
        }

        return $data;
    }
}
