<?php

namespace Kunstmaan\ArticleBundle\AdminList;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Kunstmaan\AdminBundle\Helper\Security\Acl\AclHelper;
use Kunstmaan\AdminListBundle\AdminList\Configurator\AbstractDoctrineORMAdminListConfigurator;
use Kunstmaan\AdminListBundle\AdminList\FilterType\ORM\StringFilterType;
use Kunstmaan\ArticleBundle\Entity\AbstractCategory;

/**
 * The AdminList configurator for the AbstractArticleAuthor
 */
class AbstractArticleCategoryAdminListConfigurator extends AbstractDoctrineORMAdminListConfigurator
{
    public function __construct(EntityManagerInterface $em, AclHelper $aclHelper)
    {
        parent::__construct($em, $aclHelper);
    }

    public function getEntityClass(): string
    {
        return AbstractCategory::class;
    }

    /**
     * Configure filters
     */
    public function buildFilters()
    {
        $this->addFilter('name', new StringFilterType('name'), 'article.category.list.filter.name');
    }

    /**
     * Configure the visible columns
     */
    public function buildFields()
    {
        $this->addField('name', 'article.category.list.header.name', true);
    }

    public function adaptQueryBuilder(QueryBuilder $queryBuilder)
    {
        $queryBuilder->orderBy('b.name', 'ASC');
    }

    /**
     * Overwrite the parent function. By adding the TranslationWalker, we can order by the translated fields.
     *
     * @return \Doctrine\ORM\Query|null
     */
    public function getQuery()
    {
        $query = parent::getQuery();

        if (!\is_null($query)) {
            $query->setHint(
                \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER,
                'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
            );
            $query->useQueryCache(false);
        }

        return $query;
    }
}
