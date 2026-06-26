/**
 * Block Fixer Utility
 *
 * Uses @wordpress/blocks to parse, validate, and fix block HTML.
 * This fixes validation issues caused by AI-generated templates
 * that have style attribute mismatches.
 *
 * Key insight: The parse() function automatically applies validation fixes,
 * so we just need to parse and re-serialize to get clean, validated HTML.
 */

const { parse, serialize, createBlock } = require('@wordpress/blocks');
const { registerCoreBlocks } = require('@wordpress/block-library');
const { fixNestedParagraphs } = require('./paragraphFixer');

let initialized = false;

/**
 * Initialize the WordPress block registry with core blocks.
 * Must be called before any parsing/serialization.
 */
function initializeBlockRegistry() {
  if (initialized) return;

  try {
    // Register all core blocks (paragraph, heading, image, group, etc.)
    registerCoreBlocks();

    initialized = true;
    console.error('[BlockFixer] Block registry initialized with core blocks');
  } catch (error) {
    console.error('[BlockFixer] Failed to initialize block registry:', error);
    // Continue anyway - parsing might still work for basic blocks
    initialized = true;
  }
}

/**
 * Recursively fix a block and its inner blocks.
 * Invalid blocks are recreated using createBlock() to regenerate clean HTML.
 */
function fixBlockRecursively(block) {
  // Recursively fix all inner blocks
  const fixedInnerBlocks = [];

  if (block.innerBlocks && block.innerBlocks.length > 0) {
    for (const innerBlock of block.innerBlocks) {
      const result = fixBlockRecursively(innerBlock);
      fixedInnerBlocks.push(result.block);
    }
  }

  // Always recreate blocks from attributes to normalize HTML structure.
  // This ensures element order, data-attributes, and CSS property order
  // exactly match what WordPress save() produces.
  if (!block.name) {
    // Can't fix blocks without a name (freeform HTML)
    return { block, wasFixed: false };
  }

  const fixedBlock = createBlock(
    block.name,
    block.attributes,
    fixedInnerBlocks.length > 0 ? fixedInnerBlocks : undefined
  );

  return { block: fixedBlock, wasFixed: true };
}

/**
 * Fix block validation issues in template HTML.
 * Invalid blocks are recreated using createBlock() to regenerate clean markup.
 */
function fixBlocksInTemplate(htmlContent) {
  initializeBlockRegistry();

  try {
    // Apply manual fixes before WordPress block parsing
    const preFixedContent = fixNestedParagraphs(htmlContent);

    // Parse the HTML into blocks
    const blocks = parse(preFixedContent);

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
    const fixedBlocks = blocks.map((block) => fixBlockRecursively(block).block);

    // Serialize the fixed blocks back to HTML
    let fixedHtml = serialize(fixedBlocks);

    // IMPORTANT: Run paragraph fixer AFTER serialize, because WordPress serialize()
    // can introduce nested <p> tags when there are style attribute mismatches.
    // The serializer wraps the original content (with unexpected styles) inside
    // a new <p> tag (with expected styles), creating nested paragraphs.
    const beforeParaFix = fixedHtml;
    fixedHtml = fixNestedParagraphs(fixedHtml);
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
    };
  } catch (error) {
    console.error('[BlockFixer] Error fixing blocks:', error);
    return {
      html: htmlContent,
      changed: false,
      fixedIssues: [],
    };
  }
}

/**
 * Extract the HTML block markup portion from a PHP pattern file.
 * Pattern files have a PHP header comment followed by `?>` and then HTML.
 * Returns { header, html } where header is everything up to and including `?>`.
 */
function splitPatternPhp(content) {
  // Find the closing PHP tag that separates the header from HTML
  const closeTagIndex = content.indexOf('?>');
  if (closeTagIndex === -1) {
    return null;
  }
  const headerEnd = closeTagIndex + 2;
  return {
    header: content.substring(0, headerEnd),
    html: content.substring(headerEnd),
  };
}

/**
 * Split a content file into its leading <!-- telex:meta --> header and body.
 * Returns { header: '', body: fullContent } when no header is present.
 *
 * @param {string} content
 * @returns {{ header: string, body: string }}
 */
function splitContentFile(content) {
  const trimmed = content.replace(/^\s+/, '');
  if (!trimmed.startsWith('<!-- telex:meta')) {
    return { header: '', body: content };
  }
  const closeIdx = trimmed.indexOf('-->');
  if (closeIdx === -1) {
    return { header: '', body: content };
  }
  const header = trimmed.slice(0, closeIdx + 3);
  const body = trimmed.slice(closeIdx + 3);
  return { header, body };
}

/**
 * Process an array of artefact files, fixing any template/parts/pattern files.
 * Preserves all original file properties while updating content.
 */
function fixArtefactTemplates(files) {
  // Initialize early to catch any registration errors
  initializeBlockRegistry();

  return files.map((file) => {
    // Process template and parts HTML files
    const isTemplate =
      file.path.startsWith('templates/') && file.path.endsWith('.html');
    const isPart =
      file.path.startsWith('parts/') && file.path.endsWith('.html');
    // Also process pattern PHP files (they contain block HTML after a PHP header)
    const isPattern =
      file.path.startsWith('patterns/') && file.path.endsWith('.php');
    const isContent =
      file.path.startsWith('content/') && file.path.endsWith('.html');

    if (!isTemplate && !isPart && !isPattern && !isContent) {
      return file;
    }

    // Only process files that contain WordPress block comments
    // Plain HTML files without block markup should not be processed
    if (!file.content.includes('<!-- wp:')) {
      console.error(`[BlockFixer] Skipping ${file.path} - no WordPress block markup found`);
      return file;
    }

    if (isPattern) {
      // Pattern files: split PHP header from HTML, fix only the HTML part
      const parts = splitPatternPhp(file.content);
      if (!parts) {
        console.error(`[BlockFixer] Skipping ${file.path} - no closing ?> tag found`);
        return file;
      }

      const result = fixBlocksInTemplate(parts.html);

      if (result.changed) {
        console.error(`[BlockFixer] Fixed blocks in ${file.path}`);
      }

      return {
        ...file,
        content: parts.header + result.html,
      };
    }

    if (isContent) {
      const { header, body } = splitContentFile(file.content);
      const result = fixBlocksInTemplate(body);

      if (result.changed) {
        console.error(`[BlockFixer] Fixed blocks in ${file.path}`);
      }

      const prefix = header ? `${header}\n` : '';
      return {
        ...file,
        content: prefix + result.html,
      };
    }

    // Template/parts HTML files
    const result = fixBlocksInTemplate(file.content);

    if (result.changed) {
      console.error(`[BlockFixer] Fixed blocks in ${file.path}`);
    }

    // Spread original file to preserve additional properties
    return {
      ...file,
      content: result.html,
    };
  });
}

module.exports = {
  initializeBlockRegistry,
  fixBlocksInTemplate,
  fixArtefactTemplates,
  fixNestedParagraphs,
  splitContentFile,
};
