# Especificació: Nacento Roadie — extensió MSI per a l'Akeneo Connector

**Mòdul:** `Nacento_Roadie` · **Namespace PHP:** `Nacento\Roadie` · **Paquet Composer:** `nacento/module-roadie`
**Descripció curta** (per a `composer.json` i el README): *Creates MSI source items for products created by Akeneo Connector.*
**Requisits:** Magento Open Source / Adobe Commerce 2.4.x amb MSI · Akeneo Connector Community 105.x (verificat amb 105.1.2)
**Versió del document:** 3 · **Data:** 2026-11-19

---

## 1. Propòsit

Quan l'Akeneo Connector **crea** un producte, no en crea cap *source item* de MSI. El pas `initStock` només escriu a la taula legacy `cataloginventory_stock_item`. Com que el connector importa per SQL directe, tampoc s'executa cap dels mecanismes de Magento que ho fan en altres vies d'alta.

Resultat: els productes nous queden **sense cap source assignada**. No es poden vendre i qualsevol sistema extern que intenti escriure'n l'stock per source es troba el producte sense assignar.

Aquest mòdul **completa el connector**. Quan Akeneo crea un producte nou, li crea els source items de les sources configurades, amb l'estat inicial configurat.

### Principis

- **Missió única.** Només actua sobre productes que Akeneo **crea**. No toca els productes que Akeneo actualitza ni els que es creen per altres vies.
- **No gestiona stock.** Crea els source items amb **quantitat 0** i un estat inicial. A partir d'aquí, la quantitat i l'estat són de qui gestioni l'stock (ERP, WMS, a mà…). El mòdul no en sap res, i no hi ha de saber res.
- **Cap decisió de negoci al codi.** Quines sources es creen i quines queden en In Stock es configura a l'admin.
- **Es comporta com una part més del connector.** La configuració va dins la secció de l'Akeneo Connector, i els missatges surten al log del job d'Akeneo.

## 2. Abast

**Dins de l'abast**
- **F1:** crear els source items dels productes **nous** importats per l'Akeneo Connector.
- **F2:** configuració a l'admin.
- **F3 (opcional):** comandes CLI d'auditoria i de *backfill*.

**Fora de l'abast**
- Productes creats a mà a l'admin, per API REST, per importació CSV o per qualsevol altra via.
- Productes que l'Akeneo Connector actualitza.
- Quantitats, reserves, advanced inventory (backorders, llindars…) i configuració de stocks o canals de venda.
- Esborrar source items.
- Productes sense source items propis (configurables, bundles, grouped). Els decideix MSI.

## 3. Com funciona el connector (verificat a la v105.1.2)

### 3.1 Passos del job `product`

Definits a `etc/di.xml`, `Akeneo\Connector\Job\Product`. El `JobExecutor` hi afegeix `beforeImport` al principi i `afterImport` al final, i **tot el cicle es repeteix per cada família**:

```
createTable → insertData → … → matchEntities → … → createEntities → importFiles → setValues
→ linkConfigurable → linkSimple → setWebsites → setCategories → initStock → setRelated
→ setGrouped → setUrlRewrite → importMedia → dropTable → refreshIndex → cleanCache
```

- `initStock` (`Job/Product.php`) fa un `INSERT IGNORE` a `cataloginventory_stock_item` (`stock_id = 1`, qty 0, Out of Stock). Al connector **no hi ha cap referència a MSI**.
- `dropTable` esborra la taula temporal, excepte quan el *advanced log* està activat.
- `refreshIndex` i `cleanCache` només s'executen a l'última família.

### 3.2 Events de cada pas

`Executor/JobExecutor.php`, `executeStep()`:

```php
$this->eventManager->dispatch('akeneo_connector_import_step_start_product', ['import' => $this]);
try { $this->currentJobClass->{$method}(); }
catch (Exception $e) { $this->afterRun(true); … }          // posa el job en JOB_ERROR
$this->eventManager->dispatch('akeneo_connector_import_step_finish_product', ['import' => $this]);
$this->nextStep();
```

- L'event es llança **abans de `nextStep()`**, i per tant `getMethod()` retorna el pas que s'acaba d'executar.
- L'event es llança **també quan el pas ha fallat**. Cal comprovar l'estat del job.

### 3.3 Com identifica els productes nous

`Helper/Import/Product.php`, `matchEntity()`:
- Enllaça a `akeneo_connector_entities` els productes que ja existeixen a Magento amb el mateix SKU.
- Només els que queden sense `entity_id` reben un id nou i **`_is_new = 1`** a la taula temporal.

Per tant, `_is_new = 1` vol dir "producte que no existia a Magento".

La taula temporal s'obté amb `Akeneo\Connector\Helper\Import\Entities::getTableName('product')` (→ `tmp_akeneo_connector_entities_product`). Les columnes que fa servir el mòdul són `_entity_id` i `_is_new`.

### 3.4 Punt d'enganx escollit

| Tècnica | Decisió |
|---|---|
| **Observer de `akeneo_connector_import_step_finish_product`, filtrat per `initStock`** | ✅ **Escollida.** És el punt d'extensió que ofereix el connector. Arriba una vegada per família, quan la taula temporal i `_is_new` encara hi són i els productes ja existeixen a `catalog_product_entity`. |
| Plugin `afterInitStock` sobre `Akeneo\Connector\Job\Product` | Descartada. Depèn d'una classe interna que no és `@api`. Es pot fer servir si l'event desaparegués. |
| Pas propi afegit a `steps` via `di.xml` | Descartada. La fusió d'arrays el posa al final, i obliga a fer una *preference* sobre `Job\Product`. |
| Observer de `akeneo_connector_import_finish_product` | Descartada. La taula temporal ja s'ha esborrat i no es pot saber quins productes són nous. |

## 4. Requisits funcionals

### F1. Crear els source items dels productes nous

**Disparador:** `akeneo_connector_import_step_finish_product`, a l'àrea **global** (la importació pot córrer per cron, per CLI o des de l'admin).

**Condicions**, totes necessàries:
1. el mòdul està activat i hi ha almenys una source configurada;
2. `$executor->getMethod() === 'initStock'`;
3. `$executor->getCurrentJob()->getStatus() !== JobInterface::JOB_ERROR`.

**Lògica:**
1. **Llegir els SKUs nous** de la família. És l'**única consulta SQL** del mòdul, i només de lectura:
   `tmp_akeneo_connector_entities_product t` JOIN `catalog_product_entity p ON p.entity_id = t._entity_id`
   `WHERE t._is_new = 1 AND p.type_id IN (<tipus permesos per MSI>)` → retorna `p.sku`.
   - Els tipus surten de `GetAllowedProductTypesForSourceItemManagementInterface`.
   - El SKU es llegeix de `catalog_product_entity`, i per això funciona igual amb Akeneo per identificador o per UUID.
2. Si no n'hi ha cap, **acabar**. És el cas habitual quan una família només porta actualitzacions.
3. **Per blocs** de N SKUs:
   1. Llegir els source items que ja existeixen per a aquests SKUs amb `SourceItemRepositoryInterface::getList()` i el filtre `sku IN (...)`.
   2. Per cada SKU i cada source configurada:
      - **Si no existeix:** crear-lo amb `quantity = 0` i `status = 1` si la source és a "Sources en In Stock", o `0` en cas contrari.
      - **Si existeix** (source items orfes d'un SKU esborrat i tornat a crear), la source és a "Sources en In Stock", està en Out of Stock i "Aplicar l'estat inicial als source items existents" està activat: posar `status = 1` i **mantenir la quantitat que ja tenia**.
      - **Si existeix en qualsevol altre cas:** no tocar-lo.
   3. Desar amb **`SourceItemsSaveInterface::execute($items)`**. Magento valida, sincronitza la taula legacy de la source per defecte i reindexa.
4. Afegir el resultat al log del job: `$executor->setMessage(__('MSI: %1 source items created, %2 updated', …))`.

**Gestió d'errors:**
- Tot el procés va dins un `try/catch (\Throwable)`. L'error s'escriu al log del mòdul i **mai trenca ni marca com a errònia la importació**.
- Un error en un bloc no impedeix processar els blocs següents.
- Si una source configurada ja no existeix, se salta i queda un *warning* al log.

### F2. Configuració

**On:** `Stores → Configuration → Catalog → Akeneo Connector → MSI Sources`. És un grup nou (`msi_sources`) dins la secció `akeneo_connector`, just després del grup **Products** (`sortOrder` 55).
**Abast:** només *Default*, perquè els source items són globals.
**Ruta de configuració:** `akeneo_connector/msi_sources/*`.

| Camp | Clau | Tipus | Valor per defecte |
|---|---|---|---|
| Activat | `enabled` | Sí/No | **No** |
| Sources a assignar als productes nous | `sources` | Multiselect de les sources existents | *(cap)* |
| Sources en In Stock | `in_stock_sources` | Multiselect de les sources existents | *(cap)* |
| Aplicar l'estat inicial als source items existents | `apply_status_to_existing` | Sí/No | No |

- Ve **desactivat i sense sources**. Fins que algú el configura, no fa res, i instal·lar-lo no té cap efecte.
- **Validació en desar** (*backend model*): `in_stock_sources` ha d'estar inclòs a `sources`, i `sources` no pot quedar buit si el mòdul està activat.
- La llista de sources és **explícita**. Una source nova que es creï a Magento no s'afegeix sola: decidir-ho és feina de qui configura la botiga.
- Es pot fixar per CLI, perquè els entorns siguin reproduïbles:
  `bin/magento config:set akeneo_connector/msi_sources/sources "codi1,codi2"` (opcionalment amb `--lock-env`).
- **Comentari d'ajuda al camp "Sources en In Stock":** perquè un producte amb qty 0 es pugui vendre, el producte o la configuració global també han de permetre backorders. El mòdul no ho gestiona.

**Es queda al codi:**

| Element | On | Motiu |
|---|---|---|
| Job `product` i pas `initStock` | Constants de l'observer | Depenen del funcionament intern del connector |
| Tipus de producte | MSI (`GetAllowedProductTypesForSourceItemManagementInterface`) | Ho decideix Magento |
| Quantitat inicial = 0 | Constant del model | El mòdul no gestiona stock |
| Mida del bloc (per defecte 25 SKUs) | Argument a `etc/di.xml` | Ajust tècnic |

### F3. Comandes CLI (opcional)

- **`bin/magento nacento:roadie:soundcheck [--format=table|csv] [--output=fitxer]`** (només lectura). Informa de:
  - productes amb source items permesos però **sense cap** source item;
  - productes als quals **falta** alguna de les sources configurades;
  - sources que existeixen però no són a la configuració;
  - source items **orfes**, de SKUs que no existeixen.

  Acaba amb codi de sortida ≠ 0 si hi ha incidències, per poder-la posar al cron amb una alerta.
- **`bin/magento nacento:roadie:setup [--sku=…] [--dry-run]`**. Aplica la lògica de F1 a productes que ja existeixen, ja sigui un llistat de SKUs o tots els que no tenen cap source item. Serveix per posar al dia una instal·lació que ja tenia productes abans del mòdul. Fa servir el mateix servei que F1.

## 5. Requisits no funcionals

- **Rendiment.** El cost depèn del nombre de productes **nous**. Una família només amb actualitzacions costa un `SELECT` de mil·lisegons sobre la taula temporal i cap crida al servei.
- **Idempotència.** Executar F1 dues vegades sobre la mateixa taula temporal no crea res la segona vegada.
- **No destructiu.** Mai canvia quantitats ni esborra res.
- **Aïllament.** Una fallada del mòdul no afecta mai el resultat de la importació d'Akeneo.
- **Reindex.** El fa `SourceItemsSaveInterface`. **No cal** afegir `inventory` als índexs de *Index to refresh* del connector.
- **Log.** Fitxer propi `var/log/nacento_roadie.log`, amb família, productes nous, source items creats i actualitzats, i errors.
- **Compatibilitat.** PHP 8.1+, Magento 2.4.x amb els mòduls MSI activats, Akeneo Connector 105.x.

## 6. Bones pràctiques

1. **Escriure només amb contractes de servei** (`SourceItemsSaveInterface`, `SourceItemRepositoryInterface`, `SourceItemInterfaceFactory`). Cap escriptura SQL a taules del nucli.
2. **Observer prim.** Filtra i delega a `Model\AssignSourcesToNewProducts`.
3. **Injecció per constructor**, `declare(strict_types=1)`, `readonly` i cap `ObjectManager`.
4. **Configuració de negoci a `system.xml` i `config.xml`, la tècnica a `di.xml`.**
5. **Dependències declarades:**
   - `module.xml`: `<sequence>` amb `Akeneo_Connector`, `Magento_InventoryApi` i `Magento_InventoryConfigurationApi`. És imprescindible també perquè el grup de configuració es fusioni dins la secció del connector.
   - `composer.json`: `akeneo/module-magento2-connector-community: ^105.1`, `magento/module-inventory-api`, `magento/module-inventory-configuration-api`.
6. **Textos en anglès i traduïbles** (`i18n/*.csv`).
7. **Tests:** unitaris del model, i d'integració amb una taula temporal simulada (secció 8).

## 7. Disseny tècnic

```
app/code/Nacento/Roadie/
├── registration.php
├── composer.json
├── etc/module.xml
├── etc/events.xml                          akeneo_connector_import_step_finish_product
├── etc/config.xml                          valors per defecte (desactivat)
├── etc/di.xml                              batchSize, logger propi, comandes CLI
├── etc/adminhtml/system.xml                grup msi_sources dins akeneo_connector
├── i18n/en_US.csv
├── Model/Config.php
├── Model/AssignSourcesToNewProducts.php    lògica F1
├── Model/Config/Source/Sources.php         opcions del multiselect
├── Model/Config/Backend/InStockSources.php validació in_stock ⊆ sources
├── Observer/AssignSourcesAfterInitStock.php
└── Console/Command/{Soundcheck,Setup}Command.php   (si s'inclou F3)
```

**Observer** (orientatiu):

```php
public function execute(Observer $observer): void
{
    if (!$this->config->isEnabled() || !$this->config->getSourceCodes()) {
        return;
    }
    $executor = $observer->getEvent()->getData('import');
    if (!$executor instanceof JobExecutor
        || $executor->getMethod() !== self::STEP_INIT_STOCK
        || $executor->getCurrentJob()->getStatus() === JobInterface::JOB_ERROR
    ) {
        return;
    }
    try {
        $tmpTable = $this->entitiesHelper->getTableName($executor->getCurrentJob()->getCode());
        $result   = $this->assignSources->execute($tmpTable);
        $executor->setMessage(__('MSI: %1 source items created, %2 updated', $result->created, $result->updated));
    } catch (\Throwable $e) {
        $this->logger->error('[Roadie] ' . $e->getMessage(), ['exception' => $e]);
    }
}
```

## 8. Criteris d'acceptació

1. **Producte nou:** després d'importar-lo des d'Akeneo, un producte simple nou té un source item per cada source configurada, tots amb qty 0, amb `status = 1` a les sources In Stock i `0` a la resta.
2. **Reindex:** sense cap reindex manual, l'estat queda reflectit a l'índex. Si la configuració de la botiga ho permet, `GET /V1/inventory/is-product-salable/{sku}/{stockId}` retorna `true`.
3. **Actualitzacions intactes:** en una importació que només actualitza productes, cap source item canvia i el log diu 0 creats.
4. **Rendiment:** una importació de milers de productes actualitzats i cap de nou no augmenta de manera apreciable la durada del job.
5. **Source items que ja existeixen:**
   - amb `apply_status_to_existing = 0` no es toquen;
   - amb `1`, una source In Stock que era Out of Stock passa a In Stock i la seva qty no canvia.
6. **Configuració:**
   - recent instal·lat, el mòdul no fa res;
   - desactivat, tampoc;
   - un canvi a la llista de sources s'aplica a la importació següent;
   - si s'intenta desar una source In Stock que no és a la llista, es rebutja.
7. **Errors:** si se simula una excepció al servei, la importació acaba i el job no queda en error per culpa del mòdul. L'error surt al log.
8. **Job en error:** si un pas anterior a `initStock` falla, el mòdul no fa res.
9. **Tipus:** els configurables i els bundles no reben cap source item. Els seus fills simples nous, sí.
10. **UUID:** funciona igual amb un PIM configurat per identificador o per UUID.

## 9. Punts a verificar durant el desenvolupament

- **Producte esborrat a Magento i recreat per Akeneo.** No s'ha comprovat si el connector neteja `akeneo_connector_entities` quan s'esborra un producte. Si no ho fa, el producte recreat podria sortir amb `_is_new = 0` i el mòdul no actuaria. Cal provar-ho i documentar el comportament.
- **Finestra entre lectura i escriptura.** Entre `getList()` i `SourceItemsSaveInterface::execute()`, una altra escriptura sobre el mateix SKU es podria trepitjar. En productes que s'acaben de crear dins la mateixa importació és improbable, però cal documentar-ho al README.
- **Mode single-source** (només existeix la source `default`). Cal comprovar que configurar `default` com a única source funciona i no entra en conflicte amb la sincronització legacy de MSI.

## 10. Decisions obertes

| # | Pregunta | Proposta |
|---|---|---|
| D1 | S'inclouen les comandes de F3? | Sí, almenys l'auditoria: és la manera de detectar si el mòdul deixa de funcionar. |
| D2 | Es publica com a mòdul reutilitzable (Composer, repositori propi)? | Es pot decidir més endavant. El disseny ja és agnòstic. |
