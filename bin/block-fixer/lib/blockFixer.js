/**
 * Block Fixer Utility
 *
 * Uses @wordpress/blocks to parse, validate, and fix block HTML.
 * This fixes validation issues caused by AI-generated templates
 * that have style attribute mismatches.
 *
 * Key insight: The parse() function automatically applies validation fixes,
 * so we just need to parse and re-serialize to get clean, validated HTML.
 *
 * Diverges from the telex original in one way: authored comment attributes
 * are overlaid back over parse()'s output (overlayCommentAttributes below),
 * because parse()'s deprecated-version migrations silently delete modern
 * attributes from mismatched blocks.
 */

const { parse, serialize, createBlock } = require('@wordpress/blocks');
const { registerCoreBlocks } = require('@wordpress/block-library');
const { parse: grammarParse } = require('@wordpress/block-serialization-default-parser');
const { fixNestedParagraphs, fixNestedParagraphsDetailed } = require('./paragraphFixer');

let initialized = false;

/**
 * Initialize the WordPress block registry with core blocks.
 * Must be called before any parsing/serialization.
 */
function initializeBlockRegistry({ throwOnError = false } = {}) {
  if (initialized) return;

  try {
    // Register all core blocks (paragraph, heading, image, group, etc.)
    registerCoreBlocks();

    initialized = true;
    console.error('[BlockFixer] Block registry initialized with core blocks');
  } catch (error) {
    console.error('[BlockFixer] Failed to initialize block registry:', error);
    if (throwOnError) {
      initialized = false;
      throw error;
    }
    // Continue anyway - parsing might still work for basic blocks
    initialized = true;
  }
}

/**
 * Recursively fix a block and its inner blocks.
 * Invalid blocks are recreated using createBlock() to regenerate clean HTML.
 */
function normalizedAttributes(block, compatibilityRepairs = [], blockPath = []) {
  const attrs = { ...block.attributes };

  // core/media-text serializes an empty <figure> when mediaUrl is present but
  // mediaType is missing. AI markup often includes a valid <img src> in the
  // saved HTML without the matching JSON attribute, so preserve that media by
  // inferring the type before createBlock() reserializes it.
  if (block.name === 'core/media-text' && attrs.mediaUrl && !attrs.mediaType) {
    const source = `${attrs.mediaUrl} ${block.originalContent || ''}`;
    attrs.mediaType = /<\s*video\b|\.(?:mp4|webm|ogv)(?:[?#]|$)/i.test(source)
      ? 'video'
      : 'image';
    compatibilityRepairs.push({
      code: 'media-type-inference',
      blockName: block.name,
      blockPath: blockPath.join('/'),
      value: attrs.mediaType,
    });
  }

  return attrs;
}

/**
 * Re-assert each block's original comment-delimiter attributes over what
 * parse() returned.
 *
 * When a block's authored HTML doesn't match its current save() output (e.g.
 * the model declared spacing in the comment JSON but forgot the inline style),
 * parse() tries the block type's DEPRECATED versions, and a match silently
 * migrates the block through an old schema — deleting every modern attribute
 * it doesn't know (a header group loses "layout" and "textColor", flips to
 * isValid=true, and nothing is reported). In this pipeline the comment JSON is
 * always authored in the CURRENT format and is the single source of truth —
 * the whole point of the fixer is to regenerate the HTML from it — so the raw
 * comment attributes always win over migration output. HTML-sourced attributes
 * (paragraph content, image url/alt) live outside the comment JSON and are
 * untouched by the overlay.
 *
 * The grammar parser and parse() walk the same delimiters, so the trees are
 * parallel; both represent inter-block whitespace as nameless entries, which
 * are skipped on each side. Any structural surprise stops the overlay for that
 * subtree rather than guessing.
 */
function overlayCommentAttributes(blocks, rawBlocks) {
  let j = 0;
  for (const block of blocks) {
    if (!block.name) continue; // freeform/whitespace filler
    while (j < rawBlocks.length && !rawBlocks[j].blockName) j++;
    if (j >= rawBlocks.length) return;
    const raw = rawBlocks[j++];
    const rawName = raw.blockName.includes('/') ? raw.blockName : `core/${raw.blockName}`;
    if (rawName !== block.name) return; // trees diverged — don't guess
    block.attributes = { ...block.attributes, ...(raw.attrs || {}) };
    overlayCommentAttributes(block.innerBlocks || [], raw.innerBlocks || []);
  }
}

function fixBlockRecursively(block, compatibilityRepairs = [], blockPath = []) {
  // Recursively fix all inner blocks
  const fixedInnerBlocks = (block.innerBlocks || []).map((innerBlock, index) => (
    fixBlockRecursively(innerBlock, compatibilityRepairs, [...blockPath, index])
  ));

  // Always recreate blocks from attributes to normalize HTML structure.
  // This ensures element order, data-attributes, and CSS property order
  // exactly match what WordPress save() produces.
  if (!block.name) {
    // Can't fix blocks without a name (freeform HTML)
    return block;
  }

  return createBlock(
    block.name,
    normalizedAttributes(block, compatibilityRepairs, blockPath),
    fixedInnerBlocks.length > 0 ? fixedInnerBlocks : undefined
  );
}

/**
 * Fix block validation issues in template HTML.
 * Invalid blocks are recreated using createBlock() to regenerate clean markup.
 * Runtime callers keep the original bytes on an internal failure; oracle
 * callers opt into throwOnError so a broken reference transform cannot be
 * mistaken for a successful fixed point.
 */
function fixBlocksInTemplate(htmlContent, { throwOnError = false } = {}) {
  initializeBlockRegistry({ throwOnError });

  try {
    // Apply manual fixes before WordPress block parsing
    const compatibilityRepairs = [];
    const preParagraphRepair = fixNestedParagraphsDetailed(htmlContent);
    const preFixedContent = preParagraphRepair.html;
    if (preParagraphRepair.count > 0) {
      compatibilityRepairs.push({
        code: 'nested-paragraph',
        blockName: 'core/paragraph',
        blockPath: 'document',
        stage: 'pre-parse',
        count: preParagraphRepair.count,
      });
    }

    // Parse the HTML into blocks, then restore the authored comment
    // attributes that a deprecated-version migration may have destroyed.
    const blocks = parse(preFixedContent);
    overlayCommentAttributes(blocks, grammarParse(preFixedContent));

    // Check for invalid blocks
    const fixedIssues = [];

    const collectIssues = (blockList) => {
      for (const block of blockList) {
        if (!block.isValid) {
          const blockName = block.name || 'unknown';
          const blockIssues = block.validationIssues || [];
          if (blockIssues.length > 0) {
            for (const i of blockIssues) {
              let msg;
              if (typeof i === 'string') {
                msg = i;
              } else if (typeof i.message === 'string') {
                msg = i.message;
              } else if (Array.isArray(i.args) && typeof i.args[0] === 'string') {
                const template = i.args[0];
                const values = i.args.slice(1).map((v) => {
                  if (typeof v === 'string') return v;
                  if (Array.isArray(v) && v.every(Array.isArray)) {
                    return '[' + v.map((attr) => attr[0]).join(', ') + ']';
                  }
                  if (typeof v === 'object' && v !== null) return '{...}';
                  return String(v);
                });
                msg = template;
                values.forEach((v) => { msg = msg.replace(/%[os]/, v); });
              } else {
                msg = JSON.stringify(i);
              }
              fixedIssues.push(`${blockName}: ${msg}`);
            }
          } else {
            fixedIssues.push(`${blockName}: Block marked as invalid`);
          }
        }
        if (block.innerBlocks && block.innerBlocks.length > 0) {
          collectIssues(block.innerBlocks);
        }
      }
    };

    collectIssues(blocks);

    // Always re-serialize all blocks to normalize HTML structure.
    // Even blocks that parse() considers "valid" may have structural
    // differences (element order, missing data-attributes, CSS property
    // order) that cause validation failures in WordPress Playground.
    // Re-serializing via createBlock() ensures the HTML matches exactly
    // what WordPress save() produces.
    const fixedBlocks = blocks.map((block, index) => (
      fixBlockRecursively(block, compatibilityRepairs, [index])
    ));

    // Serialize the fixed blocks back to HTML
    let fixedHtml = serialize(fixedBlocks);

    // IMPORTANT: Run paragraph fixer AFTER serialize, because WordPress serialize()
    // can introduce nested <p> tags when there are style attribute mismatches.
    // The serializer wraps the original content (with unexpected styles) inside
    // a new <p> tag (with expected styles), creating nested paragraphs.
    const beforeParaFix = fixedHtml;
    const postParagraphRepair = fixNestedParagraphsDetailed(fixedHtml);
    fixedHtml = postParagraphRepair.html;
    if (postParagraphRepair.count > 0) {
      compatibilityRepairs.push({
        code: 'nested-paragraph',
        blockName: 'core/paragraph',
        blockPath: 'document',
        stage: 'post-serialize',
        count: postParagraphRepair.count,
      });
    }
    if (preFixedContent !== htmlContent || fixedHtml !== beforeParaFix) {
      fixedIssues.push('core/paragraph: Nested paragraph tags detected and removed');
    }

    // Check if normalization changed the HTML
    const wasChanged = fixedHtml !== preFixedContent;

    // Log any explicitly invalid blocks found during parsing
    if (fixedIssues.length > 0) {
      console.error(`[BlockFixer] Found ${fixedIssues.length} invalid block(s)`);
      for (const issue of fixedIssues) {
        console.error(`  - ${issue}`);
      }
    }

    if (wasChanged) {
      console.error(`[BlockFixer] HTML normalized (re-serialized ${blocks.length} block(s))`);
    }

    return {
      html: fixedHtml,
      changed: wasChanged,
      fixedIssues,
      compatibilityRepairs,
    };
  } catch (error) {
    console.error('[BlockFixer] Error fixing blocks:', error);
    if (throwOnError) {
      throw error;
    }
    return {
      html: htmlContent,
      changed: false,
      fixedIssues: [],
      compatibilityRepairs: [],
    };
  }
}

module.exports = {
  initializeBlockRegistry,
  fixBlocksInTemplate,
  fixNestedParagraphs,
  normalizedAttributes,
  overlayCommentAttributes,
};
