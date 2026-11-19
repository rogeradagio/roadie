<?php
// Opcions dels multiselect de l'admin: totes les sources de MSI existents.
declare(strict_types=1);

namespace Nacento\Roadie\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

class Sources implements OptionSourceInterface
{
    public function __construct(private readonly SourceRepositoryInterface $sourceRepository)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->sourceRepository->getList()->getItems() as $source) {
            $options[] = [
                'value' => $source->getSourceCode(),
                'label' => sprintf('%s (%s)', $source->getName(), $source->getSourceCode()),
            ];
        }

        return $options;
    }
}
