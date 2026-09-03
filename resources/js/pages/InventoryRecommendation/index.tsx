import { Link, router } from '@inertiajs/react';
import { Check, GitBranch, RefreshCw, X } from 'lucide-react';
import { useState } from 'react';
import InventoryRecommendationController from '@/actions/App/Http/Controllers/InventoryRecommendationController';
import type { Column } from '@/components/data-table';
import DataTable from '@/components/data-table';
import FormField from '@/components/form-field';
import Modal from '@/components/modal';
import PageHeader from '@/components/page-header';
import Spinner from '@/components/spinner';
import StatusBadge from '@/components/status-badge';
import TableFilters from '@/components/table-filters';
import {
    centralAllocation,
    generate,
    index,
} from '@/routes/inventory-recommendation';
import type { Option, SkuOption } from '@/types/catalog';
import type { InventoryRecommendation } from '@/types/inventory-optimization';
import type { Paginated, StatusTone } from '@/types/ui';

type Filters = {
    search?: string;
    warehouse_id?: string;
    sku_id?: string;
    status?: string;
    recommendation_type?: string;
    stockout_risk?: string;
    overstock_risk?: string;
};

type Props = {
    recommendations: Paginated<InventoryRecommendation>;
    filters: Filters;
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
};

const STATUS_TONES: Record<InventoryRecommendation['status'], StatusTone> = {
    NEW: 'secondary',
    REVIEWED: 'info',
    ACCEPTED: 'success',
    MODIFIED: 'primary',
    REJECTED: 'danger',
    COMPLETED: 'success',
};

const RISK_TONES: Record<string, StatusTone> = {
    NONE: 'success',
    MODERATE: 'warning',
    CRITICAL: 'danger',
};

const TYPE_TONES: Record<
    InventoryRecommendation['recommendation_type'],
    StatusTone
> = {
    PURCHASE: 'primary',
    REDUCE_PURCHASE: 'warning',
    DO_NOT_REORDER: 'secondary',
    CLEARANCE: 'danger',
    TRANSFER_STOCK: 'info',
    PROMOTE: 'info',
    DISCOUNT: 'info',
    RETURN_TO_SUPPLIER: 'secondary',
    REVIEW_PRODUCT: 'secondary',
};

function ageingRiskTone(score: number): StatusTone {
    if (score >= 75) {
        return 'danger';
    }

    if (score >= 50) {
        return 'warning';
    }

    return 'success';
}

export default function InventoryRecommendationIndex({
    recommendations,
    filters,
    warehouseOptions,
    skuOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id ?? '');
    const [skuId, setSkuId] = useState(filters.sku_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [recommendationType, setRecommendationType] = useState(
        filters.recommendation_type ?? '',
    );
    const [stockoutRisk, setStockoutRisk] = useState(
        filters.stockout_risk ?? '',
    );
    const [overstockRisk, setOverstockRisk] = useState(
        filters.overstock_risk ?? '',
    );
    const [generating, setGenerating] = useState(false);
    const [modifyTarget, setModifyTarget] =
        useState<InventoryRecommendation | null>(null);
    const [modifyQty, setModifyQty] = useState('');
    const [modifyReason, setModifyReason] = useState('');
    const [rejectTarget, setRejectTarget] =
        useState<InventoryRecommendation | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [processingId, setProcessingId] = useState<number | null>(null);

    function reload(overrides: Record<string, string | number | undefined>) {
        router.get(
            index.url(),
            {
                search: search || undefined,
                warehouse_id: warehouseId || undefined,
                sku_id: skuId || undefined,
                status: status || undefined,
                recommendation_type: recommendationType || undefined,
                stockout_risk: stockoutRisk || undefined,
                overstock_risk: overstockRisk || undefined,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    }

    function runGenerate() {
        setGenerating(true);
        router.post(
            generate.url(),
            {},
            { preserveScroll: true, onFinish: () => setGenerating(false) },
        );
    }

    function runAccept(id: number) {
        setProcessingId(id);
        router.post(
            InventoryRecommendationController.accept.url(id),
            {},
            { preserveScroll: true, onFinish: () => setProcessingId(null) },
        );
    }

    function submitModify() {
        if (!modifyTarget) {
            return;
        }

        setProcessingId(modifyTarget.id);
        router.post(
            InventoryRecommendationController.modify.url(modifyTarget.id),
            { decided_qty: modifyQty, reason: modifyReason || undefined },
            {
                preserveScroll: true,
                onFinish: () => setProcessingId(null),
                onSuccess: () => setModifyTarget(null),
            },
        );
    }

    function submitReject() {
        if (!rejectTarget) {
            return;
        }

        setProcessingId(rejectTarget.id);
        router.post(
            InventoryRecommendationController.reject.url(rejectTarget.id),
            { reason: rejectReason || undefined },
            {
                preserveScroll: true,
                onFinish: () => setProcessingId(null),
                onSuccess: () => setRejectTarget(null),
            },
        );
    }

    const columns: Column<InventoryRecommendation>[] = [
        {
            key: 'sku',
            header: 'SKU',
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.sku?.sku ?? '—'}</p>
                    <p className="app-text-muted small mb-0">
                        {row.sku?.product_name ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'warehouse',
            header: 'Warehouse',
            cell: (row) => (
                <div>
                    <p className="mb-0">{row.warehouse?.name ?? '—'}</p>
                    {row.recommendation_type === 'TRANSFER_STOCK' &&
                        row.source_warehouse && (
                            <p className="app-text-muted small mb-0">
                                from {row.source_warehouse.name}
                            </p>
                        )}
                </div>
            ),
        },
        {
            key: 'recommendation_type',
            header: 'Type',
            cell: (row) => (
                <StatusBadge
                    tone={TYPE_TONES[row.recommendation_type] ?? 'secondary'}
                    label={row.recommendation_type_label}
                />
            ),
        },
        {
            key: 'current_qty',
            header: 'Current',
            numeric: true,
            cell: (row) => row.current_qty,
        },
        {
            key: 'incoming_qty',
            header: 'Incoming',
            numeric: true,
            cell: (row) => row.incoming_qty,
        },
        {
            key: 'forecast_30d',
            header: '30d Forecast',
            numeric: true,
            cell: (row) => row.forecast_30d?.toFixed(0) ?? '—',
        },
        {
            key: 'recommended_qty',
            header: 'Recommended',
            numeric: true,
            cell: (row) => (
                <div>
                    <p className="fw-semibold mb-0">{row.recommended_qty}</p>
                    {row.decided_qty !== null &&
                        row.decided_qty !== row.recommended_qty && (
                            <p className="app-text-muted small mb-0">
                                Decided: {row.decided_qty}
                            </p>
                        )}
                </div>
            ),
        },
        {
            key: 'recommended_action_date',
            header: 'By',
            cell: (row) => row.recommended_action_date,
        },
        {
            key: 'risks',
            header: 'Risks',
            cell: (row) => {
                const badges = [];

                if (row.stockout_risk && row.stockout_risk !== 'NONE') {
                    badges.push(
                        <StatusBadge
                            key="stockout"
                            tone={RISK_TONES[row.stockout_risk] ?? 'secondary'}
                            label={`Stockout: ${row.stockout_risk_label}`}
                        />,
                    );
                }

                if (row.overstock_risk && row.overstock_risk !== 'NONE') {
                    badges.push(
                        <StatusBadge
                            key="overstock"
                            tone={RISK_TONES[row.overstock_risk] ?? 'secondary'}
                            label={`Overstock: ${row.overstock_risk_label}`}
                        />,
                    );
                }

                if (row.ageing_risk !== null && row.ageing_risk >= 50) {
                    badges.push(
                        <StatusBadge
                            key="ageing"
                            tone={ageingRiskTone(row.ageing_risk)}
                            label={`Ageing: ${row.ageing_risk}/100`}
                        />,
                    );
                }

                return badges.length > 0 ? (
                    <div className="d-flex flex-column gap-1 align-items-start">
                        {badges}
                    </div>
                ) : (
                    '—'
                );
            },
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusBadge
                    tone={STATUS_TONES[row.status]}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            cell: (row) =>
                row.status === 'NEW' || row.status === 'REVIEWED' ? (
                    <div className="d-flex gap-1">
                        <button
                            type="button"
                            className="btn btn-sm btn-success"
                            disabled={processingId === row.id}
                            onClick={() => runAccept(row.id)}
                            aria-label="Accept recommendation"
                        >
                            {processingId === row.id ? (
                                <Spinner size="sm" />
                            ) : (
                                <Check aria-hidden="true" />
                            )}
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-surface"
                            disabled={processingId === row.id}
                            onClick={() => {
                                setModifyTarget(row);
                                setModifyQty(String(row.recommended_qty));
                                setModifyReason('');
                            }}
                        >
                            Modify
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-danger"
                            disabled={processingId === row.id}
                            onClick={() => {
                                setRejectTarget(row);
                                setRejectReason('');
                            }}
                            aria-label="Reject recommendation"
                        >
                            <X aria-hidden="true" />
                        </button>
                    </div>
                ) : (
                    <span className="app-text-muted small">
                        {row.decision_reason ?? '—'}
                    </span>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                eyebrow="Forecasting"
                title="Purchase recommendations"
                description="What to buy, how much to reduce, what to clear, or what to transfer between warehouses instead — from forecast, stock, supplier terms and ageing/overstock risk. Requires a completed forecast for a SKU before it can be recommended."
                actions={
                    <div className="d-flex gap-2">
                        <Link
                            href={centralAllocation.url()}
                            className="btn btn-surface"
                        >
                            <GitBranch aria-hidden="true" />
                            Central allocation
                        </Link>
                        <button
                            type="button"
                            className="btn btn-gradient"
                            disabled={generating}
                            onClick={runGenerate}
                        >
                            {generating ? (
                                <Spinner size="sm" />
                            ) : (
                                <RefreshCw aria-hidden="true" />
                            )}
                            Generate recommendations
                        </button>
                    </div>
                }
            />

            <DataTable
                columns={columns}
                rows={recommendations.data}
                rowKey={(row) => row.id}
                sort={null}
                pagination={recommendations}
                onPageChange={(page) => reload({ page })}
                onPerPageChange={(perPage) =>
                    reload({ per_page: perPage, page: 1 })
                }
                emptyTitle="No open recommendations"
                emptyDescription="Generate recommendations to see what needs reordering."
                toolbar={
                    <TableFilters
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by SKU…"
                        canReset={
                            search !== '' ||
                            warehouseId !== '' ||
                            skuId !== '' ||
                            status !== '' ||
                            recommendationType !== '' ||
                            stockoutRisk !== '' ||
                            overstockRisk !== ''
                        }
                        onReset={() => {
                            setSearch('');
                            setWarehouseId('');
                            setSkuId('');
                            setStatus('');
                            setRecommendationType('');
                            setStockoutRisk('');
                            setOverstockRisk('');
                            reload({
                                search: undefined,
                                warehouse_id: undefined,
                                sku_id: undefined,
                                status: undefined,
                                recommendation_type: undefined,
                                stockout_risk: undefined,
                                overstock_risk: undefined,
                                page: 1,
                            });
                        }}
                    >
                        <select
                            className="form-select"
                            value={warehouseId}
                            onChange={(event) => {
                                setWarehouseId(event.target.value);
                                reload({
                                    warehouse_id:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by warehouse"
                        >
                            <option value="">All warehouses</option>
                            {warehouseOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={skuId}
                            onChange={(event) => {
                                setSkuId(event.target.value);
                                reload({
                                    sku_id: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by SKU"
                        >
                            <option value="">All SKUs</option>
                            {skuOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.sku}
                                </option>
                            ))}
                        </select>

                        <select
                            className="form-select"
                            value={status}
                            onChange={(event) => {
                                setStatus(event.target.value);
                                reload({
                                    status: event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by status"
                        >
                            <option value="">All statuses</option>
                            <option value="NEW">New</option>
                            <option value="REVIEWED">Reviewed</option>
                            <option value="ACCEPTED">Accepted</option>
                            <option value="MODIFIED">Modified</option>
                            <option value="REJECTED">Rejected</option>
                            <option value="COMPLETED">Completed</option>
                        </select>

                        <select
                            className="form-select"
                            value={recommendationType}
                            onChange={(event) => {
                                setRecommendationType(event.target.value);
                                reload({
                                    recommendation_type:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by recommendation type"
                        >
                            <option value="">All types</option>
                            <option value="PURCHASE">Purchase</option>
                            <option value="REDUCE_PURCHASE">
                                Reduce purchase
                            </option>
                            <option value="DO_NOT_REORDER">
                                Do not reorder
                            </option>
                            <option value="TRANSFER_STOCK">
                                Transfer stock
                            </option>
                            <option value="CLEARANCE">Clearance</option>
                        </select>

                        <select
                            className="form-select"
                            value={stockoutRisk}
                            onChange={(event) => {
                                setStockoutRisk(event.target.value);
                                reload({
                                    stockout_risk:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by stockout risk"
                        >
                            <option value="">Any stockout risk</option>
                            <option value="CRITICAL">Critical</option>
                            <option value="MODERATE">Moderate</option>
                            <option value="NONE">None</option>
                        </select>

                        <select
                            className="form-select"
                            value={overstockRisk}
                            onChange={(event) => {
                                setOverstockRisk(event.target.value);
                                reload({
                                    overstock_risk:
                                        event.target.value || undefined,
                                    page: 1,
                                });
                            }}
                            aria-label="Filter by overstock risk"
                        >
                            <option value="">Any overstock risk</option>
                            <option value="CRITICAL">Critical</option>
                            <option value="MODERATE">Moderate</option>
                            <option value="NONE">None</option>
                        </select>
                    </TableFilters>
                }
            />

            <Modal
                open={modifyTarget !== null}
                onClose={() => setModifyTarget(null)}
                title="Modify recommendation"
                description={
                    modifyTarget
                        ? `${modifyTarget.sku?.sku ?? 'This SKU'} — engine recommended ${modifyTarget.recommended_qty}.`
                        : undefined
                }
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="btn btn-surface"
                            onClick={() => setModifyTarget(null)}
                            disabled={processingId === modifyTarget?.id}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn btn-gradient"
                            onClick={submitModify}
                            disabled={processingId === modifyTarget?.id}
                        >
                            {processingId === modifyTarget?.id && (
                                <Spinner size="sm" />
                            )}
                            Save
                        </button>
                    </>
                }
            >
                <FormField id="decided_qty" label="Quantity to order">
                    <input
                        id="decided_qty"
                        type="number"
                        min="0"
                        className="form-control"
                        value={modifyQty}
                        onChange={(event) => setModifyQty(event.target.value)}
                    />
                </FormField>
                <FormField
                    id="modify_reason"
                    label="Reason"
                    hint="e.g. Supplier issue, Promotion cancelled, Budget limitation"
                >
                    <input
                        id="modify_reason"
                        type="text"
                        className="form-control"
                        value={modifyReason}
                        onChange={(event) =>
                            setModifyReason(event.target.value)
                        }
                    />
                </FormField>
            </Modal>

            <Modal
                open={rejectTarget !== null}
                onClose={() => setRejectTarget(null)}
                title="Reject recommendation"
                description={
                    rejectTarget
                        ? `${rejectTarget.sku?.sku ?? 'This SKU'} will not be purchased based on this recommendation.`
                        : undefined
                }
                icon={X}
                iconTone="danger"
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="btn btn-surface"
                            onClick={() => setRejectTarget(null)}
                            disabled={processingId === rejectTarget?.id}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn btn-danger"
                            onClick={submitReject}
                            disabled={processingId === rejectTarget?.id}
                        >
                            {processingId === rejectTarget?.id && (
                                <Spinner size="sm" />
                            )}
                            Reject
                        </button>
                    </>
                }
            >
                <FormField id="reject_reason" label="Reason (optional)">
                    <input
                        id="reject_reason"
                        type="text"
                        className="form-control"
                        value={rejectReason}
                        onChange={(event) =>
                            setRejectReason(event.target.value)
                        }
                    />
                </FormField>
            </Modal>
        </>
    );
}
