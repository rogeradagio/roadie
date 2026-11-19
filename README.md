# Nacento Roadie (el pipa)

**English** · [Català](README.ca.md)

Creates MSI source items for products created by Akeneo Connector.

Akeneo Connector (105.x) creates products by direct SQL and only writes the legacy `cataloginventory_stock_item`, so new products end up with **no MSI source assigned**. Roadie hooks into the connector's `initStock` step and creates, for each **new** product, one source item per configured source (qty `0`, configurable initial status). It never touches updated products, never changes quantities and never deletes anything.

**Requirements:** Magento 2.4.x with MSI, Akeneo Connector Community ^105.1, PHP 8.1+.

## Installation

Composer (`path` or VCS repository):

```bash
composer require nacento/module-roadie
bin/magento module:enable Nacento_Roadie && bin/magento setup:upgrade
```

Or copy/mount this repository to `app/code/Nacento/Roadie`.

Installing it has no effect until it is configured (disabled, no sources by default).

## Configuration

`Stores → Configuration → Catalog → Akeneo Connector → MSI Sources (Nacento Roadie)` (Default scope only):

```bash
bin/magento config:set akeneo_connector/msi_sources/sources "default,erp" [--lock-env]
```

The source list is explicit: new Magento sources are not added automatically.
To sell a product with qty 0 the product or global configuration must also allow **backorders**; Roadie does not manage that.

Results are appended to the Akeneo job log (`MSI: X source items created, Y updated`) and written to `var/log/nacento_roadie.log`. Errors are logged and never fail the import.

## CLI

- `bin/magento nacento:roadie:soundcheck [--format=table|csv] [--output=file]`: read-only audit (products without source items, missing configured sources, unconfigured sources, orphan source items). Exits `1` if anything is found.
- `bin/magento nacento:roadie:setup [--sku=SKU ...] [--dry-run]`: backfill. Applies the same logic to the given SKUs, or to all products without any source item.

## Potential limitations

- **Read/write window:** between reading existing source items and saving, a concurrent write on the same SKU could be overwritten. For products created within the same import this is unlikely.
