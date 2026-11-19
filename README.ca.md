# Nacento Roadie (el pipa)

[English](README.md) · **Català**

Crea els *source items* de MSI dels productes que crea l'Akeneo Connector.

L'Akeneo Connector (105.x) crea els productes per SQL directe i només escriu a la taula legacy `cataloginventory_stock_item`, de manera que els productes nous queden **sense cap source de MSI assignada**. Roadie s'enganxa al pas `initStock` del connector i, per cada producte **nou**, crea un source item per cada source configurada (qty `0` i estat inicial configurable). Mai toca els productes actualitzats, mai canvia quantitats i mai esborra res.

**Requisits:** Magento 2.4.x amb MSI, Akeneo Connector Community ^105.1, PHP 8.1+.

## Instal·lació

Amb Composer (repositori `path` o VCS):

```bash
composer require nacento/module-roadie
bin/magento module:enable Nacento_Roadie && bin/magento setup:upgrade
```

O bé copiant o muntant aquest repositori a `app/code/Nacento/Roadie`.

Instal·lar-lo no té cap efecte fins que es configura (per defecte ve desactivat i sense sources).

## Configuració

`Stores → Configuration → Catalog → Akeneo Connector → MSI Sources (Nacento Roadie)` (només a l'abast *Default*):

```bash
bin/magento config:set akeneo_connector/msi_sources/sources "default,erp" [--lock-env]
```

La llista de sources és explícita: les sources noves que es creïn a Magento no s'hi afegeixen soles.
Perquè un producte amb qty 0 es pugui vendre, el producte o la configuració global també han de permetre **backorders**. Roadie no ho gestiona.

El resultat s'afegeix al log del job d'Akeneo (`MSI: X source items created, Y updated`) i s'escriu a `var/log/nacento_roadie.log`. Els errors queden al log i mai fan fallar la importació.

## CLI

- `bin/magento nacento:roadie:soundcheck [--format=table|csv] [--output=fitxer]`: auditoria de només lectura (productes sense source items, productes als quals falta alguna source configurada, sources que no són a la configuració i source items orfes). Acaba amb codi `1` si troba alguna incidència.
- `bin/magento nacento:roadie:setup [--sku=SKU ...] [--dry-run]`: *backfill*. Aplica la mateixa lògica als SKUs indicats o a tots els productes que no tenen cap source item.

## Possibles limitacions

- **Finestra entre lectura i escriptura:** entre que es llegeixen els source items existents i es desen, una escriptura concurrent sobre el mateix SKU es podria trepitjar. En productes creats dins la mateixa importació és improbable.
