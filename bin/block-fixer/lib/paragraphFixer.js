/**
 * Paragraph Fixer Utility
 *
 * Fixes nested <p> tags inside WordPress paragraph blocks.
 * This handles malformed HTML that AI models sometimes generate.
 */

/**
 * Parse HTML attributes from an attribute string.
 * Returns an object with attribute names as keys and values as values.
 */
function parseAttributes(attrString) {
  const attrs = {};
  if (!attrString) return attrs;

  // Match attribute="value" (double quotes) - handles single quotes inside
  const doubleQuotePattern = /(\S+)="([^"]*)"/g;
  let match;
  while ((match = doubleQuotePattern.exec(attrString)) !== null) {
    attrs[match[1]] = match[2];
  }

  // Match attribute='value' (single quotes) - handles double quotes inside
  const singleQuotePattern = /(\S+)='([^']*)'/g;
  while ((match = singleQuotePattern.exec(attrString)) !== null) {
    if (!(match[1] in attrs)) {
      attrs[match[1]] = match[2];
    }
  }

  return attrs;
}

/**
 * Merge two sets of attributes, combining classes and preferring inner values for others.
 * For class attributes, combines unique classes from both.
 * For style attributes, merges styles (inner overrides outer for same properties).
 * For other attributes, inner values are added if outer doesn't have them.
 */
function mergeAttributes(outerAttrs, innerAttrs) {
  const outer = parseAttributes(outerAttrs);
  const inner = parseAttributes(innerAttrs);
  const merged = { ...outer };

  for (const [key, value] of Object.entries(inner)) {
    if (key === 'class') {
      // Merge classes, keeping unique values
      const outerClasses = (outer.class || '').split(/\s+/).filter(Boolean);
      const innerClasses = value.split(/\s+/).filter(Boolean);
      const allClasses = [...new Set([...outerClasses, ...innerClasses])];
      merged.class = allClasses.join(' ');
    } else if (key === 'style') {
      // Merge styles - parse both and combine
      const outerStyles = (outer.style || '').split(';').filter(Boolean);
      const innerStyles = value.split(';').filter(Boolean);

      const styleMap = {};
      for (const style of [...outerStyles, ...innerStyles]) {
        const colonIdx = style.indexOf(':');
        if (colonIdx > 0) {
          const prop = style.substring(0, colonIdx).trim();
          const val = style.substring(colonIdx + 1).trim();
          styleMap[prop] = val;
        }
      }

      merged.style = Object.entries(styleMap)
        .map(([prop, val]) => `${prop}:${val}`)
        .join(';');
    } else if (!(key in outer)) {
      // Add inner attribute if outer doesn't have it
      merged[key] = value;
    }
  }

  return merged;
}

/**
 * Serialize attributes object back to HTML attribute string.
 */
function serializeAttributes(attrs) {
  const parts = [];
  for (const [key, value] of Object.entries(attrs)) {
    parts.push(`${key}="${value}"`);
  }
  return parts.length > 0 ? ' ' + parts.join(' ') : '';
}

/**
 * Fix nested <p> tags ONLY inside WordPress paragraph blocks.
 * Merges attributes from both tags: classes are combined, styles are merged,
 * and other attributes from inner are added if outer doesn't have them.
 * This handles malformed HTML that AI models sometimes generate.
 *
 * Only processes content within <!-- wp:paragraph --> blocks to avoid
 * accidentally modifying HTML that's not part of WordPress blocks.
 *
 * Example:
 *   <!-- wp:paragraph -->
 *   <p class="outer-class" style="color:red"><p class="inner-class" style="font-size:1rem">Text</p></p>
 *   <!-- /wp:paragraph -->
 * Becomes:
 *   <!-- wp:paragraph -->
 *   <p class="outer-class inner-class" style="color:red;font-size:1rem">Text</p>
 *   <!-- /wp:paragraph -->
 */
function fixNestedParagraphsDetailed(htmlContent) {
  // Only process content that contains WordPress paragraph blocks
  if (!htmlContent.includes('<!-- wp:paragraph')) {
    console.error('[ParagraphFixer] No wp:paragraph blocks found, skipping');
    return { html: htmlContent, count: 0 };
  }

  console.error('[ParagraphFixer] Processing content with wp:paragraph blocks...');

  // Pattern to match WordPress paragraph blocks with their content
  const wpParagraphBlockPattern = /(<!-- wp:paragraph[^>]*-->)([\s\S]*?)(<!-- \/wp:paragraph -->)/g;

  // Match nested <p> tags without treating > inside a quoted attribute value
  // as the end of either opening tag.
  const nestedPPattern = /<p((?:\s+(?:[^"'<>]|"[^"]*"|'[^']*')*)?)>(\s*)<p((?:\s+(?:[^"'<>]|"[^"]*"|'[^']*')*)?)>([\s\S]*?)<\/p>(\s*)<\/p>/gi;

  let result = htmlContent;
  let totalFixCount = 0;

  // Process each WordPress paragraph block
  result = result.replace(wpParagraphBlockPattern, (_fullMatch, openComment, blockContent, closeComment) => {
    let fixedContent = blockContent;
    let prevContent;
    let blockFixCount = 0;

    // Keep replacing nested p tags until no more are found
    do {
      prevContent = fixedContent;
      fixedContent = fixedContent.replace(nestedPPattern, (_match, outerAttrs, _ws1, innerAttrs, innerContent, _ws2) => {
        blockFixCount++;
        const mergedAttrs = mergeAttributes(outerAttrs, innerAttrs);
        const attrString = serializeAttributes(mergedAttrs);
        return `<p${attrString}>${innerContent}</p>`;
      });
    } while (fixedContent !== prevContent);

    totalFixCount += blockFixCount;
    return `${openComment}${fixedContent}${closeComment}`;
  });

  if (totalFixCount > 0) {
    console.error(`[ParagraphFixer] Fixed ${totalFixCount} nested <p> tag(s) in WordPress paragraph blocks`);
  }

  return { html: result, count: totalFixCount };
}

function fixNestedParagraphs(htmlContent) {
  return fixNestedParagraphsDetailed(htmlContent).html;
}

module.exports = {
  fixNestedParagraphs,
  fixNestedParagraphsDetailed,
  parseAttributes,
  mergeAttributes,
  serializeAttributes,
};
