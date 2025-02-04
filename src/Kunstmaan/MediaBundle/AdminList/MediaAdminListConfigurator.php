<?php

namespace Kunstmaan\MediaBundle\AdminList;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Kunstmaan\AdminListBundle\AdminList\Configurator\AbstractDoctrineORMAdminListConfigurator;
use Kunstmaan\AdminListBundle\AdminList\Configurator\ChangeableLimitInterface;
use Kunstmaan\AdminListBundle\AdminList\FilterType\ORM;
use Kunstmaan\AdminListBundle\Traits\ChangeableLimitTrait;
use Kunstmaan\MediaBundle\AdminList\ItemAction\MediaDeleteItemAction;
use Kunstmaan\MediaBundle\AdminList\ItemAction\MediaEditItemAction;
use Kunstmaan\MediaBundle\AdminList\ItemAction\MediaSelectItemAction;
use Kunstmaan\MediaBundle\Entity\Folder;
use Kunstmaan\MediaBundle\Entity\Media;
use Kunstmaan\MediaBundle\Form\Type\MediaType;
use Kunstmaan\MediaBundle\Helper\MediaManager;
use Kunstmaan\MediaBundle\Helper\RemoteAudio\RemoteAudioHandler;
use Kunstmaan\MediaBundle\Helper\RemoteSlide\RemoteSlideHandler;
use Kunstmaan\MediaBundle\Helper\RemoteVideo\RemoteVideoHandler;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin list configurator for the Media entity
 */
class MediaAdminListConfigurator extends AbstractDoctrineORMAdminListConfigurator implements ChangeableLimitInterface
{
    use ChangeableLimitTrait;

    /**
     * @var Folder
     */
    private $folder;

    /**
     * @var Request
     */
    private $request;

    /**
     * @param EntityManager $em           The entity manager
     * @param MediaManager  $mediaManager The media manager
     * @param Folder        $folder       The current folder
     * @param Request       $request      The request object
     */
    public function __construct(
        EntityManager $em,
        MediaManager $mediaManager,
        Folder $folder,
        Request $request,
    ) {
        parent::__construct($em);

        $this->setAdminType(MediaType::class);

        $this->folder = $folder;
        $this->request = $request;
    }

    /**
     * Configure the visible columns
     */
    public function buildFields()
    {
        $this->addField('name', 'media.adminlist.configurator.name', true);
        $this->addField('contentType', 'media.adminlist.configurator.type', true);
        $this->addField('updatedAt', 'media.adminlist.configurator.date', true);
        $this->addField('filesize', 'media.adminlist.configurator.filesize', true);
    }

    /**
     * Build filters for admin list
     */
    public function buildFilters()
    {
        $this->addFilter('name', new ORM\StringFilterType('name'), 'media.adminlist.configurator.filter.name');
        $this->addFilter('contentType', new ORM\StringFilterType('contentType'), 'media.adminlist.configurator.filter.type');
        $this->addFilter('updatedAt', new ORM\NumberFilterType('updatedAt'), 'media.adminlist.configurator.filter.updated_at');
        $this->addFilter('filesize', new ORM\NumberFilterType('filesize'), 'media.adminlist.configurator.filter.filesize');
    }

    /**
     * Return the url to list all the items
     *
     * @return array
     */
    public function getIndexUrl()
    {
        return [
            'path' => $this->request->get('_route'),
            'params' => ['folderId' => $this->folder->getId()],
        ];
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

    /**
     * Add item actions buttons
     */
    public function buildItemActions()
    {
        if ($this->request->get('_route') == 'KunstmaanMediaBundle_chooser_show_folder') {
            $this->addItemAction(new MediaSelectItemAction());
        } else {
            $this->addItemAction(new MediaEditItemAction());
            $this->addItemAction(new MediaDeleteItemAction($this->request->getRequestUri()));
        }
    }

    public function getEntityClass(): string
    {
        return Media::class;
    }

    public function adaptQueryBuilder(QueryBuilder $queryBuilder)
    {
        $queryBuilder->andWhere('b.folder = :folder')
            ->setParameter('folder', $this->folder->getId())
            ->andWhere('b.deleted = :deleted')
            ->setParameter('deleted', false)
            ->orderBy('b.updatedAt', 'DESC');

        if ($this->request->get('_route') == 'KunstmaanMediaBundle_chooser_show_folder') {
            $type = $this->request->query->get('type');
            if ($type) {
                switch ($type) {
                    case 'file':
                        $queryBuilder->andWhere('b.location = :location')
                            ->setParameter('location', 'local');

                        break;
                    case 'image':
                        $queryBuilder->andWhere('b.contentType LIKE :ctype')
                            ->setParameter('ctype', '%image%');

                        break;
                    case RemoteAudioHandler::TYPE:
                        $queryBuilder->andWhere('b.contentType = :ctype')
                            ->setParameter('ctype', RemoteAudioHandler::CONTENT_TYPE);

                        break;
                    case RemoteSlideHandler::TYPE:
                        $queryBuilder->andWhere('b.contentType = :ctype')
                            ->setParameter('ctype', RemoteSlideHandler::CONTENT_TYPE);

                        break;
                    case RemoteVideoHandler::TYPE:
                        $queryBuilder->andWhere('b.contentType = :ctype')
                            ->setParameter('ctype', RemoteVideoHandler::CONTENT_TYPE);

                        break;
                }
            }
        }
    }
}
