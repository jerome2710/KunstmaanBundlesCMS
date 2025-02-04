<?php

namespace Kunstmaan\MenuBundle\AdminList;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Kunstmaan\AdminBundle\Helper\Security\Acl\AclHelper;
use Kunstmaan\AdminListBundle\AdminList\Configurator\AbstractDoctrineORMAdminListConfigurator;
use Kunstmaan\AdminListBundle\AdminList\FilterType\ORM;
use Kunstmaan\MenuBundle\Entity\Menu;

class MenuAdminListConfigurator extends AbstractDoctrineORMAdminListConfigurator
{
    /**
     * @var string
     */
    private $locale;

    /**
     * @param EntityManager $em        The entity manager
     * @param AclHelper     $aclHelper The acl helper
     */
    public function __construct(EntityManager $em, ?AclHelper $aclHelper = null)
    {
        parent::__construct($em, $aclHelper);
    }

    /**
     * @param string $locale
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
    }

    /**
     * Configure the visible columns
     */
    public function buildFields()
    {
        $this->addField('name', 'kuma_menu.menu.adminlist.field.name', true);
    }

    /**
     * Build filters for admin list
     */
    public function buildFilters()
    {
        $this->addFilter('name', new ORM\StringFilterType('name'), 'kuma_menu.menu.adminlist.filter.name');
    }

    public function getEntityClass(): string
    {
        return Menu::class;
    }

    /**
     * @return bool
     */
    public function canAdd()
    {
        return false;
    }

    /**
     * @param object|array $item
     *
     * @return bool
     */
    public function canEdit($item)
    {
        return false;
    }

    /**
     * Configure if it's possible to delete the given $item
     *
     * @param object|array $item
     *
     * @return bool
     */
    public function canDelete($item)
    {
        return false;
    }

    public function adaptQueryBuilder(QueryBuilder $queryBuilder)
    {
        parent::adaptQueryBuilder($queryBuilder);
        $queryBuilder
            ->andWhere('b.locale = :locale')
            ->setParameter('locale', $this->locale);
    }

    /**
     * @param string|null $suffix
     *
     * @return string
     */
    public function getPathByConvention($suffix = null)
    {
        if (null === $suffix || $suffix === '') {
            return 'kunstmaanmenubundle_admin_menu';
        }

        return sprintf('kunstmaanmenubundle_admin_menu_%s', $suffix);
    }
}
