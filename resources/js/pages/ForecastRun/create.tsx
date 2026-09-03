import { Form, Head } from '@inertiajs/react';
import ForecastRunController from '@/actions/App/Http/Controllers/ForecastRunController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option } from '@/types/catalog';

type Props = {
    warehouseOptions: Option[];
};

const HORIZONS = [7, 14, 30, 60, 90, 180];

export default function ForecastRunCreate({ warehouseOptions }: Props) {
    return (
        <>
            <Head title="New forecast run" />

            <PageHeader
                eyebrow="Forecasting"
                title="New forecast run"
                description="Queues a background job that asks the ML service for a demand prediction per SKU. Leave warehouses unchecked to forecast all of them."
            />

            <SectionCard>
                <Form {...ForecastRunController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <FormField
                                id="horizon_days"
                                label="Forecast horizon"
                                error={errors.horizon_days}
                                required
                            >
                                <select
                                    id="horizon_days"
                                    name="horizon_days"
                                    className="form-select"
                                    defaultValue={30}
                                    required
                                >
                                    {HORIZONS.map((days) => (
                                        <option key={days} value={days}>
                                            {days} days
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField
                                label="Warehouses"
                                error={errors.warehouse_ids}
                                hint="Optional — leave all unchecked to include every warehouse."
                            >
                                <div className="app-stack">
                                    {warehouseOptions.map((option) => (
                                        <div
                                            key={option.id}
                                            className="form-check"
                                        >
                                            <input
                                                id={`warehouse-${option.id}`}
                                                type="checkbox"
                                                className="form-check-input"
                                                name="warehouse_ids[]"
                                                value={option.id}
                                            />
                                            <label
                                                className="form-check-label"
                                                htmlFor={`warehouse-${option.id}`}
                                            >
                                                {option.name}
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-forecast-run-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Queue forecast run
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
