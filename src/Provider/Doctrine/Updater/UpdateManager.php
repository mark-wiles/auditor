<?php

namespace DH\Auditor\Provider\Doctrine\Updater;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Exception\UpdateException;
use DH\Auditor\Provider\Doctrine\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Transaction\TransactionManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Exception;

class UpdateManager
{
    /**
     * @var TransactionManager
     */
    private $transactionManager;

    /**
     * @var Reader
     */
    private $reader;

    public function __construct(TransactionManager $transactionManager, Reader $reader)
    {
        $this->transactionManager = $transactionManager;
        $this->reader = $reader;
    }

    public function getConfiguration(): Configuration
    {
        return $this->transactionManager->getConfiguration();
    }

    /**
     * @param null|array    $sqls     SQL queries to execute
     * @param null|callable $callback Callback executed after each query is run
     */
    public function updateAuditSchema(?array $sqls = null, ?callable $callback = null): void
    {
        $auditEntityManager = $this->transactionManager->getConfiguration()->getEntityManager();

        if (null === $sqls) {
            $sqls = $this->getUpdateAuditSchemaSql();
        }

        foreach ($sqls as $index => $sql) {
            try {
                $statement = $auditEntityManager->getConnection()->prepare($sql);
                $statement->execute();

                if (null !== $callback) {
                    $callback([
                        'total' => \count($sqls),
                        'current' => $index,
                    ]);
                }
            } catch (Exception $e) {
                // something bad happened here :/
            }
        }
    }

    public function getUpdateAuditSchemaSql(): array
    {
        $readerEntityManager = $this->reader->getEntityManager();
        $readerSchemaManager = $readerEntityManager->getConnection()->getSchemaManager();

        $auditEntityManager = $this->transactionManager->getConfiguration()->getEntityManager();
        $auditSchemaManager = $auditEntityManager->getConnection()->getSchemaManager();

        $auditSchema = $auditSchemaManager->createSchema();
        $fromSchema = clone $auditSchema;
        $readerSchema = $readerSchemaManager->createSchema();
        $tables = $readerSchema->getTables();

        $entities = $this->reader->getEntities();
        foreach ($tables as $table) {
            if (\in_array($table->getName(), array_values($entities), true)) {
                $auditTablename = preg_replace(
                    sprintf('#^([^\.]+\.)?(%s)$#', preg_quote($table->getName(), '#')),
                    sprintf(
                        '$1%s$2%s',
                        preg_quote($this->transactionManager->getConfiguration()->getTablePrefix(), '#'),
                        preg_quote($this->transactionManager->getConfiguration()->getTableSuffix(), '#')
                    ),
                    $table->getName()
                );

                if ($auditSchema->hasTable($auditTablename)) {
                    $this->updateAuditTable($auditSchema->getTable($auditTablename), $auditSchema);
                } else {
                    $this->createAuditTable($table, $auditSchema);
                }
            }
        }

        return $fromSchema->getMigrateToSql($auditSchema, $auditSchemaManager->getDatabasePlatform());
    }

    /**
     * Creates an audit table.
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function createAuditTable(Table $table, ?Schema $schema = null): Schema
    {
        $entityManager = $this->getConfiguration()->getEntityManager();
        $schemaManager = $entityManager->getConnection()->getSchemaManager();
        if (null === $schema) {
            $schema = $schemaManager->createSchema();
        }

        $auditTablename = preg_replace(
            sprintf('#^([^\.]+\.)?(%s)$#', preg_quote($table->getName(), '#')),
            sprintf(
                '$1%s$2%s',
                preg_quote($this->getConfiguration()->getTablePrefix(), '#'),
                preg_quote($this->getConfiguration()->getTableSuffix(), '#')
            ),
            $table->getName()
        );

        if (null !== $auditTablename && !$schema->hasTable($auditTablename)) {
            $auditTable = $schema->createTable($auditTablename);

            // Add columns to audit table
            foreach (SchemaHelper::getAuditTableColumns() as $columnName => $struct) {
                $auditTable->addColumn($columnName, $struct['type'], $struct['options']);
            }

            // Add indices to audit table
            foreach (SchemaHelper::getAuditTableIndices($auditTablename) as $columnName => $struct) {
                if ('primary' === $struct['type']) {
                    $auditTable->setPrimaryKey([$columnName]);
                } else {
                    $auditTable->addIndex([$columnName], $struct['name']);
                }
            }
        }

        return $schema;
    }

    /**
     * Ensures an audit table's structure is valid.
     *
     * @throws UpdateException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    public function updateAuditTable(Table $table, ?Schema $schema = null, ?array $expectedColumns = null, ?array $expectedIndices = null): Schema
    {
        $entityManager = $this->getConfiguration()->getEntityManager();
        $schemaManager = $entityManager->getConnection()->getSchemaManager();
        if (null === $schema) {
            $schema = $schemaManager->createSchema();
        }

        $table = $schema->getTable($table->getName());

        $columns = $schemaManager->listTableColumns($table->getName());

        // process columns
        $this->processColumns($table, $columns, $expectedColumns ?? SchemaHelper::getAuditTableColumns());

        // process indices
        $this->processIndices($table, $expectedIndices ?? SchemaHelper::getAuditTableIndices($table->getName()));

        return $schema;
    }

    private function processColumns(Table $table, array $columns, array $expectedColumns): void
    {
        $processed = [];

        foreach ($columns as $column) {
            if (\array_key_exists($column->getName(), $expectedColumns)) {
                // column is part of expected columns
                $table->dropColumn($column->getName());
                $table->addColumn($column->getName(), $expectedColumns[$column->getName()]['type'], $expectedColumns[$column->getName()]['options']);
            } else {
                // column is not part of expected columns so it has to be removed
                $table->dropColumn($column->getName());
            }

            $processed[] = $column->getName();
        }

        foreach ($expectedColumns as $columnName => $options) {
            if (!\in_array($columnName, $processed, true)) {
                // expected column in not part of concrete ones so it's a new column, we need to add it
                $table->addColumn($columnName, $options['type'], $options['options']);
            }
        }
    }

    /**
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    private function processIndices(Table $table, array $expectedIndices): void
    {
        foreach ($expectedIndices as $columnName => $options) {
            if ('primary' === $options['type']) {
                $table->dropPrimaryKey();
                $table->setPrimaryKey([$columnName]);
            } else {
                if ($table->hasIndex($options['name'])) {
                    $table->dropIndex($options['name']);
                }
                $table->addIndex([$columnName], $options['name']);
            }
        }
    }
}
