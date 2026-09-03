import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Award,
    BarChart3,
    Boxes,
    CalendarClock,
    ClipboardList,
    Copy,
    DollarSign,
    Factory,
    Gauge,
    GitBranch,
    Layers,
    LayoutDashboard,
    ListChecks,
    ListTree,
    PackageCheck,
    PackagePlus,
    PackageSearch,
    Percent,
    Replace,
    Settings,
    ShieldCheck,
    Shuffle,
    ShoppingCart,
    SlidersHorizontal,
    Sparkles,
    Split,
    Swords,
    Tags,
    TrendingDown,
    TrendingUp,
    Undo2,
    Warehouse as WarehouseIcon,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as attributeIndex } from '@/routes/attribute';
import { index as brandIndex } from '@/routes/brand';
import { index as categoryIndex } from '@/routes/category';
import {
    anomalies as demandInsightsAnomalies,
    lostSales as demandInsightsLostSales,
} from '@/routes/demand-insights';
import { index as forecastIndex } from '@/routes/forecast';
import { index as forecastRunIndex } from '@/routes/forecast-run';
import { index as goodsReceiptIndex } from '@/routes/goods-receipt';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as inventoryAnalyticsIndex } from '@/routes/inventory-analytics';
import { index as inventoryBatchIndex } from '@/routes/inventory-batch';
import { index as inventoryDailySnapshotIndex } from '@/routes/inventory-daily-snapshot';
import {
    centralAllocation as inventoryRecommendationCentralAllocation,
    index as inventoryRecommendationIndex,
} from '@/routes/inventory-recommendation';
import { index as priceElasticityIndex } from '@/routes/price-elasticity';
import { index as productIndex } from '@/routes/product';
import {
    cannibalization as productRelationshipCannibalization,
    similarity as productRelationshipSimilarity,
    successors as productRelationshipSuccessors,
} from '@/routes/product-relationship';
import { index as productVariantIndex } from '@/routes/product-variant';
import { edit as editProfile } from '@/routes/profile';
import { index as promotionIndex } from '@/routes/promotion';
import { index as purchaseOrderIndex } from '@/routes/purchase-order';
import { index as salesOrderIndex } from '@/routes/sales-order';
import { index as salesReturnIndex } from '@/routes/sales-return';
import { edit as editSecurity } from '@/routes/security';
import { index as skuIndex } from '@/routes/sku';
import {
    create as createStockMovement,
    index as stockMovementIndex,
} from '@/routes/stock-movement';
import { index as stockTransferIndex } from '@/routes/stock-transfer';
import { index as supplierIndex } from '@/routes/supplier';
import { index as supplierPerformanceIndex } from '@/routes/supplier-performance';
import { index as warehouseIndex } from '@/routes/warehouse';
import type { NavItem } from '@/types';

type NavGroup = {
    label: string;
    items: NavItem[];
};

/**
 * Add each new module's index route to the group it belongs to. Keep the list
 * short — anything rarely used belongs on a page, not in the sidebar.
 */
const navGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutDashboard,
            },
        ],
    },
    {
        label: 'Catalog',
        items: [
            {
                title: 'Products',
                href: productIndex(),
                icon: PackageSearch,
            },
            {
                title: 'Variants',
                href: productVariantIndex(),
                icon: GitBranch,
            },
            {
                title: 'SKUs',
                href: skuIndex(),
                icon: Tags,
            },
            {
                title: 'Categories',
                href: categoryIndex(),
                icon: ListTree,
            },
            {
                title: 'Brands',
                href: brandIndex(),
                icon: Award,
            },
            {
                title: 'Attributes',
                href: attributeIndex(),
                icon: SlidersHorizontal,
            },
        ],
    },
    {
        label: 'Inventory',
        items: [
            {
                title: 'Inventory',
                href: inventoryIndex(),
                icon: Boxes,
            },
            {
                title: 'Analytics',
                href: inventoryAnalyticsIndex(),
                icon: BarChart3,
            },
            {
                title: 'Batches',
                href: inventoryBatchIndex(),
                icon: Layers,
            },
            {
                title: 'Daily snapshots',
                href: inventoryDailySnapshotIndex(),
                icon: CalendarClock,
            },
            {
                title: 'Stock movements',
                href: stockMovementIndex(),
                icon: ArrowLeftRight,
            },
            {
                title: 'Adjustments',
                href: createStockMovement(),
                icon: PackagePlus,
            },
            {
                title: 'Stock transfers',
                href: stockTransferIndex(),
                icon: Shuffle,
            },
            {
                title: 'Warehouses',
                href: warehouseIndex(),
                icon: WarehouseIcon,
            },
        ],
    },
    {
        label: 'Purchasing',
        items: [
            {
                title: 'Purchase orders',
                href: purchaseOrderIndex(),
                icon: ClipboardList,
            },
            {
                title: 'Goods receipts',
                href: goodsReceiptIndex(),
                icon: PackageCheck,
            },
            {
                title: 'Suppliers',
                href: supplierIndex(),
                icon: Factory,
            },
        ],
    },
    {
        label: 'Sales',
        items: [
            {
                title: 'Sales orders',
                href: salesOrderIndex(),
                icon: ShoppingCart,
            },
            {
                title: 'Sales returns',
                href: salesReturnIndex(),
                icon: Undo2,
            },
        ],
    },
    {
        label: 'Forecasting',
        items: [
            {
                title: 'Forecasts',
                href: forecastIndex(),
                icon: TrendingUp,
            },
            {
                title: 'Forecast runs',
                href: forecastRunIndex(),
                icon: Sparkles,
            },
            {
                title: 'Recommendations',
                href: inventoryRecommendationIndex(),
                icon: ListChecks,
            },
            {
                title: 'Central allocation',
                href: inventoryRecommendationCentralAllocation(),
                icon: Split,
            },
        ],
    },
    {
        label: 'Advanced intelligence',
        items: [
            {
                title: 'Supplier performance',
                href: supplierPerformanceIndex(),
                icon: Gauge,
            },
            {
                title: 'Lost sales',
                href: demandInsightsLostSales(),
                icon: TrendingDown,
            },
            {
                title: 'Demand anomalies',
                href: demandInsightsAnomalies(),
                icon: AlertTriangle,
            },
            {
                title: 'Product similarity',
                href: productRelationshipSimilarity(),
                icon: Copy,
            },
            {
                title: 'Cannibalization',
                href: productRelationshipCannibalization(),
                icon: Swords,
            },
            {
                title: 'Successors',
                href: productRelationshipSuccessors(),
                icon: Replace,
            },
            {
                title: 'Price elasticity',
                href: priceElasticityIndex(),
                icon: DollarSign,
            },
            {
                title: 'Promotions',
                href: promotionIndex(),
                icon: Percent,
            },
        ],
    },
    {
        label: 'Account',
        items: [
            {
                title: 'Profile',
                href: editProfile(),
                icon: Settings,
            },
            {
                title: 'Security',
                href: editSecurity(),
                icon: ShieldCheck,
            },
        ],
    },
];

type Props = {
    open: boolean;
    onNavigate: () => void;
};

export default function AppSidebar({ open, onNavigate }: Props) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <aside
            className={cn('app-sidebar', open && 'is-open')}
            aria-label="Main navigation"
        >
            <div className="app-sidebar__header">
                <Link href={dashboard()} onClick={onNavigate}>
                    <AppLogo />
                </Link>
            </div>

            <div className="app-sidebar__body">
                {navGroups.map((group) => (
                    <div key={group.label} className="app-nav-group">
                        <span className="app-nav-group__label">
                            {group.label}
                        </span>

                        <nav>
                            {group.items.map((item) => (
                                <Link
                                    key={toUrl(item.href)}
                                    href={item.href}
                                    onClick={onNavigate}
                                    className={cn(
                                        'app-nav-link',
                                        isCurrentOrParentUrl(item.href) &&
                                            'is-active',
                                    )}
                                    aria-current={
                                        isCurrentOrParentUrl(item.href)
                                            ? 'page'
                                            : undefined
                                    }
                                    title={item.title}
                                >
                                    {item.icon && (
                                        <item.icon aria-hidden="true" />
                                    )}
                                    <span className="app-nav-link__label">
                                        {item.title}
                                    </span>
                                </Link>
                            ))}
                        </nav>
                    </div>
                ))}
            </div>
        </aside>
    );
}
