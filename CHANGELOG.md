# Changelog

## [12.1.0](https://github.com/pixonaut-app/pixonaut-php/compare/v12.0.0...v12.1.0) (2026-11-14)


### Features

* target the published Pixonaut API 7.3.0 ([#50](https://github.com/pixonaut-app/pixonaut-php/issues/50)) ([3395273](https://github.com/pixonaut-app/pixonaut-php/commit/3395273870067cc5cb0b0de3cba16b3ccc5402c9))

## [12.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v11.0.0...v12.0.0) (2026-11-14)

Targets the Pixonaut API 7.2.0.

### ⚠ BREAKING CHANGES

* the constructors of the generated flat-sheet models gain the trailing optional $parts.

### Features

* target the published Pixonaut API 7.2.0 ([#48](https://github.com/pixonaut-app/pixonaut-php/issues/48)) ([03fd115](https://github.com/pixonaut-app/pixonaut-php/commit/03fd115fd61ff8535d13fd200777d4d88ce1a999))

## [11.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v10.0.0...v11.0.0) (2026-11-14)

Targets the Pixonaut API 7.1.0.

### ⚠ BREAKING CHANGES

* the constructors of BlanksVariantsListResponseVariantsItemFrame and PrintshopCatalogueProductsImportBlankRequest gain an optional argument before existing ones.

### Features

* target the published Pixonaut API 7.1.0 ([#46](https://github.com/pixonaut-app/pixonaut-php/issues/46)) ([38f95ce](https://github.com/pixonaut-app/pixonaut-php/commit/38f95ce7265d7627f6514e8eebc4f798ba215e7b))

## [10.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v9.0.0...v10.0.0) (2026-11-14)

Targets the Pixonaut API 6.3.0.

### ⚠ BREAKING CHANGES

* the constructors of the generated cylinder models (BlanksGetResponseProductModelsItemModelCylinder, BlanksListResponseBlanksItemProductModelsItemModelCylinder, PrintshopCatalogueProductsImportBlankResponse200BlankProductModelsItemModelCylinder, PrintshopCatalogueProductsImportBlankResponse202BlankProductModelsItemModelCylinder, ScenesListResponseStockItemsItemModelCylinder) gain the trailing optional $profile.

### Features

* target the published Pixonaut API 6.3.0 ([#44](https://github.com/pixonaut-app/pixonaut-php/issues/44)) ([9156a42](https://github.com/pixonaut-app/pixonaut-php/commit/9156a425ffe200ab6b5d7ed9382ed9415ebb00e8))

## [9.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v8.0.0...v9.0.0) (2026-11-14)

Targets the Pixonaut API 6.2.0.

### ⚠ BREAKING CHANGES

* the constructors of the generated models ScenesListResponseScenesItem (required $thumbnailUrl before $workspace), ScenesListResponseScenesItemOffersItem (required $camera before $rows, trailing optional $fitsKind), ScenesListResponse (trailing optional $makeCredits) and PrintshopCatalogueProductsImportBlankRequest (optional $scenes before $views) change, and Blanks::listScenes() gains the trailing optional $forKind, $forCut and $view.

### Features

* target the published Pixonaut API 6.2.0 ([#42](https://github.com/pixonaut-app/pixonaut-php/issues/42)) ([f558d82](https://github.com/pixonaut-app/pixonaut-php/commit/f558d829fe5f8a003510fa1b384c8f9d1f34e0ae))

## [8.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v7.0.0...v8.0.0) (2026-11-14)

Targets the Pixonaut API 6.1.0.

### ⚠ BREAKING CHANGES

* the constructors of the generated held-scene geometry models (BlanksGetResponseHeldScenesItemHeld and its list, import and scene counterparts) gain a trailing optional $parts argument.

### Features

* target the published Pixonaut API 6.1.0 ([#40](https://github.com/pixonaut-app/pixonaut-php/issues/40)) ([a576eb9](https://github.com/pixonaut-app/pixonaut-php/commit/a576eb98ae04b7892389105675739044c9881a02))

## [7.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v6.0.0...v7.0.0) (2026-11-13)

Targets the Pixonaut API 6.0.0.

### ⚠ BREAKING CHANGES

* generated model constructors gain arguments for the new fields, ScenesListResponseScenesItem::$stockItem is nullable, and a low-resolution image without a `label` no longer decodes.

### Features

* target the published Pixonaut API 6.0.0 ([#38](https://github.com/pixonaut-app/pixonaut-php/issues/38)) ([f1219e4](https://github.com/pixonaut-app/pixonaut-php/commit/f1219e450966c13ad1d0e58a889847338e4a7ca3))

## [6.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v5.0.0...v6.0.0) (2026-11-12)

Targets the Pixonaut API 5.0.0.

### ⚠ BREAKING CHANGES

* a template.renamed event read from events()->list() is a TemplateRenamedEvent, no longer an UnknownEvent.

### Features

* target the published Pixonaut API 5.0.0 ([#36](https://github.com/pixonaut-app/pixonaut-php/issues/36)) ([73f9acd](https://github.com/pixonaut-app/pixonaut-php/commit/73f9acd15d85b02a4397d8336fa7444a0ecdb5f2))

## [5.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v4.0.0...v5.0.0) (2026-11-11)

Targets the Pixonaut API 4.1.0.

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
