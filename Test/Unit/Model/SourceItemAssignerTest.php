<?php
// Tests unitaris de SourceItemAssigner.
declare(strict_types=1);

namespace Nacento\Roadie\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\Data\SourceItemSearchResultsInterface;
use Magento\InventoryApi\Api\Data\SourceSearchResultsInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Nacento\Roadie\Model\Config;
use Nacento\Roadie\Model\SourceItemAssigner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceItemAssignerTest extends TestCase
{
    /** @var array<string, array{sku:string, source_code:string, status:int, quantity:float}> */
    private array $existing = [];
    /** @var string[] */
    private array $knownSources = [];
    /** @var list<list<array<string, mixed>>> each saved batch as plain arrays */
    private array $saved = [];
    private ?\Throwable $failFirstSave = null;
    private LoggerInterface $logger;

    private function makeAssigner(array $sources, array $inStock, bool $apply, int $batchSize = 25, ?array $known = null): SourceItemAssigner
    {
        $this->knownSources = $known ?? $sources;
        $config = $this->createMock(Config::class);
        $config->method('getSourceCodes')->willReturn($sources);
        $config->method('getInStockSourceCodes')->willReturn($inStock);
        $config->method('isApplyStatusToExisting')->willReturn($apply);

        $lastFilter = null;
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value) use (&$lastFilter, &$builder) {
            $lastFilter = [$field, $value];
            return $builder;
        });
        $builder->method('create')->willReturnCallback(function () use (&$lastFilter) {
            $c = new class extends SearchCriteria {
                public ?array $filter = null;
            };
            $c->filter = $lastFilter;
            return $c;
        });
        $builderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $builderFactory->method('create')->willReturn($builder);

        $sourceRepo = $this->createMock(SourceRepositoryInterface::class);
        $sourceRepo->method('getList')->willReturnCallback(function ($criteria) {
            $items = [];
            foreach (array_intersect($criteria->filter[1], $this->knownSources) as $code) {
                $s = $this->createMock(SourceInterface::class);
                $s->method('getSourceCode')->willReturn($code);
                $items[] = $s;
            }
            $r = $this->createMock(SourceSearchResultsInterface::class);
            $r->method('getItems')->willReturn($items);
            return $r;
        });

        $itemRepo = $this->createMock(SourceItemRepositoryInterface::class);
        $itemRepo->method('getList')->willReturnCallback(function ($criteria) {
            $items = [];
            foreach ($this->existing as $data) {
                if (in_array($data['sku'], $criteria->filter[1], true)) {
                    $items[] = $this->makeItem($data);
                }
            }
            $r = $this->createMock(SourceItemSearchResultsInterface::class);
            $r->method('getItems')->willReturn($items);
            return $r;
        });

        $factory = $this->createMock(SourceItemInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->makeItem([]));

        $save = $this->createMock(SourceItemsSaveInterface::class);
        $save->method('execute')->willReturnCallback(function (array $items) {
            if ($this->failFirstSave) {
                $e = $this->failFirstSave;
                $this->failFirstSave = null;
                throw $e;
            }
            $this->saved[] = array_map(static fn($i) => $i->data, $items);
        });

        $this->logger = $this->createMock(LoggerInterface::class);

        return new SourceItemAssigner($config, $sourceRepo, $itemRepo, $builderFactory, $factory, $save, $this->logger, $batchSize);
    }

    private function makeItem(array $data): SourceItemInterface
    {
        return new class($data) implements SourceItemInterface {
            public function __construct(public array $data) {}
            public function getSku(): ?string { return $this->data['sku'] ?? null; }
            public function setSku(?string $sku): void { $this->data['sku'] = $sku; }
            public function getSourceCode(): ?string { return $this->data['source_code'] ?? null; }
            public function setSourceCode(?string $sourceCode): void { $this->data['source_code'] = $sourceCode; }
            public function getQuantity(): ?float { return $this->data['quantity'] ?? null; }
            public function setQuantity(?float $quantity): void { $this->data['quantity'] = $quantity; }
            public function getStatus(): ?int { return $this->data['status'] ?? null; }
            public function setStatus(?int $status): void { $this->data['status'] = $status; }
            public function getExtensionAttributes(): ?\Magento\InventoryApi\Api\Data\SourceItemExtensionInterface { return null; }
            public function setExtensionAttributes(\Magento\InventoryApi\Api\Data\SourceItemExtensionInterface $extensionAttributes): void {}
        };
    }

    private function allSaved(): array
    {
        return array_merge([], ...$this->saved);
    }

    public function testCreatesMissingItemsWithQtyZeroAndStatusPerSource(): void
    {
        $result = $this->makeAssigner(['a', 'b'], ['a'], false)->assign(['S1']);

        self::assertSame(2, $result->created);
        self::assertSame(0, $result->updated);
        self::assertEquals([
            ['sku' => 'S1', 'source_code' => 'a', 'quantity' => 0.0, 'status' => 1],
            ['sku' => 'S1', 'source_code' => 'b', 'quantity' => 0.0, 'status' => 0],
        ], $this->allSaved());
    }

    public function testLeavesExistingItemsUntouchedWhenFlagOff(): void
    {
        $this->existing[] = ['sku' => 'S1', 'source_code' => 'a', 'status' => 0, 'quantity' => 7.0];
        $result = $this->makeAssigner(['a', 'b'], ['a'], false)->assign(['S1']);

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(['b'], array_column($this->allSaved(), 'source_code'));
    }

    public function testUpdatesExistingOutOfStockInStockSourceWhenFlagOn(): void
    {
        $this->existing[] = ['sku' => 'S1', 'source_code' => 'a', 'status' => 0, 'quantity' => 7.0];
        $result = $this->makeAssigner(['a'], ['a'], true)->assign(['S1']);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);
        self::assertEquals([['sku' => 'S1', 'source_code' => 'a', 'status' => 1, 'quantity' => 7.0]], $this->allSaved());
    }

    public function testDoesNotTouchExistingNonInStockSource(): void
    {
        $this->existing[] = ['sku' => 'S1', 'source_code' => 'b', 'status' => 0, 'quantity' => 0.0];
        $result = $this->makeAssigner(['a', 'b'], ['a'], true)->assign(['S1']);

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(['a'], array_column($this->allSaved(), 'source_code'));
    }

    public function testSplitsIntoBatches(): void
    {
        $this->makeAssigner(['a'], [], false, 2)->assign(['S1', 'S2', 'S3']);

        self::assertCount(2, $this->saved);
    }

    public function testSkipsUnknownSourceWithWarning(): void
    {
        $assigner = $this->makeAssigner(['a', 'gone'], [], false, 25, ['a']);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('gone'));

        self::assertSame(1, $assigner->assign(['S1'])->created);
    }

    public function testContinuesAfterFailedBatch(): void
    {
        $assigner = $this->makeAssigner(['a'], [], false, 1);
        $this->failFirstSave = new \RuntimeException('boom');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('boom'));

        $result = $assigner->assign(['S1', 'S2']);

        self::assertSame(1, $result->created);
        self::assertSame(['S2'], array_column($this->allSaved(), 'sku'));
    }

    public function testDryRunDoesNotSave(): void
    {
        $result = $this->makeAssigner(['a', 'b'], [], false)->assign(['S1'], true);

        self::assertSame(2, $result->created);
        self::assertSame([], $this->saved);
    }

    public function testEmptySkusDoesNothing(): void
    {
        $result = $this->makeAssigner(['a'], [], false)->assign([]);

        self::assertSame(0, $result->created);
        self::assertSame([], $this->saved);
    }
}
