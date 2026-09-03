import { Form, Head } from '@inertiajs/react';
import Alert from '@/components/alert';
import FormField from '@/components/form-field';
import Spinner from '@/components/spinner';
import TextLink from '@/components/text-link';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Forgot password" />

            {status && (
                <Alert tone="success" className="mb-3">
                    <p>{status}</p>
                </Alert>
            )}

            <Form {...email.form()}>
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="email"
                            label="Email address"
                            error={errors.email}
                            required
                        >
                            <input
                                id="email"
                                name="email"
                                type="email"
                                className="form-control"
                                placeholder="you@company.com"
                                autoComplete="email"
                                required
                                autoFocus
                            />
                        </FormField>

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner size="sm" />}
                            Email password reset link
                        </button>
                    </>
                )}
            </Form>

            <p className="app-auth__footer">
                Remembered it?{' '}
                <TextLink href={login()}>Back to log in</TextLink>
            </p>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Reset your password',
    description: 'We will email you a secure link to choose a new one.',
};
