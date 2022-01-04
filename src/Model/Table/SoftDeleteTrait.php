<?php
namespace SoftDelete\Model\Table;

use Cake\Datasource\RulesChecker as BaseRulesChecker;
use Cake\Datasource\EntityInterface;
use SoftDelete\Error\MissingColumnException;
use SoftDelete\ORM\Query;

trait SoftDeleteTrait
{
    /**
     * Get the configured deletion field
     *
     * @return string
     * @throws MissingColumnException
     */
    public function getSoftDeleteField(): string
	{
		$field = $this->softDeleteField ?? 'deleted';

        if ($this->getSchema()->getColumn($field) === null) {
            throw new MissingColumnException(
                __('Configured field `{0}` is missing from the table `{1}`.',
                    $field,
                    $this->getAlias()
                )
            );
        }

        return $field;
    }

    public function query(): \Cake\ORM\Query
    {
        return new Query($this->getConnection(), $this);
    }

    /**
     * Perform the delete operation.
     *
     * Will soft delete the entity provided. Will remove rows from any
     * dependent associations, and clear out join tables for BelongsToMany associations.
     *
     * @param \Cake\DataSource\EntityInterface $entity The entity to softly delete.
     * @param \ArrayObject $options The options for the deletion.
     * @throws \InvalidArgumentException if there are no primary key values of the
     * passed entity
     * @return bool success
     */
    protected function _processDelete($entity, $options): bool
    {
        if ($entity->isNew()) {
            return false;
        }

        $primaryKey = (array)$this->getPrimaryKey();
        if (!$entity->has($primaryKey)) {
            $msg = 'Deleting requires all primary key values.';
            throw new \InvalidArgumentException($msg);
        }

        if ($options['checkRules'] && !$this->checkRules($entity, BaseRulesChecker::DELETE, $options)) {
            return false;
        }
        /** @var \Cake\Event\Event $event */
        $event = $this->dispatchEvent(
            'Model.beforeDelete', 
            [
                'entity' => $entity,
                'options' => $options
            ]
        );

        if ($event->isStopped()) {
            return $event->getResult();
        }

        $this->_associations->cascadeDelete(
            $entity,
            ['_primary' => false] + $options->getArrayCopy()
        );

        $query = $this->query();
        $conditions = $entity->extract($primaryKey);
        $statement = $query->update()
            ->set([$this->getSoftDeleteField() => date('Y-m-d H:i:s')])
            ->where($conditions)
            ->execute();

        $success = $statement->rowCount() > 0;
        if (!$success) {
            return false;
        }

        $this->dispatchEvent(
            'Model.afterDelete', 
            [
                'entity' => $entity,
                'options' => $options
            ]
        );

        return true;
    }

    /**
     * Soft deletes all records matching `$conditions`.
     * @return int number of affected rows.
     */
    public function deleteAll($conditions): int
    {
        $query = $this->query()
            ->update()
            ->set([$this->getSoftDeleteField() => date('Y-m-d H:i:s')])
            ->where($conditions);
        $statement = $query->execute();
        $statement->closeCursor();
        return $statement->rowCount();
    }

    /**
     * Hard deletes the given $entity.
     * @return bool true in case of success, false otherwise.
     */
    public function hardDelete(EntityInterface $entity): bool
	{
        if(!$this->delete($entity)) {
            return false;
        }
        $primaryKey = (array)$this->getPrimaryKey();
        $query = $this->query();
        $conditions = $entity->extract($primaryKey);
        $statement = $query->delete()
            ->where($conditions)
            ->execute();

        return $statement->rowCount() > 0;
    }

    /**
     * Hard deletes all records that were softly deleted before a given date.
     * @param \DateTime $until Date until witch soft deleted records must be hard deleted.
     * @return int number of affected rows.
     */
    public function hardDeleteAll(\Datetime $until): int
	{
        $query = $this->query()
            ->delete()
            ->where(
                [
                    $this->getSoftDeleteField() . ' IS NOT NULL',
                    $this->getSoftDeleteField() . ' <=' => $until->format('Y-m-d H:i:s')
                ]
            );
        $statement = $query->execute();
        $statement->closeCursor();
        return $statement->rowCount();
    }

    /**
     * Restore a soft deleted entity into an active state.
     * @param EntityInterface $entity Entity to be restored.
     * @return EntityInterface|false entity in case of success, false otherwise.
     */
    public function restore(EntityInterface $entity)
    {
        $softDeleteField = $this->getSoftDeleteField();
        $entity->$softDeleteField = null;
        return $this->save($entity);
    }
}
