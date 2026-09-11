The `codex-perf` branch collects changes from the Tbilisi11, Lumen10, and Atlas10 request audits. Its draft PR targets `trunk`.

The changes reduce repeated context, prevent conflicting instructions, and reduce serial requests. Each implementation PR targets `codex-perf` for separate review.

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

The combined test commit `1d9ce7c041f35a3f74e3f9709c95604c32618c21` on `codex-perf-validation` passed PHP 8.1 and PHP 8.4 CI. See [the combined CI run](https://github.com/Automattic/minimalistic-site-builder/actions/runs/34615806748). That test branch includes the implementation changes and their merge conflict resolutions.

Early image overlap remains disabled. PR #679 supplies request preparation; a host still needs scheduler and serial result-application work.

The paid four-page `tbilisi12` demo completed after an API schema fix. Its estimated cost is $2.36 across both attempts. Active build time is 200.2 seconds, excluding the repair pause. See [the multi-page audit](codex-perf-multipage.md) for request details, limits, and open findings. This run does not establish a controlled percentage improvement.
