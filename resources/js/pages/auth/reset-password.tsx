import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import Spinner from '@/components/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="Reset password" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="email"
                            label="Email address"
                            error={errors.email}
                        >
                            <input
                                id="email"
                                name="email"
                                type="email"
                                className="form-control"
                                value={email}
                                autoComplete="email"
                                readOnly
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
                                placeholder="Choose a new password"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                autoFocus
                            />
                        </FormField>

                        <FormField
                            id="password_confirmation"
                            label="Confirm password"
                            error={errors.password_confirmation}
                            required
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                placeholder="Repeat the new password"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </FormField>

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner size="sm" />}
                            Reset password
                        </button>
                    </>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Choose a new password',
    description: 'Pick something long and unique to keep your account safe.',
};
