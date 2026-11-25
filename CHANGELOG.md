# Changelog

## [31.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v30.0.0...v31.0.0) (2026-11-25)

Targets the Pixonaut API 14.4.0.

### ⚠ BREAKING CHANGES

* BlanksVariantsCostsResponse's constructor takes the new required missing and read arguments.

### Features

* target the published Pixonaut API 14.4.0 ([#100](https://github.com/pixonaut-app/pixonaut-php/issues/100)) ([5a9148f](https://github.com/pixonaut-app/pixonaut-php/commit/5a9148f91a5dc9bc7b542a0bdae33407e236c61e))

## [30.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v29.0.0...v30.0.0) (2026-11-24)

Targets the Pixonaut API 14.3.0.

### ⚠ BREAKING CHANGES

* the optional marksFrame argument comes before maskAssetId (or maskUrl, or position) in the constructors of the six view models that gained it, so a positional call to any of them moves.

### Features

* target the published Pixonaut API 14.3.0 ([#98](https://github.com/pixonaut-app/pixonaut-php/issues/98)) ([89ecea3](https://github.com/pixonaut-app/pixonaut-php/commit/89ecea304ecfc05c48f56670be40fe6b705e2b46))

## [29.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v28.0.0...v29.0.0) (2026-11-24)

Targets the Pixonaut API 14.2.0.

### ⚠ BREAKING CHANGES

* the constructor of OrdersProgressGetResponse takes the new required items argument before parcels, and the optional pages argument of BlanksMockupsListResponsePlacementsItemAreasItem comes before shape, so a positional call to either moves.

### Features

* target the published Pixonaut API 14.2.0 ([#96](https://github.com/pixonaut-app/pixonaut-php/issues/96)) ([660036a](https://github.com/pixonaut-app/pixonaut-php/commit/660036aa79dabf63027a826d6636268536ad1267))

## [28.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v27.0.0...v28.0.0) (2026-11-24)

Targets the Pixonaut API 14.1.0.

### ⚠ BREAKING CHANGES

* the constructors of DesignsGetResponse, DesignsErasePersonalDataResponse and every *PrintshopOrdersItem answer model take the new required page, pageCount and cancelsItems arguments, and the optional pages argument of the blank print-area models (*PlacementsItemAreasItem) comes before safeAreaMm and shape, so a positional call to them moves.

### Features

* target the published Pixonaut API 14.1.0 ([#94](https://github.com/pixonaut-app/pixonaut-php/issues/94)) ([061796a](https://github.com/pixonaut-app/pixonaut-php/commit/061796aee179bb436200c5b0a8bf266bcaf2d007))

## [27.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v26.0.0...v27.0.0) (2026-11-23)

Targets the Pixonaut API 14.0.0.

### ⚠ BREAKING CHANGES

* Orders::createBatch(), update(), retry(), listRerouteTargets(), getJob(), updateJob(), listJobFiles(), getJobFile() and bundleJobFiles() are removed, along with their request and response models. OrdersCreateRequest takes a required items list in place of prints. The order.failed, order.rerouted, order.submitted, order.tracking_added and order.tracking_changed events and their classes are removed, and order statuses are the new order status set.

### Features

* target the published Pixonaut API 14.0.0 ([#92](https://github.com/pixonaut-app/pixonaut-php/issues/92)) ([3bc76f9](https://github.com/pixonaut-app/pixonaut-php/commit/3bc76f9ca3879d82e13d28e68bef2891c2aff55e))

## [26.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v25.0.0...v26.0.0) (2026-11-20)

Targets the Pixonaut API 13.1.0.

### ⚠ BREAKING CHANGES

* the generated DesignsCommitResponse and DesignsApproveResponse constructors take a new smallerThanArea argument.

### Features

* target the published Pixonaut API 13.1.0 ([#88](https://github.com/pixonaut-app/pixonaut-php/issues/88)) ([2361183](https://github.com/pixonaut-app/pixonaut-php/commit/23611836e39985f00d35f79c65a79874749e67e1))

## [25.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v24.1.0...v25.0.0) (2026-11-20)

Targets the Pixonaut API 13.0.0.

### ⚠ BREAKING CHANGES

* an order's answer requires `orderedWith` and `storeOrderWait`, and a cylinder model's parts gain an `inside` arm, which changes generated constructors.

### Features

* target the published Pixonaut API 13.0.0 ([#86](https://github.com/pixonaut-app/pixonaut-php/issues/86)) ([1c826b9](https://github.com/pixonaut-app/pixonaut-php/commit/1c826b9e93993890fb98832bb085c5ab8030cd53))

## [24.1.0](https://github.com/pixonaut-app/pixonaut-php/compare/v24.0.0...v24.1.0) (2026-11-19)

Targets the Pixonaut API 12.1.1.

### Features

* target the published Pixonaut API 12.1.1 ([#84](https://github.com/pixonaut-app/pixonaut-php/issues/84)) ([0b35b97](https://github.com/pixonaut-app/pixonaut-php/commit/0b35b97ce4b10dba4a413840f77166bb17ebb95f))

## [24.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v23.0.0...v24.0.0) (2026-11-19)

Targets the Pixonaut API 12.0.0.

### ⚠ BREAKING CHANGES

* target the published Pixonaut API 12.0.0 ([#82](https://github.com/pixonaut-app/pixonaut-php/issues/82))

### Features

* target the published Pixonaut API 12.0.0 ([#82](https://github.com/pixonaut-app/pixonaut-php/issues/82)) ([245cd1e](https://github.com/pixonaut-app/pixonaut-php/commit/245cd1ee694a0571f294dc41506d0a9c62664b43))

## [23.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v22.0.0...v23.0.0) (2026-11-17)

Targets the Pixonaut API 10.6.0.

### ⚠ BREAKING CHANGES

* target the published Pixonaut API 10.6.0 ([#80](https://github.com/pixonaut-app/pixonaut-php/issues/80))

### Features

* target the published Pixonaut API 10.6.0 ([#80](https://github.com/pixonaut-app/pixonaut-php/issues/80)) ([b6d48dc](https://github.com/pixonaut-app/pixonaut-php/commit/b6d48dcbce54e863cfa20cf5acf8051d9be06462))

## [22.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v21.0.0...v22.0.0) (2026-11-17)

Targets the Pixonaut API 10.5.0.

### ⚠ BREAKING CHANGES

* the conversation card models' constructors take a trailing optional storage argument.

### Features

* target the published Pixonaut API 10.5.0 ([#78](https://github.com/pixonaut-app/pixonaut-php/issues/78)) ([1ae94cf](https://github.com/pixonaut-app/pixonaut-php/commit/1ae94cfa278c15ff29f4c50db7a3847e4e268442))

## [21.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v20.3.0...v21.0.0) (2026-11-17)

Targets the Pixonaut API 10.4.1.

### ⚠ BREAKING CHANGES

* StorageGetResponse's constructor takes the required stores list.

### Features

* target the published Pixonaut API 10.4.1 ([#76](https://github.com/pixonaut-app/pixonaut-php/issues/76)) ([5829f91](https://github.com/pixonaut-app/pixonaut-php/commit/5829f91c7a3819182e5ec739260fd7e262cbfee5))

## [20.3.0](https://github.com/pixonaut-app/pixonaut-php/compare/v20.2.0...v20.3.0) (2026-11-16)

Targets the Pixonaut API 10.3.0.

### Features

* target the published Pixonaut API 10.3.0 ([#74](https://github.com/pixonaut-app/pixonaut-php/issues/74)) ([73e5906](https://github.com/pixonaut-app/pixonaut-php/commit/73e5906aee9b849441771f3adad350b003425c92))

## [20.2.0](https://github.com/pixonaut-app/pixonaut-php/compare/v20.1.0...v20.2.0) (2026-11-16)

Targets the Pixonaut API 10.2.0.

### Features

* target the published Pixonaut API 10.2.0 ([#72](https://github.com/pixonaut-app/pixonaut-php/issues/72)) ([d3ca1eb](https://github.com/pixonaut-app/pixonaut-php/commit/d3ca1eb4ccef2ca1dbb028160df0167b493f2078))

## [20.1.0](https://github.com/pixonaut-app/pixonaut-php/compare/v20.0.0...v20.1.0) (2026-11-16)

Targets the Pixonaut API 10.1.0.

### Features

* target the published Pixonaut API 10.1.0 ([#70](https://github.com/pixonaut-app/pixonaut-php/issues/70)) ([572d366](https://github.com/pixonaut-app/pixonaut-php/commit/572d366a3413289ee4e674df0b7332f60286c6c2))

## [20.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v19.0.0...v20.0.0) (2026-11-16)

Targets the Pixonaut API 10.0.0.

### ⚠ BREAKING CHANGES

* the constructors of the bound product models (BlanksGetResponseBoundProduct, BlanksListResponseBlanksItemBoundProduct and the two PrintshopCatalogueProductsImportBlankResponse…BlankBoundProduct models), of BlanksVariantsCostsResponse and BlanksVariantsCostsResponseCostsItem, and of the two PrintshopConnectionsListResponseOption…ConnectionsItem models gain arguments for the new fields. Code that builds these models itself must pass them; code that only reads answers is unchanged.

### Features

* target the published Pixonaut API 10.0.0 ([#68](https://github.com/pixonaut-app/pixonaut-php/issues/68)) ([9612e57](https://github.com/pixonaut-app/pixonaut-php/commit/9612e5737279ca3e2d9aa9e809e3a699a95fed8e))

## [19.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v18.0.0...v19.0.0) (2026-11-15)

Targets the Pixonaut API 9.1.0.

### ⚠ BREAKING CHANGES

* the constructors of the three Pixel conversation models ending in ToolResultContentOption2ItemPictureOfTemplateMockup (under PixelApplicationsCreateResponse, PixelConversationGetResponse and PixelDismissalsCreateResponse) gain the optional argument $variant before $viewId. Code that names its arguments is unchanged; code that passes $viewId by position must be checked.

### Features

* target the published Pixonaut API 9.1.0 ([#66](https://github.com/pixonaut-app/pixonaut-php/issues/66)) ([a894902](https://github.com/pixonaut-app/pixonaut-php/commit/a894902e5783643ad1117dbbfe1daa0e43142669))

## [18.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v17.0.0...v18.0.0) (2026-11-15)

Targets the Pixonaut API 9.0.0.

### ⚠ BREAKING CHANGES

* the constructors of TemplatesPrintSizeValidateRequestOption1 and TemplatesPrintSizeValidateRequestOption2 gain the optional last arguments $variant and $variantRow, ScenesListResponseScenesItemOffersItem gains $standIn, and the blank models (BlanksGetResponse, BlanksListResponseBlanksItem, PrintshopCatalogueProductsImportBlankResponse200Blank, PrintshopCatalogueProductsImportBlankResponse202Blank) and their OptionsItem models gain the optional $bakesJob and $newValues, which are not last on the blank models. Code that names its arguments is unchanged; code that passes a blank model's optional arguments by position must be checked.

### Features

* target the published Pixonaut API 9.0.0 ([#64](https://github.com/pixonaut-app/pixonaut-php/issues/64)) ([d4ccb0a](https://github.com/pixonaut-app/pixonaut-php/commit/d4ccb0a8f4de9c81d827ae1f719fb708b6e99a4c))

## [17.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v16.0.0...v17.0.0) (2026-11-15)

Targets the Pixonaut API 8.4.0.

### ⚠ BREAKING CHANGES

* the constructor of PrintshopCatalogueProductsImportBlankRequestScenesItem gains an optional last argument, $row. Code that names its arguments, or passes the four before it by position, is unchanged.

### Features

* target the published Pixonaut API 8.4.0 ([#62](https://github.com/pixonaut-app/pixonaut-php/issues/62)) ([c4552ea](https://github.com/pixonaut-app/pixonaut-php/commit/c4552eaad9ff37a0182da15298a07bde49d1dc98))

## [16.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v15.0.0...v16.0.0) (2026-11-14)

Targets the Pixonaut API 8.3.0.

### ⚠ BREAKING CHANGES

* the constructors of BlanksMockupsListResponsePlacementsItem and BlanksMockupsListResponsePlacementsItemAreasItem gain an optional argument, $foldMm, before their later optional ones, and that of ScenesListResponseScenesItemGeometryAnchorsItem an optional last one, $bareAboveMm. Code that names its arguments is unchanged.

### Features

* target the published Pixonaut API 8.3.0 ([#60](https://github.com/pixonaut-app/pixonaut-php/issues/60)) ([f12d280](https://github.com/pixonaut-app/pixonaut-php/commit/f12d2804ee803d1298945b0e842f83955e03a1bd))

## [15.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v14.0.0...v15.0.0) (2026-11-14)

Targets the Pixonaut API 8.2.0.

### ⚠ BREAKING CHANGES

* the constructors of the four product model row classes (BlanksGetResponseProductModelsItem and its list and import twins) gain an optional last argument, $fromPrintshop.

### Features

* target the published Pixonaut API 8.2.0 ([#58](https://github.com/pixonaut-app/pixonaut-php/issues/58)) ([e94c1b0](https://github.com/pixonaut-app/pixonaut-php/commit/e94c1b09421679aaeea73994c56e6295a1e1a5b3))

## [14.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v13.0.0...v14.0.0) (2026-11-14)

Targets the Pixonaut API 8.1.0.

### ⚠ BREAKING CHANGES

* the constructor of PrintshopCatalogueProductsListResponseProductsItem gains the required $productKind.

### Features

* a product model of a kind added later reads as an UnknownVariant ([#55](https://github.com/pixonaut-app/pixonaut-php/issues/55)) ([462e1e1](https://github.com/pixonaut-app/pixonaut-php/commit/462e1e1934ac88a17b6bd503744e70e2184c7475))
* target the published Pixonaut API 8.1.0 ([#56](https://github.com/pixonaut-app/pixonaut-php/issues/56)) ([1f97a9c](https://github.com/pixonaut-app/pixonaut-php/commit/1f97a9c6c09da6218ea463fddc946f7e7e3340f5))

## [13.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v12.1.0...v13.0.0) (2026-11-14)

Targets the Pixonaut API 8.0.0.

### ⚠ BREAKING CHANGES

* a product model's decoder returns one more class, the canvas's, wherever a blank's or a stock item's model is read.

### Features

* target the published Pixonaut API 8.0.0 ([#53](https://github.com/pixonaut-app/pixonaut-php/issues/53)) ([38acf23](https://github.com/pixonaut-app/pixonaut-php/commit/38acf23d66164187165d06db302f3be7821a1d5a))

## [12.1.0](https://github.com/pixonaut-app/pixonaut-php/compare/v12.0.0...v12.1.0) (2026-11-14)

Targets the Pixonaut API 7.3.0.

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
