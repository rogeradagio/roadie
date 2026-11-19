<?php
// Llegeix la configuració del mòdul (akeneo_connector/msi_sources/*).
declare(strict_types=1);

namespace Nacento\Roadie\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_PATH_ENABLED = 'akeneo_connector/msi_sources/enabled';
    public const XML_PATH_SOURCES = 'akeneo_connector/msi_sources/sources';
    public const XML_PATH_IN_STOCK_SOURCES = 'akeneo_connector/msi_sources/in_stock_sources';
    public const XML_PATH_APPLY_STATUS_TO_EXISTING = 'akeneo_connector/msi_sources/apply_status_to_existing';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /** @return string[] */
    public function getSourceCodes(): array
    {
        return self::parseList($this->scopeConfig->getValue(self::XML_PATH_SOURCES));
    }

    /** @return string[] */
    public function getInStockSourceCodes(): array
    {
        return self::parseList($this->scopeConfig->getValue(self::XML_PATH_IN_STOCK_SOURCES));
    }

    public function isApplyStatusToExisting(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_APPLY_STATUS_TO_EXISTING);
    }

    /** @return string[] */
    public static function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }
        $items = array_map('trim', explode(',', (string)$value));

        return array_values(array_unique(array_filter($items, static fn(string $v): bool => $v !== '')));
    }
}
