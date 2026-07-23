'use strict';

/** Seeded JavaScript-number vectors for the PHP JsJsonEncoder differential. */
const MASK_64 = (1n << 64n) - 1n;

function nextBits(state) {
    let value = state & MASK_64;
    value ^= (value << 13n) & MASK_64;
    value ^= value >> 7n;
    value ^= (value << 17n) & MASK_64;
    return value & MASK_64;
}

function doubleFromBits(bits) {
    const bytes = Buffer.allocUnsafe(8);
    bytes.writeBigUInt64BE(bits);
    return bytes.readDoubleBE(0);
}

function serializeAttributes(attributes) {
    return JSON.stringify(attributes)
        .replaceAll('\\\\', '\\u005c')
        .replaceAll('--', '\\u002d\\u002d')
        .replaceAll('<', '\\u003c')
        .replaceAll('>', '\\u003e')
        .replaceAll('&', '\\u0026')
        .replaceAll('\\"', '\\u0022');
}

function vectors(seed, count) {
    let state = BigInt(seed) & MASK_64;
    if (state === 0n) state = 1n;
    const output = [];
    for (let index = 0; index < count; index++) {
        state = nextBits(state);
        const value = doubleFromBits(state);
        output.push({
            bits: state.toString(16).padStart(16, '0'),
            json: JSON.stringify(value),
            comment: serializeAttributes({ n: value }),
        });
    }
    return output;
}

const seed = process.argv[2] === undefined ? 0x6a09e667f3bcc909n : BigInt(process.argv[2]);
const count = process.argv[3] === undefined ? 512 : Number(process.argv[3]);
if (!Number.isSafeInteger(count) || count < 1 || count > 100000) {
    throw new Error('count must be an integer from 1 through 100000');
}
process.stdout.write(JSON.stringify({
    seed: `0x${seed.toString(16)}`,
    count,
    vectors: vectors(seed, count),
}) + '\n');
