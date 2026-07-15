import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  escapeODataString,
  odataLiteral,
  contactMatchFilter,
  contactMatchQuery,
  timelineFilter,
  timelineQuery,
  parseEntityIdHeader,
  EMAIL_REGARDING_TYPE,
} from '../dist/index.js';

test('escapeODataString doubles single quotes', () => {
  assert.equal(escapeODataString("O'Brien"), "O''Brien");
  assert.equal(odataLiteral("O'Brien"), "'O''Brien'");
});

test('contactMatchFilter lower-cases value and covers default fields', () => {
  const f = contactMatchFilter('Foo@Bar.COM');
  assert.equal(
    f,
    "(tolower(email) eq 'foo@bar.com' or tolower(email_business) eq 'foo@bar.com')",
  );
});

test('contactMatchFilter respects custom fields', () => {
  const f = contactMatchFilter('a@b.com', ['email', 'email_business', 'email_personal']);
  assert.match(f, /tolower\(email_personal\) eq 'a@b\.com'/);
});

test('contactMatchQuery includes $top=1 and $select=id', () => {
  const q = contactMatchQuery('a@b.com');
  const params = new URLSearchParams(q);
  assert.equal(params.get('$top'), '1');
  assert.equal(params.get('$select'), 'id');
  assert.ok(params.get('$filter').includes("tolower(email) eq 'a@b.com'"));
});

test('timelineFilter emits bare guid and quoted regarding_type', () => {
  const f = timelineFilter('11111111-2222-3333-4444-555555555555');
  assert.equal(
    f,
    "regarding_id eq 11111111-2222-3333-4444-555555555555 and regarding_type eq 'CRM.Email'",
  );
  assert.equal(EMAIL_REGARDING_TYPE, 'CRM.Email');
});

test('timelineQuery orders by sent_at desc', () => {
  const params = new URLSearchParams(timelineQuery('guid', 25));
  assert.equal(params.get('$orderby'), 'sent_at desc');
  assert.equal(params.get('$top'), '25');
});

test('parseEntityIdHeader extracts guid and quoted keys', () => {
  assert.equal(
    parseEntityIdHeader('https://x/odata/CRM.Email(9f8e7d6c-0000-1111-2222-333344445555)'),
    '9f8e7d6c-0000-1111-2222-333344445555',
  );
  assert.equal(parseEntityIdHeader("https://x/odata/EmailSet('abc-123')"), 'abc-123');
  assert.equal(parseEntityIdHeader(''), null);
  assert.equal(parseEntityIdHeader(null), null);
  assert.equal(parseEntityIdHeader('no-parens-here'), null);
});
