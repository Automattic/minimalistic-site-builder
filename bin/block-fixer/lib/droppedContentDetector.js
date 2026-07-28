'use strict';

/** Count each normalized `prop:value` declaration in quoted style attrs. */
function styleDeclarationCounts(html) {
  const counts = new Map();
  for (const match of html.matchAll(/\bstyle\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    const value = match[1] ?? match[2] ?? '';
    for (const raw of value.split(';')) {
      const declaration = raw.trim();
      if (!declaration) continue;
      const colon = declaration.indexOf(':');
      const normalized = colon === -1
        ? declaration
        : declaration.slice(0, colon).trim().toLowerCase()
          + ':' + declaration.slice(colon + 1).trim();
      counts.set(normalized, (counts.get(normalized) || 0) + 1);
    }
  }
  return counts;
}

/** Count each whitespace-delimited token in quoted class attrs. */
function classTokenCounts(html) {
  const counts = new Map();
  for (const match of html.matchAll(/\bclass\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    const value = match[1] ?? match[2] ?? '';
    for (const token of value.split(/\s+/)) {
      if (token) counts.set(token, (counts.get(token) || 0) + 1);
    }
  }
  return counts;
}

function droppedValues(before, after) {
  const dropped = [];
  for (const [value, count] of before) {
    const remaining = after.get(value) || 0;
    if (remaining < count) {
      dropped.push({ value, lost: count - remaining });
    }
  }
  return dropped;
}

function recordLine(record) {
  return `DROPPED ${record.kind} \`${record.value}\``
    + (record.lost > 1 ? ` (x${record.lost})` : '')
    + ' — not mirrored in the block comment JSON attributes';
}

/**
 * Structured, occurrence-counted original-to-final loss records. Record order
 * is stable and contractual: original style order, then original class order.
 */
function detectDroppedContentRecords(original, fixed) {
  const records = [];
  for (const dropped of droppedValues(
    styleDeclarationCounts(original),
    styleDeclarationCounts(fixed)
  )) {
    const record = { kind: 'style', ...dropped };
    records.push({ ...record, line: recordLine(record) });
  }
  for (const dropped of droppedValues(
    classTokenCounts(original),
    classTokenCounts(fixed)
  )) {
    const record = { kind: 'class', ...dropped };
    records.push({ ...record, line: recordLine(record) });
  }
  return records;
}

function detectDroppedContent(original, fixed) {
  return detectDroppedContentRecords(original, fixed).map((record) => record.line);
}

module.exports = {
  classTokenCounts,
  detectDroppedContent,
  detectDroppedContentRecords,
  droppedValues,
  recordLine,
  styleDeclarationCounts,
};
