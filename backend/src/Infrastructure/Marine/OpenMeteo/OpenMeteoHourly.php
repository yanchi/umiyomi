<?php

declare(strict_types=1);

namespace App\Infrastructure\Marine\OpenMeteo;

/**
 * Weather API と Marine API に共通する `hourly` ブロックの形式チェック。2 つの DTO で同じ検証を重複させないために切り出した.
 *
 * @internal
 */
final readonly class OpenMeteoHourly
{
    /**
     * @param array<mixed> $hourly
     * @param list<int>    $time
     */
    private function __construct(
        private array $hourly,
        private array $time,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException
     */
    public static function fromArray(array $data): self
    {
        $hourly = $data['hourly'] ?? null;
        if (!\is_array($hourly)) {
            throw new \UnexpectedValueException('Open-Meteo response has no "hourly" block.');
        }

        $time = $hourly['time'] ?? null;
        if (!\is_array($time) || !array_is_list($time)) {
            throw new \UnexpectedValueException('Open-Meteo response has no "hourly.time" list.');
        }
        $timestamps = [];
        foreach ($time as $value) {
            if (!\is_int($value)) {
                throw new \UnexpectedValueException('Open-Meteo "hourly.time" must be unixtime.');
            }
            $timestamps[] = $value;
        }

        return new self($hourly, $timestamps);
    }

    /**
     * @return list<int>
     */
    public function time(): array
    {
        return $this->time;
    }

    /**
     * @return list<float|null>
     *
     * @throws \UnexpectedValueException
     */
    public function values(string $key): array
    {
        $values = $this->hourly[$key] ?? null;
        if (!\is_array($values) || !array_is_list($values)) {
            throw new \UnexpectedValueException(\sprintf('Open-Meteo response has no "hourly.%s" list.', $key));
        }
        if (\count($values) !== \count($this->time)) {
            throw new \UnexpectedValueException(\sprintf('Open-Meteo "hourly.%s" length differs from "hourly.time".', $key));
        }

        $result = [];
        foreach ($values as $value) {
            if (null !== $value && !\is_int($value) && !\is_float($value)) {
                throw new \UnexpectedValueException(\sprintf('Open-Meteo "hourly.%s" contains a non-numeric value.', $key));
            }
            $result[] = null === $value ? null : (float) $value;
        }

        return $result;
    }
}
