import { Form, Head } from '@inertiajs/react';
import CategoryController from '@/actions/App/Http/Controllers/CategoryController';
import FormField from '@/components/form-field';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import type { Category, Option } from '@/types/catalog';

type Props = {
    id: number;
    category: Category;
    parentOptions: Option[];
};

export default function CategoryEdit({ id, category, parentOptions }: Props) {
    return (
        <>
            <Head title={`Edit ${category.name}`} />

            <PageHeader
                eyebrow="Catalog"
                title={`Edit ${category.name}`}
                description="Update this category's details."
            />

            <SectionCard>
                <Form
                    {...CategoryController.update.form(id)}
                    options={{ preserveScroll: true }}
                >
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
                                    defaultValue={category.name}
                                    required
                                />
                            </FormField>

                            <FormField
                                id="code"
                                label="Code"
                                error={errors.code}
                                required
                            >
                                <input
                                    id="code"
                                    name="code"
                                    type="text"
                                    className="form-control"
                                    defaultValue={category.code}
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
                                    defaultValue={category.parent_id ?? ''}
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
                                        defaultChecked={category.status}
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
                                    data-test="update-category-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Save changes
                                </button>
                            </div>
                        </div>
                    )}
                </Form>
            </SectionCard>
        </>
    );
}
