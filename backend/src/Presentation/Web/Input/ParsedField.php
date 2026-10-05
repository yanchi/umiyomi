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
        public bool $isUnmarkedSpacePair = false,
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

    /**
     * @param bool $isUnmarkedSpacePair 方角の文字・記号・カンマのない、空白だけで区切られた数字 2 つか。度分の書き間違いと区別できない
     */
    public static function pair(Angle $first, Angle $second, bool $isUnmarkedSpacePair = false): self
    {
        return new self(FieldKind::Pair, $first, $second, null, true, $isUnmarkedSpacePair);
    }

    /**
     * @param bool $looksLikePair 1 行の形をしていたか。使わない欄を読まない判断（FR-004）に使う
     */
    public static function invalid(NotationError $error, bool $looksLikePair = false): self
    {
        return new self(FieldKind::Invalid, null, null, $error, $looksLikePair);
    }
}
