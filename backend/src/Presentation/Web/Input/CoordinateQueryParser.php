<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 項目 2 つの GET フォームで全角変換などの独自の正規化が中心になるため、Symfony Form ではなく専用のパーサーで解釈する（research R7）.
 */
final readonly class CoordinateQueryParser
{
    // スマホの日本語入力で混ざりやすい全角文字を半角に寄せる
    private const array FULL_WIDTH = [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        '．' => '.', '－' => '-', '−' => '-', '＋' => '+',
    ];

    private const string DECIMAL_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    // 度分秒や N/S/E/W を使った書き方だと分かる記号。十進数で入力し直す案内を添える。指数表記の e と区別するため方位は大文字だけ
    private const string DEGREE_NOTATION_PATTERN = '/[NSEW°˚\'"′″度分秒]/u';

    private const string DECIMAL_HINT = '十進数（例：27.75）で入力してください';

    public function parse(?string $latitude, ?string $longitude): CoordinateQuery
    {
        $rawLatitude = $latitude ?? '';
        $rawLongitude = $longitude ?? '';
        $errors = [];

        $parsedLatitude = $this->parseValue($rawLatitude, 90.0, '緯度', $errors['latitude']);
        $parsedLongitude = $this->parseValue($rawLongitude, 180.0, '経度', $errors['longitude']);

        return new CoordinateQuery(
            $rawLatitude,
            $rawLongitude,
            $parsedLatitude,
            $parsedLongitude,
            array_filter($errors, static fn (?string $error): bool => null !== $error),
        );
    }

    private function parseValue(string $raw, float $limit, string $label, ?string &$error): ?float
    {
        $error = null;
        $normalized = strtr((string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $raw), self::FULL_WIDTH);

        if (1 !== preg_match(self::DECIMAL_PATTERN, $normalized)) {
            $error = \sprintf('%sを数値（-%s〜%s）で入力してください', $label, $limit, $limit);
            if (1 === preg_match(self::DEGREE_NOTATION_PATTERN, $normalized)) {
                $error .= '。'.self::DECIMAL_HINT;
            }

            return null;
        }

        $value = (float) $normalized;
        if ($value < -$limit || $value > $limit) {
            $error = \sprintf('%sは -%s〜%s の範囲で入力してください', $label, $limit, $limit);

            return null;
        }

        return $value;
    }
}
