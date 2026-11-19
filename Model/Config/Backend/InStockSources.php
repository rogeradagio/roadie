<?php
// Valida en desar: les sources In Stock han de ser a la llista de sources, i la llista no pot ser buida si el mòdul està activat.
declare(strict_types=1);

namespace Nacento\Roadie\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Nacento\Roadie\Model\Config;

class InStockSources extends Value
{
    public function beforeSave()
    {
        $inStock = Config::parseList($this->getValue());

        $sources = Config::parseList(
            $this->getFieldsetDataValue('sources') ?? $this->_config->getValue(Config::XML_PATH_SOURCES)
        );
        $enabled = (bool)($this->getFieldsetDataValue('enabled') ?? $this->_config->getValue(Config::XML_PATH_ENABLED));

        if ($enabled && !$sources) {
            throw new LocalizedException(
                __('Select at least one source to assign when MSI Sources is enabled.')
            );
        }
        $invalid = array_diff($inStock, $sources);
        if ($invalid) {
            throw new LocalizedException(__(
                'In-stock sources must also be selected in "Sources to assign to new products": %1',
                implode(', ', $invalid)
            ));
        }
        $this->setValue(implode(',', $inStock));

        return parent::beforeSave();
    }
}
