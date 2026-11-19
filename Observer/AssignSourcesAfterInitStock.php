<?php
// Observer del final de cada pas del job product d'Akeneo: després d'initStock, crea els source items dels productes nous sense trencar mai la importació.
declare(strict_types=1);

namespace Nacento\Roadie\Observer;

use Akeneo\Connector\Api\Data\JobInterface;
use Akeneo\Connector\Executor\JobExecutor;
use Akeneo\Connector\Helper\Import\Entities;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Nacento\Roadie\Model\AssignSourcesToNewProducts;
use Nacento\Roadie\Model\Config;
use Psr\Log\LoggerInterface;

class AssignSourcesAfterInitStock implements ObserverInterface
{
    public const STEP_INIT_STOCK = 'initStock';

    public function __construct(
        private readonly Config $config,
        private readonly Entities $entitiesHelper,
        private readonly AssignSourcesToNewProducts $assignSources,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->getSourceCodes()) {
                return;
            }
            $executor = $observer->getEvent()->getData('import');
            if (!$executor instanceof JobExecutor || $executor->getMethod() !== self::STEP_INIT_STOCK) {
                return;
            }
            $job = $executor->getCurrentJob();
            if (!$job || (int)$job->getStatus() === JobInterface::JOB_ERROR) {
                return;
            }
            $family = null;
            $jobClass = $executor->getCurrentJobClass();
            if ($jobClass && method_exists($jobClass, 'getFamily')) {
                $family = (string)$jobClass->getFamily();
            }
            $tmpTable = $this->entitiesHelper->getTableName($job->getCode());
            $result = $this->assignSources->execute($tmpTable, $family);
            $executor->setAdditionalMessage(
                __('MSI: %1 source items created, %2 updated', $result->created, $result->updated)
            );
        } catch (\Throwable $e) {
            $this->logger->error('[Roadie] ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
