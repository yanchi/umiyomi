<?php

declare(strict_types=1);

namespace App\Presentation\Web\Input;

/**
 * 項目 2 つの GET フォームで、表記の読み取り（CoordinateNotationParser）を 2 欄の組み合わせに当てはめる。
 * 独自の読み取りが中心で、2 欄が互いに依存する（FR-004）ため、Symfony Form ではなく専用のパーサーで解釈する（research R7）.
 */
final readonly class CoordinateQueryParser
{
    private const string EXAMPLES = '入力例：27.75 / 27.75, 129.05 / 27°45.0\'N / 27°45\'00"N';

    public function __construct(private CoordinateNotationParser $notationParser)
    {
    }

    /**
     * @param string|null $ignored リダイレクト先の URL の ignored パラメータ（lat / lon）。通知を出すためだけに使う
     */
    public function parse(?string $latitude, ?string $longitude, ?string $ignored = null): CoordinateQuery
    {
        $rawLatitude = $latitude ?? '';
        $rawLongitude = $longitude ?? '';

        $latitudeField = $this->notationParser->parse($rawLatitude);
        $longitudeField = $this->notationParser->parse($rawLongitude);

        $latitudeIsPair = self::looksLikePair($latitudeField);
        $longitudeIsPair = self::looksLikePair($longitudeField);

        if ($latitudeIsPair && $longitudeIsPair) {
            $message = $this->message(NotationError::BothPairs, Axis::Latitude);

            return new CoordinateQuery($rawLatitude, $rawLongitude, null, null, ['latitude' => $message, 'longitude' => $message]);
        }
        if ($latitudeIsPair) {
            return $this->fromPair($rawLatitude, $rawLongitude, $latitudeField, Axis::Latitude, $longitudeField);
        }
        if ($longitudeIsPair) {
            return $this->fromPair($rawLatitude, $rawLongitude, $longitudeField, Axis::Longitude, $latitudeField);
        }

        $errors = [];
        $latitudeAngle = $this->singleAngle($latitudeField, Axis::Latitude, $errors);
        $longitudeAngle = $this->singleAngle($longitudeField, Axis::Longitude, $errors);

        if (null === $latitudeAngle || null === $longitudeAngle) {
            return new CoordinateQuery($rawLatitude, $rawLongitude, null, null, $errors);
        }

        return $this->valid($rawLatitude, $rawLongitude, $latitudeAngle, $longitudeAngle, $this->ignoredFromParameter($ignored, $rawLatitude, $rawLongitude, $latitudeAngle, $longitudeAngle));
    }

    /**
     * 「1 行の形をしている」欄。中身に誤りがあっても、その欄に原因を出し、もう一方の欄は読まない（FR-004）.
     */
    private static function looksLikePair(ParsedField $field): bool
    {
        return FieldKind::Pair === $field->kind || $field->looksLikePair;
    }

    /**
     * 1 行の「緯度, 経度」が入った欄から地点を決める。エラーはその欄に出す.
     *
     * @param Axis $holder 1 行が入っている欄
     */
    private function fromPair(string $rawLatitude, string $rawLongitude, ParsedField $pair, Axis $holder, ParsedField $other): CoordinateQuery
    {
        $key = Axis::Latitude === $holder ? 'latitude' : 'longitude';
        $first = $pair->first;
        $second = $pair->second;

        if (FieldKind::Pair !== $pair->kind || null === $first || null === $second) {
            $error = $pair->error ?? NotationError::Unreadable;

            return new CoordinateQuery($rawLatitude, $rawLongitude, null, null, [$key => $this->message($error, $holder)]);
        }

        // 方角の文字があれば順序を問わずそれで決め、なければ常に先頭を緯度にする（FR-003）
        $longitudeFirst = Axis::Longitude === $first->axis;
        $latitudeAngle = $longitudeFirst ? $second : $first;
        $longitudeAngle = $longitudeFirst ? $first : $second;

        $outOfRange = $this->outOfRange($latitudeAngle, $longitudeAngle, null === $first->axis);
        if (null !== $outOfRange) {
            return new CoordinateQuery($rawLatitude, $rawLongitude, null, null, [$key => $outOfRange]);
        }

        // 使わなかった欄は、空でなければその旨を知らせる。どんな文字列が入っていてもエラーにはしない
        $ignoredField = FieldKind::Empty === $other->kind ? null : (Axis::Latitude === $holder ? 'longitude' : 'latitude');

        return $this->valid($rawLatitude, $rawLongitude, $latitudeAngle, $longitudeAngle, $ignoredField);
    }

    /**
     * 1 行の値の範囲を調べて、原因の文言を返す。範囲内なら null.
     */
    private function outOfRange(Angle $latitude, Angle $longitude, bool $withoutDirections): ?string
    {
        $latitudeFits = $latitude->isWithin(90);
        $longitudeFits = $longitude->isWithin(180);
        if ($latitudeFits && $longitudeFits) {
            return null;
        }

        // 入れ替えれば両方とも範囲内に収まるときだけ、順序の誤りと案内する。方角の文字があれば誤りではない。
        // 入れ替えて直すことはしない（35.00, 45.00 のように、どちらの順でも範囲内の入力があるため。FR-022）
        if ($withoutDirections && !$latitudeFits && $longitude->isWithin(90) && $latitude->isWithin(180)) {
            return $this->message(NotationError::SwappedOrder, Axis::Latitude);
        }

        // 1 欄に出せる文言は 1 つ。両方とも範囲外なら緯度を示し、緯度を直せば次の送信で経度の誤りが分かる
        return $this->message(NotationError::OutOfRange, Axis::Latitude, $latitudeFits ? Axis::Longitude : Axis::Latitude);
    }

    /**
     * @param 'latitude'|'longitude'|null $ignoredField
     */
    private function valid(string $rawLatitude, string $rawLongitude, Angle $latitude, Angle $longitude, ?string $ignoredField): CoordinateQuery
    {
        return new CoordinateQuery(
            $rawLatitude,
            $rawLongitude,
            $latitude->toFloat(),
            $longitude->toFloat(),
            [],
            $latitude->toCanonicalString(),
            $longitude->toCanonicalString(),
            $ignoredField,
        );
    }

    /**
     * リダイレクト先の URL の ignored パラメータを通知用の欄の名前にする。
     * 正規形でない入力では使わない。利用者が URL に書いた値でリダイレクトを経ずに通知が出るのを避けるため.
     *
     * @return 'latitude'|'longitude'|null
     */
    private function ignoredFromParameter(?string $ignored, string $rawLatitude, string $rawLongitude, Angle $latitude, Angle $longitude): ?string
    {
        if ($rawLatitude !== $latitude->toCanonicalString() || $rawLongitude !== $longitude->toCanonicalString()) {
            return null;
        }

        return match ($ignored) {
            'lat' => 'latitude',
            'lon' => 'longitude',
            default => null,
        };
    }

    /**
     * 1 欄に値 1 つとして読む。エラーは $errors に欄の軸のキーで足す.
     *
     * @param array<'latitude'|'longitude', string> $errors
     */
    private function singleAngle(ParsedField $field, Axis $axis, array &$errors): ?Angle
    {
        $key = Axis::Latitude === $axis ? 'latitude' : 'longitude';
        $error = $field->error;
        $angle = $field->first;

        if (FieldKind::Single === $field->kind && null !== $angle) {
            if (null !== $angle->axis && $angle->axis !== $axis) {
                $error = NotationError::AxisMismatch;
            } elseif (!$angle->isWithin(Axis::Latitude === $axis ? 90 : 180)) {
                $error = NotationError::OutOfRange;
            } else {
                return $angle;
            }
        }

        $errors[$key] = $this->message($error ?? NotationError::Unreadable, $axis);

        return null;
    }

    /**
     * 文言はここに集める。欄の名前（緯度・経度）を埋め込むため、読み取りの側では文言を持たない.
     *
     * @param Axis $field   エラーを出す欄の軸
     * @param Axis $subject 範囲外の値の軸。1 行の文字列では、入力した欄と範囲外の値の軸が異なることがある
     */
    private function message(NotationError $error, Axis $field, ?Axis $subject = null): string
    {
        $label = self::label($field);
        $subject ??= $field;

        return match ($error) {
            NotationError::Empty => \sprintf('%sを入力してください', $label),
            NotationError::TooLong => \sprintf('%sの入力が長すぎます。100 文字以内で入力してください', $label),
            NotationError::Unreadable => \sprintf('%sを読み取れませんでした。%s', $label, self::EXAMPLES),
            NotationError::Url => 'リンクからは地点を読み取れません。地図アプリで緯度・経度をコピーして貼り付けてください',
            NotationError::ConflictingDirections => \sprintf('%sに方角の文字が複数あります。N・S・E・W のどれか 1 つにしてください', $label),
            NotationError::SignAndDirection => \sprintf('%sにマイナス記号と方角の文字の両方があります。どちらか一方にしてください', $label),
            NotationError::DegreeNotInteger => '度分・度分秒で入力するときは、度を整数にしてください（例：27°45.0\'）',
            NotationError::MinuteSecondRange => '分・秒は 0 以上 60 未満で入力してください',
            NotationError::AxisMismatch => Axis::Latitude === $field
                ? '緯度には N（北緯）か S（南緯）を付けてください。E・W は経度に使います'
                : '経度には E（東経）か W（西経）を付けてください。N・S は緯度に使います',
            NotationError::OutOfRange => \sprintf('%sは %s の範囲で入力してください', self::label($subject), Axis::Latitude === $subject ? '-90〜90' : '-180〜180'),
            NotationError::TooManyValues => '緯度と経度の 2 つだけを入力してください。'.self::EXAMPLES,
            NotationError::UnitlessDegreeMinutes => '度分で入力するときは、度と分の記号を付けてください（例：27°45.0\' 129°03.0\'）',
            NotationError::DecimalComma => '小数点にはカンマではなくピリオドを使ってください（例：27.75）。「緯度, 経度」は、カンマのあとに空白を入れてください',
            NotationError::PairDirectionMixed => '緯度と経度の両方に方角の文字を付けるか、両方とも付けずに入力してください',
            NotationError::PairSameAxis => '緯度（N・S）と経度（E・W）を 1 つずつ入力してください',
            NotationError::SwappedOrder => '緯度と経度の順序が逆になっている可能性があります。「緯度, 経度」の順で入力してください（例：27.75, 129.05）',
            NotationError::BothPairs => '「緯度, 経度」はどちらか一方の欄だけに入力してください',
        };
    }

    private static function label(Axis $axis): string
    {
        return Axis::Latitude === $axis ? '緯度' : '経度';
    }
}
