<?php
// Lògica de F1: obté els SKUs nous de la família i els passa a l'assignador.
declare(strict_types=1);

namespace Nacento\Roadie\Model;

use Psr\Log\LoggerInterface;

class AssignSourcesToNewProducts
{
    public function __construct(
        private readonly NewProductSkuProvider $skuProvider,
        private readonly SourceItemAssigner $assigner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $tmpTable, ?string $family = null): AssignResult
    {
        $skus = $this->skuProvider->getNewSkus($tmpTable);
        if (!$skus) {
            return new AssignResult();
        }
        $result = $this->assigner->assign($skus);
        $this->logger->info(sprintf(
            '[Roadie] Family %s: %d new products, %d source items created, %d updated',
            $family ?? '-',
            count($skus),
            $result->created,
            $result->updated
        ));

        return $result;
    }
}
