import { Form } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormField from '@/components/form-field';
import Modal from '@/components/modal';
import PasswordInput from '@/components/password-input';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);

    return (
        <SectionCard
            title="Delete account"
            subtitle="Permanently remove your account and everything in it."
        >
            <div className="app-danger-zone">
                <div>
                    <p className="fw-semibold mb-1">This cannot be undone</p>
                    <p className="app-text-muted small mb-0">
                        Deleting your account erases your profile, sessions and
                        all associated records.
                    </p>
                </div>

                <button
                    type="button"
                    className="btn btn-danger"
                    onClick={() => setOpen(true)}
                    data-test="delete-user-button"
                >
                    Delete account
                </button>
            </div>

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Delete your account?"
                description="All of your data will be permanently removed. Enter your password to confirm."
                icon={TriangleAlert}
                iconTone="danger"
                size="sm"
            >
                <Form
                    {...ProfileController.destroy.form()}
                    options={{ preserveScroll: true }}
                    onError={() => passwordInput.current?.focus()}
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <>
                            <FormField
                                id="delete-password"
                                label="Password"
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="delete-password"
                                    name="password"
                                    ref={passwordInput}
                                    placeholder="Password"
                                    autoComplete="current-password"
                                />
                            </FormField>

                            <div className="app-form-actions justify-content-end mt-4">
                                <button
                                    type="button"
                                    className="btn btn-surface"
                                    onClick={() => setOpen(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-danger"
                                    disabled={processing}
                                    data-test="confirm-delete-user-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Delete account
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </Modal>
        </SectionCard>
    );
}
