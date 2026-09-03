import { Form, Head, router } from '@inertiajs/react';
import SalesReturnController from '@/actions/App/Http/Controllers/SalesReturnController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import { create } from '@/routes/sales-return';
import type {
    OutstandingSalesOrderItem,
    ReturnCondition,
    SalesOrderOption,
    StatusOption,
} from '@/types/sales-purchasing';

type Props = {
    salesOrderOptions: SalesOrderOption[];
    salesOrderId: number | null;
    outstandingItems: OutstandingSalesOrderItem[];
    conditions: StatusOption<ReturnCondition>[];
};

export default function SalesReturnCreate({
    salesOrderOptions,
    salesOrderId,
    outstandingItems,
    conditions,
}: Props) {
    return (
        <>
            <Head title="Record sales return" />

            <PageHeader
                eyebrow="Sales"
                title="Record sales return"
                description="Log stock coming back against a confirmed sales order."
            />

            <SectionCard>
                <div className="app-stack">
                    <FormField id="sales_order_picker" label="Sales order">
                        <select
                            id="sales_order_picker"
                            className="form-select"
                            value={salesOrderId ?? ''}
                            onChange={(event) =>
                                router.get(create.url(), {
                                    sales_order_id:
                                        event.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Select a sales order</option>
                            {salesOrderOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.order_number}
                                </option>
                            ))}
                        </select>
                    </FormField>

                    {salesOrderId !== null && outstandingItems.length === 0 && (
                        <p className="app-text-muted">
                            Every line on this order has already been returned
                            in full.
                        </p>
                    )}

                    {salesOrderId !== null && outstandingItems.length > 0 && (
                        <Form
                            {...SalesReturnController.store.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="app-stack">
                                    <input
                                        type="hidden"
                                        name="sales_order_id"
                                        value={salesOrderId}
                                    />

                                    <FormField
                                        id="return_date"
                                        label="Return date"
                                        error={errors.return_date}
                                        required
                                    >
                                        <input
                                            id="return_date"
                                            name="return_date"
                                            type="date"
                                            className="form-control"
                                            defaultValue={
                                                new Date()
                                                    .toISOString()
                                                    .split('T')[0]
                                            }
                                            required
                                        />
                                    </FormField>

                                    <FormField
                                        id="reason"
                                        label="Reason"
                                        error={errors.reason}
                                        hint="Optional"
                                    >
                                        <input
                                            id="reason"
                                            name="reason"
                                            type="text"
                                            className="form-control"
                                        />
                                    </FormField>

                                    <FormField
                                        label="Returned lines"
                                        error={errors.items}
                                    >
                                        <div className="table-responsive">
                                            <table className="table app-table">
                                                <thead>
                                                    <tr>
                                                        <th>SKU</th>
                                                        <th>Product</th>
                                                        <th className="text-end">
                                                            Sold
                                                        </th>
                                                        <th
                                                            style={{
                                                                width: '8rem',
                                                            }}
                                                        >
                                                            Returning now
                                                        </th>
                                                        <th
                                                            style={{
                                                                width: '10rem',
                                                            }}
                                                        >
                                                            Condition
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {outstandingItems.map(
                                                        (item, index) => (
                                                            <tr key={item.id}>
                                                                <td>
                                                                    <input
                                                                        type="hidden"
                                                                        name={`items[${index}][sales_order_item_id]`}
                                                                        value={
                                                                            item.id
                                                                        }
                                                                    />
                                                                    {item.sku}
                                                                </td>
                                                                <td>
                                                                    {
                                                                        item.product_name
                                                                    }
                                                                </td>
                                                                <td className="text-end">
                                                                    {
                                                                        item.remaining_qty
                                                                    }
                                                                </td>
                                                                <td>
                                                                    <input
                                                                        name={`items[${index}][quantity]`}
                                                                        type="number"
                                                                        min={0}
                                                                        max={
                                                                            item.remaining_qty
                                                                        }
                                                                        className="form-control"
                                                                        defaultValue={
                                                                            item.remaining_qty
                                                                        }
                                                                        aria-label={`Quantity returned for ${item.sku}`}
                                                                    />
                                                                </td>
                                                                <td>
                                                                    <select
                                                                        name={`items[${index}][condition]`}
                                                                        className="form-select"
                                                                        defaultValue="sellable"
                                                                        aria-label={`Condition for ${item.sku}`}
                                                                    >
                                                                        {conditions.map(
                                                                            (
                                                                                option,
                                                                            ) => (
                                                                                <option
                                                                                    key={
                                                                                        option.value
                                                                                    }
                                                                                    value={
                                                                                        option.value
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        option.label
                                                                                    }
                                                                                </option>
                                                                            ),
                                                                        )}
                                                                    </select>
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </FormField>

                                    <div className="app-form-actions">
                                        <button
                                            type="submit"
                                            className="btn btn-gradient"
                                            disabled={processing}
                                            data-test="create-sales-return-button"
                                        >
                                            {processing && (
                                                <Spinner size="sm" />
                                            )}
                                            Record return
                                        </button>
                                    </div>
                                </div>
                            )}
                        </Form>
                    )}
                </div>
            </SectionCard>
        </>
    );
}
