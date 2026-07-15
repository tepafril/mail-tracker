import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

import {
  normalizeMessageId,
  normalizeAddress,
  syntheticKeyPreimage,
  syntheticKey,
  sha256Hex,
} from '../dist/index.js';

test('normalizeMessageId strips brackets, trims, lower-cases', () => {
  assert.equal(normalizeMessageId('<ABC@Example.COM>'), 'abc@example.com');
  assert.equal(normalizeMessageId('  <abc@x>  '), 'abc@x');
  assert.equal(normalizeMessageId('abc@x'), 'abc@x');
});

test('normalizeMessageId returns null for empty/nullish', () => {
  assert.equal(normalizeMessageId(''), null);
  assert.equal(normalizeMessageId('   '), null);
  assert.equal(normalizeMessageId('<>'), null);
  assert.equal(normalizeMessageId(null), null);
  assert.equal(normalizeMessageId(undefined), null);
});

test('normalizeAddress trims and lower-cases', () => {
  assert.equal(normalizeAddress('  Foo@Bar.COM '), 'foo@bar.com');
});

test('syntheticKeyPreimage is recipient-order-independent and de-duplicated', () => {
  const base = {
    tenantId: 't1',
    userId: 'u1',
    subject: 'Hello',
    sentAtMs: 1_700_000_000_000,
  };
  const a = syntheticKeyPreimage({ ...base, recipients: ['b@x.com', 'a@x.com'] });
  const b = syntheticKeyPreimage({ ...base, recipients: ['A@X.com', 'b@x.com', 'a@x.com'] });
  assert.equal(a, b);
  assert.match(a, /a@x\.com,b@x\.com/);
});

test('syntheticKeyPreimage buckets time to the minute and collapses subject whitespace', () => {
  const pre = syntheticKeyPreimage({
    tenantId: 't1',
    userId: 'u1',
    recipients: ['a@x.com'],
    subject: '  Re:   quarterly    report ',
    sentAtMs: 90_000, // 90s -> minute bucket 1
  });
  const lines = pre.split('\n');
  assert.equal(lines[0], 't1');
  assert.equal(lines[1], 'u1');
  assert.equal(lines[2], 'a@x.com');
  assert.equal(lines[3], 'Re: quarterly report');
  assert.equal(lines[4], '1');
});

test('negative epoch uses Math.floor (PHP mirror asserts the same)', () => {
  const bucket = (ms) =>
    syntheticKeyPreimage({ tenantId: 't', userId: 'u', recipients: ['a@x.com'], subject: 's', sentAtMs: ms }).split('\n')[4];
  assert.equal(bucket(-1), '-1');
  assert.equal(bucket(-60001), '-2');
  assert.equal(bucket(59999), '0');
});

test('BOM (U+FEFF) collapses like whitespace (PHP mirror asserts the same)', () => {
  const subject = 'x' + String.fromCharCode(0xfeff) + 'y';
  const pre = syntheticKeyPreimage({ tenantId: 't', userId: 'u', recipients: ['a@x.com'], subject, sentAtMs: 0 });
  assert.equal(pre.split('\n')[3], 'x y');
});

test('syntheticKey equals sha256 hex of the preimage', async () => {
  const input = {
    tenantId: 'tenant-guid',
    userId: 'user-guid',
    recipients: ['a@x.com', 'b@x.com'],
    subject: 'Deal update',
    sentAtMs: 1_700_000_055_000,
  };
  const pre = syntheticKeyPreimage(input);
  const expected = createHash('sha256').update(pre, 'utf8').digest('hex');
  assert.equal(await syntheticKey(input), expected);
  // GOLDEN cross-language vector — the PHP mirror (App\Support\DedupKey) asserts the
  // exact same digest for these inputs. If this changes, both suites must change.
  assert.equal(
    await syntheticKey(input),
    'd28005bf72531008c3dc3ebba90db03177b81317896fc06baad9e5239b7faee6',
  );
  // Two messages within the same minute + same recipients/subject collide (intended).
  const within = { ...input, sentAtMs: input.sentAtMs + 3000 };
  assert.equal(await syntheticKey(within), expected);
});

test('sha256Hex matches node crypto for a known vector', async () => {
  assert.equal(
    await sha256Hex('abc'),
    'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
  );
});
