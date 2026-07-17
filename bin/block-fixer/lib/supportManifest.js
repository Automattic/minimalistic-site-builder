'use strict';

/*
 * Development/oracle mirror of the reviewed PHP runtime manifest. The
 * registry generator parses supported-blocks.php and fails on any drift.
 */
const SUPPORTED_BLOCKS = Object.freeze({
  'core/paragraph': { strategy: 'STATIC_RENDERER', probes: [{ content: 'Probe paragraph' }] },
  'core/group': { strategy: 'STATIC_RENDERER', probes: [{ layout: { type: 'constrained' } }] },
  'core/column': { strategy: 'STATIC_RENDERER', probes: [{}] },
  'core/columns': { strategy: 'STATIC_RENDERER', probes: [{}] },
  'core/heading': { strategy: 'STATIC_RENDERER', probes: [{ content: 'Probe heading', level: 3 }] },
  'core/image': { strategy: 'STATIC_RENDERER', probes: [{ url: 'https://example.invalid/probe.jpg', alt: 'Probe', sizeSlug: 'large' }] },
  'core/separator': { strategy: 'STATIC_RENDERER', probes: [{ className: 'is-style-wide' }] },
  'core/buttons': { strategy: 'STATIC_RENDERER', probes: [{}] },
  'core/button': { strategy: 'STATIC_RENDERER', probes: [{ text: 'Probe button', url: '#probe' }] },
  'core/spacer': { strategy: 'STATIC_RENDERER', probes: [{ height: '40px' }] },
  'core/list': { strategy: 'STATIC_RENDERER', probes: [{}] },
  'core/list-item': { strategy: 'STATIC_RENDERER', probes: [{ content: 'Probe item' }] },
  'core/cover': { strategy: 'STATIC_RENDERER', probes: [{ dimRatio: 40, minHeight: 320 }] },
  'core/media-text': { strategy: 'STATIC_RENDERER', probes: [{ mediaUrl: 'https://example.invalid/probe.jpg', mediaType: 'image', mediaWidth: 45 }] },
  'core/quote': { strategy: 'STATIC_RENDERER', probes: [{}] },
  'core/pullquote': { strategy: 'STATIC_RENDERER', probes: [{ value: '<p>Probe quote</p>', citation: 'Source' }] },
  'core/gallery': { strategy: 'STATIC_RENDERER', probes: [{ columns: 2 }] },
  'core/table': { strategy: 'STATIC_RENDERER', probes: [{ body: [{ cells: [{ content: 'Probe', tag: 'td' }] }] }] },
  'core/embed': { strategy: 'STATIC_RENDERER', probes: [{ url: 'https://example.invalid/', type: 'rich', providerNameSlug: 'example' }] },
  'core/html': { strategy: 'RAW_CONTENT', probes: [{ content: '<div>Probe raw HTML</div>' }] },
  'core/navigation': { strategy: 'CONDITIONAL', probes: [{}, { ref: 42 }] },
  'core/navigation-link': { strategy: 'INNER_BLOCKS', probes: [{ label: 'Probe link', url: '#probe' }] },
  'core/social-links': { strategy: 'STATIC_RENDERER', probes: [{ size: 'has-normal-icon-size' }] },
  'core/social-link': { strategy: 'DYNAMIC_NULL', probes: [{ service: 'wordpress', url: 'https://wordpress.org/' }] },
  'core/page-list': { strategy: 'DYNAMIC_NULL', probes: [{}] },
  'core/post-content': { strategy: 'DYNAMIC_NULL', probes: [{}] },
  'core/site-logo': { strategy: 'DYNAMIC_NULL', probes: [{}] },
  'core/site-tagline': { strategy: 'DYNAMIC_NULL', probes: [{}] },
  'core/site-title': { strategy: 'DYNAMIC_NULL', probes: [{}] },
  'core/template-part': { strategy: 'DYNAMIC_NULL', probes: [{ slug: 'header', theme: 'probe' }] },
});

module.exports = {
  SUPPORTED_BLOCKS,
};
