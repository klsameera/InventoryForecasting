import { Form, Head } from '@inertiajs/react';
import StockMovementController from '@/actions/App/Http/Controllers/StockMovementController';
import Alert from '@/components/alert';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { MovementTypeOption, Option, SkuOption } from '@/types/catalog';

type Props = {
    warehouseOptions: Option[];
    skuOptions: SkuOption[];
    movementTypes: MovementTypeOption[];
};

export default function StockMovementCreate({
    warehouseOptions,
    skuOptions,
    movementTypes,
}: Props) {
    return (
        <>
            <Head title="Record adjustment" />

            <PageHeader
                eyebrow="Inventory"
                title="Record adjustment"
                description="Manually record a stock change. This writes an entry to the ledger and updates the warehouse balance immediately."
            />

            <SectionCard>
                <Form {...StockMovementController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            {Object.keys(errors).length > 0 && (
                                <Alert
                                    tone="danger"
                                    title="Couldn't record this movement"
                                    messages={Object.values(errors)}
                                />
                            )}

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="warehouse_id"
                                        label="Warehouse"
                                        error={errors.warehouse_id}
                                        required
                                    >
                                        <select
                                            id="warehouse_id"
                                            name="warehouse_id"
                                            className="form-select"
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a warehouse
                                            </option>
                                            {warehouseOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="sku_id"
                                        label="SKU"
                                        error={errors.sku_id}
                                        required
                                    >
                                        <select
                                            id="sku_id"
                                            name="sku_id"
                                            className="form-select"
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a SKU
                                            </option>
                                            {skuOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.sku}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                </div>
                            </div>

                            <FormField
                                id="movement_type"
                                label="Movement type"
                                error={errors.movement_type}
                                required
                            >
                                <select
                                    id="movement_type"
                                    name="movement_type"
                                    className="form-select"
                                    defaultValue=""
                                    required
                                >
                                    <option value="" disabled>
                                        Select a movement type
                                    </option>
                                    {movementTypes.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <div className="row g-3">
                                <div className="col-md-6">
                                    <FormField
                                        id="quantity"
                                        label="Quantity"
                                        error={errors.quantity}
                                        required
                                    >
                                        <input
                                            id="quantity"
                                            name="quantity"
                                            type="number"
                                            min={1}
                                            className="form-control"
                                            required
                                        />
                                    </FormField>
                                </div>

                                <div className="col-md-6">
                                    <FormField
                                        id="unit_cost"
                                        label="Unit cost"
                                        error={errors.unit_cost}
                                        hint="Only affects average cost on an inbound movement."
                                    >
                                        <input
                                            id="unit_cost"
                                            name="unit_cost"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            className="form-control"
                                        />
                                    </FormField>
                                </div>
                            </div>

                            <FormField
                                id="occurred_at"
                                label="Occurred at"
                                error={errors.occurred_at}
                                hint="Defaults to now if left blank."
                            >
                                <input
                                    id="occurred_at"
                                    name="occurred_at"
                                    type="datetime-local"
                                    className="form-control"
                                />
                            </FormField>

                            <FormField
                                id="notes"
                                label="Notes"
                                error={errors.notes}
                            >
                                <textarea
                                    id="notes"
                                    name="notes"
                                    className="form-control"
                                    rows={3}
                                />
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-stock-movement-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Record movement
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
