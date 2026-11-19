<?php
// Llegeix de la taula temporal d'Akeneo els SKUs dels productes nous (_is_new = 1) de tipus gestionats per MSI.
declare(strict_types=1);

namespace Nacento\Roadie\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Model\GetAllowedProductTypesForSourceItemManagementInterface;

class NewProductSkuProvider
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly GetAllowedProductTypesForSourceItemManagementInterface $allowedProductTypes
    ) {
    }

    /** @return string[] */
    public function getNewSkus(string $tmpTable): array
    {
        $connection = $this->resource->getConnection();
        if (!$connection->isTableExists($tmpTable)) {
            return [];
        }
        $types = $this->allowedProductTypes->execute();
        if (!$types) {
            return [];
        }
        $select = $connection->select()
            ->distinct()
            ->from(['t' => $tmpTable], [])
            ->join(
                ['p' => $this->resource->getTableName('catalog_product_entity')],
                'p.entity_id = t._entity_id',
                ['sku']
            )
            ->where('t._is_new = ?', 1)
            ->where('p.type_id IN (?)', $types);

        return array_map('strval', $connection->fetchCol($select));
    }
}
