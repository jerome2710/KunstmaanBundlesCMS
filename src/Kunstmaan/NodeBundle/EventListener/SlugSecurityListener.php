<?php

namespace Kunstmaan\NodeBundle\EventListener;

use Doctrine\ORM\EntityManager;
use Kunstmaan\AdminBundle\Helper\Security\Acl\Permission\PermissionMap;
use Kunstmaan\NodeBundle\Event\SlugSecurityEvent;
use Kunstmaan\NodeBundle\Helper\NodeMenu;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class SlugSecurityListener
{
    /**
     * @var AuthorizationCheckerInterface
     */
    protected $authorizationChecker;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var NodeMenu
     */
    protected $nodeMenu;

    /**
     * @var bool
     */
    private $permissionsEnabled;

    public function __construct(
        EntityManager $entityManager,
        AuthorizationCheckerInterface $authorizationChecker,
        NodeMenu $nodeMenu,
        $permissionsEnabled = true,
    ) {
        $this->em = $entityManager;
        $this->authorizationChecker = $authorizationChecker;
        $this->nodeMenu = $nodeMenu;
        $this->permissionsEnabled = $permissionsEnabled;
    }

    /**
     * Perform basic security checks
     *
     * @throws AccessDeniedException
     * @throws NotFoundHttpException
     */
    public function onSlugSecurityEvent(SlugSecurityEvent $event)
    {
        $node = $event->getNode();
        $nodeTranslation = $event->getNodeTranslation();
        $request = $event->getRequest();

        if ($this->permissionsEnabled && false === $this->authorizationChecker->isGranted(PermissionMap::PERMISSION_VIEW, $node)) {
            throw new AccessDeniedException('You do not have sufficient rights to access this page.');
        }

        $isPreview = $request->attributes->get('preview');

        if (!$isPreview && !$nodeTranslation->isOnline()) {
            throw new NotFoundHttpException('The requested page is not online');
        }

        $nodeMenu = $this->nodeMenu;
        $nodeMenu->setLocale($nodeTranslation->getLang());
        $nodeMenu->setCurrentNode($node);
        $nodeMenu->setIncludeOffline($isPreview);

        $request->attributes->set('_nodeMenu', $nodeMenu);
    }
}
