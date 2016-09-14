<?php
/**
 * @category    OppositeOfGreen
 * @package     OppositeOfGreen_RapidFlow
 * @author      oppositeofgreen.magento@gmail.com
 */

namespace OppositeOfGreen\RapidFlow\Model\ResourceModel;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product\Action;
use Magento\Catalog\Model\ResourceModel\Product\Action as ResourceAction;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as AttributeOptionCollectionFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Store\Model\StoreManagerInterface;
use Unirgy\RapidFlow\Helper\Data as HelperData;
use Unirgy\RapidFlow\Model\ResourceModel\AbstractResource as ResourceModelAbstractResource;
use Unirgy\RapidFlow\Model\ResourceModel\AbstractResource\Fixed as AbstractResourceFixed;
use Unirgy\RapidFlowPro\Model\ResourceModel\ProductExtra as UnirgyProductExtra;

/**
 * Class ProductExtra
 *
 * During development, switch from UnirgyProductExtra to AbstractResourceFixed to reduce method not found errors.
 *   (Unirgy ProductExtra is encrypted and produces lots of "not found" messages in IDE.)
 *
 * @category    OppositeOfGreen
 * @package     OppositeOfGreen\RapidFlow\Model\ResourceModel
 */
class ProductExtra extends UnirgyProductExtra
{
    const COLUMN_SKU = 1;
    const COLUMN_ATTRIBUTE_CODE = 2;
    const COLUMN_ATTRIBUTE_VALUE = 3;
    const COLUMN_POSITION = 4;
    const COLUMN_GLU = 5;

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @var AttributeCollectionFactory
     */
    protected $attributeCollectionFactory;

    /**
     * @var AttributeOptionCollectionFactory
     */
    protected $attrOptionCollectionFactory;

    /**
     * @var array
     */
    protected $attributeCodeOptionsPair;

    /**
     * @var array
     */
    protected $attributeCodeOptionValueIdsPair;

    /**
     * @var CatalogConfig
     */
    protected $catalogConfig;

    /**
     * @var int
     */
    protected $attributeSetId = 0;

    /**
     * @var array
     */
    protected $loadedAttributeSets;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var array
     */
    protected $productsAttributeChanges = [];

    /**
     * @var Action
     */
    protected $productAction;

    /**
     * ProductExtra constructor.
     *
     * @param Context                          $context
     * @param FormatInterface                  $frameworkModelLocale
     * @param Filesystem                       $filesystem
     * @param HelperData                       $rapidFlowHelper
     * @param StoreManagerInterface            $storeManager
     * @param EavConfig                        $eavConfig
     * @param WriteFactory                     $writeFactory
     * @param ManagerInterface                 $eventManager
     * @param AttributeCollectionFactory       $attributeCollectionFactory
     * @param AttributeOptionCollectionFactory $attrOptionCollectionFactory
     * @param ProductFactory                   $productFactory
     * @param CatalogConfig                    $catalogConfig
     * @param ResourceAction                   $productAction
     */
    public function __construct(
        Context $context,
        FormatInterface $frameworkModelLocale,
        Filesystem $filesystem,
        HelperData $rapidFlowHelper,
        StoreManagerInterface $storeManager,
        EavConfig $eavConfig,
        WriteFactory $writeFactory,
        ManagerInterface $eventManager,
        AttributeCollectionFactory $attributeCollectionFactory,
        AttributeOptionCollectionFactory $attrOptionCollectionFactory,
        ProductFactory $productFactory,
        CatalogConfig $catalogConfig,
        ResourceAction $productAction
    ) {
        parent::__construct($context, $frameworkModelLocale, $filesystem, $rapidFlowHelper,
                            $storeManager, $eavConfig, $writeFactory, $eventManager);
        $this->eavConfig = $eavConfig;
        $this->attributeCollectionFactory = $attributeCollectionFactory;
        $this->attrOptionCollectionFactory = $attrOptionCollectionFactory;
        $this->productFactory = $productFactory;
        $this->catalogConfig = $catalogConfig;
        $this->productAction = $productAction;
    }

//    protected function _construct()
//    {
//        parent::_construct();
//    }

    /**
     * Set up any objects used during the import.
     */
    protected function init()
    {
        $this->catalogConfig->setStoreId($this->_profile->getStoreId());
        $this->attributeSetId = $this->catalogConfig->getAttributeSetId(4, 'Default');
        $this->loadAttributeOptions();
    }

    /**
     * Overrides Fixed _afterImport to catch end of file processing.
     *
     * @param int $cnt
     */
    protected function _afterImport($cnt)
    {
        // alternative: run save en masse at end:
        //$this->applyAllChanges();
        parent::_afterImport($cnt);
    }

    /**
     * Handles rows of individual product attribute values.
     *
     * @param array $row
     * @return mixed
     * @throws \Exception
     */
    public function _importRowCPAV($row)
    {
        if (!$this->attributeSetId){
            $this->init();
        }
        $attributeCode = $row[self::COLUMN_ATTRIBUTE_CODE];
        $sku = $row[self::COLUMN_SKU];
        $productId = $this->getProductIdBySku($sku);
        if (!$productId) {
            throw new \Exception("Sku \"$sku\" does not exist.");
        }
        $productId = "$productId"; // need key value, not index
        $value = $row[self::COLUMN_ATTRIBUTE_VALUE];
        $position = isset($row[self::COLUMN_POSITION]) ? $row[self::COLUMN_POSITION] : 0;
        $glue = isset($row[self::COLUMN_GLU]) ? $row[self::COLUMN_GLU] : ",";
        $options = $this->getAttributeOptionValueIdsPair($attributeCode);
        if (!array_key_exists($productId, $this->productsAttributeChanges)) {
            $this->productsAttributeChanges[$productId] = [];
        }
        if (!array_key_exists($attributeCode, $this->productsAttributeChanges[$productId])) {
            if (!$this->attributeExists($attributeCode)) {
                // optimize: only check this once
                throw new \Exception("Attribute \"$attributeCode\" does not exist.");
            }
            $this->productsAttributeChanges[$productId][$attributeCode] = [];
            // Note: I am deliberately wiping out and replacing all current values.
            //   If you don't want this behavior, load, explode and store current values here.
        }
        // store our values locally with *position* in case we add more later and want to sort them properly:
        if ($options) {
            // select or multiselect
            $intValue = $this->setOptionsToValues($options, $row[self::COLUMN_ATTRIBUTE_VALUE]);
            if (is_array($intValue)) {
                $intValue = implode($glue, array_unique($intValue));
            }
            if (!empty($value) && empty($intValue)) {
                // TODO: add missing values if profile was configured to do that? like in product import.
                throw new \Exception("Value \"$value\" not found for attribute \"$attributeCode\".");
            }
            $this->productsAttributeChanges[$productId][$attributeCode][] = [
                'position' => $position,
                'value' => $intValue,
                'glue' => $glue,
            ];
        } else {
            // flat value
            $value = $row[self::COLUMN_ATTRIBUTE_VALUE];
            $this->productsAttributeChanges[$productId][$attributeCode][] = [
                'position' => $position,
                'value' => $value,
                'glue' => $glue,
            ];
        }
        $this->applyAttributeChange($productId,
                                    $attributeCode,
                                    $this->productsAttributeChanges[$productId][$attributeCode]
        );
        return self::IMPORT_ROW_RESULT_SUCCESS;
    }

    /**
     * Runs all the product changes at once.
     *
     * @param $attributeCode
     * @param $attributeValues
     */
    protected function applyAllChanges($attributeCode, $attributeValues)
    {
        foreach ($this->productsAttributeChanges as $productId => $attributeChanges) {
            $productAttributes = [];
            foreach ($attributeChanges as $attributeCode => $attributeValues) {
                // sort by position, extract value
                usort($attributeValues, array($this, 'sortAttribute'));
                $glue = (isset($attributeValues[0]['glue'])) ? $attributeValues[0]['glue'] : "";
                $attributeValues = array_map(function($v) { return $v['value']; }, $attributeValues);
                // flatten and store
                $attributeValue = implode($glue, array_unique($attributeValues));
                $productAttributes[$attributeCode] = $attributeValue;
            }
            $this->productAction->updateAttributes(
                [$productId],
                $productAttributes,
                $this->_profile->getStoreId()
                );
            // if you have 2 or fewer stores, this will always save to store 0.
            // see: _saveAttributeValue getIsSingleStoreModeAllowed hasSingleStore.
        }
    }

    /**
     * Runs the attribute changes on each row.
     *
     * @param $productId
     * @param $attributeCode
     * @param $attributeValues
     */
    protected function applyAttributeChange($productId, $attributeCode, $attributeValues)
    {
        $productAttributes = [];
        // sort by position, extract value
        usort($attributeValues, array($this, 'sortAttribute'));
        $glue = (isset($attributeValues[0]['glue'])) ? $attributeValues[0]['glue'] : "";
        $attributeValues = array_map(function($v) { return $v['value']; }, $attributeValues);
        // flatten
        $attributeValue = implode($glue, array_unique($attributeValues));
        $productAttributes[$attributeCode] = $attributeValue;
        // save
        $this->productAction->updateAttributes(
            [$productId],
            $productAttributes,
            $this->_profile->getStoreId()
        );
        // if you have 2 or fewer stores, this will always save to store 0.
        // see: _saveAttributeValue getIsSingleStoreModeAllowed hasSingleStore.
    }

    /**
     * Inefficient, but \Magento\Catalog\Model\ResourceModel\Product\Action updateAttributes crashes hard otherwise.
     *
     * @param $attributeCode
     * @return bool
     */
    protected function attributeExists($attributeCode)
    {
        $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $attributeCode);
        return ($attribute && $attribute->getId());
    }

    /**
     * Sort entries by position property.
     *
     * @param array $valueA
     * @param array $valueB
     * @return int
     */
    protected function sortAttribute($valueA, $valueB)
    {
        $a1 = intval($valueA['position']) ?: 0;
        $b1 = intval($valueB['position']) ?: 0;
        return ($a1 > $b1) ? 1 : -1;
    }

    /**
     * Assign options data to attribute value
     *
     * @param mixed $options
     * @param array $values
     * @return array|mixed
     */
    protected function setOptionsToValues($options, $values)
    {
        $values = $this->getArrayValue($values);
        $result = [];
        foreach ($values as $value) {
            if (isset($options[$value])) {
                $result[] = $options[$value];
            }
        }
        return count($result) == 1 ? current($result) : $result;
    }

    /**
     * Get formatted array value
     *
     * @param mixed $value
     * @return array
     */
    protected function getArrayValue($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (false !== strpos($value, "\n")) {
            $value = array_filter(explode("\n", $value));
        }
        return !is_array($value) ? [$value] : $value;
    }

    /**
     * Get attribute options by attribute code
     *
     * @param string $attributeCode
     * @return \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection|null
     */
    public function getAttributeOptions($attributeCode)
    {
        if (!isset($this->attributeCodeOptionsPair[$attributeCode])) {
            $this->loadAttributeOptions();
        }
        return isset($this->attributeCodeOptionsPair[$attributeCode])
            ? $this->attributeCodeOptionsPair[$attributeCode]
            : null;
    }

    /**
     * Loads all attributes with options for current attribute set
     *
     * @return $this
     */
    protected function loadAttributeOptions()
    {
        if (isset($this->loadedAttributeSets[$this->attributeSetId])) {
            return $this;
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection $collection */
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToSelect(['attribute_code', 'attribute_id']);
        $collection->setAttributeSetFilter($this->attributeSetId);
        $collection->setFrontendInputTypeFilter(['in' => ['select', 'multiselect']]);
        foreach ($collection as $item) {
            $options = $this->attrOptionCollectionFactory->create()
                                                         ->setAttributeFilter($item->getAttributeId())->setPositionOrder('asc', true)->load();
            $this->attributeCodeOptionsPair[$item->getAttributeCode()] = $options;
        }
        $this->loadedAttributeSets[$this->attributeSetId] = true;
        return $this;
    }

    /**
     * Find attribute option value pair
     *
     * @param mixed $attributeCode
     * @return mixed
     */
    protected function getAttributeOptionValueIdsPair($attributeCode)
    {
        if (!empty($this->attributeCodeOptionValueIdsPair[$attributeCode])) {
            return $this->attributeCodeOptionValueIdsPair[$attributeCode];
        }

        $options = $this->getAttributeOptions($attributeCode);
        $opt = [];
        if ($options) {
            foreach ($options as $option) {
                $opt[$option->getValue()] = $option->getId();
            }
        }
        $this->attributeCodeOptionValueIdsPair[$attributeCode] = $opt;
        return $this->attributeCodeOptionValueIdsPair[$attributeCode];
    }

    /**
     * Retrieve product ID by sku
     *
     * @param string $sku
     * @return int
     */
    protected function getProductIdBySku($sku)
    {
        $product = $this->productFactory->create();
        $productId = $product->getIdBySku($sku);
        return $productId;
    }
}