<?php
// Auditoria de només lectura dels source items de MSI (nacento:roadie:soundcheck).
declare(strict_types=1);

namespace Nacento\Roadie\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Model\GetAllowedProductTypesForSourceItemManagementInterface;
use Nacento\Roadie\Model\Config;


class SoundcheckReport
{
    public const NO_SOURCE_ITEMS = 'no_source_items';
    public const MISSING_CONFIGURED_SOURCES = 'missing_configured_sources';
    public const UNCONFIGURED_SOURCES = 'unconfigured_sources';
    public const ORPHAN_SOURCE_ITEMS = 'orphan_source_items';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly GetAllowedProductTypesForSourceItemManagementInterface $allowedProductTypes,
        private readonly Config $config
    ) {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function run(): array
    {
        return [
            self::NO_SOURCE_ITEMS => array_map(static fn(string $sku): array => ['sku' => $sku], $this->getSkusWithoutSourceItems()),
            self::MISSING_CONFIGURED_SOURCES => $this->getMissingConfiguredSources(),
            self::UNCONFIGURED_SOURCES => array_map(
                static fn(string $code): array => ['source_code' => $code],
                $this->diffSources($this->getAllSourceCodes(), $this->config->getSourceCodes())
            ),
            self::ORPHAN_SOURCE_ITEMS => $this->getOrphanSourceItems(),
        ];
    }

    /** @return string[] */
    public function getSkusWithoutSourceItems(): array
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from(['p' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
            ->joinLeft(['si' => $this->resource->getTableName('inventory_source_item')], 'si.sku = p.sku', [])
            ->where('p.type_id IN (?)', $this->allowedProductTypes->execute() ?: [''])
            ->where('si.source_item_id IS NULL')
            ->order('p.sku');

        return array_map('strval', $c->fetchCol($select));
    }

    /**
     * @param string[] $all
     * @param string[] $configured
     * @return string[]
     */
    public function diffSources(array $all, array $configured): array
    {
        return array_values(array_diff($all, $configured));
    }

    /** @return list<array<string, mixed>> */
    private function getMissingConfiguredSources(): array
    {
        $configured = $this->config->getSourceCodes();
        if (!$configured) {
            return [];
        }
        $c = $this->resource->getConnection();

        $select = $c->select()
            ->from(['p' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
            ->join(['s' => $this->resource->getTableName('inventory_source')], $c->quoteInto('s.source_code IN (?)', $configured), ['source_code'])
            ->joinLeft(
                ['si' => $this->resource->getTableName('inventory_source_item')],
                'si.sku = p.sku AND si.source_code = s.source_code',
                []
            )
            ->where('p.type_id IN (?)', $this->allowedProductTypes->execute() ?: [''])
            ->where('si.source_item_id IS NULL')
            ->where('EXISTS (?)', new \Zend_Db_Expr(
                'SELECT 1 FROM ' . $this->resource->getTableName('inventory_source_item') . ' x WHERE x.sku = p.sku'
            ))
            ->order(['p.sku', 's.source_code']);

        return $c->fetchAll($select);
    }

    /** @return string[] */
    private function getAllSourceCodes(): array
    {
        $c = $this->resource->getConnection();

        return array_map('strval', $c->fetchCol(
            $c->select()->from($this->resource->getTableName('inventory_source'), ['source_code'])->order('source_code')
        ));
    }

    /** @return list<array<string, mixed>> */
    private function getOrphanSourceItems(): array
    {
        $c = $this->resource->getConnection();
        $select = $c->select()
            ->from(['si' => $this->resource->getTableName('inventory_source_item')], ['sku', 'source_code', 'quantity', 'status'])
            ->joinLeft(['p' => $this->resource->getTableName('catalog_product_entity')], 'p.sku = si.sku', [])
            ->where('p.entity_id IS NULL')
            ->order(['si.sku', 'si.source_code']);

        return $c->fetchAll($select);
    }
}
