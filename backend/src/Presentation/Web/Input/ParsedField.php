<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 1 欄の読み取り結果。不正な組み合わせを作れないよう、名前付きコンストラクタだけで作る.
 */
final readonly class ParsedField
{
    private function __construct(
        public FieldKind $kind,
        public ?Angle $first,
        public ?Angle $second,
        public ?NotationError $error,
        public bool $looksLikePair,
    ) {
    }

    public static function empty(): self
    {
        return new self(FieldKind::Empty, null, null, NotationError::Empty, false);
    }

    public static function single(Angle $angle): self
    {
        return new self(FieldKind::Single, $angle, null, null, false);
    }

    public static function pair(Angle $first, Angle $second): self
    {
        return new self(FieldKind::Pair, $first, $second, null, true);
    }

    /**
     * @param bool $looksLikePair 1 行の形をしていたか。使わない欄を読まない判断（FR-004）に使う
     */
    public static function invalid(NotationError $error, bool $looksLikePair = false): self
    {
        return new self(FieldKind::Invalid, null, null, $error, $looksLikePair);
    }
}
