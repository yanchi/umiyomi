// 端末から取得した位置を、入力欄に入れる小数点以下 2 桁（約 1km）の文字列にする。
// 規則（桁数・負号の扱い）だけを持ち、DOM には触れない。

// toFixed は 2 進数の値そのものを最も近い 2 桁に丸めるので、桁の境目で Math.round(x * 100) / 100 のような誤差が出ない。
// 丸めて 0 になる値に負号を付けると「-0.00」になるため、符号は丸めたあとの文字列を見て付ける
const formatDegrees = (value) => {
    const text = Math.abs(value).toFixed(2);

    return value < 0 && text !== '0.00' ? `-${text}` : text;
};

export const formatPosition = ({ latitude, longitude }) => ({
    latitude: formatDegrees(latitude),
    longitude: formatDegrees(longitude),
});
