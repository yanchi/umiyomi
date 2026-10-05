<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 案内画面の Query から文脈を 1 つに決める.
 *
 * 案内画面へのリンクは誰でも作れるため、決まった形の値だけを使い、正しくない値はエラーにせず捨てる。
 * 003 の CoordinateQueryParser を使わないのは、度分や 1 行の貼り付けまで受け付けてしまい「決まった形」より広くなるため。
 * 予報画面が作るリンクはいつも正規形（27.75）なので、正規形だけを受け付ければ足りる
 */
final readonly class FeedbackContextParser
{
    // 予報画面がリンクを作るときにも同じ長さで切る
    public const int MAX_INPUT_LENGTH = 100;

    public function parse(string $latitude, string $longitude, string $updated, string $inputLatitude, string $inputLongitude): FeedbackContext
    {
        if ($this->isCoordinate($latitude, 90) && $this->isCoordinate($longitude, 180)) {
            return FeedbackContext::forecast($latitude, $longitude, $this->isLastUpdated($updated) ? $updated : null);
        }

        $inputLatitude = $this->sanitizeInput($inputLatitude);
        $inputLongitude = $this->sanitizeInput($inputLongitude);
        if ('' !== $inputLatitude || '' !== $inputLongitude) {
            return FeedbackContext::rejectedInput($inputLatitude, $inputLongitude);
        }

        return FeedbackContext::none();
    }

    private function isCoordinate(string $value, int $limit): bool
    {
        return 1 === preg_match('/^-?\d{1,3}\.\d{2}$/D', $value) && abs((float) $value) <= $limit;
    }

    private function isLastUpdated(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y/m/d H:i', $value);

        // 2026/02/30 のような繰り上がる日付を除くため、書式に戻して一致を確かめる
        return false !== $date && $date->format('Y/m/d H:i') === $value;
    }

    private function sanitizeInput(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $withoutControls = preg_replace('/\p{Cc}/u', ' ', $value) ?? '';

        return mb_substr($withoutControls, 0, self::MAX_INPUT_LENGTH);
    }
}
