<?php

namespace Kunstmaan\PagePartBundle\PagePartAdmin;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Kunstmaan\AdminBundle\Entity\EntityInterface;
use Kunstmaan\PagePartBundle\Dto\PagePartDeleteInfo;
use Kunstmaan\PagePartBundle\Entity\PagePartRef;
use Kunstmaan\PagePartBundle\Event\Events;
use Kunstmaan\PagePartBundle\Event\PagePartEvent;
use Kunstmaan\PagePartBundle\Helper\HasPagePartsInterface;
use Kunstmaan\PagePartBundle\Helper\PagePartInterface;
use Kunstmaan\PagePartBundle\Repository\PagePartRefRepository;
use Kunstmaan\UtilitiesBundle\Helper\ClassLookup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;

class PagePartAdmin
{
    /**
     * @var PagePartAdminConfiguratorInterface
     */
    protected $configurator;

    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var HasPagePartsInterface
     */
    protected $page;

    /**
     * @var string
     */
    protected $context;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var PagePartInterface[]
     */
    protected $pageParts = [];

    /**
     * @var PagePartRef[]
     */
    protected $pagePartRefs = [];

    /**
     * @var PagePartInterface[]
     */
    protected $newPageParts = [];

    /**
     * @param PagePartAdminConfiguratorInterface $configurator The configurator
     * @param EntityManagerInterface             $em           The entity manager
     * @param HasPagePartsInterface              $page         The page
     * @param string|null                        $context      The context
     * @param ContainerInterface|null            $container    The container
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(PagePartAdminConfiguratorInterface $configurator, EntityManagerInterface $em, HasPagePartsInterface $page, $context = null, ?ContainerInterface $container = null)
    {
        if (!($page instanceof EntityInterface)) {
            throw new \InvalidArgumentException('Page must be an instance of EntityInterface.');
        }

        $this->configurator = $configurator;
        $this->em = $em;
        $this->page = $page;
        $this->container = $container;

        if ($context) {
            $this->context = $context;
        } elseif ($this->configurator->getContext()) {
            $this->context = $this->configurator->getContext();
        } else {
            $this->context = 'main';
        }

        $this->initializePageParts();
    }

    /**
     * Get all pageparts from the database, and store them.
     */
    private function initializePageParts()
    {
        // Get all the pagepartrefs
        /** @var PagePartRefRepository $ppRefRepo */
        $ppRefRepo = $this->em->getRepository(PagePartRef::class);
        $ppRefs = $ppRefRepo->getPagePartRefs($this->page, $this->context);

        // Group pagepartrefs per type
        $types = [];
        foreach ($ppRefs as $pagePartRef) {
            $types[$pagePartRef->getPagePartEntityname()][] = $pagePartRef->getPagePartId();
            $this->pagePartRefs[$pagePartRef->getId()] = $pagePartRef;
        }

        // Fetch all the pageparts (only one query per pagepart type)
        /** @var EntityInterface[] $pageParts */
        $pageParts = [];
        foreach ($types as $classname => $ids) {
            $result = $this->em->getRepository($classname)->findBy(['id' => $ids]);
            $pageParts = array_merge($pageParts, $result);
        }

        // Link the pagepartref to the pagepart
        foreach ($this->pagePartRefs as $pagePartRef) {
            foreach ($pageParts as $key => $pagePart) {
                if (ClassLookup::getClass($pagePart) == $pagePartRef->getPagePartEntityname()
                    && $pagePart->getId() == $pagePartRef->getPagePartId()
                ) {
                    $this->pageParts[$pagePartRef->getId()] = $pagePart;
                    unset($pageParts[$key]);

                    break;
                }
            }
        }
    }

    /**
     * @return EntityInterface
     */
    public function getPage()
    {
        return $this->page;
    }

    public function preBindRequest(Request $request)
    {
        /** @var array<string, list<PagePartDeleteInfo>> $subPagePartsToDelete */
        $subPagePartsToDelete = [];
        // Fetch all sub-entities that should be removed
        foreach (array_keys($request->request->all()) as $key) {
            if (!str_starts_with($key, 'delete_pagepartadmin_')) {
                continue;
            }

            preg_match('#^delete_pagepartadmin_(\d+)_(.*)#', $key, $ppInfo);
            // Skip not persisted sub entities
            if (!isset($ppInfo[1], $ppInfo[2])) {
                continue;
            }
            preg_match_all('#([a-zA-Z0-9]+)_(\\d+)#', $ppInfo[2], $matches, PREG_SET_ORDER);

            if (count($matches) > 0) {
                $subPagePartsToDelete[$ppInfo[1]][] = $this->getDeleteInfo($matches);
            }
        }

        $doFlush = false;
        foreach ($this->pagePartRefs as $pagePartRef) {
            // Remove pageparts
            if ('true' === $request->request->get($pagePartRef->getId() . '_deleted')) {
                $pagePart = $this->pageParts[$pagePartRef->getId()];
                $this->em->remove($pagePart);
                $this->em->remove($pagePartRef);

                unset($this->pageParts[$pagePartRef->getId()], $this->pagePartRefs[$pagePartRef->getId()]);
                $doFlush = true;
            }

            // Remove sub-entities from pageparts
            if (\array_key_exists($pagePartRef->getId(), $subPagePartsToDelete)) {
                $pagePart = $this->pageParts[$pagePartRef->getId()];
                /** @var PagePartDeleteInfo|null $deleteInfo */
                foreach ($subPagePartsToDelete[$pagePartRef->getId()] as $deleteInfo) {
                    if ($deleteInfo === null) {
                        continue;
                    }
                    /** @var EntityInterface $deleteObject */
                    $deleteObject = $this->getObjectForDeletion($pagePart, $deleteInfo);

                    if (null !== $deleteObject) {
                        $this->em->remove($deleteObject);
                        $doFlush = true;
                    }
                }
            }
        }

        if ($doFlush) {
            $this->em->flush();
        }

        // Create the objects for the new pageparts
        $this->newPageParts = [];
        $newRefIds = $request->request->all($this->context . '_new');

        if (\is_array($newRefIds)) {
            foreach ($newRefIds as $newId) {
                $type = $request->request->get($this->context . '_type_' . $newId);
                $this->newPageParts[$newId] = new $type();
            }
        }

        // Sort pageparts again
        $sequences = $request->request->all($this->context . '_sequence');
        if ($sequences !== []) {
            $tempPageparts = $this->pageParts;
            $this->pageParts = [];
            foreach ($sequences as $sequence) {
                if (\array_key_exists($sequence, $this->newPageParts)) {
                    $this->pageParts[$sequence] = $this->newPageParts[$sequence];
                } elseif (\array_key_exists($sequence, $tempPageparts)) {
                    $this->pageParts[$sequence] = $tempPageparts[$sequence];
                } else {
                    $this->pageParts[$sequence] = $this->getPagePart($sequence, array_search($sequence, $sequences) + 1);
                }
            }

            unset($tempPageparts);
        }
    }

    public function bindRequest(Request $request)
    {
    }

    public function adaptForm(FormBuilderInterface $formbuilder)
    {
        $data = $formbuilder->getData();

        foreach ($this->pageParts as $pagePartRefId => $pagePart) {
            $data['pagepartadmin_' . $pagePartRefId] = $pagePart;
            $formbuilder->add('pagepartadmin_' . $pagePartRefId, $pagePart->getDefaultAdminType());
        }

        foreach ($this->newPageParts as $newPagePartRefId => $newPagePart) {
            $data['pagepartadmin_' . $newPagePartRefId] = $newPagePart;
            $formbuilder->add('pagepartadmin_' . $newPagePartRefId, $newPagePart->getDefaultAdminType());
        }

        $formbuilder->setData($data);
    }

    public function persist(Request $request)
    {
        /** @var PagePartRefRepository $ppRefRepo */
        $ppRefRepo = $this->em->getRepository(PagePartRef::class);

        // Add new pageparts on the correct position + Re-order and save pageparts if needed
        $sequences = $request->request->all($this->context . '_sequence');
        $sequencescount = \count($sequences);
        for ($i = 0; $i < $sequencescount; ++$i) {
            $pagePartRefId = $sequences[$i];

            if (\array_key_exists($pagePartRefId, $this->newPageParts)) {
                $pagePart = $this->newPageParts[$pagePartRefId];
                $this->em->persist($pagePart);
                $this->em->flush();

                $ppRefRepo->addPagePart($this->page, $pagePart, $i + 1, $this->context, false);
            } elseif (\array_key_exists($pagePartRefId, $this->pagePartRefs)) {
                $pagePartRef = $this->pagePartRefs[$pagePartRefId];
                if ($pagePartRef instanceof PagePartRef && $pagePartRef->getSequencenumber() != ($i + 1)) {
                    $pagePartRef->setSequencenumber($i + 1);
                    $pagePartRef->setContext($this->context);
                    $this->em->persist($pagePartRef);
                }
                $pagePart = $pagePartRef->getPagePart($this->em);
            }

            if (isset($pagePart)) {
                $this->container->get('event_dispatcher')->dispatch(new PagePartEvent($pagePart, $this->page), Events::POST_PERSIST);
            }
        }
    }

    /**
     * @return string|null
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * This getter returns an array holding info on page part types that can be added to the page.
     * The types are filtererd here, based on the amount of page parts of a certain type that can be added to the page.
     *
     * @return array
     */
    public function getPossiblePagePartTypes()
    {
        $possiblePPTypes = $this->configurator->getPossiblePagePartTypes();
        $result = [];

        // filter page part types that can only be added x times to the page context.
        // to achieve this, provide a 'pagelimit' parameter when adding the pp type in your PagePartAdminConfiguration
        if (!empty($possiblePPTypes)) {
            foreach ($possiblePPTypes as $possibleTypeData) {
                if (\array_key_exists('pagelimit', $possibleTypeData)) {
                    $pageLimit = $possibleTypeData['pagelimit'];
                    /** @var PagePartRefRepository $entityRepository */
                    $entityRepository = $this->em->getRepository(PagePartRef::class);
                    $formPPCount = $entityRepository->countPagePartsOfType(
                        $this->page,
                        $possibleTypeData['class'],
                        $this->configurator->getContext()
                    );
                    if ($formPPCount < $pageLimit) {
                        $result[] = $possibleTypeData;
                    }
                } else {
                    $result[] = $possibleTypeData;
                }
            }
        }

        return $result;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->configurator->getName();
    }

    /**
     * @return array
     */
    public function getPagePartMap()
    {
        return $this->pageParts;
    }

    /**
     * @return string
     */
    public function getType(PagePartInterface $pagepart)
    {
        $possiblePagePartTypes = $this->configurator->getPossiblePagePartTypes();
        foreach ($possiblePagePartTypes as &$pageparttype) {
            if ($pageparttype['class'] == ClassLookup::getClass($pagepart)) {
                return $pageparttype['name'];
            }
        }

        return 'no name';
    }

    /**
     * @param int $id
     * @param int $sequenceNumber
     *
     * @return PagePartInterface
     */
    public function getPagePart($id, $sequenceNumber)
    {
        /** @var PagePartRefRepository $ppRefRepo */
        $ppRefRepo = $this->em->getRepository(PagePartRef::class);

        return $ppRefRepo->getPagePart($id, $this->context, $sequenceNumber);
    }

    /**
     * @param object $pagepart
     *
     * @return string
     */
    public function getClassName($pagepart)
    {
        return \get_class($pagepart);
    }

    private function getDeleteInfo(array $deleteKeyMatches): ?PagePartDeleteInfo
    {
        $currentItem = array_shift($deleteKeyMatches);
        if (null === $currentItem) {
            return null;
        }

        return new PagePartDeleteInfo($currentItem[1], $currentItem[2], $this->getDeleteInfo($deleteKeyMatches));
    }

    private function getObjectForDeletion($obj, PagePartDeleteInfo $deleteInfo): ?object
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        /** @var Collection<EntityInterface> $objects */
        $objects = $propertyAccessor->getValue($obj, $deleteInfo->getName());
        $object = null;
        if ($deleteInfo->hasNestedDeleteInfo()) {
            // When a nested item is deleted the id passed is the collection array key instead of the entity id.
            $object = $objects->get($deleteInfo->getId());
        } else {
            foreach ($objects as $data) {
                if ($data->getId() == $deleteInfo->getId()) {
                    $object = $data;
                    break;
                }
            }
        }

        if ($object === null) {
            return null;
        }

        if (!$deleteInfo->hasNestedDeleteInfo()) {
            return $object;
        }

        return $this->getObjectForDeletion($object, $deleteInfo->getNestedDeleteInfo());
    }
}
