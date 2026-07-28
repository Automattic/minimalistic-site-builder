'use strict';

const CASES = [
  {
    name: 'group-html-attribute-repairs',
    milestone: 'M1',
    capabilities: [
      'block:core/group',
      'repair:custom-class-recovery',
      'repair:anchor-recovery',
      'repair:aria-label-recovery',
      'repair-gate:custom-class-already-authored',
      'repair-gate:anchor-already-authored',
      'repair-gate:aria-label-already-authored',
      'selector:attribute-root',
      'report:fixed',
    ],
    files: {
      'parts/content.html': String.raw`<!-- wp:group -->
<div class="wp-block-group campaign-copy" id="intro" aria-label="Intro group"></div>
<!-- /wp:group -->`,
      'parts/already-authored.html': String.raw`<!-- wp:group {"anchor":"kept","ariaLabel":"Kept group","className":"kept-class"} -->
<div class="wp-block-group kept-class" id="kept" aria-label="Kept group"></div>
<!-- /wp:group -->`,
    },
    repairs: [
      { file: 'parts/content.html', blockPath: '0', code: 'custom-class-recovery' },
      { file: 'parts/content.html', blockPath: '0', code: 'anchor-recovery' },
      { file: 'parts/content.html', blockPath: '0', code: 'aria-label-recovery' },
    ],
  },
  {
    name: 'nested-group-support',
    milestone: 'M1',
    capabilities: [
      'block:core/group',
      'block:core/paragraph',
      'nested-blocks',
      'inner-content-interleaving',
      'repair:nested-paragraph',
      'repair-gate:valid-paragraph',
      'rich-text:entities',
      'rich-text:br',
      'support:background-color',
      'support:text-color',
      'support:spacing',
      'support:layout',
    ],
    files: {
      'parts/nested.html': String.raw`<!-- wp:group {"align":"wide","backgroundColor":"contrast","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|lg","bottom":"2rem"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"className":"inner-copy"} -->
<p><p class="inner-copy">Hello&nbsp;<strong>world</strong><br>Next</p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`,
    },
    repairs: [
      { file: 'parts/nested.html', blockPath: '0/0', code: 'nested-paragraph' },
    ],
  },
  {
    name: 'rich-text-literal-nbsp',
    milestone: 'M1',
    capabilities: [
      'block:core/paragraph',
      'rich-text:literal-nbsp',
      'deprecation:paragraph-align',
      'report:ok',
    ],
    files: {
      'parts/literal-nbsp.html': `<!-- wp:paragraph {"textColor":"secondary","align":"center","className":"has-secondary-color has-text-color has-body-font-family","fontFamily":"body","fontSize":"caption","style":{"typography":{"textAlign":"center"}}} -->
<p class="has-text-align-center has-secondary-color has-text-color has-body-font-family has-caption-font-size">Studio: A · B</p>
<!-- /wp:paragraph -->`,
    },
    repairs: [],
  },
  {
    name: 'pre-paragraph-repair-noop',
    milestone: 'M1',
    capabilities: [
      'block:core/paragraph',
      'oracle:authoritative-changed-flag',
      'repair-gate:pre-paragraph-noop',
      'report:ok',
    ],
    files: {
      'parts/nested-paragraph.html': `<!-- wp:paragraph -->
<p><p>Hello</p></p>
<!-- /wp:paragraph -->`,
    },
    repairs: [],
  },
  {
    name: 'observed-deprecation-adapters',
    milestone: 'M1',
    capabilities: [
      'block:core/site-title',
      'block:core/navigation',
      'block:core/page-list',
      'deprecation:site-title-align',
      'deprecation:site-title-font-family',
      'deprecation:navigation-font-family',
      'report:fixed',
    ],
    files: {
      'parts/deprecations.html': `<!-- wp:site-title {"textAlign":"center"} /-->
<!-- wp:site-title {"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->
<!-- wp:navigation {"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} -->
<!-- wp:page-list /-->
<!-- /wp:navigation -->`,
    },
    repairs: [],
  },
  {
    name: 'dropped-inner-image-content',
    milestone: 'M1',
    capabilities: [
      'block:core/image',
      'report:dropped-style',
      'report:dropped-class',
      'report:repeat-counting',
      'report:vertical-rhythm-drop',
      'html:single-and-double-quotes',
    ],
    files: {
      'parts/image.html': String.raw`<!-- wp:image {"url":"theme:./assets/card.jpg","alt":"Card","sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="theme:./assets/card.jpg" alt="Card" class='legacy-crop legacy-crop' style='height:200px; object-fit:cover; width:100%; height:200px'/></figure>
<!-- /wp:image -->`,
    },
    repairs: [],
  },
  {
    name: 'freeform-missing-and-delimiters',
    milestone: 'M2',
    capabilities: [
      'freeform:before-between-after',
      'missing-block:paired',
      'missing-block:void',
      'missing-block:core/figure',
      'strategy:missing-block',
      'delimiter:balanced',
      'delimiter:mismatched',
      'delimiter:stray-closer',
      'delimiter:unclosed',
      'delimiter:malformed-json',
    ],
    files: {
      'templates/index.html': String.raw`Before <em>freeform</em>
<!-- wp:figure {"shape":"unknown"} -->
<figure class="legacy-figure"><img src="legacy.jpg" alt="Legacy"></figure>
<!-- /wp:figure -->
Between
<!-- /wp:group -->
<!-- wp:paragraph {"broken": -->
<p>Malformed JSON remains freeform.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Unclosed block
After`,
      'parts/mismatched.html': String.raw`<!-- wp:group -->
<div><!-- wp:paragraph --><p>Mismatched closer</p><!-- /wp:heading --></div>
<!-- /wp:group -->`,
      'parts/missing-void.html': '<!-- wp:figure {"shape":"void"} /-->',
    },
    repairs: [],
  },
  {
    name: 'json-comment-boundaries',
    milestone: 'M1',
    capabilities: [
      'block:core/group',
      'json:apostrophe',
      'json:quote',
      'json:backslash',
      'json:comment-boundary',
      'json:html-sensitive',
      'json:empty-array',
      'json:empty-object',
      'json:unicode',
      'json:numeric-key-order',
      'json:negative-zero',
      'json:exponent-formatting',
      'json:wrong-type',
      'json:defaults',
      'json:object-key-order',
    ],
    files: {
      'parts/json.html': String.raw`<!-- wp:group {"metadata":{"name":"O'Reilly \"quoted\" \\ path -- <tag> & — café 😀","categories":[],"bindings":{},"numericKeys":{"10":"ten","2":"two","01":"leading"},"negativeZero":-0,"small":1e-7,"large":1e+21,"patternName":""},"lock":"wrong-type","layout":{"type":"constrained"}} -->
<div class="wp-block-group"></div>
<!-- /wp:group -->`,
    },
    repairs: [],
  },
  {
    name: 'media-text-type-inference',
    milestone: 'M2',
    capabilities: [
      'block:core/media-text',
      'block:core/paragraph',
      'branch:media-text-image',
      'branch:media-text-video',
      'branch:media-text-position-left',
      'branch:media-text-position-right',
      'branch:media-text-image-fill',
      'repair:media-type-inference',
      'repair-gate:media-type-present',
      'selector:descendant',
      'source:attribute',
      'strategy:static-renderer',
    ],
    files: {
      'parts/media.html': String.raw`<!-- wp:media-text {"align":"wide","mediaPosition":"right","mediaWidth":58,"verticalAlignment":"center","mediaUrl":"theme:./assets/hero.jpg"} -->
<div class="wp-block-media-text alignwide has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-center" style="grid-template-columns:auto 58%"><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><figure class="wp-block-media-text__media"><img src="theme:./assets/hero.jpg" alt="Hero"/></figure></div>
<!-- /wp:media-text -->`,
      'parts/media-video.html': String.raw`<!-- wp:media-text {"mediaUrl":"https://example.invalid/video.mp4","mediaType":"video","mediaPosition":"left","mediaWidth":45,"verticalAlignment":"center"} -->
<!-- wp:paragraph {"content":"Video media"} /-->
<!-- /wp:media-text -->`,
      'parts/media-image.html': String.raw`<!-- wp:media-text {"mediaUrl":"https://example.invalid/image.jpg","mediaType":"image","mediaAlt":"Image alt","href":"https://example.com/full","linkTarget":"_blank","rel":"noreferrer","mediaPosition":"right","mediaWidth":60,"imageFill":true,"focalPoint":{"x":0.2,"y":0.8}} -->
<!-- wp:paragraph {"content":"Image media"} /-->
<!-- /wp:media-text -->`,
    },
    repairs: [
      { file: 'parts/media.html', blockPath: '0', code: 'media-type-inference' },
    ],
  },
  {
    name: 'structural-block-renderers',
    milestone: 'M2',
    capabilities: [
      'block:core/buttons',
      'block:core/button',
      'block:core/spacer',
      'block:core/list',
      'block:core/list-item',
      'branch:button-link-attributes',
      'deprecation:button-tag',
      'branch:spacer-height-width',
      'branch:list-ordered-reversed-start',
      'html:boolean-attribute',
      'rich-text:inline-formatting',
      'selector:descendant',
      'strategy:static-renderer',
      'support:layout-flex',
    ],
    files: {
      'parts/structural.html': String.raw`<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:button {"text":"Start <strong>now</strong>","url":"#start","linkTarget":"_blank","rel":"noreferrer noopener"} /-->
<!-- /wp:buttons -->
<!-- wp:spacer {"height":"72px","width":"25%"} /-->
<!-- wp:list {"ordered":true,"start":3,"reversed":true} -->
<!-- wp:list-item {"content":"First &amp; <em>best</em>"} /-->
<!-- wp:list-item {"content":"Second"} /-->
<!-- /wp:list -->`,
    },
    repairs: [],
  },
  {
    name: 'image-source-selector-branches',
    milestone: 'M2',
    capabilities: [
      'block:core/image',
      'branch:image-link',
      'branch:image-caption',
      'branch:image-title-alt',
      'html:void-element',
      'rich-text:inline-formatting',
      'selector:child',
      'selector:descendant',
      'source:attribute',
      'source:rich-text',
      'strategy:static-renderer',
    ],
    files: {
      'parts/image.html': String.raw`<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><a href="https://example.com/full" target="_blank" rel="noreferrer"><img src="https://example.invalid/link.jpg" alt="Linked" title="Image title"/></a><figcaption class="wp-element-caption">Linked <em>caption</em></figcaption></figure>
<!-- /wp:image -->`,
    },
    repairs: [],
  },
  {
    name: 'block-support-pipeline',
    milestone: 'M2',
    capabilities: [
      'block:core/group',
      'block:core/columns',
      'block:core/column',
      'block:core/paragraph',
      'support:alignment',
      'support:anchor',
      'support:aria-label',
      'support:background-image-skip-serialization',
      'support:border',
      'support:child-layout',
      'support:color-custom',
      'support:custom-class',
      'support:dimensions',
      'support:element-link-color',
      'support:flex-size',
      'support:font-family',
      'support:font-size',
      'support:gradient',
      'support:layout-flex',
      'support:self-stretch',
      'support:shadow',
      'support:spacing',
      'support:typography',
    ],
    files: {
      'parts/supports.html': String.raw`<!-- wp:group {"align":"full","anchor":"support-matrix","ariaLabel":"Support matrix","className":"campaign-shell","fontFamily":"heading","fontSize":"large","style":{"color":{"text":"#112233","background":"#f4f4f4","gradient":"linear-gradient(135deg,#ffffff 0%,#dddddd 100%)"},"elements":{"link":{"color":{"text":"var:preset|color|accent"}}},"typography":{"lineHeight":"1.4","fontWeight":"700","fontStyle":"italic","textTransform":"uppercase","textDecoration":"underline","letterSpacing":"0.05em"},"spacing":{"margin":{"top":"2rem","bottom":"3rem"},"padding":{"top":"1rem","right":"2rem","bottom":"1rem","left":"2rem"},"blockGap":"1.5rem"},"border":{"color":"#334455","style":"solid","width":"2px","radius":"12px"},"shadow":"0 2px 5px rgba(0,0,0,0.25)","dimensions":{"minHeight":"300px"},"background":{"backgroundImage":{"url":"https://example.invalid/background.jpg"},"backgroundPosition":"center center","backgroundSize":"cover"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:paragraph --><p>Supported content</p><!-- /wp:paragraph -->
<!-- /wp:group -->
<!-- wp:columns {"verticalAlignment":"center"} -->
<!-- wp:column {"width":"33.33%","style":{"layout":{"selfStretch":"fill","flexSize":"2"}}} -->
<!-- wp:paragraph --><p>Stretch</p><!-- /wp:paragraph -->
<!-- /wp:column -->
<!-- wp:column {"style":{"layout":{"selfStretch":"fixed","flexSize":"240px"}}} -->
<!-- wp:paragraph --><p>Fixed</p><!-- /wp:paragraph -->
<!-- /wp:column -->
<!-- /wp:columns -->`,
    },
    repairs: [],
  },
  {
    name: 'cover-renderer-branches',
    milestone: 'M2',
    capabilities: [
      'block:core/cover',
      'block:core/paragraph',
      'branch:cover-image',
      'branch:cover-video',
      'branch:cover-parallax',
      'branch:cover-repeat',
      'branch:cover-dim-zero',
      'branch:cover-dim-zero-gradient',
      'deprecation:paragraph-selectorless',
      'branch:cover-focal-point',
      'branch:cover-min-height-unit',
      'html:boolean-attribute',
      'html:void-element',
      'strategy:static-renderer',
      'support:dimensions',
    ],
    files: {
      'parts/image.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/cover.jpg","alt":"Cover image","dimRatio":40,"minHeight":320,"minHeightUnit":"px","contentPosition":"center center"} -->
<!-- wp:paragraph {"content":"Image cover"} /-->
<!-- /wp:cover -->`,
      'parts/video.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/cover.mp4","backgroundType":"video","poster":"https://example.invalid/poster.jpg","dimRatio":20,"minHeight":50,"minHeightUnit":"vh"} -->
<!-- wp:paragraph {"content":"Video cover"} /-->
<!-- /wp:cover -->`,
      'parts/parallax.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/parallax.jpg","hasParallax":true,"focalPoint":{"x":0.25,"y":0.75},"dimRatio":50} -->
<!-- wp:paragraph {"content":"Parallax cover"} /-->
<!-- /wp:cover -->`,
      'parts/repeated.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/tile.png","isRepeated":true,"dimRatio":0} -->
<!-- wp:paragraph {"content":"Repeated cover"} /-->
<!-- /wp:cover -->`,
      'parts/dim-zero-preset-gradient.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/cover.jpg","dimRatio":0,"gradient":"vivid-cyan-blue-to-vivid-purple"} -->
<!-- wp:paragraph {"content":"Preset gradient at zero dim"} /-->
<!-- /wp:cover -->`,
      'parts/dim-zero-custom-gradient.html': String.raw`<!-- wp:cover {"url":"https://example.invalid/cover.jpg","dimRatio":0,"overlayColor":"base","customGradient":"linear-gradient(red, blue)"} -->
<!-- wp:paragraph {"content":"Custom gradient at zero dim"} /-->
<!-- /wp:cover -->`,
    },
    repairs: [],
  },
  {
    name: 'quote-embed-and-raw-renderers',
    milestone: 'M2',
    capabilities: [
      'block:core/quote',
      'block:core/pullquote',
      'block:core/embed',
      'block:core/html',
      'block:core/paragraph',
      'branch:quote-inner-blocks-and-citation',
      'branch:pullquote-value-and-citation',
      'branch:embed-provider-caption',
      'rich-text:inline-formatting',
      'source:html',
      'source:raw',
      'source:rich-text',
      'strategy:raw-content',
      'strategy:static-renderer',
    ],
    files: {
      'parts/rich-tail.html': String.raw`<!-- wp:quote {"citation":"Source <em>One</em>"} -->
<!-- wp:paragraph {"content":"Quoted <strong>text</strong>"} /-->
<!-- /wp:quote -->
<!-- wp:pullquote {"value":"Probe <strong>quote</strong>","citation":"Source"} /-->
<!-- wp:embed {"url":"https://example.com/watch?v=1","type":"video","providerNameSlug":"youtube","responsive":true,"caption":"Video <em>caption</em>"} /-->
<!-- wp:html {"content":"<section data-raw=\"yes\"><p>Raw &amp; untouched</p></section>"} /-->`,
    },
    repairs: [],
  },
  {
    name: 'gallery-and-table-renderers',
    milestone: 'M2',
    capabilities: [
      'block:core/gallery',
      'block:core/image',
      'block:core/table',
      'branch:gallery-caption',
      'branch:gallery-cropped',
      'branch:gallery-not-cropped',
      'branch:gallery-nested-images',
      'branch:table-head-body-foot',
      'branch:table-caption',
      'branch:table-fluid-layout',
      'html:boolean-attribute',
      'selector:recursive-query',
      'source:attribute',
      'source:query',
      'source:rich-text',
      'source:tag',
      'strategy:static-renderer',
    ],
    files: {
      'parts/gallery.html': String.raw`<!-- wp:gallery {"columns":2,"caption":"Gallery <em>caption</em>","imageCrop":false,"fixedHeight":false,"aspectRatio":"4/3"} -->
<!-- wp:image {"url":"https://example.invalid/one.jpg","alt":"One"} /-->
<!-- wp:image {"url":"https://example.invalid/two.jpg","alt":"Two"} /-->
<!-- /wp:gallery -->
<!-- wp:gallery {"columns":1,"imageCrop":true,"fixedHeight":true,"aspectRatio":"1"} -->
<!-- wp:image {"url":"https://example.invalid/cropped.jpg","alt":"Cropped"} /-->
<!-- /wp:gallery -->`,
      'parts/table.html': String.raw`<!-- wp:table {"hasFixedLayout":false,"head":[{"cells":[{"content":"Name","tag":"th","scope":"col"},{"content":"Value","tag":"th","scope":"col"}]}],"body":[{"cells":[{"content":"Alpha","tag":"td"},{"content":"42","tag":"td","align":"right"}]}],"foot":[{"cells":[{"content":"Total","tag":"th","scope":"row"},{"content":"42","tag":"td"}]}],"caption":"Table <em>caption</em>"} /-->`,
    },
    repairs: [],
  },
  {
    name: 'navigation-strategy-branches',
    milestone: 'M2',
    capabilities: [
      'block:core/navigation',
      'block:core/navigation-link',
      'branch:navigation-without-ref',
      'branch:navigation-with-ref',
      'branch:navigation-link-children',
      'strategy:conditional',
      'strategy:inner-blocks',
      'serializer:rendered-content-void-paired',
    ],
    files: {
      'parts/navigation.html': String.raw`<!-- wp:navigation {"overlayMenu":"never"} -->
<!-- wp:navigation-link {"label":"Parent","url":"/parent"} -->
<!-- wp:navigation-link {"label":"Child","url":"/child"} /-->
<!-- /wp:navigation-link -->
<!-- /wp:navigation -->
<!-- wp:navigation {"ref":42} -->
<!-- wp:navigation-link {"label":"Omitted","url":"/omitted"} /-->
<!-- /wp:navigation -->`,
    },
    repairs: [],
  },
  {
    name: 'social-and-dynamic-strategies',
    milestone: 'M2',
    capabilities: [
      'block:core/social-links',
      'block:core/social-link',
      'block:core/page-list',
      'block:core/post-content',
      'block:core/site-logo',
      'block:core/site-tagline',
      'block:core/site-title',
      'block:core/template-part',
      'branch:social-links-wrapper',
      'branch:social-links-labels',
      'branch:social-links-child-comments',
      'strategy:dynamic-null',
      'strategy:static-renderer',
    ],
    files: {
      'parts/social.html': String.raw`<!-- wp:social-links {"openInNewTab":true,"showLabels":true,"size":"has-large-icon-size"} -->
<!-- wp:social-link {"url":"https://wordpress.org/","service":"wordpress","label":"WordPress"} /-->
<!-- wp:social-link {"url":"https://github.com/","service":"github","label":"GitHub"} /-->
<!-- /wp:social-links -->`,
      'templates/dynamic.html': String.raw`<!-- wp:page-list /-->
<!-- wp:post-content /-->
<!-- wp:site-logo {"width":120} /-->
<!-- wp:site-tagline /-->
<!-- wp:site-title {"level":2} /-->
<!-- wp:template-part {"slug":"header","theme":"probe"} /-->`,
    },
    repairs: [],
  },
  {
    name: 'report-order-status-and-skip',
    milestone: 'M1',
    capabilities: [
      'block:core/paragraph',
      'block:core/site-title',
      'report:fixed',
      'report:ok',
      'report:skip',
      'report:lexical-path-order',
      'report:empty-drops',
    ],
    files: {
      'templates/a-ok.html': '<!-- wp:site-title /-->',
      'parts/z-skipped.html': '<main>Plain HTML without block comments.</main>\n',
      'parts/a-fixed.html': '<!-- wp:paragraph --><p>Compact</p><!-- /wp:paragraph -->',
    },
    repairs: [],
  },
  {
    name: 'tbilisi25-footer-fixed-point',
    milestone: 'M1',
    capabilities: [
      'oracle:three-pass-convergence',
      'deprecation:group-default-tag',
      'deprecation:paragraph-pre-font-support',
      'deprecation:paragraph-selectorless',
      'deprecation:site-title-align',
      'repair:nested-paragraph',
      'nested-blocks',
      'block:core/group',
      'block:core/site-title',
      'block:core/paragraph',
      'block:core/separator',
      'block:core/columns',
      'block:core/column',
      'block:core/heading',
      'block:core/navigation',
      'block:core/navigation-link',
      'support:alignment',
      'support:color-elements',
      'support:custom-class',
      'support:font-family',
      'support:font-size',
      'support:layout',
      'support:preset-variables',
      'support:spacing',
      'support:typography',
    ],
    advisorySource: 'projects/tbilisi25/theme/parts/footer.html',
    advisoryTarget: 'parts/footer.html',
    repairs: [
      { file: 'parts/footer.html', blockPath: 'document', code: 'nested-paragraph' },
    ],
  },
  {
    name: "details-static-renderer",
    milestone: "M4",
    capabilities: [
      "block:core/details",
      "block:core/paragraph",
      "render:name-before-open-order",
      "render:empty-name-omitted",
      "render:anchor-id-slot",
      "support:color",
      "support:spacing",
      "report:ok",
      "report:fixed",
    ],
    files: {
      "parts/canonical.html": String.raw`<!-- wp:details -->
<details class="wp-block-details"><summary></summary></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Shipping</summary><!-- wp:paragraph -->
<p>Orders ship within two business days.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->`,
      "parts/repairs.html": String.raw`<!-- wp:details {"showContent":true} -->
<details class="wp-block-details"><summary>FAQ</summary><!-- wp:paragraph -->
<p>Answer</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"showContent":true,"name":"faq-group"} -->
<details class="wp-block-details"><summary>Group A</summary></details>
<!-- /wp:details -->

<!-- wp:details {"showContent":true,"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"1rem"}}}} -->
<details class="wp-block-details"><summary>Styled</summary></details>
<!-- /wp:details -->

<!-- wp:details {"anchor":"faq","showContent":true} -->
<details class="wp-block-details" id="faq"><summary>Anchored</summary></details>
<!-- /wp:details -->

<!-- wp:details {"name":"","showContent":true} -->
<details class="wp-block-details"><summary>Empty name</summary></details>
<!-- /wp:details -->`,
    },
    repairs: [],
  },
  {
    name: "generated-demo-layout-and-paragraph-signatures",
    milestone: "M3",
    capabilities: [
      "block:core/group",
      "block:core/columns",
      "block:core/column",
      "block:core/paragraph",
      "deprecation:columns-element-link",
      "deprecation:group-element-link",
      "deprecation:paragraph-selectorless",
      "legacy:group-top-level-border-drop",
      "legacy:group-top-level-shadow-drop",
      "repair:nested-paragraph",
      "support:layout",
      "support:layout-align-items",
      "support:layout-default",
      "support:layout-wide-size",
      "support:short-spacing-preset-signature",
      "support:spacing",
      "report:fixed",
    ],
    files: {
      "parts/align-items.html": String.raw`<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"center","alignItems":"center"}} -->
<div class="wp-block-group alignwide"></div>
<!-- /wp:group -->`,
      "parts/columns-element-link.html": String.raw`<!-- wp:columns {"align":"wide","textColor":"base","style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}}} -->
<div class="wp-block-columns alignwide has-base-color has-text-color"><!-- wp:column -->
<div class="wp-block-column"></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->`,
      "parts/group-element-link.html": String.raw`<!-- wp:group {"align":"full","backgroundColor":"contrast","textColor":"base","style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-color has-contrast-background-color has-text-color has-background"></div>
<!-- /wp:group -->`,
      "parts/layout-default.html": String.raw`<!-- wp:group {"align":"wide","className":"masonry-3","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide masonry-3"></div>
<!-- /wp:group -->`,
      "parts/legacy-border.html": String.raw`<!-- wp:group {"tagName":"header","align":"wide","backgroundColor":"base","border":{"bottom":{"width":"1px","color":"var:preset|color|primary"}},"layout":{"type":"constrained"}} -->
<header class="wp-block-group alignwide has-base-background-color has-background" style="border-bottom:1px solid var(--wp--preset--color--primary)"></header>
<!-- /wp:group -->`,
      "parts/legacy-shadow.html": String.raw`<!-- wp:group {"backgroundColor":"base","shadow":"var:preset|shadow|soft-settle","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-base-background-color has-background" style="box-shadow:var(--wp--preset--shadow--soft-settle)"></div>
<!-- /wp:group -->`,
      "parts/paragraph-spacing.html": String.raw`<!-- wp:paragraph {"align":"center","fontSize":"lead","textColor":"secondary","style":{"spacing":{"margin":{"top":"md","bottom":"lg"}}}} -->
<p class="has-text-align-center has-secondary-color has-text-color has-lead-font-size" style="margin-top:var(--wp--preset--spacing--md);margin-bottom:var(--wp--preset--spacing--lg)">Twenty years of documentary photography from Argentina.</p>
<!-- /wp:paragraph -->`,
      "parts/wide-size.html": String.raw`<!-- wp:group {"tagName":"header","className":"header-overlay","textColor":"base","layout":{"type":"constrained","wideSize":"1320px"}} -->
<header class="wp-block-group header-overlay has-base-color has-text-color"></header>
<!-- /wp:group -->`,
    },
    repairs: [
      { file: "parts/paragraph-spacing.html", blockPath: "document", code: "nested-paragraph" },
    ],
  },
  {
    name: "group-layout-content-size",
    milestone: "M2",
    capabilities: [
      "block:core/group",
      "block:core/paragraph",
      "support:layout",
      "support:layout-content-size",
      "support:spacing",
      "report:fixed",
    ],
    files: {
      "parts/constrained.html": String.raw`<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}},"layout":{"type":"constrained","contentSize":"850px"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p>Open Thursday through Sunday.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->`,
    },
    repairs: [],
  },
  {
    name: "heading-legacy-text-align",
    milestone: "M2",
    capabilities: [
      "block:core/heading",
      "deprecation:heading-text-align",
      "support:text-color",
      "support:font-family",
      "report:fixed",
    ],
    files: {
      "parts/heading.html": String.raw`<!-- wp:heading {"textAlign":"center","level":2,"fontFamily":"heading","textColor":"primary"} -->
<h2 class="wp-block-heading has-text-align-center has-primary-color has-text-color has-heading-font-family">A table set for strangers, kept warm for centuries</h2>
<!-- /wp:heading -->`,
    },
    repairs: [],
  },
  {
    name: "paragraph-inline-color-carryover",
    milestone: "M2",
    capabilities: [
      "block:core/paragraph",
      "deprecation:paragraph-selectorless",
      "repair:nested-paragraph",
      "repair:nested-paragraph-style-merge",
      "support:text-color",
      "support:font-size",
      "support:font-family",
      "support:typography",
      "report:fixed",
    ],
    files: {
      "parts/colophon.html": String.raw`<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.2em","textTransform":"uppercase"}},"fontSize":"caption","fontFamily":"heading","textColor":"base"} -->
<p class="has-text-color has-caption-font-size has-heading-font-family" style="color:var(--wp--preset--color--secondary);letter-spacing:0.2em;text-transform:uppercase">06 — Colophon</p>
<!-- /wp:paragraph -->`,
    },
    repairs: [
      { file: "parts/colophon.html", blockPath: "document", code: "nested-paragraph" },
    ],
  },
  {
    name: "site-tagline-legacy-text-align",
    milestone: "M4",
    capabilities: [
      "block:core/site-tagline",
      "deprecation:site-tagline-align",
      "deprecation:site-tagline-font-family",
      "support:typography",
      "report:fixed",
    ],
    files: {
      "parts/tagline.html": String.raw`<!-- wp:site-tagline {"textAlign":"center"} /-->
<!-- wp:site-tagline {"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->
<!-- wp:site-tagline {"textAlign":"center","style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} /-->`,
    },
    repairs: [],
  },
];

/*
 * Reviewable cutover contract for fixture coverage. Block-name completeness is
 * computed from SUPPORTED_BLOCKS; this list covers behavior that cannot be
 * inferred merely from a name appearing in a delimiter.
 */
const REQUIRED_CAPABILITIES = Object.freeze([
  'oracle:three-pass-convergence',
  'oracle:authoritative-changed-flag',
  'freeform:before-between-after',
  'missing-block:paired',
  'missing-block:void',
  'delimiter:balanced',
  'delimiter:mismatched',
  'delimiter:stray-closer',
  'delimiter:unclosed',
  'delimiter:malformed-json',
  'serializer:rendered-content-void-paired',
  'strategy:static-renderer',
  'strategy:raw-content',
  'strategy:conditional',
  'strategy:inner-blocks',
  'strategy:dynamic-null',
  'strategy:missing-block',
  'source:attribute',
  'source:html',
  'source:raw',
  'source:query',
  'source:rich-text',
  'source:tag',
  'selector:attribute-root',
  'selector:descendant',
  'selector:child',
  'selector:recursive-query',
  'rich-text:entities',
  'rich-text:literal-nbsp',
  'rich-text:br',
  'rich-text:inline-formatting',
  'deprecation:paragraph-align',
  'deprecation:paragraph-pre-font-support',
  'deprecation:paragraph-selectorless',
  'deprecation:group-default-tag',
  'deprecation:button-tag',
  'deprecation:site-title-align',
  'deprecation:site-title-font-family',
  'deprecation:navigation-font-family',
  'json:apostrophe',
  'json:quote',
  'json:backslash',
  'json:comment-boundary',
  'json:html-sensitive',
  'json:unicode',
  'json:empty-array',
  'json:empty-object',
  'json:numeric-key-order',
  'json:object-key-order',
  'json:negative-zero',
  'json:exponent-formatting',
  'json:wrong-type',
  'json:defaults',
  'repair:nested-paragraph',
  'repair:media-type-inference',
  'repair:custom-class-recovery',
  'repair:anchor-recovery',
  'repair:aria-label-recovery',
  'repair-gate:valid-paragraph',
  'repair-gate:pre-paragraph-noop',
  'repair-gate:media-type-present',
  'repair-gate:custom-class-already-authored',
  'repair-gate:anchor-already-authored',
  'repair-gate:aria-label-already-authored',
  'report:fixed',
  'report:ok',
  'report:skip',
  'report:lexical-path-order',
  'report:dropped-style',
  'report:dropped-class',
  'report:repeat-counting',
  'report:vertical-rhythm-drop',
  'branch:cover-image',
  'branch:cover-video',
  'branch:cover-parallax',
  'branch:cover-repeat',
  'branch:cover-dim-zero-gradient',
  'branch:media-text-image',
  'branch:media-text-video',
  'branch:table-head-body-foot',
  'branch:table-caption',
  'branch:gallery-caption',
  'branch:gallery-cropped',
  'branch:gallery-not-cropped',
  'branch:navigation-with-ref',
  'branch:navigation-without-ref',
  'branch:navigation-link-children',
  'branch:social-links-wrapper',
  'branch:social-links-child-comments',
  'support:alignment',
  'support:anchor',
  'support:aria-label',
  'support:background-image-skip-serialization',
  'support:border',
  'support:child-layout',
  'support:color-custom',
  'support:custom-class',
  'support:dimensions',
  'support:element-link-color',
  'support:flex-size',
  'support:font-family',
  'support:font-size',
  'support:gradient',
  'support:layout',
  'support:layout-flex',
  'support:preset-variables',
  'support:self-stretch',
  'support:shadow',
  'support:spacing',
  'support:typography',
]);

// Every deprecation index observed by the pinned instrumentation must have a
// reviewed disposition. The fixture generator rejects either an unreviewed
// hit or a stale disposition which is no longer exercised.
const REVIEWED_DEPRECATIONS = Object.freeze({
  'deprecation:core/button:3': 'raw-overlay-current-save-equivalent',
  'deprecation:core/group:0': 'raw-overlay-current-save-equivalent',
  'deprecation:core/navigation:3': 'explicit-adapter',
  'deprecation:core/paragraph:0': 'explicit-adapter',
  'deprecation:core/paragraph:1': 'explicit-adapter',
  'deprecation:core/paragraph:6': 'explicit-adapter',
  'deprecation:core/site-title:0': 'explicit-adapter',
  'deprecation:core/site-title:1': 'explicit-adapter',
  // Observed by the post-oracle cases adopted here. Each is handled by a
  // reviewed adapter in src/BlockSerializer/Attributes/DeprecationAdapters.php:
  // site-tagline shares siteIdentityText() with site-title; heading has its own.
  'deprecation:core/columns:0': 'explicit-adapter',
  'deprecation:core/group:4': 'explicit-adapter',
  'deprecation:core/heading:0': 'explicit-adapter',
  'deprecation:core/site-tagline:0': 'explicit-adapter',
  'deprecation:core/site-tagline:1': 'explicit-adapter',
});

module.exports = {
  CASES,
  REQUIRED_CAPABILITIES,
  REVIEWED_DEPRECATIONS,
};
