import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormField from '@/components/form-field';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import SectionCard from '@/components/section-card';
import Spinner from '@/components/spinner';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <SectionCard
                title="Password"
                subtitle="Use a long, unique password you do not reuse anywhere else."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="current_password"
                                label="Current password"
                                error={errors.current_password}
                                required
                            >
                                <PasswordInput
                                    id="current_password"
                                    name="current_password"
                                    ref={currentPasswordInput}
                                    placeholder="Current password"
                                    autoComplete="current-password"
                                />
                            </FormField>

                            <FormField
                                id="password"
                                label="New password"
                                error={errors.password}
                                required
                            >
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    ref={passwordInput}
                                    placeholder="New password"
                                    autoComplete="new-password"
                                    passwordrules={props.passwordRules}
                                />
                            </FormField>

                            <FormField
                                id="password_confirmation"
                                label="Confirm new password"
                                error={errors.password_confirmation}
                                required
                            >
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    placeholder="Confirm new password"
                                    autoComplete="new-password"
                                    passwordrules={props.passwordRules}
                                />
                            </FormField>

                            <div className="app-form-actions mt-4">
                                <button
                                    type="submit"
                                    className="btn btn-gradient"
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {processing && <Spinner size="sm" />}
                                    Update password
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </SectionCard>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [{ title: 'Security settings', href: edit() }],
};
