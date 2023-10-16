<?php

namespace SoftDelete\ORM;

use Cake\ORM\Query\SelectQuery as CakeSelectQuery;


class SelectQuery extends CakeSelectQuery
{
    public function triggerBeforeFind(): void
    {
        if (!$this->_beforeFindFired && $this->_type === 'select') {
            parent::triggerBeforeFind();

            $aliasedField = $this
                ->getRepository()
                ->aliasField($this->getRepository()->getSoftDeleteField());
            if (!is_array($this->getOptions()) || !in_array('withDeleted', $this->getOptions())) {
                $this->andWhere($aliasedField . ' IS NULL');
            }
        }
    }
}