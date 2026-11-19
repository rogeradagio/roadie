<?php
// Crea els source items de les sources configurades per a uns SKUs (qty 0 i estat inicial), per blocs i només amb contractes de servei de MSI.
declare(strict_types=1);

namespace Nacento\Roadie\Model;

use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;


class SourceItemAssigner
{
    public const INITIAL_QUANTITY = 0.0;

    public function __construct(
        private readonly Config $config,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly SourceItemRepositoryInterface $sourceItemRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 25
    ) {
    }

    /**
     * @param string[] $skus
     */
    public function assign(array $skus, bool $dryRun = false): AssignResult
    {
        $skus = array_values(array_unique(array_map('strval', $skus)));
        if (!$skus) {
            return new AssignResult();
        }
        $sourceCodes = $this->getExistingConfiguredSources();
        if (!$sourceCodes) {
            return new AssignResult();
        }
        $inStock = array_flip($this->config->getInStockSourceCodes());
        $applyToExisting = $this->config->isApplyStatusToExisting();

        $created = 0;
        $updated = 0;
        foreach (array_chunk($skus, max(1, $this->batchSize)) as $batch) {
            try {
                $existing = $this->getExistingItems($batch);
                $toSave = [];
                $batchCreated = 0;
                $batchUpdated = 0;
                foreach ($batch as $sku) {
                    foreach ($sourceCodes as $sourceCode) {
                        $item = $existing[$sku . '|' . $sourceCode] ?? null;
                        $isInStockSource = isset($inStock[$sourceCode]);
                        if ($item === null) {
                            $item = $this->sourceItemFactory->create();
                            $item->setSku($sku);
                            $item->setSourceCode($sourceCode);
                            $item->setQuantity(self::INITIAL_QUANTITY);
                            $item->setStatus($isInStockSource
                                ? SourceItemInterface::STATUS_IN_STOCK
                                : SourceItemInterface::STATUS_OUT_OF_STOCK);
                            $toSave[] = $item;
                            $batchCreated++;
                        } elseif ($isInStockSource
                            && $applyToExisting
                            && (int)$item->getStatus() === SourceItemInterface::STATUS_OUT_OF_STOCK
                        ) {
                            $item->setStatus(SourceItemInterface::STATUS_IN_STOCK);
                            $toSave[] = $item;
                            $batchUpdated++;
                        }
                    }
                }
                if ($toSave && !$dryRun) {
                    $this->sourceItemsSave->execute($toSave);
                }
                $created += $batchCreated;
                $updated += $batchUpdated;
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[Roadie] Failed to save source items for SKUs ' . implode(', ', $batch) . ': ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        return new AssignResult($created, $updated);
    }

    /** @return string[] */
    private function getExistingConfiguredSources(): array
    {
        $configured = $this->config->getSourceCodes();
        if (!$configured) {
            return [];
        }
        $criteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter(SourceInterface::SOURCE_CODE, $configured, 'in')
            ->create();
        $existing = [];
        foreach ($this->sourceRepository->getList($criteria)->getItems() as $source) {
            $existing[$source->getSourceCode()] = true;
        }
        $missing = array_diff($configured, array_keys($existing));
        if ($missing) {
            $this->logger->warning('[Roadie] Configured sources not found, skipped: ' . implode(', ', $missing));
        }

        return array_values(array_filter($configured, static fn(string $c): bool => isset($existing[$c])));
    }

    /**
     * @param string[] $skus
     * @return array<string, SourceItemInterface> keyed by "sku|source_code"
     */
    private function getExistingItems(array $skus): array
    {
        $criteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter(SourceItemInterface::SKU, $skus, 'in')
            ->create();
        $items = [];
        foreach ($this->sourceItemRepository->getList($criteria)->getItems() as $item) {
            $items[$item->getSku() . '|' . $item->getSourceCode()] = $item;
        }

        return $items;
    }
}
