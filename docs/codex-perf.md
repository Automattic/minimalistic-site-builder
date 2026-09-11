The `codex-perf` branch collects changes from the Tbilisi11, Lumen10, and Atlas10 request audits. Its draft PR targets `trunk`.

The changes reduce repeated context, prevent conflicting instructions, and reduce serial requests. The original implementation PRs and the multi-page fixes target `codex-perf`.

| PR | Change |
|---|---|
| [#667](https://github.com/Automattic/minimalistic-site-builder/pull/667) | Repair page surface budgets before model requests. |
| [#668](https://github.com/Automattic/minimalistic-site-builder/pull/668) | Preserve required photographs in compatible compositions. |
| [#669](https://github.com/Automattic/minimalistic-site-builder/pull/669) | Reduce markup context and select only required recipes. |
| [#670](https://github.com/Automattic/minimalistic-site-builder/pull/670) | Run independent image checks and retries in concurrent batches. |
| [#671](https://github.com/Automattic/minimalistic-site-builder/pull/671) | Count provider attempts separately from delivered assets. |
| [#672](https://github.com/Automattic/minimalistic-site-builder/pull/672) | Render common UI illustrations locally. |
| [#673](https://github.com/Automattic/minimalistic-site-builder/pull/673) | Use 1K images for small display slots. |
| [#674](https://github.com/Automattic/minimalistic-site-builder/pull/674) | Compile fixed theme values and request bounded typography choices. |
| [#675](https://github.com/Automattic/minimalistic-site-builder/pull/675) | Match action labels to available destinations. |
| [#676](https://github.com/Automattic/minimalistic-site-builder/pull/676) | Prepare the markup cache with a useful request. |
| [#677](https://github.com/Automattic/minimalistic-site-builder/pull/677) | Reuse equivalent image requests and preserve separate asset paths. |
| [#678](https://github.com/Automattic/minimalistic-site-builder/pull/678) | Combine concept selection and design expansion. |
| [#679](https://github.com/Automattic/minimalistic-site-builder/pull/679) | Prepare isolated image requests for a future scheduler. |
| [#683](https://github.com/Automattic/minimalistic-site-builder/pull/683) | Select one useful cache writer for each shared prefix. |
| [#684](https://github.com/Automattic/minimalistic-site-builder/pull/684) | Enforce planned image counts and preserve gallery content. |
| [#685](https://github.com/Automattic/minimalistic-site-builder/pull/685) | Share the page-plan prefix across initial and repair requests. |
| [#686](https://github.com/Automattic/minimalistic-site-builder/pull/686) | Use explicit image slots for crop, size, and hero QA. |
| [#687](https://github.com/Automattic/minimalistic-site-builder/pull/687) | Repair multi-page actions and dead pattern links. |
| [#688](https://github.com/Automattic/minimalistic-site-builder/pull/688) | Filter final image references and reuse eligible paid results. |
| [#689](https://github.com/Automattic/minimalistic-site-builder/pull/689) | Bound QA payload memory and preserve concurrent replacements. |
| [#690](https://github.com/Automattic/minimalistic-site-builder/pull/690) | Repair card surfaces, text scale, and section headings. |
| [#691](https://github.com/Automattic/minimalistic-site-builder/pull/691) | Overlap image generation, QA, and replacements on one scheduler. |
| [#692](https://github.com/Automattic/minimalistic-site-builder/pull/692) | Start raw image requests after page assembly and apply ready results in the normal image phase. |
| [#693](https://github.com/Automattic/minimalistic-site-builder/pull/693) | Preserve cover and full-width slots and square logo requests. |
| [#694](https://github.com/Automattic/minimalistic-site-builder/pull/694) | Repair compact interior heroes before another model request. |
| [#695](https://github.com/Automattic/minimalistic-site-builder/pull/695) | Repair text card classes, hero headings, and long caption-size paragraphs. |
| [#696](https://github.com/Automattic/minimalistic-site-builder/pull/696) | Match enquiry actions and copy to verified contact channels. |
| [#697](https://github.com/Automattic/minimalistic-site-builder/pull/697) | Ignore harmless raw font sizes that match the theme scale. |
| [#698](https://github.com/Automattic/minimalistic-site-builder/pull/698) | Test image overlap with explicit event order instead of processor speed. |

The branch contains all 29 fix PRs above. Each PR passed PHP 8.1 and PHP 8.4 CI before its merge. The changes now form the defaults.

The CLI starts raw image requests after page assembly. The normal image phase applies ready results and starts QA while slower image requests continue. See [the early image stage contract](early-image-stage.md).

The paid four-page `tbilisi12` demo exposed the multi-page defects. Its estimated cost was $2.36 across both attempts. Active build time was 200.2 seconds, excluding the repair pause. See [the original audit](codex-perf-multipage.md) and [the fix verification](codex-perf-verification.md) for evidence and limits. These runs do not establish a controlled percentage improvement.

The second paid demo, `tbilisi13`, completed five pages and 19 sections in 189.4 seconds at an estimated cost of $2.23. It confirms cache reuse and image/QA overlap. Saved-response tests verify the additional fixes that followed this run. No Linear issues exist for these PRs.
