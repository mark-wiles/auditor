<?php

namespace DH\Auditor\Provider\Doctrine\Audit\Event;

use DH\Auditor\Provider\Doctrine\DBAL\Logger;
use DH\Auditor\Provider\Doctrine\DBAL\LoggerChain;
use DH\Auditor\Provider\Doctrine\Model\Transaction;
use DH\Auditor\Provider\Doctrine\Transaction\TransactionManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

class DoctrineSubscriber implements EventSubscriber
{
    /**
     * @var TransactionManager
     */
    private $transactionManager;

    /**
     * @var ?SQLLogger
     */
    private $loggerBackup;

    public function __construct(TransactionManager $transactionManager)
    {
        $this->transactionManager = $transactionManager;
    }

    /**
     * It is called inside EntityManager#flush() after the changes to all the managed entities
     * and their associations have been computed.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#onflush
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getEntityManager();
        $transaction = new Transaction();

        // extend the SQL logger
        $this->loggerBackup = $em->getConnection()->getConfiguration()->getSQLLogger();
        $auditLogger = new Logger(function () use ($em, $transaction): void {
            // flushes pending data
            $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
            $this->transactionManager->process($transaction);
        });

        // Initialize a new LoggerChain with the new AuditLogger + the existing SQLLoggers.
        $loggerChain = new LoggerChain();
        $loggerChain->addLogger($auditLogger);
        if ($this->loggerBackup instanceof LoggerChain) {
            /** @var SQLLogger $logger */
            foreach ($this->loggerBackup->getLoggers() as $logger) {
                $loggerChain->addLogger($logger);
            }
        } elseif ($this->loggerBackup instanceof SQLLogger) {
            $loggerChain->addLogger($this->loggerBackup);
        }
        $em->getConnection()->getConfiguration()->setSQLLogger($loggerChain);

        // Populate transaction
        $this->transactionManager->populate($transaction);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }
}
