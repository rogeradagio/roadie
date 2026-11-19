<?php
// Comanda nacento:roadie:soundcheck: informe d'incidències de MSI; acaba amb codi 1 si en troba.
declare(strict_types=1);

namespace Nacento\Roadie\Console\Command;

use Nacento\Roadie\Model\Audit\SoundcheckReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class SoundcheckCommand extends Command
{
    public function __construct(private readonly SoundcheckReport $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('nacento:roadie:soundcheck')
            ->setDescription('Audit MSI source items (read-only). Exits non-zero when issues are found.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table|csv', 'table')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the report to this file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, ['table', 'csv'], true)) {
            $output->writeln('<error>--format must be table or csv</error>');
            return Command::INVALID;
        }
        $file = $input->getOption('output');
        $out = $file ? new StreamOutput(fopen((string)$file, 'wb')) : $output;

        $issues = 0;
        foreach ($this->report->run() as $section => $rows) {
            $issues += count($rows);
            if ($format === 'csv') {
                $this->writeCsv($out, $section, $rows);
                continue;
            }
            $out->writeln(sprintf('<info>%s</info>: %d', $section, count($rows)));
            if ($rows) {
                (new Table($out))->setHeaders(array_keys($rows[0]))->setRows($rows)->render();
            }
        }
        if ($file) {
            $output->writeln(sprintf('Report written to %s (%d issues)', $file, $issues));
        }

        return $issues > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeCsv(OutputInterface $out, string $section, array $rows): void
    {
        $fh = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($fh, array_merge(['section' => $section], $row));
        }
        rewind($fh);
        $out->write((string)stream_get_contents($fh));
        fclose($fh);
    }
}
