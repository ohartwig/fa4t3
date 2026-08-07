# [4.2.0](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.5...v4.2.0) (2026-08-07)


### Bug Fixes

* **commit-signing:** die .gitsigners war die leere Vorlage ([633cc51](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/633cc51247d0337e5406c4cd07e35f0bfaddd61e))


### Features

* **commit-signing:** add .gitsigners + lefthook hint ([196c4d5](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/196c4d5f6bf3848a9658788e2ca88fd90acc3289))

## [4.1.5](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.4...v4.1.5) (2026-07-29)


### Bug Fixes

* **docs:** point at the handbook repository, not an unreachable domain ([342c212](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/342c2122b3bff98c87326401ec20a7a1646355b7))

## [4.1.4](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.3...v4.1.4) (2026-07-28)


### Bug Fixes

* repair the unit suite, and the renamed keys it stopped guarding ([b8ac262](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/b8ac2629994c3ec6215862581f6dafea36ca68fc))

## [4.1.3](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.2...v4.1.3) (2026-07-24)


### Bug Fixes

* **ci:** drop github-mirror (no public mirroring for now) ([af5d51a](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/af5d51ab3af9427dd18dfd35732c053f1ca42b18))

## [4.1.2](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.1...v4.1.2) (2026-07-24)


### Bug Fixes

* **ci:** drop ter-publish (no TER publishing for now) ([5e9c6eb](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/5e9c6eb5c8cbd892bc0a55146bd1996c4fbe4df1))
* **ci:** ter-publish 1.2.12 (az1a IPv4 for tailor install) ([41b3885](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/41b3885a06e28be98b06e79a5b96b8bd1504b877))

## [4.1.1](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.1.0...v4.1.1) (2026-07-24)


### Bug Fixes

* **ci:** adopt github-mirror 1.2.10 (skip mirror when no token) ([0833a2e](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/0833a2efa7f1eaf9042183e8bc4535b63bc61120))
* **ci:** github-mirror 1.2.11 (contains skip-if-no-token) ([028ebba](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/028ebbaf4874bc2ee29f7c3f919e899258b4f30c))

# [4.1.0](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/compare/v4.0.5...v4.1.0) (2026-07-23)


### Bug Fixes

* drive Fathom consent gating from fa4t3ConsentCategory (data-category / data-consent) ([48990b5](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/48990b55f48d1a961b7a4ea4c6ee2843c6d164df))
* **readme:** license is MIT, not GPL-2.0-or-later ([aa3eefd](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/aa3eefd46282ca409e374431c3101f6a9cbe6887))
* **tests:** migrate [@test](https://git.ole-hartwig.eu/test) annotation to PHPUnit 13 #[Test] attribute ([9ae5857](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/9ae5857bd9978834b83e16defda3e008b5d5a346))


### Features

* **release:** add develop branch as rc-prerelease channel ([81c0861](https://git.ole-hartwig.eu/development/moselwal/typo3-fathom-analytics/commit/81c0861c7dc72f50f0d6926559a16c01f404d05e))

## [4.0.5](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/compare/v4.0.4...v4.0.5) (2026-06-07)


### Bug Fixes

* **release:** drop [skip ci] from semantic-release commit ([0755e26](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/0755e26e2cd4f96a45cee4352aaf9ee78452c6e1))

## [4.0.4](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/compare/v4.0.3...v4.0.4) (2026-06-07)


### Bug Fixes

* **ci:** allow_failure on 9 known-broken component jobs ([f23b0b3](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/f23b0b3060e75a2bede474b5957955206c7ba77e))
* composer normalize + require-checker whitelist for transitive symbols ([71d6f03](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/71d6f031400fc2d95f000e19b42f362a106fe95a))
* **composer:** add homepage field ([03608b0](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/03608b09d6bb2522830cdf8a74b1522a401b1933))
* **md:** replace bare fenced codeblocks with ```text ([73538c0](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/73538c088c21d0f858fa938113219eda3971faf9))
* **md:** scope markdownlint to public surfaces ([17f469e](https://gitlab.moselwal.io/development/moselwal/typo3-fathom-analytics/commit/17f469ec42ab90138c30b635cdafefe83c28eef4))
