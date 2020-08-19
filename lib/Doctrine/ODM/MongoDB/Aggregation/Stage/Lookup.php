<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Aggregation\Stage;

use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Aggregation\Expr;
use Doctrine\ODM\MongoDB\Aggregation\Stage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\MappingException;
use Doctrine\ODM\MongoDB\Persisters\DocumentPersister;
use Doctrine\Persistence\Mapping\MappingException as BaseMappingException;
use InvalidArgumentException;

/**
 * Fluent interface for building aggregation pipelines.
 */
class Lookup extends Stage
{
    /** @var DocumentManager */
    private $dm;

    /** @var ClassMetadata */
    private $class;

    /** @var ClassMetadata */
    private $targetClass;

    /** @var string */
    private $from;

    /** @var string */
    private $localField;

    /** @var string */
    private $foreignField;

    /** @var string */
    private $as;

    /** @var array */
    private $let;

    /** @var Expr|array */
    private $pipeline;

    public function __construct(Builder $builder, string $from, DocumentManager $documentManager, ClassMetadata $class)
    {
        parent::__construct($builder);

        $this->dm    = $documentManager;
        $this->class = $class;

        $this->from($from);
    }

    /**
     * Specifies the name of the new array field to add to the input documents.
     *
     * The new array field contains the matching documents from the from
     * collection. If the specified name already exists in the input document,
     * the existing field is overwritten.
     */
    public function alias(string $alias) : self
    {
        $this->as = $alias;

        return $this;
    }

    /**
     * Specifies the collection or field name in the same database to perform the join with.
     *
     * The from collection cannot be sharded.
     */
    public function from(string $from) : self
    {
        // $from can either be
        // a) a field name indicating a reference to a different document. Currently, only REFERENCE_STORE_AS_ID is supported
        // b) a Class name
        // c) a collection name
        // In cases b) and c) the local and foreign fields need to be filled
        if ($this->class->hasReference($from)) {
            return $this->fromReference($from);
        }

        // Check if mapped class with given name exists
        try {
            $this->targetClass = $this->dm->getClassMetadata($from);
        } catch (BaseMappingException $e) {
            $this->from = $from;

            return $this;
        }

        if ($this->targetClass->isSharded()) {
            throw MappingException::cannotUseShardedCollectionInLookupStages($this->targetClass->name);
        }

        $this->from = $this->targetClass->getCollection();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getExpression() : array
    {
        if (isset($this->pipeline)) {
            $pipeline = $this->pipeline instanceof Expr ? $this->pipeline->getExpression() : $this->pipeline;

            return [
                '$lookup' => [
                    'from' => $this->from,
                    'let' => (object) $this->let,
                    'pipeline' => $pipeline,
                    'as' => $this->as,
                ],
            ];
        }

        return [
            '$lookup' => [
                'from' => $this->from,
                'localField' => $this->localField,
                'foreignField' => $this->foreignField,
                'as' => $this->as,
            ],
        ];
    }

    /**
     * Specifies the field from the documents input to the $lookup stage.
     *
     * $lookup performs an equality match on the localField to the foreignField
     * from the documents of the from collection. If an input document does not
     * contain the localField, the $lookup treats the field as having a value of
     * null for matching purposes.
     */
    public function localField(string $localField) : self
    {
        $this->localField = $this->prepareFieldName($localField, $this->class);

        return $this;
    }

    /**
     * Specifies the field from the documents in the from collection.
     *
     * $lookup performs an equality match on the foreignField to the localField
     * from the input documents. If a document in the from collection does not
     * contain the foreignField, the $lookup treats the value as null for
     * matching purposes.
     */
    public function foreignField(string $foreignField) : self
    {
        $this->foreignField = $this->prepareFieldName($foreignField, $this->targetClass);

        return $this;
    }

    /**
     * Specifies variables to use in the pipeline field stages.
     *
     * Use the variable expressions to access the fields from the documents input to the $lookup stage.
     * The pipeline cannot directly access the input document fields.
     * Instead, first define the variables for the input document fields, and then reference the variables in the stages in the pipeline.
     */
    public function let(array $let) : self
    {
        $this->let = $let;

        return $this;
    }

    /**
     * Specifies the pipeline to run on the joined collection.
     *
     * The pipeline determines the resulting documents from the joined collection. To return all documents, specify an empty pipeline [].
     * The pipeline cannot include the $out stage or the $merge stage.
     * The pipeline cannot directly access the input document fields.
     * Instead, first define the variables for the input document fields, and then reference the variables in the stages in the pipeline.
     */
    public function pipeline($pipeline) : self
    {
        if (! is_array($pipeline) && ! $pipeline instanceof Expr) {
            throw new InvalidArgumentException(__METHOD__ . ' expects either an aggregation builder or an aggregation stage.');
        }

        $this->pipeline = $pipeline;

        return $this;
    }

    protected function prepareFieldName(string $fieldName, ?ClassMetadata $class = null) : string
    {
        if (! $class) {
            return $fieldName;
        }

        return $this->getDocumentPersister($class)->prepareFieldName($fieldName);
    }

    /**
     * @throws MappingException
     */
    private function fromReference(string $fieldName) : self
    {
        if (! $this->class->hasReference($fieldName)) {
            MappingException::referenceMappingNotFound($this->class->name, $fieldName);
        }

        $referenceMapping  = $this->class->getFieldMapping($fieldName);
        $this->targetClass = $this->dm->getClassMetadata($referenceMapping['targetDocument']);
        if ($this->targetClass->isSharded()) {
            throw MappingException::cannotUseShardedCollectionInLookupStages($this->targetClass->name);
        }

        $this->from = $this->targetClass->getCollection();

        if ($referenceMapping['isOwningSide']) {
            switch ($referenceMapping['storeAs']) {
                case ClassMetadata::REFERENCE_STORE_AS_ID:
                case ClassMetadata::REFERENCE_STORE_AS_REF:
                    $referencedFieldName = ClassMetadata::getReferenceFieldName($referenceMapping['storeAs'], $referenceMapping['name']);
                    break;

                default:
                    throw MappingException::cannotLookupDbRefReference($this->class->name, $fieldName);
            }

            $this
                ->foreignField('_id')
                ->localField($referencedFieldName);
        } else {
            if (isset($referenceMapping['repositoryMethod']) || ! isset($referenceMapping['mappedBy'])) {
                throw MappingException::repositoryMethodLookupNotAllowed($this->class->name, $fieldName);
            }

            $mappedByMapping = $this->targetClass->getFieldMapping($referenceMapping['mappedBy']);
            switch ($mappedByMapping['storeAs']) {
                case ClassMetadata::REFERENCE_STORE_AS_ID:
                case ClassMetadata::REFERENCE_STORE_AS_REF:
                    $referencedFieldName = ClassMetadata::getReferenceFieldName($mappedByMapping['storeAs'], $mappedByMapping['name']);
                    break;

                default:
                    throw MappingException::cannotLookupDbRefReference($this->class->name, $fieldName);
            }

            $this
                ->localField('_id')
                ->foreignField($referencedFieldName)
                ->alias($fieldName);
        }

        return $this;
    }

    private function getDocumentPersister(ClassMetadata $class) : DocumentPersister
    {
        return $this->dm->getUnitOfWork()->getDocumentPersister($class->name);
    }
}
