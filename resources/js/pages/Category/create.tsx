import { Form, Head } from '@inertiajs/react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Option } from '@/types/catalog';

type Props = {
    parentOptions: Option[];
};

export default function CategoryCreate({ parentOptions }: Props) {
    return (
        <>
            <Head title="New category" />

            <PageHeader
                eyebrow="Catalog"
                title="New category"
                description="Add a category, optionally nested under an existing one."
            />

            <SectionCard>
                <Form {...CategoryController.store.form()}>
                    {({ processing, errors }) => (
                        <div className="app-stack">
                            <FormField
                                id="name"
                                label="Name"
                                error={errors.name}
                                required
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-control"
                                    placeholder="Running shoes"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                                hint="A short unique identifier, e.g. CAT-001."
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    placeholder="CAT-001"
                                    required
                                />
                            </FormField>

                            <FormField
                                id="parent_id"
                                label="Parent category"
                                error={errors.parent_id}
                                hint="Leave unselected for a top-level category."
                            >
                                <select
                                    id="parent_id"
                                    name="parent_id"
                                    className="form-select"
                                    defaultValue=""
                                >
                                    <option value="">No parent</option>
                                    {parentOptions.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField error={errors.status}>
                                <div className="form-check form-switch">
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="0"
                                    />
                                    <input
                                        id="status"
                                        name="status"
                                        type="checkbox"
                                        className="form-check-input"
                                        value="1"
                                        defaultChecked
                                    />
                                    <label
                                        className="form-check-label"
                                        htmlFor="status"
                                    >
                                        Active
                                    </label>
                                </div>
                            </FormField>

                            <div className="app-form-actions">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="create-category-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Create category
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
