<?php

namespace DH\Auditor\Provider\Doctrine;

use DH\Auditor\Auditor;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\AbstractProvider;
use DH\Auditor\Provider\Doctrine\Audit\Annotation\AnnotationLoader;
use DH\Auditor\Provider\Doctrine\Audit\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Transaction\TransactionManager;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\SoftDeleteable\SoftDeleteableListener;

class DoctrineProvider extends AbstractProvider
{
    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface[]
     */
    private $storageEntityManagers;

    /**
     * @var EntityManagerInterface[]
     */
    private $auditingEntityManagers;

    /**
     * @var TransactionManager
     */
    private $transactionManager;

    /**
     * @var Reader
     */
    private $reader;

    public function __construct(Configuration $configuration, array $storageEntityManagers, array $auditingEntityManagers)
    {
        $this->configuration = $configuration;
        $this->storageEntityManagers = $storageEntityManagers;
        $this->auditingEntityManagers = $auditingEntityManagers;

        $this->transactionManager = new TransactionManager($this);

        foreach ($this->storageEntityManagers as $em) {
            $evm = $em->getEventManager();
            $evm->addEventSubscriber(new CreateSchemaListener($this));
        }
        foreach ($this->auditingEntityManagers as $em) {
            $evm = $em->getEventManager();
            $evm->addEventSubscriber(new DoctrineSubscriber($this->transactionManager));
            $evm->addEventSubscriber(new SoftDeleteableListener());

            $annotationLoader = new AnnotationLoader($em);

            $this->configuration->setEntities(array_merge(
                $this->configuration->getEntities(),
                $annotationLoader->load()
            ));
        }
    }

    public function persist(LifecycleEvent $event): void
    {
        // TODO: Implement persist() method.
    }

    /**
     * Returns true if $entity is auditable.
     *
     * @param object|string $entity
     */
    public function isAuditable($entity): bool
    {
        $class = DoctrineHelper::getRealClassName($entity);

        // is $entity part of audited entities?
        if (!\array_key_exists($class, $this->configuration->getEntities())) {
            // no => $entity is not audited
            return false;
        }

        return true;
    }

    /**
     * Returns true if $entity is audited.
     *
     * @param object|string $entity
     */
    public function isAudited($entity): bool
    {
        if (!$this->auditor->getConfiguration()->isEnabled()) {
            return false;
        }

        $class = DoctrineHelper::getRealClassName($entity);

        // is $entity part of audited entities?
        if (!\array_key_exists($class, $this->configuration->getEntities())) {
            // no => $entity is not audited
            return false;
        }

        $entityOptions = $this->configuration->getEntities()[$class];

        if (null === $entityOptions) {
            // no option defined => $entity is audited
            return true;
        }

        if (isset($entityOptions['enabled'])) {
            return (bool) $entityOptions['enabled'];
        }

        return true;
    }

    /**
     * Returns true if $field is audited.
     *
     * @param object|string $entity
     */
    public function isAuditedField($entity, string $field): bool
    {
        // is $field is part of globally ignored columns?
        if (\in_array($field, $this->configuration->getIgnoredColumns(), true)) {
            // yes => $field is not audited
            return false;
        }

        // is $entity audited?
        if (!$this->isAudited($entity)) {
            // no => $field is not audited
            return false;
        }

        $class = DoctrineHelper::getRealClassName($entity);
        $entityOptions = $this->configuration->getEntities()[$class];

        if (null === $entityOptions) {
            // no option defined => $field is audited
            return true;
        }

        // are columns excluded and is field part of them?
        if (isset($entityOptions['ignored_columns']) &&
            \in_array($field, $entityOptions['ignored_columns'], true)) {
            // yes => $field is not audited
            return false;
        }

        return true;
    }

    public function getAuditor(): Auditor
    {
        return $this->auditor;
    }

    public function supportsStorage(): bool
    {
        return true;
    }

    public function supportsAuditing(): bool
    {
        return true;
    }
}
