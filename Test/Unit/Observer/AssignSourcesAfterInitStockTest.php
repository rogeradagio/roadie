<?php
// Tests unitaris de l'observer: filtres (pas, estat del job, configuració) i gestió d'errors.
declare(strict_types=1);

namespace Nacento\Roadie\Test\Unit\Observer;

use Akeneo\Connector\Executor\JobExecutor;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Model\Job;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Nacento\Roadie\Model\AssignResult;
use Nacento\Roadie\Model\AssignSourcesToNewProducts;
use Nacento\Roadie\Model\Config;
use Nacento\Roadie\Observer\AssignSourcesAfterInitStock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AssignSourcesAfterInitStockTest extends TestCase
{
    private Config&MockObject $config;
    private AssignSourcesToNewProducts&MockObject $assign;
    private LoggerInterface&MockObject $logger;
    private JobExecutor&MockObject $executor;
    private AssignSourcesAfterInitStock $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->assign = $this->createMock(AssignSourcesToNewProducts::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $entities = $this->createMock(Entities::class);
        $entities->method('getTableName')->with('product')->willReturn('tmp_akeneo_connector_entities_product');
        $this->executor = $this->createMock(JobExecutor::class);

        $this->observer = new AssignSourcesAfterInitStock($this->config, $entities, $this->assign, $this->logger);
    }

    private function fire(string $method = 'initStock', mixed $status = 3, array $sources = ['default']): void
    {
        $this->config->method('getSourceCodes')->willReturn($sources);
        $job = $this->createMock(Job::class);
        $job->method('getStatus')->willReturn($status);
        $job->method('getCode')->willReturn('product');
        $this->executor->method('getMethod')->willReturn($method);
        $this->executor->method('getCurrentJob')->willReturn($job);

        $this->observer->execute(new Observer(['event' => new Event(['import' => $this->executor])]));
    }

    public function testSkipsWhenNoSources(): void
    {
        $this->assign->expects(self::never())->method('execute');
        $this->fire(sources: []);
    }

    public function testSkipsOtherSteps(): void
    {
        $this->assign->expects(self::never())->method('execute');
        $this->fire('setValues');
    }

    public function testSkipsWhenJobError(): void
    {
        $this->assign->expects(self::never())->method('execute');
        $this->fire(status: 2);
    }

    public function testSkipsWhenJobErrorAsString(): void
    {
        $this->assign->expects(self::never())->method('execute');
        $this->fire(status: '2');
    }

    public function testRunsOnInitStockAndAppendsMessage(): void
    {
        $this->assign->expects(self::once())->method('execute')
            ->with('tmp_akeneo_connector_entities_product')
            ->willReturn(new AssignResult(3, 1));
        $this->executor->expects(self::once())->method('setAdditionalMessage')
            ->with(self::callback(static fn($m): bool => (string)$m === 'MSI: 3 source items created, 1 updated'));
        $this->fire();
    }

    public function testSwallowsAndLogsThrowable(): void
    {
        $this->assign->method('execute')->willThrowException(new \Error('kaboom'));
        $this->executor->expects(self::never())->method('setAdditionalMessage');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('[Roadie] kaboom'));
        $this->fire();
    }

    public function testSkipsWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $observer = new AssignSourcesAfterInitStock($config, $this->createMock(Entities::class), $this->assign, $this->logger);
        $this->assign->expects(self::never())->method('execute');
        $observer->execute(new Observer(['event' => new Event(['import' => $this->executor])]));
    }
}
