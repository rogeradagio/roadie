<?php
// Comanda nacento:roadie:setup: aplica la lògica de F1 a productes existents (backfill).
declare(strict_types=1);

namespace Nacento\Roadie\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Nacento\Roadie\Model\Audit\SoundcheckReport;
use Nacento\Roadie\Model\Config;
use Nacento\Roadie\Model\SourceItemAssigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly SourceItemAssigner $assigner,
        private readonly SoundcheckReport $report,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('nacento:roadie:setup')
            ->setDescription('Create the configured MSI source items for existing products (backfill).')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'SKU(s); default: all products without source items')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count only, do not save');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->getSourceCodes()) {
            $output->writeln('<error>No sources configured in akeneo_connector/msi_sources/sources.</error>');
            return Command::FAILURE;
        }
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable) {
            // area already set
        }
        $skus = (array)$input->getOption('sku') ?: $this->report->getSkusWithoutSourceItems();
        $dryRun = (bool)$input->getOption('dry-run');
        $result = $this->assigner->assign($skus, $dryRun);
        $output->writeln(sprintf(
            '%s%d products, %d created, %d updated',
            $dryRun ? '[dry-run] ' : '',
            count($skus),
            $result->created,
            $result->updated
        ));

        return Command::SUCCESS;
    }
}
