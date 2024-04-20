<?php

namespace Kunstmaan\NodeSearchBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Kunstmaan\NodeBundle\Entity\NodeTranslation;
use Kunstmaan\NodeBundle\Entity\StructureNode;
use Kunstmaan\NodeBundle\Event\NodeEvent;
use Kunstmaan\NodeSearchBundle\Configuration\NodePagesConfiguration;

/**
 * EventListener which will be triggered when a Node has been updated in order to update its related documents
 * in the index
 */
class NodeIndexUpdateEventListener implements NodeIndexUpdateEventListenerInterface
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var NodePagesConfiguration */
    private $nodePagesConfiguration;

    /** @var array */
    private $entityChangeSet;

    public function __construct(NodePagesConfiguration $nodePagesConfiguration, ?EntityManagerInterface $em = null)
    {
        $this->nodePagesConfiguration = $nodePagesConfiguration;
        $this->em = $em;
    }

    public function preUpdate(PreUpdateEventArgs $args)
    {
        if ($args->getObject() instanceof NodeTranslation) {
            // unfortunately we have to keep a state to see what has changed
            $this->entityChangeSet = [
                'nodeTranslationId' => $args->getObject()->getId(),
                'changeSet' => $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($args->getObject()),
            ];
        }
    }

    public function onPostPublish(NodeEvent $event)
    {
        $this->index($event);
    }

    public function onPostPersist(NodeEvent $event)
    {
        $reIndexChildren = (
            !\is_null($this->entityChangeSet)
            && $this->entityChangeSet['nodeTranslationId'] == $event->getNodeTranslation()->getId()
            && isset($this->entityChangeSet['changeSet']['url'])
        );
        $this->index($event, $reIndexChildren);
    }

    /**
     * @param bool $reIndexChildren
     */
    private function index(NodeEvent $event, $reIndexChildren = false)
    {
        $nodeTranslation = $event->getNodeTranslation();

        if ($this->hasOfflineParents($nodeTranslation)) {
            return;
        }

        $this->nodePagesConfiguration->indexNodeTranslation($nodeTranslation, true);

        if ($reIndexChildren) {
            $this->nodePagesConfiguration->indexChildren($event->getNode(), $nodeTranslation->getLang());
        }
    }

    public function onPostDelete(NodeEvent $event)
    {
        $this->delete($event);
    }

    public function onPostUnPublish(NodeEvent $event)
    {
        $this->delete($event);
    }

    public function delete(NodeEvent $event)
    {
        $this->nodePagesConfiguration->deleteNodeTranslation($event->getNodeTranslation());
    }

    private function hasOfflineParents(NodeTranslation $nodeTranslation): bool
    {
        $lang = $nodeTranslation->getLang();
        $node = $nodeTranslation->getNode();
        if (null !== $this->em) {
            $em = $this->em;
        } else {
            $lang = $nodeTranslation->getLang();
            foreach ($nodeTranslation->getNode()->getParents() as $node) {
                $nodeNT = $node->getNodeTranslation($lang, true);
                if ($nodeNT && !$nodeNT->isOnline()) {
                    return true;
                }
            }

            return false;
        }

        foreach ($node->getParents() as $parent) {
            $parentNodeTranslation = $parent->getNodeTranslation($lang, true);
            if (null === $parentNodeTranslation) {
                continue;
            }
            $parentRef = $parentNodeTranslation->getRef($em);
            // Continue looping unless we find an offline page that is not a StructureNode
            if ($parentRef instanceof StructureNode || $parentNodeTranslation->isOnline()) {
                continue;
            }

            return true;
        }

        return false;
    }
}
