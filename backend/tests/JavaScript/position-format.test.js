import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { formatPosition } from '../../assets/coordinate-input/position-format.js';

describe('formatPosition', () => {
    it('小数点以下 2 桁の文字列にする', () => {
        assert.deepEqual(formatPosition({ latitude: 27.754, longitude: 129.0461 }), { latitude: '27.75', longitude: '129.05' });
    });

    it('マイナスの値は符号を付けたまま 2 桁にする', () => {
        assert.equal(formatPosition({ latitude: -33.8688, longitude: 151.2093 }).latitude, '-33.87');
        assert.equal(formatPosition({ latitude: 0, longitude: -70.255 }).longitude, '-70.25');
    });

    it('丸めて 0 になる値に負号を付けない', () => {
        assert.equal(formatPosition({ latitude: -0.004, longitude: 0 }).latitude, '0.00');
    });

    it('0 と -0 は 0.00', () => {
        assert.deepEqual(formatPosition({ latitude: 0, longitude: -0 }), { latitude: '0.00', longitude: '0.00' });
    });

    it('範囲の端の値', () => {
        assert.equal(formatPosition({ latitude: 90, longitude: -180 }).latitude, '90.00');
        assert.equal(formatPosition({ latitude: 90, longitude: -180 }).longitude, '-180.00');
    });
});
