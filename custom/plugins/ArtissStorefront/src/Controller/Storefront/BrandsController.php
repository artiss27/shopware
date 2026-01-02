<?php declare(strict_types=1);

namespace ArtissStorefront\Controller\Storefront;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BrandsController extends StorefrontController
{
    public function __construct(
        private readonly EntityRepository $manufacturerRepository
    ) {
    }

    #[Route(
        path: '/brands',
        name: 'frontend.brands.page',
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $criteria = new Criteria();
        $criteria->addAssociation('media');
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $manufacturers = $this->manufacturerRepository->search($criteria, $context->getContext());

        return $this->renderStorefront('@ArtissStorefront/storefront/page/brands/index.html.twig', [
            'manufacturers' => $manufacturers,
            'page' => [
                'metaTitle' => 'Всі бренди',
                'metaDescription' => 'Список всіх брендів',
            ]
        ]);
    }
}
