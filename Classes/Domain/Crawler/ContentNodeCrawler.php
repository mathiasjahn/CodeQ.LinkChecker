<?php

declare(strict_types=1);

namespace CodeQ\LinkChecker\Domain\Crawler;

use CodeQ\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use CodeQ\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Service\LinkingService;

class ContentNodeCrawler
{
    /**
     * Pattern to match telephone numbers.
     *
     * @var string
     */
    protected const PATTERN_SUPPORTED_PHONE_NUMBERS = '/href="(tel):(\+?\d*)/';

    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

    /**
     * @var RouterInterface
     * @Flow\Inject
     */
    protected $router;

    /**
     * @var NodeDataRepository
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    public function crawl(Context $context, Domain $domain): array
    {
        /** @var Node[] $allContentAndDocumentNodes */
        $allContentAndDocumentNodes = FlowQuery::q([$context->getCurrentSiteNode()])
            ->find('[instanceof Neos.Neos:Document],[instanceof Neos.Neos:Content]')->get();

        $messages = [];

        foreach ($allContentAndDocumentNodes as $node) {
            if (!$this->findIsNodeVisible($node)) {
                continue;
            }

            $nodeData = $node->getNodeData();

            $unresolvedUris = [];
            $invalidPhoneNumbers = [];

            $properties = $nodeData->getProperties();

            foreach ($properties as $property) {
                $this->crawlPropertyForNodesAndAssets($property, $node, $unresolvedUris);
                $this->crawlPropertyForTelephoneNumbers($property, $invalidPhoneNumbers);
            }

            foreach ($unresolvedUris as $uri) {
                $messages[] = 'Not found: ' . $uri;

                $this->createResultItem($context, $domain, $node, $uri, 404);
            }
            foreach ($invalidPhoneNumbers as $phoneNumber) {
                $messages[] = 'Invalid format: ' . $phoneNumber;

                /* @see https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml - 490 is unassigned, and so we can use it */
                $this->createResultItem($context, $domain, $node, $phoneNumber, 490);
            }
        }

        return $messages;
    }

    /**
     * @see \Neos\Neos\Fusion\ConvertUrisImplementation::evaluate
     */
    protected function crawlPropertyForNodesAndAssets(
        $property,
        NodeInterface $nodeOfProperty,
        array &$unresolvedUris
    ): void {
        if (!is_string($property)) {
            return;
        }

        if (!str_contains($property, 'node://') && !str_contains($property, 'asset://')) {
            return;
        }

        preg_replace_callback(
            LinkingService::PATTERN_SUPPORTED_URIS,
            function (array $matches) use (
                $nodeOfProperty,
                &$unresolvedUris
            ) {
                $type = $matches[1];
                $identifier = $matches[0];
                $targetIsVisible = false;
                switch ($type) {
                    case 'node':
                        $linkedNode = $nodeOfProperty->getContext()->getNodeByIdentifier($identifier);
                        $targetIsVisible = $linkedNode && $this->findIsNodeVisible($linkedNode);
                        break;
                    case 'asset':
                        $targetIsVisible = $this->linkingService->resolveAssetUri($identifier) !== null;
                        break;
                }

                if ($targetIsVisible === false) {
                    $unresolvedUris[] = $identifier;
                }

                return "";
            },
            $property
        );
    }

    private function crawlPropertyForTelephoneNumbers(
        $property,
        array &$invalidPhoneNumbers
    ): void {
        if (!is_string($property)) {
            return;
        }

        if (!str_contains($property, 'tel:')) {
            return;
        }

        preg_replace_callback(
            self::PATTERN_SUPPORTED_PHONE_NUMBERS,
            static function (array $matches) use (&$invalidPhoneNumbers) {
                if ($matches[1] === 'tel') {
                    $resolvedUri = str_starts_with($matches[2], '+') ? $matches[2] : null;
                } else {
                    $resolvedUri = null;
                }

                if ($resolvedUri === null) {
                    $invalidPhoneNumbers[] = 'tel:' . $matches[2];
                    return $matches[0];
                }

                return $resolvedUri;
            },
            $property
        );
    }

    protected function createResultItem(
        Context $context,
        Domain $domain,
        NodeInterface $node,
        string $uri,
        int $statusCode
    ): void {
        $documentNode = $this->findClosestDocumentNode($node);
        $nodeData = $documentNode->getNodeData();
        $sourceNodeIdentifier = $nodeData->getIdentifier();
        $sourceNodePath = $nodeData->getPath();

        $resultItem = new ResultItem();
        $resultItem->setDomain($domain->getHostname());
        $resultItem->setSource($sourceNodeIdentifier);
        $resultItem->setSourcePath($sourceNodePath);
        $resultItem->setTarget($uri);

        if (str_starts_with($uri, 'node://')) {
            $this->setTargetNodePath($resultItem, $uri);
        }

        $resultItem->setStatusCode($statusCode);
        $resultItem->setCreatedAt($context->getCurrentDateTime());
        $resultItem->setCheckedAt($context->getCurrentDateTime());

        $this->resultItemRepository->add($resultItem);
    }

    private function findClosestDocumentNode(Node $node): NodeInterface
    {
        while ($node->getNodeType()->isOfType('Neos.Neos:Document') === false) {
            $node = $node->findParentNode();
        }
        return $node;
    }

    private function findIsNodeVisible(Node $node): bool
    {
        do {
            $previousNode = $node;
            $node = $node->getParent();
            if ($node === null) {
                if ($previousNode->isRoot()) {
                    return true;
                }
                return false;
            }
        } while (true);
    }

    private function setTargetNodePath(ResultItem $resultItem, string $uri): void
    {
        preg_match(LinkingService::PATTERN_SUPPORTED_URIS, $uri, $matches);
        $nodeIdentifier = $matches[2];

        $baseContext = $this->createContext('live', []);
        $targetNode = $baseContext->getNodeByIdentifier($nodeIdentifier);

        if (!($targetNode instanceof NodeInterface)) {
            return;
        }

        $targetNodePath = $targetNode->getNodeData()->getPath();
        $resultItem->setTargetPath($targetNodePath);
    }

    private function createContext(string $workspaceName, array $dimensions): Context
    {
        return $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);
    }
}
