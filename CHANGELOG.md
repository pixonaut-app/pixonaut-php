# Changelog

## [5.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v4.0.0...v5.0.0) (2026-11-11)


### ⚠ BREAKING CHANGES

* the UV-pass models' constructors take `fromFlatLayout` before `occluderAssetId` and `pieceSizesMm`, so a call that passes those by position must name them.

### Features

* target the published Pixonaut API 4.1.0 ([#34](https://github.com/pixonaut-app/pixonaut-php/issues/34)) ([6c09250](https://github.com/pixonaut-app/pixonaut-php/commit/6c092507579bd889555fd87ee0ee481e7c9dff26))

## [4.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v3.1.0...v4.0.0) (2026-11-11)

Targets the Pixonaut API 4.0.0.

### ⚠ BREAKING CHANGES

* the models of these answers take the new required arguments, inserted among their constructors' positional parameters: print-size verdicts (`lowResolution`), assets (`lowResolutionAccepted`), `PrintsGetResponse` (`lowResolution`, `remake`), blanks (`generation`), fitting templates and placements and a share link's designer answer (`lowResolution`), a variant appearance's image (`mime`) and a printshop connection (`catalogue`). The new optional arguments sit among the existing optional ones, so code passing those positionally must name them.

### Features

* target the published Pixonaut API 4.0.0 ([#32](https://github.com/pixonaut-app/pixonaut-php/issues/32)) ([7c87d0a](https://github.com/pixonaut-app/pixonaut-php/commit/7c87d0aa22c1524d2fd9dbd1d558648f7a7da9d8))

## [3.1.0](https://github.com/pixonaut-app/pixonaut-php/compare/v3.0.0...v3.1.0) (2026-11-10)

Targets the Pixonaut API 3.0.0.

### Features

* map a batched request that got no response ([#29](https://github.com/pixonaut-app/pixonaut-php/issues/29)) ([076dee4](https://github.com/pixonaut-app/pixonaut-php/commit/076dee487c25068212645f451c09c8458d255070))

## [3.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v2.0.1...v3.0.0) (2026-11-10)

Targets the Pixonaut API 3.0.0.

### ⚠ BREAKING CHANGES

* ScenesListResponseScenesItem takes the required `personSwappable` and `workspace` arguments, inserted among its constructor's positional parameters. A tool result picture's `of` can now be a `...PictureOfSceneCandidate`, so code that handles every variant of that union must handle it too.

### Features

* target the published Pixonaut API 3.0.0 ([#27](https://github.com/pixonaut-app/pixonaut-php/issues/27)) ([96b419e](https://github.com/pixonaut-app/pixonaut-php/commit/96b419eaa10bb1042cb0176d27aff41ebab57886))

## [2.0.1](https://github.com/pixonaut-app/pixonaut-php/compare/v2.0.0...v2.0.1) (2026-11-09)

Targets the Pixonaut API 2.0.0.

### Bug Fixes

* target the published Pixonaut API 2.0.0 ([#25](https://github.com/pixonaut-app/pixonaut-php/issues/25)) ([1b5db65](https://github.com/pixonaut-app/pixonaut-php/commit/1b5db6578859ce7f8297f3ba45f980a4748a2e58))

## [2.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v1.0.0...v2.0.0) (2026-11-09)

Targets the Pixonaut API 1.0.0.

### ⚠ BREAKING CHANGES

* `photoSource` and `scenes` are gone from BlanksListResponseBlanksItem and BlanksGetResponse, `garment` from their placements, and `colour` and `printCoverage` from ScenesListResponseScenesItem, with the BlanksGetResponsePhotoSource, BlanksListResponseBlanksItemPhotoSource and ScenesListResponseScenesItemPrintCoverage enums and the scene and garment models. Constructors of several response models take the new fields.

### Features

* read the blank shape the API answers today ([#22](https://github.com/pixonaut-app/pixonaut-php/issues/22)) ([3c42478](https://github.com/pixonaut-app/pixonaut-php/commit/3c42478ff4ce181e0c68c1aeeafc89df1d396f42))

## 1.0.0 (2026-11-06)

Targets the Pixonaut API 1.0.0.

### Features

* **generator:** generate models, error codes and operations from the pinned spec ([#13](https://github.com/pixonaut-app/pixonaut-php/issues/13)) ([5d2d24c](https://github.com/pixonaut-app/pixonaut-php/commit/5d2d24ca6eedc457b637d192d63e315fca6e3392))
* **http:** add the PSR-18 client core ([#12](https://github.com/pixonaut-app/pixonaut-php/issues/12)) ([3e2738d](https://github.com/pixonaut-app/pixonaut-php/commit/3e2738de6d0a4499d00e44dd4447c526a392805b))
* quick-start, prefixing check and the 1.0.0 release ([#18](https://github.com/pixonaut-app/pixonaut-php/issues/18)) ([2a83e10](https://github.com/pixonaut-app/pixonaut-php/commit/2a83e10cc7904d9fc6d7dbcc9abea824fb886059))
* **resources:** generate the workspace administration resources ([#16](https://github.com/pixonaut-app/pixonaut-php/issues/16)) ([2c5e0bf](https://github.com/pixonaut-app/pixonaut-php/commit/2c5e0bf55e1ce89a7b3387e70703aa5ff98d6732))
* **resources:** typed resource methods for the store-facing operations ([#15](https://github.com/pixonaut-app/pixonaut-php/issues/15)) ([9663a6f](https://github.com/pixonaut-app/pixonaut-php/commit/9663a6f6572cdab01afe42f4eaf55bf0d7ac231f))
* **spec:** pin the API spec from the CDN by version and sha256 ([#11](https://github.com/pixonaut-app/pixonaut-php/issues/11)) ([b29d83e](https://github.com/pixonaut-app/pixonaut-php/commit/b29d83ea63f8f1d12b4ca91f0b5ecd6ea4750114))
* **webhooks:** verify delivery signatures and read typed events ([#14](https://github.com/pixonaut-app/pixonaut-php/issues/14)) ([7e9ea7d](https://github.com/pixonaut-app/pixonaut-php/commit/7e9ea7d9177df3c044c7abdd47ec6c4e737bf7ea))
