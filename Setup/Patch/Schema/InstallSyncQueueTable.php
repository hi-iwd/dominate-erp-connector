<?php

namespace Dominate\ErpConnector\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Install sync queue table schema patch.
 */
class InstallSyncQueueTable implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private SchemaSetupInterface $schemaSetup;

    /**
     * InstallSyncQueueTable constructor.
     *
     * @param SchemaSetupInterface $schemaSetup
     */
    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $installer = $this->schemaSetup;
        $installer->startSetup();

        /**
         * Create table 'dominate_sync_queue'
         */
        $table = $installer->getConnection()->newTable(
            $installer->getTable('dominate_sync_queue')
        )->addColumn(
            'id',
            Table::TYPE_INTEGER,
            null,
            ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
            'Queue ID'
        )->addColumn(
            'entity_type',
            Table::TYPE_TEXT,
            50,
            ['nullable' => false],
            'Entity Type (order, customer, inventory, etc.)'
        )->addColumn(
            'entity_id',
            Table::TYPE_TEXT,
            255,
            ['nullable' => false],
            'Entity ID from source system'
        )->addColumn(
            'event',
            Table::TYPE_TEXT,
            50,
            ['nullable' => false],
            'Event type (create, update, delete, etc.)'
        )->addColumn(
            'payload',
            Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'JSON payload data'
        )->addColumn(
            'attempts',
            Table::TYPE_INTEGER,
            null,
            ['unsigned' => true, 'nullable' => false, 'default' => 0],
            'Number of attempts'
        )->addColumn(
            'next_attempt_at',
            Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => true],
            'Next attempt timestamp'
        )->addColumn(
            'last_attempt_at',
            Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => true],
            'Last attempt timestamp'
        )->addColumn(
            'error_message',
            Table::TYPE_TEXT,
            null,
            ['nullable' => true],
            'Error message from last attempt'
        )->addColumn(
            'created_at',
            Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
            'Created timestamp'
        )->addColumn(
            'updated_at',
            Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
            'Updated timestamp'
        )->addIndex(
            $installer->getIdxName('dominate_sync_queue', ['attempts', 'next_attempt_at']),
            ['attempts', 'next_attempt_at'],
            ['type' => 'index']
        )->addIndex(
            $installer->getIdxName('dominate_sync_queue', ['next_attempt_at']),
            ['next_attempt_at'],
            ['type' => 'index']
        )->addIndex(
            $installer->getIdxName('dominate_sync_queue', ['entity_type', 'entity_id']),
            ['entity_type', 'entity_id'],
            ['type' => 'index']
        )->setComment(
            'Dominate ERP Connector Sync Queue'
        );

        $installer->getConnection()->createTable($table);

        $installer->endSetup();

        return $this;
    }
}

