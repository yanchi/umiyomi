<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 1 欄の文字列を座標の表記として読む。状態を持たず、Symfony にも依存しない（research R1）.
 *
 * 浮動小数点に変換すると桁の多い入力（27.7549999999999999 など）で四捨五入が食い違うため、
 * 数字の文字列から「小数点以下 2 桁に丸めた値 × 100」の整数を直接求める（research R2）。
 */
final readonly class CoordinateNotationParser
{
    // 長い入力を正規表現にかける前に切る（FR-024）
    private const int MAX_LENGTH = 100;

    // 整数部が 4 桁以上の値は範囲外にしかならないので、桁あふれを避けるため 999 に寄せる
    private const int WHOLE_DEGREES_CAP = 999;

    // Unicode の NFKC は使わない。何を受け付けるかをテストで列挙できるよう、置き換える文字を明示する（research R3）。
    // strtr は長いキーを優先して 1 回だけ置き換えるので、秒の記号 '' は分の記号 ' より先に 1 つの " になる
    private const array NORMALIZATION = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        '．' => '.',
        '，' => ',', '、' => ',', '､' => ',',
        '－' => '-', '−' => '-', '‐' => '-', '‑' => '-', '–' => '-',
        '＋' => '+',
        '（' => '(', '）' => ')',
        "\t" => ' ', "\n" => ' ', "\r" => ' ',
        '˚' => '°', 'º' => '°', '度' => '°', 'd' => '°', 'D' => '°',
        '′' => "'", '’' => "'", '‘' => "'", '＇' => "'", '分' => "'",
        '″' => '"', '”' => '"', '“' => '"', '＂' => '"', '秒' => '"',
        "''" => '"', '′′' => '"', '’’' => '"',
        '北緯' => 'N', '南緯' => 'S', '東経' => 'E', '西経' => 'W',
        'n' => 'N', 's' => 'S', 'e' => 'E', 'w' => 'W',
        'Ｎ' => 'N', 'Ｓ' => 'S', 'Ｅ' => 'E', 'Ｗ' => 'W',
        'ｎ' => 'N', 'ｓ' => 'S', 'ｅ' => 'E', 'ｗ' => 'W',
    ];

    // \d は /u で非 ASCII の数字にも一致し (int) 変換が 0 になるため、[0-9] を使う
    private const string DECIMAL = '(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)';

    public function parse(string $raw): ParsedField
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return ParsedField::invalid(NotationError::Unreadable);
        }
        if (mb_strlen($raw) > self::MAX_LENGTH) {
            return ParsedField::invalid(NotationError::TooLong);
        }

        // 正規化の前に調べる。正規化は英字（d・e・s など）を置き換えるので、リンクの文字列が変わってしまう
        if (1 === preg_match('~^[\s\x{3000}]*https?://~iu', $raw)) {
            return ParsedField::invalid(NotationError::Url);
        }

        $normalized = $this->normalize($raw);
        if ('' === $normalized) {
            return ParsedField::empty();
        }

        $single = $this->readSingle($normalized);
        if (null !== $single) {
            return $single;
        }

        return $this->readPair($normalized) ?? $this->describeUnreadable($normalized);
    }

    private function normalize(string $raw): string
    {
        // 全角空白・ノーブレークスペースなど、コピー元で混ざる空白をまとめて ASCII の空白にする。trim は ASCII の空白しか除かないため
        $spaced = preg_replace('/\p{Zs}/u', ' ', $raw) ?? $raw;
        $text = trim(strtr($spaced, self::NORMALIZATION), ' ');
        // 地図アプリの出力に括弧で囲まれたものがあるため、全体を囲む 1 組だけ外す
        if (str_starts_with($text, '(') && str_ends_with($text, ')')) {
            $text = trim(substr($text, 1, -1), ' ');
        }

        return $text;
    }

    /**
     * 値 1 つ。形が合わなければ null（呼び出し側が他の形を試す）.
     */
    private function readSingle(string $text): ?ParsedField
    {
        if (1 !== preg_match('/^'.self::anglePattern('s_', true, true).'$/u', $text, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $angle = $this->buildAngle($match, 's_');

        return $angle instanceof Angle ? ParsedField::single($angle) : ParsedField::invalid($angle);
    }

    /**
     * 値 2 つ（1 行の「緯度, 経度」）。形が合わなければ null.
     *
     * 方角の文字が前置か後置かは先頭の文字で決める。決めずに両方を許すと、`N 27.75 E 129.05` の
     * `E` が 1 つ目の値の後置の方角と読めてしまい、正しい入力が方角の重複になるため。
     */
    private function readPair(string $text): ?ParsedField
    {
        $isPrefixStyle = 1 === preg_match('/^[NSEW]/', $text);
        $pattern = \sprintf(
            '/^%s(?: *, *| +)%s$/u',
            self::anglePattern('a_', $isPrefixStyle, !$isPrefixStyle),
            self::anglePattern('b_', $isPrefixStyle, !$isPrefixStyle),
        );
        if (1 !== preg_match($pattern, $text, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        // 記号も小数点もない整数 2 つは、度分や小数点のカンマの書き間違いと区別できない。別の地点を黙って表示しないよう受け付けない
        if (1 === preg_match('/^[+-]?[0-9]+ +[+-]?[0-9]+$/', $text)) {
            return ParsedField::invalid(NotationError::UnitlessDegreeMinutes, true);
        }
        if (1 === preg_match('/^[+-]?[0-9]+,[+-]?[0-9]+$/', $text)) {
            return ParsedField::invalid(NotationError::DecimalComma, true);
        }

        $first = $this->buildAngle($match, 'a_');
        $second = $this->buildAngle($match, 'b_');
        foreach ([$first, $second] as $angle) {
            if ($angle instanceof NotationError) {
                return ParsedField::invalid($angle, true);
            }
        }
        \assert($first instanceof Angle && $second instanceof Angle);

        // 片方だけ方角の文字があると、`N27 45.0` を緯度 27・経度 45 と取り違える。両方にそろえさせる（research R4）
        if ((null === $first->axis) !== (null === $second->axis)) {
            return ParsedField::invalid(NotationError::PairDirectionMixed, true);
        }
        if (null !== $first->axis && $first->axis === $second->axis) {
            return ParsedField::invalid(NotationError::PairSameAxis, true);
        }

        return ParsedField::pair($first, $second);
    }

    /**
     * どの形にも合わなかった文字列の原因を決める。1 行の形をしていれば、もう一方の欄を読まない判断（FR-004）に使えるよう印を付ける.
     */
    private function describeUnreadable(string $text): ParsedField
    {
        $tokens = preg_split('/[ ,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        // UTF-8 として正しい文字列に限って呼ぶので失敗しないが、PHPStan の型のために空配列を当てる
        if (false === $tokens) {
            $tokens = [];
        }
        $valueTokens = array_filter($tokens, static fn (string $token): bool => 1 === preg_match('/[0-9]/', $token));
        $hasComma = str_contains($text, ',');
        if (!$hasComma && \count($valueTokens) < 2) {
            return ParsedField::invalid(NotationError::Unreadable);
        }

        $segments = array_filter(explode(',', $text), static fn (string $segment): bool => '' !== trim($segment));
        if (\count($segments) >= 3) {
            return ParsedField::invalid(NotationError::TooManyValues, true);
        }
        // 度分を記号なしの数字で並べた入力は、推測せずに記号を付けるよう案内する（FR-005）
        if (1 === preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?(?: +[+-]?[0-9]+(?:\.[0-9]+)?){2,3}$/', $text)) {
            return ParsedField::invalid(NotationError::UnitlessDegreeMinutes, true);
        }
        if (\count($valueTokens) >= 3) {
            return ParsedField::invalid(NotationError::TooManyValues, true);
        }

        return ParsedField::invalid(NotationError::Unreadable, true);
    }

    /**
     * 値 1 つの正規表現。値 2 つの読み取りでも部品として使えるよう、グループ名に接頭辞を付ける.
     *
     * @param bool $prefixDirection 方角の文字を前に置けるか
     * @param bool $suffixDirection 方角の文字を後ろに置けるか
     */
    private static function anglePattern(string $prefix, bool $prefixDirection, bool $suffixDirection): string
    {
        $pre = $prefixDirection ? \sprintf('(?:(?<%spre>[NSEW]) *)?', $prefix) : '';
        $post = $suffixDirection ? \sprintf('(?: *(?<%spost>[NSEW]))?', $prefix) : '';

        return $pre.\sprintf('(?<%ssign>[+-])? *', $prefix).self::bodyPattern($prefix).$post;
    }

    /**
     * 値の本体（方角の文字・符号を除いた部分）。表記ごとに別のグループ名を使い、どれに合ったかを読み取り側が判別する.
     */
    private static function bodyPattern(string $prefix): string
    {
        $decimal = self::DECIMAL;
        // 先に長い表記を試す。度の記号は十進数でも付けられるため、十進数は最後
        $alternatives = [
            // 度分秒（秒の記号は省略可）
            '(?<@dd>[0-9]+) *° *(?<@dm>[0-9]+) *\' *(?<@ds>'.$decimal.') *"?',
            // ハイフン区切りの度分秒（方角の文字が必須。読み取りで確かめる）
            '(?<@hd>[0-9]+)-(?<@hm>[0-9]+)-(?<@hs>'.$decimal.')',
            // 度分（分の記号は省略可）
            '(?<@md>[0-9]+) *° *(?<@mm>'.$decimal.') *\'?',
            // 度が整数でない度分。「27.75° 129.05」のような十進数の 2 つと区別するため、分の記号があるときだけ読む
            '(?<@bd>[0-9]+\.[0-9]*|\.[0-9]+) *° *(?<@bm>'.$decimal.') *\'',
            // ハイフン区切りの度分（方角の文字が必須）
            '(?<@gd>[0-9]+)-(?<@gm>'.$decimal.')',
            // 十進数（度の記号は任意）
            '(?<@decimal>'.$decimal.') *°?',
        ];

        return str_replace('@', $prefix, '(?:'.implode('|', $alternatives).')');
    }

    /**
     * @param array<int|string, string|null> $match
     */
    private function buildAngle(array $match, string $prefix): Angle|NotationError
    {
        $value = $this->readBody($match, $prefix);
        if ($value instanceof NotationError) {
            return $value;
        }
        [$magnitude, $wholeDegrees, $hasFraction] = $value;

        return $this->applyDirection($match, $prefix, $magnitude, $wholeDegrees, $hasFraction);
    }

    /**
     * 合った表記のグループから、丸めた値の大きさなどを求める.
     *
     * @param array<int|string, string|null> $match
     *
     * @return array{int, int, bool}|NotationError [丸めた値 × 100 の大きさ, 整数部, 整数部より下の桁に 0 以外があるか]
     */
    private function readBody(array $match, string $prefix): array|NotationError
    {
        $group = static fn (string $name): ?string => $match[$prefix.$name] ?? null;

        $degrees = $group('dd');
        if (null !== $degrees) {
            return $this->readDegreesMinutesSeconds($degrees, $group('dm') ?? '', $group('ds') ?? '');
        }
        $degrees = $group('hd');
        if (null !== $degrees) {
            return $this->requireDirection($match, $prefix) ?? $this->readDegreesMinutesSeconds($degrees, $group('hm') ?? '', $group('hs') ?? '');
        }
        $degrees = $group('md');
        if (null !== $degrees) {
            return $this->readDegreesMinutes($degrees, $group('mm') ?? '');
        }
        if (null !== $group('bd')) {
            return NotationError::DegreeNotInteger;
        }
        $degrees = $group('gd');
        if (null !== $degrees) {
            return $this->requireDirection($match, $prefix) ?? $this->readDegreesMinutes($degrees, $group('gm') ?? '');
        }

        return $this->readDecimal($group('decimal') ?? '');
    }

    /**
     * ハイフン区切りは、方角の文字がなければ数字の並びとの区別がつかないので受け付けない.
     *
     * @param array<int|string, string|null> $match
     */
    private function requireDirection(array $match, string $prefix): ?NotationError
    {
        return null === ($match[$prefix.'pre'] ?? null) && null === ($match[$prefix.'post'] ?? null) ? NotationError::Unreadable : null;
    }

    /**
     * @return array{int, int, bool}
     */
    private function readDecimal(string $decimal): array
    {
        [$whole, $fraction] = self::splitDecimal($decimal);
        $wholeDegrees = self::wholeDegrees($whole);

        // 小数点以下 3 桁目の四捨五入。それより下の桁は結果に影響しない
        $digits = str_pad(substr($fraction, 0, 3), 3, '0');
        $magnitude = 100 * $wholeDegrees + (int) substr($digits, 0, 2) + ((int) $digits[2] >= 5 ? 1 : 0);

        return [$magnitude, $wholeDegrees, '' !== ltrim($fraction, '0')];
    }

    /**
     * 度分。D + m/60 の 100 倍 = 100·D + 5m/3 の四捨五入は floor((10m + 3) / 6)。
     * 10m = 10·Mi + m1 + r（0 ≤ r < 1）で、整数 K と 0 ≤ r < 1 なら floor((K + r) / 6) = floor(K / 6) なので、
     * 分の小数点以下 2 桁目より下は結果に影響しない.
     *
     * @return array{int, int, bool}|NotationError
     */
    private function readDegreesMinutes(string $degrees, string $minutes): array|NotationError
    {
        [$minuteWhole, $minuteFraction] = self::splitDecimal($minutes);
        $wholeMinutes = self::wholeDegrees($minuteWhole);
        if ($wholeMinutes >= 60) {
            return NotationError::MinuteSecondRange;
        }

        $wholeDegrees = self::wholeDegrees($degrees);
        $firstDigit = (int) substr($minuteFraction.'0', 0, 1);
        $magnitude = 100 * $wholeDegrees + intdiv(10 * $wholeMinutes + $firstDigit + 3, 6);

        return [$magnitude, $wholeDegrees, '' !== ltrim($minuteWhole.$minuteFraction, '0')];
    }

    /**
     * 度分秒。D + (60·Mi + s)/3600 の 100 倍 = 100·D + (60·Mi + s)/36 の四捨五入は floor((120·Mi + 2s + 36) / 72)。
     * 2s の小数部は 0 以上 2 未満なので、秒の小数点以下 1 桁目が 5 以上のときだけ 1 繰り上がる（2s = 2·Si + 2·0.s1…）.
     *
     * @return array{int, int, bool}|NotationError
     */
    private function readDegreesMinutesSeconds(string $degrees, string $minutes, string $seconds): array|NotationError
    {
        [$secondWhole, $secondFraction] = self::splitDecimal($seconds);
        $wholeMinutes = self::wholeDegrees($minutes);
        $wholeSeconds = self::wholeDegrees($secondWhole);
        if ($wholeMinutes >= 60 || $wholeSeconds >= 60) {
            return NotationError::MinuteSecondRange;
        }

        $wholeDegrees = self::wholeDegrees($degrees);
        $firstDigit = (int) substr($secondFraction.'0', 0, 1);
        $magnitude = 100 * $wholeDegrees + intdiv(120 * $wholeMinutes + 2 * $wholeSeconds + 36 + ($firstDigit >= 5 ? 1 : 0), 72);

        return [$magnitude, $wholeDegrees, '' !== ltrim($minutes.$secondWhole.$secondFraction, '0')];
    }

    /**
     * @return array{string, string} [整数部の数字, 小数部の数字]
     */
    private static function splitDecimal(string $decimal): array
    {
        $point = strpos($decimal, '.');

        return false === $point ? [$decimal, ''] : [substr($decimal, 0, $point), substr($decimal, $point + 1)];
    }

    private static function wholeDegrees(string $digits): int
    {
        $significant = ltrim($digits, '0');

        return \strlen($significant) >= 4 ? self::WHOLE_DEGREES_CAP : (int) $significant;
    }

    /**
     * 方角の文字と符号を適用して Angle にする。N・E は正、S・W は負（FR-006）.
     *
     * @param array<int|string, string|null> $match
     */
    private function applyDirection(array $match, string $prefix, int $magnitude, int $wholeDegrees, bool $hasFraction): Angle|NotationError
    {
        $before = $match[$prefix.'pre'] ?? null;
        $after = $match[$prefix.'post'] ?? null;
        $sign = $match[$prefix.'sign'] ?? null;

        if (null !== $before && null !== $after) {
            return NotationError::ConflictingDirections;
        }
        $direction = $before ?? $after;
        if (null !== $direction && null !== $sign) {
            return NotationError::SignAndDirection;
        }

        $negative = '-' === $sign || 'S' === $direction || 'W' === $direction;
        $axis = match ($direction) {
            'N', 'S' => Axis::Latitude,
            'E', 'W' => Axis::Longitude,
            default => null,
        };

        return new Angle($negative ? -$magnitude : $magnitude, $axis, $wholeDegrees, $hasFraction);
    }
}
