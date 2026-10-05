// 現在地の取得を 1 回分の結果として返す。Geolocation とタイマーは引数で受け取り、DOM には依存しない（research R8・R10）。
// 取得した位置はここで 2 桁の文字列にするだけで、保存も送信もしない（FR-015）。
import { formatPosition } from './position-format.js';

const PERMISSION_DENIED = 1;

// geolocation の timeout オプションは許可の確認ダイアログの待ち時間を含まないため、操作から 15 秒を数えるには独自のタイマーが要る。
// ダイアログで 15 秒以上迷うと取得できなかった扱いになるが、もう一度押せば取得できる
export const createLocationRequester = ({ geolocation, timeoutMs = 15000, setTimer, clearTimer }) => {
    let busy = false;

    const request = () => {
        // 取得中に重ねて取得しない（FR-017）
        if (busy) {
            return Promise.resolve({ status: 'busy' });
        }
        busy = true;

        return new Promise((resolve) => {
            let settled = false;
            let timer = null;

            // 時間切れのあとに届いた成功・失敗は、結果を変えないよう捨てる
            const finish = (result) => {
                if (settled) {
                    return;
                }
                settled = true;
                busy = false;
                if (timer !== null) {
                    clearTimer(timer);
                }
                resolve(result);
            };

            timer = setTimer(() => finish({ status: 'failed' }), timeoutMs);

            try {
                // 高精度は求めない（GPS の測位待ちより速く、2 桁に丸める用途には十分）。移動中に古い位置を使わないよう、キャッシュも使わない
                geolocation.getCurrentPosition(
                    (position) => finish({ status: 'ok', ...formatPosition(position.coords) }),
                    (error) => finish({ status: error?.code === PERMISSION_DENIED ? 'denied' : 'failed' }),
                    { enableHighAccuracy: false, maximumAge: 0 },
                );
            } catch {
                finish({ status: 'failed' });
            }
        });
    };

    return { request };
};
